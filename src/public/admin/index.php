<?php
declare(strict_types=1);

// Stub pour debloquer le routage Apache + valider la chaine FastCGI vers PHP-FPM.
// Sera remplace par le front controller MVC en phase P2 (src/Core/Router.php a venir).

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$phpVersion = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
$now = htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Wakdo - back-office</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; color: #222; }
        img { max-height: 80px; }
        small { color: #666; }
        code { background: #f4f4f4; padding: 0.1em 0.3em; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>Wakdo - back-office</h1>
    <p>En construction.</p>
    <p><small>Phase P1 - conception Merise en cours. Le back-office sera implemente en phases P2 a P4.</small></p>
    <hr>
    <p><small>Diagnostic FastCGI : PHP <code><?= $phpVersion ?></code> repond a <code><?= $now ?></code>.</small></p>
    <p><small>TODO P2 : assets partages (logo, images produits) via Apache Alias entre les 2 vhosts.</small></p>
</body>
</html>
