<?php

declare(strict_types=1);

/**
 * Formulaire ingredient (creation/edition), injecte dans admin/layout.php. Reaffiche
 * valeurs + erreurs (RG-T18). Ne porte QUE les champs de definition : le stock
 * (stock_quantity) se gere via reappro/inventaire, is_active via le bouton
 * activer/desactiver de la liste (RG-T16). CSRF cache.
 *
 * @var int                    $ingredientId
 * @var array<string, mixed>   $values
 * @var array<string, string>  $errors
 * @var string                 $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($ingredientId ?? 0);
$action = $id !== 0 ? '/admin/ingredients/' . $id : '/admin/ingredients';

/** @var array<string, mixed> $vals */
$vals = isset($values) && is_array($values) ? $values : [];
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];

$val = static fn (string $k): string => htmlspecialchars((string) ($vals[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static fn (string $k): string => isset($errs[$k]) && is_string($errs[$k]) ? $errs[$k] : '';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id !== 0 ? 'Modifier l ingredient' : 'Nouvel ingredient' ?></h1>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="name">Nom</label>
        <input class="form-input" type="text" id="name" name="name" maxlength="120" value="<?= $val('name') ?>" required>
        <?php if ($err('name') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('name'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="unit">Unite (ex. portion, sachet, piece)</label>
        <input class="form-input" type="text" id="unit" name="unit" maxlength="40" value="<?= $val('unit') ?>" required>
        <?php if ($err('unit') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('unit'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="stock_capacity">Capacite (reference 100%, en unites)</label>
        <input class="form-input" type="number" id="stock_capacity" name="stock_capacity" min="1" value="<?= $val('stock_capacity') ?>" required>
        <?php if ($err('stock_capacity') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('stock_capacity'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="pack_size">Taille d un pack de reappro (unites)</label>
        <input class="form-input" type="number" id="pack_size" name="pack_size" min="1" max="65535" value="<?= $val('pack_size') ?>" required>
        <?php if ($err('pack_size') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('pack_size'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="pack_label">Libelle du pack (optionnel)</label>
        <input class="form-input" type="text" id="pack_label" name="pack_label" maxlength="80" value="<?= $val('pack_label') ?>">
        <?php if ($err('pack_label') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('pack_label'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="low_stock_pct">Seuil d alerte (% de la capacite)</label>
        <input class="form-input" type="number" id="low_stock_pct" name="low_stock_pct" min="0" max="100" value="<?= $val('low_stock_pct') ?>" required>
        <?php if ($err('low_stock_pct') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('low_stock_pct'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="critical_stock_pct">Seuil critique (% de la capacite, &lt; alerte)</label>
        <input class="form-input" type="number" id="critical_stock_pct" name="critical_stock_pct" min="0" max="100" value="<?= $val('critical_stock_pct') ?>" required>
        <?php if ($err('critical_stock_pct') !== ''): ?><p class="form-error"><?= htmlspecialchars($err('critical_stock_pct'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <?php if ($id === 0): ?>
        <p><small>Le stock initial est a 0 : etablissez-le ensuite via un reapprovisionnement ou un inventaire (chaque mouvement est trace).</small></p>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Enregistrer</button>
        <a class="btn btn-secondary" href="/admin/ingredients">Annuler</a>
    </div>
</form>

<?php if ($id !== 0): ?>
    <?php $kcal = $val('energy_kcal_100g'); ?>
    <section class="card" aria-labelledby="nutrition-title">
        <h2 id="nutrition-title">Valeur nutritionnelle</h2>
        <?php if ($kcal !== ''): ?>
            <p>Apport energetique : <strong><?= $kcal ?> kcal / 100 g</strong>
               (source : <?= $val('nutrition_source') ?>, importe le <?= $val('nutrition_fetched_at') ?>)</p>
        <?php else: ?>
            <p>Aucune donnee nutritionnelle importee pour le moment.</p>
        <?php endif; ?>
        <!-- Import depuis une API externe (OpenFoodFacts), action explicite, POST + CSRF. -->
        <form method="post" action="/admin/ingredients/<?= $id ?>/enrich">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <button class="btn btn-secondary" type="submit">Importer la valeur nutritionnelle (OpenFoodFacts)</button>
        </form>
    </section>
<?php endif; ?>
