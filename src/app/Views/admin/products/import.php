<?php

declare(strict_types=1);

/**
 * Import CSV de produits + recettes : depot du fichier (premier temps, aucune
 * ecriture). Le second temps (apercu + confirmation) est une page separee
 * (import_preview.php), rendue par ProductController::importPreview().
 *
 * @var list<string>          $columns  colonnes attendues, dans l'ordre exact
 * @var array<string, string> $errors
 * @var string                $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];
$fileError = isset($errs['fichier']) && is_string($errs['fichier']) ? $errs['fichier'] : '';
/** @var list<string> $cols */
$cols = isset($columns) && is_array($columns) ? $columns : [];

$columnHelp = [
    'categorie'  => 'Nom de la catégorie existante (ou son identifiant numérique).',
    'produit'    => 'Nom du produit.',
    'description' => 'Description du produit (facultatif).',
    'prix_ttc'   => 'Prix TTC en euros, avec virgule ou point (ex. 6,90).',
    'tva'        => 'Taux de TVA : 5,5 ou 10 (les deux seuls taux existants).',
    'taille_cl'  => 'Volume en centilitres pour une boisson (facultatif).',
    'disponible' => 'oui ou non (vide = oui).',
    'ingredient' => 'Nom de l\'ingrédient de cette ligne (vide = produit sans recette pour cette ligne).',
    'unite'      => 'Unité de l\'ingrédient (requise si l\'ingrédient n\'existe pas encore).',
    'quantite'   => 'Quantité de l\'ingrédient dans la recette (entier >= 1).',
    'retirable'  => 'oui ou non : le client peut-il retirer cet ingrédient ? (vide = non)',
    'ajoutable'  => 'oui ou non : le client peut-il ajouter cet ingrédient en supplément ? (vide = non)',
];
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Importer des produits</h1>
        <p class="page-subtitle">Un fichier CSV, plusieurs produits et leurs recettes en une fois</p>
    </div>
</div>

<div class="form-card">
    <p>Le fichier contient une ligne par ingrédient d'un produit (une ligne sans ingrédient est autorisée pour un produit sans recette). Les colonnes du produit sont répétées sur chaque ligne de ce produit.</p>
    <p><a class="btn btn-secondary" href="/admin/products/import/template">Télécharger le modèle CSV</a></p>

    <?php /* Repli du systeme de design (design-system.md 2.5) : le detail des
             colonnes est une reference qu'on ne consulte pas a chaque import. */ ?>
    <details class="form-advanced">
        <summary>Détail des colonnes attendues</summary>
        <table class="table">
            <thead><tr><th scope="col">Colonne</th><th scope="col">Contenu attendu</th></tr></thead>
            <tbody>
                <?php foreach ($cols as $col): ?>
                    <tr>
                        <th scope="row"><?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?></th>
                        <td><?= htmlspecialchars($columnHelp[$col] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><small>Encodage UTF-8 (avec ou sans BOM), séparateur point-virgule (Excel français) ou virgule, détecté automatiquement. Fichier limité à 2 Mo et 2000 lignes de données. Pour un produit déjà existant (même nom, même catégorie), la recette du fichier remplace entièrement sa recette actuelle.</small></p>
    </details>
</div>

<form method="post" enctype="multipart/form-data" action="/admin/products/import/preview" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="csv_file">Fichier CSV</label>
        <input class="form-input" type="file" id="csv_file" name="csv_file" accept=".csv,text/csv"
               <?= $fileError !== '' ? 'aria-describedby="csv-file-error"' : '' ?>>
        <?php if ($fileError !== ''): ?><p class="form-error" id="csv-file-error"><?= htmlspecialchars($fileError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Analyser le fichier (aperçu, aucune écriture)</button>
        <a class="btn btn-secondary" href="/admin/products">Annuler</a>
    </div>
</form>
