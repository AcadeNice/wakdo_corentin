<?php

declare(strict_types=1);

/**
 * Formulaire produit (creation/edition), injecte dans admin/layout.php. Reaffiche
 * valeurs + erreurs (RG-T18). La section email + PIN n'est requise que pour un
 * changement de prix/TVA en edition (RG-T13, modele equipier + PIN). CSRF cache.
 *
 * @var int                               $productId
 * @var array<int, array<string, mixed>>  $categories
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

$val = static fn (string $k): string => htmlspecialchars((string) ($vals[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static fn (string $k): string => isset($errs[$k]) && is_string($errs[$k]) ? $errs[$k] : '';
$selectedCat = (string) ($vals['category_id'] ?? '');
$selectedVat = (string) ($vals['vat_rate'] ?? '100');
$available = (bool) ($vals['is_available'] ?? true);
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id !== 0 ? 'Modifier le produit' : 'Nouveau produit' ?></h1>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card">
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
        <label class="form-label" for="name">Nom</label>
        <input class="form-input" type="text" id="name" name="name" maxlength="120" value="<?= $val('name') ?>" required>
        <?php if ($err('name') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('name'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="description">Description</label>
        <textarea class="form-input" id="description" name="description"><?= $val('description') ?></textarea>
    </div>

    <div class="form-group">
        <label class="form-label" for="price_cents">Prix (en centimes)</label>
        <input class="form-input" type="number" id="price_cents" name="price_cents" min="1" value="<?= $val('price_cents') ?>" required>
        <?php if ($err('price_cents') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('price_cents'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="vat_rate">TVA</label>
        <select class="form-input" id="vat_rate" name="vat_rate">
            <option value="100"<?= $selectedVat === '100' ? ' selected' : '' ?>>10% (sur place / general)</option>
            <option value="55"<?= $selectedVat === '55' ? ' selected' : '' ?>>5,5% (a emporter)</option>
        </select>
        <?php if ($err('vat_rate') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('vat_rate'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="image_path">Chemin de l'image (optionnel)</label>
        <input class="form-input" type="text" id="image_path" name="image_path" maxlength="255" value="<?= $val('image_path') ?>">
        <?php if ($err('image_path') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('image_path'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="display_order">Ordre d'affichage</label>
        <input class="form-input" type="number" id="display_order" name="display_order" min="0" value="<?= $val('display_order') ?>">
        <?php if ($err('display_order') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('display_order'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label"><input type="checkbox" name="is_available" value="1"<?= $available ? ' checked' : '' ?>> Disponible</label>
    </div>

    <?php if ($id !== 0): ?>
        <fieldset class="form-group">
            <legend>Changement de prix ou de TVA : confirmation par PIN</legend>
            <p><small>Renseignez votre email et votre PIN uniquement si vous modifiez le prix ou la TVA (action tracee).</small></p>
            <div class="form-group">
                <label class="form-label" for="pin_email">Votre email</label>
                <input class="form-input" type="email" id="pin_email" name="pin_email" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label" for="pin">Votre PIN</label>
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
