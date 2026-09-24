// Parcours E2E admin : garde de session -> connexion -> dashboard -> deconnexion.
// L'admin seede n'a PAS de PIN (pin_hash NULL) -> pas d'action sensible testable ici.
// URLs absolues sur admin.wakdo.test (le vhost admin ; baseURL = kiosk pour la borne).
const { test, expect } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
// Identifiants DEV seedes (db/seeds/0001) ; a changer en prod.
const EMAIL = 'admin@wakdo.local';
const PASSWORD = 'WakdoAdmin2026!';

test('parcours admin : garde -> login -> dashboard -> logout', async ({ page }) => {

  await test.step('la garde de session redirige vers /login', async () => {
    await page.goto(`${ADMIN}/admin/dashboard`);
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('#email')).toBeVisible();
  });

  await test.step('connexion admin', async () => {
    await page.fill('#email', EMAIL);
    await page.fill('#password', PASSWORD);
    // Le jeton _csrf cache est soumis avec le formulaire (comme un vrai navigateur).
    await page.locator('form[action="/login"] button[type="submit"]').click();
    // role.default_route de l'admin = /admin/dashboard
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await expect(page.locator('#userMenuBtn')).toBeVisible();
  });

  await test.step('deconnexion', async () => {
    await page.locator('#userMenuBtn').click();
    await page.locator('form[action="/logout"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/login/);
  });
});

// Regression F40 (capture 26) : le bouton fixe "Police adaptee" (bas-droite) recouvrait
// le bouton "Masquer" de la derniere categorie une fois le contenu descendu jusqu'en
// bas. .content reserve desormais une marge basse (admin.css) pour que rien ne finisse
// dessous, quel que soit le defilement.
test('le bouton Police adaptee ne recouvre aucun bouton d action une fois le contenu descendu', async ({ page }) => {
  await page.goto('http://admin.wakdo.test/login');
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page.locator('#userMenuBtn')).toBeVisible();

  await page.goto('http://admin.wakdo.test/admin/categories');
  const toggle = page.locator('.a11y-toggle');
  await expect(toggle).toBeVisible();

  // Descend le conteneur de contenu tout en bas (la ou le dernier bouton d'action vit).
  await page.locator('.content').evaluate((el) => { el.scrollTop = el.scrollHeight; });

  const toggleBox = await toggle.boundingBox();
  const lastActionButton = page.locator('.content .btn, .content button').last();
  await expect(lastActionButton).toBeVisible();
  const buttonBox = await lastActionButton.boundingBox();

  const overlaps = toggleBox && buttonBox
    && toggleBox.x < buttonBox.x + buttonBox.width
    && toggleBox.x + toggleBox.width > buttonBox.x
    && toggleBox.y < buttonBox.y + buttonBox.height
    && toggleBox.y + toggleBox.height > buttonBox.y;
  expect(overlaps).toBe(false);
});

// Regression F40 (mise en page) : les cases Retirable et Ajoutable, ajoutees comme
// deux <label> DOM distincts sans le moindre espace entre elles (product-recipe.js),
// se touchaient. Une marge sur le premier label les separe desormais.
test('les cases Retirable et Ajoutable de la recette ne sont pas collees', async ({ page }) => {
  await page.goto('http://admin.wakdo.test/login');
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page.locator('#userMenuBtn')).toBeVisible();

  await page.goto('http://admin.wakdo.test/admin/products');
  await page.locator('a.btn:has-text("Recette")').first().click();
  await expect(page).toHaveURL(/\/admin\/products\/\d+\/recipe/);

  const removableLabel = page.locator('.recipe-removable').first().locator('xpath=..');
  const addableLabel = page.locator('.recipe-addable').first().locator('xpath=..');
  await expect(removableLabel).toBeVisible();
  const removableBox = await removableLabel.boundingBox();
  const addableBox = await addableLabel.boundingBox();

  // Meme ligne (meme Y) : un ECART strictement positif entre les deux boites, pas
  // seulement l'absence de chevauchement (>=0 laisserait passer un ecart de 0px,
  // c'est-a-dire des cases collees -- relecture independante du 24/09).
  const gap = addableBox.x - (removableBox.x + removableBox.width);
  expect(gap).toBeGreaterThan(0);
});
