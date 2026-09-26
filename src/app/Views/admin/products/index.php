<?php

declare(strict_types=1);

/**
 * Liste des produits (CRUD admin), injectee dans admin/layout.php. Texte echappe.
 *
 * @var array<int, array<string, mixed>> $products
 * @var list<int>                        $autoUnavailable  ids en rupture auto (RG-T21)
 * @var list<string>                     $permissions      permissions du role courant (injectees par AdminController::adminView)
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($products) && is_array($products) ? $products : [];
/** @var list<int> $autoIds */
$autoIds = isset($autoUnavailable) && is_array($autoUnavailable) ? array_map('intval', $autoUnavailable) : [];
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$euros = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' EUR';

// Un lien qui repondrait 403 n'est pas rendu (meme principe que layout.php et
// ProductController::byCategory -> $canUpdateProduct). Corrige un point releve par le
// balayage transversal (rapport.md sweep) : Cuisine/Comptoir/Drive/Responsable voyaient
// "Nouveau produit"/"Modifier"/"Recette"/"Supprimer" bien qu'ils n'aient pas la
// permission, et tombaient sur un refus en cliquant. Calcule ICI (vue) plutot que dans le
// controleur : ce lot ne touche que des vues (perimetre du lot), $permissions est deja
// injecte dans toute vue admin (voir layout.php, meme mecanisme).
/** @var list<string> $perms */
$perms = isset($permissions) && is_array($permissions) ? $permissions : [];
$can = static fn (string $code): bool => in_array($code, $perms, true);
$canCreateProduct = $can('product.create');
$canUpdateProduct = $can('product.update');
$canManageIngredient = $can('ingredient.manage'); // Recette = liaisons produit-ingredient
$canDeleteProduct = $can('product.delete');
?>
<?php /* Repere visuel "ligne modifiee" : voir le commentaire equivalent, plus complet,
         dans ingredients/index.php (meme mecanisme, meme justification RGAA). Cablage
         cote JS partage : stock-thresholds.js (initRowHighlight). Ne se declenche
         aujourd'hui que depuis products/delete.php (proprietaire de ce lot) ; une
         creation/modification reussie (products/form.php, hors perimetre de ce lot, voir
         le rapport) ne pose pas encore la cle - a completer par qui reprendra ce fichier :
         il suffit d'ajouter data-row-key="product:<id>" sur le <form> de form.php. */ ?>
<style>
.row-highlight { animation: rowHighlightFade 3s ease-out forwards; }
@keyframes rowHighlightFade {
    0%   { box-shadow: inset 4px 0 0 var(--color-yellow-dark); }
    70%  { box-shadow: inset 4px 0 0 var(--color-yellow-dark); }
    100% { box-shadow: inset 4px 0 0 transparent; }
}
@media (prefers-reduced-motion: reduce) {
    .row-highlight { animation-duration: 0.8s; }
}
</style>
<div class="page-header">
    <div>
        <h1 class="page-title">Produits</h1>
        <p class="page-subtitle">Gestion des produits du catalogue</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/admin/products/by-category">Vue par catégorie</a>
        <?php if ($canCreateProduct): ?>
            <a class="btn btn-primary" href="/admin/products/new">Nouveau produit</a>
        <?php endif; ?>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Catégorie</th>
                    <th class="table-num">Prix</th>
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
                    <tr data-row-key="product:<?= $id ?>">
                        <td class="fw-600">
                            <?= $esc($row['name'] ?? '') ?>
                            <?php if ($isVariant): ?>
                                <span class="pill pill-neutral" title="Cette ligne est une variante de taille, pas un produit affiché seul sur la borne">Variante de <?= $esc($baseName !== '' ? $baseName : '?') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= $esc($row['category_name'] ?? '') ?></td>
                        <td class="table-num"><?= $esc($euros((int) ($row['price_cents'] ?? 0))) ?></td>
                        <td class="muted"><?= $vat === 55 ? '5,5%' : '10%' ?></td>
                        <td>
                            <?php if (!$available): ?>
                                <span class="pill pill-neutral">Indisponible</span>
                            <?php elseif ($autoRupture): ?>
                                <span class="pill pill-warning" title="Un ingrédient requis est en rupture critique">Rupture auto</span>
                            <?php else: ?>
                                <span class="pill pill-success">Disponible</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="row-actions">
                                <?php if ($canUpdateProduct): ?>
                                    <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/edit">Modifier</a>
                                <?php endif; ?>
                                <?php if ($canManageIngredient): ?>
                                    <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/recipe">Recette</a>
                                <?php endif; ?>
                                <?php if ($canDeleteProduct): ?>
                                    <span class="row-actions__danger">
                                        <a class="btn btn-secondary" href="/admin/products/<?= $id ?>/delete">Supprimer</a>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
