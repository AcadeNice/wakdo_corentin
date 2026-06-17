// Parcours E2E borne : welcome -> categories -> produit -> ajout panier -> panier
// -> paiement -> confirmation. La stack est montee a part (run.sh) ; le panier vit
// dans localStorage (meme origine), donc on peut naviguer par goto sans perdre l'etat.
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
    // Categorie 2 = boissons : produits SIMPLES (la categorie 1 = menus, qui rendent
    // un autre gabarit a slots, sans bouton d'ajout direct).
    await page.locator('a[href="products.html?category=2"]').click();
    await expect(page).toHaveURL(/products\.html\?category=2/);
  });

  await test.step('produits -> fiche produit', async () => {
    // Cartes rendues par JS depuis le JSON : auto-wait sur la 1re carte.
    const firstCard = page.locator('#products-grid a.product-card').first();
    await expect(firstCard).toBeVisible();
    await firstCard.click();
    await expect(page).toHaveURL(/product\.html\?id=/);
  });

  await test.step('ajout au panier', async () => {
    const addBtn = page.locator('#add-to-cart-btn');
    await expect(addBtn).toBeVisible();
    await addBtn.click();
    // Feedback visuel "Ajoute !" (page-product.js) ; l'ecriture localStorage est synchrone.
    await expect(addBtn).toHaveText(/Ajoute/);
  });

  await test.step('panier : recapitulatif', async () => {
    await page.goto('/cart.html');
    await expect(page.locator('#cart-summary')).toBeVisible();
    await expect(page.locator('#cart-list li')).toHaveCount(1);
    // Total calcule, plus le placeholder "—".
    await expect(page.locator('#total-ttc')).not.toHaveText('—');
    await page.locator('#pay-btn').click();
    await expect(page).toHaveURL(/payment\.html/);
  });

  await test.step('paiement -> confirmation', async () => {
    await page.locator('#pay-card').click();
    await expect(page).toHaveURL(/confirmation\.html/);
    await expect(page.locator('.confirmation-banner__title')).toHaveText(/Commande confirmee/);
    // Numero de commande genere (plus le placeholder).
    await expect(page.locator('#order-number')).not.toHaveText('—');
  });
});
