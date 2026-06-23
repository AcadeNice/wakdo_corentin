<?php

declare(strict_types=1);

/**
 * Liste des utilisateurs back-office (CRUD admin), injectee dans admin/layout.php.
 * Texte echappe (RG-T15). Les mutations sont gardees par permission cote serveur ;
 * les boutons ne s'affichent que selon les capacites de l'acteur.
 *
 * @var array<int, array<string, mixed>> $users
 * @var int                              $currentId   id de l'acteur (pas d'auto-desactivation)
 * @var bool                             $canCreate
 * @var bool                             $canUpdate
 * @var bool                             $canDeactiv
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($users) && is_array($users) ? $users : [];
$me = (int) ($currentId ?? 0);
$canCreate = (bool) ($canCreate ?? false);
$canUpdate = (bool) ($canUpdate ?? false);
$canDeactiv = (bool) ($canDeactiv ?? false);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Utilisateurs</h1>
        <p class="page-subtitle">Comptes du back-office. Les operations sensibles exigent un PIN.</p>
    </div>
    <?php if ($canCreate): ?>
    <div class="page-actions">
        <a class="btn btn-primary" href="/admin/users/new">Nouvel utilisateur</a>
    </div>
    <?php endif; ?>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Statut</th>
                    <th style="width:280px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="5" class="muted">Aucun utilisateur.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int) ($row['id'] ?? 0);
                    $active = (int) ($row['is_active'] ?? 0) === 1;
                    $anon = ($row['anonymized_at'] ?? null) !== null;
                    $isSelf = $id === $me;
                    $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($name !== '' ? $name : '(anonymise)') ?><?= $isSelf ? ' <span class="muted">(vous)</span>' : '' ?></td>
                        <td class="muted"><?= $esc($row['email'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['role_label'] ?? '') ?></td>
                        <td>
                            <?php if ($anon): ?>
                                <span class="pill pill-neutral">Anonymise</span>
                            <?php elseif ($active): ?>
                                <span class="pill pill-success">Actif</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">Inactif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$anon): ?>
                                <?php if ($canUpdate): ?>
                                    <a class="btn btn-secondary" href="/admin/users/<?= $id ?>/edit">Modifier</a>
                                    <a class="btn btn-secondary" href="/admin/users/<?= $id ?>/reset-pin">Reset PIN</a>
                                <?php endif; ?>
                                <?php if ($canDeactiv && $active && !$isSelf): ?>
                                    <a class="btn btn-secondary" href="/admin/users/<?= $id ?>/deactivate">Desactiver</a>
                                <?php endif; ?>
                                <?php if ($canUpdate && !$isSelf): ?>
                                    <a class="btn btn-secondary" href="/admin/users/<?= $id ?>/erase">Anonymiser</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
