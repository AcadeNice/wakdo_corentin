<?php

declare(strict_types=1);

namespace App\Controllers;

use PDOException;
use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Catalogue\CategoryRepository;
use App\Catalogue\IngredientRepository;
use App\Catalogue\ProductRepository;
use App\Core\DatabaseInterface;
use App\Core\Response;

/**
 * CRUD des produits (P3). Cas riche du catalogue + premier usage reel des actions
 * sensibles (RG-T13/RG-T14) :
 *  - create (product.create) : pas de PIN (mlt 8.1) ;
 *  - update (product.update) : PIN equipier + audit UNIQUEMENT si prix ou TVA
 *    change (mlt 8.2 RG-4) ; sinon mise a jour simple ;
 *  - delete (product.delete) : PIN equipier + audit, suppression dure seulement si
 *    le produit n'est reference nulle part (FK RESTRICT -> 409 sinon).
 * Le PIN suit le modele "identifiant equipier + PIN" : email + PIN resolus en un
 * acting_user_id ecrit dans audit_log, dans la meme transaction que l'effet (RG-T08).
 *
 * Non `final` : les tests sous-classent pour injecter des doubles.
 */
class ProductController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('product.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/products/index', [
            'title'           => 'Produits - Wakdo Admin',
            'activeNav'       => 'products',
            'products'        => $this->productRepository()->all(),
            // Rupture AUTOMATIQUE par le stock (RG-T21), distincte du retrait manuel
            // (is_available=0) : la vue signale les deux differemment.
            'autoUnavailable' => $this->productRepository()->autoUnavailableIds(),
        ], $guard);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params = []): Response
    {
        $guard = $this->guard('product.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->renderForm($guard, 0, [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(array $params = []): Response
    {
        $guard = $this->guard('product.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        // id = 0 a la creation : pas d'auto-reference possible (le produit n'existe
        // pas encore), validate() le sait par le 2e argument.
        [$data, $errors] = $this->validate($form, 0);
        if ($errors !== []) {
            return $this->renderForm($guard, 0, $form, $errors, 422);
        }

        $this->productRepository()->create($data);
        $this->setFlash('Produit cree.');

        return $this->redirect('/admin/products');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(array $params): Response
    {
        $guard = $this->guard('product.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $product = $this->productRepository()->find($id);
        if ($product === null) {
            return $this->notFound($guard);
        }

        return $this->renderForm($guard, $id, $product, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params): Response
    {
        $guard = $this->guard('product.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $current = $this->productRepository()->find($id);
        if ($current === null) {
            return $this->notFound($guard);
        }

        [$data, $errors] = $this->validate($form, $id);
        if ($errors !== []) {
            return $this->renderForm($guard, $id, $form, $errors, 422);
        }

        // RG-T13/8.2 : seul un changement de prix ou de TVA est une action sensible.
        $priceChanged = $data['price_cents'] !== (int) ($current['price_cents'] ?? 0);
        $vatChanged = $data['vat_rate'] !== (int) ($current['vat_rate'] ?? 0);

        if (!$priceChanged && !$vatChanged) {
            $this->productRepository()->update($id, $data);
            $this->setFlash('Produit mis a jour.');

            return $this->redirect('/admin/products');
        }

        // Changement sensible : exige email + PIN (modele equipier + PIN, RG-T13).
        // RG-T22 : verrou de throttle PIN par UTILISATEUR AGISSANT (session), evalue
        // AVANT la verification argon2id. Un acteur verrouille recoit le MEME 422
        // generique ; on paie un leurre de timing (parite avec le chemin mauvais-PIN)
        // et on n'ecrit PAS de nouvelle ligne pin.failed (les echecs ayant arme le
        // verrou sont deja audites : borne l'amplification de l'audit append-only).
        $actorId = $guard->userId ?? 0;
        if ($actorId > 0 && $this->pinThrottle()->isLocked($actorId)) {
            $this->pinVerifier()->payTimingDecoy($form['pin'] ?? '');

            return $this->renderForm($guard, $id, $form, ['pin' => 'Email ou PIN invalide (requis pour modifier prix/TVA).'], 422);
        }

        $actor = $this->pinVerifier()->resolveActingUser(trim($form['pin_email'] ?? ''), $form['pin'] ?? '');
        if ($actor === null) {
            // RG-T08 : la trace pin.failed (RG-T14) et l'increment du throttle
            // (RG-T22) sont ecrits dans UNE meme transaction (pas d'etat partiel
            // si crash entre les deux ecritures).
            $email = trim($form['pin_email'] ?? '');
            $this->db()->transaction(function (DatabaseInterface $db) use ($email, $id, $actorId): void {
                $this->logFailedPin($db, $email, $id);
                $this->pinThrottle()->recordFailureWithin($db, $actorId);
            });

            return $this->renderForm($guard, $id, $form, ['pin' => 'Email ou PIN invalide (requis pour modifier prix/TVA).'], 422);
        }

        $summary = $this->changeSummary($current, $data, $priceChanged, $vatChanged);

        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $data, $actor, $summary): void {
            (new ProductRepository($db))->update($id, $data);
            $this->writeAudit($db, 'product.update', $actor['id'], $actor['role_id'], $id, $summary);
        });

        // PIN valide : reinitialise le compteur de throttle de l'acteur de SESSION
        // (RG-T22), apres l'effet reussi. Cle = $actorId ($guard->userId), la meme
        // qu'a l'increment ; surtout PAS $actor['id'] (l'equipier resolu par le PIN,
        // un autre individu) sinon le compteur de l'agissant ne serait jamais purge.
        $this->pinThrottle()->reset($actorId);

        $this->setFlash('Produit mis a jour (changement de prix/TVA trace).');

        return $this->redirect('/admin/products');
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmDelete(array $params): Response
    {
        $guard = $this->guard('product.delete');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $product = $this->productRepository()->find($id);
        if ($product === null) {
            return $this->notFound($guard);
        }

        return $this->renderDelete($guard, $id, $product, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(array $params): Response
    {
        $guard = $this->guard('product.delete');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $product = $this->productRepository()->find($id);
        if ($product === null) {
            return $this->notFound($guard);
        }

        // RG-T22 : meme garde que update() (verrou par utilisateur agissant, AVANT
        // la verification, leurre de timing, pas de pin.failed sous verrou actif).
        $actorId = $guard->userId ?? 0;
        if ($actorId > 0 && $this->pinThrottle()->isLocked($actorId)) {
            $this->pinVerifier()->payTimingDecoy($form['pin'] ?? '');

            return $this->renderDelete($guard, $id, $product, 'Email ou PIN invalide (requis pour supprimer).');
        }

        $actor = $this->pinVerifier()->resolveActingUser(trim($form['pin_email'] ?? ''), $form['pin'] ?? '');
        if ($actor === null) {
            // RG-T08 : trace pin.failed (RG-T14) + increment throttle (RG-T22) dans
            // UNE meme transaction (pas d'etat partiel si crash entre les deux).
            $email = trim($form['pin_email'] ?? '');
            $this->db()->transaction(function (DatabaseInterface $db) use ($email, $id, $actorId): void {
                $this->logFailedPin($db, $email, $id);
                $this->pinThrottle()->recordFailureWithin($db, $actorId);
            });

            return $this->renderDelete($guard, $id, $product, 'Email ou PIN invalide (requis pour supprimer).');
        }

        $name = (string) ($product['name'] ?? '');

        // Dette #27 : product_ingredient (FK product_id CASCADE) sera emporte par la
        // suppression. On compte AVANT (lecture hors transaction) pour tracer le
        // nombre de lignes de recette cascade-supprimees dans le resume d'audit :
        // aucune perte hors-trace dans le journal append-only.
        $cascaded = $this->productRepository()->compositionCount($id);
        $summary = 'Suppression produit: ' . $name
            . ' (' . $cascaded . ' ligne(s) de recette cascade-supprimee(s))';

        // FK RESTRICT (order_item / menu / menu_slot_option / order_item_selection)
        // -> PDOException 23000 -> 409 Conflit (catch ci-dessous). product_ingredient
        // est CASCADE (recette possedee par le produit) : supprimee avec lui, jamais
        // bloquante (cf. docblock ProductRepository).
        try {
            $this->db()->transaction(function (DatabaseInterface $db) use ($id, $actor, $summary): void {
                $deleted = (new ProductRepository($db))->delete($id);
                if ($deleted === 1) {
                    $this->writeAudit($db, 'product.delete', $actor['id'], $actor['role_id'], $id, $summary);
                }
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->renderDelete($guard, $id, $product, 'Produit reference par des commandes ou menus : suppression impossible. Masquez-le plutot.', 409);
            }

            throw $exception;
        }

        // PIN valide et suppression effective : reinitialise le compteur de l'acteur
        // de session (RG-T22, cle = $actorId). Apres le try/catch : non atteint si la
        // FK a bloque (409), ce qui est benin (l'acteur n'est pas un attaquant).
        $this->pinThrottle()->reset($actorId);

        $this->setFlash('Produit supprime.');

        return $this->redirect('/admin/products');
    }

    /**
     * Editeur de recette (PR-B, mlt domaine recettes). Compose product_ingredient :
     * la commandabilite est gardee par `ingredient.manage` (composition du produit),
     * DISTINCTE de product.create/update/delete (CRUD produit). Aucun PIN : editer
     * une recette n'est pas une action sensible RG-T13.
     *
     * @param array<string, string> $params
     */
    public function recipeForm(array $params): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $product = $this->productRepository()->find($id);
        if ($product === null) {
            return $this->notFound($guard);
        }

        return $this->renderRecipe($guard, $id, $product, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveRecipe(array $params): Response
    {
        $guard = $this->guard('ingredient.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $product = $this->productRepository()->find($id);
        if ($product === null) {
            return $this->notFound($guard);
        }

        $errors = [];
        $lines = $this->parseComposition($form['composition_json'] ?? '', $errors);
        if ($errors !== []) {
            return $this->renderRecipe($guard, $id, $product, $errors, 422);
        }

        // Composition vide autorisee : un produit peut n'avoir aucune recette
        // definie (setComposition purge alors la table sans rien reinserer).
        $this->productRepository()->setComposition($id, $lines);
        $this->setFlash('Recette mise a jour.');

        return $this->redirect('/admin/products');
    }

    protected function productRepository(): ProductRepository
    {
        return new ProductRepository($this->db());
    }

    protected function ingredientRepository(): IngredientRepository
    {
        return new IngredientRepository($this->db());
    }

    protected function categoryRepository(): CategoryRepository
    {
        return new CategoryRepository($this->db());
    }

    protected function pinVerifier(): PinVerifier
    {
        return new PinVerifier($this->db(), $this->config, $this->passwordHasher());
    }

    protected function pinThrottle(): PinThrottle
    {
        return new PinThrottle($this->db(), $this->config);
    }

    protected function passwordHasher(): PasswordHasher
    {
        return new PasswordHasher($this->config);
    }

    /**
     * Validation serveur (RG-T18) + allowlist (RG-T16). Renvoie [donnees, erreurs].
     * $currentId = id du produit edite (0 a la creation), pour interdire l'auto-
     * reference des FK de variante (F9-3).
     *
     * @param array<string, string> $form
     * @return array{0: array{category_id: int, name: string, description: ?string, price_cents: int, size_cl: ?int, base_product_id: ?int, maxi_variant_product_id: ?int, vat_rate: int, image_path: ?string, is_available: int, display_order: int}, 1: array<string, string>}
     */
    private function validate(array $form, int $currentId): array
    {
        $errors = [];

        $categoryRaw = trim($form['category_id'] ?? '');
        $categoryId = ctype_digit($categoryRaw) ? (int) $categoryRaw : 0;
        if ($categoryId === 0 || !$this->productRepository()->categoryExists($categoryId)) {
            $errors['category_id'] = 'Categorie requise et valide.';
        }

        $name = trim($form['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Le nom est requis (120 caracteres max).';
        }

        $priceRaw = trim($form['price_cents'] ?? '');
        $priceValid = ctype_digit($priceRaw) && (int) $priceRaw > 0 && (int) $priceRaw <= 4294967295;
        if (!$priceValid) {
            $errors['price_cents'] = 'Le prix (en centimes) doit etre un entier strictement positif.';
        }

        $vat = ctype_digit(trim($form['vat_rate'] ?? '')) ? (int) trim($form['vat_rate'] ?? '') : 0;
        if ($vat !== 55 && $vat !== 100) {
            $errors['vat_rate'] = 'La TVA doit valoir 55 (5,5%) ou 100 (10%).';
        }

        $image = trim($form['image_path'] ?? '');
        if ($image !== '' && mb_strlen($image) > 255) {
            $errors['image_path'] = 'Chemin image trop long (255 max).';
        }

        $orderRaw = trim($form['display_order'] ?? '0');
        if (!ctype_digit($orderRaw) || (int) $orderRaw > 65535) {
            $errors['display_order'] = 'L ordre d affichage doit etre un entier entre 0 et 65535.';
        }

        $description = trim($form['description'] ?? '');

        // --- Champs de variante (F9-3, R4 / migrations 0006-0007) ---
        // Tous nullables : un champ vide signifie "produit de base / autonome, sans
        // dimension taille ni substitution Maxi". Bornes refletant les colonnes :
        // size_cl SMALLINT UNSIGNED (0..65535), base/maxi FK INT UNSIGNED.

        // size_cl : volume en cl, entier >= 0 si fourni (vide = NULL).
        $sizeRaw = trim($form['size_cl'] ?? '');
        $sizeCl = null;
        if ($sizeRaw !== '') {
            if (!ctype_digit($sizeRaw) || (int) $sizeRaw > 65535) {
                $errors['size_cl'] = 'La taille (en cl) doit etre un entier entre 0 et 65535.';
            } else {
                $sizeCl = (int) $sizeRaw;
            }
        }

        // base_product_id : ce produit devient une VARIANTE de taille de la base
        // designee. La base doit exister, etre differente de soi (pas d'auto-
        // reference), et etre elle-meme une BASE (productIsBase) : on interdit une
        // chaine de variantes (une variante ne peut pointer vers une autre variante).
        $baseRaw = trim($form['base_product_id'] ?? '');
        $baseId = null;
        if ($baseRaw !== '') {
            if (!ctype_digit($baseRaw)) {
                $errors['base_product_id'] = 'Le produit de base doit etre un produit existant.';
            } elseif ((int) $baseRaw === $currentId) {
                $errors['base_product_id'] = 'Un produit ne peut pas etre sa propre base.';
            } elseif (!$this->productRepository()->productExists((int) $baseRaw)) {
                $errors['base_product_id'] = 'Le produit de base doit etre un produit existant.';
            } elseif (!$this->productRepository()->productIsBase((int) $baseRaw)) {
                $errors['base_product_id'] = 'Le produit de base doit lui-meme etre un produit de base (pas une variante).';
            } else {
                $baseId = (int) $baseRaw;
            }
        }

        // maxi_variant_product_id : la variante Grande servie quand un MENU est
        // commande en Maxi. Doit exister et etre differente de soi (auto-reference
        // directe interdite). Pas de contrainte de base ici : la cible Maxi est elle
        // aussi un produit a part entiere (ex. "Grande Frite"), pas une base de taille.
        $maxiRaw = trim($form['maxi_variant_product_id'] ?? '');
        $maxiId = null;
        if ($maxiRaw !== '') {
            if (!ctype_digit($maxiRaw)) {
                $errors['maxi_variant_product_id'] = 'La variante Maxi doit etre un produit existant.';
            } elseif ((int) $maxiRaw === $currentId) {
                $errors['maxi_variant_product_id'] = 'Un produit ne peut pas etre sa propre variante Maxi.';
            } elseif (!$this->productRepository()->productExists((int) $maxiRaw)) {
                $errors['maxi_variant_product_id'] = 'La variante Maxi doit etre un produit existant.';
            } else {
                $maxiId = (int) $maxiRaw;
            }
        }

        $data = [
            'category_id'             => $categoryId,
            'name'                    => $name,
            'description'             => $description !== '' ? $description : null,
            'price_cents'             => $priceValid ? (int) $priceRaw : 0,
            'size_cl'                 => $sizeCl,
            'base_product_id'         => $baseId,
            'maxi_variant_product_id' => $maxiId,
            'vat_rate'                => ($vat === 55 || $vat === 100) ? $vat : 100,
            'image_path'              => $image !== '' ? $image : null,
            'is_available'            => ($form['is_available'] ?? '') !== '' ? 1 : 0,
            'display_order'           => (ctype_digit($orderRaw) && (int) $orderRaw <= 65535) ? (int) $orderRaw : 0,
        ];

        return [$data, $errors];
    }

    /**
     * @param array<string, mixed> $current
     * @param array{price_cents: int, vat_rate: int} $data
     */
    private function changeSummary(array $current, array $data, bool $priceChanged, bool $vatChanged): string
    {
        $parts = [];
        if ($priceChanged) {
            $parts[] = sprintf('price_cents %d -> %d', (int) ($current['price_cents'] ?? 0), $data['price_cents']);
        }
        if ($vatChanged) {
            $parts[] = sprintf('vat_rate %d -> %d', (int) ($current['vat_rate'] ?? 0), $data['vat_rate']);
        }

        return implode(', ', $parts);
    }

    /**
     * Trace une tentative de PIN echouee sur une action sensible (RG-T14) : rend
     * le brute-force d'attribution detectable/alertable (un pic de pin.failed pour
     * un email cible est visible en revue). Acteur inconnu (PIN non resolu).
     *
     * NB : cette ligne d'audit n'est PAS le verrou. Le throttle degressif (par
     * utilisateur agissant) est porte par PinThrottle / RG-T22 ; il ecrit une
     * nouvelle ligne pin.failed UNIQUEMENT hors verrou actif (sous verrou, les
     * echecs ayant arme le verrou sont deja audites), ce qui borne l'amplification
     * de l'audit append-only (RG-T14).
     */
    private function logFailedPin(DatabaseInterface $db, string $email, int $productId): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            [
                'uid' => null,
                'rid' => null,
                'code' => 'pin.failed',
                'etype' => 'product',
                'eid' => $productId,
                'summary' => 'Echec PIN action sensible (email tente: ' . $email . ')',
            ],
        );
    }

    private function writeAudit(DatabaseInterface $db, string $action, int $userId, int $roleId, int $entityId, string $summary): void
    {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            ['uid' => $userId, 'rid' => $roleId, 'code' => $action, 'etype' => 'product', 'eid' => $entityId, 'summary' => $summary],
        );
    }

    /**
     * Decode + valide la composition soumise en JSON (champ cache composition_json),
     * RG-T18 (revalidation serveur) + RG-T16 (allowlist). Un ingredient inconnu est
     * FILTRE (jamais une erreur bloquante) ; la PK composite impose un ingredient au
     * plus une fois (dedup). Les bornes refletent les CHECK de table : quantity_normal
     * >= 1, quantity_maxi >= quantity_normal, extra_price_cents >= 0. Composition vide
     * = aucune ligne, sans erreur.
     *
     * @param array<string, string> $errors
     * @return list<array{ingredient_id:int, quantity_normal:int, quantity_maxi:int, is_removable:int, is_addable:int, extra_price_cents:int}>
     */
    private function parseComposition(string $json, array &$errors): array
    {
        $json = trim($json);
        if ($json === '' || $json === '[]') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $errors['composition'] = 'Composition invalide.';

            return [];
        }

        $lines = [];
        $seen = [];
        foreach ($decoded as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $ingredientId = is_numeric($raw['ingredient_id'] ?? null) ? (int) $raw['ingredient_id'] : 0;
            if ($ingredientId <= 0 || !$this->productRepository()->ingredientExists($ingredientId)) {
                continue; // ingredient inconnu : filtre (allowlist), pas une erreur
            }
            if (isset($seen[$ingredientId])) {
                continue; // PK composite (product_id, ingredient_id) : un seul par ingredient
            }

            $qn = is_numeric($raw['quantity_normal'] ?? null) ? (int) $raw['quantity_normal'] : 0;
            $qm = is_numeric($raw['quantity_maxi'] ?? null) ? (int) $raw['quantity_maxi'] : 0;
            $extra = is_numeric($raw['extra_price_cents'] ?? null) ? (int) $raw['extra_price_cents'] : -1;

            if ($qn < 1 || $qn > 65535) {
                $errors['composition'] = 'La quantite normale doit etre un entier >= 1.';
                continue;
            }
            if ($qm < $qn || $qm > 65535) {
                $errors['composition'] = 'La quantite maxi doit etre >= la quantite normale.';
                continue;
            }
            if ($extra < 0 || $extra > 4294967295) {
                $errors['composition'] = 'Le supplement (en centimes) doit etre un entier >= 0.';
                continue;
            }

            $seen[$ingredientId] = true;
            $lines[] = [
                'ingredient_id'     => $ingredientId,
                'quantity_normal'   => $qn,
                'quantity_maxi'     => $qm,
                'is_removable'      => empty($raw['is_removable']) ? 0 : 1,
                'is_addable'        => empty($raw['is_addable']) ? 0 : 1,
                'extra_price_cents' => $extra,
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, string> $errors
     */
    private function renderRecipe(GuardResult $guard, int $id, array $product, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/products/recipe', [
            'title'       => 'Recette - ' . (string) ($product['name'] ?? '') . ' - Wakdo Admin',
            'activeNav'   => 'products',
            'productId'   => $id,
            'productName' => (string) ($product['name'] ?? ''),
            'ingredients' => $this->ingredientRepository()->all(),
            'composition' => $this->productRepository()->composition($id),
            'errors'      => $errors,
            'csrfToken'   => Csrf::token($this->sessionManager()),
        ], $guard, $status);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(GuardResult $guard, int $id, array $values, array $errors, int $status = 200): Response
    {
        // F9-3 : selects base_product_id (de quelle base ce produit est-il la
        // variante de taille ?) et maxi_variant_product_id (quelle variante Grande
        // servir en menu Maxi ?). On ne propose que des produits de BASE
        // (basesOnly, R4) -- une variante ne peut etre ni une base ni, par
        // simplicite, une cible Maxi -- et on exclut le produit lui-meme de la liste
        // (pas d'auto-reference), garde miroir de validate().
        $baseCandidates = array_values(array_filter(
            $this->productRepository()->basesOnly(),
            static fn (array $p): bool => (int) ($p['id'] ?? 0) !== $id,
        ));

        return $this->adminView('admin/products/form', [
            'title'          => ($id !== 0 ? 'Modifier' : 'Nouveau') . ' produit - Wakdo Admin',
            'activeNav'      => 'products',
            'productId'      => $id,
            'categories'     => $this->categoryRepository()->all(),
            'baseCandidates' => $baseCandidates,
            'values'     => [
                'category_id'             => (string) ($values['category_id'] ?? ''),
                'name'                    => (string) ($values['name'] ?? ''),
                'description'             => (string) ($values['description'] ?? ''),
                'price_cents'             => (string) ($values['price_cents'] ?? ''),
                'size_cl'                 => (string) ($values['size_cl'] ?? ''),
                'base_product_id'         => (string) ($values['base_product_id'] ?? ''),
                'maxi_variant_product_id' => (string) ($values['maxi_variant_product_id'] ?? ''),
                'vat_rate'                => (string) ($values['vat_rate'] ?? '100'),
                'image_path'              => (string) ($values['image_path'] ?? ''),
                // Defaut coche a la creation (errors vide + values vide) ; sur un
                // re-rendu POST (erreurs), refleter la presence reelle du champ
                // (case decochee = absente = non cochee), pas le defaut a 1.
                'is_available'            => $errors === [] ? ((int) ($values['is_available'] ?? 1) === 1) : array_key_exists('is_available', $values),
                'display_order'           => (string) ($values['display_order'] ?? '0'),
            ],
            'errors'     => $errors,
        ], $guard, $status);
    }

    /**
     * @param array<string, mixed> $product
     */
    private function renderDelete(GuardResult $guard, int $id, array $product, ?string $error, ?int $status = null): Response
    {
        return $this->adminView('admin/products/delete', [
            'title'     => 'Supprimer un produit - Wakdo Admin',
            'activeNav' => 'products',
            'productId' => $id,
            'name'      => (string) ($product['name'] ?? ''),
            'error'     => $error,
        ], $guard, $status ?? ($error !== null ? 422 : 200));
    }

    private function notFound(GuardResult $guard): Response
    {
        return $this->adminView('admin/not_found', ['title' => 'Introuvable', 'activeNav' => 'products'], $guard, 404);
    }

    private function redirect(string $location): Response
    {
        return Response::make('', 302, ['Location' => $location]);
    }

    private function invalidCsrf(): Response
    {
        return Response::make('Requete invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
