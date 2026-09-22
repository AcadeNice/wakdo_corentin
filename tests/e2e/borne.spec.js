// Parcours E2E borne : welcome -> categories -> produits (grille) -> modale d'options
// au clic produit -> ajout -> le panneau de commande persistant (unique vue panier)
// reflete la ligne -> paiement -> confirmation. La stack est montee a part (run.sh) ;
// le panier vit dans localStorage (meme origine), on navigue sans perdre l'etat.
// Il n'existe plus de page panier ni de page produit separees (lot F3 panier unique).
const { test, expect } = require('@playwright/test');

test('parcours borne : de l\'accueil a la confirmation de commande', async ({ page }) => {

  await test.step('accueil -> categories', async () => {
    await page.goto('/index.html');
    await expect(page).toHaveTitle(/Bienvenue/);
    await expect(page.locator('#welcome-heading')).toBeVisible();
    // CTA "sur place" -> categories.html?mode=sur-place
    await page.locator('a[href*="categories.html?mode=sur-place"]').click();
    await expect(page).toHaveURL(/categories\.html/);
  });

  await test.step('categories -> produits', async () => {
    await expect(page.locator('h1.categories-main__heading')).toBeVisible();
    // Categorie 2 = boissons : produits SIMPLES (la categorie 1 = menus, qui ouvrent
    // le composeur a slots, un autre parcours). Un produit simple ouvre la modale options.
    await page.locator('a[href="products.html?category=2"]').click();
    await expect(page).toHaveURL(/products\.html\?category=2/);
  });

  await test.step('clic produit -> modale options', async () => {
    // Cartes rendues par JS depuis le JSON : auto-wait sur la 1re carte commandable.
    const firstCard = page.locator('#products-grid a.product-card:not(.product-card--unavailable)').first();
    await expect(firstCard).toBeVisible();
    await firstCard.click();
    // product-options.js monte une modale (.composer-overlay) avec le bouton d'ajout #po-add.
    await expect(page.locator('.composer-overlay [role="dialog"]')).toBeVisible();
    await expect(page.locator('#po-add')).toBeVisible();
  });

  await test.step('ajout -> le panneau de commande reflete la ligne', async () => {
    await page.locator('#po-add').click();
    // La modale se ferme et le panneau persistant (unique vue panier) montre la ligne.
    await expect(page.locator('.composer-overlay')).toHaveCount(0);
    const panel = page.locator('[data-order-panel]');
    await expect(panel.locator('.order-panel__line')).toHaveCount(1);
    // Total calcule (le panneau affiche un montant, plus le placeholder vide).
    await expect(panel.locator('.order-panel__total-value')).not.toHaveText('');
  });

  await test.step('panneau Payer -> paiement', async () => {
    const payLink = page.locator('[data-order-panel] .order-panel__pay');
    await expect(payLink).toHaveAttribute('aria-disabled', 'false');
    await payLink.click();
    await expect(page).toHaveURL(/payment\.html/);
  });

  await test.step('paiement -> confirmation', async () => {
    await page.locator('#pay-card').click();
    // Sur-place : page-payment.js ouvre la modale chevalet (numero de table) avant de
    // soumettre. On saisit un numero puis on enregistre pour declencher le checkout.
    await expect(page.locator('#chevalet-input')).toBeVisible();
    await page.locator('#chevalet-input').fill('12');
    await page.locator('#chevalet-ok').click();
    await expect(page).toHaveURL(/confirmation\.html/);
    await expect(page.locator('.confirmation-banner__title')).toHaveText(/Commande confirmee/);
    // Numero de commande genere (plus le placeholder).
    await expect(page.locator('#order-number')).not.toHaveText('—');
  });
});
