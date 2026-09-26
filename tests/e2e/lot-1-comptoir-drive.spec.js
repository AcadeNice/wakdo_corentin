// Lot 1 (comptoir / drive) : non-regression propre a ce lot.
//  1. ERG-01 : le composeur de menu (counter-order.js, openComposer) se pilote
//     entierement par tap sur des tuiles .pos-tile, plus aucun <select> dans la
//     modale (design-system.md 2.6, plan.md 2.1).
//  2. Chevauchement service_tag / .pos__panel-foot (croisement lot 0 x PR #156) :
//     mesure par calcul de zones, meme methode que tests/e2e/counter-pos.spec.js.
//  3. Ligne mise en evidence apres creation + encaissement (design-system.md 2.6,
//     cadre reduit a cette vue -- voir le commentaire dans admin.css).
//
// Lancement (pile jetable) :
//   _byan-output/outils/e2e.sh <depot> <nom-unique> tests/e2e/lot-1-comptoir-drive.spec.js
const { test, expect } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const EMAIL = 'admin@wakdo.local';
const PASSWORD = 'WakdoAdmin2026!';

async function login(page) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page.locator('#userMenuBtn')).toBeVisible();
}

// Meme calcul geometrique que tests/e2e/counter-pos.spec.js (chevauchement booleen).
function overlaps(a, b) {
  return a && b
    && a.x < b.x + b.width
    && a.x + a.width > b.x
    && a.y < b.y + b.height
    && a.y + a.height > b.y;
}

// Part masquee de `a` par `b` (0..1), meme calcul que le balayage back-office
// (tests/e2e/backoffice-sweep/audit-page.js, categorie "masquage fixe") : proportion
// de la surface de `a` recouverte par `b`.
function overlapRatio(a, b) {
  if (!a || !b) {
    return 0;
  }
  const ix = Math.max(0, Math.min(a.x + a.width, b.x + b.width) - Math.max(a.x, b.x));
  const iy = Math.max(0, Math.min(a.y + a.height, b.y + b.height) - Math.max(a.y, b.y));
  const areaA = a.width * a.height;
  return areaA > 0 ? (ix * iy) / areaA : 0;
}

test.describe('ERG-01 : composeur de menu en tuiles', () => {
  test('composer un Menu Big Mac en Maxi (Coca Sans Sucres, Potatoes) uniquement par taps, aucun select restant', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await login(page);
    await page.goto(`${ADMIN}/counter/orders/new`);

    await page.getByRole('tab', { name: 'Menus', exact: true }).click();
    await page.locator('.pos-tile', { hasText: 'Menu Big Mac' }).click();

    const modal = page.locator('#menu-composer-modal');
    await expect(modal).not.toHaveAttribute('hidden', '');

    // Critere de reussite (plan.md 2.1) : aucun <select> restant dans la modale.
    await expect(modal.locator('select')).toHaveCount(0);
    await expect(modal.locator('.pos-tile')).not.toHaveCount(0);

    // Accompagnement et boisson d'abord, PENDANT que le format est encore Normal (le
    // libelle des tuiles de slot est encore le nom de base) ; le format Maxi vient en
    // dernier et relibelle les tuiles DEJA choisies sans changer leur valeur (ERG-01).
    const sideGroup = modal.locator('.menu-composer__slot', { hasText: 'Accompagnement' }).locator('.pos-tile-group');
    await sideGroup.locator('.pos-tile', { hasText: /^Potatoes$/ }).click();

    const drinkGroup = modal.locator('.menu-composer__slot', { hasText: 'Boisson' }).locator('.pos-tile-group');
    await drinkGroup.locator('.pos-tile', { hasText: /^Coca Sans Sucres$/ }).click();

    // Format Maxi (tuile, plus le bouton radio d'origine) -- devient "Grande
    // Potatoes" / "Coca Sans Sucres 50cl" a l'affichage, meme selection sous le capot.
    await modal.locator('.menu-composer__format-tiles .pos-tile', { hasText: 'Maxi' }).click();
    await expect(sideGroup.locator('.pos-tile.is-selected')).toHaveText('Grande Potatoes');
    await expect(drinkGroup.locator('.pos-tile.is-selected')).toHaveText('Coca Sans Sucres 50cl');

    await modal.locator('.menu-composer__add').click();
    await expect(modal).toHaveAttribute('hidden', '');

    const label = page.locator('.order-cart__label').first();
    await expect(label).toContainText('Menu Big Mac (Maxi)');
    await expect(label).toContainText('Grande Potatoes');
    await expect(label).toContainText('Coca Sans Sucres 50cl');
  });
});

test.describe('Croisement lot 0 x PR #156 : service_tag vs .pos__panel-foot collant', () => {
  for (const path of ['/counter/orders/new', '/drive/orders/new']) {
    for (const size of [{ width: 768, height: 1024 }, { width: 390, height: 844 }]) {
      test(`${path} a ${size.width}px : le champ Table n est pas masque par le pied du panneau`, async ({ page }) => {
        await page.setViewportSize(size);
        await login(page);
        await page.goto(`${ADMIN}${path}`);

        const tag = page.locator('#service_tag_group');
        const foot = page.locator('.pos__panel-foot');
        await expect(foot).toBeVisible();

        // Au drive, service_tag n'existe pas (RG-T09, mode fige) : rien a verifier ici,
        // seul le pied de panneau doit rester visible et fonctionnel.
        if (await tag.count() === 0) {
          await expect(page.locator('#order-submit')).toBeVisible();
          return;
        }
        await expect(tag).toBeVisible();

        const tagBox = await tag.boundingBox();
        const footBox = await foot.boundingBox();
        const ratio = overlapRatio(tagBox, footBox);

        expect(overlaps(tagBox, footBox) && ratio > 0, `chevauchement Table/pied de panneau : ${Math.round(ratio * 100)} %`).toBe(false);
      });
    }
  }
});

test.describe('Ligne mise en evidence apres creation + encaissement', () => {
  test('une commande comptoir tout juste creee est signalee dans "En cours" ET l historique (couleur + texte)', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await login(page);
    await page.goto(`${ADMIN}/counter/orders/new`);

    // Boisson simple (ajout direct par tap, sans modale) : suffit a passer une
    // commande valide -- aria-haspopup distingue une tuile "a composer" d'un ajout
    // direct (D, deja couvert par tests/js/counter-order.test.js).
    await page.getByRole('tab', { name: 'Boissons', exact: true }).click();
    await page.locator('.pos-tile:not([aria-haspopup])').first().click();

    await page.locator('#order-submit').click();
    await expect(page).toHaveURL(/\/counter\/orders\?highlight=/);

    // La commande fraichement creee apparait dans LES DEUX tableaux (En cours, deja
    // paid/preparing ; Historique recent, qui ramene tous les statuts) : les deux
    // lignes sont signalees, pas seulement l'une des deux.
    const rows = page.locator('tr.row-highlight');
    await expect(rows).toHaveCount(2);
    // Pas seulement la couleur (WCAG 1.4.1) : un texte porte aussi l'information.
    await expect(rows.first().locator('.row-highlight__tag')).toHaveText('Nouveau');
    await expect(rows.last().locator('.row-highlight__tag')).toHaveText('Nouveau');
  });
});
