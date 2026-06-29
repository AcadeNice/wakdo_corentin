<?php

declare(strict_types=1);

/**
 * KDS cuisine : file des commandes payees (lecture seule ; remise si order.deliver).
 * Injecte dans admin/layout.php. Utilise la grille .kitchen-* (admin.css). Le bouton
 * de remise n'apparait que pour les roles dotes de order.deliver (kitchen ne l'a pas :
 * il voit la file en lecture seule ; counter/drive/admin remettent).
 *
 * Chaque commande porte son detail (items -> selections + modifiers) et une bande SLA
 * (sla_band : fresh / warn / late) calculee cote serveur depuis (now - paid_at),
 * mappee vers une classe CSS sur la carte (kds-order--fresh / --warn / --late). Le KDS
 * est rendu exploitable pour PREPARER : la liste lisible des articles est affichee.
 *
 * @var list<array<string, mixed>> $orders
 * @var bool        $canDeliver
 * @var bool        $canPrepare
 * @var string      $csrfToken
 */

$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $esc($csrfToken ?? '');
$rows = isset($orders) && is_array($orders) ? $orders : [];
$can = !empty($canDeliver);
$canPrep = !empty($canPrepare);

$sourceLabel = static fn (string $s): string => ['kiosk' => 'Borne', 'counter' => 'Comptoir', 'drive' => 'Drive'][$s] ?? $s;
// Etat de preparation visible (retour oral #8) -> libelle FR sur la carte.
$statusLabel = static fn (string $s): string => ['paid' => 'En attente', 'preparing' => 'En preparation', 'ready' => 'Prete'][$s] ?? $s;
$modeLabel = static fn (string $m): string => $m === 'dine_in' ? 'Sur place' : ($m === 'drive' ? 'Drive' : 'A emporter');

// Bande SLA (serveur) -> classe CSS de la carte. Defaut prudent sur valeur inconnue.
$slaClass = static fn (string $band): string => [
    'fresh' => 'kds-order--fresh',
    'warn'  => 'kds-order--warn',
    'late'  => 'kds-order--late',
][$band] ?? 'kds-order--fresh';

/**
 * Libelle lisible d'un article : "<qty>x <label> (Maxi) - <selections> - <modifs>".
 * S'appuie sur les snapshots (label_snapshot, format) ; les modificateurs sont rendus
 * "sans <ingredient>" (remove) / "+<ingredient>" (add). Tout est echappe a la sortie.
 *
 * @param array<string, mixed> $item
 */
$itemLabel = static function (array $item) use ($esc): string {
    $qty = max(1, (int) ($item['quantity'] ?? 1));
    $name = (string) ($item['label_snapshot'] ?? '');
    $main = $esc($qty) . 'x ' . $esc($name);
    if ((string) ($item['format'] ?? 'normal') === 'maxi') {
        $main .= ' (Maxi)';
    }

    $parts = [];

    $selections = isset($item['selections']) && is_array($item['selections']) ? $item['selections'] : [];
    $selLabels = [];
    foreach ($selections as $sel) {
        $label = trim((string) ($sel['label_snapshot'] ?? ''));
        if ($label !== '') {
            $selLabels[] = $esc($label);
        }
    }
    if ($selLabels !== []) {
        $parts[] = implode(', ', $selLabels);
    }

    $modifiers = isset($item['modifiers']) && is_array($item['modifiers']) ? $item['modifiers'] : [];
    $modLabels = [];
    foreach ($modifiers as $mod) {
        $ing = trim((string) ($mod['ingredient_name'] ?? ''));
        if ($ing === '') {
            continue;
        }
        $modLabels[] = ((string) ($mod['action'] ?? '') === 'add' ? '+' : 'sans ') . $esc($ing);
    }
    if ($modLabels !== []) {
        $parts[] = implode(', ', $modLabels);
    }

    return $parts === [] ? $main : $main . ' - ' . implode(' - ', $parts);
};
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Cuisine</h1>
        <p class="page-subtitle">File des commandes payees, de la plus ancienne a la plus recente.</p>
    </div>
    <span class="kitchen-clock" id="kitchenTime" aria-hidden="true"></span>
</div>

<?php if ($rows === []): ?>
    <p>Aucune commande en attente de preparation.</p>
<?php else: ?>
    <section class="kitchen-grid" aria-label="File des commandes payees">
        <?php foreach ($rows as $o): ?>
            <?php
            $items = isset($o['items']) && is_array($o['items']) ? $o['items'] : [];
            $band = (string) ($o['sla_band'] ?? 'fresh');
            $status = (string) ($o['status'] ?? 'paid');
            $num = (string) ($o['order_number'] ?? '');
            ?>
            <article class="kitchen-card <?= $esc($slaClass($band)) ?>">
                <div class="kitchen-card-header">
                    <span class="kitchen-order-num"><?= $esc($num) ?></span>
                    <span class="kitchen-status kitchen-status--<?= $esc($status) ?>"><?= $esc($statusLabel($status)) ?></span>
                    <span class="kitchen-card-source"><?= $esc($sourceLabel((string) ($o['source'] ?? ''))) ?></span>
                </div>
                <div class="kitchen-card-body">
                    <p class="kitchen-line">Mode : <?= $esc($modeLabel((string) ($o['service_mode'] ?? ''))) ?></p>
                    <?php if (($o['service_tag'] ?? '') !== ''): ?>
                        <p class="kitchen-line">Table : <?= $esc($o['service_tag']) ?></p>
                    <?php endif; ?>
                    <p class="kitchen-line">Payee a : <?= $esc($o['paid_at'] ?? '') ?></p>
                    <?php if ($items === []): ?>
                        <p class="kitchen-line">Aucun article.</p>
                    <?php else: ?>
                        <ul class="kds-items">
                            <?php foreach ($items as $item): ?>
                                <li class="kds-item"><?= $itemLabel($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php if ($canPrep || $can): ?>
                    <div class="kitchen-card-footer">
                        <?php if ($canPrep && $status === 'preparing'): ?>
                            <form method="post" action="/admin/orders/<?= rawurlencode($num) ?>/ready">
                                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                <button class="btn btn-secondary" type="submit">Prete</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($can): ?>
                            <form method="post" action="/admin/orders/<?= rawurlencode($num) ?>/deliver">
                                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                <button class="btn btn-primary" type="submit">Remettre (livree)</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
