<?php

declare(strict_types=1);

namespace App\Controllers;

use PDOException;
use Throwable;
use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinThrottle;
use App\Auth\PinVerifier;
use App\Catalogue\CategoryRepository;
use App\Catalogue\ImportBlockedException;
use App\Catalogue\IngredientRepository;
use App\Catalogue\MenuRepository;
use App\Catalogue\ProductImportService;
use App\Catalogue\ProductRepository;
use App\Core\DatabaseInterface;
use App\Core\ImageUploadException;
use App\Core\ImageUploader;
use App\Core\Money;
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
     * Vue de LECTURE du catalogue rangee comme la borne l'affiche : une section par
     * categorie, dans l'ordre des onglets de la borne, avec les variantes de taille
     * repliees sur leur base (F20). Complete la liste plate (index) sans la remplacer :
     * la liste plate reste la seule qui montre et gere les variantes ligne par ligne.
     *
     * Quatre lectures a nombre FIXE, jamais une par article :
     *  - les categories, qui servent d'ossature ordonnee des sections ;
     *  - les produits de base groupes par categorie ;
     *  - le set des produits en rupture calculee (RG-T21) ;
     *  - les menus, parce qu'un menu n'est pas une ligne de la table product : sans eux
     *    la section Menus dirait "aucun article" alors que la borne y montre les menus.
     *
     * Produits et menus sont normalises ICI en une seule forme d'article (avec son etat
     * de disponibilite deja resolu) : la vue reste declarative et l'etat est testable
     * directement, comme pour le tableau de bord stock (ADR-0012).
     *
     * @param array<string, string> $params
     */
    public function byCategory(array $params = []): Response
    {
        $guard = $this->guard('product.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        $categories        = $this->categoryRepository()->all();
        $productsByCat     = $this->productRepository()->basesByCategory();
        $autoUnavailable   = array_fill_keys($this->productRepository()->autoUnavailableIds(), true);
        $menus             = $this->menuRepository()->all();
        $canUpdateProduct  = $this->may($guard, 'product.update');
        $canUpdateMenu     = $this->may($guard, 'menu.update');

        /** @var array<int, list<array<string, mixed>>> $articles */
        $articles = [];
        foreach ($productsByCat as $categoryId => $rows) {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $articles[$categoryId][] = [
                    'id'            => $id,
                    'name'          => (string) $row['name'],
                    'price_cents'   => (int) $row['price_cents'],
                    'vat_rate'      => (int) $row['vat_rate'],
                    'kind'          => 'produit',
                    'state'         => $this->availabilityState((int) $row['is_available'], isset($autoUnavailable[$id])),
                    'variant_count' => (int) $row['variant_count'],
                    'edit_url'      => $canUpdateProduct ? '/admin/products/' . $id . '/edit' : null,
                ];
            }
        }
        foreach ($menus as $row) {
            $categoryId = (int) ($row['category_id'] ?? 0);
            // Un menu impose son burger : il devient non commandable quand ce burger
            // tombe en rupture calculee. Meme regle que la borne (RG-T21, F2).
            $burgerId = (int) ($row['burger_product_id'] ?? 0);
            $articles[$categoryId][] = [
                'id'            => (int) ($row['id'] ?? 0),
                'name'          => (string) ($row['name'] ?? ''),
                'price_cents'   => (int) ($row['price_normal_cents'] ?? 0),
                'vat_rate'      => null,
                'kind'          => 'menu',
                'state'         => $this->availabilityState((int) ($row['is_available'] ?? 0), isset($autoUnavailable[$burgerId])),
                'variant_count' => 0,
                'edit_url'      => $canUpdateMenu ? '/admin/menus/' . (int) ($row['id'] ?? 0) . '/edit' : null,
            ];
        }

        $total = 0;
        $orderable = 0;
        foreach ($articles as $rows) {
            foreach ($rows as $row) {
                $total++;
                if ($row['state'] === 'available') {
                    $orderable++;
                }
            }
        }

        return $this->adminView('admin/products/by_category', [
            'title'          => 'Produits par catégorie - Wakdo Admin',
            'activeNav'      => 'products-by-category',
            'categories'     => $categories,
            'articles'       => $articles,
            'totalArticles'  => $total,
            'nOrderable'     => $orderable,
            'nNotOrderable'  => $total - $orderable,
            // Le rangement est une ECRITURE : il lui faut le jeton anti-rejeu et
            // la meme permission que la modification d'un produit.
            'canReorder'     => $canUpdateProduct,
            'csrfToken'      => Csrf::token($this->sessionManager()),
        ], $guard);
    }

    /**
     * Etat de disponibilite a l'affichage, en TROIS valeurs distinctes : un retrait
     * manuel (is_available = 0) et une rupture calculee par le stock (RG-T21) ont des
     * causes et des remedes differents, les confondre enverrait l'equipier chercher au
     * mauvais endroit. Le retrait manuel prime.
     */
    private function availabilityState(int $isAvailable, bool $autoRupture): string
    {
        if ($isAvailable !== 1) {
            return 'unavailable';
        }

        return $autoRupture ? 'auto_rupture' : 'available';
    }

    /**
     * RG-T03 : la permission est-elle detenue par le role de la session courante ?
     * Utilise pour adapter l'affichage (un lien qui repondrait 403 n'est pas rendu) sans
     * remplacer la garde par-route, qui reste seule a faire foi.
     */
    private function may(GuardResult $guard, string $permission): bool
    {
        return $guard->roleId !== null && $this->authorizer()->can($guard->roleId, $permission);
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

        // A verifier AVANT le CSRF : un corps rejete par post_max_size (image trop
        // lourde) vide _csrf comme tout le reste ; sans cette branche, l'equipier
        // recevrait le 403 generique "Requete invalide" sans savoir pourquoi.
        $oversized = $this->oversizedUploadError();
        if ($oversized !== null) {
            return $this->renderForm($guard, 0, $form, ['image_file' => $oversized], 422);
        }

        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        // id = 0 a la creation : pas d'auto-reference possible (le produit n'existe
        // pas encore), validate() le sait par le 2e argument.
        [$data, $errors] = $this->validate($form, 0);

        // L'image est controlee ICI mais ecrite plus bas : si un autre champ est
        // refuse, le formulaire repart en 422 sans avoir depose de fichier que
        // plus rien ne referencerait.
        $imageFile = $this->request->file('image_file');
        $uploader = $this->imageUploader();
        $hasImage = $imageFile !== null && $uploader->isSubmitted($imageFile);
        if ($hasImage && $imageFile !== null) {
            try {
                $uploader->validate($imageFile, 'products');
            } catch (ImageUploadException $exception) {
                $errors['image_file'] = $exception->getMessage();
            }
        }

        // Composition (recette) integree au formulaire produit : champ cache
        // composition_json, PRESENT ssi la vue l'a rendu (elle le rend toujours).
        // Absent (ex. appelant plus ancien) -> recette non touchee du tout, aucune
        // ecriture product_ingredient supplementaire (retro-compatibilite).
        $canCreateIngredient = $this->may($guard, 'ingredient.manage');
        $hasComposition = array_key_exists('composition_json', $form);
        $discardedIngredientCount = 0;
        $lines = $hasComposition ? $this->parseCompositionLines($form['composition_json'], $errors, $canCreateIngredient, $discardedIngredientCount) : [];

        if ($errors !== []) {
            return $this->renderForm($guard, 0, $form, $errors, 422);
        }

        if ($hasImage && $imageFile !== null) {
            $data['image_path'] = $uploader->store($imageFile, 'products');
        }

        if (!$hasComposition) {
            $this->productRepository()->create($data);
            $this->setFlash('Produit créé.');

            return $this->redirect('/admin/products');
        }

        // Produit + ingredients nouveaux + recette : UNE seule transaction
        // (RG-T08). Un echec a n'importe quelle etape annule tout (rien de
        // partiel : ni produit, ni ingredient, ni recette).
        $createdIngredientNames = [];
        $this->db()->transaction(function (DatabaseInterface $db) use ($data, $lines, &$createdIngredientNames): void {
            $productRepo = new ProductRepository($db);
            $productRepo->create($data);
            $productId = $this->lastInsertId($db);
            [$finalLines, $createdIngredientNames] = $this->materializeCompositionLines($db, $lines);
            $productRepo->replaceCompositionWithin($db, $productId, $finalLines);
        });

        $this->setFlash($this->creationFlashMessage($createdIngredientNames, $discardedIngredientCount));

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

        // La base garde price_cents en centimes ; le champ se relit et se ressaisit
        // en euros (F40, section "Textes techniques ou en anglais" de
        // defauts-visibles.md). Sans cette conversion, un formulaire d'edition
        // reaffiche l'entier brut de centimes.
        $product['price_cents'] = Money::centsToEuros((int) ($product['price_cents'] ?? 0));

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

        $id = (int) ($params['id'] ?? 0);

        $oversized = $this->oversizedUploadError();
        if ($oversized !== null) {
            return $this->renderForm($guard, $id, $form, ['image_file' => $oversized], 422);
        }

        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $current = $this->productRepository()->find($id);
        if ($current === null) {
            return $this->notFound($guard);
        }

        [$data, $errors] = $this->validate($form, $id);

        // Meme discipline qu'a la creation, et elle compte davantage ici : le
        // chemin sensible (prix/TVA) peut encore repartir en 422 apres cette
        // ligne, sur un code a usage unique refuse.
        $imageFile = $this->request->file('image_file');
        $uploader = $this->imageUploader();
        $hasImage = $imageFile !== null && $uploader->isSubmitted($imageFile);
        if ($hasImage && $imageFile !== null) {
            try {
                $uploader->validate($imageFile, 'products');
            } catch (ImageUploadException $exception) {
                $errors['image_file'] = $exception->getMessage();
            }
        }

        // Composition (recette) integree au formulaire produit : voir store().
        $canCreateIngredient = $this->may($guard, 'ingredient.manage');
        $hasComposition = array_key_exists('composition_json', $form);
        $discardedIngredientCount = 0;
        $lines = $hasComposition ? $this->parseCompositionLines($form['composition_json'], $errors, $canCreateIngredient, $discardedIngredientCount) : [];

        // RG-T03 (relecture adverse, 2026-09-26) : ce produit EXISTE deja. Si sa
        // recette changerait REELLEMENT (pas seulement un ingredient nouveau,
        // deja couvert par $canCreateIngredient ci-dessus), la meme permission
        // que la page Recette dediee et l'import CSV est exigee ICI aussi --
        // sinon product.update seul suffisait a vider la recette d'un produit
        // deja en carte (doctrine : docs/api/import-produits.md section 5,
        // "le produit existait-il deja", pas "quel ecran est utilise"). Un
        // resoumis STRICTEMENT identique a la recette enregistree ne declenche
        // rien : seul un changement REEL est concerne.
        if ($hasComposition && $errors === [] && !$canCreateIngredient && $this->compositionDiffersFromStored($id, $lines)) {
            $errors['composition'] = 'Ce produit existe déjà : il faut le droit de gérer les ingrédients pour changer sa recette (demandez à un manager), ou ne modifiez pas la recette.';
        }

        if ($errors !== []) {
            return $this->renderForm($guard, $id, $form, $errors, 422);
        }

        $previousImage = is_string($current['image_path'] ?? null) ? (string) $current['image_path'] : null;

        // RG-T13/8.2 : seul un changement de prix ou de TVA est une action sensible.
        $priceChanged = $data['price_cents'] !== (int) ($current['price_cents'] ?? 0);
        $vatChanged = $data['vat_rate'] !== (int) ($current['vat_rate'] ?? 0);

        if (!$priceChanged && !$vatChanged) {
            if ($hasImage && $imageFile !== null) {
                $data['image_path'] = $uploader->store($imageFile, 'products');
            }

            if (!$hasComposition) {
                $this->productRepository()->update($id, $data);

                // L'ancienne image n'est effacee qu'une fois la base a jour : en cas
                // d'echec de l'ecriture, le produit garde une photo valide.
                if ($hasImage) {
                    $uploader->remove($previousImage);
                }

                $this->setFlash('Produit mis à jour.');

                return $this->redirect('/admin/products');
            }

            // Meme produit + recette en une seule transaction que store() : voir
            // ce docblock pour la raison (RG-T08, rien de partiel).
            $createdIngredientNames = [];
            $this->db()->transaction(function (DatabaseInterface $db) use ($id, $data, $lines, &$createdIngredientNames): void {
                $productRepo = new ProductRepository($db);
                $productRepo->update($id, $data);
                [$finalLines, $createdIngredientNames] = $this->materializeCompositionLines($db, $lines);
                $productRepo->replaceCompositionWithin($db, $id, $finalLines);
            });

            if ($hasImage) {
                $uploader->remove($previousImage);
            }

            $this->setFlash($this->updateFlashMessage($createdIngredientNames, false, $discardedIngredientCount));

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

        if ($hasImage && $imageFile !== null) {
            $data['image_path'] = $uploader->store($imageFile, 'products');
        }

        $summary = $this->changeSummary($current, $data, $priceChanged, $vatChanged);

        $createdIngredientNames = [];
        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $data, $actor, $summary, $hasComposition, $lines, &$createdIngredientNames): void {
            $productRepo = new ProductRepository($db);
            $productRepo->update($id, $data);
            $this->writeAudit($db, 'product.update', $actor['id'], $actor['role_id'], $id, $summary);

            if ($hasComposition) {
                [$finalLines, $createdIngredientNames] = $this->materializeCompositionLines($db, $lines);
                $productRepo->replaceCompositionWithin($db, $id, $finalLines);
            }
        });

        // PIN valide : reinitialise le compteur de throttle de l'acteur de SESSION
        // (RG-T22), apres l'effet reussi. Cle = $actorId ($guard->userId), la meme
        // qu'a l'increment ; surtout PAS $actor['id'] (l'equipier resolu par le PIN,
        // un autre individu) sinon le compteur de l'agissant ne serait jamais purge.
        $this->pinThrottle()->reset($actorId);

        if ($hasImage) {
            $uploader->remove($previousImage);
        }

        $this->setFlash($this->updateFlashMessage($createdIngredientNames, true, $discardedIngredientCount));

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
            . ' (' . $cascaded . ' ligne(s) de recette cascade-supprimée(s))';

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
                return $this->renderDelete($guard, $id, $product, 'Produit référencé par des commandes ou menus : suppression impossible. Masquez-le plutôt.', 409);
            }

            throw $exception;
        }

        // Le produit est parti : son image deposee n'a plus de proprietaire.
        // remove() ignore les chemins du catalogue livre avec le projet, donc
        // supprimer un produit d'origine ne touche pas a ses assets.
        $this->imageUploader()->remove(is_string($product['image_path'] ?? null) ? (string) $product['image_path'] : null);

        // PIN valide et suppression effective : reinitialise le compteur de l'acteur
        // de session (RG-T22, cle = $actorId). Apres le try/catch : non atteint si la
        // FK a bloque (409), ce qui est benin (l'acteur n'est pas un attaquant).
        $this->pinThrottle()->reset($actorId);

        $this->setFlash('Produit supprimé.');

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

        // guard() a deja exige ingredient.manage pour ATTEINDRE cette action :
        // $canCreateIngredient est donc toujours vrai ici (meme code que le
        // formulaire produit, cf. parseCompositionLines()).
        $errors = [];
        $discardedIngredientCount = 0;
        $lines = $this->parseCompositionLines($form['composition_json'] ?? '', $errors, true, $discardedIngredientCount);
        if ($errors !== []) {
            return $this->renderRecipe($guard, $id, $product, $errors, 422);
        }

        // Composition vide autorisee : un produit peut n'avoir aucune recette
        // definie (replaceCompositionWithin purge alors la table sans rien
        // reinserer). UNE transaction : creation d'un ingredient nouveau +
        // recette, meme si aucun ingredient nouveau n'est present ici (RG-T08).
        $createdIngredientNames = [];
        $this->db()->transaction(function (DatabaseInterface $db) use ($id, $lines, &$createdIngredientNames): void {
            $productRepo = new ProductRepository($db);
            [$finalLines, $createdIngredientNames] = $this->materializeCompositionLines($db, $lines);
            $productRepo->replaceCompositionWithin($db, $id, $finalLines);
        });

        $message = $createdIngredientNames === []
            ? 'Recette mise à jour.'
            : sprintf(
                'Recette mise à jour. %d nouvel(nouveaux) ingrédient(s) créé(s) à stock 0 (%s) : pensez à les réapprovisionner et à vérifier leurs allergènes.',
                count($createdIngredientNames),
                implode(', ', $createdIngredientNames),
            );
        $this->setFlash($message . $this->discardedIngredientNotice($discardedIngredientCount));

        return $this->redirect('/admin/products');
    }

    /**
     * Deplace un produit d'un rang dans sa categorie.
     *
     * POST et non GET : l'action change l'etat du catalogue, elle doit donc etre
     * protegee par le jeton anti-rejeu comme les autres ecritures, et ne pas
     * pouvoir etre declenchee par un simple lien visite.
     *
     * @param array<string, string> $params
     */
    public function move(array $params): Response
    {
        $guard = $this->guard('product.update');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $direction = (string) ($form['direction'] ?? '');
        if ($direction !== 'up' && $direction !== 'down') {
            return $this->redirect('/admin/products/by-category');
        }

        // false = produit introuvable OU deja en bout de liste. Le deuxieme cas
        // n'est pas une erreur : l'utilisateur a clique sur une fleche sans
        // effet, on le ramene simplement a sa liste sans message alarmant.
        if ($this->productRepository()->reorderWithinCategory((int) ($params['id'] ?? 0), $direction)) {
            $this->setFlash('Ordre du catalogue mis à jour.');
        }

        return $this->redirect('/admin/products/by-category');
    }

    /** Cle de session du CSV en attente de confirmation (voir importPreview()/importConfirm()). */
    private const IMPORT_SESSION_KEY = '_product_import_pending';

    /** Duree de vie maximale d'un apercu non confirme (secondes). */
    private const IMPORT_TTL_SECONDS = 1800;

    /**
     * Bouton "Télécharger le modèle CSV" -- gabarit avec 2 exemples reels
     * (ProductImportService::templateCsv()). Meme permission que l'import
     * (product.create) : celui qui peut creer un produit peut telecharger le
     * modele pour en preparer plusieurs.
     *
     * @param array<string, string> $params
     */
    public function importTemplate(array $params = []): Response
    {
        $guard = $this->guard('product.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        return Response::make(ProductImportService::templateCsv(), 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="wakdo-import-produits.csv"',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function importForm(array $params = []): Response
    {
        $guard = $this->guard('product.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->renderImportUpload($guard, []);
    }

    /**
     * Premier temps de l'import (2 temps, RG-T18) : ANALYSE seule, aucune
     * ecriture. Le CSV recu est garde en SESSION (jamais en champ cache
     * modifiable par le client) sous un jeton a usage unique, relu et
     * revalide integralement a la confirmation (importConfirm) -- un apercu
     * n'est jamais une source de verite, seule une nouvelle analyse l'est.
     *
     * @param array<string, string> $params
     */
    public function importPreview(array $params = []): Response
    {
        $guard = $this->guard('product.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $file = $this->request->file('csv_file');
        $uploadError = $this->csvUploadError($file);
        if ($uploadError !== null || $file === null) {
            return $this->renderImportUpload($guard, ['fichier' => $uploadError ?? 'Choisissez un fichier CSV.'], 422);
        }

        $content = (string) file_get_contents((string) $file['tmp_name']);

        $report = $this->importService()->preview($content, $this->db());
        $this->guardImportAuthorizations($guard, $report);

        $token = bin2hex(random_bytes(16));
        $this->sessionManager()->set(self::IMPORT_SESSION_KEY, ['token' => $token, 'csv' => $content, 'created_at' => time()]);

        return $this->renderImportPreview($guard, $report, $token, []);
    }

    /**
     * Second temps : confirmation. Rejoue integralement l'analyse (defense
     * contre un etat perime -- un autre equipier a pu creer entre-temps la
     * categorie/le produit/l'ingredient que le fichier visait) et, seulement si
     * zero erreur, ecrit tout en UNE transaction (ProductImportService::apply,
     * tout ou rien). Un changement de prix exige le PIN, comme en HTML
     * (RG-T13) ; sinon aucun PIN n'est demande.
     *
     * @param array<string, string> $params
     */
    public function importConfirm(array $params = []): Response
    {
        $guard = $this->guard('product.create');
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $csv = $this->pendingImportCsv((string) ($form['import_token'] ?? ''));
        if ($csv === null) {
            $this->setFlash('Aperçu expiré ou introuvable : renvoyez le fichier.');

            return $this->redirect('/admin/products/import');
        }

        $report = $this->importService()->preview($csv, $this->db());
        $this->guardImportAuthorizations($guard, $report);

        if ($report['errors'] !== []) {
            return $this->renderImportPreview($guard, $report, (string) ($form['import_token'] ?? ''), [], 422);
        }

        $actorId = $guard->userId ?? 0;
        $actor = ['id' => $guard->userId ?? 0, 'role_id' => $guard->roleId ?? 0];

        if ($report['hasPriceChange']) {
            if ($actorId > 0 && $this->pinThrottle()->isLocked($actorId)) {
                $this->pinVerifier()->payTimingDecoy($form['pin'] ?? '');

                return $this->renderImportPreview($guard, $report, (string) ($form['import_token'] ?? ''), ['pin' => 'Email ou PIN invalide (requis : ce fichier modifie au moins un prix).'], 422);
            }

            $resolved = $this->pinVerifier()->resolveActingUser(trim($form['pin_email'] ?? ''), $form['pin'] ?? '');
            if ($resolved === null) {
                $email = trim($form['pin_email'] ?? '');
                $this->db()->transaction(function (DatabaseInterface $db) use ($email, $actorId): void {
                    $this->logFailedPin($db, $email, 0);
                    $this->pinThrottle()->recordFailureWithin($db, $actorId);
                });

                return $this->renderImportPreview($guard, $report, (string) ($form['import_token'] ?? ''), ['pin' => 'Email ou PIN invalide (requis : ce fichier modifie au moins un prix).'], 422);
            }
            $actor = $resolved;
        }

        try {
            $result = $this->importService()->apply($csv, $this->db(), $actor['id'], $actor['role_id']);
        } catch (ImportBlockedException $exception) {
            $this->sessionManager()->set(self::IMPORT_SESSION_KEY, null);
            $this->setFlash($exception->getMessage() . ' Renvoyez le fichier corrigé.');

            return $this->redirect('/admin/products/import');
        } catch (Throwable $exception) {
            // Filet de securite : apply() n'ecrit que DANS la transaction de
            // ProductImportService (RG-T08), donc toute exception non prevue ici
            // a deja ete annulee (rollback) avant de remonter -- rien n'est resté
            // a moitié écrit. Sans ce filet, une erreur inattendue (ex. une
            // valeur trop longue pour une colonne, si un controle amont a laissé
            // passer un cas non prevu) affichait la page d'erreur brute du
            // gestionnaire generique, illisible pour un equipier.
            //
            // Ce filet court-circuite le gestionnaire GLOBAL (src/public/admin/
            // index.php) qui, lui, trace TOUJOURS avant de repondre ("l'incident
            // doit rester diagnosticable cote serveur") -- meme trace ICI (meme
            // format), sinon ce cas precis redeviendrait invisible cote serveur
            // (releve par relecture adverse, 2026-09-26).
            error_log(sprintf(
                '[wakdo] Unhandled %s: %s @ %s:%d',
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));
            $this->sessionManager()->set(self::IMPORT_SESSION_KEY, null);
            $this->setFlash('L\'import a échoué de façon inattendue : rien n\'a été enregistré. Renvoyez le fichier, ou contactez un administrateur si cela persiste.');

            return $this->redirect('/admin/products/import');
        }

        if ($report['hasPriceChange']) {
            $this->pinThrottle()->reset($actorId);
        }
        $this->sessionManager()->set(self::IMPORT_SESSION_KEY, null);

        $this->setFlash(sprintf(
            'Import terminé : %d produit(s) créé(s), %d mis à jour (dont %d changement(s) de prix), %d inchangé(s), %d ingrédient(s) créé(s).',
            $result['created'],
            $result['updated'],
            $result['price_changed'],
            $result['unchanged'],
            $result['ingredients_created'],
        ));

        return $this->redirect('/admin/products');
    }

    protected function importService(): ProductImportService
    {
        return new ProductImportService();
    }

    /**
     * Point d'extension pour les tests, meme raison et meme convention que
     * ImageUploader::isUploadedFile() : is_uploaded_file() ne repond vrai qu'a
     * la suite d'un veritable envoi HTTP traite par PHP, ce qu'un test
     * unitaire n'a jamais. Les tests sous-classent pour retomber sur
     * l'equivalent filesystem le plus proche (is_file()).
     */
    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    /**
     * Relit le CSV en attente de confirmation depuis la session, seulement si
     * le jeton correspond exactement (hash_equals, comparaison a temps
     * constant) et que l'apercu n'a pas expire (30 min). Retourne null dans
     * tout autre cas (jeton absent/faux, session vide, expiration).
     */
    private function pendingImportCsv(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        $pending = $this->sessionManager()->get(self::IMPORT_SESSION_KEY);
        if (!is_array($pending)) {
            return null;
        }

        $storedToken = (string) ($pending['token'] ?? '');
        if ($storedToken === '' || !hash_equals($storedToken, $token)) {
            return null;
        }

        if (time() - (int) ($pending['created_at'] ?? 0) > self::IMPORT_TTL_SECONDS) {
            $this->sessionManager()->set(self::IMPORT_SESSION_KEY, null);

            return null;
        }

        return (string) ($pending['csv'] ?? '');
    }

    /**
     * Doctrine d'autorisation de l'import (RG-T03, defense en profondeur -- pas
     * seulement filtre sur l'ecran) :
     *
     *  - `product.create` (deja exige a l'entree des 3 actions, guard() route)
     *    suffit pour un fichier qui NE FAIT QUE creer des produits neufs, sans
     *    toucher a un ingredient nouveau.
     *  - `product.update` est EXIGE EN PLUS des qu'au moins un produit du
     *    fichier correspond a un produit DEJA EXISTANT (nom + categorie),
     *    QU'IL SOIT CLASSE "a mettre a jour" OU "inchange" : les deux ecrivent
     *    reellement la ligne produit (apply() appelle
     *    ProductRepository::update() dans les deux cas) et "inchange" ne
     *    protege de rien -- c'est une classification d'affichage, pas une
     *    garantie qu'aucune ecriture n'aura lieu.
     *  - `ingredient.manage` est EXIGEE EN PLUS des qu'au moins un ingredient
     *    SERAIT CREE, **ou** des qu'au moins un produit EXISTANT verrait sa
     *    recette REMPLACEE -- remplacer irreversiblement la recette d'un
     *    produit deja en carte (jusqu'a la vider, RG-T21) est exactement le
     *    geste que la page Recette dediee protege deja par cette permission ;
     *    l'import ne doit pas ouvrir un chemin plus permissif pour le meme effet.
     *
     * Ecart assume avec la page recette dediee (documente aussi dans
     * docs/api/import-produits.md) : composer la recette d'un produit NEUVE
     * depuis LE FORMULAIRE produit ne demande que product.create/update (la
     * recette nait avec un produit qui n'existait pas), alors que remplacer la
     * recette d'un produit EXISTANT -- que ce soit via la page Recette ou via
     * l'import -- demande ingredient.manage. La frontiere est "le produit
     * existait-il deja", pas "quel ecran est utilise".
     *
     * @param array<string, mixed> $report
     */
    protected function guardImportAuthorizations(GuardResult $guard, array &$report): void
    {
        $touchesExistingProduct = $report['productsToUpdate'] !== [] || $report['productsUnchanged'] !== [];
        $createsIngredient = $report['ingredientsToCreate'] !== [];

        if ($touchesExistingProduct && !$this->may($guard, 'product.update')) {
            $report['errors'][] = [
                'line' => 0,
                'column' => 'produit',
                'message' => 'Ce fichier modifie au moins un produit déjà existant : il faut le droit de modifier les produits pour cela (demandez à un manager), ou retirez ces lignes du fichier.',
            ];
        }

        if (($touchesExistingProduct || $createsIngredient) && !$this->may($guard, 'ingredient.manage')) {
            $report['errors'][] = [
                'line' => 0,
                'column' => 'ingredient',
                'message' => $touchesExistingProduct
                    ? 'Ce fichier remplacerait la recette d\'au moins un produit déjà existant (et créerait peut-être aussi de nouveaux ingrédients) : il faut le droit de gérer les ingrédients pour cela (demandez à un manager), ou retirez ces lignes du fichier.'
                    : 'Ce fichier créerait de nouveaux ingrédients : il faut le droit de gérer les ingrédients pour cela (demandez à un manager), ou retirez ces lignes du fichier.',
            ];
        }
    }

    /**
     * Verifications d'upload avant toute analyse (RG-T18) : fichier reellement
     * envoye, taille (post_max_size ET plafond applicatif), extension .csv.
     * Renvoie le message d'erreur a afficher, ou null si l'envoi est correct.
     *
     * @param array<string, mixed>|null $file
     */
    private function csvUploadError(?array $file): ?string
    {
        $oversized = $this->oversizedUploadError();
        if ($oversized !== null) {
            return $oversized;
        }

        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Choisissez un fichier CSV.';
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            return match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf('Le fichier dépasse la taille maximale (%d Mo).', (int) (ProductImportService::MAX_BYTES / (1024 * 1024))),
                UPLOAD_ERR_PARTIAL => 'L\'envoi a été interrompu, réessayez.',
                default => 'L\'envoi du fichier a échoué.',
            };
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return 'Le fichier envoyé est vide.';
        }
        if ($size > ProductImportService::MAX_BYTES) {
            return sprintf('Le fichier dépasse la taille maximale (%d Mo).', (int) (ProductImportService::MAX_BYTES / (1024 * 1024)));
        }

        $name = (string) ($file['name'] ?? '');
        if (!preg_match('/\.csv$/i', $name)) {
            return 'Le fichier doit avoir l\'extension .csv.';
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !$this->isUploadedFile($tmp)) {
            return 'Le fichier reçu n\'est pas un envoi valide.';
        }

        // Garde-fou binaire simple (defense en profondeur) : un CSV texte ne
        // contient jamais d'octet NUL. L'analyse elle-meme (en-tete attendu)
        // refuse deja tout contenu qui ne serait pas un CSV exploitable.
        $sample = (string) file_get_contents($tmp, false, null, 0, 4096);
        if (str_contains($sample, "\0")) {
            return 'Le fichier ne ressemble pas à un CSV texte.';
        }

        return null;
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderImportUpload(GuardResult $guard, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/products/import', [
            'title'     => 'Importer des produits - Wakdo Admin',
            'activeNav' => 'products',
            'columns'   => ProductImportService::COLUMNS,
            'errors'    => $errors,
        ], $guard, $status);
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, string> $errors
     */
    private function renderImportPreview(GuardResult $guard, array $report, string $token, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/products/import_preview', [
            'title'       => 'Aperçu de l\'import - Wakdo Admin',
            'activeNav'   => 'products',
            'report'      => $report,
            'importToken' => $token,
            'errors'      => $errors,
        ], $guard, $status);
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

    protected function menuRepository(): MenuRepository
    {
        return new MenuRepository($this->db());
    }

    protected function imageUploader(): ImageUploader
    {
        return new ImageUploader($this->config);
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
    protected function validate(array $form, int $currentId): array
    {
        $errors = [];

        $categoryRaw = trim($form['category_id'] ?? '');
        $categoryId = ctype_digit($categoryRaw) ? (int) $categoryRaw : 0;
        if ($categoryId === 0 || !$this->productRepository()->categoryExists($categoryId)) {
            $errors['category_id'] = 'Catégorie requise et valide.';
        }

        $name = trim($form['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Le nom est requis (120 caractères max).';
        }

        // Saisie en EUROS (F40, section "Textes techniques ou en anglais" de
        // defauts-visibles.md) : accepte la virgule ou le point comme separateur
        // decimal ("1,90" ou "1.90"). La base reste en centimes ; Money est la
        // source unique de conversion (repli d'edition : voir edit()/renderForm()).
        $priceRaw = trim($form['price_cents'] ?? '');
        $priceCents = Money::parseEurosToCents($priceRaw);
        $priceValid = $priceCents !== null;
        if (!$priceValid) {
            $errors['price_cents'] = 'Le prix doit être un montant en euros strictement positif (ex. 1,90).';
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
            $errors['display_order'] = 'L\'ordre d\'affichage doit être un entier entre 0 et 65535.';
        }

        $description = trim($form['description'] ?? '');
        // `description` est une colonne TEXT (pas VARCHAR) : sa limite MariaDB
        // est en OCTETS (65535), pas en caracteres -- strlen() (octets), pas
        // mb_strlen() (caracteres), a la difference de `image_path` ci-dessus
        // (VARCHAR, limite en caracteres). Trouve et corrige en meme temps que
        // le meme defaut sur l'import CSV (relecture adverse, 2026-09-26) :
        // sans ce controle, une description demesuree passait la validation
        // puis faisait echouer l'ecriture.
        if (strlen($description) > 65535) {
            $errors['description'] = 'La description est trop longue (environ 65 000 caractères maximum, moins si le texte contient beaucoup de caractères spéciaux).';
        }

        // --- Champs de variante (F9-3, R4 / migrations 0006-0007) ---
        // Tous nullables : un champ vide signifie "produit de base / autonome, sans
        // dimension taille ni substitution Maxi". Bornes refletant les colonnes :
        // size_cl SMALLINT UNSIGNED (0..65535), base/maxi FK INT UNSIGNED.

        // size_cl : volume en cl, entier >= 0 si fourni (vide = NULL).
        $sizeRaw = trim($form['size_cl'] ?? '');
        $sizeCl = null;
        if ($sizeRaw !== '') {
            if (!ctype_digit($sizeRaw) || (int) $sizeRaw > 65535) {
                $errors['size_cl'] = 'La taille (en cl) doit être un entier entre 0 et 65535.';
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
                $errors['base_product_id'] = 'Le produit de base doit être un produit existant.';
            } elseif ((int) $baseRaw === $currentId) {
                $errors['base_product_id'] = 'Un produit ne peut pas être sa propre base.';
            } elseif (!$this->productRepository()->productExists((int) $baseRaw)) {
                $errors['base_product_id'] = 'Le produit de base doit être un produit existant.';
            } elseif (!$this->productRepository()->productIsBase((int) $baseRaw)) {
                $errors['base_product_id'] = 'Le produit de base doit lui-même être un produit de base (pas une variante).';
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
                $errors['maxi_variant_product_id'] = 'La variante Maxi doit être un produit existant.';
            } elseif ((int) $maxiRaw === $currentId) {
                $errors['maxi_variant_product_id'] = 'Un produit ne peut pas être sa propre variante Maxi.';
            } elseif (!$this->productRepository()->productExists((int) $maxiRaw)) {
                $errors['maxi_variant_product_id'] = 'La variante Maxi doit être un produit existant.';
            } else {
                $maxiId = (int) $maxiRaw;
            }
        }

        $data = [
            'category_id'             => $categoryId,
            'name'                    => $name,
            'description'             => $description !== '' ? $description : null,
            'price_cents'             => $priceValid ? $priceCents : 0,
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
    protected function changeSummary(array $current, array $data, bool $priceChanged, bool $vatChanged): string
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
                'summary' => 'Échec PIN action sensible (email tenté: ' . $email . ')',
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
    protected function parseComposition(string $json, array &$errors): array
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
                $errors['composition'] = 'La quantité normale doit être un entier >= 1.';
                continue;
            }
            if ($qm < $qn || $qm > 65535) {
                $errors['composition'] = 'La quantité maxi doit être >= la quantité normale.';
                continue;
            }
            if ($extra < 0 || $extra > 4294967295) {
                $errors['composition'] = 'Le supplément (en centimes) doit être un entier >= 0.';
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
     * Variante etendue de parseComposition() : en plus d'un ingredient EXISTANT
     * (ingredient_id, meme allowlist -- ingredient inconnu filtre, dedup par PK
     * composite), accepte une ligne portant `new_ingredient` (nom + unite
     * requis, conditionnement/capacite optionnels) pour creer l'ingredient A LA
     * VOLEE, sans quitter le formulaire produit. Partagee par saveRecipe() (page
     * recette dediee -- meme code, elle ne diverge pas) et
     * ProductController::store()/update() (recette integree au formulaire
     * produit). $canCreateIngredient reflete la permission `ingredient.manage`
     * (RG-T03, defense en profondeur : meme si l'ecran cache le bouton de
     * creation a un role qui ne l'a pas, le serveur revalide et refuse la ligne).
     *
     * @param array<string, string> $errors
     * @return list<array{is_new: bool, ingredient_id: ?int, name: ?string, unit: ?string, pack_size: ?int, pack_label: ?string, stock_capacity: ?int, quantity_normal: int, quantity_maxi: int, is_removable: int, is_addable: int, extra_price_cents: int}>
     */
    protected function parseCompositionLines(string $json, array &$errors, bool $canCreateIngredient, int &$discardedIngredientCount = 0): array
    {
        $discardedIngredientCount = 0;
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
        $seenExisting = [];
        $seenNewNames = [];
        foreach ($decoded as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $qn = is_numeric($raw['quantity_normal'] ?? null) ? (int) $raw['quantity_normal'] : 0;
            $qm = is_numeric($raw['quantity_maxi'] ?? null) ? (int) $raw['quantity_maxi'] : 0;
            $extra = is_numeric($raw['extra_price_cents'] ?? null) ? (int) $raw['extra_price_cents'] : -1;
            $removable = empty($raw['is_removable']) ? 0 : 1;
            $addable = empty($raw['is_addable']) ? 0 : 1;

            $newIngredient = is_array($raw['new_ingredient'] ?? null) ? $raw['new_ingredient'] : null;
            if ($newIngredient !== null) {
                if (!$canCreateIngredient) {
                    $errors['composition'] = 'Vous n\'avez pas la permission de créer un nouvel ingrédient (ingredient.manage requis) : choisissez un ingrédient existant.';
                    continue;
                }

                $name = trim((string) ($newIngredient['name'] ?? ''));
                $unit = trim((string) ($newIngredient['unit'] ?? ''));
                if ($name === '' || mb_strlen($name) > 120) {
                    $errors['composition'] = 'Le nom du nouvel ingrédient est requis (120 caractères max).';
                    continue;
                }
                if ($unit === '' || mb_strlen($unit) > 40) {
                    $errors['composition'] = 'L\'unité du nouvel ingrédient est requise (40 caractères max).';
                    continue;
                }
                if (isset($seenNewNames[mb_strtolower($name)]) || $this->ingredientRepository()->nameExists($name)) {
                    $errors['composition'] = sprintf('Un ingrédient nommé "%s" existe déjà (ou est déjà proposé à la création juste au-dessus).', $name);
                    continue;
                }

                $packRaw = trim((string) ($newIngredient['pack_size'] ?? ''));
                $packSize = ProductImportService::DEFAULT_PACK_SIZE;
                if ($packRaw !== '') {
                    if (!ctype_digit($packRaw) || (int) $packRaw < 1 || (int) $packRaw > 65535) {
                        $errors['composition'] = 'La taille de conditionnement doit être un entier entre 1 et 65535.';
                        continue;
                    }
                    $packSize = (int) $packRaw;
                }

                $capRaw = trim((string) ($newIngredient['stock_capacity'] ?? ''));
                $capacity = ProductImportService::DEFAULT_STOCK_CAPACITY;
                if ($capRaw !== '') {
                    if (!ctype_digit($capRaw) || (int) $capRaw < 1 || (int) $capRaw > 2147483647) {
                        $errors['composition'] = 'La capacité doit être un entier >= 1.';
                        continue;
                    }
                    $capacity = (int) $capRaw;
                }

                $packLabel = trim((string) ($newIngredient['pack_label'] ?? ''));
                if (mb_strlen($packLabel) > 80) {
                    $errors['composition'] = 'Le libellé de conditionnement est trop long (80 caractères max).';
                    continue;
                }

                if ($qn < 1 || $qn > 65535) {
                    $errors['composition'] = 'La quantité normale doit être un entier >= 1.';
                    continue;
                }
                if ($qm < $qn || $qm > 65535) {
                    $errors['composition'] = 'La quantité maxi doit être >= la quantité normale.';
                    continue;
                }
                if ($extra < 0 || $extra > 4294967295) {
                    $errors['composition'] = 'Le supplément (en centimes) doit être un entier >= 0.';
                    continue;
                }

                $seenNewNames[mb_strtolower($name)] = true;
                $lines[] = [
                    'is_new' => true, 'ingredient_id' => null, 'name' => $name, 'unit' => $unit,
                    'pack_size' => $packSize, 'pack_label' => $packLabel !== '' ? $packLabel : null,
                    'stock_capacity' => $capacity,
                    'quantity_normal' => $qn, 'quantity_maxi' => $qm,
                    'is_removable' => $removable, 'is_addable' => $addable, 'extra_price_cents' => $extra,
                ];
                continue;
            }

            $ingredientId = is_numeric($raw['ingredient_id'] ?? null) ? (int) $raw['ingredient_id'] : 0;
            if ($ingredientId <= 0 || !$this->productRepository()->ingredientExists($ingredientId)) {
                // Filtre en silence cote donnees (allowlist RG-T16 : jamais un
                // raw DB-detail cote equipier), mais COMPTE : un role qui a le
                // droit ne doit pas perdre une ligne de recette sans le savoir
                // (formulaire perime -- l'ingredient a ete supprime entre
                // l'affichage et la soumission). Signale dans le message de
                // confirmation (relecture adverse n°3, 2026-09-26).
                ++$discardedIngredientCount;
                continue;
            }
            if (isset($seenExisting[$ingredientId])) {
                continue; // PK composite (product_id, ingredient_id) : un seul par ingredient
            }

            if ($qn < 1 || $qn > 65535) {
                $errors['composition'] = 'La quantité normale doit être un entier >= 1.';
                continue;
            }
            if ($qm < $qn || $qm > 65535) {
                $errors['composition'] = 'La quantité maxi doit être >= la quantité normale.';
                continue;
            }
            if ($extra < 0 || $extra > 4294967295) {
                $errors['composition'] = 'Le supplément (en centimes) doit être un entier >= 0.';
                continue;
            }

            $seenExisting[$ingredientId] = true;
            $lines[] = [
                'is_new' => false, 'ingredient_id' => $ingredientId, 'name' => null, 'unit' => null,
                'pack_size' => null, 'pack_label' => null, 'stock_capacity' => null,
                'quantity_normal' => $qn, 'quantity_maxi' => $qm,
                'is_removable' => $removable, 'is_addable' => $addable, 'extra_price_cents' => $extra,
            ];
        }

        return $lines;
    }

    /**
     * Cree en base chaque ligne `is_new` (stock 0, seuils par defaut du projet --
     * memes constantes que ProductImportService, source unique) et renvoie la
     * liste APLATIE prete pour ProductRepository::replaceCompositionWithin()
     * (tous les ingredient_id resolus, nouveaux comme existants), ainsi que les
     * noms crees (pour le message de confirmation : "pensez a reapprovisionner").
     * A appeler DANS la transaction ouverte par l'appelant (RG-T08).
     *
     * @param list<array<string, mixed>> $lines
     * @return array{0: list<array{ingredient_id:int, quantity_normal:int, quantity_maxi:int, is_removable:int, is_addable:int, extra_price_cents:int}>, 1: list<string>}
     */
    private function materializeCompositionLines(DatabaseInterface $db, array $lines): array
    {
        $ingredientRepo = new IngredientRepository($db);
        $final = [];
        $createdNames = [];

        foreach ($lines as $line) {
            if ($line['is_new']) {
                $ingredientRepo->create([
                    'name'               => $line['name'],
                    'unit'               => $line['unit'],
                    'stock_quantity'     => 0,
                    'stock_capacity'     => $line['stock_capacity'],
                    'pack_size'          => $line['pack_size'],
                    'pack_label'         => $line['pack_label'],
                    'low_stock_pct'      => ProductImportService::DEFAULT_LOW_STOCK_PCT,
                    'critical_stock_pct' => ProductImportService::DEFAULT_CRITICAL_STOCK_PCT,
                    'is_active'          => 1,
                ]);
                $ingredientId = $this->lastInsertId($db);
                $createdNames[] = $line['name'];
            } else {
                $ingredientId = $line['ingredient_id'];
            }

            $final[] = [
                'ingredient_id'     => $ingredientId,
                'quantity_normal'   => $line['quantity_normal'],
                'quantity_maxi'     => $line['quantity_maxi'],
                'is_removable'      => $line['is_removable'],
                'is_addable'        => $line['is_addable'],
                'extra_price_cents' => $line['extra_price_cents'],
            ];
        }

        return [$final, $createdNames];
    }

    /**
     * Le contenu de $lines (deja parse par parseCompositionLines()) DIFFERE-t-il
     * de la composition ACTUELLEMENT enregistree pour ce produit ? Une ligne
     * "nouvel ingredient" differe TOUJOURS (il n'existe pas encore dans la
     * composition actuelle). Comparaison EXACTE (ksort + !==), volontairement
     * INSENSIBLE A L'ORDRE des lignes : le client les soumet dans l'ordre
     * d'ajout/affichage, la base les relit triees par nom d'ingredient
     * (ProductRepository::composition()) -- un simple !== sur les tableaux
     * tels quels serait sensible a cet ordre et exigerait ingredient.manage
     * sur un RESOUMIS strictement identique, sans le moindre changement reel.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function compositionDiffersFromStored(int $productId, array $lines): bool
    {
        $current = [];
        foreach ($this->productRepository()->composition($productId) as $row) {
            $current[(int) $row['ingredient_id']] = [
                'quantity_normal'   => (int) $row['quantity_normal'],
                'quantity_maxi'     => (int) $row['quantity_maxi'],
                'is_removable'      => (int) $row['is_removable'],
                'is_addable'        => (int) $row['is_addable'],
                'extra_price_cents' => (int) $row['extra_price_cents'],
            ];
        }

        $submitted = [];
        foreach ($lines as $line) {
            if ($line['is_new']) {
                return true;
            }
            $submitted[(int) $line['ingredient_id']] = [
                'quantity_normal'   => (int) $line['quantity_normal'],
                'quantity_maxi'     => (int) $line['quantity_maxi'],
                'is_removable'      => (int) $line['is_removable'],
                'is_addable'        => (int) $line['is_addable'],
                'extra_price_cents' => (int) $line['extra_price_cents'],
            ];
        }

        ksort($current);
        ksort($submitted);

        return $current !== $submitted;
    }

    /**
     * `SELECT LAST_INSERT_ID()` sur LA CONNEXION DE LA TRANSACTION EN COURS
     * ($db, pas $this->db()) : indispensable pour recuperer l'id d'un produit ou
     * d'un ingredient qui vient d'etre insere DANS la meme transaction (product,
     * puis chaque ingredient nouveau). Meme technique que
     * Admin\Api\JsonApiTrait::lastInsertId(), dupliquee ici (pas de base commune
     * entre un controleur HTML et le trait JSON) : ProductRepository/
     * IngredientRepository::create() ne renvoient pas l'id cree.
     */
    private function lastInsertId(DatabaseInterface $db): int
    {
        return (int) ($db->fetch('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
    }

    /**
     * @param list<string> $createdIngredientNames
     */
    private function creationFlashMessage(array $createdIngredientNames, int $discardedIngredientCount = 0): string
    {
        $message = $createdIngredientNames === []
            ? 'Produit créé.'
            : sprintf(
                'Produit créé. %d nouvel(nouveaux) ingrédient(s) créé(s) à stock 0 (%s) : pensez à les réapprovisionner et à vérifier leurs allergènes (aucune revue n\'existe encore pour eux).',
                count($createdIngredientNames),
                implode(', ', $createdIngredientNames),
            );

        return $message . $this->discardedIngredientNotice($discardedIngredientCount);
    }

    /**
     * @param list<string> $createdIngredientNames
     */
    private function updateFlashMessage(array $createdIngredientNames, bool $sensitiveChange, int $discardedIngredientCount = 0): string
    {
        $base = $sensitiveChange ? 'Produit mis à jour (changement de prix/TVA tracé).' : 'Produit mis à jour.';
        $message = $createdIngredientNames === []
            ? $base
            : $base . sprintf(
                ' %d nouvel(nouveaux) ingrédient(s) créé(s) à stock 0 (%s) : pensez à les réapprovisionner et à vérifier leurs allergènes (aucune revue n\'existe encore pour eux).',
                count($createdIngredientNames),
                implode(', ', $createdIngredientNames),
            );

        return $message . $this->discardedIngredientNotice($discardedIngredientCount);
    }

    /**
     * Un ingredient_id inconnu est filtre en SILENCE dans
     * parseCompositionLines() (allowlist RG-T16 : jamais un raw DB-detail
     * cote equipier), mais un role qui A le droit ne doit pas perdre une
     * ligne de recette sans le savoir -- formulaire perime : l'ingredient a
     * ete supprime entre l'affichage et la soumission. Signale dans le
     * message de confirmation (releve par relecture adverse n°3, 2026-09-26).
     */
    private function discardedIngredientNotice(int $discardedIngredientCount): string
    {
        if ($discardedIngredientCount <= 0) {
            return '';
        }

        return sprintf(
            ' Attention : %d ligne(s) de recette ignorée(s) (ingrédient introuvable, probablement supprimé entre-temps) — vérifiez la recette.',
            $discardedIngredientCount,
        );
    }

    /**
     * Composition d'un produit (vide a la creation, id = 0), au format
     * ATTENDU EN ENTREE par le builder JS partage (product-recipe.js) :
     * `ingredient_id` present, jamais `new_ingredient` (une composition deja en
     * base ne contient que des ingredients existants). Sert a pre-remplir le
     * champ cache compositionJson du formulaire produit a l'affichage initial
     * (GET create/edit) ; un re-rendu apres erreur (POST) echo au contraire le
     * JSON exactement tel que soumis (voir renderForm()).
     *
     * @return list<array{ingredient_id:int, quantity_normal:int, quantity_maxi:int, is_removable:int, is_addable:int, extra_price_cents:int}>
     */
    private function slimComposition(int $id): array
    {
        if ($id === 0) {
            return [];
        }

        return array_map(
            static fn (array $c): array => [
                'ingredient_id'     => (int) ($c['ingredient_id'] ?? 0),
                'quantity_normal'   => (int) ($c['quantity_normal'] ?? 1),
                'quantity_maxi'     => (int) ($c['quantity_maxi'] ?? 1),
                'is_removable'      => (int) ($c['is_removable'] ?? 0),
                'is_addable'        => (int) ($c['is_addable'] ?? 0),
                'extra_price_cents' => (int) ($c['extra_price_cents'] ?? 0),
            ],
            $this->productRepository()->composition($id),
        );
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

        // Composition (recette) integree au formulaire produit : sur un
        // re-rendu apres erreur (422), $values PORTE le composition_json
        // exactement tel que soumis (l'utilisateur ne perd pas sa saisie sur une
        // erreur ailleurs dans le formulaire) ; sinon (affichage initial GET,
        // creation OU edition), on part de la composition reelle en base (vide
        // a la creation, id = 0).
        $compositionJson = array_key_exists('composition_json', $values)
            ? (string) $values['composition_json']
            : (string) json_encode($this->slimComposition($id), JSON_UNESCAPED_UNICODE);

        return $this->adminView('admin/products/form', [
            'title'                => ($id !== 0 ? 'Modifier' : 'Nouveau') . ' produit - Wakdo Admin',
            'activeNav'            => 'products',
            'productId'            => $id,
            'categories'           => $this->categoryRepository()->all(),
            'baseCandidates'       => $baseCandidates,
            'ingredients'          => $this->ingredientRepository()->all(),
            'compositionJson'      => $compositionJson,
            'canCreateIngredient'  => $this->may($guard, 'ingredient.manage'),
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
        return Response::make('Requête invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
