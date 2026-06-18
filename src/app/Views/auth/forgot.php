<?php

declare(strict_types=1);

/**
 * Fragment de la demande de reinitialisation (phase 1 de 12.3), injecte dans
 * layout.php. La reponse est neutre : aucun indice sur l'existence du compte.
 *
 * @var string      $csrfToken
 * @var string|null $notice
 */

$token = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$noticeMessage = isset($notice) && is_string($notice) ? $notice : null;
?>
<main class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <img src="/assets/images/logo.png" alt="Wakdo">
            <span class="login-logo-title">Wakdo Admin</span>
            <span class="login-logo-sub">Reinitialisation du mot de passe</span>
        </div>

        <?php if ($noticeMessage !== null): ?>
            <p class="alert alert-info" role="status"><?= htmlspecialchars($noticeMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" action="/forgot_password">
            <input type="hidden" name="_csrf" value="<?= $token ?>">

            <div class="form-group">
                <label class="form-label" for="email">Adresse e-mail</label>
                <input class="form-input" type="email" id="email" name="email" autocomplete="email" required>
            </div>

            <button type="submit" class="btn btn-primary">Envoyer le lien</button>
        </form>

        <p class="login-footer"><a href="/login">Retour a la connexion</a></p>
    </div>
</main>
