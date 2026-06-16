<?php

declare(strict_types=1);

/**
 * Confirmation de suppression d'un menu (action sensible RG-T13/mlt 8.6) : exige
 * l'email + le PIN de l'equipier. La suppression cascade vers menu_slot /
 * menu_slot_option ; bloquee (422) si reference par une commande historique.
 * Injecte dans admin/layout.php.
 *
 * @var int          $menuId
 * @var string       $name
 * @var string|null  $error
 * @var string       $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($menuId ?? 0);
$menuName = htmlspecialchars((string) ($name ?? ''), ENT_QUOTES, 'UTF-8');
$errorMessage = isset($error) && is_string($error) ? $error : null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Supprimer un menu</h1>
        <p class="page-subtitle">Confirmez la suppression de "<?= $menuName ?>".</p>
    </div>
</div>

<section>
    <?php if ($errorMessage !== null): ?>
        <p role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/menus/<?= $id ?>/delete" class="form-card">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">

        <p><small>La suppression est tracee (audit) et retire aussi les slots du menu. Renseignez votre email et votre PIN.</small></p>

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
            <a class="btn btn-secondary" href="/admin/menus">Annuler</a>
        </div>
    </form>
</section>
