<?php

declare(strict_types=1);

/**
 * Liste des commandes (order.read), injectee dans admin/layout.php. Lecture seule :
 * numero, mode, chevalet, statut, total ttc, date. Une colonne d'actions affiche le
 * lien Annuler (CANCEL_ORDER 7.1) pour les commandes pending_payment/paid quand le
 * role detient order.cancel (manager ne l'a PAS, D5). Tri du plus recent au plus
 * ancien (cf. OrderQueryRepository::recent). Toute valeur est echappee (RG-T15).
 *
 * @var list<array<string, mixed>> $orders
 * @var bool                       $canCancel
 */

$canCancelOrder = isset($canCancel) && $canCancel === true;

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
    'preparing'       => 'En preparation',
    'ready'           => 'Prete',
    'delivered'       => 'Livree',
    'cancelled'       => 'Annulee',
    default           => $s,
};

$statusPill = static fn (string $s): string => match ($s) {
    'paid', 'ready', 'delivered' => 'pill-success',
    'preparing'                  => 'pill-warning',
    'cancelled'                  => 'pill-danger',
    default                      => 'pill-warning',
};

/** @var list<array<string, mixed>> $rows */
$rows = isset($orders) && is_array($orders) ? $orders : [];
?>

<section class="admin-section" aria-labelledby="orders-heading">
    <h1 id="orders-heading" class="admin-section__title">Commandes</h1>
    <p class="admin-section__sub"><?= count($rows) ?> commande(s) recente(s)</p>

    <?php if ($rows === []): ?>
        <p class="admin-empty">Aucune commande pour le moment.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Numero</th>
                    <th>Mode</th>
                    <th>Chevalet</th>
                    <th>Statut</th>
                    <th>Total</th>
                    <th>Date</th>
                    <?php if ($canCancelOrder): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $o): ?>
                    <?php
                    $number = (string) ($o['order_number'] ?? '');
                    $status = (string) ($o['status'] ?? '');
                    // PRE-3 (7.1) : les commandes non terminales sont annulables (cancel()
                    // accepte pending_payment, paid et les etats de cuisine preparing/ready).
                    $rowCancellable = in_array($status, ['pending_payment', 'paid', 'preparing', 'ready'], true);
                    ?>
                    <tr>
                        <td><strong><?= $esc($number) ?></strong></td>
                        <td><?= $esc($modeLabel((string) ($o['service_mode'] ?? ''))) ?></td>
                        <td><?= ($o['service_tag'] ?? '') !== '' ? $esc($o['service_tag']) : '—' ?></td>
                        <td><span class="pill <?= $esc($statusPill($status)) ?>"><?= $esc($statusLabel($status)) ?></span></td>
                        <td><?= $esc($euros($o['total_ttc_cents'] ?? 0)) ?></td>
                        <td><?= $esc($o['created_at'] ?? '') ?></td>
                        <?php if ($canCancelOrder): ?>
                            <td>
                                <?php if ($rowCancellable): ?>
                                    <a class="btn btn-secondary" href="/admin/orders/<?= rawurlencode($number) ?>/cancel">Annuler</a>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
