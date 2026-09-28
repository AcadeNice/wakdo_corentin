// Pages d'erreur du back-office (App\Core\ErrorResponse), en vrai navigateur contre une pile
// jetable : une adresse inconnue affiche une page lisible par un equipier (plus de JSON brut),
// sans detail interne, et conforme aux regles d'accessibilite mesurees ailleurs (axe-core,
// WCAG 2.0/2.1 A et AA). L'API, elle, garde son enveloppe JSON.
// Identifiants publics des comptes de demonstration (db/seeds/0001, docs/demo/comptes-demo.md).
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const ADMIN = 'http://admin.wakdo.test';

test.describe('Pages d erreur du back-office', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${ADMIN}/login`);
    await page.fill('#email', 'admin@wakdo.local');
    await page.fill('#password', 'WakdoAdmin2026!');
    await page.locator('form[action="/login"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });

  test('une adresse inconnue affiche une page lisible, accessible, sans JSON', async ({ page }) => {
    const res = await page.goto(`${ADMIN}/admin/adresse-qui-n-existe-pas`);
    expect(res.status()).toBe(404);
    expect(res.headers()['content-type']).toContain('text/html');
    await expect(page.locator('h1')).toHaveText('Page introuvable');
    await expect(page.locator('a[href="/admin/dashboard"]')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('NOT_FOUND');

    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => v.id)).toEqual([]);
  });

  test('une adresse qui n existe que pour un envoi de formulaire affiche aussi une page introuvable', async ({ page }) => {
    const res = await page.goto(`${ADMIN}/admin/products/1`);
    expect(res.status()).toBe(405);
    await expect(page.locator('h1')).toHaveText('Page introuvable');
  });

  test('l API garde son enveloppe JSON', async ({ request }) => {
    const res = await request.get(`${ADMIN}/admin/api/adresse-inconnue`);
    expect(res.status()).toBe(404);
    expect(await res.json()).toEqual({ data: null, error: { code: 'NOT_FOUND', message: 'Resource not found' } });
  });
});
