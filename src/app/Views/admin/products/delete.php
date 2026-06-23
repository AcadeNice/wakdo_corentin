<?php

declare(strict_types=1);

/**
 * Confirmation de suppression d'un produit (action sensible RG-T13) : exige
 * l'email + le PIN de l'equipier. Injecte dans admin/layout.php.
 *
 * @var int          $productId
 * @var string       $name
 * @var string|null  $error
 * @var string       $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($productId ?? 0);
$productName = htmlspecialchars((string) ($name ?? ''), ENT_QUOTES, 'UTF-8');
$errorMessage = isset($error) && is_string($error) ? $error : null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Supprimer un produit</h1>
        <p class="page-subtitle">Confirmez la suppression de "<?= $productName ?>".</p>
    </div>
</div>

<section>
    <?php if ($errorMessage !== null): ?>
        <p role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/products/<?= $id ?>/delete" class="form-card">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">

        <p><small>La suppression est tracee (audit). Renseignez votre email et votre PIN.</small></p>

        <div class="form-group">
            <label class="form-label" for="pin_email">Votre email</label>
            <input class="form-input" type="email" id="pin_email" name="pin_email" autocomplete="off" required>
        </div>

        <div class="form-group">
            <label class="form-label" for="pin">Votre PIN</label>
            <input class="form-input" type="password" id="pin" name="pin" inputmode="numeric" autocomplete="off" required>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Supprimer definitivement</button>
            <a class="btn btn-secondary" href="/admin/products">Annuler</a>
        </div>
    </form>
</section>
