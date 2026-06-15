<?php

declare(strict_types=1);

/**
 * Fragment du tableau de bord, injecte dans admin/layout.php. Volontairement
 * minimal en chunk shell : les KPI reels (ventes, commandes) viendront avec le
 * chunk statistiques (permission stats.read).
 *
 * @var string $currentUserName
 */

$name = htmlspecialchars($currentUserName ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Tableau de bord</h1>
        <p class="page-subtitle">Bienvenue, <?= $name ?>.</p>
    </div>
</div>

<section>
    <p>Le back-office est en ligne. Utilisez la navigation pour gerer le catalogue,
       les commandes et les utilisateurs selon vos permissions.</p>
    <p><small>Les indicateurs (ventes, commandes du jour) seront ajoutes prochainement.</small></p>
</section>
