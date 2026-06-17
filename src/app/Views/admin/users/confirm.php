<?php

declare(strict_types=1);

/**
 * Page de confirmation des actions sensibles sur un compte (desactivation,
 * reinitialisation de PIN, anonymisation RGPD), injectee dans admin/layout.php.
 * Chaque action exige une re-autorisation par PIN equipier (RG-T13). Texte echappe.
 *
 * @var string                $kind       deactivate | reset-pin | erase
 * @var int                   $userId
 * @var string                $userLabel
 * @var string|null           $error
 * @var string                $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($userId ?? 0);
$kind = (string) ($kind ?? '');
$label = htmlspecialchars((string) ($userLabel ?? ''), ENT_QUOTES, 'UTF-8');
$err = isset($error) && is_string($error) ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';

/** @var array<string, array{path:string, title:string, message:string, button:string}> $kinds */
$kinds = [
    'deactivate' => [
        'path'    => '/admin/users/' . $id . '/deactivate',
        'title'   => 'Desactiver le compte',
        'message' => 'L\'utilisateur ne pourra plus se connecter. L\'historique reste intact. Reversible (reactivation via Modifier).',
        'button'  => 'Desactiver',
    ],
    'reset-pin' => [
        'path'    => '/admin/users/' . $id . '/reset-pin',
        'title'   => 'Reinitialiser le PIN',
        'message' => 'Le PIN d\'action sensible de cet equipier sera efface. Il devra en redefinir un en self-service.',
        'button'  => 'Reinitialiser le PIN',
    ],
    'erase' => [
        'path'    => '/admin/users/' . $id . '/erase',
        'title'   => 'Anonymiser le compte (RGPD)',
        'message' => 'Les donnees personnelles seront effacees definitivement (droit a l\'effacement). La ligne est conservee anonymisee pour preserver l\'historique. Action IRREVERSIBLE.',
        'button'  => 'Anonymiser definitivement',
    ],
];
$c = $kinds[$kind] ?? $kinds['deactivate'];
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($c['path'], ENT_QUOTES, 'UTF-8') ?>" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <p>Compte cible : <strong><?= $label ?></strong></p>
    <p class="muted"><?= htmlspecialchars($c['message'], ENT_QUOTES, 'UTF-8') ?></p>

    <?php if ($err !== ''): ?><p class="form-error"><?= $err ?></p><?php endif; ?>

    <fieldset class="form-group">
        <legend>Re-autorisation (PIN equipier)</legend>
        <div class="form-group">
            <label class="form-label" for="pin_email">Email equipier</label>
            <input class="form-input" type="email" id="pin_email" name="pin_email" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="pin">PIN</label>
            <input class="form-input" type="password" id="pin" name="pin" inputmode="numeric" autocomplete="off">
        </div>
    </fieldset>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= htmlspecialchars($c['button'], ENT_QUOTES, 'UTF-8') ?></button>
        <a class="btn btn-secondary" href="/admin/users">Annuler</a>
    </div>
</form>
