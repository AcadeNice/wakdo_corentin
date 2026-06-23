<?php

declare(strict_types=1);

/**
 * Liste des roles (RBAC, role.manage), injectee dans admin/layout.php. Texte echappe.
 * Presentation humanisee : page d'accueil et canal affiches en clair (la base garde
 * les chemins / enums techniques).
 *
 * @var array<int, array<string, mixed>> $roles
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($roles) && is_array($roles) ? $roles : [];
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$routeLabels = [
    '/admin/dashboard'   => 'Tableau de bord',
    '/admin/stats'       => 'Statistiques',
    '/admin/products'    => 'Produits',
    '/admin/menus'       => 'Menus',
    '/admin/ingredients' => 'Stock',
    '/admin/categories'  => 'Categories',
    '/admin/users'       => 'Comptes',
    '/admin/roles'       => 'Roles',
];
$canalLabels = ['kiosk' => 'Borne', 'counter' => 'Comptoir', 'drive' => 'Drive'];
$routeHuman = static fn (string $r): string => $r === '' ? '—' : ($routeLabels[$r] ?? $r);
$canalHuman = static fn (?string $s): string => ($s === null || $s === '') ? '—' : ($canalLabels[$s] ?? $s);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Roles et droits d'acces</h1>
        <p class="page-subtitle">Modifier un role est une action sensible (confirmation par PIN).</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="/admin/roles/new">Nouveau role</a>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Code interne</th>
                    <th>Page d'accueil</th>
                    <th>Canal</th>
                    <th>Statut</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="muted">Aucun role.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int) ($row['id'] ?? 0);
                    $active = (int) ($row['is_active'] ?? 0) === 1;
                    $src = isset($row['order_source']) && is_string($row['order_source']) ? $row['order_source'] : null;
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($row['label'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['code'] ?? '') ?></td>
                        <td class="muted"><?= $esc($routeHuman((string) ($row['default_route'] ?? ''))) ?></td>
                        <td class="muted"><?= $esc($canalHuman($src)) ?></td>
                        <td>
                            <?php if ($active): ?>
                                <span class="pill pill-success">Actif</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">Inactif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-secondary" href="/admin/roles/<?= $id ?>/edit">Modifier</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
