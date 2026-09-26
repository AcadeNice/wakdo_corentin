// Regression bug back-office (2026-09-26) : creer un produit renvoyait "Requête
// invalide." (403) systematiquement -- le formulaire est TOUJOURS en
// multipart/form-data (upload d'image), et Request::formBody() ne reconnaissait que
// l'urlencode : _csrf etait donc toujours absent. Couvre le cas nominal (sans image,
// avec une petite image) et le cas de l'image trop lourde (message clair attendu).
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const ADMIN = 'http://admin.wakdo.test';
const EMAIL = 'admin@wakdo.local';
const PASSWORD = 'WakdoAdmin2026!';

async function login(page) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin\/dashboard/);
}

async function fillBaseFields(page, name) {
  await page.selectOption('#category_id', { index: 1 });
  await page.fill('#name', name);
  await page.fill('#price_cents', '5,90');
}

test.describe('Creation de produit (back-office)', () => {

  test('creer un produit SANS image aboutit (plus de "Requête invalide")', async ({ page }) => {
    await login(page);
    await page.goto(`${ADMIN}/admin/products/new`);
    await fillBaseFields(page, 'Produit E2E sans image');

    await page.locator('form.form-card button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/products$/);
    await expect(page.locator('body')).not.toContainText('Requête invalide');
    await expect(page.locator('body')).toContainText('Produit E2E sans image');
  });

  test('creer un produit AVEC une petite image aboutit', async ({ page }) => {
    await login(page);
    await page.goto(`${ADMIN}/admin/products/new`);
    await fillBaseFields(page, 'Produit E2E petite image');

    // PNG 1x1 valide et complet (signature + IHDR + IDAT + IEND).
    const png = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      'base64',
    );
    const tmp = path.join(os.tmpdir(), 'e2e-small.png');
    fs.writeFileSync(tmp, png);

    await page.setInputFiles('#image_file', tmp);
    await page.locator('form.form-card button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/products$/);
    await expect(page.locator('body')).not.toContainText('Requête invalide');
  });

  test('image trop lourde (6 Mo) : message clair, pas de "Requête invalide"', async ({ page }) => {
    await login(page);
    await page.goto(`${ADMIN}/admin/products/new`);
    await fillBaseFields(page, 'Produit E2E image trop lourde');

    const tmp = path.join(os.tmpdir(), 'e2e-big.jpg');
    fs.writeFileSync(tmp, Buffer.alloc(6 * 1024 * 1024, 0xff));

    await page.setInputFiles('#image_file', tmp);
    // Avertissement client (image-drop.js), avant meme l'envoi.
    await expect(page.locator('[data-image-drop-hint]')).toContainText('trop lourd');

    await page.locator('form.form-card button[type="submit"]').click();

    // Le serveur refuse (post_max_size non depasse a 6 Mo : c'est
    // upload_max_filesize=5M qui rejette l'image, cote ImageUploader), avec un
    // message en francais clair -- jamais le 403 brut.
    await expect(page.locator('body')).not.toContainText('Requête invalide');
    await expect(page.locator('body')).toContainText(/dépasse la taille maximale/);
  });
});

test.describe('Modification de categorie (back-office)', () => {
  // Meme formulaire multipart/form-data (upload d'image) que le produit : le
  // bug touchait aussi la MODIFICATION d'une categorie existante, pas
  // seulement la creation d'un produit.
  test('modifier une categorie existante aboutit (plus de "Requête invalide")', async ({ page }) => {
    await login(page);
    await page.goto(`${ADMIN}/admin/categories`);
    await page.locator('a.btn:has-text("Modifier")').first().click();
    await expect(page).toHaveURL(/\/admin\/categories\/\d+\/edit/);

    const nouveauLibelle = 'Catégorie E2E modifiée';
    await page.fill('#name', nouveauLibelle);
    await page.locator('form.form-card button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/categories$/);
    await expect(page.locator('body')).not.toContainText('Requête invalide');
    await expect(page.locator('body')).toContainText(nouveauLibelle);
  });
});
