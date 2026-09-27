<?php

declare(strict_types=1);

/**
 * Gabarit HTML5 du back-office. Recoit $title et $content depuis le controleur.
 * Les variables sont fournies par Controller::view() via extract().
 *
 * @var string $title
 * @var string $content
 * @var callable(string): string $asset  adresse d'un fichier statique, marqueur de version compris (App\Core\Asset)
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
    <link rel="stylesheet" href="<?= $asset('/assets/css/admin.css') ?>">
</head>
<body>
<?= $content ?? '' ?>
<?php /* Controle de saisie en temps reel (Cr 2.b.1) : connexion, oubli et nouveau mot de passe. */ ?>
<script src="<?= $asset('/assets/js/form-validation.js') ?>"></script>
</body>
</html>
