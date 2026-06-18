<?php

declare(strict_types=1);

/**
 * Shell du back-office (topbar + sidebar + zone de contenu), reutilise par toutes
 * les pages admin rendues serveur. Recoit le contenu de la page et le contexte
 * commun injecte par AdminController::adminView().
 *
 * Chemins d'assets ABSOLUS (/assets/...) : les pages sont servies sous des routes
 * /admin/... alors que les fichiers vivent a la racine du docroot du vhost admin ;
 * un chemin relatif resoudrait vers /admin/assets/... (404).
 *
 * @var string       $title
 * @var string       $content
 * @var string       $currentUserName
 * @var string       $currentUserRole
 * @var list<string> $permissions
 * @var string       $csrfToken
 * @var string       $activeNav
 * @var string|null  $flash
 */

$pageTitle = htmlspecialchars($title ?? 'Wakdo Admin', ENT_QUOTES, 'UTF-8');
$userName = htmlspecialchars($currentUserName ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$userRole = htmlspecialchars($currentUserRole ?? '', ENT_QUOTES, 'UTF-8');
$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$active = is_string($activeNav ?? null) ? $activeNav : '';

/** @var list<string> $perms */
$perms = isset($permissions) && is_array($permissions) ? $permissions : [];
$can = static fn (string $code): bool => in_array($code, $perms, true);

// Initiales pour l'avatar (2 lettres max), derivees du nom affiche. Fonctions
// multibyte (UTF-8) : un prenom a initiale accentuee (frequent en francais) doit
// produire une lettre valide, pas un octet de tete isole qui viderait l'echappement.
$initials = '';
foreach (preg_split('/\s+/', trim((string) ($currentUserName ?? ''))) ?: [] as $word) {
    if ($word !== '' && mb_strlen($initials, 'UTF-8') < 2) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
    }
}
$initials = $initials !== '' ? $initials : 'U';

/**
 * @param string $code  cle de nav active
 * @param string $current
 */
$navClass = static function (string $code, string $current): string {
    return $code === $current ? 'sidebar-item active' : 'sidebar-item';
};
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body data-user-email="<?= htmlspecialchars($currentUserEmail ?? '', ENT_QUOTES, 'UTF-8') ?>">
<div class="admin-layout">
    <header class="topbar">
        <div class="topbar-actions">
            <div class="topbar-user">
                <button class="topbar-user-btn" id="userMenuBtn" type="button" aria-haspopup="true" aria-expanded="false">
                    <div class="topbar-user-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                    <div>
                        <div class="topbar-user-name"><?= $userName ?></div>
                        <div class="topbar-user-role"><?= $userRole ?></div>
                    </div>
                </button>
                <div class="dropdown-menu" id="userMenu">
                    <a href="/admin/profile/pin">Mon PIN d'action sensible</a>
                    <div class="divider"></div>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <button class="danger" type="submit">Se deconnecter</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <nav class="sidebar">
        <div class="sidebar-brand">
            <img class="sidebar-brand-logo" src="/assets/images/logo.png" alt="Wakdo">
            <span class="sidebar-brand-name">Wak<span>do</span></span>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-section-label">Vue d'ensemble</div>
            <a href="/admin/dashboard" class="<?= $navClass('dashboard', $active) ?>">Tableau de bord</a>
        </div>

        <?php if ($can('category.manage') || $can('product.read') || $can('menu.read')): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-label">Catalogue</div>
            <?php if ($can('category.manage')): ?>
                <a href="/admin/categories" class="<?= $navClass('categories', $active) ?>">Categories</a>
            <?php endif; ?>
            <?php if ($can('product.read')): ?>
                <a href="/admin/products" class="<?= $navClass('products', $active) ?>">Produits</a>
            <?php endif; ?>
            <?php if ($can('menu.read')): ?>
                <a href="/admin/menus" class="<?= $navClass('menus', $active) ?>">Menus</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($can('stock.read')): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-label">Stock</div>
            <a href="/admin/ingredients" class="<?= $navClass('stock', $active) ?>">Ingredients</a>
        </div>
        <?php endif; ?>

        <?php if ($can('stats.read')): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-label">Pilotage</div>
            <a href="/admin/stats" class="<?= $navClass('stats', $active) ?>">Statistiques</a>
        </div>
        <?php endif; ?>

        <?php if ($can('user.read') || $can('role.manage')): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-label">Administration</div>
            <?php if ($can('user.read')): ?>
                <a href="/admin/users" class="<?= $navClass('users', $active) ?>">Utilisateurs</a>
            <?php endif; ?>
            <?php if ($can('role.manage')): ?>
                <a href="/admin/roles" class="<?= $navClass('roles', $active) ?>">Roles</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php /*
            Items de nav volontairement absents tant que leur page n'existe pas
            (un lien vers une route non enregistree renvoie un 404). A reactiver
            avec leur route respective : Commandes (order.read) -- domaine P4.
        */ ?>
    </nav>

    <main class="content">
        <?php $flashMessage = isset($flash) && is_string($flash) ? $flash : null; ?>
        <?php if ($flashMessage !== null && $flashMessage !== ''): ?>
            <div class="flash" role="status"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?= $content ?? '' ?>
    </main>
</div>
<script src="/assets/js/admin.js"></script>
<script src="/assets/js/pin-modal.js"></script>
</body>
</html>
