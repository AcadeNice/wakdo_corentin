<?php

declare(strict_types=1);

/**
 * Liste des roles (RBAC, role.manage), injectee dans admin/layout.php. Texte echappe.
 * Presentation humanisee : page d'accueil et canal affiches en clair (la base garde
 * les chemins / enums techniques). Le code interne (admin, manager, kitchen...) n'est
 * PAS affiche ici : la migration 0012_role_labels_fr.sql documente explicitement que
 * "code, default_route et order_source restent des identifiants techniques, jamais
 * affiches en clair aux equipiers" -- le nom (colonne "Nom") est le seul identifiant
 * qui doit apparaitre a l'ecran (balayage 2026-09-26, defaut "texte technique",
 * roles/index.php:71 avant correction).
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
    '/admin/categories'  => 'Catégories',
    '/admin/users'       => 'Comptes',
    '/admin/roles'       => 'Rôles',
    // Roles operationnels (kitchen/counter/drive, seed 0001) : sans ces entrees, le
    // chemin technique brut s'affichait dans la colonne (F40, textes techniques).
    '/kitchen/display'   => 'Écran cuisine (KDS)',
    '/counter/orders'    => 'Comptoir',
    '/drive/orders'      => 'Drive',
];
$canalLabels = ['kiosk' => 'Borne', 'counter' => 'Comptoir', 'drive' => 'Drive'];
$routeHuman = static fn (string $r): string => $r === '' ? '—' : ($routeLabels[$r] ?? $r);
$canalHuman = static fn (?string $s): string => ($s === null || $s === '') ? '—' : ($canalLabels[$s] ?? $s);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Rôles et droits d'accès</h1>
        <p class="page-subtitle">Modifier un rôle est une action sensible (confirmation par PIN).</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="/admin/roles/new">Nouveau rôle</a>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Page d'accueil</th>
                    <th>Canal</th>
                    <th>Statut</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="5" class="muted">Aucun rôle.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int) ($row['id'] ?? 0);
                    $active = (int) ($row['is_active'] ?? 0) === 1;
                    $src = isset($row['order_source']) && is_string($row['order_source']) ? $row['order_source'] : null;
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($row['label'] ?? '') ?></td>
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
