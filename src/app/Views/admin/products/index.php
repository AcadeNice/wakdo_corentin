<?php

declare(strict_types=1);

/**
 * Liste des produits (CRUD admin), injectee dans admin/layout.php. Texte echappe.
 *
 * @var array<int, array<string, mixed>> $products
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($products) && is_array($products) ? $products : [];
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
                    $vat = (int) ($row['vat_rate'] ?? 100);
                    ?>
                    <tr>
                        <td class="fw-600"><?= $esc($row['name'] ?? '') ?></td>
                        <td class="muted"><?= $esc($row['category_name'] ?? '') ?></td>
                        <td><?= $esc($euros((int) ($row['price_cents'] ?? 0))) ?></td>
                        <td class="muted"><?= $vat === 55 ? '5,5%' : '10%' ?></td>
                        <td>
                            <?php if ($available): ?>
                                <span class="pill pill-success">Disponible</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">Indisponible</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/edit">Modifier</a>
                            <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/delete">Supprimer</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
