<?php

declare(strict_types=1);

/**
 * Liste des roles (RBAC, role.manage), injectee dans admin/layout.php. Texte echappe.
 *
 * @var array<int, array<string, mixed>> $roles
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($roles) && is_array($roles) ? $roles : [];
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Roles et permissions</h1>
        <p class="page-subtitle">Matrice RBAC. Modifier un role est une action sensible (PIN + audit).</p>
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
                    <th>Code</th>
                    <th>Libelle</th>
                    <th>Route par defaut</th>
                    <th>Source</th>
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
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($row['code'] ?? '') ?></td>
                        <td><?= $esc($row['label'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['default_route'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['order_source'] ?? '-') ?></td>
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
