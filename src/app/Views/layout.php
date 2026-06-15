<?php

declare(strict_types=1);

/**
 * Gabarit HTML5 du back-office. Recoit $title et $content depuis le controleur.
 * Les variables sont fournies par Controller::view() via extract().
 *
 * @var string $title
 * @var string $content
 */

$pageTitle = htmlspecialchars($title ?? 'Wakdo', ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Back-office prive : jamais indexe par les moteurs de recherche. -->
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 1.5rem; }
        small { color: #666; }
        code { background: #f4f4f4; padding: 0.1em 0.3em; border-radius: 3px; }
    </style>
</head>
<body>
<?= $content ?? '' ?>
</body>
</html>
