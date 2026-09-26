// Preuve RBAC en navigateur pour les 5 comptes de demonstration (seed
// db/seeds/0009_demo_accounts.sql, identifiants publics documentes dans
// docs/demo/comptes-demo.md). Pour chaque compte : connexion, page d'arrivee
// (role.default_route, seed 0001), navigation visible (liens du menu lateral,
// admin/layout.php) et au moins un refus (403 "Acces refuse").
//
// Complementaire de tests/Integration/RouteMatrixRoleDbTest.php (qui prouve la
// meme grille au niveau de l'autorisation, contre une vraie base) : ce fichier
// prouve qu'elle se traduit reellement dans le navigateur, pour le jury.
//
// URLs absolues sur admin.wakdo.test (meme convention que admin.spec.js).
const { test, expect } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';

async function login(page, email, password) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.locator('form[action="/login"] button[type="submit"]').click();
}

async function expectForbidden(page, path) {
  const response = await page.goto(`${ADMIN}${path}`);
  expect(response.status()).toBe(403);
  await expect(page.locator('.page-title')).toHaveText('Accès refusé');
}

test.describe('RBAC - comptes de demonstration', () => {
  test('manager : atterrit sur /admin/stats, voit catalogue+stock+comptes, PAS commandes ni roles', async ({ page }) => {
    await login(page, 'manager@wakdo.local', 'WakdoManager2026!');
    await expect(page).toHaveURL(/\/admin\/stats/);

    // Navigation visible : category.manage, product.read, stock.read, user.read, stats.read.
    await expect(page.locator('a[href="/admin/categories"]')).toBeVisible();
    await expect(page.locator('a[href="/admin/ingredients"]')).toBeVisible();
    await expect(page.locator('a[href="/admin/users"]')).toBeVisible();
    await expect(page.locator('a[href="/admin/stats"]')).toBeVisible();

    // Navigation absente : aucun order.*, pas role.manage.
    await expect(page.locator('a[href="/admin/orders"]')).toHaveCount(0);
    await expect(page.locator('a[href="/kitchen/display"]')).toHaveCount(0);
    await expect(page.locator('a[href="/admin/roles"]')).toHaveCount(0);

    // Refus : manager ne detient pas role.manage (separation des pouvoirs, D5 -
    // pas order.cancel non plus, mais cette route exige un numero de commande
    // existant ; /admin/roles suffit a prouver le refus sans fixture supplementaire).
    await expectForbidden(page, '/admin/roles');
  });

  test('cuisine : atterrit sur /kitchen/display, voit Commandes+Cuisine, PAS de saisie ni stats', async ({ page }) => {
    await login(page, 'cuisine@wakdo.local', 'WakdoCuisine2026!');
    await expect(page).toHaveURL(/\/kitchen\/display/);

    // order.read donne acces aux DEUX liens (Commandes ET Cuisine KDS) - verifie
    // dans le code (OrderAdminController::index n'est garde que par order.read),
    // documente dans docs/demo/comptes-demo.md pour ne pas laisser croire que
    // kitchen ne voit que l'ecran cuisine.
    await expect(page.locator('a[href="/admin/orders"]')).toBeVisible();
    await expect(page.locator('a[href="/kitchen/display"]')).toBeVisible();
    await expect(page.locator('a[href="/admin/ingredients"]')).toBeVisible();

    // Navigation absente : pas de category.manage, pas de saisie commande
    // (order.create absent), pas de stats, pas d'administration des comptes.
    await expect(page.locator('a[href="/admin/categories"]')).toHaveCount(0);
    await expect(page.locator('a[href="/counter/orders"]')).toHaveCount(0);
    await expect(page.locator('a[href="/drive/orders"]')).toHaveCount(0);
    await expect(page.locator('a[href="/admin/stats"]')).toHaveCount(0);
    await expect(page.locator('a[href="/admin/users"]')).toHaveCount(0);

    // Refus : pas de user.read.
    await expectForbidden(page, '/admin/users');
  });

  test('comptoir : atterrit sur /counter/orders, voit Saisie commande, PAS comptes ni stats', async ({ page }) => {
    await login(page, 'comptoir@wakdo.local', 'WakdoComptoir2026!');
    await expect(page).toHaveURL(/\/counter\/orders/);

    await expect(page.locator('a[href="/counter/orders"]')).toBeVisible();
    await expect(page.locator('a[href="/admin/orders"]')).toBeVisible();
    await expect(page.locator('a[href="/kitchen/display"]')).toBeVisible();
    await expect(page.locator('a[href="/admin/ingredients"]')).toBeVisible();

    await expect(page.locator('a[href="/admin/categories"]')).toHaveCount(0);
    await expect(page.locator('a[href="/admin/stats"]')).toHaveCount(0);
    await expect(page.locator('a[href="/admin/users"]')).toHaveCount(0);
    await expect(page.locator('a[href="/admin/roles"]')).toHaveCount(0);

    // Refus donne dans la commande : le comptoir ouvre /admin/users -> 403.
    await expectForbidden(page, '/admin/users');

    // C6 (relecture adverse, RG-T12, docs/demo/matrice-rbac.md section 3) : canal
    // FIXE 'counter' -> l'AUTRE canal (drive) rend 403, channelGuard() refuse meme
    // avec order.create detenu. Preuve avec le vrai compte de demo, pas un role
    // auto-provisionne (cf. tests/e2e/rbac-channel.spec.js pour les roles
    // personnalises kiosk/visibilite restreinte).
    await expectForbidden(page, '/drive/orders');
  });

  test('comptoir (2e equipier) : memes droits/refus que le premier equipier comptoir', async ({ page }) => {
    await login(page, 'comptoir2@wakdo.local', 'WakdoComptoirB2026!');
    await expect(page).toHaveURL(/\/counter\/orders/);
    await expect(page.locator('a[href="/counter/orders"]')).toBeVisible();
    await expectForbidden(page, '/admin/users');
  });

  test('drive : atterrit sur /drive/orders, voit Saisie commande, PAS ingredients.manage ni stats', async ({ page }) => {
    await login(page, 'drive@wakdo.local', 'WakdoDrive2026!');
    await expect(page).toHaveURL(/\/drive\/orders/);

    await expect(page.locator('a[href="/drive/orders"]')).toBeVisible();
    await expect(page.locator('a[href="/admin/orders"]')).toBeVisible();
    await expect(page.locator('a[href="/kitchen/display"]')).toBeVisible();

    await expect(page.locator('a[href="/admin/categories"]')).toHaveCount(0);
    await expect(page.locator('a[href="/admin/stats"]')).toHaveCount(0);
    await expect(page.locator('a[href="/admin/users"]')).toHaveCount(0);

    // Refus : drive ne detient pas ingredient.manage (creation d'ingredient).
    await expectForbidden(page, '/admin/ingredients/new');

    // D6 (relecture adverse, RG-T12, symetrique de C6 ci-dessus) : canal FIXE
    // 'drive' -> l'AUTRE canal (counter) rend 403.
    await expectForbidden(page, '/counter/orders');
  });
});
