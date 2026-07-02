<?php

declare(strict_types=1);

/**
 * Tableau de bord stock (READ_STOCK 9.3), injecte dans admin/layout.php. Oriente
 * usage quotidien : on met en avant ce qui est bas a reapprovisionner, le CRUD de
 * definition (config rare) est relegue. Le lien metier explique a quoi sert le stock :
 * un ingredient requis sous le seuil critique rend les produits qui l'utilisent
 * indisponibles sur la borne (RG-T21). Pourcentage/bande resolus cote depot ; les
 * liens d'action restent conditionnes aux permissions (garde reelle par-route). Texte echappe.
 *
 * @var array<int, array<string, mixed>> $ingredients
 * @var array<string, int>               $bandCounts
 * @var bool        $canManage
 * @var bool        $canRestock
 * @var bool        $canCount
 * @var string|null $thresholdError
 * @var string      $csrfToken
 */

/** @var array<int, array<string, mixed>> $rows */
$rows = isset($ingredients) && is_array($ingredients) ? $ingredients : [];
/** @var array<string, int> $counts */
$counts = isset($bandCounts) && is_array($bandCounts) ? $bandCounts : [];
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$manage = (bool) ($canManage ?? false);
$restock = (bool) ($canRestock ?? false);
$count = (bool) ($canCount ?? false);

$nCritical = (int) ($counts['critical'] ?? 0);
$nLow = (int) ($counts['low'] ?? 0);
$nNormal = (int) ($counts['normal'] ?? 0);
$thresholdErr = isset($thresholdError) && is_string($thresholdError) ? $thresholdError : null;

/**
 * Bouton "Regler les seuils" (F13). Ouvre la modale pre-remplie via des data-attributes
 * (l'id pour l'action POST, le nom pour le titre, les trois valeurs courantes) ; le JS
 * stock-thresholds.js intercepte le clic. Affiche seulement si le role peut calibrer le
 * stock ($restock = stock.manage). Valeurs echappees (attributs HTML).
 *
 * @param array<string, mixed> $row
 */
$renderThresholdButton = static function (array $row) use ($esc, $restock): string {
    if (!$restock) {
        return '';
    }
    $id = (int) ($row['id'] ?? 0);

    return '<button type="button" class="btn btn-ghost btn-sm" data-threshold-open'
        . ' data-id="' . $id . '"'
        . ' data-name="' . $esc($row['name'] ?? '') . '"'
        . ' data-capacity="' . (int) ($row['stock_capacity'] ?? 0) . '"'
        . ' data-low="' . (int) ($row['low_stock_pct'] ?? 0) . '"'
        . ' data-critical="' . (int) ($row['critical_stock_pct'] ?? 0) . '">Regler les seuils</button>';
};

// Les ingredients a reapprovisionner : critiques d'abord, puis en alerte. Le reste
// (au-dessus des seuils) va dans la liste calme "Tous les ingredients" plus bas.
$critical = [];
$low = [];
foreach ($rows as $row) {
    $band = (string) ($row['stock_band'] ?? 'normal');
    if ($band === 'critical') {
        $critical[] = $row;
    } elseif ($band === 'low') {
        $low[] = $row;
    }
}
$toRestock = array_merge($critical, $low);

$barClass = static fn (string $band): string => match ($band) {
    'critical' => 'stock-bar__fill stock-bar--critical',
    'low'      => 'stock-bar__fill stock-bar--low',
    default    => 'stock-bar__fill stock-bar--normal',
};

/**
 * Barre de niveau : conteneur + portion remplie (largeur = pct%, couleur = bande).
 * La largeur est bornee a 100 pour rester dans le conteneur meme si le depot renvoie
 * un pourcentage superieur. Style inline pour la largeur (deja la convention admin).
 *
 * @param array<string, mixed> $row
 */
$renderBar = static function (array $row) use ($esc, $barClass): string {
    $pct = (int) ($row['stock_pct'] ?? 0);
    $width = max(0, min(100, $pct));
    $band = (string) ($row['stock_band'] ?? 'normal');
    $qty = (int) ($row['stock_quantity'] ?? 0);
    $cap = (int) ($row['stock_capacity'] ?? 0);

    $state = match ($band) {
        'critical' => 'critique',
        'low'      => 'en alerte',
        default    => 'au-dessus du seuil',
    };
    $html = '<div class="stock-bar" role="img" aria-label="Niveau de stock ' . $pct . ' pourcent, etat ' . $state . '">';
    $html .= '<span class="' . $esc($barClass($band)) . '" style="width:' . $width . '%"></span>';
    $html .= '</div>';
    $html .= '<div class="stock-bar__meta"><span class="stock-bar__pct">' . $pct . '%</span>';
    $html .= '<span class="stock-bar__qty">' . $esc((string) $qty) . ' / ' . $esc((string) $cap) . '</span></div>';

    return $html;
};
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Stock des ingredients</h1>
        <p class="page-subtitle">Ce qui est bas a reapprovisionner, en un coup d oeil</p>
    </div>
    <?php if ($manage): ?>
        <div class="page-actions">
            <a class="btn btn-secondary" href="/admin/ingredients/new">Nouvel ingredient</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($thresholdErr !== null && $thresholdErr !== ''): ?>
    <div class="flash flash-error" role="alert"><?= $esc($thresholdErr) ?></div>
<?php endif; ?>

<p class="stock-explainer">
    Le stock pilote ce qui est commandable sur la borne. Un ingredient requis par une
    recette qui passe sous son seuil critique rend les produits qui l utilisent
    indisponibles a la commande. Tenez les niveaux a jour pour garder le menu ouvert.
</p>

<div class="stock-summary">
    <div class="stock-summary__item stock-summary__item--danger">
        <span class="stock-summary__count"><?= $nCritical ?></span>
        <span class="stock-summary__label">critiques</span>
    </div>
    <div class="stock-summary__item stock-summary__item--warning">
        <span class="stock-summary__count"><?= $nLow ?></span>
        <span class="stock-summary__label">en alerte</span>
    </div>
    <div class="stock-summary__item stock-summary__item--success">
        <span class="stock-summary__count"><?= $nNormal ?></span>
        <span class="stock-summary__label">au-dessus du seuil</span>
    </div>
</div>

<section class="stock-section stock-section--restock">
    <h2 class="stock-section__title">A reapprovisionner</h2>
    <?php if ($toRestock === []): ?>
        <div class="stock-empty stock-empty--ok">
            Tous les ingredients sont au-dessus de leurs seuils.
        </div>
    <?php else: ?>
        <div class="stock-cards">
            <?php foreach ($toRestock as $row): ?>
                <?php
                $id = (int) ($row['id'] ?? 0);
                $band = (string) ($row['stock_band'] ?? 'normal');
                $bandPill = $band === 'critical' ? 'pill pill-danger' : 'pill pill-warning';
                $bandText = $band === 'critical' ? 'Critique' : 'Alerte';
                ?>
                <div class="stock-card stock-card--<?= $esc($band) ?>">
                    <div class="stock-card__head">
                        <div>
                            <span class="stock-card__name"><?= $esc($row['name'] ?? '') ?></span>
                            <span class="stock-card__unit"><?= $esc($row['unit'] ?? '') ?></span>
                        </div>
                        <span class="<?= $bandPill ?>"><?= $bandText ?></span>
                    </div>
                    <?= $renderBar($row) ?>
                    <div class="stock-card__actions">
                        <?php if ($restock): ?>
                            <a class="btn btn-primary stock-card__action" href="/admin/ingredients/<?= $id ?>/restock">Reapprovisionner</a>
                        <?php endif; ?>
                        <?= $renderThresholdButton($row) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="stock-section">
    <h2 class="stock-section__title">Tous les ingredients</h2>
    <?php if ($rows === []): ?>
        <div class="stock-empty">Aucun ingredient.</div>
    <?php else: ?>
        <ul class="stock-list">
            <?php foreach ($rows as $row): ?>
                <?php
                $id = (int) ($row['id'] ?? 0);
                $active = (int) ($row['is_active'] ?? 0) === 1;
                ?>
                <li class="stock-list__row">
                    <div class="stock-list__main">
                        <span class="stock-list__name"><?= $esc($row['name'] ?? '') ?></span>
                        <span class="stock-list__unit"><?= $esc($row['unit'] ?? '') ?></span>
                        <?php if ($active): ?>
                            <span class="pill pill-success">Actif</span>
                        <?php else: ?>
                            <span class="pill pill-neutral">Inactif</span>
                        <?php endif; ?>
                    </div>
                    <div class="stock-list__bar"><?= $renderBar($row) ?></div>
                    <div class="stock-list__actions">
                        <?php if ($count): ?>
                            <a class="btn btn-secondary btn-sm" href="/admin/ingredients/<?= $id ?>/inventory">Inventaire</a>
                            <a class="btn btn-secondary btn-sm" href="/admin/ingredients/<?= $id ?>/adjust">Ajuster</a>
                        <?php endif; ?>
                        <?= $renderThresholdButton($row) ?>
                        <a class="btn btn-ghost btn-sm" href="/admin/ingredients/<?= $id ?>/movements">Mouvements</a>
                        <?php if ($manage): ?>
                            <span class="stock-list__crud">
                                <a class="btn btn-ghost btn-sm" href="/admin/ingredients/<?= $id ?>/edit">Modifier</a>
                                <form method="post" action="/admin/ingredients/<?= $id ?>/toggle" class="stock-list__inline-form">
                                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= $active ? 'Desactiver' : 'Reactiver' ?></button>
                                </form>
                                <a class="btn btn-ghost btn-sm" href="/admin/ingredients/<?= $id ?>/delete">Supprimer</a>
                            </span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($restock): ?>
    <?php /*
        Modale de reglage rapide des seuils (F13), rendue serveur (VRAI form POST + CSRF,
        comme restock/inventory ; pas de fetch). Une seule modale pour la page : le bouton
        clique (data-threshold-open) y injecte l'action /admin/ingredients/{id}/thresholds
        et pre-remplit les trois champs depuis ses data-attributes (stock-thresholds.js).
        Reutilise les classes .pin-modal-* (overlay generique). Cachee par defaut (pas de
        classe .open) : sans JS, elle reste invisible et les actions classiques fonctionnent.
    */ ?>
    <div class="pin-modal-overlay" data-threshold-modal role="dialog" aria-modal="true" aria-label="Reglage des seuils de stock">
        <div class="pin-modal">
            <div class="pin-modal-head">
                <div>
                    <h2 class="pin-modal-title">Regler les seuils</h2>
                    <p class="pin-modal-sub" data-threshold-name>Capacite de reference et seuils d alerte de l ingredient.</p>
                </div>
            </div>
            <form method="post" action="" data-threshold-form>
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <div class="form-group">
                    <label class="form-label" for="th-capacity">Capacite (quantite consideree comme 100%)</label>
                    <input class="form-input" type="number" id="th-capacity" name="stock_capacity" min="1" step="1" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="th-low">Seuil d alerte (% du plein)</label>
                    <input class="form-input" type="number" id="th-low" name="low_stock_pct" min="0" max="100" step="1" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="th-critical">Seuil critique (% du plein)</label>
                    <input class="form-input" type="number" id="th-critical" name="critical_stock_pct" min="0" max="100" step="1" required>
                    <p class="form-hint">Le seuil critique doit etre inferieur au seuil d alerte. Sous le critique, les produits qui utilisent cet ingredient passent indisponibles sur la borne.</p>
                </div>
                <p class="form-error" data-threshold-error hidden></p>
                <div class="pin-modal-actions">
                    <button class="btn btn-secondary" type="button" data-threshold-cancel>Annuler</button>
                    <button class="btn btn-primary" type="submit">Enregistrer les seuils</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
