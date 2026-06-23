<?php

declare(strict_types=1);

/**
 * Formulaire menu (creation/edition), injecte dans admin/layout.php. Reaffiche
 * valeurs + erreurs (RG-T18). La composition de slots est geree par le builder
 * vanilla JS (menu-form.js) qui serialise l'etat dans le champ cache slots_json
 * a la soumission. Pas de PIN ici (create/update non sensibles, mlt 8.4/8.5).
 *
 * @var int                              $menuId
 * @var array<int, array<string, mixed>> $categories
 * @var array<int, array<string, mixed>> $products
 * @var list<string>                     $slotTypes
 * @var array<string, mixed>             $values
 * @var string                           $slotsJson
 * @var array<string, string>            $errors
 * @var string                           $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($menuId ?? 0);
$action = $id !== 0 ? '/admin/menus/' . $id : '/admin/menus';

/** @var array<string, mixed> $vals */
$vals = isset($values) && is_array($values) ? $values : [];
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];
/** @var array<int, array<string, mixed>> $cats */
$cats = isset($categories) && is_array($categories) ? $categories : [];
/** @var array<int, array<string, mixed>> $prods */
$prods = isset($products) && is_array($products) ? $products : [];
/** @var list<string> $types */
$types = isset($slotTypes) && is_array($slotTypes) ? $slotTypes : [];

$val = static fn (string $k): string => htmlspecialchars((string) ($vals[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static fn (string $k): string => isset($errs[$k]) && is_string($errs[$k]) ? $errs[$k] : '';
$selectedCat = (string) ($vals['category_id'] ?? '');
$selectedBurger = (string) ($vals['burger_product_id'] ?? '');
$available = (bool) ($vals['is_available'] ?? true);

// Donnees pour le builder JS, passees en attributs data-* (CSP 'self' : pas de
// script inline). htmlspecialchars rend le JSON sur-able comme valeur d'attribut.
$slimProducts = array_map(
    static fn (array $p): array => ['id' => (int) ($p['id'] ?? 0), 'name' => (string) ($p['name'] ?? '')],
    $prods,
);
$attr = static fn (mixed $data): string => htmlspecialchars(
    (string) json_encode($data, JSON_UNESCAPED_UNICODE),
    ENT_QUOTES,
    'UTF-8',
);
$slotsData = isset($slotsJson) && is_string($slotsJson) && $slotsJson !== '' ? $slotsJson : '[]';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id !== 0 ? 'Modifier le menu' : 'Nouveau menu' ?></h1>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card" id="menu-form">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="category_id">Categorie</label>
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
        <label class="form-label" for="burger_product_id">Burger de base</label>
        <select class="form-input" id="burger_product_id" name="burger_product_id" required>
            <option value="">-- choisir --</option>
            <?php foreach ($prods as $p): ?>
                <?php $pid = (string) ($p['id'] ?? ''); ?>
                <option value="<?= htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') ?>"<?= $pid === $selectedBurger ? ' selected' : '' ?>>
                    <?= htmlspecialchars((string) ($p['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($err('burger_product_id') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('burger_product_id'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="name">Nom</label>
        <input class="form-input" type="text" id="name" name="name" maxlength="120" value="<?= $val('name') ?>" required>
        <?php if ($err('name') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('name'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="price_normal_cents">Prix Normal (en centimes)</label>
        <input class="form-input" type="number" id="price_normal_cents" name="price_normal_cents" min="1" value="<?= $val('price_normal_cents') ?>" required>
        <?php if ($err('price_normal_cents') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('price_normal_cents'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="price_maxi_cents">Prix Maxi (en centimes)</label>
        <input class="form-input" type="number" id="price_maxi_cents" name="price_maxi_cents" min="1" value="<?= $val('price_maxi_cents') ?>" required>
        <?php if ($err('price_maxi_cents') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('price_maxi_cents'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="display_order">Ordre d'affichage</label>
        <input class="form-input" type="number" id="display_order" name="display_order" min="0" value="<?= $val('display_order') ?>">
        <?php if ($err('display_order') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('display_order'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label"><input type="checkbox" name="is_available" value="1"<?= $available ? ' checked' : '' ?>> Disponible</label>
    </div>

    <fieldset class="form-group">
        <legend>Slots de composition</legend>
        <p><small>Au moins un slot, chacun avec au moins une option. Les choix proposes au client par slot.</small></p>
        <?php if ($err('slots') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('slots'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <div id="slot-builder"
             data-products="<?= $attr($slimProducts) ?>"
             data-slot-types="<?= $attr($types) ?>"
             data-slots="<?= htmlspecialchars($slotsData, ENT_QUOTES, 'UTF-8') ?>"></div>
        <button class="btn btn-secondary" type="button" id="add-slot">Ajouter un slot</button>
    </fieldset>

    <input type="hidden" name="slots_json" id="slots_json" value="">

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Enregistrer</button>
        <a class="btn btn-secondary" href="/admin/menus">Annuler</a>
    </div>
</form>
<script src="/assets/js/menu-form.js"></script>
