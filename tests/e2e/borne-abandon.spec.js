// Regression bug borne (2026-09-26) : quitter une commande en cours et revenir la
// laissait en place, sans jamais demander confirmation ("commande fantome"). Couvre
// les deux chemins de sortie reels (lien "Retour a l'accueil", bouton Precedent du
// navigateur) et le nettoyage silencieux de l'accueil (welcome-reset.js).
const { test, expect } = require('@playwright/test');

/** Ajoute le 1er produit simple (categorie boissons) au panier depuis l'accueil. */
async function addOneProductToCart(page) {
  await page.goto('/index.html');
  await page.locator('a[href*="categories.html?mode=sur-place"]').click();
  await expect(page).toHaveURL(/categories\.html/);
  await page.locator('a[href="products.html?category=2"]').click();
  await expect(page).toHaveURL(/products\.html\?category=2/);
  const firstCard = page.locator('#products-grid a.product-card:not(.product-card--unavailable)').first();
  await expect(firstCard).toBeVisible();
  await firstCard.click();
  await expect(page.locator('#po-add')).toBeVisible();
  await page.locator('#po-add').click();
  await expect(page.locator('[data-order-panel] .order-panel__line')).toHaveCount(1);
}

test.describe('Abandon de commande (borne)', () => {

  test('categories : Retour a l accueil avec panier vide ne demande rien', async ({ page }) => {
    await page.goto('/index.html');
    await page.locator('a[href*="categories.html?mode=sur-place"]').click();
    await expect(page).toHaveURL(/categories\.html/);

    await page.locator('#back-to-welcome').click();
    await expect(page).toHaveURL(/index\.html|\/$/);
  });

  test('categories : Retour a l accueil avec panier non vide demande confirmation', async ({ page }) => {
    await addOneProductToCart(page);

    // Retour aux categories (intra-parcours, cart intact) puis clic "Retour a l'accueil".
    await page.goBack(); // products.html -> categories.html (bouton "Categories" du header produits)
    await expect(page).toHaveURL(/categories\.html/);

    await page.locator('#back-to-welcome').click();
    await expect(page.locator('.confirm-overlay')).toBeVisible();
    await expect(page.locator('.confirm-modal__message')).toContainText('Abandonner');

    // Annuler : on reste sur categories.html, rien n'est efface.
    await page.locator('.confirm-modal__cancel').click();
    await expect(page.locator('.confirm-overlay')).toHaveCount(0);
    await expect(page).toHaveURL(/categories\.html/);
  });

  test('categories : confirmer l abandon vide le panier et revient a un accueil propre', async ({ page }) => {
    await addOneProductToCart(page);
    await page.goBack();
    await expect(page).toHaveURL(/categories\.html/);

    await page.locator('#back-to-welcome').click();
    await page.locator('.confirm-modal__confirm').click();

    await expect(page).toHaveURL(/index\.html|\/$/);
    await expect(page.locator('#welcome-heading')).toBeVisible();

    // Reprendre un parcours normal ensuite : le panier est bien vide (pas de ligne
    // fantome au premier produit ajoute).
    await page.locator('a[href*="categories.html?mode=a-emporter"]').click();
    await page.locator('a[href="products.html?category=2"]').click();
    const firstCard = page.locator('#products-grid a.product-card:not(.product-card--unavailable)').first();
    await firstCard.click();
    await page.locator('#po-add').click();
    await expect(page.locator('[data-order-panel] .order-panel__line')).toHaveCount(1);
  });

  test('bouton Precedent du navigateur sur categories avec panier non vide demande confirmation', async ({ page }) => {
    await addOneProductToCart(page);
    await page.goBack(); // -> categories.html, panier non vide, piege pose au chargement

    await page.goBack(); // tente de quitter vers l'accueil
    await expect(page.locator('.confirm-overlay')).toBeVisible();

    // Confirmer envoie a l'accueil et vide le panier.
    await page.locator('.confirm-modal__confirm').click();
    await expect(page).toHaveURL(/index\.html|\/$/);
  });

  test('un panier residuel au chargement de l accueil est efface silencieusement (pas de commande fantome)', async ({ page }) => {
    await addOneProductToCart(page);

    // Simule un rechargement direct de l'accueil (redemarrage/coupure) : le panier
    // est toujours en localStorage, mais AUCUNE confirmation n'est attendue ici --
    // c'est un nettoyage silencieux (welcome-reset.js), pas une sortie active.
    await page.goto('/index.html');
    await expect(page.locator('.confirm-overlay')).toHaveCount(0);

    await page.locator('a[href*="categories.html?mode=sur-place"]').click();
    await page.locator('a[href="products.html?category=2"]').click();
    // Le panneau ne montre AUCUNE ligne residuelle du parcours precedent.
    await expect(page.locator('[data-order-panel] .order-panel__empty')).toBeVisible();
  });
});
