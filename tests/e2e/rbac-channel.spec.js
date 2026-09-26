// RG-T12 (canal fixe) : verifie en conditions reelles (navigateur, session HTTP)
// le cloisonnement par canal de commande corrige sur ce chantier :
//  1. un role a canal FIXE (role.order_source) n'accede qu'a la page de son propre
//     canal (/counter/orders ou /drive/orders), l'autre rend 403 ;
//  2. un role SANS canal fixe (admin) garde l'acces aux deux ;
//  3. l'annulation d'une commande d'un canal non visible est refusee AVANT le PIN.
//
// Separe de tests/e2e/rbac-demo.spec.js (prevu par la PR #152, feat/demo-accounts,
// non presente sur cette branche) : ce fichier ne suppose PAS le seed 0009 (comptes
// de demo par role) -- il PROVISIONNE lui-meme, via le formulaire reel
// /admin/users/new (pas d'acces direct a la base), un compte comptoir et un compte
// drive dedies a ce test, avant de les utiliser. Si le seed venait a exister sous
// les memes emails, la creation echouerait proprement (email deja pris) ; ce cas
// n'est pas gere ici (fichier pense pour une pile fraiche, comme demande).
const { test, expect } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const DEMO_PASSWORD = 'RbacChannelE2e2026!';

const COUNTER_DEMO = { email: 'rbac-e2e-counter@wakdo.local', role: 'Équipier comptoir' };
const DRIVE_DEMO = { email: 'rbac-e2e-drive@wakdo.local', role: 'Équipier drive' };

async function login(page, email, password) {
  await page.goto(`${ADMIN}/login`);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.locator('form[action="/login"] button[type="submit"]').click();
  await expect(page.locator('#userMenuBtn')).toBeVisible();
}

async function logout(page) {
  await page.locator('#userMenuBtn').click();
  await page.locator('form[action="/logout"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/login/);
}

const ADMIN_PIN = '4729';

// L'admin de demo (seed 0001) n'a PAS de PIN (pin_hash NULL) : la creation d'un
// utilisateur est une action sensible (RG-T13, modal PIN, cf. pin-modal.js) qui
// echouerait sans PIN pose au prealable. Aucun PIN n'est requis pour DEFINIR un
// PIN (ProfileController::updatePin exige le mot de passe courant, pas un PIN) --
// meme flux que /admin/profile/pin en conditions reelles.
async function ensureAdminHasPin(page) {
  await page.goto(`${ADMIN}/admin/profile/pin`);
  await page.fill('#current_password', ADMIN_PASSWORD);
  await page.fill('#pin', ADMIN_PIN);
  await page.fill('#pin_confirm', ADMIN_PIN);
  await page.locator('form[action="/admin/profile/pin"] button[type="submit"]').click();
}

async function createDemoUser(page, { email, role }) {
  await page.goto(`${ADMIN}/admin/users/new`);
  await page.fill('#email', email);
  await page.fill('#first_name', 'RBAC');
  await page.fill('#last_name', 'E2E');
  await page.selectOption('#role_id', { label: role });
  await page.fill('#password', DEMO_PASSWORD);
  await page.locator('form[action="/admin/users"] button[type="submit"]').click();

  // Action sensible (RG-T13) : le clic ouvre le modal PIN au lieu de soumettre
  // directement (pin-modal.js). On y saisit l'identifiant + PIN de l'admin de
  // session (ensureAdminHasPin l'a pose juste avant), comme un vrai equipier le
  // ferait au clavier.
  await expect(page.locator('.pin-modal-overlay.open')).toBeVisible();
  await page.fill('#pm-email', ADMIN_EMAIL);
  await page.fill('#pm-pin', ADMIN_PIN);
  await page.locator('[data-pm-form] button[type="submit"]').click();

  // Redirection vers /admin/users (creation reussie) : pas d'assertion stricte sur
  // l'URL exacte pour rester tolerant a une eventuelle pagination, mais on verifie
  // qu'on n'est plus sur le formulaire (le champ email resterait rempli avec un
  // message d'erreur sinon).
  await expect(page).not.toHaveURL(/\/admin\/users\/new$/);
}

test.describe('RG-T12 : cloisonnement par canal de commande', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await ensureAdminHasPin(page);
    await createDemoUser(page, COUNTER_DEMO);
    await createDemoUser(page, DRIVE_DEMO);
    await page.close();
  });

  test('un compte drive ne peut pas accéder à /counter/orders (GET) ni y créer une commande (POST)', async ({ page }) => {
    await login(page, DRIVE_DEMO.email, DEMO_PASSWORD);

    const getResp = await page.goto(`${ADMIN}/counter/orders`);
    expect(getResp.status()).toBe(403);

    const getNewResp = await page.goto(`${ADMIN}/counter/orders/new`);
    expect(getNewResp.status()).toBe(403);

    // POST direct (falsification de canal par le chemin) : meme garde applique au
    // formulaire, pas seulement a l'affichage.
    const csrfPage = await page.request.get(`${ADMIN}/drive/orders/new`);
    const csrfHtml = await csrfPage.text();
    const csrfMatch = csrfHtml.match(/name="_csrf" value="([^"]*)"/);
    const postResp = await page.request.post(`${ADMIN}/counter/orders`, {
      form: { _csrf: csrfMatch ? csrfMatch[1] : '', service_mode: 'dine_in', qty_1: '1' },
    });
    expect(postResp.status()).toBe(403);

    await logout(page);
  });

  test('un compte comptoir ne peut pas accéder à /drive/orders (GET)', async ({ page }) => {
    await login(page, COUNTER_DEMO.email, DEMO_PASSWORD);

    const resp = await page.goto(`${ADMIN}/drive/orders`);
    expect(resp.status()).toBe(403);

    // Contre-exemple : son propre canal reste ouvert.
    const ownResp = await page.goto(`${ADMIN}/counter/orders`);
    expect(ownResp.status()).toBe(200);

    await logout(page);
  });

  test('un rôle sans canal fixe (admin) garde l\'accès aux deux pages', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);

    const counterResp = await page.goto(`${ADMIN}/counter/orders`);
    expect(counterResp.status()).toBe(200);

    const driveResp = await page.goto(`${ADMIN}/drive/orders`);
    expect(driveResp.status()).toBe(200);

    await logout(page);
  });

  test('annuler une commande d\'un canal non visible est refusé avant le PIN', async ({ page }) => {
    // Cree une commande comptoir avec le compte comptoir (le sien).
    await login(page, COUNTER_DEMO.email, DEMO_PASSWORD);
    await page.goto(`${ADMIN}/counter/orders/new`);
    const csrf = await page.locator('input[name="_csrf"]').first().getAttribute('value');
    // page.request suit les redirections par defaut : 200 = redirection suivie
    // jusqu'a la liste (creation reussie), pas le 302 brut emis par store().
    const storeResp = await page.request.post(`${ADMIN}/counter/orders`, {
      form: { _csrf: csrf, service_mode: 'dine_in', qty_1: '1' },
    });
    expect(storeResp.status()).toBe(200);
    expect(storeResp.url()).toContain('/counter/orders');

    const listBody = await (await page.request.get(`${ADMIN}/counter/orders`)).text();
    const numberMatch = listBody.match(/>([CK]\d+)<\/strong>/);
    expect(numberMatch).not.toBeNull();
    const orderNumber = numberMatch[1];
    await logout(page);

    // Le compte drive (qui ne voit pas le canal comptoir, role_visible_source)
    // tente d'ouvrir la page de confirmation d'annulation : 403 AVANT tout PIN.
    await login(page, DRIVE_DEMO.email, DEMO_PASSWORD);
    const cancelPageResp = await page.goto(`${ADMIN}/admin/orders/${orderNumber}/cancel`);
    expect(cancelPageResp.status()).toBe(403);
    await logout(page);
  });
});
