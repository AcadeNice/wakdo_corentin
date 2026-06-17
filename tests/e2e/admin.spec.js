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
