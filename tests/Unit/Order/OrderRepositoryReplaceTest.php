<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductRepository;
use App\Order\OrderRepository;
use App\Order\OrderValidationException;
use App\Tests\Support\FakeOrderDatabase;

/**
 * Modification d'une commande encore en attente de paiement (F18). Le client revient
 * de l'ecran de paiement, change son panier, et repart payer : sa commande doit etre
 * MISE A JOUR, pas doublee. Avant ce lot, la borne creait une seconde commande et
 * abandonnait la premiere (nettoyee la nuit par le balayage de F10).
 *
 * Ce que ces tests verrouillent, par ordre de gravite :
 *  1. le prix est recalcule SERVEUR par le meme chemin qu'a la creation -- un panier
 *     modifie ne doit pas pouvoir etre facture autrement que le meme panier cree d'un
 *     coup (RG-T16) ;
 *  2. une commande deja encaissee n'est PAS modifiable -- sinon on changerait les
 *     lignes d'une commande dont le stock est deja debite ;
 *  3. la modification ne touche NI le stock NI le journal des mouvements ;
 *  4. l'ancien contenu part entierement (purge avant reinsertion), sans ligne
 *     survivante.
 */
final class OrderRepositoryReplaceTest extends TestCase
{
    private function repo(FakeOrderDatabase $db): OrderRepository
    {
        return new OrderRepository($db, new ProductRepository($db), new MenuRepository($db));
    }

    /**
     * Base commune : deux produits au catalogue et une commande K100 en attente.
     */
    private function pendingDb(): FakeOrderDatabase
    {
        $db = new FakeOrderDatabase();
        $db->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $db->products[13] = ['id' => 13, 'name' => 'Frite', 'price_cents' => 250, 'vat_rate' => 100, 'is_available' => 1];
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        return $db;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function req(array $items): array
    {
        return ['service_mode' => 'takeaway', 'items' => $items];
    }

    public function testReplacesTheItemsAndRecomputesTheTotalsServerSide(): void
    {
        $db = $this->pendingDb();

        $res = $this->repo($db)->replaceItems('K100', $this->req([
            ['type' => 'product', 'product_id' => 12, 'quantity' => 1],
            ['type' => 'product', 'product_id' => 13, 'quantity' => 2],
        ]));

        // 890 + 2*250 = 1390 TTC. Les totaux viennent du catalogue serveur, pas du client.
        self::assertSame(1390, $res['total_ttc_cents']);
        self::assertSame('K100', $res['order_number']);
        self::assertSame('pending_payment', $res['status']);
        $totals = $db->firstWrite('UPDATE customer_order SET total_ht_cents');
        self::assertSame(1390, $totals['ttc']);
        // HT = round(890*1000/1100) + 2*round(250*1000/1100) = 809 + 2*227 = 1263.
        self::assertSame(1263, $totals['ht']);
        self::assertSame(127, $totals['vat']);
    }

    public function testPricesMatchWhatTheSameCartWouldCostAtCreation(): void
    {
        // Garde anti-divergence : creation et modification passent par resolveAndTotal().
        // Si l'une des deux se mettait a calculer autrement, un client pourrait obtenir
        // un prix different selon qu'il a modifie sa commande ou non.
        $items = [['type' => 'product', 'product_id' => 12, 'quantity' => 3]];

        $created = new FakeOrderDatabase();
        $created->products[12] = ['id' => 12, 'name' => 'Cheeseburger', 'price_cents' => 890, 'vat_rate' => 100, 'is_available' => 1];
        $atCreation = $this->repo($created)->createPending(
            ['idempotency_key' => 'k-new', 'service_mode' => 'takeaway', 'items' => $items],
        );

        $db = $this->pendingDb();
        $atReplace = $this->repo($db)->replaceItems('K100', $this->req($items));

        self::assertSame($atCreation['total_ttc_cents'], $atReplace['total_ttc_cents']);
        self::assertSame(
            $created->firstWrite('INSERT INTO customer_order')['ht'],
            $db->firstWrite('UPDATE customer_order SET total_ht_cents')['ht'],
        );
    }

    public function testPurgesTheOldItemsBeforeInsertingTheNewOnes(): void
    {
        $db = $this->pendingDb();

        $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));

        // L'ordre compte : inserer avant de purger laisserait les anciennes lignes ET
        // les nouvelles sur la commande, donc un total qui ne correspond a rien.
        $sqls = array_map(static fn (array $w): string => $w['sql'], $db->writes);
        $deleteAt = null;
        $insertAt = null;
        foreach ($sqls as $i => $sql) {
            if ($deleteAt === null && str_contains($sql, 'DELETE FROM order_item')) {
                $deleteAt = $i;
            }
            if ($insertAt === null && str_contains($sql, 'INSERT INTO order_item ')) {
                $insertAt = $i;
            }
        }
        self::assertNotNull($deleteAt, 'la purge doit avoir lieu');
        self::assertNotNull($insertAt);
        self::assertLessThan($insertAt, $deleteAt);
        self::assertSame(['id' => 100], $db->firstWrite('DELETE FROM order_item'));
    }

    public function testPurgeRemovesTheWholeOrderNotJustOneLine(): void
    {
        $db = $this->pendingDb();

        $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));

        // La purge cible order_id, pas un item precis : les selections de slot et les
        // modificateurs d'ingredient partent en CASCADE avec leurs lignes. Une purge
        // ligne a ligne laisserait des enfants orphelins si une ligne etait oubliee.
        self::assertStringContainsString(
            'DELETE FROM order_item WHERE order_id = :id',
            $db->firstWriteSql('DELETE FROM order_item'),
        );
    }

    public function testRefusesToModifyAnAlreadyPaidOrder(): void
    {
        $db = $this->pendingDb();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'preparing'];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('INVALID_TRANSITION');
        $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));
    }

    public function testRefusesToModifyACancelledOrder(): void
    {
        $db = $this->pendingDb();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'cancelled'];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('INVALID_TRANSITION');
        $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));
    }

    public function testAPaidOrderIsNotTouchedAtAllWhenRefused(): void
    {
        $db = $this->pendingDb();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'preparing'];

        try {
            $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));
        } catch (OrderValidationException) {
            // attendu
        }

        // Le refus doit intervenir AVANT toute ecriture : une purge suivie d'un rollback
        // reste correcte en base, mais compter sur le rollback pour la coherence d'une
        // commande deja encaissee est un risque inutile.
        self::assertSame([], $db->writes);
    }

    public function testUnknownOrderNumberIsNotFound(): void
    {
        $db = $this->pendingDb();
        $db->orderByNumber = null;

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('ORDER_NOT_FOUND');
        $this->repo($db)->replaceItems('K404', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));
    }

    public function testAnEmptyCartIsRefusedWithoutTouchingTheOrder(): void
    {
        $db = $this->pendingDb();

        try {
            $this->repo($db)->replaceItems('K100', $this->req([]));
            self::fail('un panier vide doit etre refuse');
        } catch (OrderValidationException $exception) {
            self::assertSame('EMPTY_ORDER', $exception->getMessage());
        }

        // Vider la commande la rendrait payable a 0 EUR : la validation passe AVANT la
        // transaction, donc rien n'est ecrit.
        self::assertSame([], $db->writes);
    }

    public function testAnOutOfStockProductCannotBeCarriedIntoTheModifiedCart(): void
    {
        $db = $this->pendingDb();
        // RG-T21 : le produit 13 est tombe en rupture calculee depuis la creation.
        $db->autoUnavailableRows = [['product_id' => 13]];

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessage('PRODUCT_UNAVAILABLE');
        $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));
    }

    public function testNeverWritesToStock(): void
    {
        $db = $this->pendingDb();
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 2, 'quantity_maxi' => 2, 'is_removable' => 0]];

        $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 12, 'quantity' => 4]]));

        // Une commande en attente n'a rien consomme : modifier son contenu ne doit ni
        // debiter ni recrediter. Le stock ne bouge qu'a l'encaissement (RG-T20) et a
        // l'annulation d'une commande encaissee.
        foreach ($db->writes as $write) {
            self::assertStringNotContainsString('UPDATE ingredient', $write['sql']);
            self::assertStringNotContainsString('INSERT INTO stock_movement', $write['sql']);
        }
    }

    public function testDoesNotTouchTheOrderNumberNorTheIdempotencyKey(): void
    {
        $db = $this->pendingDb();

        $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));

        // Le numero est deja affiche au client et la cle porte l'idempotence : les
        // reecrire ferait perdre le lien avec la session de paiement en cours.
        $totalsSql = $db->firstWriteSql('UPDATE customer_order SET total_ht_cents');
        self::assertStringNotContainsString('order_number', $totalsSql);
        self::assertStringNotContainsString('idempotency_key', $totalsSql);
        self::assertStringNotContainsString('status', $totalsSql);
    }

    // -------------------------------------------------------------------------
    // Serialisation avec l'encaissement
    // -------------------------------------------------------------------------

    public function testTakesAnExplicitRowLockBeforeTouchingAnything(): void
    {
        $db = $this->pendingDb();

        $this->repo($db)->replaceItems('K100', $this->req([['type' => 'product', 'product_id' => 13, 'quantity' => 1]]));

        // Le verrou est le SEUL mecanisme qui serialise une modification avec un
        // encaissement concurrent. La garde-dans-le-WHERE habituelle du projet ne peut pas
        // jouer ce role ici : mesure faite sur MariaDB 11.4, un UPDATE vers des valeurs
        // IDENTIQUES rend 0 ligne affectee, exactement comme une garde en echec -- le code
        // ne saurait pas distinguer un no-op legitime d'une course perdue.
        $lock = array_values(array_filter(
            $db->reads,
            static fn (array $r): bool => str_contains($r['sql'], 'FOR UPDATE'),
        ));
        self::assertCount(1, $lock);
        self::assertStringContainsString('FROM customer_order', $lock[0]['sql']);
        self::assertSame(['n' => 'K100'], $lock[0]['params']);
    }

    public function testPaymentTakesTheSameLockSoBothOperationsSerialise(): void
    {
        $db = $this->pendingDb();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $this->repo($db)->pay('K100');

        // Un verrou pris d'un seul cote ne serialise rien. Cette assertion est le pendant
        // de la precedente : si l'encaissement cessait de le prendre, une modification
        // pourrait s'intercaler et le debit de stock porterait sur des lignes remplacees.
        $lock = array_values(array_filter(
            $db->reads,
            static fn (array $r): bool => str_contains($r['sql'], 'FOR UPDATE'),
        ));
        self::assertCount(1, $lock);
        self::assertSame(['n' => 'K100'], $lock[0]['params']);
    }

    public function testPaymentLocksBeforeAnyNonLockingReadInsideItsTransaction(): void
    {
        $db = $this->pendingDb();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        $db->orderItems = [['id' => 1, 'item_type' => 'product', 'product_id' => 12, 'menu_id' => null, 'format' => 'normal', 'quantity' => 1]];
        $db->compositions[12] = [['ingredient_id' => 5, 'quantity_normal' => 2, 'quantity_maxi' => 2, 'is_removable' => 0]];

        $this->repo($db)->pay('K100');

        // GARDE DE NON-REGRESSION, et c'est la plus importante du lot.
        //
        // Mesure faite sur MariaDB 11.4 : l'instantane de lecture d'une transaction est
        // pose PARESSEUSEMENT, a sa premiere lecture NON verrouillante. Consequence
        // mesuree : si une lecture simple precede la prise de verrou, l'instantane est
        // fige AVANT que le verrou n'ait serialise quoi que ce soit -- et le calcul de
        // consommation lit alors des lignes deja remplacees. Le stock est debite pour des
        // articles que le client n'a pas commandes, et AUCUNE garde ne le signale (les
        // deux UPDATE rendent 1 ligne affectee).
        //
        // Un ajout aussi anodin qu'un SELECT de journalisation en tete de transaction
        // suffirait. Ce test echoue si cela arrive.
        $firstLock = null;
        $firstPlainRead = null;
        foreach ($db->reads as $i => $read) {
            if ($firstLock === null && str_contains($read['sql'], 'FOR UPDATE')) {
                $firstLock = $i;
            }
            // La pre-lecture hors transaction (customer_order par numero) est benigne :
            // autocommit, son instantane est relache a son propre commit implicite. On ne
            // surveille donc que les lectures des tables du calcul de consommation.
            if ($firstPlainRead === null && str_contains($read['sql'], 'FROM order_item')) {
                $firstPlainRead = $i;
            }
        }
        self::assertNotNull($firstLock, 'l encaissement doit prendre le verrou');
        self::assertNotNull($firstPlainRead, 'le calcul de consommation doit lire les lignes');
        self::assertLessThan(
            $firstPlainRead,
            $firstLock,
            'le verrou doit etre pris AVANT toute lecture non verrouillante du contenu',
        );
    }

    public function testALostPaymentRaceReportsTheRealStatusNotAnOptimisticOne(): void
    {
        $db = $this->pendingDb();
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];
        // Un autre appel a gagne la course, et il est deja alle plus loin que l'encaissement.
        $db->payUpdateAffected = 0;
        $db->recheckStatus = 'ready';

        $res = $this->repo($db)->pay('K100');

        // Annoncer 'preparing' pour une commande deja prete serait faux : la borne
        // afficherait un etat que la cuisine a depasse.
        self::assertSame('ready', $res['status']);
    }

    public function testPaymentReadsItsTotalUnderTheLockNotFromTheEarlierRead(): void
    {
        $db = $this->pendingDb();
        // Pre-lecture hors transaction : total perime (le client a modifie depuis).
        $db->orderByNumber = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $res = $this->repo($db)->pay('K100');

        // Le double rend la meme ligne aux deux lectures, donc ce test verrouille le
        // CHEMIN : le total renvoye doit venir de la lecture verrouillee, pas de la
        // pre-lecture. Depuis F18 une commande en attente est modifiable, et facturer un
        // total perime serait un ecart entre le montant annonce et le contenu reel.
        $lock = array_values(array_filter(
            $db->reads,
            static fn (array $r): bool => str_contains($r['sql'], 'FOR UPDATE'),
        ));
        self::assertStringContainsString('total_ttc_cents', $lock[0]['sql']);
        self::assertSame(890, $res['total_ttc_cents']);
    }

    // -------------------------------------------------------------------------
    // createPending : ce que fait une cle d'idempotence deja connue
    // -------------------------------------------------------------------------

    public function testKnownKeyOnAPendingOrderUpdatesItInsteadOfCreatingASecond(): void
    {
        $db = $this->pendingDb();
        $db->existingByKey = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'pending_payment'];

        $res = $this->repo($db)->createPending([
            'idempotency_key' => 'k-1',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 13, 'quantity' => 1]],
        ]);

        // Le defaut corrige : avant, la cle connue renvoyait la commande TELLE QUELLE en
        // ignorant les lignes envoyees -- donc un panier modifie aurait ete facture a
        // l'ancien contenu. La borne contournait en changeant de cle, ce qui creait une
        // seconde commande et abandonnait la premiere.
        self::assertSame('K100', $res['order_number']);
        self::assertSame(250, $res['total_ttc_cents']);
        self::assertSame([], array_filter(
            $db->writes,
            static fn (array $w): bool => str_contains($w['sql'], 'INSERT INTO customer_order'),
        ));
    }

    public function testKnownKeyOnAnAlreadyPaidOrderReturnsItUnchanged(): void
    {
        $db = $this->pendingDb();
        $db->existingByKey = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'preparing'];

        $res = $this->repo($db)->createPending([
            'idempotency_key' => 'k-1',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 13, 'quantity' => 1]],
        ]);

        // Vrai renvoi de tentative apres un encaissement dont la reponse a ete perdue :
        // on reflete l'etat reel, aucune ecriture. Une commande encaissee n'est plus
        // modifiable (RG-T19).
        self::assertSame('preparing', $res['status']);
        self::assertSame(890, $res['total_ttc_cents']);
        self::assertSame([], $db->writes);
    }

    public function testKnownKeyOnACancelledOrderSignalsThatTheKeyIsSpent(): void
    {
        $db = $this->pendingDb();
        $db->existingByKey = ['id' => 100, 'order_number' => 'K100', 'total_ttc_cents' => 890, 'status' => 'cancelled'];

        try {
            $this->repo($db)->createPending([
                'idempotency_key' => 'k-1',
                'service_mode' => 'takeaway',
                'items' => [['type' => 'product', 'product_id' => 13, 'quantity' => 1]],
            ]);
            self::fail('une cle liee a une commande annulee doit etre signalee');
        } catch (OrderValidationException $exception) {
            // La colonne idempotency_key est UNIQUE : la cle ne peut pas porter une
            // seconde commande. Sans ce code, un client dont la commande a ete annulee
            // ou expiree pendant qu'il hesitait resterait bloque : ni payable, ni
            // recreable. La borne s'en sert pour repartir d'une cle neuve.
            self::assertSame('ORDER_CANCELLED', $exception->getMessage());
        }
        self::assertSame([], $db->writes);
    }

    public function testAFreshKeyStillCreatesANewOrder(): void
    {
        $db = $this->pendingDb();
        $db->existingByKey = null;

        $res = $this->repo($db)->createPending([
            'idempotency_key' => 'k-neuve',
            'service_mode' => 'takeaway',
            'items' => [['type' => 'product', 'product_id' => 12, 'quantity' => 1]],
        ]);

        self::assertSame('pending_payment', $res['status']);
        self::assertNotSame([], array_filter(
            $db->writes,
            static fn (array $w): bool => str_contains($w['sql'], 'INSERT INTO customer_order'),
        ));
    }
}
