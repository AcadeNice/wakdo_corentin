<?php

declare(strict_types=1);

/**
 * Inventaire (INVENTORY_COUNT 9.2), injecte dans admin/layout.php. Action sensible :
 * exige le PIN equipier (email + PIN, RG-T13). Le comptage absolu cale le stock et
 * ecrit une correction tracee a l'equipier (stock_movement.user_id), sans audit_log
 * (RG-T14). CSRF cache.
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
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Inventaire</h1>
        <p class="page-subtitle"><?= $esc($ing['name'] ?? '') ?> - stock theorique <?= $esc((string) ((int) ($ing['stock_quantity'] ?? 0))) ?> <?= $esc($ing['unit'] ?? '') ?></p>
    </div>
</div>

<form method="post" action="/admin/ingredients/<?= $id ?>/inventory" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <p><small>Saisissez le comptage physique reel. L'ecart avec le theorique est enregistre et impute a l'equipier (action tracee).</small></p>

    <div class="form-group">
        <label class="form-label" for="actual_quantity">Comptage physique</label>
        <input class="form-input" type="number" id="actual_quantity" name="actual_quantity" min="0" value="<?= $val('actual_quantity') ?>" required>
        <?php if ($err('actual_quantity') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('actual_quantity'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="note">Raison (optionnelle)</label>
        <input class="form-input" type="text" id="note" name="note" maxlength="255" value="<?= $val('note') ?>">
        <?php if ($err('note') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('note'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <fieldset class="form-group">
        <legend>Confirmation par PIN equipier</legend>
        <div class="form-group">
            <label class="form-label" for="pin_email">Votre email</label>
            <input class="form-input" type="email" id="pin_email" name="pin_email" autocomplete="off" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="pin">Votre PIN</label>
            <input class="form-input" type="password" id="pin" name="pin" inputmode="numeric" autocomplete="off" required>
        </div>
        <?php if ($err('pin') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('pin'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </fieldset>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Valider l inventaire</button>
        <a class="btn btn-secondary" href="/admin/ingredients">Annuler</a>
    </div>
</form>
