<?php

declare(strict_types=1);

/**
 * Formulaire utilisateur (creation/edition), injecte dans admin/layout.php.
 * Reaffiche valeurs + erreurs (RG-T18). Toute soumission est une action sensible :
 * le bloc PIN equipier (email + PIN) est requis (RG-T13). Le mot de passe est requis
 * a la creation, optionnel a l'edition (laisser vide = inchange, mlt 10.2 RG-2).
 *
 * @var int                              $userId
 * @var list<array{id:int, label:string}> $roles
 * @var array<string, mixed>             $values
 * @var array<string, string>            $errors
 * @var string                           $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($userId ?? 0);
$action = $id !== 0 ? '/admin/users/' . $id : '/admin/users';
/** @var array<string, mixed> $vals */
$vals = isset($values) && is_array($values) ? $values : [];
/** @var array<string, string> $errs */
$errs = isset($errors) && is_array($errors) ? $errors : [];
/** @var list<array{id:int, label:string}> $roleList */
$roleList = isset($roles) && is_array($roles) ? $roles : [];

$val = static fn (string $k): string => htmlspecialchars((string) ($vals[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static fn (string $k): string => isset($errs[$k]) && is_string($errs[$k]) ? htmlspecialchars($errs[$k], ENT_QUOTES, 'UTF-8') : '';
$selectedRole = (string) ($vals['role_id'] ?? '');
$active = (bool) ($vals['is_active'] ?? true);
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id !== 0 ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur' ?></h1>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="form-card">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

    <div class="form-group">
        <label class="form-label" for="email">Email</label>
        <input class="form-input" type="email" id="email" name="email" maxlength="254" value="<?= $val('email') ?>" required>
        <?php if ($err('email') !== ''): ?><p class="form-error"><?= $err('email') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="first_name">Prenom</label>
        <input class="form-input" type="text" id="first_name" name="first_name" maxlength="60" value="<?= $val('first_name') ?>" required>
        <?php if ($err('first_name') !== ''): ?><p class="form-error"><?= $err('first_name') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="last_name">Nom</label>
        <input class="form-input" type="text" id="last_name" name="last_name" maxlength="60" value="<?= $val('last_name') ?>" required>
        <?php if ($err('last_name') !== ''): ?><p class="form-error"><?= $err('last_name') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="role_id">Role</label>
        <select class="form-input" id="role_id" name="role_id" required>
            <option value="">-- choisir --</option>
            <?php foreach ($roleList as $role): ?>
                <?php $rid = (string) $role['id']; ?>
                <option value="<?= htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') ?>"<?= $rid === $selectedRole ? ' selected' : '' ?>>
                    <?= htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($err('role_id') !== ''): ?><p class="form-error"><?= $err('role_id') ?></p><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="password"><?= $id !== 0 ? 'Nouveau mot de passe (laisser vide = inchange)' : 'Mot de passe' ?></label>
        <input class="form-input" type="password" id="password" name="password" autocomplete="new-password"<?= $id === 0 ? ' required' : '' ?>>
        <?php if ($err('password') !== ''): ?><p class="form-error"><?= $err('password') ?></p><?php endif; ?>
    </div>

    <?php if ($id !== 0): ?>
    <div class="form-group">
        <label class="form-label"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> Compte actif</label>
    </div>
    <?php endif; ?>

    <fieldset class="form-group">
        <legend>Re-autorisation (PIN equipier)</legend>
        <p><small>La gestion des comptes est une action sensible : confirmez avec votre email et votre PIN.</small></p>
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
        <a class="btn btn-secondary" href="/admin/users">Annuler</a>
    </div>
</form>
