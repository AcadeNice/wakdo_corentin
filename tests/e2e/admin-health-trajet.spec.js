// Page Sante (/admin/health) : le trajet d'un appel affiche des reponses REELLES, jouees en
// vrai navigateur contre une pile jetable. Les tests JS (tests/js/health.test.js) prouvent la
// logique sur un faux DOM ; ce fichier prouve ce que seul un vrai serveur montre :
//   - une lecture lancee part pour de vrai et affiche la reponse du serveur ;
//   - une ecriture (passer commande) n'est JAMAIS envoyee depuis la page : le trajet montre
//     la reponse capturee sur une pile de test (src/app/Health/captured-responses.json) ;
//   - le refus « sans session » d'une route JSON part sans le cookie et rend le vrai 401 ;
//   - aucun corps invente (l'ancien « { … } ») ne s'affiche plus.
// Identifiants publics des comptes de demonstration (db/seeds/0001, docs/demo/comptes-demo.md).
const { test, expect } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';

test.describe('Page Sante : trajet d un appel avec les vraies reponses', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${ADMIN}/login`);
    await page.fill('#email', 'admin@wakdo.local');
    await page.fill('#password', 'WakdoAdmin2026!');
    await page.locator('form[action="/login"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(`${ADMIN}/admin/health`);
    await expect(page.locator('#health-presets .health-chip').first()).toBeVisible();
  });

  test('une lecture lancee part pour de vrai et affiche la reponse du serveur', async ({ page }) => {
    const reads = [];
    page.on('request', (r) => { if (new URL(r.url()).pathname === '/api/products') reads.push(r.method()); });
    await page.locator('#health-presets .health-chip', { hasText: 'lire le catalogue' }).click();

    await expect(page.locator('#health-status')).toContainText('200');
    await expect(page.locator('#health-respsource')).toContainText('Appel réel');
    await expect(page.locator('#health-respbody')).toContainText('"data"');
    await expect(page.locator('#health-reqbody')).toContainText('GET /api/products');
    expect(reads).toEqual(['GET']);
  });

  test('passer commande montre la reponse capturee et n envoie rien', async ({ page }) => {
    const writes = [];
    page.on('request', (r) => { if (r.method() !== 'GET' && new URL(r.url()).pathname.startsWith('/api/orders')) writes.push(r.url()); });
    await page.locator('#health-presets .health-chip', { hasText: 'passer commande' }).click();

    await expect(page.locator('#health-status')).toContainText('201');
    await expect(page.locator('#health-respsource')).toContainText('capturée');
    await expect(page.locator('#health-respsource')).toContainText('écrirait en base');
    await expect(page.locator('#health-respbody')).toContainText('order_number');
    await expect(page.locator('#health-reqbody')).toContainText('POST /api/orders');
    expect(writes).toEqual([]);
  });

  test('le refus sans session d une route JSON rend le vrai 401', async ({ page }) => {
    await page.locator('#health-presets .health-chip', { hasText: 'appel sans session' }).click();

    await expect(page.locator('#health-status')).toContainText('401');
    await expect(page.locator('#health-respbody')).toContainText('AUTH_REQUIRED');
    await expect(page.locator('#health-respsource')).toContainText('sans le cookie de session');
    await expect(page.locator('[data-step-id="session"]')).toHaveClass(/health-step--failed/);
  });

  test('aucun corps invente sur les cinq appels types', async ({ page }) => {
    const chips = page.locator('#health-presets .health-chip');
    const n = await chips.count();
    for (let i = 0; i < n; i++) {
      await chips.nth(i).click();
      await expect(page.locator('#health-status')).not.toHaveText('en route…');
      await expect(page.locator('#health-respsource')).not.toHaveText('');
      await expect(page.locator('#health-respbody')).not.toContainText('{ … }');
      await expect(page.locator('#health-respbody')).not.toContainText('[ … ]');
    }
  });
});
