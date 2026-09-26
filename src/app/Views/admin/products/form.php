<?php

declare(strict_types=1);

/**
 * Formulaire produit (creation/edition), injecte dans admin/layout.php. Reaffiche
 * valeurs + erreurs (RG-T18). La section email + PIN n'est requise que pour un
 * changement de prix/TVA en edition (RG-T13, modele equipier + PIN). CSRF cache.
 *
 * @var int                               $productId
 * @var array<int, array<string, mixed>>  $categories
 * @var array<int, array<string, mixed>>  $baseCandidates  produits de base eligibles (R4)
 * @var array<int, array<string, mixed>>  $ingredients  catalogue pour le picker de recette
 * @var string                            $compositionJson  composition initiale (JSON), voir ProductController::renderForm()
 * @var bool                              $canCreateIngredient  permission ingredient.manage (bouton "nouvel ingrédient")
 * @var array<string, mixed>              $values
 * @var array<string, string>             $errors
 * @var string                            $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($productId ?? 0);
$action = $id !== 0 ? '/admin/products/' . $id : '/admin/products';

/** @var array<string, mixed> $vals */
$vals = isset($values) && is_array($values) ? $values : [];
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];
/** @var array<int, array<string, mixed>> $cats */
$cats = isset($categories) && is_array($categories) ? $categories : [];
/** @var array<int, array<string, mixed>> $bases */
$bases = isset($baseCandidates) && is_array($baseCandidates) ? $baseCandidates : [];

$val = static fn (string $k): string => htmlspecialchars((string) ($vals[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static fn (string $k): string => isset($errs[$k]) && is_string($errs[$k]) ? $errs[$k] : '';
$selectedCat = (string) ($vals['category_id'] ?? '');
$selectedVat = (string) ($vals['vat_rate'] ?? '100');
$available = (bool) ($vals['is_available'] ?? true);
$selectedBase = (string) ($vals['base_product_id'] ?? '');
$selectedMaxi = (string) ($vals['maxi_variant_product_id'] ?? '');

/** @var array<int, array<string, mixed>> $ings */
$ings = isset($ingredients) && is_array($ingredients) ? $ingredients : [];
$slimIngredients = array_map(
    static fn (array $i): array => ['id' => (int) ($i['id'] ?? 0), 'name' => (string) ($i['name'] ?? ''), 'unit' => (string) ($i['unit'] ?? '')],
    $ings,
);
$compositionJsonValue = (string) ($compositionJson ?? '[]');
$canCreateIngredientFlag = (bool) ($canCreateIngredient ?? false);
$attr = static fn (mixed $data): string => htmlspecialchars((string) json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

// "Variantes" est replie dans un <details class="form-advanced"> (design-system.md
// 2.5) : trois champs que la grande majorite des produits laisse vides. Ouvert
// d'office si l'un des trois porte deja une valeur, sinon un champ rempli
// resterait invisible a la reouverture du formulaire (meme regle que
// categories/form.php pour le chemin d'image).
$hasVariantValue = ($vals['size_cl'] ?? '') !== ''
    || ($vals['base_product_id'] ?? '') !== ''
    || ($vals['maxi_variant_product_id'] ?? '') !== '';
// Une erreur de validation sur un champ replie doit etre visible sans avoir a
// deplier : sans cela le formulaire refuse d'enregistrer sans dire pourquoi.
$hasVariantError = $err('size_cl') !== '' || $err('base_product_id') !== '' || $err('maxi_variant_product_id') !== '';
$variantsOpen = ($hasVariantValue || $hasVariantError) ? ' open' : '';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id !== 0 ? 'Modifier le produit' : 'Nouveau produit' ?></h1>
    </div>
</div>

<?php /* data-row-key : apres un enregistrement reussi, le controleur redirige vers
         /admin/products ; stock-thresholds.js (initRowHighlight) retrouve la ligne
         portant la meme cle et la met en evidence quelques secondes. Le commentaire
         de products/index.php demandait ce complement -- la liste porte deja la cle
         cote ligne (lot 1), c'est le formulaire qui ne la posait pas. Uniquement en
         EDITION : a la creation, l'identifiant n'existe pas encore. */ ?>
<?php /* data-pin-when-changed : la confirmation par code personnel ne s'ouvre que si
         le prix ou la TVA a bouge -- le serveur n'exige le PIN que dans ce cas
         (ProductController::update, RG-T13/8.2). Sans cet attribut, changer la
         recette ou corriger un nom aurait reclame un code, ce que la regle ne
         demande pas. Voir pin-modal.js pour le detail (et pour le fait que le
         serveur reste l'autorite). */ ?>
<form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card"<?= $id !== 0 ? ' data-pin-when-changed="price_cents,vat_rate" data-row-key="product:' . $id . '"' : '' ?>>
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="category_id">Catégorie</label>
        <select class="form-input" id="category_id" name="category_id" required>
            <option value="">-- choisir --</option>
            <?php foreach ($cats as $cat): ?>
                <?php $cid = (string) ($cat['id'] ?? ''); ?>
                <option value="<?= htmlspecialchars($cid, ENT_QUOTES, 'UTF-8') ?>"<?= $cid === $selectedCat ? ' selected' : '' ?>>
                    <?= htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($err('category_id') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('category_id'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="name">Nom</label>
        <input class="form-input" type="text" id="name" name="name" maxlength="120" value="<?= $val('name') ?>" required>
        <?php if ($err('name') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('name'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="description">Description</label>
        <textarea class="form-input" id="description" name="description"><?= $val('description') ?></textarea>
        <?php if ($err('description') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('description'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="price_cents">Prix (en euros)</label>
        <input class="form-input" type="text" inputmode="decimal" id="price_cents" name="price_cents"
               pattern="(?=.*[1-9])[0-9]{1,7}([.,][0-9]{1,2})?" data-pattern-message="Montant invalide (exemple : 1,90)."
               placeholder="ex. 1,90" value="<?= $val('price_cents') ?>" required>
        <?php if ($err('price_cents') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('price_cents'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="vat_rate">TVA</label>
        <select class="form-input" id="vat_rate" name="vat_rate">
            <option value="100"<?= $selectedVat === '100' ? ' selected' : '' ?>>10% (sur place / général)</option>
            <option value="55"<?= $selectedVat === '55' ? ' selected' : '' ?>>5,5% (à emporter)</option>
        </select>
        <?php if ($err('vat_rate') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('vat_rate'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="image_file">Image du produit</label>

        <!-- Le champ fichier reste visible et atteignable au clavier : la zone de
             depot l'entoure sans le remplacer, donc le formulaire marche aussi
             bien a la souris, au clavier, et sans JavaScript (Cr 1.c.4). -->
        <div class="image-drop" data-image-drop>
            <img class="image-drop-preview" data-image-drop-preview alt="" hidden>
            <input class="image-drop-input" type="file" id="image_file" name="image_file" accept="image/jpeg,image/png,image/webp">
            <p class="image-drop-note" data-image-drop-hint>
                Glissez une image ici, ou utilisez le bouton ci-dessus. JPEG, PNG ou WebP, 5 Mo maximum.
            </p>
        </div>
        <?php if ($err('image_file') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('image_file'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

        <?php /* Champ secondaire (rarement modifie) replie, comme dans
                 categories/form.php : meme section du systeme de design
                 (design-system.md 2.5), meme regle d'ouverture si le champ
                 porte deja une valeur. */ ?>
        <details class="form-advanced"<?= ($id !== 0 && ($vals['image_path'] ?? '') !== '') || $err('image_path') !== '' ? ' open' : '' ?>>
            <summary>Ou : chemin d'une image déjà présente sur le serveur (optionnel)</summary>
            <label class="form-label" for="image_path">Chemin de l'image</label>
            <input class="form-input" type="text" id="image_path" name="image_path" maxlength="255" value="<?= $val('image_path') ?>">
            <p class="image-drop-note">Une image déposée ci-dessus remplace ce chemin après enregistrement.</p>
            <?php if ($err('image_path') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('image_path'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        </details>
    </div>

    <div class="form-group">
        <label class="form-label" for="display_order">Ordre d'affichage</label>
        <input class="form-input" type="number" id="display_order" name="display_order" min="0" max="65535" value="<?= $val('display_order') ?>" required>
        <?php if ($err('display_order') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('display_order'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label form-check"><input type="checkbox" name="is_available" value="1"<?= $available ? ' checked' : '' ?>> <span>Disponible à la vente</span></label>
    </div>

    <details class="form-advanced"<?= $variantsOpen ?>>
        <summary>Tailles et format Maxi (optionnel)</summary>
        <p><small>À remplir seulement pour une boisson en plusieurs tailles ou un accompagnement servi en plus grand au format Maxi. Laissez vide pour un produit ordinaire.</small></p>

        <div class="form-group">
            <label class="form-label" for="size_cl">Taille en centilitres (boissons)</label>
            <input class="form-input" type="number" id="size_cl" name="size_cl" min="0" max="65535" value="<?= $val('size_cl') ?>">
            <small>Exemple : 30 ou 50 pour un soda. Laissez vide si le produit n'a pas de taille.</small>
            <?php if ($err('size_cl') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('size_cl'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="base_product_id">Variante de taille de</label>
            <select class="form-input" id="base_product_id" name="base_product_id">
                <option value="">-- ce produit n'est pas une variante --</option>
                <?php foreach ($bases as $b): ?>
                    <?php $bid = (string) ($b['id'] ?? ''); ?>
                    <option value="<?= htmlspecialchars($bid, ENT_QUOTES, 'UTF-8') ?>"<?= $bid === $selectedBase ? ' selected' : '' ?>>
                        <?= htmlspecialchars((string) ($b['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Rattache ce produit à un produit principal comme une autre taille (exemple : "Coca 50cl" rattaché à "Coca"). Une variante n'apparaît pas seule sur la borne.</small>
            <?php if ($err('base_product_id') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('base_product_id'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="maxi_variant_product_id">Version servie en format Maxi</label>
            <select class="form-input" id="maxi_variant_product_id" name="maxi_variant_product_id">
                <option value="">-- aucune (pas de version Maxi) --</option>
                <?php foreach ($bases as $b): ?>
                    <?php $bid = (string) ($b['id'] ?? ''); ?>
                    <option value="<?= htmlspecialchars($bid, ENT_QUOTES, 'UTF-8') ?>"<?= $bid === $selectedMaxi ? ' selected' : '' ?>>
                        <?= htmlspecialchars((string) ($b['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Le produit servi à la place de celui-ci quand le menu est commandé en Maxi (exemple : "Moyenne Frite" servie en "Grande Frite").</small>
            <?php if ($err('maxi_variant_product_id') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('maxi_variant_product_id'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        </div>
    </details>

    <fieldset class="form-group">
        <legend>Composition du produit (sa recette)</legend>
        <p><small>Les ingrédients qui composent ce produit. Un ingrédient non retirable dont le stock tombe à 0 met le produit en rupture automatique à la borne.</small></p>
        <?php if (($errs['composition'] ?? '') !== ''): ?><p class="form-error" id="composition-error"><?= htmlspecialchars($errs['composition'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <div id="recipe-builder"
             data-ingredients="<?= $attr($slimIngredients) ?>"
             data-composition="<?= htmlspecialchars($compositionJsonValue, ENT_QUOTES, 'UTF-8') ?>"
             data-can-create-ingredient="<?= $canCreateIngredientFlag ? '1' : '0' ?>"
             <?= ($errs['composition'] ?? '') !== '' ? 'aria-describedby="composition-error"' : '' ?>></div>
        <div class="form-actions">
            <button class="btn btn-secondary" type="button" id="add-ingredient">Ajouter un ingrédient</button>
            <?php if ($canCreateIngredientFlag): ?>
                <button class="btn btn-secondary" type="button" id="add-new-ingredient">Créer un nouvel ingrédient</button>
            <?php endif; ?>
        </div>
    </fieldset>

    <input type="hidden" name="composition_json" id="composition_json" value="<?= htmlspecialchars($compositionJsonValue, ENT_QUOTES, 'UTF-8') ?>">

    <?php if ($id !== 0): ?>
        <fieldset class="form-group">
            <legend>Changement de prix ou de TVA : confirmation par code personnel</legend>
            <p><small>Un changement de prix ou de TVA est tracé et doit être confirmé. Une fenêtre vous demandera votre email et votre code au moment d'enregistrer ; ces champs ne servent que si le navigateur ne peut pas l'ouvrir.</small></p>
            <div class="form-group">
                <label class="form-label" for="pin_email">Votre email</label>
                <input class="form-input" type="email" id="pin_email" name="pin_email" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label" for="pin">Votre code personnel</label>
                <input class="form-input" type="password" id="pin" name="pin" inputmode="numeric" autocomplete="off">
            </div>
            <?php if ($err('pin') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('pin'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Enregistrer</button>
        <a class="btn btn-secondary" href="/admin/products">Annuler</a>
    </div>
</form>

<script src="/assets/js/image-drop.js"></script>
<script src="/assets/js/product-recipe.js"></script>
