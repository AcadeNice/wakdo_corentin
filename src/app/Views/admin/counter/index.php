<?php

declare(strict_types=1);

/**
 * Liste des commandes du canal (comptoir ou drive), injectee dans admin/layout.php.
 * Lecture seule : numero, mode, statut, total, date + bouton "Nouvelle commande".
 * Partagee par les deux canaux ; le titre, le lien de creation et la source viennent
 * du controleur (CounterOrderController::channelView). Toute valeur est echappee (RG-T15).
 *
 * @var list<array<string, mixed>> $orders
 * @var string                     $channelTitle
 * @var string                     $newPath
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$euros = static fn (mixed $cents): string => number_format(((int) $cents) / 100, 2, ',', ' ') . ' EUR';

$modeLabel = static fn (string $m): string => match ($m) {
    'dine_in'  => 'Sur place',
    'takeaway' => 'A emporter',
    'drive'    => 'Drive',
    default    => $m,
};

$statusLabel = static fn (string $s): string => match ($s) {
    'pending_payment' => 'En attente',
    'paid'            => 'Payee',
    'delivered'       => 'Livree',
    'cancelled'       => 'Annulee',
    default           => $s,
};

$statusPill = static fn (string $s): string => match ($s) {
    'paid', 'delivered' => 'pill-success',
    'cancelled'         => 'pill-danger',
    default             => 'pill-warning',
};

/** @var list<array<string, mixed>> $rows */
$rows = isset($orders) && is_array($orders) ? $orders : [];
$heading = isset($channelTitle) && is_string($channelTitle) ? $channelTitle : 'Commandes';
$createPath = isset($newPath) && is_string($newPath) ? $newPath : '/counter/orders/new';
?>

<section class="admin-section" aria-labelledby="counter-heading">
    <div class="page-header">
        <h1 id="counter-heading" class="admin-section__title"><?= $esc($heading) ?></h1>
        <a class="btn btn-primary" href="<?= $esc($createPath) ?>">Nouvelle commande</a>
    </div>
    <p class="admin-section__sub"><?= count($rows) ?> commande(s) recente(s)</p>

    <?php if ($rows === []): ?>
        <p class="admin-empty">Aucune commande pour ce canal.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Numero</th>
                    <th>Mode</th>
                    <th>Statut</th>
                    <th>Total</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $o): ?>
                    <?php $status = (string) ($o['status'] ?? ''); ?>
                    <tr>
                        <td><strong><?= $esc($o['order_number'] ?? '') ?></strong></td>
                        <td><?= $esc($modeLabel((string) ($o['service_mode'] ?? ''))) ?></td>
                        <td><span class="pill <?= $esc($statusPill($status)) ?>"><?= $esc($statusLabel($status)) ?></span></td>
                        <td><?= $esc($euros($o['total_ttc_cents'] ?? 0)) ?></td>
                        <td><?= $esc($o['created_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
