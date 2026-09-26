// Lot 6 (simplification/plan.md) : liste et formulaire des categories --
// alignement numerique de la colonne Ordre, retrait de la colonne "Reference"
// (slug technique affiche seul, sans utilite pour un equipier -- balayage
// 2026-09-26, categorie "texte technique"), verification que les fleches
// Monter/Descendre restent utilisables au doigt (>= 24x24 px, WCAG 2.2 2.5.8)
// et etiquetees, actions de ligne regroupees (.row-actions), formulaire
// raccourci (chemin d'image de secours replie dans un <details>).
//
// Hors perimetre de ce fichier : la SOUMISSION du formulaire categorie
// (creation/edition). Ce formulaire est enctype="multipart/form-data" et
// Request::formBody() ne le parse pas cote serveur pour ce type de corps
// (BUG-01, documente dans rapport.md, hors perimetre visuel de ce lot) --
// toute soumission via un vrai navigateur echoue AUJOURD'HUI pour une raison
// sans rapport avec ce lot. Les verifications ci-dessous restent donc soit en
// GET (navigation, mesure), soit sur des actions qui ne sont pas multipart
// (bascule visible/masquee, deplacement Monter/Descendre).
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright');

// Meme jeu de regles que tests/e2e/a11y.spec.js (RGAA = WCAG jusqu'a AA) : ni
// plus, ni moins. /admin/categories et son formulaire ne sont pas dans la
// campagne axe-core existante (ni celle de lot 0, ni a11y.spec.js) -- ce lot y
// ajoute une mesure propre a ses deux pages, pas une extension du fichier
// partage.
const TAGS_WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const ADMIN = 'http://admin.wakdo.test';
const EMAIL = process.env.ADMIN_EMAIL || 'admin@wakdo.local';
const PASSWORD = process.env.ADMIN_PASSWORD || 'WakdoAdmin2026!';

async function login(page) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page.locator('#userMenuBtn')).toBeVisible();
}

test.describe('Lot 6 -- catégories', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('la colonne "Référence" (slug technique) a disparu de la liste', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/categories`);
    await expect(page.locator('table thead th', { hasText: 'Référence' })).toHaveCount(0);
    // Le libelle et l'ordre restent, seuls a identifier la categorie ici.
    await expect(page.locator('table thead th', { hasText: 'Libellé' })).toHaveCount(1);
    await expect(page.locator('table thead th', { hasText: 'Ordre' })).toHaveCount(1);
  });

  test('la colonne Ordre est alignée à droite (.table-num)', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/categories`);
    const cell = page.locator('td.order-cell').first();
    await expect(cell).toHaveClass(/table-num/);
    await expect(cell).toHaveCSS('text-align', 'right');
  });

  test('les flèches Monter/Descendre restent utilisables au doigt et étiquetées (390px)', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${ADMIN}/admin/categories`);
    const arrows = page.locator('button.btn-order');
    const count = await arrows.count();
    expect(count, 'au moins une paire de flèches sur la liste seedée').toBeGreaterThan(0);
    for (let i = 0; i < count; i += 1) {
      const arrow = arrows.nth(i);
      // Une extrémité de liste désactive une flèche (premier/dernier rang) : elle
      // reste dans le DOM mais n'a pas a etre mesuree comme cible active.
      if (await arrow.isDisabled()) continue;
      const box = await arrow.boundingBox();
      expect(box.width, `flèche ${i} largeur >= 24px (WCAG 2.5.8)`).toBeGreaterThanOrEqual(24);
      expect(box.height, `flèche ${i} hauteur >= 24px (WCAG 2.5.8)`).toBeGreaterThanOrEqual(24);
      await expect(arrow, `flèche ${i} porte un aria-label`).toHaveAttribute('aria-label', /.+/);
    }
  });

  test('les actions de ligne (Modifier / Masquer) sont regroupées (.row-actions)', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/categories`);
    const row = page.locator('tbody tr').first();
    await expect(row.locator('.row-actions')).toBeVisible();
    await expect(row.locator('.row-actions a', { hasText: 'Modifier' })).toBeVisible();
    await expect(row.locator('.row-actions button', { hasText: /^(Masquer|Afficher)$/ })).toBeVisible();
  });

  test('bascule Masquer/Afficher toujours fonctionnelle (non-régression)', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/categories`);
    const row = page.locator('tbody tr').first();
    const before = (await row.locator('button', { hasText: /^(Masquer|Afficher)$/ }).textContent()).trim();
    await row.locator('button', { hasText: /^(Masquer|Afficher)$/ }).click();
    await expect(page.locator('main .flash[role="status"]')).toBeVisible();
    const after = (await row.locator('button', { hasText: /^(Masquer|Afficher)$/ }).textContent()).trim();
    expect(after, 'le libellé du bouton bascule').not.toBe(before);
    // Remet l'état d'origine : ne pas laisser une catégorie seedée masquée pour
    // les vérifications suivantes (sur cette même pile jetable).
    await row.locator('button', { hasText: /^(Masquer|Afficher)$/ }).click();
  });

  test('formulaire catégorie (création) : le chemin d\'image de secours est replié et s\'ouvre au clic', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/categories/new`);
    const details = page.locator('details.form-advanced');
    await expect(details).toHaveCount(1);
    expect(await details.evaluate((el) => el.open), 'replié par défaut à la création').toBe(false);
    await expect(page.locator('#image_path')).toBeHidden();
    await details.locator('summary').click();
    expect(await details.evaluate((el) => el.open), 'ouvert après clic sur le résumé').toBe(true);
    await expect(page.locator('#image_path')).toBeVisible();
  });

  test('formulaire catégorie (édition) : le champ replié reflète s\'il porte déjà une valeur', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/categories`);
    await page.locator('tbody tr').first().locator('a', { hasText: 'Modifier' }).click();
    await expect(page).toHaveURL(/\/admin\/categories\/\d+\/edit/);
    const value = (await page.inputValue('#image_path')).trim();
    const isOpen = await page.locator('details.form-advanced').first().evaluate((el) => el.open);
    expect(isOpen, 'ouvert si et seulement si une valeur est déjà présente').toBe(value !== '');
  });

  test('"Ordre d\'affichage" reste visible, non replié (garde le parcours du balayage transversal)', async ({ page }) => {
    // tests/e2e/backoffice-sweep.spec.js (filet commun, non modifiable par ce lot)
    // remplit #display_order en aveugle : il doit rester atteignable sans clic
    // préalable, a la creation comme a l'edition.
    await page.goto(`${ADMIN}/admin/categories/new`);
    await expect(page.locator('#display_order')).toBeVisible();
    await page.goto(`${ADMIN}/admin/categories`);
    await page.locator('tbody tr').first().locator('a', { hasText: 'Modifier' }).click();
    await expect(page.locator('#display_order')).toBeVisible();
  });

  test('accessibilité mesurée (axe-core, WCAG AA) : liste et formulaire des catégories', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/categories`);
    let res = await new AxeBuilder({ page }).withTags(TAGS_WCAG_AA).analyze();
    expect(res.violations, JSON.stringify(res.violations.map((v) => v.id))).toEqual([]);

    await page.goto(`${ADMIN}/admin/categories/new`);
    res = await new AxeBuilder({ page }).withTags(TAGS_WCAG_AA).analyze();
    expect(res.violations, JSON.stringify(res.violations.map((v) => v.id))).toEqual([]);

    // Le resume replie est aussi mesure ouvert (contenu revele au clic).
    await page.locator('details.form-advanced summary').click();
    res = await new AxeBuilder({ page }).withTags(TAGS_WCAG_AA).analyze();
    expect(res.violations, JSON.stringify(res.violations.map((v) => v.id))).toEqual([]);
  });
});
