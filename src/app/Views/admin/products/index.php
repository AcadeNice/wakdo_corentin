<?php

declare(strict_types=1);

/**
 * Liste des produits (CRUD admin), injectee dans admin/layout.php. Texte echappe.
 *
 * @var array<int, array<string, mixed>> $products
 * @var list<int>                        $autoUnavailable  ids en rupture auto (RG-T21)
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($products) && is_array($products) ? $products : [];
/** @var list<int> $autoIds */
$autoIds = isset($autoUnavailable) && is_array($autoUnavailable) ? array_map('intval', $autoUnavailable) : [];
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$euros = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' EUR';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Produits</h1>
        <p class="page-subtitle">Gestion des produits du catalogue</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="/admin/products/new">Nouveau produit</a>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Categorie</th>
                    <th>Prix</th>
                    <th>TVA</th>
                    <th>Statut</th>
                    <th style="width:160px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="muted">Aucun produit.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int) ($row['id'] ?? 0);
                    $available = (int) ($row['is_available'] ?? 0) === 1;
                    $autoRupture = in_array($id, $autoIds, true); // RG-T21 : stock-driven
                    $vat = (int) ($row['vat_rate'] ?? 100);
                    // R4/F9-4 : une ligne dont base_product_id est non nul est une
                    // VARIANTE de taille, pas un produit autonome. On la garde dans la
                    // liste (l'admin la voit et la gere) mais on la marque "Variante de
                    // X" pour qu'aucune confusion ne subsiste.
                    $baseProductId = isset($row['base_product_id']) && $row['base_product_id'] !== null
                        ? (int) $row['base_product_id'] : 0;
                    $isVariant = $baseProductId > 0;
                    $baseName = (string) ($row['base_name'] ?? '');
                    ?>
                    <tr>
                        <td class="fw-600">
                            <?= $esc($row['name'] ?? '') ?>
                            <?php if ($isVariant): ?>
                                <span class="pill pill-neutral" title="Cette ligne est une variante de taille, pas un produit affiche seul sur la borne">Variante de <?= $esc($baseName !== '' ? $baseName : '?') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= $esc($row['category_name'] ?? '') ?></td>
                        <td><?= $esc($euros((int) ($row['price_cents'] ?? 0))) ?></td>
                        <td class="muted"><?= $vat === 55 ? '5,5%' : '10%' ?></td>
                        <td>
                            <?php if (!$available): ?>
                                <span class="pill pill-neutral">Indisponible</span>
                            <?php elseif ($autoRupture): ?>
                                <span class="pill pill-warning" title="Un ingredient requis est en rupture critique (RG-T21)">Rupture auto</span>
                            <?php else: ?>
                                <span class="pill pill-success">Disponible</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/edit">Modifier</a>
                            <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/recipe">Recette</a>
                            <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/delete">Supprimer</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
