<?php

declare(strict_types=1);

/**
 * Formulaire de creation/edition d'une categorie, injecte dans admin/layout.php.
 * Reaffiche les valeurs soumises et les erreurs de validation (RG-T18). CSRF cache.
 *
 * @var int                  $categoryId  0 = creation, sinon edition
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string               $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($categoryId ?? 0);
$action = $id !== 0 ? '/admin/categories/' . $id : '/admin/categories';

/** @var array<string, mixed> $vals */
$vals = isset($values) && is_array($values) ? $values : [];
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];

$val = static fn (string $k): string => htmlspecialchars((string) ($vals[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static fn (string $k): string => isset($errs[$k]) && is_string($errs[$k]) ? $errs[$k] : '';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id !== 0 ? 'Modifier la categorie' : 'Nouvelle categorie' ?></h1>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="name">Libelle</label>
        <input class="form-input" type="text" id="name" name="name" maxlength="60" value="<?= $val('name') ?>" required>
        <?php if ($err('name') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('name'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="slug">Slug</label>
        <input class="form-input" type="text" id="slug" name="slug" maxlength="60" value="<?= $val('slug') ?>" required>
        <?php if ($err('slug') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('slug'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="display_order">Ordre d'affichage</label>
        <input class="form-input" type="number" id="display_order" name="display_order" min="0" value="<?= $val('display_order') ?>">
        <?php if ($err('display_order') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('display_order'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="image_path">Chemin de l'image (optionnel)</label>
        <input class="form-input" type="text" id="image_path" name="image_path" maxlength="255" value="<?= $val('image_path') ?>">
        <?php if ($err('image_path') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('image_path'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Enregistrer</button>
        <a class="btn btn-secondary" href="/admin/categories">Annuler</a>
    </div>
</form>
