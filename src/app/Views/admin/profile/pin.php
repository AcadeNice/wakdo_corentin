<?php

declare(strict_types=1);

/**
 * Formulaire de definition / changement du PIN de l'utilisateur connecte.
 * Injecte dans admin/layout.php. Le PIN sert a re-autoriser les actions sensibles.
 *
 * @var string       $csrfToken
 * @var bool         $pinIsSet
 * @var string|null  $error
 * @var int          $pinMinLength
 * @var int          $pinMaxLength
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$alreadySet = isset($pinIsSet) && $pinIsSet === true;
$errorMessage = isset($error) && is_string($error) ? $error : null;
// Politique de longueur du PIN reprise par le controle pendant la saisie (Cr 2.b.1) :
// ProfileController transmet toujours les bornes lues par PinVerifier (la meme source
// que la regle serveur) ; aucune valeur par defaut n'est recopiee ici.
$pinPattern = '[0-9]{' . $pinMinLength . ',' . $pinMaxLength . '}';
$pinRule = sprintf('Le PIN compte de %d à %d chiffres, sans lettre ni espace.', $pinMinLength, $pinMaxLength);
$pinRuleAttributes = ' pattern="' . htmlspecialchars($pinPattern, ENT_QUOTES, 'UTF-8')
    . '" data-pattern-message="' . htmlspecialchars($pinRule, ENT_QUOTES, 'UTF-8') . '"';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Mon PIN</h1>
        <p class="page-subtitle">PIN de confirmation des actions sensibles (annulation, prix, suppressions...)</p>
    </div>
</div>

<section>
    <p><small>Statut : <?= $alreadySet ? 'un PIN est défini.' : 'aucun PIN défini pour l\'instant.' ?></small></p>

    <?php if ($errorMessage !== null): ?>
        <p class="form-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/profile/pin" class="form-card">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">

        <div class="form-group">
            <label class="form-label" for="current_password">Mot de passe actuel</label>
            <input class="form-input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            <small>Confirme votre identité avant de définir un PIN d'action sensible.</small>
        </div>

        <div class="form-group">
            <label class="form-label" for="pin">Nouveau PIN</label>
            <input class="form-input" type="password" id="pin" name="pin" inputmode="numeric" autocomplete="off" required<?= $pinRuleAttributes ?>>
            <small><?= htmlspecialchars($pinRule, ENT_QUOTES, 'UTF-8') ?></small>
        </div>

        <div class="form-group">
            <label class="form-label" for="pin_confirm">Confirmer le PIN</label>
            <input class="form-input" type="password" id="pin_confirm" name="pin_confirm" inputmode="numeric" autocomplete="off" required<?= $pinRuleAttributes ?> data-match="pin">
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Enregistrer</button>
        </div>
    </form>
</section>
