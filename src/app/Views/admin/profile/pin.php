<?php

declare(strict_types=1);

/**
 * Formulaire de definition / changement du PIN de l'utilisateur connecte.
 * Injecte dans admin/layout.php. Le PIN sert a re-autoriser les actions sensibles.
 *
 * @var string       $csrfToken
 * @var bool         $pinIsSet
 * @var string|null  $error
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$alreadySet = isset($pinIsSet) && $pinIsSet === true;
$errorMessage = isset($error) && is_string($error) ? $error : null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Mon PIN</h1>
        <p class="page-subtitle">PIN de confirmation des actions sensibles (annulation, prix, suppressions...)</p>
    </div>
</div>

<section>
    <p><small>Statut : <?= $alreadySet ? 'un PIN est defini.' : 'aucun PIN defini pour l instant.' ?></small></p>

    <?php if ($errorMessage !== null): ?>
        <p role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/profile/pin" class="form-card">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">

        <div class="form-group">
            <label class="form-label" for="current_password">Mot de passe actuel</label>
            <input class="form-input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            <small>Confirme votre identite avant de definir un PIN d action sensible.</small>
        </div>

        <div class="form-group">
            <label class="form-label" for="pin">Nouveau PIN</label>
            <input class="form-input" type="password" id="pin" name="pin" inputmode="numeric" autocomplete="off" required>
        </div>

        <div class="form-group">
            <label class="form-label" for="pin_confirm">Confirmer le PIN</label>
            <input class="form-input" type="password" id="pin_confirm" name="pin_confirm" inputmode="numeric" autocomplete="off" required>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Enregistrer</button>
        </div>
    </form>
</section>
