// Lot 3 (simplification back-office) : tableau de bord Stock + liste Produits.
// Couvre les 4 livrables de ce lot qui n'etaient pas deja verrouilles par un test
// existant : masquage des liens produit selon la permission reelle (regression
// releve par le balayage transversal, rapport.md sweep), alignement numerique
// (.table-num), regroupement des actions de ligne (.row-actions/.row-actions__danger)
// et le repere visuel "ligne modifiee" (.row-highlight, cable via stock-thresholds.js).
// N'AJOUTE aucun cas dans backoffice-sweep.spec.js / admin.spec.js (convention du
// plan de simplification, §4) : fichier propre a ce lot.
const { test, expect } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const MANAGER_EMAIL = 'manager@wakdo.local';
const MANAGER_PASSWORD = 'WakdoManager2026!';
const CUISINE_EMAIL = 'cuisine@wakdo.local';
const CUISINE_PASSWORD = 'WakdoCuisine2026!';

// "Pain sésame", seede a 100% de capacite (voir admin-stock-movement.spec.js) : un
// reappro dessus est un mouvement reel et sans risque pour les autres specs.
const RESTOCK_INGREDIENT_ID = 2;

async function login(page, email, password) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.locator('form[action="/login"] button[type="submit"]').click();
}

test.describe('Stock des ingredients - tableau de bord (lot 3)', () => {
  test('la liste complete regroupe les actions de ligne, le bouton de suppression est en danger sur la page de confirmation', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto(`${ADMIN}/admin/ingredients`);

    const firstRow = page.locator('.stock-list__row').first();
    await expect(firstRow.locator('.row-actions')).toHaveCount(1);

    // Bouton de suppression : ghost sur la LISTE (simple navigation vers la
    // confirmation), danger seulement sur la page de confirmation elle-meme
    // (design-system.md §3 : "un mot par vue", ingredients/delete.php L41).
    const deleteLink = firstRow.locator('a:has-text("Supprimer")');
    await expect(deleteLink).toHaveCount(1);
    await deleteLink.click();
    await expect(page).toHaveURL(/\/admin\/ingredients\/\d+\/delete/);
    await expect(page.locator('button[type="submit"]:has-text("Supprimer définitivement")')).toHaveClass(/btn-danger/);
  });

  test('mouvements de stock : la colonne Variation est alignee (table-num)', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto(`${ADMIN}/admin/ingredients/${RESTOCK_INGREDIENT_ID}/movements`);
    await expect(page.locator('th.table-num:has-text("Variation")')).toHaveCount(1);
  });

  test('un reapprovisionnement reussi met en evidence la ligne concernee au retour sur le tableau de bord, sans erreur console (CSP)', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto(`${ADMIN}/admin/ingredients/${RESTOCK_INGREDIENT_ID}/restock`);
    await page.fill('#packs', '2');
    await page.locator('form.form-card button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/ingredients$/);
    const row = page.locator(`[data-row-key="ingredient:${RESTOCK_INGREDIENT_ID}"]`).first();
    await expect(row).toHaveClass(/row-highlight/);

    const cspViolations = consoleErrors.filter((m) => /content security policy/i.test(m));
    expect(cspViolations).toEqual([]);
  });
});

test.describe('Produits - liste (lot 3)', () => {
  test('administrateur : les 4 actions sont visibles, Prix aligne (table-num), Supprimer separe (row-actions__danger)', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto(`${ADMIN}/admin/products`);

    await expect(page.locator('a.btn-primary:has-text("Nouveau produit")')).toBeVisible();
    await expect(page.locator('th.table-num:has-text("Prix")')).toHaveCount(1);

    const firstRow = page.locator('table tbody tr').first();
    await expect(firstRow.locator('a:has-text("Modifier")')).toBeVisible();
    await expect(firstRow.locator('a:has-text("Recette")')).toBeVisible();
    await expect(firstRow.locator('.row-actions__danger a:has-text("Supprimer")')).toBeVisible();
  });

  test('responsable (manager) : cree/modifie/voit la recette, mais PAS Supprimer (product.delete absent, cf. rbac-demo.spec.js)', async ({ page }) => {
    await login(page, MANAGER_EMAIL, MANAGER_PASSWORD);
    await page.goto(`${ADMIN}/admin/products`);

    await expect(page.locator('a.btn-primary:has-text("Nouveau produit")')).toBeVisible();
    const firstRow = page.locator('table tbody tr').first();
    await expect(firstRow.locator('a:has-text("Modifier")')).toBeVisible();
    await expect(firstRow.locator('a:has-text("Recette")')).toBeVisible();
    await expect(firstRow.locator('a:has-text("Supprimer")')).toHaveCount(0);
  });

  test('equipier cuisine : aucune des 4 actions de gestion, uniquement la vue par categorie', async ({ page }) => {
    await login(page, CUISINE_EMAIL, CUISINE_PASSWORD);
    await page.goto(`${ADMIN}/admin/products`);

    await expect(page.locator('a:has-text("Nouveau produit")')).toHaveCount(0);
    await expect(page.locator('a:has-text("Modifier")')).toHaveCount(0);
    await expect(page.locator('a:has-text("Recette")')).toHaveCount(0);
    await expect(page.locator('a:has-text("Supprimer")')).toHaveCount(0);
    await expect(page.locator('a.btn-secondary:has-text("Vue par catégorie")')).toBeVisible();

    // Le refus reel par-route (RG-T03) tient toujours : masquer le lien n'est qu'un
    // confort d'affichage, pas la garde. Navigation directe -> 403, jamais un lien cliquable.
    const response = await page.goto(`${ADMIN}/admin/products/new`);
    expect(response.status()).toBe(403);
  });

  test('le repere de ligne modifiee s applique a la bonne ligne produit (mecanisme verifie sans suppression reelle)', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto(`${ADMIN}/admin/products`);

    const rows = page.locator('table tbody tr[data-row-key]');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
    const targetKey = await rows.first().getAttribute('data-row-key');

    // Injecte la cle comme le ferait products/delete.php juste avant une navigation
    // reussie (meme mecanisme que stock-thresholds.test.js, ici verifie en navigateur
    // reel : confirme que la CSP script-src 'self' de ce vhost n'empeche pas le script
    // externe de lire sessionStorage ni d'appliquer la classe).
    await page.evaluate((key) => sessionStorage.setItem('wakdo-row-highlight', key), targetKey);
    await page.reload();

    await expect(page.locator(`[data-row-key="${targetKey}"]`)).toHaveClass(/row-highlight/);
    // Usage unique : un second chargement ne re-applique rien.
    await page.reload();
    await expect(page.locator(`[data-row-key="${targetKey}"]`)).not.toHaveClass(/row-highlight/);
  });
});
