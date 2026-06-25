<?php

declare(strict_types=1);

/**
 * POS tactile a tuiles (comptoir / drive), injecte dans admin/layout.php. Refonte de
 * la saisie : a la place du formulaire-liste, un ecran de caisse facon borne client
 * (onglets categories en haut, grille de tuiles produits/menus a gauche, panneau
 * commande persistant a droite). Pensee pour la tablette : grandes cibles tactiles,
 * un tap sur une tuile ajoute le produit a la commande (qty 1), un produit a
 * modificateurs ou un menu ouvre la modale de composition.
 *
 * Le panier est construit cote client par counter-order.js (CSP 'self', vanilla JS,
 * zero handler inline) : il lit produits et menus depuis un script JSON inerte
 * (type="application/json"), construit les onglets, rend la grille, gere le panneau
 * commande, et serialise les items en JSON dans le champ cache #items_json a la
 * soumission. Le serveur revalide tout (RG-T18, resolveModifiers) et recalcule les
 * prix (RG-T16) : les prix affiches cote client (par ligne + total + libelle du
 * bouton) sont INDICATIFS, le serveur reste seul juge. Le contrat de soumission est
 * inchange (items_json + service_mode + service_tag + _csrf). Sans JS, la grille ne
 * s'affiche pas : un message invite a activer JS (le POS est interactif par nature).
 *
 * Partage par les deux canaux ; la source/landing viennent du controleur. Au canal
 * drive, service_mode est FIGE a 'drive' (affichage non editable + input cache,
 * RG-T09 : un select readonly reste editable, on ne s'y fie pas). Echappement RG-T15.
 *
 * @var list<array<string, mixed>> $products
 * @var list<array<string, mixed>> $menus        menus + slots (option_product_ids)
 * @var string                     $source       'counter' | 'drive'
 * @var string                     $serviceMode  valeur preselectionnee / reaffichee
 * @var string                     $serviceTag   numero de table reaffiche (re-rendu d'erreur)
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
$tag = isset($serviceTag) && is_string($serviceTag) ? $serviceTag : '';
$errorMessage = isset($error) && is_string($error) ? $error : null;

/** @var list<array<string, mixed>> $productRows */
$productRows = isset($products) && is_array($products) ? $products : [];
/** @var list<array<string, mixed>> $menuRows */
$menuRows = isset($menus) && is_array($menus) ? $menus : [];

// Projection compacte pour le JS : seules les cles utiles a la composition, l'affichage
// (tuiles : nom, prix, image, categorie) et le calcul local. Les prix sont passes pour
// l'affichage local (le serveur reste seul juge, RG-T16). modifiers : ingredients
// retirables / ajoutables proposables (cases a cocher cote client ; resolveModifiers
// revalide chacun cote serveur).
$jsModifiers = static fn (mixed $rows): array => array_map(
    static fn (array $r): array => [
        'ingredient_id'     => (int) ($r['ingredient_id'] ?? 0),
        'name'              => (string) ($r['name'] ?? ''),
        'is_removable'      => (int) ($r['is_removable'] ?? 0),
        'is_addable'        => (int) ($r['is_addable'] ?? 0),
        'extra_price_cents' => (int) ($r['extra_price_cents'] ?? 0),
    ],
    is_array($rows) ? $rows : [],
);

// Nom de categorie d'une ligne : category_name si fourni, sinon repli "Autres" pour ne
// pas creer d'onglet a libelle vide.
$catNameOf = static fn (array $r): string => isset($r['category_name'])
        && is_string($r['category_name']) && $r['category_name'] !== ''
    ? $r['category_name']
    : 'Autres';

$jsProducts = array_map(
    static fn (array $p): array => [
        'id'            => (int) ($p['id'] ?? 0),
        'name'          => (string) ($p['name'] ?? ''),
        'price'         => (int) ($p['price_cents'] ?? 0),
        'image'         => (string) ($p['image_path'] ?? ''),
        'category_id'   => (int) ($p['category_id'] ?? 0),
        'category_name' => $catNameOf($p),
        'modifiers'     => $jsModifiers($p['modifiers'] ?? null),
        // RG-T21 : false = rupture de stock calculee. La tuile reste visible (parite
        // borne) mais grisee et non tappable cote JS. Absent => commandable par defaut.
        'commandable'   => ($p['is_orderable'] ?? true) !== false,
    ],
    $productRows,
);
$jsMenus = array_map(
    static function (array $m) use ($jsModifiers, $catNameOf): array {
        /** @var list<array<string, mixed>> $slots */
        $slots = isset($m['slots']) && is_array($m['slots']) ? $m['slots'] : [];

        return [
            'id'               => (int) ($m['id'] ?? 0),
            'name'             => (string) ($m['name'] ?? ''),
            'price_normal'     => (int) ($m['price_normal_cents'] ?? 0),
            'price_maxi'       => (int) ($m['price_maxi_cents'] ?? 0),
            'image'            => (string) ($m['image_path'] ?? ''),
            'category_id'      => (int) ($m['category_id'] ?? 0),
            'category_name'    => $catNameOf($m),
            // RG-T21 (granularite burger impose seul) : false = burger en rupture
            // calculee. La tuile menu reste visible mais grisee et non tappable.
            'commandable'      => ($m['is_orderable'] ?? true) !== false,
            // Modificateurs du burger support : la selection d'un menu cible le burger
            // (resolveModifiers cote serveur le resout sur burger_product_id).
            'burger_modifiers' => $jsModifiers($m['burger_modifiers'] ?? null),
            'slots'            => array_map(
                static fn (array $s): array => [
                    'id'                 => (int) ($s['id'] ?? 0),
                    'name'               => (string) ($s['name'] ?? ''),
                    'slot_type'          => (string) ($s['slot_type'] ?? ''),
                    'is_required'        => (int) ($s['is_required'] ?? 0),
                    'display_order'      => (int) ($s['display_order'] ?? 0),
                    'option_product_ids' => array_map('intval', is_array($s['option_product_ids'] ?? null) ? $s['option_product_ids'] : []),
                ],
                $slots,
            ),
        ];
    },
    $menuRows,
);

// JSON inerte (type="application/json") plutot que data-* : la charge (compo de chaque
// produit + slots de chaque menu) peut etre volumineuse ; un script JSON reste CSP-safe
// (non execute) et plus lisible qu'un long attribut data-*. JSON_HEX_* echappe < > & '
// pour que la sortie soit sure a l'interieur d'un <script> (anti-XSS, RG-T15).
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div class="page-header">
    <h1 class="page-title">Nouvelle commande <?= $chan === 'drive' ? 'drive' : 'comptoir' ?></h1>
    <a class="btn btn-secondary" href="<?= $esc($backTo) ?>">Annuler</a>
</div>

<?php if ($errorMessage !== null): ?>
    <p class="form-error" role="alert"><?= $esc($errorMessage) ?></p>
<?php endif; ?>

<form method="post" action="<?= $esc($action) ?>" class="pos" id="counter-order-form">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="items_json" id="items_json" value="">

    <?php /* Donnees du catalogue pour counter-order.js : script JSON inerte (CSP-safe). */ ?>
    <script type="application/json" id="pos-products"><?= (string) json_encode($jsProducts, $jsonFlags) ?></script>
    <script type="application/json" id="pos-menus"><?= (string) json_encode($jsMenus, $jsonFlags) ?></script>

    <div class="pos__main">
        <div class="pos__catalogue">
            <?php /* Barre d'onglets categories (construite par le JS depuis le catalogue). */ ?>
            <div class="pos__tabs" id="pos-tabs" role="tablist" aria-label="Categories"></div>

            <?php if ($productRows === [] && $menuRows === []): ?>
                <p class="admin-empty">Aucun produit ni menu commandable pour le moment.</p>
            <?php else: ?>
                <?php /* Grille de tuiles (remplie par le JS) + repli sans JS. role=tabpanel
                         relie au tablist (aria-labelledby pose par le JS vers l'onglet
                         actif). Pas d'aria-live ici : la grille est rebatie a chaque
                         changement de categorie, une re-annonce complete serait verbeuse. */ ?>
                <div class="pos__grid" id="pos-grid" role="tabpanel" tabindex="0">
                    <p class="pos__nojs">Activez JavaScript pour saisir une commande sur cet ecran de caisse.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php /* Panneau commande persistant (recap a droite, facon caisse). */ ?>
        <aside class="pos__panel" aria-label="Commande en cours">
            <div class="pos__panel-head">
                <span class="pos__panel-title">Commande</span>
                <div class="pos__service">
                    <?php if ($chan === 'drive'): ?>
                        <?php /* RG-T09 : au drive, le mode est impose. On AFFICHE 'Drive' fige et on
                                 transmet la valeur par un champ cache (un select readonly resterait
                                 editable, donc non fiable ; disabled ne serait pas soumis). */ ?>
                        <p class="form-static" id="service_mode_display">Drive</p>
                        <input type="hidden" name="service_mode" id="service_mode" value="drive">
                    <?php else: ?>
                        <label class="pos__service-label" for="service_mode">Mode</label>
                        <select class="form-input" id="service_mode" name="service_mode">
                            <option value="dine_in"<?= $mode === 'dine_in' ? ' selected' : '' ?>>Sur place</option>
                            <option value="takeaway"<?= $mode === 'takeaway' ? ' selected' : '' ?>>A emporter</option>
                        </select>
                    <?php endif; ?>
                </div>
                <?php if ($chan !== 'drive'): ?>
                    <?php /* 7a : numero de table, utile uniquement en sur place. Masque par defaut
                             hors dine_in (toggle JS sur le mode) ; le champ reste soumis tel quel,
                             persist() l'ignore hors dine_in. */ ?>
                    <div class="pos__service" id="service_tag_group"<?= $mode === 'dine_in' ? '' : ' hidden' ?>>
                        <label class="pos__service-label" for="service_tag">Table</label>
                        <input class="form-input" type="text" id="service_tag" name="service_tag"
                               maxlength="20" value="<?= $esc($tag) ?>" autocomplete="off">
                    </div>
                <?php endif; ?>
            </div>

            <?php /* Pas d'aria-live sur la liste : elle est rebatie a chaque +/- (une
                     re-annonce de tout le panier serait verbeuse). Une region live dediee
                     (#pos-announce) annonce un message concis a chaque mutation. */ ?>
            <ul class="order-cart" id="order-cart">
                <li class="order-cart__empty" id="order-cart-empty">Panier vide.</li>
            </ul>

            <div class="pos__panel-foot">
                <?php /* Total indicatif du panier (recalcule cote serveur a l'encaissement). */ ?>
                <p class="order-total" id="order-total">Total <span id="order-total-value"><?= $esc($euros(0)) ?></span></p>
                <button class="btn btn-primary pos__pay" type="submit" id="order-submit">Encaisser <?= $esc($euros(0)) ?></button>
            </div>

            <?php /* Region live concise (C) : recoit "Total X EUR, N articles" a chaque
                     mutation du panier. Visuellement discrete (classe sr-only). */ ?>
            <span class="sr-only" id="pos-announce" role="status" aria-live="polite"></span>
        </aside>
    </div>
</form>

<!-- Conteneur de la modale de configuration de menu (rempli par counter-order.js). -->
<div id="menu-composer-modal" hidden></div>

<script src="/assets/js/counter-order.js"></script>
