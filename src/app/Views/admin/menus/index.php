<?php

declare(strict_types=1);

/**
 * Liste des menus (CRUD admin), injectee dans admin/layout.php. Texte echappe.
 * Le toggle de disponibilite est un POST CSRF (pas de JS).
 *
 * @var array<int, array<string, mixed>> $menus
 * @var string                           $csrfToken
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($menus) && is_array($menus) ? $menus : [];
$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$euros = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' EUR';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Menus</h1>
        <p class="page-subtitle">Gestion des menus composes (burger + slots)</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="/admin/menus/new">Nouveau menu</a>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Categorie</th>
                    <th>Burger de base</th>
                    <th>Prix (Normal &ndash; Maxi)</th>
                    <th>Statut</th>
                    <th style="width:240px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="muted">Aucun menu.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int) ($row['id'] ?? 0);
                    $available = (int) ($row['is_available'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($row['name'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['category_name'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['burger_name'] ?? '') ?></td>
                        <td><?= $esc($euros((int) ($row['price_normal_cents'] ?? 0))) ?> &ndash; <?= $esc($euros((int) ($row['price_maxi_cents'] ?? 0))) ?></td>
                        <td>
                            <?php if ($available): ?>
                                <span class="pill pill-success">Disponible</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">Indisponible</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-secondary" href="/admin/menus/<?= $id ?>/edit">Modifier</a>
                            <form method="post" action="/admin/menus/<?= $id ?>/toggle" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                <button class="btn btn-secondary" type="submit"><?= $available ? 'Desactiver' : 'Activer' ?></button>
                            </form>
                            <a class="btn btn-secondary" href="/admin/menus/<?= $id ?>/delete">Supprimer</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
