<?php

declare(strict_types=1);

/**
 * Formulaire role (creation/edition RBAC), injecte dans admin/layout.php. La
 * matrice de permissions et les sources visibles sont des cases SCALAIRES
 * (`perm_<id>`, `source_<enum>`) : Request::formBody ne garde que les scalaires,
 * donc pas de `name[]` ni de JS. Toute soumission exige le PIN equipier (RG-T13).
 * Le `code` est editable a la creation, fige a l'edition (immuable).
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
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id !== 0 ? 'Modifier le role' : 'Nouveau role' ?></h1>
        <?php if ($isAdmin): ?><p class="page-subtitle">Role administrateur : il doit conserver <code>role.manage</code> et rester actif.</p><?php endif; ?>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="code">Code</label>
        <?php if ($id === 0): ?>
            <input class="form-input" type="text" id="code" name="code" maxlength="40" value="<?= $val('code') ?>" required>
            <?php if ($err('code') !== ''): ?><p class="form-error"><?= $err('code') ?></p><?php endif; ?>
        <?php else: ?>
            <input class="form-input" type="text" id="code" value="<?= $val('code') ?>" disabled>
            <p><small class="muted">Le code est immuable apres creation.</small></p>
        <?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="label">Libelle</label>
        <input class="form-input" type="text" id="label" name="label" maxlength="80" value="<?= $val('label') ?>" required>
        <?php if ($err('label') !== ''): ?><p class="form-error"><?= $err('label') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="description">Description</label>
        <input class="form-input" type="text" id="description" name="description" value="<?= $val('description') ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="default_route">Route par defaut (landing)</label>
        <input class="form-input" type="text" id="default_route" name="default_route" maxlength="120" value="<?= $val('default_route') ?>">
        <?php if ($err('default_route') !== ''): ?><p class="form-error"><?= $err('default_route') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="order_source">Source de commande auto-taggee</label>
        <select class="form-input" id="order_source" name="order_source">
            <option value="">-- aucune (admin/manager) --</option>
            <?php foreach ($srcList as $src): ?>
                <option value="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>"<?= $src === $selectedSource ? ' selected' : '' ?>><?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($err('order_source') !== ''): ?><p class="form-error"><?= $err('order_source') ?></p><?php endif; ?>
    </div>

    <?php if ($id !== 0): ?>
    <div class="form-group">
        <label class="form-label"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> Role actif</label>
    </div>
    <?php endif; ?>

    <fieldset class="form-group">
        <legend>Permissions</legend>
        <?php if ($err('permissions') !== ''): ?><p class="form-error"><?= $err('permissions') ?></p><?php endif; ?>
        <div style="max-height:320px; overflow-y:auto;">
            <?php foreach ($perms as $p): ?>
                <?php
                $pid = (int) ($p['id'] ?? 0);
                $checked = in_array($pid, $selPerms, true);
                ?>
                <label style="display:block; padding:2px 0;">
                    <input type="checkbox" name="perm_<?= $pid ?>" value="1"<?= $checked ? ' checked' : '' ?>>
                    <code><?= htmlspecialchars((string) ($p['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                    <span class="muted">- <?= htmlspecialchars((string) ($p['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="form-group">
        <legend>Sources de tableau de bord visibles</legend>
        <?php foreach ($srcList as $src): ?>
            <label style="display:inline-block; margin-right:1rem;">
                <input type="checkbox" name="source_<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" value="1"<?= in_array($src, $selSources, true) ? ' checked' : '' ?>>
                <?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="form-group">
        <legend>Re-autorisation (PIN equipier)</legend>
        <div class="form-group">
            <label class="form-label" for="pin_email">Email equipier</label>
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
