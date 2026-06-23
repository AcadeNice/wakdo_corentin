<?php

declare(strict_types=1);

/**
 * Reapprovisionnement (RESTOCK 9.1), injecte dans admin/layout.php. SANS PIN
 * (9.1 hors ensemble sensible RG-T13) : +N packs => stock += N * pack_size. CSRF cache.
 *
 * @var int                    $ingredientId
 * @var array<string, mixed>   $ingredient
 * @var array<string, mixed>   $values
 * @var array<string, string>  $errors
 * @var string                 $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($ingredientId ?? 0);
/** @var array<string, mixed> $ing */
$ing = isset($ingredient) && is_array($ingredient) ? $ingredient : [];
/** @var array<string, mixed> $vals */
$vals = isset($values) && is_array($values) ? $values : [];
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn (string $k): string => htmlspecialchars((string) ($vals[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static fn (string $k): string => isset($errs[$k]) && is_string($errs[$k]) ? $errs[$k] : '';
$packSize = (int) ($ing['pack_size'] ?? 1);
$packLabel = (string) ($ing['pack_label'] ?? '');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Reapprovisionner</h1>
        <p class="page-subtitle"><?= $esc($ing['name'] ?? '') ?> - stock actuel <?= $esc((string) ((int) ($ing['stock_quantity'] ?? 0))) ?> <?= $esc($ing['unit'] ?? '') ?></p>
    </div>
</div>

<form method="post" action="/admin/ingredients/<?= $id ?>/restock" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <p><small>Un pack = <?= $esc((string) $packSize) ?> unite(s)<?= $packLabel !== '' ? ' (' . $esc($packLabel) . ')' : '' ?>. Le stock augmente de N x taille de pack.</small></p>

    <div class="form-group">
        <label class="form-label" for="packs">Nombre de packs recus</label>
        <input class="form-input" type="number" id="packs" name="packs" min="1" max="65535" value="<?= $val('packs') ?>" required>
        <?php if ($err('packs') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('packs'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="note">Note (optionnelle : ref. livraison)</label>
        <input class="form-input" type="text" id="note" name="note" maxlength="255" value="<?= $val('note') ?>">
        <?php if ($err('note') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('note'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Enregistrer le reappro</button>
        <a class="btn btn-secondary" href="/admin/ingredients">Annuler</a>
    </div>
</form>
