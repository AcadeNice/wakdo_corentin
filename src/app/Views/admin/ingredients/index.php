<?php

declare(strict_types=1);

/**
 * Liste du stock (READ_STOCK 9.3), injectee dans admin/layout.php. Affiche le
 * pourcentage et la bande calcules (RG-2) ; les liens d'action sont conditionnes
 * aux permissions (la garde reelle reste par-route). Texte echappe.
 *
 * @var array<int, array<string, mixed>> $ingredients
 * @var bool   $canManage
 * @var bool   $canRestock
 * @var bool   $canCount
 * @var string $csrfToken
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($ingredients) && is_array($ingredients) ? $ingredients : [];
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$manage = (bool) ($canManage ?? false);
$restock = (bool) ($canRestock ?? false);
$count = (bool) ($canCount ?? false);

$bandLabel = static fn (string $band): string => match ($band) {
    'critical' => 'pill pill-danger',
    'low'      => 'pill pill-warning',
    default    => 'pill pill-success',
};
$bandText = static fn (string $band): string => match ($band) {
    'critical' => 'Critique',
    'low'      => 'Alerte',
    default    => 'Normal',
};
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Stock</h1>
        <p class="page-subtitle">Ingredients, niveaux de stock et mouvements</p>
    </div>
    <?php if ($manage): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="/admin/ingredients/new">Nouvel ingredient</a>
        </div>
    <?php endif; ?>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Ingredient</th>
                    <th>Unite</th>
                    <th>Stock</th>
                    <th>Niveau</th>
                    <th>Statut</th>
                    <th style="width:280px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="muted">Aucun ingredient.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int) ($row['id'] ?? 0);
                    $active = (int) ($row['is_active'] ?? 0) === 1;
                    $band = (string) ($row['stock_band'] ?? 'normal');
                    $pct = (int) ($row['stock_pct'] ?? 0);
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($row['name'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['unit'] ?? '') ?></td>
                        <td>
                            <?= $esc((string) ((int) ($row['stock_quantity'] ?? 0))) ?>
                            <span class="muted">/ <?= $esc((string) ((int) ($row['stock_capacity'] ?? 0))) ?> (<?= $pct ?>%)</span>
                        </td>
                        <td><span class="<?= $bandLabel($band) ?>"><?= $bandText($band) ?></span></td>
                        <td>
                            <?php if ($active): ?>
                                <span class="pill pill-success">Actif</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">Inactif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-secondary" href="/admin/ingredients/<?= $id ?>/movements">Mouvements</a>
                            <?php if ($restock): ?>
                                <a class="btn btn-secondary" href="/admin/ingredients/<?= $id ?>/restock">Reappro</a>
                            <?php endif; ?>
                            <?php if ($count): ?>
                                <a class="btn btn-secondary" href="/admin/ingredients/<?= $id ?>/inventory">Inventaire</a>
                            <?php endif; ?>
                            <?php if ($manage): ?>
                                <a class="btn btn-secondary" href="/admin/ingredients/<?= $id ?>/edit">Modifier</a>
                                <form method="post" action="/admin/ingredients/<?= $id ?>/toggle" style="display:inline;">
                                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                    <button class="btn btn-secondary" type="submit"><?= $active ? 'Desactiver' : 'Reactiver' ?></button>
                                </form>
                                <a class="btn btn-secondary" href="/admin/ingredients/<?= $id ?>/delete">Supprimer</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
