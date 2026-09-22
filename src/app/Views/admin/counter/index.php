<?php

declare(strict_types=1);

/**
 * Liste des commandes du canal (comptoir ou drive), injectee dans admin/layout.php.
 * Deux sections : "En cours" (commandes payees non livrees du canal, la plus ancienne
 * d'abord, RG-T12) EN HAUT pour le service, puis l'historique recent (tous statuts)
 * en dessous. Lecture seule : numero, mode, statut, total, date + bouton "Nouvelle
 * commande". Partagee par les deux canaux ; le titre, le lien de creation et la source
 * viennent du controleur (CounterOrderController::channelView). Echappement RG-T15.
 * Aucun rafraichissement auto (polling hors scope) : la page se relit a la navigation.
 *
 * @var list<array<string, mixed>> $orders      historique recent (tous statuts)
 * @var list<array<string, mixed>> $inProgress  file "En cours" (paid non livre, canal)
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
/** @var list<array<string, mixed>> $queue */
$queue = isset($inProgress) && is_array($inProgress) ? $inProgress : [];
$heading = isset($channelTitle) && is_string($channelTitle) ? $channelTitle : 'Commandes';
$createPath = isset($newPath) && is_string($newPath) ? $newPath : '/counter/orders/new';
?>

<section class="admin-section" aria-labelledby="counter-heading">
    <div class="page-header">
        <h1 id="counter-heading" class="admin-section__title"><?= $esc($heading) ?></h1>
        <a class="btn btn-primary" href="<?= $esc($createPath) ?>">Nouvelle commande</a>
    </div>

    <h2 class="admin-section__subtitle">En cours</h2>
    <p class="admin-section__sub"><?= count($queue) ?> commande(s) a servir</p>
    <?php if ($queue === []): ?>
        <p class="admin-empty">Aucune commande en cours.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Numero</th>
                    <th>Mode</th>
                    <th>Table</th>
                    <th>Total</th>
                    <th>Payee a</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queue as $o): ?>
                    <?php
                    // Numero de table : pertinent seulement en sur place (service_tag est
                    // NULL hors dine_in cote serveur). On affiche un tiret sinon, pour que
                    // l'equipier distingue "pas de table" d'une donnee manquante.
                    $queueMode = (string) ($o['service_mode'] ?? '');
                    $queueTag = $queueMode === 'dine_in' ? (string) ($o['service_tag'] ?? '') : '';
                    ?>
                    <tr>
                        <td><strong><?= $esc($o['order_number'] ?? '') ?></strong></td>
                        <td><?= $esc($modeLabel($queueMode)) ?></td>
                        <td><?= $queueTag !== '' ? $esc($queueTag) : '-' ?></td>
                        <td><?= $esc($euros($o['total_ttc_cents'] ?? 0)) ?></td>
                        <td><?= $esc($o['paid_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 class="admin-section__subtitle">Historique recent</h2>
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
