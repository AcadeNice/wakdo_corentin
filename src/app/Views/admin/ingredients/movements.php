<?php

declare(strict_types=1);

/**
 * Historique des mouvements de stock d'un ingredient (READ_STOCK 9.3 RG-3), injecte
 * dans admin/layout.php. RG-4 : la colonne "acteur" n'est rendue que pour
 * manager/admin ($showActor) ; le personnel de ligne voit les deltas sans l'auteur.
 * Texte echappe.
 *
 * @var array<string, mixed>              $ingredient
 * @var array<int, array<string, mixed>>  $movements
 * @var bool                              $showActor
 * @var array<int, string>                $actorNames
 */

/** @var array<string, mixed> $ing */
$ing = isset($ingredient) && is_array($ingredient) ? $ingredient : [];
/** @var array<int, array<string, mixed>> $rows */
$rows = isset($movements) && is_array($movements) ? $movements : [];
/** @var array<int, string> $names */
$names = isset($actorNames) && is_array($actorNames) ? $actorNames : [];
$withActor = (bool) ($showActor ?? false);

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$typeText = static fn (string $t): string => match ($t) {
    'restock'              => 'Reappro',
    'inventory_correction' => 'Inventaire',
    'sale'                 => 'Vente',
    'cancellation'         => 'Annulation',
    default                => $t,
};
$colspan = $withActor ? 5 : 4;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Mouvements - <?= $esc($ing['name'] ?? '') ?></h1>
        <p class="page-subtitle">Stock actuel <?= $esc((string) ((int) ($ing['stock_quantity'] ?? 0))) ?> <?= $esc($ing['unit'] ?? '') ?> (<?= (int) ($ing['stock_pct'] ?? 0) ?>%)</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/admin/ingredients">Retour</a>
    </div>
</div>

<div class="table-container">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Delta</th>
                    <th>Note</th>
                    <?php if ($withActor): ?><th>Acteur</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= $colspan ?>" class="muted">Aucun mouvement.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $delta = (int) ($row['delta'] ?? 0);
                    $uid = $row['user_id'] !== null ? (int) $row['user_id'] : 0;
                    ?>
                    <tr>
                        <td class="muted"><?= $esc($row['created_at'] ?? '') ?></td>
                        <td><?= $esc($typeText((string) ($row['movement_type'] ?? ''))) ?></td>
                        <td><?= $delta > 0 ? '+' . $delta : (string) $delta ?></td>
                        <td class="muted"><?= $esc($row['note'] ?? '') ?></td>
                        <?php if ($withActor): ?>
                            <td class="muted"><?= $uid > 0 ? $esc($names[$uid] ?? ('#' . $uid)) : '-' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
