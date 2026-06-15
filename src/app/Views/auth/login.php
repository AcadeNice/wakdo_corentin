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
    <h1>Wakdo Admin</h1>
    <p><small>Back-office de gestion</small></p>

    <?php if ($noticeMessage !== null): ?>
        <p role="status"><?= htmlspecialchars($noticeMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <p role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="<?= $token ?>">

        <div class="form-group">
            <label for="email">Adresse e-mail</label>
            <input type="email" id="email" name="email" autocomplete="email" required>
        </div>

        <div class="form-group">
            <label for="password">Mot de passe</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>

        <button type="submit">Se connecter</button>
    </form>

    <p><a href="/forgot_password">Mot de passe oublie ?</a></p>
</main>
