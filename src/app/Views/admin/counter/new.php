<?php

declare(strict_types=1);

/**
 * Composeur de commande comptoir/drive COMPLET (sous-lot 3c + refonte saisie),
 * injecte dans admin/layout.php. Produits commandables ET menus composes (slots
 * accompagnement/boisson/sauce + format Normal/Maxi + modificateurs d'ingredients).
 *
 * Le panier est construit cote client par counter-order.js (CSP 'self', vanilla JS,
 * zero handler inline) : il lit produits et menus depuis les data-* de
 * #counter-order-form (dont la composition PROPOSABLE de chaque produit et du burger
 * de chaque menu : ingredients retirables / ajoutables + surcout), et serialise les
 * items en JSON dans le champ cache #items_json a la soumission. Le serveur revalide
 * tout (RG-T18, resolveModifiers) et recalcule les prix (RG-T16). Les prix affiches
 * cote client (par ligne + total + libelle du bouton) sont INDICATIFS : le serveur
 * reste seul juge. Le tableau de quantites produit `qty_<id>` reste present comme
 * repli sans JS (3a) : le champ quantite est rendu EDITABLE pour TOUS les produits.
 * Pour un produit personnalisable, c'est counter-order.js qui neutralise le champ au
 * cablage (desactivation + indice "via Personnaliser") et route la saisie vers la
 * modale ; sans JS, le champ qty de base fonctionne (commande sans modificateurs, ce
 * que legacyQuantities sait traiter). La gestion des modificateurs depend donc de JS.
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

// Donnees pour counter-order.js, passees en attributs data-* (CSP 'self' : pas de
// script inline). htmlspecialchars rend le JSON sur-able comme valeur d'attribut.
$attr = static fn (mixed $data): string => htmlspecialchars(
    (string) json_encode($data, JSON_UNESCAPED_UNICODE),
    ENT_QUOTES,
    'UTF-8',
);

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

// Projection compacte pour le JS : seules les cles utiles a la composition. Les
// prix sont passes pour l'affichage local (le serveur reste seul juge, RG-T16).
// modifiers : ingredients retirables / ajoutables proposables (le client les affiche
// en cases a cocher ; resolveModifiers revalide chacun cote serveur).
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
$jsProducts = array_map(
    static fn (array $p): array => [
        'id'        => (int) ($p['id'] ?? 0),
        'name'      => (string) ($p['name'] ?? ''),
        'price'     => (int) ($p['price_cents'] ?? 0),
        'modifiers' => $jsModifiers($p['modifiers'] ?? null),
    ],
    $productRows,
);
$jsMenus = array_map(
    static function (array $m) use ($jsModifiers): array {
        /** @var list<array<string, mixed>> $slots */
        $slots = isset($m['slots']) && is_array($m['slots']) ? $m['slots'] : [];

        return [
            'id'               => (int) ($m['id'] ?? 0),
            'name'             => (string) ($m['name'] ?? ''),
            'price_normal'     => (int) ($m['price_normal_cents'] ?? 0),
            'price_maxi'       => (int) ($m['price_maxi_cents'] ?? 0),
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

// Regroupement des produits par categorie (7b) : sous-titre par categorie pour que
// l'equipier scanne plus vite. availableForCatalogue trie deja par categorie puis
// display_order ; on conserve cet ordre et on agrege les lignes consecutives de meme
// categorie. category_name absent -> groupe "Autres" (evite une cle vide a l'ecran).
$productGroups = [];
foreach ($productRows as $p) {
    $catName = isset($p['category_name']) && is_string($p['category_name']) && $p['category_name'] !== ''
        ? $p['category_name']
        : 'Autres';
    if (!isset($productGroups[$catName])) {
        $productGroups[$catName] = [];
    }
    $productGroups[$catName][] = $p;
}
?>
<div class="page-header">
    <h1 class="page-title">Nouvelle commande <?= $chan === 'drive' ? 'drive' : 'comptoir' ?></h1>
</div>

<?php if ($errorMessage !== null): ?>
    <p class="form-error" role="alert"><?= $esc($errorMessage) ?></p>
<?php endif; ?>

<form method="post" action="<?= $esc($action) ?>" class="form-card" id="counter-order-form"
      data-products="<?= $attr($jsProducts) ?>"
      data-menus="<?= $attr($jsMenus) ?>">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="items_json" id="items_json" value="">

    <div class="form-group">
        <label class="form-label" for="service_mode">Mode de service</label>
        <?php if ($chan === 'drive'): ?>
            <?php /* RG-T09 : au drive, le mode est impose. On AFFICHE 'Drive' fige et on
                     transmet la valeur par un champ cache (un select readonly resterait
                     editable, donc non fiable ; disabled ne serait pas soumis). */ ?>
            <p class="form-static" id="service_mode_display">Drive</p>
            <input type="hidden" name="service_mode" id="service_mode" value="drive">
        <?php else: ?>
            <select class="form-input" id="service_mode" name="service_mode">
                <option value="dine_in"<?= $mode === 'dine_in' ? ' selected' : '' ?>>Sur place</option>
                <option value="takeaway"<?= $mode === 'takeaway' ? ' selected' : '' ?>>A emporter</option>
            </select>
        <?php endif; ?>
    </div>

    <?php if ($chan !== 'drive'): ?>
        <?php /* 7a : numero de table, utile uniquement en sur place. Masque par defaut
                 (toggle JS sur le mode) ; le champ reste soumis tel quel, persist()
                 l'ignore hors dine_in. */ ?>
        <div class="form-group" id="service_tag_group"<?= $mode === 'dine_in' ? '' : ' hidden' ?>>
            <label class="form-label" for="service_tag">Numero de table</label>
            <input class="form-input" type="text" id="service_tag" name="service_tag"
                   maxlength="20" value="<?= $esc($tag) ?>" autocomplete="off">
        </div>
    <?php endif; ?>

    <fieldset class="form-group">
        <legend>Produits</legend>
        <?php if ($productRows === []): ?>
            <p class="admin-empty">Aucun produit commandable pour le moment.</p>
        <?php else: ?>
            <?php foreach ($productGroups as $catName => $catProducts): ?>
                <h3 class="order-group__title"><?= $esc($catName) ?></h3>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Produit</th>
                            <th>Prix</th>
                            <th>Quantite</th>
                            <th>Personnaliser</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catProducts as $p): ?>
                            <?php
                            $pid = (int) ($p['id'] ?? 0);
                            // Un produit ne porte un bouton "Personnaliser" que si sa recette
                            // offre au moins un ingredient retirable/ajoutable (data-* modifiers).
                            $hasModifiers = isset($p['modifiers']) && is_array($p['modifiers']) && $p['modifiers'] !== [];
                            ?>
                            <tr>
                                <td><?= $esc($p['name'] ?? '') ?></td>
                                <td><?= $esc($euros($p['price_cents'] ?? 0)) ?></td>
                                <td>
                                    <?php /* 4 (progressive enhancement) : le champ quantite est
                                             rendu EDITABLE pour TOUS les produits, pour que la saisie
                                             marche SANS JS (qty de base, sans modificateurs). C'est
                                             counter-order.js qui neutralise ce champ au cablage pour un
                                             produit personnalisable et route la saisie vers la modale.
                                             L'indice "via Personnaliser" est cache par defaut et revele
                                             par le JS, pour ne pas perturber le rendu sans JS. */ ?>
                                    <input class="form-input order-qty" type="number" min="0" value="0"
                                           id="qty_<?= $pid ?>" name="qty_<?= $pid ?>"
                                           data-product-id="<?= $pid ?>"
                                           aria-label="Quantite <?= $esc($p['name'] ?? '') ?>">
                                    <?php if ($hasModifiers): ?>
                                        <span class="order-qty-hint" data-qty-hint="<?= $pid ?>" hidden>via Personnaliser</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($hasModifiers): ?>
                                        <button class="btn btn-secondary product-configure" type="button" data-product-id="<?= $pid ?>">
                                            Personnaliser
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    </fieldset>

    <fieldset class="form-group">
        <legend>Menus</legend>
        <?php if ($menuRows === []): ?>
            <p class="admin-empty">Aucun menu commandable pour le moment.</p>
        <?php else: ?>
            <ul class="menu-list" id="menu-list">
                <?php foreach ($menuRows as $m): ?>
                    <?php
                    $mid = (int) ($m['id'] ?? 0);
                    $priceNormal = (int) ($m['price_normal_cents'] ?? 0);
                    $priceMaxi = (int) ($m['price_maxi_cents'] ?? 0);
                    ?>
                    <li class="menu-list__item">
                        <span class="menu-list__name"><?= $esc($m['name'] ?? '') ?></span>
                        <?php /* 6 : afficher les deux prix qualifies (Normal / Maxi) pour que
                                 le choix de format soit lisible avant d'ouvrir la modale. */ ?>
                        <span class="menu-list__price">
                            Normal <?= $esc($euros($priceNormal)) ?> / Maxi <?= $esc($euros($priceMaxi)) ?>
                        </span>
                        <button class="btn btn-secondary menu-configure" type="button" data-menu-id="<?= $mid ?>">
                            Configurer
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </fieldset>

    <fieldset class="form-group">
        <legend>Panier</legend>
        <ul class="order-cart" id="order-cart" aria-live="polite">
            <li class="order-cart__empty" id="order-cart-empty">Panier vide.</li>
        </ul>
        <?php /* 1 : total indicatif du panier (recalcule cote serveur a l'encaissement). */ ?>
        <p class="order-total" id="order-total">Total : <span id="order-total-value"><?= $esc($euros(0)) ?></span></p>
    </fieldset>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit" id="order-submit">Encaisser <?= $esc($euros(0)) ?></button>
        <a class="btn btn-secondary" href="<?= $esc($backTo) ?>">Annuler</a>
    </div>
</form>

<!-- Conteneur de la modale de configuration de menu (rempli par counter-order.js). -->
<div id="menu-composer-modal" hidden></div>

<script src="/assets/js/counter-order.js"></script>
