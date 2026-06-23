<?php

declare(strict_types=1);

/**
 * Fragment 403, injecte dans admin/layout.php : l'utilisateur est authentifie
 * mais ne detient pas la permission requise (RG-T03).
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Acces refuse</h1>
        <p class="page-subtitle">Vous n'avez pas la permission d'acceder a cette page.</p>
    </div>
</div>

<section>
    <p><a href="/admin/dashboard">Retour au tableau de bord</a></p>
</section>
