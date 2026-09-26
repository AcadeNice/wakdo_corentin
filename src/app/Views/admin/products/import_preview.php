<?php

declare(strict_types=1);

/**
 * Apercu de l'import CSV (second ecran) : rapport de ProductImportService::preview(),
 * AUCUNE ecriture n'a encore eu lieu. Tout texte issu du fichier (noms de
 * produit/ingredient/categorie, messages d'erreur qui les citent) passe par
 * htmlspecialchars -- aucun echo brut du contenu importe.
 *
 * @var array<string, mixed>  $report
 * @var string                $importToken
 * @var array<string, string> $errors  erreurs de la CONFIRMATION (PIN), distinctes de $report['errors']
 * @var string                $csrfToken
 */

$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $h($csrfToken ?? '');
$token = $h($importToken ?? '');

/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];
$pinError = isset($errs['pin']) && is_string($errs['pin']) ? $errs['pin'] : '';

/** @var list<array{line:int,column:string,message:string}> $fileErrors */
$fileErrors = isset($report['errors']) && is_array($report['errors']) ? $report['errors'] : [];
$canConfirm = $fileErrors === [];

$euros = static fn (?int $cents): string => $cents === null ? '—' : number_format($cents / 100, 2, ',', ' ') . ' €';
$vatLabel = static fn (?int $rate): string => $rate === 55 ? '5,5 %' : ($rate === 100 ? '10 %' : '—');

/**
 * @param list<array<string, mixed>> $rows
 */
$productRow = static function (array $p) use ($h, $euros, $vatLabel): string {
    $warn = ($p['action'] ?? '') !== 'create' && (int) ($p['recipe_line_count'] ?? 0) === 0;
    $priceFlag = !empty($p['price_changed']);

    return '<tr>'
        . '<td>' . $h($p['line'] ?? '') . '</td>'
        . '<td>' . $h($p['name'] ?? '') . '</td>'
        . '<td>' . $h($p['category_name'] ?? '') . '</td>'
        . '<td>' . $euros($p['price_cents'] ?? null) . ($priceFlag ? ' <strong>(changement de prix)</strong>' : '') . '</td>'
        . '<td>' . $vatLabel($p['vat_rate'] ?? null) . '</td>'
        . '<td>' . $h((int) ($p['recipe_line_count'] ?? 0)) . ($warn ? ' <strong>(recette videe : aucune ligne ingrédient dans le fichier)</strong>' : '') . '</td>'
        . '</tr>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Aperçu de l'import</h1>
        <p class="page-subtitle"><?= $h((int) ($report['totalDataLines'] ?? 0)) ?> ligne(s) de données analysée(s) — aucune écriture n'a encore eu lieu</p>
    </div>
</div>

<?php if ($fileErrors !== []): ?>
    <div class="form-card" role="alert">
        <h2>Le fichier contient <?= count($fileErrors) ?> erreur(s) : l'import est bloqué</h2>
        <p>Corrigez le fichier puis renvoyez-le. Aucune confirmation n'est possible tant qu'une erreur subsiste.</p>
        <table class="table">
            <thead><tr><th scope="col">Ligne</th><th scope="col">Colonne</th><th scope="col">Erreur</th></tr></thead>
            <tbody>
                <?php foreach ($fileErrors as $e): ?>
                    <tr>
                        <td><?= $h($e['line'] ?? '') ?></td>
                        <td><?= $h($e['column'] ?? '') ?></td>
                        <td><?= $h($e['message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="form-card">
    <h2>Résumé</h2>
    <ul>
        <li><?= count($report['productsToCreate'] ?? []) ?> produit(s) à créer</li>
        <li><?= count($report['productsToUpdate'] ?? []) ?> produit(s) à mettre à jour</li>
        <li><?= count($report['productsUnchanged'] ?? []) ?> produit(s) inchangé(s)</li>
        <li><?= count($report['ingredientsExisting'] ?? []) ?> ingrédient(s) déjà existant(s)</li>
        <li><?= count($report['ingredientsToCreate'] ?? []) ?> ingrédient(s) à créer (stock à 0)</li>
    </ul>
</div>

<?php foreach ([
    ['productsToCreate', 'Produits à créer'],
    ['productsToUpdate', 'Produits à mettre à jour'],
    ['productsUnchanged', 'Produits inchangés'],
] as [$key, $title]): ?>
    <?php $rows = isset($report[$key]) && is_array($report[$key]) ? $report[$key] : []; ?>
    <?php if ($rows !== []): ?>
        <div class="form-card">
            <h2><?= $h($title) ?> (<?= count($rows) ?>)</h2>
            <table class="table">
                <thead><tr><th scope="col">Ligne</th><th scope="col">Produit</th><th scope="col">Catégorie</th><th scope="col">Prix</th><th scope="col">TVA</th><th scope="col">Lignes de recette</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $p): ?>
                        <?= $productRow($p) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php $newIngredients = isset($report['ingredientsToCreate']) && is_array($report['ingredientsToCreate']) ? $report['ingredientsToCreate'] : []; ?>
<?php if ($newIngredients !== []): ?>
    <div class="form-card">
        <h2>Ingrédients à créer (<?= count($newIngredients) ?>)</h2>
        <p><small>Créés à stock 0 : à réapprovisionner après import. Leur liste d'allergènes n'est pas encore revue.</small></p>
        <table class="table">
            <thead><tr><th scope="col">Ligne</th><th scope="col">Nom</th><th scope="col">Unité</th></tr></thead>
            <tbody>
                <?php foreach ($newIngredients as $ing): ?>
                    <tr><td><?= $h($ing['first_line'] ?? '') ?></td><td><?= $h($ing['name'] ?? '') ?></td><td><?= $h($ing['unit'] ?? '') ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="form-card">
    <h2>Confirmation</h2>
    <?php if (!$canConfirm): ?>
        <p>Corrigez les erreurs ci-dessus et renvoyez le fichier.</p>
        <p><a class="btn btn-secondary" href="/admin/products/import">Renvoyer un fichier</a></p>
    <?php else: ?>
        <form method="post" action="/admin/products/import/confirm">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="import_token" value="<?= $token ?>">

            <?php if (!empty($report['hasPriceChange'])): ?>
                <fieldset class="form-group">
                    <legend>Ce fichier modifie au moins un prix : confirmation par PIN</legend>
                    <div class="form-group">
                        <label class="form-label" for="pin_email">Votre email</label>
                        <input class="form-input" type="email" id="pin_email" name="pin_email" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pin">Votre PIN</label>
                        <input class="form-input" type="password" id="pin" name="pin" inputmode="numeric" autocomplete="off"
                               <?= $pinError !== '' ? 'aria-describedby="pin-error"' : '' ?>>
                    </div>
                    <?php if ($pinError !== ''): ?><p class="form-error" id="pin-error"><?= $h($pinError) ?></p><?php endif; ?>
                </fieldset>
            <?php endif; ?>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Confirmer l'import</button>
                <a class="btn btn-secondary" href="/admin/products/import">Annuler</a>
            </div>
        </form>
    <?php endif; ?>
</div>
