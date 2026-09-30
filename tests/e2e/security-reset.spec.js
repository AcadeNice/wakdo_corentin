// Tests de securite -- lien de reinitialisation du mot de passe (OWASP Top 10 2021 A07 ;
// OWASP ASVS 4.0 V2.5 recuperation de compte, V3.3 fin de session).
//
// Phase 2 de tests/e2e/run-security.sh : la phase 1 (security-bruteforce.spec.js) demande
// un lien pour un compte jetable ; sans SMTP, l'application l'ecrit dans son journal
// (App\Auth\LogMailer). Le lanceur y relit l'adresse et le jeton, puis rejoue ce fichier
// avec SEC_RESET_EMAIL et SEC_RESET_TOKEN. Hors de ce lanceur, le fichier est saute.
//
// L'expiration (lien plus vieux que PASSWORD_RESET_TTL) n'est pas attendue ici en temps
// reel : elle est prouvee contre une vraie base, horloge injectee, par
// tests/Integration/Security/PasswordResetExpiryDbTest.php.
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const EMAIL = process.env.SEC_RESET_EMAIL || '';
const TOKEN = process.env.SEC_RESET_TOKEN || '';
const OLD_PASSWORD = 'SecBrute2026!x';
const NEW_PASSWORD = 'SecNouveau2026!y';

async function ctx() {
  return pwRequest.newContext({ extraHTTPHeaders: { 'X-Forwarded-For': '198.51.100.77' } });
}

async function submitReset(c, token, password) {
  const page = await (await c.get(`${ADMIN}/reset_password?token=${encodeURIComponent(token)}`)).text();
  const csrf = page.match(/name="_csrf" value="([^"]+)"/)[1];
  const res = await c.post(`${ADMIN}/reset_password`, {
    form: { _csrf: csrf, token, password, password_confirm: password }, maxRedirects: 0,
  });
  return { status: res.status(), location: res.headers()['location'] || '', body: await res.text() };
}

test.describe.configure({ mode: 'serial' });

test.describe('Lien de reinitialisation du mot de passe', () => {
  test.skip(!EMAIL || !TOKEN, 'phase "reset" de tests/e2e/run-security.sh uniquement (SEC_RESET_EMAIL + SEC_RESET_TOKEN)');

  let before;

  test('un jeton forge (bonne forme, mauvaise valeur) est refuse', async () => {
    const c = await ctx();
    const forged = await submitReset(c, 'a'.repeat(64), NEW_PASSWORD);
    expect(forged.status).toBe(200);
    expect(forged.body).toContain('Lien invalide ou expiré');
    await c.dispose();
  });

  test('le lien du journal change le mot de passe ; l ancien est refuse, le nouveau accepte', async () => {
    before = await ctx();
    const login = await before.post(`${ADMIN}/admin/api/auth/login`, { data: { email: EMAIL, password: OLD_PASSWORD } });
    expect(login.status()).toBe(200);

    const c = await ctx();
    const done = await submitReset(c, TOKEN, NEW_PASSWORD);
    expect(done.status).toBe(302);
    expect(done.location).toBe('/login?reset=ok');

    expect((await c.post(`${ADMIN}/admin/api/auth/login`, { data: { email: EMAIL, password: OLD_PASSWORD } })).status()).toBe(401);
    expect((await c.post(`${ADMIN}/admin/api/auth/login`, { data: { email: EMAIL, password: NEW_PASSWORD } })).status()).toBe(200);
    await c.dispose();
  });

  test('le meme lien ne sert qu une fois', async () => {
    const c = await ctx();
    const again = await submitReset(c, TOKEN, 'EncoreUnAutre2026!z');
    expect(again.status).toBe(200);
    expect(again.body).toContain('Lien invalide ou expiré');
    expect((await c.post(`${ADMIN}/admin/api/auth/login`, { data: { email: EMAIL, password: NEW_PASSWORD } })).status()).toBe(200);
    await c.dispose();
  });

  test('une session ouverte AVANT la reinitialisation est fermee apres', async () => {
    const res = await before.get(`${ADMIN}/admin/api/auth/me`);
    expect(res.status()).toBeLessThan(500);
    expect(res.status()).toBe(401);
  });

  test.afterAll(async () => {
    if (before) await before.dispose();
  });
});

// D-7.a (revue adverse, contre-audit 30/09) : ne depend pas d'un jeton REEL
// (contrairement au describe ci-dessus, phase "reset" uniquement) -- l'en-tete
// Referrer-Policy est pose pour TOUT GET /reset_password, jeton valide ou non
// -- donc joue en phase "main" comme le reste des specs security-*.
test.describe('Referrer-Policy sur la page de reinitialisation (D-7.a)', () => {
  test('en-tete no-referrer, et aucune requete de la page (ressources, envoi du formulaire) ne porte le jeton en Referer', async ({ page }) => {
    // Jeton de forme valide (64 caracteres hexadecimaux) mais fictif : la page
    // se rend (formulaire vide-la-valeur, "Lien invalide" possible a l'envoi),
    // ce qui suffit -- l'en-tete et le Referer ne dependent pas de la validite
    // du jeton en base.
    const token = 'd7a'.padEnd(64, '0');
    const url = `${ADMIN}/reset_password?token=${token}`;

    const seenReferers = [];
    page.on('request', (req) => {
      const referer = req.headers()['referer'];
      if (referer) {
        seenReferers.push({ url: req.url(), referer });
      }
    });

    const response = await page.goto(url);
    expect(response.headers()['referrer-policy']).toBe('no-referrer');

    // Laisse le temps aux ressources de la page (feuille de style, script,
    // logo) de partir : c'est EXACTEMENT le canal que D-7.a ferme.
    await page.waitForLoadState('networkidle');

    // L'envoi du formulaire poste vers /reset_password (meme origine, meme
    // page) : mots de passe valides en longueur (passe la validation cote
    // navigateur) mais differents (refus cote serveur, sans consommer le
    // jeton) -- ce qui compte ici est la requete elle-meme, pas son issue.
    await page.fill('#password', 'longenough1');
    await page.fill('#password_confirm', 'different01');
    await page.click('form[action="/reset_password"] button[type="submit"]');
    await page.waitForLoadState('networkidle');

    expect(seenReferers, JSON.stringify(seenReferers)).toEqual([]);
  });
});
