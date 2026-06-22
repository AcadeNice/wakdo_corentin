<?php

declare(strict_types=1);

/**
 * Composeur de commande comptoir/drive (version PRODUITS, sous-lot 3a), injecte dans
 * admin/layout.php. Une quantite par produit commandable (champ qty_<id>) + un select
 * service_mode. Partage par les deux canaux ; la source/landing viennent du controleur.
 * Au canal drive, service_mode est verrouille a 'drive' (RG-T09). Echappement RG-T15.
 *
 * @var list<array<string, mixed>> $products
 * @var string                     $source       'counter' | 'drive'
 * @var string                     $serviceMode  valeur preselectionnee / reaffichee
 * @var string                     $landing      retour a la liste du canal
 * @var string|null                $error
 * @var string                     $csrfToken
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$euros = static fn (mixed $cents): string => number_format(((int) $cents) / 100, 2, ',', ' ') . ' EUR';

$csrf = $esc($csrfToken ?? '');
$chan = isset($source) && $source === 'drive' ? 'drive' : 'counter';
$action = $chan === 'drive' ? '/drive/orders' : '/counter/orders';
$backTo = isset($landing) && is_string($landing) ? $landing : '/counter/orders';
$mode = isset($serviceMode) && is_string($serviceMode) ? $serviceMode : ($chan === 'drive' ? 'drive' : 'dine_in');
$errorMessage = isset($error) && is_string($error) ? $error : null;

/** @var list<array<string, mixed>> $rows */
$rows = isset($products) && is_array($products) ? $products : [];

// RG-T09 : au drive, le seul mode possible est 'drive'. Le comptoir choisit librement.
$modeOptions = $chan === 'drive'
    ? ['drive' => 'Drive']
    : ['dine_in' => 'Sur place', 'takeaway' => 'A emporter'];
?>
<div class="page-header">
    <h1 class="page-title">Nouvelle commande <?= $chan === 'drive' ? 'drive' : 'comptoir' ?></h1>
</div>

<?php if ($errorMessage !== null): ?>
    <p class="form-error" role="alert"><?= $esc($errorMessage) ?></p>
<?php endif; ?>

<form method="post" action="<?= $esc($action) ?>" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="service_mode">Mode de service</label>
        <select class="form-input" id="service_mode" name="service_mode"<?= $chan === 'drive' ? ' readonly' : '' ?>>
            <?php foreach ($modeOptions as $value => $label): ?>
                <option value="<?= $esc($value) ?>"<?= $mode === $value ? ' selected' : '' ?>><?= $esc($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($rows === []): ?>
        <p class="admin-empty">Aucun produit commandable pour le moment.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Prix</th>
                    <th>Quantite</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $p): ?>
                    <?php $pid = (int) ($p['id'] ?? 0); ?>
                    <tr>
                        <td><?= $esc($p['name'] ?? '') ?></td>
                        <td><?= $esc($euros($p['price_cents'] ?? 0)) ?></td>
                        <td>
                            <input class="form-input" type="number" min="0" value="0"
                                   id="qty_<?= $pid ?>" name="qty_<?= $pid ?>"
                                   aria-label="Quantite <?= $esc($p['name'] ?? '') ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Encaisser la commande</button>
        <a class="btn btn-secondary" href="<?= $esc($backTo) ?>">Annuler</a>
    </div>
</form>
