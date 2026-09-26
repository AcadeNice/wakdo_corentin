<?php

declare(strict_types=1);

/**
 * Liste des categories (CRUD admin), injectee dans admin/layout.php. Bascule de
 * visibilite via formulaire POST + CSRF (pas de GET mutant). Tout texte echappe.
 *
 * Balayage 2026-09-26 ("texte technique") : la colonne "Reference" affichait le
 * slug seul, sans etiquette utile pour un equipier (identifiant technique de lien,
 * pas une information de gestion) -- retiree plutot que redecoree, le libelle
 * suffit a identifier la categorie dans cette liste. Colonne "Ordre" alignee a
 * droite (design-system.md 2.3) ; actions de ligne regroupees (design-system.md
 * 2.4) -- pas de bouton irreversible sur cette page (bascule visible/masquee
 * reste reversible), donc pas de separateur "danger".
 *
 * @var array<int, array<string, mixed>> $categories
 * @var string                           $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
/** @var array<int, array<string, mixed>> $rows */
$rows = isset($categories) && is_array($categories) ? $categories : [];
// Reindexation : le rang sert a griser la fleche du haut sur la premiere
// ligne et celle du bas sur la derniere.
$rows = array_values($rows);
$dernierRang = count($rows) - 1;

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Catégories</h1>
        <p class="page-subtitle">Gestion des catégories du catalogue</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="/admin/categories/new">Nouvelle catégorie</a>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Libellé</th>
                    <th class="table-num">Ordre</th>
                    <th>Statut</th>
                    <th style="width:160px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="4" class="muted">Aucune catégorie.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $rang => $row): ?>
                    <?php
                    $id = (int) ($row['id'] ?? 0);
                    $active = (int) ($row['is_active'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($row['name'] ?? '') ?></td>
                        <td class="order-cell table-num">
                            <span class="muted"><?= $esc($row['display_order'] ?? 0) ?></span>
                            <?php $libelle = $esc($row['name'] ?? ''); ?>
                            <form method="post" action="/admin/categories/<?= $id ?>/move" style="display:inline;">
                                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="direction" value="up">
                                <button class="btn-order" type="submit" aria-label="Monter <?= $libelle ?>" title="Monter"<?= $rang === 0 ? ' disabled' : '' ?>>&#9650;</button>
                            </form>
                            <form method="post" action="/admin/categories/<?= $id ?>/move" style="display:inline;">
                                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="direction" value="down">
                                <button class="btn-order" type="submit" aria-label="Descendre <?= $libelle ?>" title="Descendre"<?= $rang === $dernierRang ? ' disabled' : '' ?>>&#9660;</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($active): ?>
                                <span class="pill pill-success">Visible</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">Masquée</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="row-actions">
                                <a class="btn btn-secondary" href="/admin/categories/<?= $id ?>/edit">Modifier</a>
                                <form method="post" action="/admin/categories/<?= $id ?>/toggle">
                                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                    <button class="btn btn-secondary" type="submit"><?= $active ? 'Masquer' : 'Afficher' ?></button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
