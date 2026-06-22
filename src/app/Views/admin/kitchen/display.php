<?php

declare(strict_types=1);

/**
 * KDS cuisine : file des commandes payees (lecture seule ; remise si order.deliver).
 * Injecte dans admin/layout.php. Utilise la grille .kitchen-* (admin.css). Le bouton
 * de remise n'apparait que pour les roles dotes de order.deliver (kitchen ne l'a pas :
 * il voit la file en lecture seule ; counter/drive/admin remettent).
 *
 * @var list<array<string, mixed>> $orders
 * @var bool        $canDeliver
 * @var string      $csrfToken
 */

$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $esc($csrfToken ?? '');
$rows = isset($orders) && is_array($orders) ? $orders : [];
$can = !empty($canDeliver);

$sourceLabel = static fn (string $s): string => ['kiosk' => 'Borne', 'counter' => 'Comptoir', 'drive' => 'Drive'][$s] ?? $s;
$modeLabel = static fn (string $m): string => $m === 'dine_in' ? 'Sur place' : ($m === 'drive' ? 'Drive' : 'A emporter');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Cuisine</h1>
        <p class="page-subtitle">File des commandes payees, de la plus ancienne a la plus recente.</p>
    </div>
</div>

<?php if ($rows === []): ?>
    <p>Aucune commande en attente de preparation.</p>
<?php else: ?>
    <section class="kitchen-grid" aria-label="File des commandes payees">
        <?php foreach ($rows as $o): ?>
            <article class="kitchen-card">
                <div class="kitchen-card-header">
                    <span class="kitchen-card-number"><?= $esc($o['order_number'] ?? '') ?></span>
                    <span class="kitchen-card-source"><?= $esc($sourceLabel((string) ($o['source'] ?? ''))) ?></span>
                </div>
                <div class="kitchen-card-body">
                    <p class="kitchen-line">Mode : <?= $esc($modeLabel((string) ($o['service_mode'] ?? ''))) ?></p>
                    <?php if (($o['service_tag'] ?? '') !== ''): ?>
                        <p class="kitchen-line">Table : <?= $esc($o['service_tag']) ?></p>
                    <?php endif; ?>
                    <p class="kitchen-line">Payee a : <?= $esc($o['paid_at'] ?? '') ?></p>
                </div>
                <?php if ($can): ?>
                    <div class="kitchen-card-footer">
                        <form method="post" action="/admin/orders/<?= rawurlencode((string) ($o['order_number'] ?? '')) ?>/deliver">
                            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                            <button class="btn btn-primary" type="submit">Remettre (livree)</button>
                        </form>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
