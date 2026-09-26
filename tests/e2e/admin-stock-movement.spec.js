// Regression bug back-office (stock, 2026-09-26) : "quand on fait un mouvement, il
// ne se passe rien". Deux causes reelles trouvees en investiguant :
//
//  1. Le jeu de donnees de demonstration seede TOUS les ingredients a EXACTEMENT
//     100% de leur capacite (verifie en base : 50 ingredients / 50 a capacite). Le
//     tout premier reappro ou ajustement positif tente sur une installation neuve
//     est donc TOUJOURS plafonne a +0 -- un succes (302 + flash) sans le moindre
//     changement visible. Corrige : le flash distingue desormais un mouvement
//     plafonne d'un mouvement a plein effet (IngredientController + IngredientRepository).
//
//  2. En production (commit 74d4398, avant PR #146/2a09597, deja corrige avant
//     cette session) : pin-modal.js masquait le fieldset PIN inline SANS d'abord
//     en extraire le message d'erreur du serveur -- un PIN refuse rechargeait
//     alors la meme page SANS AUCUNE indication visible. Verrouille ici au niveau
//     e2e en plus de la couverture JS unitaire existante (pin-modal.test.js).
//
// On navigue DIRECTEMENT par id (comme le ferait un signet, ou les liens
// Ajuster/Inventaire toujours presents sur la liste complete) plutot que de
// cliquer le bouton "Réapprovisionner" du tableau de bord : celui-ci n'apparait
// que sous le seuil bas, absent par construction sur une installation neuve.
const { test, expect } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const EMAIL = 'admin@wakdo.local';
const PASSWORD = 'WakdoAdmin2026!';
const PIN = '3141';
const INGREDIENT_ID = 2; // "Pain sesame", seede a 300/300 (100% de capacite)

async function login(page) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin\/dashboard/);
}

async function setPin(page) {
  await page.goto(`${ADMIN}/admin/profile/pin`);
  await page.fill('#current_password', PASSWORD);
  await page.fill('#pin', PIN);
  await page.fill('#pin_confirm', PIN);
  await page.locator('form.form-card button[type="submit"]').click();
}

async function confirmPin(page) {
  await expect(page.locator('.pin-modal-overlay.open')).toBeVisible();
  await page.fill('#pm-email', EMAIL);
  await page.fill('#pm-pin', PIN);
  await page.locator('[data-pm-form] button[type="submit"]').click();
}

test.describe('Mouvements de stock (back-office)', () => {

  test('reapprovisionnement sur un ingredient deja plein : le message dit clairement que rien n a ete ajoute', async ({ page }) => {
    await login(page);
    await page.goto(`${ADMIN}/admin/ingredients/${INGREDIENT_ID}/restock`);
    await page.fill('#packs', '3');
    await page.locator('form.form-card button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/ingredients$/);
    await expect(page.locator('body')).toContainText('plafonné');
    await expect(page.locator('body')).toContainText('+0');
  });

  test('reapprovisionnement avec de la place reelle enregistre un succes plein effet', async ({ page }) => {
    await login(page);
    await setPin(page);

    // Cree de la place : ajustement negatif (PIN), avant de re-tenter le reappro.
    await page.goto(`${ADMIN}/admin/ingredients/${INGREDIENT_ID}/adjust`);
    await page.fill('#delta', '-50');
    await page.locator('form.form-card button[type="submit"]').click();
    await confirmPin(page);
    await expect(page).toHaveURL(/\/admin\/ingredients$/);

    await page.goto(`${ADMIN}/admin/ingredients/${INGREDIENT_ID}/restock`);
    await page.fill('#packs', '1');
    await page.locator('form.form-card button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/ingredients$/);
    await expect(page.locator('body')).toContainText('Réapprovisionnement enregistré.');
  });

  test('ajustement negatif de stock avec PIN correct enregistre et confirme (succes plein effet)', async ({ page }) => {
    await login(page);
    await setPin(page);
    await page.goto(`${ADMIN}/admin/ingredients/${INGREDIENT_ID}/adjust`);
    // Negatif : l'ingredient de demonstration etant deja a sa capacite, un delta
    // positif clamperait a 0 (voir le test dedie plus haut) -- un delta negatif
    // s'applique integralement, sans plafonnement.
    await page.fill('#delta', '-4');
    await page.locator('form.form-card button[type="submit"]').click();
    await confirmPin(page);

    await expect(page).toHaveURL(/\/admin\/ingredients$/);
    await expect(page.locator('body')).toContainText('Ajustement de stock enregistré.');
  });

  /**
   * LE bug historique reproduit : sur le code de production (74d4398, avant PR
   * #146), cette meme sequence ne montrait RIEN apres un PIN refuse -- ni erreur,
   * ni modal, la page revenait juste identique. Verrouille le comportement CORRIGE.
   */
  test('ajustement avec PIN incorrect affiche un message d erreur visible (pas un echec silencieux)', async ({ page }) => {
    await login(page);
    await setPin(page);
    await page.goto(`${ADMIN}/admin/ingredients/${INGREDIENT_ID}/adjust`);
    await page.fill('#delta', '2');
    await page.locator('form.form-card button[type="submit"]').click();
    await expect(page.locator('.pin-modal-overlay.open')).toBeVisible();

    await page.fill('#pm-email', EMAIL);
    await page.fill('#pm-pin', '0000'); // PIN volontairement faux
    await page.locator('[data-pm-form] button[type="submit"]').click();

    const errorMessage = page.locator('.form-error', { hasText: 'Email ou PIN invalide' });
    await expect(errorMessage).toBeVisible();
  });

  test('inventaire au-dessus de la capacite est plafonne et le dit clairement', async ({ page }) => {
    await login(page);
    await setPin(page);
    await page.goto(`${ADMIN}/admin/ingredients/${INGREDIENT_ID}/inventory`);
    await page.fill('#actual_quantity', '5000'); // tres au-dessus de la capacite (300)
    await page.locator('form.form-card button[type="submit"]').click();
    await confirmPin(page);

    await expect(page).toHaveURL(/\/admin\/ingredients$/);
    await expect(page.locator('body')).toContainText('plafonné');
  });
});
