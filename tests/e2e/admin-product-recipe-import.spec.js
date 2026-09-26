// Chantier "recette dans le formulaire produit + import CSV" (2026-09-26).
// Trois parcours demandes :
//  1. Creer un burger avec 3 ingredients (dont 1 nouveau) depuis le formulaire
//     produit, puis verifier sa recette, l'ingredient dans Stock, et la rupture
//     a la borne.
//  2. Telecharger le modele CSV, l'importer tel quel, lire l'apercu, confirmer,
//     puis verifier les produits et le stock.
//  3. Importer un fichier avec des erreurs : apercu rouge, confirmation
//     impossible.
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const ADMIN = 'http://admin.wakdo.test';
const KIOSK = 'http://kiosk.wakdo.test';
const EMAIL = 'admin@wakdo.local';
const PASSWORD = 'WakdoAdmin2026!';

async function login(page) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin\/dashboard/);
}

// selectOption({label}) exige une correspondance EXACTE (le libelle reel porte
// l'unite, ex. "Pain burger (piece)", que ce test ne connait pas a l'avance) :
// on retrouve plutot l'<option> par un texte PARTIEL, puis on selectionne sa
// valeur (l'id numerique de l'ingredient).
async function selectIngredientContaining(select, namePart) {
  const value = await select.locator('option', { hasText: namePart }).first().getAttribute('value');
  await select.selectOption(value);
}

test.describe('Recette integree au formulaire produit', () => {
  test('creer un burger avec 3 ingredients (1 nouveau) : recette, stock, rupture a la borne', async ({ page }) => {
    const unique = Date.now();
    const productName = `Burger E2E ${unique}`;
    const newIngredientName = `Sauce secrète E2E ${unique}`;

    await login(page);
    await page.goto(`${ADMIN}/admin/products/new`);

    await page.selectOption('#category_id', { label: 'Burgers' });
    await page.fill('#name', productName);
    await page.fill('#price_cents', '6,90');

    // Ligne 1 : ingredient existant (Pain burger, seede).
    await page.locator('#add-ingredient').click();
    await selectIngredientContaining(page.locator('.recipe-line').nth(0).locator('.recipe-ingredient'), 'Pain burger');

    // Ligne 2 : un second ingredient existant, marque NON RETIRABLE (RG-T21 :
    // c'est lui qui doit faire tomber le produit en rupture une fois a 0).
    await page.locator('#add-ingredient').click();
    await selectIngredientContaining(page.locator('.recipe-line').nth(1).locator('.recipe-ingredient'), 'Cheddar');

    // Ligne 3 : un NOUVEL ingredient, cree a la volee (stock 0), NON RETIRABLE.
    await page.locator('#add-new-ingredient').click();
    const newLine = page.locator('.recipe-line-new').first();
    await newLine.locator('.recipe-new-name').fill(newIngredientName);
    await newLine.locator('.recipe-new-unit').fill('g');
    // "Retirable" reste DECOCHE (defaut) : cet ingredient nouveau est donc NON
    // RETIRABLE, ce qui le rend eligible a la rupture automatique (RG-T21).
    await expect(newLine.locator('.recipe-removable')).not.toBeChecked();

    await page.locator('form.form-card button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/products$/);
    await expect(page.locator('body')).toContainText(productName);
    // Message de creation d'ingredient (F40 : clair, jamais un code technique).
    await expect(page.locator('body')).toContainText(/réapprovisionner/);

    // --- Verification 1 : la recette du produit porte bien les 3 lignes. ---
    await page.locator(`tr:has-text("${productName}") a:has-text("Recette")`).click();
    await expect(page).toHaveURL(/\/admin\/products\/\d+\/recipe/);
    await expect(page.locator('.recipe-line')).toHaveCount(3);
    await expect(page.locator('body')).toContainText(newIngredientName);

    // --- Verification 2 : le nouvel ingredient existe dans Stock, a 0. ---
    //     Page Stock = une liste <ul><li>, PAS un tableau (contrairement a la
    //     liste des produits) : le selecteur reflete ce balisage reel.
    await page.goto(`${ADMIN}/admin/ingredients`);
    const stockRow = page.locator('li.stock-list__row', { hasText: newIngredientName });
    await expect(stockRow).toBeVisible();
    // stock_quantity=0 / stock_capacity=100 (RG-CREATE-ING + defaut du projet).
    await expect(stockRow.locator('.stock-bar__qty')).toHaveText('0 / 100');

    // --- Verification 3 : rupture a la borne. Meme d'abord visible dans la
    //     LISTE admin (pastille "Rupture auto", deja couverte en PHPUnit par
    //     testIndexFlagsStockDrivenRupture) que sur la grille kiosk (categorie
    //     Burgers = id 3, ordre de seed db/seeds/0002_catalogue.sql). L'ingredient
    //     nouvellement cree est a stock 0 et NON RETIRABLE par defaut -> RG-T21
    //     doit faire tomber le produit en rupture calculee des sa creation. ---
    await page.goto(`${ADMIN}/admin/products`);
    await expect(page.locator(`tr:has-text("${productName}")`)).toContainText('Rupture auto');

    // nav.js renvoie a l'accueil toute page profonde SANS mode de consommation
    // memorise (localStorage wakdo_mode) : on le seme avant de naviguer, comme
    // le fait deja a11y.spec.js pour la meme raison (aucune commande creee,
    // c'est de l'etat purement client).
    await page.addInitScript(() => { localStorage.setItem('wakdo_mode', 'sur-place'); });
    await page.goto(`${KIOSK}/products.html?category=3`);
    const card = page.locator('#products-grid a.product-card', { hasText: productName });
    await expect(card).toBeVisible();
    await expect(card).toHaveClass(/product-card--unavailable/);
  });
});

test.describe('Import CSV de produits (modele -> apercu -> confirmation)', () => {
  test('telecharger le modele, l\'importer tel quel, lire l\'apercu, confirmer, verifier produits + stock', async ({ page }) => {
    await login(page);
    await page.goto(`${ADMIN}/admin/products/import`);

    // Telechargement direct via page.request (partage les cookies de session
    // de la page), sans dependre du gestionnaire de telechargement du navigateur.
    const templateResponse = await page.request.get(`${ADMIN}/admin/products/import/template`);
    expect(templateResponse.status()).toBe(200);
    const csv = await templateResponse.text();
    expect(csv).toContain('categorie;produit;description');

    const tmp = path.join(os.tmpdir(), `wakdo-e2e-import-${Date.now()}.csv`);
    fs.writeFileSync(tmp, csv);

    await page.setInputFiles('#csv_file', tmp);
    await page.locator('form[action="/admin/products/import/preview"] button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/products\/import\/preview$/);
    await expect(page.locator('body')).not.toContainText('erreur(s) : l\'import est bloqué');
    await expect(page.locator('body')).toContainText('Cheeseburger Wakdo');
    await expect(page.locator('body')).toContainText('Coca-Cola');

    await page.locator('form[action="/admin/products/import/confirm"] button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/products$/);
    await expect(page.locator('body')).toContainText(/produit\(s\) créé/);

    // --- Verification : les produits du modele existent desormais. ---
    await page.goto(`${ADMIN}/admin/products`);
    await expect(page.locator('body')).toContainText('Cheeseburger Wakdo');
    await expect(page.locator('body')).toContainText('Coca-Cola');

    // --- Verification : les ingredients du modele existent dans Stock. ---
    await page.goto(`${ADMIN}/admin/ingredients`);
    await expect(page.locator('body')).toContainText('Pain burger');
    await expect(page.locator('body')).toContainText('Cheddar');
  });
});

test.describe('Import CSV : fichier avec erreurs', () => {
  test('apercu rouge, confirmation impossible', async ({ page }) => {
    await login(page);
    await page.goto(`${ADMIN}/admin/products/import`);

    const csv = [
      'categorie;produit;description;prix_ttc;tva;taille_cl;disponible;ingredient;unite;quantite;retirable;ajoutable',
      'CategorieInexistante;Produit E2E erreur;;6,90;20;;oui;;;;;',
    ].join('\r\n');
    const tmp = path.join(os.tmpdir(), `wakdo-e2e-import-error-${Date.now()}.csv`);
    fs.writeFileSync(tmp, csv);

    await page.setInputFiles('#csv_file', tmp);
    await page.locator('form[action="/admin/products/import/preview"] button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/products\/import\/preview$/);
    await expect(page.locator('body')).toContainText('l\'import est bloqué');
    await expect(page.locator('body')).toContainText(/catégorie inconnue/i);
    await expect(page.locator('body')).toContainText(/TVA invalide/i);
    // Aucun bouton de confirmation ne doit etre propose.
    await expect(page.locator('form[action="/admin/products/import/confirm"]')).toHaveCount(0);
  });
});
