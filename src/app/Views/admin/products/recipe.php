<?php

declare(strict_types=1);

/**
 * Editeur de recette d'un produit (composition product_ingredient), injecte dans
 * admin/layout.php. La composition est geree par le builder vanilla product-recipe.js
 * qui serialise son etat dans le champ cache composition_json a la soumission. Pas
 * de PIN (editer une recette n'est pas une action sensible RG-T13). Permission
 * ingredient.manage (distincte du CRUD produit). CSP 'self' : aucun script inline,
 * donnees passees en attributs data-*.
 *
 * @var int                              $productId
 * @var string                           $productName
 * @var array<int, array<string, mixed>> $ingredients  catalogue pour le picker
 * @var array<int, array<string, mixed>> $composition  lignes existantes
 * @var array<string, string>            $errors
 * @var string                           $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($productId ?? 0);
$name = htmlspecialchars((string) ($productName ?? ''), ENT_QUOTES, 'UTF-8');
$action = '/admin/products/' . $id . '/recipe';

/** @var array<int, array<string, mixed>> $ings */
$ings = isset($ingredients) && is_array($ingredients) ? $ingredients : [];
/** @var array<int, array<string, mixed>> $comp */
$comp = isset($composition) && is_array($composition) ? $composition : [];
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];
$compError = isset($errs['composition']) && is_string($errs['composition']) ? $errs['composition'] : '';

// Donnees pour le builder JS, en attributs data-* (CSP 'self'). htmlspecialchars
// rend le JSON sur-able comme valeur d'attribut.
$slimIngredients = array_map(
    static fn (array $i): array => [
        'id'   => (int) ($i['id'] ?? 0),
        'name' => (string) ($i['name'] ?? ''),
        'unit' => (string) ($i['unit'] ?? ''),
    ],
    $ings,
);
$slimComposition = array_map(
    static fn (array $c): array => [
        'ingredient_id'     => (int) ($c['ingredient_id'] ?? 0),
        'quantity_normal'   => (int) ($c['quantity_normal'] ?? 1),
        'quantity_maxi'     => (int) ($c['quantity_maxi'] ?? 1),
        'is_removable'      => (int) ($c['is_removable'] ?? 0),
        'is_addable'        => (int) ($c['is_addable'] ?? 0),
        'extra_price_cents' => (int) ($c['extra_price_cents'] ?? 0),
    ],
    $comp,
);
$attr = static fn (mixed $data): string => htmlspecialchars(
    (string) json_encode($data, JSON_UNESCAPED_UNICODE),
    ENT_QUOTES,
    'UTF-8',
);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Recette - <?= $name ?></h1>
        <p class="page-subtitle">Composition en ingrédients : la disponibilité du produit en découle</p>
    </div>
    <?php /* Meme emplacement que les autres ecrans du back-office (page-actions,
             lot 0) : les deux pages ou l'on rebondit depuis une recette sont la
             fiche du produit et le stock des ingredients qu'elle consomme. */ ?>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/edit">Fiche du produit</a>
        <a class="btn btn-secondary" href="/admin/ingredients">Stock des ingrédients</a>
    </div>
</div>

<?php /* data-row-key : apres enregistrement, le controleur redirige vers
         /admin/products ; stock-thresholds.js met alors la ligne de ce produit en
         evidence quelques secondes, pour qu'on retrouve d'un coup d'oeil celui
         qu'on vient de modifier. */ ?>
<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card" id="recipe-form" data-row-key="product:<?= $id ?>">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <fieldset class="form-group">
        <legend>Ingrédients</legend>
        <p><small>Un ingrédient que le client ne peut pas retirer bloque la vente du produit dès que son stock est critique. Un ingrédient retirable ou proposé en supplément ne bloque jamais le produit.</small></p>
        <?php if ($compError !== ''): ?><p class="form-error" id="composition-error"><?= htmlspecialchars($compError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <div id="recipe-builder"
             data-ingredients="<?= $attr($slimIngredients) ?>"
             data-composition="<?= $attr($slimComposition) ?>"
             data-can-create-ingredient="1"
             <?= $compError !== '' ? 'aria-describedby="composition-error"' : '' ?>></div>
        <div class="form-actions">
            <button class="btn btn-secondary" type="button" id="add-ingredient">Ajouter un ingrédient</button>
            <button class="btn btn-secondary" type="button" id="add-new-ingredient">Créer un nouvel ingrédient</button>
        </div>
    </fieldset>

    <input type="hidden" name="composition_json" id="composition_json" value="">

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Enregistrer la recette</button>
        <a class="btn btn-secondary" href="/admin/products">Retour</a>
    </div>
</form>
<script src="/assets/js/product-recipe.js"></script>
