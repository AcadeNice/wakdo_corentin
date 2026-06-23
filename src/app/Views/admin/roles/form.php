<?php

declare(strict_types=1);

/**
 * Formulaire role (creation/edition RBAC), injecte dans admin/layout.php. La
 * matrice de permissions et les sources visibles sont des cases SCALAIRES
 * (`perm_<id>`, `source_<enum>`) : Request::formBody ne garde que les scalaires,
 * donc pas de `name[]` ni de JS. Toute soumission exige le PIN equipier (RG-T13).
 * Le `code` est editable a la creation, fige a l'edition (immuable).
 *
 * Presentation humanisee (option a) : les permissions sont regroupees par domaine
 * et libellees en francais ICI (la base reste la source des codes) ; canal et page
 * d'accueil sont des listes deroulantes. Les NOMS de champs postes sont inchanges.
 *
 * @var int                              $roleId
 * @var bool                             $isAdminRole
 * @var array<int, array<string, mixed>> $permissions    catalogue {id, code, label}
 * @var list<string>                     $sources        enum visibles
 * @var list<int>                        $selectedPerms
 * @var list<string>                     $selectedSources
 * @var array<string, mixed>             $values
 * @var array<string, string>            $errors
 * @var string                           $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($roleId ?? 0);
$action = $id !== 0 ? '/admin/roles/' . $id : '/admin/roles';
$isAdmin = (bool) ($isAdminRole ?? false);
/** @var array<string, mixed> $vals */
$vals = isset($values) && is_array($values) ? $values : [];
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];
/** @var array<int, array<string, mixed>> $perms */
$perms = isset($permissions) && is_array($permissions) ? $permissions : [];
/** @var list<int> $selPerms */
$selPerms = isset($selectedPerms) && is_array($selectedPerms) ? array_map('intval', $selectedPerms) : [];
/** @var list<string> $selSources */
$selSources = isset($selectedSources) && is_array($selectedSources) ? $selectedSources : [];
/** @var list<string> $srcList */
$srcList = isset($sources) && is_array($sources) ? $sources : [];

$val = static fn (string $k): string => htmlspecialchars((string) ($vals[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static fn (string $k): string => isset($errs[$k]) && is_string($errs[$k]) ? htmlspecialchars($errs[$k], ENT_QUOTES, 'UTF-8') : '';
$selectedSource = (string) ($vals['order_source'] ?? '');
$active = (bool) ($vals['is_active'] ?? true);

// --- Correspondances humaines (presentation seule) ---
// Canal de commande : enum technique -> libelle parlant.
$canalLabels = ['kiosk' => 'Borne', 'counter' => 'Comptoir', 'drive' => 'Drive'];
$canalLabel = static fn (string $enum): string => $canalLabels[$enum] ?? $enum;

// Pages proposees comme page d'accueil (liste deroulante) : chemin -> libelle.
$routeOptions = [
    '/admin/dashboard'   => 'Tableau de bord',
    '/admin/stats'       => 'Statistiques',
    '/admin/products'    => 'Produits',
    '/admin/menus'       => 'Menus',
    '/admin/ingredients' => 'Stock',
    '/admin/categories'  => 'Categories',
    '/admin/users'       => 'Comptes',
    '/admin/roles'       => 'Roles',
];
$currentRoute = (string) ($vals['default_route'] ?? '');
// Toujours pouvoir reselectionner la valeur courante meme si hors liste (ex. seed).
if ($currentRoute !== '' && !isset($routeOptions[$currentRoute])) {
    $routeOptions[$currentRoute] = $currentRoute;
}

// Permissions : code technique -> [groupe, action]. La base reste la source des codes.
$permMap = [
    'product.read'      => ['Produits', 'Voir'],
    'product.create'    => ['Produits', 'Creer'],
    'product.update'    => ['Produits', 'Modifier'],
    'product.delete'    => ['Produits', 'Supprimer'],
    'menu.read'         => ['Menus', 'Voir'],
    'menu.create'       => ['Menus', 'Creer'],
    'menu.update'       => ['Menus', 'Modifier'],
    'menu.delete'       => ['Menus', 'Supprimer'],
    'category.manage'   => ['Catalogue & recettes', 'Gerer les categories'],
    'ingredient.manage' => ['Catalogue & recettes', 'Gerer les ingredients et recettes'],
    'stock.read'        => ['Stock', 'Voir'],
    'stock.count'       => ['Stock', "Faire l'inventaire"],
    'stock.manage'      => ['Stock', 'Reapprovisionner'],
    'order.read'        => ['Commandes', 'Voir'],
    'order.create'      => ['Commandes', 'Creer'],
    'order.deliver'     => ['Commandes', 'Livrer'],
    'order.cancel'      => ['Commandes', 'Annuler'],
    'user.read'         => ['Comptes', 'Voir'],
    'user.create'       => ['Comptes', 'Creer'],
    'user.update'       => ['Comptes', 'Modifier'],
    'user.deactivate'   => ['Comptes', 'Desactiver'],
    'role.manage'       => ['Roles & statistiques', 'Gerer les roles'],
    'stats.read'        => ['Roles & statistiques', 'Voir les statistiques'],
];
$groupOrder = ['Produits', 'Menus', 'Catalogue & recettes', 'Stock', 'Commandes', 'Comptes', 'Roles & statistiques', 'Autres'];

// Regroupe le catalogue recu par domaine humain.
$grouped = [];
foreach ($perms as $p) {
    $code = (string) ($p['code'] ?? '');
    $map = $permMap[$code] ?? ['Autres', (string) ($p['label'] ?? $code)];
    $grouped[$map[0]][] = [
        'id'      => (int) ($p['id'] ?? 0),
        'action'  => $map[1],
        'checked' => in_array((int) ($p['id'] ?? 0), $selPerms, true),
    ];
}
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id !== 0 ? 'Modifier le role' : 'Nouveau role' ?></h1>
        <p class="page-subtitle">
            <?php if ($isAdmin): ?>
                Role administrateur : il doit garder le droit de gerer les roles et rester actif.
            <?php else: ?>
                Definissez ce que ce role peut faire dans le back-office.
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="label">Nom du role</label>
        <input class="form-input" type="text" id="label" name="label" maxlength="80" value="<?= $val('label') ?>" required>
        <?php if ($err('label') !== ''): ?><p class="form-error"><?= $err('label') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="code">Code interne</label>
        <?php if ($id === 0): ?>
            <input class="form-input" type="text" id="code" name="code" maxlength="40" value="<?= $val('code') ?>" required>
            <p class="form-helper">Identifiant technique (sans espace), non modifiable apres creation.</p>
            <?php if ($err('code') !== ''): ?><p class="form-error"><?= $err('code') ?></p><?php endif; ?>
        <?php else: ?>
            <input class="form-input" type="text" id="code" value="<?= $val('code') ?>" disabled>
            <p class="form-helper">Identifiant technique, non modifiable apres creation.</p>
        <?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="description">Description</label>
        <input class="form-input" type="text" id="description" name="description" value="<?= $val('description') ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="default_route">Page d'accueil apres connexion</label>
        <select class="form-input" id="default_route" name="default_route">
            <option value="">— Aucune —</option>
            <?php foreach ($routeOptions as $path => $pageLabel): ?>
                <option value="<?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>"<?= $path === $currentRoute ? ' selected' : '' ?>><?= htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <p class="form-helper">L'ecran affiche a cette personne quand elle se connecte.</p>
        <?php if ($err('default_route') !== ''): ?><p class="form-error"><?= $err('default_route') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="order_source">Canal de commande</label>
        <select class="form-input" id="order_source" name="order_source">
            <option value="">— Aucun (role de gestion) —</option>
            <?php foreach ($srcList as $src): ?>
                <option value="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>"<?= $src === $selectedSource ? ' selected' : '' ?>><?= htmlspecialchars($canalLabel($src), ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <p class="form-helper">Les commandes prises par ce role sont rattachees a ce canal.</p>
        <?php if ($err('order_source') !== ''): ?><p class="form-error"><?= $err('order_source') ?></p><?php endif; ?>
    </div>

    <?php if ($id !== 0): ?>
    <div class="form-group">
        <label class="form-label"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> Ce role est actif</label>
    </div>
    <?php endif; ?>

    <fieldset class="form-group">
        <legend>Droits d'acces</legend>
        <p class="form-helper">Cochez ce que ce role est autorise a faire.</p>
        <?php if ($err('permissions') !== ''): ?><p class="form-error"><?= $err('permissions') ?></p><?php endif; ?>
        <div class="perm-grid">
            <?php foreach ($groupOrder as $group): ?>
                <?php if (empty($grouped[$group])): continue; endif; ?>
                <div class="perm-group">
                    <h4 class="perm-group-title"><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></h4>
                    <?php foreach ($grouped[$group] as $item): ?>
                        <label class="perm-opt">
                            <input type="checkbox" name="perm_<?= $item['id'] ?>" value="1"<?= $item['checked'] ? ' checked' : '' ?>>
                            <?= htmlspecialchars($item['action'], ENT_QUOTES, 'UTF-8') ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="form-group">
        <legend>Canaux visibles sur le tableau de bord</legend>
        <?php foreach ($srcList as $src): ?>
            <label class="perm-opt">
                <input type="checkbox" name="source_<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" value="1"<?= in_array($src, $selSources, true) ? ' checked' : '' ?>>
                <?= htmlspecialchars($canalLabel($src), ENT_QUOTES, 'UTF-8') ?>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="form-group">
        <legend>Confirmation par PIN</legend>
        <div class="form-group">
            <label class="form-label" for="pin_email">Email de l'equipier</label>
            <input class="form-input" type="email" id="pin_email" name="pin_email" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="pin">PIN</label>
            <input class="form-input" type="password" id="pin" name="pin" inputmode="numeric" autocomplete="off">
        </div>
        <?php if ($err('pin') !== ''): ?><p class="form-error"><?= $err('pin') ?></p><?php endif; ?>
    </fieldset>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Enregistrer</button>
        <a class="btn btn-secondary" href="/admin/roles">Annuler</a>
    </div>
</form>
