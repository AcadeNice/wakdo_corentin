<?php

declare(strict_types=1);

/**
 * Confirmation d'annulation d'une commande (CANCEL_ORDER 7.1), injectee dans
 * admin/layout.php. Action sensible de manipulation d'argent : exige le PIN
 * equipier (email + PIN, RG-T13). L'annulation est tracee (audit_log) et re-credite
 * le stock si la commande etait payee. CSRF cache. Tout texte echappe (RG-T15).
 *
 * @var array<string, mixed>  $order
 * @var string|null           $error
 * @var string                $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
/** @var array<string, mixed> $o */
$o = isset($order) && is_array($order) ? $order : [];
$errorMessage = isset($error) && is_string($error) ? $error : null;

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$euros = static fn (mixed $cents): string => number_format(((int) $cents) / 100, 2, ',', ' ') . ' EUR';

$number = (string) ($o['order_number'] ?? '');
$status = (string) ($o['status'] ?? '');

$statusLabel = static fn (string $s): string => match ($s) {
    'pending_payment' => 'En attente',
    'paid'            => 'Payee',
    'delivered'       => 'Livree',
    'cancelled'       => 'Annulee',
    default           => $s,
};

// PRE-3 (7.1) : seuls pending_payment / paid peuvent transiter vers cancelled.
$cancellable = in_array($status, ['pending_payment', 'paid'], true);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Annuler une commande</h1>
        <p class="page-subtitle">Commande <?= $esc($number) ?> - <?= $esc($statusLabel($status)) ?> - <?= $esc($euros($o['total_ttc_cents'] ?? 0)) ?></p>
    </div>
</div>

<section>
    <?php if ($errorMessage !== null): ?>
        <p class="form-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if (!$cancellable): ?>
        <p role="alert">Cette commande est livree ou deja annulee : elle ne peut plus etre annulee.</p>
        <div class="form-actions">
            <a class="btn btn-secondary" href="/admin/orders">Retour</a>
        </div>
    <?php else: ?>
        <form method="post" action="/admin/orders/<?= rawurlencode($number) ?>/cancel" class="form-card">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">

            <p><small>L'annulation est tracee (audit) et re-credite le stock si la commande etait payee. Renseignez votre email et votre PIN.</small></p>

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
            </fieldset>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Annuler la commande</button>
                <a class="btn btn-secondary" href="/admin/orders">Retour</a>
            </div>
        </form>
    <?php endif; ?>
</section>
