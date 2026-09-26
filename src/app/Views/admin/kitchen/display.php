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
 * $highlightOrder (numero de commande ou null) : posee par OrderAdminController::ready()
 * juste avant la redirection ici, consommee une seule fois (comme _flash). Signale la
 * carte concernee par le dernier changement de statut, SANS s'appuyer sur la seule
 * couleur (WCAG 1.4.1) : classe .kitchen-card--updated (contour qui s'efface seul,
 * cf. <style> plus bas) + texte "Mise a jour a l'instant" dans l'en-tete de la carte.
 *
 * @var list<array<string, mixed>> $orders
 * @var bool        $canDeliver
 * @var bool        $canPrepare
 * @var string      $csrfToken
 * @var string|null $highlightOrder
 */

$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $esc($csrfToken ?? '');
$rows = isset($orders) && is_array($orders) ? $orders : [];
$can = !empty($canDeliver);
$canPrep = !empty($canPrepare);
$highlight = isset($highlightOrder) && is_string($highlightOrder) ? $highlightOrder : null;

$sourceLabel = static fn (string $s): string => ['kiosk' => 'Borne', 'counter' => 'Comptoir', 'drive' => 'Drive'][$s] ?? $s;
// Etat de preparation visible (retour oral #8) -> libelle FR sur la carte.
$statusLabel = static fn (string $s): string => ['paid' => 'En attente', 'preparing' => 'En préparation', 'ready' => 'Prête'][$s] ?? $s;
$modeLabel = static fn (string $m): string => $m === 'dine_in' ? 'Sur place' : ($m === 'drive' ? 'Drive' : 'À emporter');

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
        <p class="page-subtitle">File des commandes payées, de la plus ancienne à la plus récente.</p>
    </div>
    <span class="kitchen-clock" id="kitchenTime" aria-hidden="true"></span>
</div>

<?php if ($rows === []): ?>
    <p>Aucune commande en attente de préparation.</p>
<?php else: ?>
    <section class="kitchen-grid" aria-label="File des commandes payées">
        <?php foreach ($rows as $o): ?>
            <?php
            $items = isset($o['items']) && is_array($o['items']) ? $o['items'] : [];
            $band = (string) ($o['sla_band'] ?? 'fresh');
            $status = (string) ($o['status'] ?? 'paid');
            $num = (string) ($o['order_number'] ?? '');
            $isUpdated = $num !== '' && $num === $highlight;
            $cardClass = $esc($slaClass($band)) . ($isUpdated ? ' kitchen-card--updated' : '');
            ?>
            <article class="kitchen-card <?= $cardClass ?>">
                <div class="kitchen-card-header">
                    <span class="kitchen-order-num"><?= $esc($num) ?></span>
                    <span class="kitchen-status kitchen-status--<?= $esc($status) ?>"><?= $esc($statusLabel($status)) ?></span>
                    <span class="kitchen-card-source"><?= $esc($sourceLabel((string) ($o['source'] ?? ''))) ?></span>
                </div>
                <div class="kitchen-card-body">
                    <?php if ($isUpdated): ?><p class="kitchen-card__badge">Mise à jour à l'instant</p><?php endif; ?>
                    <p class="kitchen-line">Mode : <?= $esc($modeLabel((string) ($o['service_mode'] ?? ''))) ?></p>
                    <?php if (($o['service_tag'] ?? '') !== ''): ?>
                        <p class="kitchen-line">Table : <?= $esc($o['service_tag']) ?></p>
                    <?php endif; ?>
                    <p class="kitchen-line">Payée à : <?= $esc($o['paid_at'] ?? '') ?></p>
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
                                <button class="btn btn-secondary" type="submit">Prête</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($can): ?>
                            <form method="post" action="/admin/orders/<?= rawurlencode($num) ?>/deliver">
                                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                <button class="btn btn-primary" type="submit">Remettre (livrée)</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php /*
 * Lisibilite a distance : cet ecran est regarde de quelques metres, mains prises,
 * pas consulte de pres comme un tableau de bureau -- le numero de commande, le statut
 * et le contenu de la commande doivent se lire d'un coup d'oeil. Sur-echelle locale a
 * CE fichier au-dessus des tailles historiques (12-16px), avec les jetons du lot 0
 * (--text-*, admin.css :root) plutot que des valeurs codees en dur -- aucune classe
 * renommee, uniquement surchargee. admin.css reste hors perimetre (lot 0/1, en
 * parallele) : la surcharge vit ici, pas dans la feuille commune.
*/ ?>
<style>
.kitchen-order-num {
    font-size: var(--text-2xl);
}
.kitchen-status {
    font-size: var(--text-md);
    padding: var(--space-1) var(--space-3);
}
.kitchen-card-source {
    font-size: var(--text-base);
}
.kitchen-line {
    font-size: var(--text-lg);
}
.kds-item {
    font-size: var(--text-lg);
    line-height: 1.4;
}
.kitchen-clock {
    font-size: var(--text-xl);
}
</style>
<?php /*
 * Carte mise en evidence apres un changement de statut ("Prete") -- non couvert par
 * le lot 0 (design-system.md, plan.md 2.6 : point volontairement exclu du gel commun
 * pour ne pas toucher plusieurs lots a la fois). Scope local a CE fichier (le lot 2
 * n'a pas le droit de modifier admin.css, propriete d'un autre lot en parallele) :
 * jetons de couleur repris de :root (admin.css), aucune valeur codee en dur. Contour
 * (box-shadow), pas fond : .kds-order--warn/--late posent deja un fond (bande SLA),
 * un second fond entrerait en concurrence visuelle. Respecte prefers-reduced-motion.
 * Le contour seul ne porte pas le sens (WCAG 1.4.1) : le texte ".kitchen-card__badge"
 * l'accompagne toujours.
*/ ?>
<style>
.kitchen-card--updated {
    animation: lot2-card-updated-fade 4s ease-out forwards;
}
.kitchen-card__badge {
    margin: 0 0 8px;
    font-size: var(--text-sm);
    font-weight: 700;
    color: var(--color-success-text);
    animation: lot2-badge-fade 4s ease-out forwards;
}
@keyframes lot2-card-updated-fade {
    0%, 15% { box-shadow: 0 0 0 3px var(--color-success); }
    100% { box-shadow: 0 0 0 3px transparent; }
}
@keyframes lot2-badge-fade {
    0%, 70% { opacity: 1; }
    100% { opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .kitchen-card--updated { animation: none; box-shadow: 0 0 0 3px var(--color-success); }
    .kitchen-card__badge { animation: none; }
}
</style>
