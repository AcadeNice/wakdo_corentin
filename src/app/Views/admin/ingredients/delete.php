<?php

declare(strict_types=1);

/**
 * Confirmation de suppression d'un ingredient, injecte dans admin/layout.php. La
 * suppression d'ingredient n'est PAS une action sensible (8.8 hors RG-T13) : pas de
 * PIN, juste une confirmation CSRF. La suppression dure echoue (409) si l'ingredient
 * est reference par une recette ou un mouvement (FK RESTRICT) -> proposer la
 * desactivation. CSRF cache.
 *
 * @var int          $ingredientId
 * @var string       $name
 * @var string|null  $error
 * @var string       $csrfToken
 */

$csrf = htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8');
$id = (int) ($ingredientId ?? 0);
$ingredientName = htmlspecialchars((string) ($name ?? ''), ENT_QUOTES, 'UTF-8');
$errorMessage = isset($error) && is_string($error) ? $error : null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Supprimer un ingredient</h1>
        <p class="page-subtitle">Confirmez la suppression de "<?= $ingredientName ?>".</p>
    </div>
</div>

<section>
    <?php if ($errorMessage !== null): ?>
        <p role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/ingredients/<?= $id ?>/delete" class="form-card">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">

        <p><small>Un ingredient deja utilise (recette ou mouvement de stock) ne peut pas etre supprime : desactivez-le a la place.</small></p>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Supprimer definitivement</button>
            <a class="btn btn-secondary" href="/admin/ingredients">Annuler</a>
        </div>
    </form>
</section>
