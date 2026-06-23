<?php

declare(strict_types=1);

/**
 * Fragment de la confirmation de reinitialisation (phase 2 de 12.3), injecte
 * dans layout.php. Le token brut transite en champ cache (usage unique cote service).
 *
 * @var string      $csrfToken
 * @var string      $token
 * @var string|null $error
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$resetToken = htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8');
$errorMessage = isset($error) && is_string($error) ? $error : null;
?>
<main class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <img src="/assets/images/logo.png" alt="Wakdo">
            <span class="login-logo-title">Wakdo Admin</span>
            <span class="login-logo-sub">Nouveau mot de passe</span>
        </div>

        <?php if ($errorMessage !== null): ?>
            <p class="alert alert-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" action="/reset_password">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="token" value="<?= $resetToken ?>">

            <div class="form-group">
                <label class="form-label" for="password">Nouveau mot de passe</label>
                <input class="form-input" type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm">Confirmer le mot de passe</label>
                <input class="form-input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="8" required>
            </div>

            <button type="submit" class="btn btn-primary">Reinitialiser</button>
        </form>

        <p class="login-footer"><a href="/login">Retour a la connexion</a></p>
    </div>
</main>
