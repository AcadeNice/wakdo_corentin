// ERG-03 (audit UX pre-soutenance, rapport annexe ux-backoffice) : sur tablette
// portrait (768px), le panneau commande du POS comptoir/drive passe en flux normal
// SOUS la grille de produits (admin.css, @media max-width: 860px) ; son pied (bouton
// "Encaisser") pouvait alors chevaucher le bouton fixe "Police adaptee" (.a11y-toggle)
// DES LE CHARGEMENT, sans le moindre defilement -- confirme par calcul de zones avant
// correctif. Meme methode que le test de non-regression existant sur /admin/categories
// (tests/e2e/admin.spec.js, "le bouton Police adaptee ne recouvre aucun bouton
// d'action") : mesure des deux boites, chevauchement calcule geometriquement plutot
// que juge sur une capture. Un seul viewport par test (Playwright ne permet pas de
// changer le viewport en cours de page) ; 768 est le cas a risque (rapport), 1366 est
// le cas de garde-fou (panneau en colonne fixe a droite, aucune raison de chevaucher).
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

function overlaps(a, b) {
  return a && b
    && a.x < b.x + b.width
    && a.x + a.width > b.x
    && a.y < b.y + b.height
    && a.y + a.height > b.y;
}

for (const path of ['/counter/orders/new', '/drive/orders/new']) {
  test(`${path} a 768px : le bouton Police adaptee ne recouvre pas Encaisser (sans defiler)`, async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 });
    await login(page);

    await page.goto(`${ADMIN}${path}`);
    const toggle = page.locator('.a11y-toggle');
    const payButton = page.locator('#order-submit');
    await expect(toggle).toBeVisible();
    await expect(payButton).toBeVisible();

    // Aucun defilement ici, expres : le rapport ERG-03 constate le chevauchement
    // DES LE CHARGEMENT (capture pleine page 768x1024), pas apres une action de
    // l'equipier -- au repos est le cas qui compte pour ce correctif.
    const toggleBox = await toggle.boundingBox();
    const payBox = await payButton.boundingBox();

    expect(overlaps(toggleBox, payBox)).toBe(false);
  });

  test(`${path} a 1366px : pas de chevauchement (panneau en colonne fixe, garde-fou)`, async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await login(page);

    await page.goto(`${ADMIN}${path}`);
    const toggle = page.locator('.a11y-toggle');
    const payButton = page.locator('#order-submit');
    await expect(toggle).toBeVisible();
    await expect(payButton).toBeVisible();

    const toggleBox = await toggle.boundingBox();
    const payBox = await payButton.boundingBox();

    expect(overlaps(toggleBox, payBox)).toBe(false);
  });
}
