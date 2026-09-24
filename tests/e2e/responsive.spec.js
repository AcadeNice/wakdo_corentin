// E2E responsive (Cr 1.b.1) et controle de saisie (Cr 2.b.1), dans un vrai Chromium.
//
// 1. Aucune page ne defile horizontalement a 360 et 390 px de large (telephones
//    courants en portrait), borne ET back-office, ni a 768 px (tablette) pour la borne.
//    Ce test a revele le 2026-09-23 que la page produits de la borne debordait de
//    872 a 902 px sous 900 px de large (bandeau de categories) : corrige dans style.css.
// 2. Sous 640 px, le menu lateral du back-office passe en bande au-dessus du contenu,
//    qui prend toute la largeur. Le back-office ne defile pas au niveau du document
//    (.admin-layout : hauteur fixe, overflow hidden) : c'est la zone de contenu
//    main.content qui defile, c'est donc elle qu'on mesure. Les tableaux larges
//    defilent dans leur propre cadre, ce qui est voulu. La page courante doit rester
//    visible dans la bande.
// 3. Le controle de saisie signale un ecart pendant la frappe, et le modal PIN s'ouvre
//    a l'envoi d'un formulaire d'action sensible (regression du 2026-09-23 : des champs
//    PIN masques mais required bloquaient l'envoi avant le modal).
//
// Captures optionnelles pour le dossier de preuves : CAPTURES_DIR=<dossier>.
const { test, expect } = require('@playwright/test');
const path = require('path');

const ADMIN = 'http://admin.wakdo.test';
// Identifiants DEV seedes (db/seeds/0001) ; a changer en prod.
const EMAIL = 'admin@wakdo.local';
const PASSWORD = 'WakdoAdmin2026!';
const PHONE_WIDTHS = [360, 390];
const BORNE_WIDTHS = [360, 390, 768];

const BORNE_PAGES = [
  ['accueil', '/index.html'],
  ['categories', '/categories.html?mode=sur-place'],
  ['produits', '/products.html?category=2&mode=sur-place'],
  ['paiement', '/payment.html'],
  ['confirmation', '/confirmation.html'],
];
const ADMIN_PAGES = [
  ['tableau-de-bord', '/admin/dashboard'],
  ['ingredients', '/admin/ingredients'],
  ['produits', '/admin/products'],
  ['nouveau-produit', '/admin/products/new'],
  ['produits-par-categorie', '/admin/products/by-category'],
  ['categories', '/admin/categories'],
  ['menus', '/admin/menus'],
  ['nouveau-menu', '/admin/menus/new'],
  ['commandes', '/admin/orders'],
  ['saisie-commande', '/counter/orders'],
  ['cuisine', '/kitchen/display'],
  ['statistiques', '/admin/stats'],
  ['utilisateurs', '/admin/users'],
  ['roles', '/admin/roles'],
  ['mon-pin', '/admin/profile/pin'],
];

async function login(page) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin\/dashboard/);
}

/** Largeur de defilement moins largeur visible : 0 = pas de defilement lateral. */
async function horizontalOverflow(page, selector = null) {
  return page.evaluate((sel) => {
    const el = sel ? document.querySelector(sel) : document.documentElement;
    return el.scrollWidth - el.clientWidth;
  }, selector);
}

async function capture(page, name) {
  if (!process.env.CAPTURES_DIR) return;
  await page.screenshot({ path: path.join(process.env.CAPTURES_DIR, `${name}.png`), fullPage: false });
}

for (const width of BORNE_WIDTHS) {
  test.describe(`borne ${width} px`, () => {
    test.use({ viewport: { width, height: 800 } });

    test('aucune page ne defile horizontalement', async ({ page }) => {
      for (const [name, url] of BORNE_PAGES) {
        await page.goto(url);
        await page.waitForLoadState('networkidle');
        expect(await horizontalOverflow(page), `${name} a ${width} px`).toBeLessThanOrEqual(0);
        await capture(page, `borne-${name}-${width}`);
      }
    });
  });
}

for (const width of PHONE_WIDTHS) {
  test.describe(`back-office ${width} px`, () => {
    test.use({ viewport: { width, height: 800 } });

    test('pas de defilement lateral, menu en bande au-dessus du contenu', async ({ page }) => {
      await login(page);
      for (const [name, url] of ADMIN_PAGES) {
        await page.goto(`${ADMIN}${url}`);
        expect(await horizontalOverflow(page), `${name} a ${width} px (document)`).toBeLessThanOrEqual(0);
        expect(await horizontalOverflow(page, 'main.content'), `${name} a ${width} px (zone de contenu)`).toBeLessThanOrEqual(0);

        const active = page.locator('nav.sidebar .sidebar-item.active');
        if (await active.count() > 0) {
          const box = await active.first().boundingBox();
          expect(box.x, `${name} : page courante visible dans la bande`).toBeGreaterThanOrEqual(0);
          expect(box.x + box.width, `${name} : page courante visible dans la bande`).toBeLessThanOrEqual(width + 1);
        }

        const sidebar = await page.locator('nav.sidebar').boundingBox();
        const content = await page.locator('main.content').boundingBox();
        expect(sidebar.width, 'menu sur toute la largeur').toBeGreaterThanOrEqual(width - 1);
        expect(content.width, 'contenu sur toute la largeur').toBeGreaterThanOrEqual(width - 1);
        expect(sidebar.y + sidebar.height, 'menu au-dessus du contenu').toBeLessThanOrEqual(content.y + 1);
        await capture(page, `admin-${name}-${width}`);
      }
    });
  });
}

test('back-office large : le menu lateral reste une colonne a gauche du contenu', async ({ page }) => {
  await page.setViewportSize({ width: 1366, height: 800 });
  await login(page);
  const sidebar = await page.locator('nav.sidebar').boundingBox();
  const content = await page.locator('main.content').boundingBox();
  expect(sidebar.x + sidebar.width).toBeLessThanOrEqual(content.x + 1);
  expect(sidebar.width).toBeLessThan(300);
});

test('controle de saisie : un ecart est signale pendant la frappe, puis efface', async ({ page }) => {
  // Prix saisi en EUROS (F40, section "Textes techniques ou en anglais" de
  // defauts-visibles.md) : le champ est passe en type="text" + pattern
  // (products/form.php), le controle en direct suit donc le motif, pas les bornes
  // min/max d'un type="number".
  await login(page);
  await page.goto(`${ADMIN}/admin/products/new`);
  const price = page.locator('#price_cents');
  await price.fill('1,900');
  const error = page.locator('#price_cents-live-error');
  await expect(error).toBeVisible();
  await expect(error).toHaveText('Montant invalide (exemple : 1,90).');
  await expect(price).toHaveAttribute('aria-invalid', 'true');
  await capture(page, 'admin-controle-saisie');

  await price.fill('6,50');
  await expect(error).toBeHidden();
  await expect(price).toHaveAttribute('aria-invalid', 'false');
});

test('action sensible : le modal PIN s ouvre meme sans le controle de saisie', async ({ page }) => {
  // Deux protections contre l'envoi bloque : form-validation.js (novalidate, champs
  // masques ignores) et pin-modal.js (required retire des champs qu'il masque). Ce
  // test coupe la premiere pour prouver que la seconde suffit a elle seule.
  await page.route('**/assets/js/form-validation.js', (route) => route.abort());
  await login(page);
  await page.goto(`${ADMIN}/admin/ingredients`);
  await page.locator('a[href$="/adjust"]').first().click();
  await page.fill('#delta', '5');
  await page.locator('form[action$="/adjust"] button[type="submit"]').click();
  await expect(page.locator('.pin-modal-overlay.open')).toBeVisible();
});

test('action sensible : l envoi ouvre le modal PIN au lieu d etre bloque', async ({ page }) => {
  await login(page);
  await page.goto(`${ADMIN}/admin/ingredients`);
  await page.locator('a[href$="/adjust"]').first().click();
  await expect(page).toHaveURL(/\/admin\/ingredients\/\d+\/adjust/);

  await page.fill('#delta', '5');
  await page.locator('form[action$="/adjust"] button[type="submit"]').click();
  await expect(page.locator('.pin-modal-overlay.open')).toBeVisible();
  await capture(page, 'admin-modal-pin');
});

test('action sensible : apres un PIN refuse, le message du serveur reste visible et le modal le reprend', async ({ page }) => {
  // Page reelle, gabarit complet : form-validation.js puis pin-modal.js. L'administrateur
  // de demonstration n'a pas de PIN : le serveur refuse et recharge le formulaire avec
  // son message, que le bloc masque par le modal cachait avant correctif.
  await login(page);
  await page.goto(`${ADMIN}/admin/ingredients`);
  await page.locator('a[href$="/adjust"]').first().click();
  await page.fill('#delta', '5');
  const save = page.locator('form[action$="/adjust"] button[type="submit"]');
  await save.click();
  await page.fill('#pm-email', EMAIL);
  await page.fill('#pm-pin', '0000');
  await page.locator('[data-pm-form] button[type="submit"]').click();

  const serverError = page.locator('form[action$="/adjust"] .form-error:not(.form-error--live)');
  await expect(serverError).toBeVisible();
  const message = (await serverError.textContent()).trim();
  expect(message.length).toBeGreaterThan(0);

  await save.click();
  await expect(page.locator('[data-pm-error]')).toBeVisible();
  await expect(page.locator('[data-pm-error]')).toHaveText(message);
  await capture(page, 'admin-modal-pin-refuse');
});
