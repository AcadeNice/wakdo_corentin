<?php

declare(strict_types=1);

/**
 * Tableau de bord, injecte dans admin/layout.php (direction UI A+C).
 * Indicateurs synthetiques catalogue + sante stock (StatsRepository).
 *
 * @var string                          $currentUserName
 * @var array<string, array<string,int>> $counts
 * @var array{bands:array<string,int>}   $stock
 * @var array<string, mixed>|null        $sales      KPIs de vente (si role stats.read), sinon non defini
 * @var list<array{day:string,orders:int,revenue_cents:int}>|null $salesByDay  serie 7 jours (si stats.read)
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

<?php
/**
 * Bloc VENTES : present uniquement si le controleur a fourni $sales (role stats.read).
 * Un equipier sans cette permission ne voit pas le CA (pas de garde de page : juste un
 * enrichissement conditionnel). Tout est genere serveur : aucune lib JS, aucun CDN.
 *
 * @var array<string, mixed>|null $sales
 */
$sales = isset($sales) && is_array($sales) ? $sales : null;
if ($sales !== null):
    $euros = static fn (mixed $cents): string => number_format(((int) $cents) / 100, 2, ',', ' ') . ' EUR';
    /** @var list<array<string, mixed>> $series */
    $series = isset($salesByDay) && is_array($salesByDay) ? $salesByDay : [];
    $maxRev = 0;
    $windowTotal = 0;
    foreach ($series as $pt) {
        $rev = (int) ($pt['revenue_cents'] ?? 0);
        $maxRev = max($maxRev, $rev);
        $windowTotal += $rev;
    }
?>
<div class="page-header">
    <div>
        <h2 class="page-title">Ventes</h2>
        <p class="page-subtitle"><?= htmlspecialchars($euros($sales['revenue_today_cents'] ?? 0), ENT_QUOTES, 'UTF-8') ?> aujourd'hui &mdash; <?= (int) ($sales['paid_count_today'] ?? 0) ?> commande(s) payee(s).</p>
    </div>
</div>

<section class="stats-cards" aria-label="Indicateurs de vente">
    <div class="stat-card">
        <div class="stat-card__value"><?= htmlspecialchars($euros($sales['revenue_today_cents'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="stat-card__label">CA du jour</div>
        <div class="stat-card__sub muted"><?= htmlspecialchars($euros($sales['revenue_cents'] ?? 0), ENT_QUOTES, 'UTF-8') ?> au total</div>
    </div>
    <div class="stat-card">
        <div class="stat-card__value"><?= (int) ($sales['paid_count_today'] ?? 0) ?></div>
        <div class="stat-card__label">Commandes payees (jour)</div>
        <div class="stat-card__sub muted"><?= (int) ($sales['paid_count'] ?? 0) ?> au total</div>
    </div>
    <div class="stat-card">
        <div class="stat-card__value"><?= htmlspecialchars($euros($sales['avg_basket_cents'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="stat-card__label">Panier moyen</div>
        <div class="stat-card__sub muted">par commande payee</div>
    </div>
</section>

<?php
$n = count($series);
if ($n > 0):
    // Mini-graphe SVG INLINE (balisage, pas de script ni lib CDN -> conforme CSP
    // script-src 'self'). Coordonnees fixes + viewBox : le SVG s'adapte en largeur.
    $vbW = 700;
    $vbH = 200;
    $padX = 12;
    $baseY = 158;
    $maxBarH = 130;
    $slot = ($vbW - 2 * $padX) / $n;
    $barW = $slot * 0.62;
    $summary = 'Chiffre d affaires des ' . $n . ' derniers jours, total ' . $euros($windowTotal);
?>
<div class="page-header"><div><h2 class="page-title">CA des <?= $n ?> derniers jours</h2></div></div>
<svg class="dash-chart" viewBox="0 0 <?= $vbW ?> <?= $vbH ?>" role="img" aria-label="<?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?>" preserveAspectRatio="xMidYMid meet">
    <line class="dash-axis" x1="<?= $padX ?>" y1="<?= $baseY ?>" x2="<?= $vbW - $padX ?>" y2="<?= $baseY ?>" />
    <?php foreach ($series as $i => $pt):
        $rev = (int) ($pt['revenue_cents'] ?? 0);
        $h = $maxRev > 0 ? (int) round($rev / $maxRev * $maxBarH) : 0;
        $x = $padX + $i * $slot + ($slot - $barW) / 2;
        $cx = $x + $barW / 2;
        $day = (string) ($pt['day'] ?? '');
        $label = strlen($day) === 10 ? substr($day, 8, 2) . '/' . substr($day, 5, 2) : $day;
        $tip = htmlspecialchars($label . ' : ' . $euros($rev), ENT_QUOTES, 'UTF-8');
    ?>
        <?php if ($h > 0): ?>
        <rect class="dash-bar" x="<?= round($x, 1) ?>" y="<?= $baseY - $h ?>" width="<?= round($barW, 1) ?>" height="<?= $h ?>" rx="3"><title><?= $tip ?></title></rect>
        <?php endif; ?>
        <text class="dash-label" x="<?= round($cx, 1) ?>" y="<?= $baseY + 22 ?>" text-anchor="middle"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></text>
    <?php endforeach; ?>
</svg>
<?php endif; ?>
<?php endif; ?>
