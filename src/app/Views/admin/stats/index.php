<?php

declare(strict_types=1);

/**
 * Tableau de bord statistiques (stats.read), injecte dans admin/layout.php.
 * KPIs disponibles en P3 : compteurs de catalogue + sante du stock (RG-T21).
 * Les KPIs de vente (CA, volumes) arrivent avec le domaine commande (P4).
 *
 * @var array{products:array{total:int,available:int}, categories:array{total:int,active:int}, menus:array{total:int,available:int}, ingredients:array{total:int,active:int}} $counts
 * @var array{active_total:int, bands:array{normal:int,low:int,critical:int}, alerts:list<array{name:string,stock_pct:int,stock_band:string}>} $stock
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
/** @var array<string, array<string, int>> $c */
$c = isset($counts) && is_array($counts) ? $counts : [];
/** @var array<string, mixed> $s */
$s = isset($stock) && is_array($stock) ? $stock : ['active_total' => 0, 'bands' => ['normal' => 0, 'low' => 0, 'critical' => 0], 'alerts' => []];
$bands = is_array($s['bands'] ?? null) ? $s['bands'] : ['normal' => 0, 'low' => 0, 'critical' => 0];
/** @var list<array<string, mixed>> $alerts */
$alerts = is_array($s['alerts'] ?? null) ? $s['alerts'] : [];

$bandLabel = static fn (string $b): string => match ($b) {
    'critical' => 'Critique',
    'low'      => 'Alerte',
    default    => 'Normal',
};
$bandPill = static fn (string $b): string => match ($b) {
    'critical' => 'pill-danger',
    'low'      => 'pill-warning',
    default    => 'pill-success',
};

/** @var list<array{key:string, label:string, sub:string}> $cards */
$cards = [
    ['key' => 'products',    'label' => 'Produits',    'sub' => 'available'],
    ['key' => 'menus',       'label' => 'Menus',       'sub' => 'available'],
    ['key' => 'categories',  'label' => 'Categories',  'sub' => 'active'],
    ['key' => 'ingredients', 'label' => 'Ingredients', 'sub' => 'active'],
];
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Statistiques</h1>
        <p class="page-subtitle">Sante du catalogue et du stock. Les indicateurs de vente arriveront avec les commandes (P4).</p>
    </div>
</div>

<div class="stats-cards">
    <?php foreach ($cards as $card): ?>
        <?php
        $entity = is_array($c[$card['key']] ?? null) ? $c[$card['key']] : [];
        $total = (int) ($entity['total'] ?? 0);
        $sub = (int) ($entity[$card['sub']] ?? 0);
        $subLabel = $card['sub'] === 'available' ? 'disponibles' : 'actifs';
        ?>
        <div class="stat-card">
            <div class="stat-card__value"><?= $esc($total) ?></div>
            <div class="stat-card__label"><?= $esc($card['label']) ?></div>
            <div class="stat-card__sub muted"><?= $esc($sub) ?> <?= $esc($subLabel) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="page-header">
    <div>
        <h2 class="page-title">Sante du stock</h2>
        <p class="page-subtitle"><?= $esc((int) ($s['active_total'] ?? 0)) ?> ingredients actifs — normal <?= $esc((int) $bands['normal']) ?>, alerte <?= $esc((int) $bands['low']) ?>, critique <?= $esc((int) $bands['critical']) ?> (RG-T21).</p>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Ingredient</th>
                    <th>Stock</th>
                    <th>Etat</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($alerts === []): ?>
                    <tr><td colspan="3" class="muted">Aucun ingredient en alerte ou rupture. Stock sain.</td></tr>
                <?php endif; ?>
                <?php foreach ($alerts as $a): ?>
                    <?php $band = (string) ($a['stock_band'] ?? 'normal'); ?>
                    <tr>
                        <td class="fw-600"><?= $esc($a['name'] ?? '') ?></td>
                        <td><?= $esc((int) ($a['stock_pct'] ?? 0)) ?>%</td>
                        <td>
                            <span class="pill <?= $esc($bandPill($band)) ?>" data-band="<?= $esc($band) ?>"><?= $esc($bandLabel($band)) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
