<?php

declare(strict_types=1);

/**
 * Liste des categories (CRUD admin), injectee dans admin/layout.php. Bascule de
 * visibilite via formulaire POST + CSRF (pas de GET mutant). Tout texte echappe.
 *
 * @var array<int, array<string, mixed>> $categories
 * @var string                           $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
/** @var array<int, array<string, mixed>> $rows */
$rows = isset($categories) && is_array($categories) ? $categories : [];

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Categories</h1>
        <p class="page-subtitle">Gestion des categories du catalogue</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="/admin/categories/new">Nouvelle categorie</a>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Libelle</th>
                    <th>Slug</th>
                    <th>Ordre</th>
                    <th>Statut</th>
                    <th style="width:160px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="5" class="muted">Aucune categorie.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int) ($row['id'] ?? 0);
                    $active = (int) ($row['is_active'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($row['name'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['slug'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['display_order'] ?? 0) ?></td>
                        <td>
                            <?php if ($active): ?>
                                <span class="pill pill-success">Visible</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">Masquee</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-secondary" href="/admin/categories/<?= $id ?>/edit">Modifier</a>
                            <form method="post" action="/admin/categories/<?= $id ?>/toggle" style="display:inline;">
                                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                <button class="btn btn-secondary" type="submit"><?= $active ? 'Masquer' : 'Afficher' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
