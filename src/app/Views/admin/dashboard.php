<?php

declare(strict_types=1);

/**
 * Tableau de bord, injecte dans admin/layout.php (direction UI A+C).
 * Indicateurs synthetiques catalogue + sante stock (StatsRepository).
 *
 * @var string                          $currentUserName
 * @var array<string, array<string,int>> $counts
 * @var array{bands:array<string,int>}   $stock
 */

$name = htmlspecialchars($currentUserName ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');

$kpi = isset($counts) && is_array($counts) ? $counts : [];
$stk = isset($stock) && is_array($stock) ? $stock : [];

$nProducts   = (int) ($kpi['products']['available'] ?? 0);
$nCategories = (int) ($kpi['categories']['total'] ?? 0);
$nMenus      = (int) ($kpi['menus']['total'] ?? 0);
$nCritical   = (int) ($stk['bands']['critical'] ?? 0);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Tableau de bord</h1>
        <p class="page-subtitle">Bienvenue, <?= $name ?> &mdash; voici l'essentiel de votre restaurant aujourd'hui.</p>
    </div>
</div>

<section class="dash-tiles" aria-label="Indicateurs cles">
    <article class="tile">
        <div class="tile-top">
            <span class="tile-ico"><svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 11a2 2 0 01-2 2H8a2 2 0 01-2-2L5 8z"/><path d="M9 8a3 3 0 016 0"/></svg></span>
            <span class="tile-tag">En vente</span>
        </div>
        <div class="tile-value"><?= $nProducts ?></div>
        <div class="tile-label">Produits actifs</div>
    </article>

    <article class="tile">
        <div class="tile-top">
            <span class="tile-ico"><svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
            <span class="tile-tag">Classees</span>
        </div>
        <div class="tile-value"><?= $nCategories ?></div>
        <div class="tile-label">Categories</div>
    </article>

    <article class="tile">
        <div class="tile-top">
            <span class="tile-ico"><svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14a1 1 0 011 1v17l-8-4-8 4V4a1 1 0 011-1z"/></svg></span>
            <span class="tile-tag">Proposes</span>
        </div>
        <div class="tile-value"><?= $nMenus ?></div>
        <div class="tile-label">Menus</div>
    </article>

    <article class="tile<?= $nCritical > 0 ? ' alert' : '' ?>">
        <div class="tile-top">
            <span class="tile-ico"><svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 16H3l9-16z"/><path d="M12 9v5"/><path d="M12 17.5h.01"/></svg></span>
            <span class="tile-tag"><?= $nCritical > 0 ? 'A recommander' : 'OK' ?></span>
        </div>
        <div class="tile-value"><?= $nCritical ?></div>
        <div class="tile-label">Stock critique</div>
    </article>
</section>
