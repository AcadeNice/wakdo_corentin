<?php

declare(strict_types=1);

/**
 * Fragment du formulaire de connexion back-office, injecte dans layout.php.
 * Tout texte dynamique est echappe (RG-T15). action POST /login, jeton CSRF cache.
 *
 * @var string      $csrfToken
 * @var string|null $error
 * @var string|null $notice
 */

$token = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$errorMessage = isset($error) && is_string($error) ? $error : null;
$noticeMessage = isset($notice) && is_string($notice) ? $notice : null;
?>
<main class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <img src="/assets/images/logo.png" alt="Wakdo">
            <span class="login-logo-title">Wakdo Admin</span>
            <span class="login-logo-sub">Back-office de gestion</span>
        </div>

        <?php if ($noticeMessage !== null): ?>
            <p class="alert alert-info" role="status"><?= htmlspecialchars($noticeMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($errorMessage !== null): ?>
            <p class="alert alert-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" action="/login">
            <input type="hidden" name="_csrf" value="<?= $token ?>">

            <div class="form-group">
                <label class="form-label" for="email">Adresse e-mail</label>
                <input class="form-input" type="email" id="email" name="email" autocomplete="email" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Mot de passe</label>
                <input class="form-input" type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn btn-primary">Se connecter</button>
        </form>

        <p class="login-footer"><a href="/forgot_password">Mot de passe oublie ?</a></p>
    </div>
</main>
