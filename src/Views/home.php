<?php

declare(strict_types=1);

/**
 * Fragment de la page d'accueil back-office, injecte dans layout.php.
 *
 * @var string $appEnv
 */

$env = htmlspecialchars($appEnv ?? 'unknown', ENT_QUOTES, 'UTF-8');
?>
<main>
    <h1>Wakdo back-office</h1>
    <p>Le squelette back-end (P2) est en ligne.</p>
    <p>
        <small>
            Coeur MVC from scratch : autoloader PSR-4 manuel, routeur, PDO prepared statements.
            Environnement : <code><?= $env ?></code>.
        </small>
    </p>
    <p>
        <small>Sonde de sante : <code>GET /api/health</code></small>
    </p>
</main>
