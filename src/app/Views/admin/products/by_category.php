<?php

declare(strict_types=1);

/**
 * Catalogue range par categorie (F20), injecte dans admin/layout.php. Vue de LECTURE :
 * aucun formulaire, aucun JavaScript, aucun attribut style en ligne.
 *
 * Les articles arrivent DEJA normalises et leur etat de disponibilite deja resolu par
 * ProductController::byCategory : la vue ne fait que du rendu. Volontairement sans
 * vignette produit : afficher une image demanderait un repli sans handler en ligne,
 * donc un fichier de script, ce qu'une page de lecture ne justifie pas.
 *
 * @var array<int, array<string, mixed>>       $categories     ossature ordonnee (display_order)
 * @var array<int, list<array<string, mixed>>> $articles       category_id => articles
 * @var int                                    $totalArticles
 * @var int                                    $nOrderable
 * @var int                                    $nNotOrderable
 */

/** @var array<int, array<string, mixed>> $cats */
$cats = isset($categories) && is_array($categories) ? $categories : [];
/** @var array<int, list<array<string, mixed>>> $byCat */
$byCat = isset($articles) && is_array($articles) ? $articles : [];
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$euros = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' EUR';
// Les noms de categorie sont stockes en minuscules : on capitalise a l'affichage,
// comme la borne le fait sur ses cartes.
$cap = static fn (string $s): string => mb_convert_case(mb_substr($s, 0, 1), MB_CASE_UPPER, 'UTF-8') . mb_substr($s, 1);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Produits par categorie</h1>
        <p class="page-subtitle">Le catalogue tel que la borne le presente, onglet par onglet</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/admin/products">Vue liste</a>
    </div>
</div>

<p class="catalogue-explainer">
    Les categories sont rangees dans l ordre des onglets de la borne. Les tailles d une
    meme boisson sont regroupees sur le produit de base, comme sur la borne : le client
    choisit la taille apres avoir choisi le produit. Pour modifier une taille ligne par
    ligne, passez par la vue liste.
</p>

<div class="catalogue-summary">
    <div class="catalogue-summary__item">
        <span class="catalogue-summary__count"><?= (int) ($totalArticles ?? 0) ?></span>
        <span class="catalogue-summary__label">articles au catalogue</span>
    </div>
    <div class="catalogue-summary__item catalogue-summary__item--success">
        <span class="catalogue-summary__count"><?= (int) ($nOrderable ?? 0) ?></span>
        <span class="catalogue-summary__label">commandables</span>
    </div>
    <div class="catalogue-summary__item catalogue-summary__item--warning">
        <span class="catalogue-summary__count"><?= (int) ($nNotOrderable ?? 0) ?></span>
        <span class="catalogue-summary__label">non commandables</span>
    </div>
</div>

<?php if ($cats === []): ?>
    <div class="admin-empty">Aucune categorie. Creez-en une pour ranger le catalogue.</div>
<?php endif; ?>

<?php foreach ($cats as $category): ?>
    <?php
    $catId = (int) ($category['id'] ?? 0);
    $catActive = (int) ($category['is_active'] ?? 0) === 1;
    $rows = $byCat[$catId] ?? [];
    $headingId = 'catalogue-cat-' . $catId;
    ?>
    <section class="catalogue-group<?= $catActive ? '' : ' catalogue-group--hidden' ?>" aria-labelledby="<?= $headingId ?>">
        <div class="catalogue-group__head">
            <h2 class="catalogue-group__title" id="<?= $headingId ?>"><?= $esc($cap((string) ($category['name'] ?? ''))) ?></h2>
            <span class="catalogue-group__count"><?= count($rows) ?> article<?= count($rows) === 1 ? '' : 's' ?></span>
            <?php if (!$catActive): ?>
                <span class="pill pill-neutral">Masquee sur la borne</span>
            <?php endif; ?>
        </div>

        <?php if (!$catActive): ?>
            <p class="catalogue-group__note">
                Cette categorie est desactivee : ses articles n apparaissent pas sur la
                borne, meme ceux marques disponibles. Reactivez-la depuis la page
                Categories pour les rendre commandables.
            </p>
        <?php endif; ?>

        <?php if ($rows === []): ?>
            <div class="catalogue-empty">Aucun article dans cette categorie.</div>
        <?php else: ?>
            <div class="catalogue-grid">
                <?php foreach ($rows as $row): ?>
                    <?php
                    $state = (string) ($row['state'] ?? 'available');
                    $variants = (int) ($row['variant_count'] ?? 0);
                    $vat = $row['vat_rate'] ?? null;
                    $editUrl = $row['edit_url'] ?? null;
                    ?>
                    <article class="catalogue-card">
                        <div class="catalogue-card__name">
                            <?= $esc($row['name'] ?? '') ?>
                            <?php if (($row['kind'] ?? '') === 'menu'): ?>
                                <span class="pill pill-neutral">Menu</span>
                            <?php endif; ?>
                        </div>
                        <div class="catalogue-card__price"><?= $esc($euros((int) ($row['price_cents'] ?? 0))) ?></div>
                        <div class="catalogue-card__meta">
                            <?php if ($state === 'unavailable'): ?>
                                <span class="pill pill-neutral">Indisponible</span>
                            <?php elseif ($state === 'auto_rupture'): ?>
                                <span class="pill pill-warning" title="Un ingredient requis est en rupture critique (RG-T21)">Rupture auto</span>
                            <?php else: ?>
                                <span class="pill pill-success">Disponible</span>
                            <?php endif; ?>
                            <?php if ($vat !== null): ?>
                                <span class="muted">TVA <?= (int) $vat === 55 ? '5,5%' : '10%' ?></span>
                            <?php endif; ?>
                            <?php if ($variants > 0): ?>
                                <span class="pill pill-neutral" title="Tailles regroupees sur ce produit de base"><?= $variants ?> taille<?= $variants === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($editUrl !== null): ?>
                            <div class="catalogue-card__actions">
                                <a class="btn btn-secondary btn-sm" href="<?= $esc($editUrl) ?>">Modifier</a>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
