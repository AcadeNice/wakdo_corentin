// Lot 7 (simplification/plan.md) : liste et formulaire des menus -- bouton de
// suppression en .btn-danger, actions de ligne regroupées avec séparateur
// danger (.row-actions / .row-actions__danger), alignement numérique des prix
// (.table-num), et masquage de "Nouveau menu" / "Modifier" / "Supprimer" selon
// la permission réelle du rôle (menu.create / menu.update / menu.delete --
// balayage 2026-09-26, catégorie "erreur : lien refusé").
//
// Le masquage par rôle SANS permission (Équipier cuisine/comptoir/drive,
// Responsable pour la suppression) n'est pas re-testé ici avec un compte
// dédié : tests/e2e/backoffice-sweep.spec.js (filet commun, non modifié par ce
// lot) crée déjà un compte par rôle et balaie /admin/menus pour chacun -- c'est
// la vérification faisant autorité que les 4 "lien refusé" documentés dans
// rapport.md ont disparu. Ce fichier vérifie ce qui est propre à ce lot : la
// classe CSS exacte, le regroupement visuel, et la non-régression du parcours
// CRUD complet pour un rôle qui a TOUTES les permissions (l'administrateur).
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright');

// Meme jeu de regles que tests/e2e/a11y.spec.js (RGAA = WCAG jusqu'a AA) : ni
// plus, ni moins. /admin/menus et ses pages ne sont pas dans la campagne
// axe-core existante -- ce lot y ajoute une mesure propre a ses pages, pas une
// extension du fichier partage.
const TAGS_WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const ADMIN = 'http://admin.wakdo.test';
const EMAIL = process.env.ADMIN_EMAIL || 'admin@wakdo.local';
const PASSWORD = process.env.ADMIN_PASSWORD || 'WakdoAdmin2026!';
const RUN = Date.now().toString(36);

async function login(page) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page.locator('#userMenuBtn')).toBeVisible();
}

test.describe('Lot 7 -- menus', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('administrateur (toutes permissions) : Nouveau/Modifier/Supprimer restent visibles', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/menus`);
    await expect(page.locator('a[href="/admin/menus/new"]', { hasText: 'Nouveau menu' })).toBeVisible();
    const row = page.locator('tbody tr').first();
    await expect(row.locator('a', { hasText: 'Modifier' })).toBeVisible();
    await expect(row.locator('a', { hasText: 'Supprimer' })).toBeVisible();
  });

  test('les prix (Normal/Maxi) sont alignés à droite (.table-num)', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/menus`);
    const cell = page.locator('tbody tr').first().locator('td.table-num');
    await expect(cell).toHaveCSS('text-align', 'right');
  });

  test('les actions de ligne sont regroupées, "Supprimer" séparé (.row-actions__danger)', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/menus`);
    const row = page.locator('tbody tr').first();
    await expect(row.locator('.row-actions')).toBeVisible();
    await expect(row.locator('.row-actions__danger a', { hasText: 'Supprimer' })).toBeVisible();
  });

  test('page de confirmation de suppression : le bouton "Supprimer définitivement" est en .btn-danger', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/menus`);
    await page.locator('tbody tr').first().locator('a', { hasText: 'Supprimer' }).click();
    await expect(page).toHaveURL(/\/admin\/menus\/\d+\/delete/);
    const submit = page.locator('form button[type="submit"]', { hasText: 'Supprimer définitivement' });
    await expect(submit).toHaveClass(/btn-danger/);
    await expect(submit).not.toHaveClass(/btn-primary/);
  });

  test('création d\'un menu (tuiles de slot, non-régression du slot builder)', async ({ page }) => {
    const menuName = `Lot7 menu ${RUN}`;
    await page.goto(`${ADMIN}/admin/menus`);
    await page.locator('a[href="/admin/menus/new"]').click();
    await expect(page).toHaveURL(/\/admin\/menus\/new/);

    await page.selectOption('#category_id', { label: 'Menus' }).catch(() => page.selectOption('#category_id', { index: 1 }));
    await page.selectOption('#burger_product_id', { index: 1 });
    await page.fill('#name', menuName);
    await page.fill('#price_normal_cents', '8,50');
    await page.fill('#price_maxi_cents', '9,50');
    // "Ordre d'affichage" reste visible sans repli (aucun details.form-advanced
    // sur ce formulaire, voir menus/form.php) : rien à ouvrir avant de le remplir.
    await expect(page.locator('#display_order')).toBeVisible();
    await page.fill('#display_order', '90');

    const slot = page.locator('.slot-block').first();
    await slot.locator('.slot-name').fill('Boisson');
    await slot.locator('.slot-required').check();
    await slot.locator('.slot-option').first().check();

    await page.locator('#menu-form button[type="submit"]').click();
    await expect(page.locator('main .flash[role="status"]')).toHaveText('Menu créé.');
    await expect(page.locator('tbody tr', { hasText: menuName })).toBeVisible();
  });

  test('bascule Activer/Désactiver toujours fonctionnelle (non-régression)', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/menus`);
    const row = page.locator('tbody tr').first();
    const before = (await row.locator('.row-actions button', { hasText: /^(Activer|Désactiver)$/ }).textContent()).trim();
    await row.locator('.row-actions button', { hasText: /^(Activer|Désactiver)$/ }).click();
    await expect(page.locator('main .flash[role="status"]')).toBeVisible();
    const after = (await row.locator('.row-actions button', { hasText: /^(Activer|Désactiver)$/ }).textContent()).trim();
    expect(after, 'le libellé du bouton bascule').not.toBe(before);
    // Remet l'état d'origine pour ne pas fausser les vérifications suivantes.
    await row.locator('.row-actions button', { hasText: /^(Activer|Désactiver)$/ }).click();
  });

  test('accessibilité mesurée (axe-core, WCAG AA) : liste, formulaire et suppression des menus', async ({ page }) => {
    await page.goto(`${ADMIN}/admin/menus`);
    let res = await new AxeBuilder({ page }).withTags(TAGS_WCAG_AA).analyze();
    expect(res.violations, JSON.stringify(res.violations.map((v) => v.id))).toEqual([]);

    await page.goto(`${ADMIN}/admin/menus/new`);
    res = await new AxeBuilder({ page }).withTags(TAGS_WCAG_AA).analyze();
    expect(res.violations, JSON.stringify(res.violations.map((v) => v.id))).toEqual([]);

    await page.goto(`${ADMIN}/admin/menus`);
    await page.locator('tbody tr').first().locator('a', { hasText: 'Supprimer' }).click();
    await expect(page).toHaveURL(/\/admin\/menus\/\d+\/delete/);
    res = await new AxeBuilder({ page }).withTags(TAGS_WCAG_AA).analyze();
    expect(res.violations, JSON.stringify(res.violations.map((v) => v.id))).toEqual([]);
  });
});
