// Page Sante (/admin/health) : les trois ajouts de lecture, joues en vrai navigateur contre
// une pile jetable. Les tests JS (tests/js/health.test.js) prouvent la logique sur un faux
// DOM ; ce fichier prouve ce que seul un vrai navigateur et un vrai serveur montrent :
//   - le detail d'une sonde affiche la reponse reelle du serveur ;
//   - la console appelle une route GET avec la session de la page, parametre compris ;
//   - la connexion de demonstration (credentials: 'omit') renvoie un jeton SANS remplacer la
//     session de qui regarde la page : apres elle, /admin/api/auth/me repond encore role admin ;
//   - les panneaux de reponse restent fermes tant qu'aucun appel n'est lance.
// Identifiants publics des comptes de demonstration (db/seeds/0001 et 0009,
// docs/demo/comptes-demo.md) ; URLs absolues sur admin.wakdo.test (meme convention que admin.spec.js).
const { test, expect } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';

async function login(page, email, password) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.locator('form[action="/login"] button[type="submit"]').click();
}

async function consoleCall(page, label) {
  await page.selectOption('#health-console-route', { label });
  await page.click('#health-console-send');
  await expect(page.locator('#health-console-result')).toBeVisible();
}

test.describe('Page Sante : console de lecture et connexion de demonstration', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin@wakdo.local', 'WakdoAdmin2026!');
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await page.goto(`${ADMIN}/admin/health`);
    await expect(page.locator('#health-console-route option').first()).toBeAttached();
  });

  test('avant tout appel, les panneaux de reponse sont fermes', async ({ page }) => {
    await expect(page.locator('#health-console-result')).toBeHidden();
    await expect(page.locator('#health-login-result')).toBeHidden();
    await expect(page.locator('.health-probe-detail').first()).toBeHidden();
    await expect(page.locator('#health-login-token-row')).toBeHidden();
  });

  test('le detail d une sonde montre la reponse reelle du serveur', async ({ page }) => {
    const item = page.locator('li:has([data-probe-run])').first();
    await item.locator('[data-probe-run]').click();
    await expect(item.locator('[data-probe-result]')).not.toHaveText('Pas encore lancé.');
    const toggle = item.locator('[data-probe-detail-toggle]');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    const panel = item.locator('.health-probe-detail');
    await expect(panel).toBeVisible();
    await expect(panel).toContainText('"status"');
  });

  test('la console ne propose que des lectures et appelle avec la session de la page', async ({ page }) => {
    const labels = await page.locator('#health-console-route option').allTextContents();
    expect(labels.length).toBeGreaterThan(10);
    for (const l of labels) expect(l.startsWith('GET ')).toBe(true);

    await consoleCall(page, labels.find((l) => l.startsWith('GET /admin/api/auth/me ')));
    await expect(page.locator('#health-console-resp-status')).toContainText('200');
    await expect(page.locator('#health-console-body')).toContainText('"role_code": "admin"');
  });

  test('un parametre de chemin vide bloque l appel, un parametre rempli est envoye', async ({ page }) => {
    const labels = await page.locator('#health-console-route option').allTextContents();
    await page.selectOption('#health-console-route', { label: labels.find((l) => l.startsWith('GET /admin/api/products/{id} ')) });
    await page.click('#health-console-send');
    await expect(page.locator('#health-console-result')).toBeHidden();
    await expect(page.locator('#health-console-status')).not.toHaveText('');

    await page.locator('#health-console-params input').first().fill('1');
    await page.click('#health-console-send');
    await expect(page.locator('#health-console-result')).toBeVisible();
    await expect(page.locator('#health-console-resp-status')).toContainText('200');
  });

  test('la connexion de demonstration renvoie un jeton sans remplacer la session de la page', async ({ page }) => {
    await page.fill('#health-login-email', 'manager@wakdo.local');
    await page.fill('#health-login-password', 'WakdoManager2026!');
    await page.locator('#health-login-form button[type="submit"]').click();

    await expect(page.locator('#health-login-result')).toBeVisible();
    await expect(page.locator('#health-login-resp-status')).toContainText('200');
    await expect(page.locator('#health-login-body')).toContainText('csrf_token');
    await expect(page.locator('#health-login-body')).toContainText('manager@wakdo.local');
    await expect(page.locator('#health-login-token-row')).toBeVisible();
    await expect(page.locator('#health-login-password')).toHaveValue('');
    await expect(page.locator('#health-login-curl')).toContainText('curl -c cookies.txt');
    await expect(page.locator('#health-login-curl')).not.toContainText('WakdoManager2026!');

    const labels = await page.locator('#health-console-route option').allTextContents();
    await consoleCall(page, labels.find((l) => l.startsWith('GET /admin/api/auth/me ')));
    await expect(page.locator('#health-console-body')).toContainText('"role_code": "admin"');
    await expect(page.locator('#health-console-body')).not.toContainText('"role_code": "manager"');
  });
});
