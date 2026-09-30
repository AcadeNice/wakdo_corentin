// Tests de securite -- force brute et enumeration de comptes. OWASP Top 10 2021 A07
// Identification and Authentication Failures ; OWASP ASVS 4.0 V2.2.1 (protections contre
// la force brute) et V2.5 (recuperation de compte).
//
// Base existante, non dupliquee : la courbe de blocage (seuil, delai de base, plafond) est
// testee unitairement (tests/Unit/Auth/ThrottlePolicyTest.php, PinThrottleTest.php) et
// contre une vraie base (tests/Integration/AuthServiceDbTest.php, PinThrottleDbTest.php) ;
// la neutralite de la demande de reinitialisation l'est en unitaire
// (tests/Unit/Auth/PasswordResetServiceTest.php, PasswordResetControllerTest.php).
//
// Ajoute ici, de bout en bout sur la pile jetable :
//  - message identique pour un compte existant (mot de passe faux) et un compte inconnu ;
//  - verrou PAR COMPTE apres 5 echecs, qui tient meme depuis une autre adresse IP et meme
//    avec le bon mot de passe, sans se distinguer d'un echec ordinaire ;
//  - verrou PAR IP apres 20 echecs (429 + Retry-After), qui n'affecte pas les autres IP ;
//  - PIN d'action sensible bloque apres 5 echecs, meme un PIN juste ensuite ;
//  - reponse identique a "mot de passe oublie" que l'adresse existe ou non.
//
// ISOLATION : l'application lit l'IP cliente dans le DERNIER element de X-Forwarded-For
// (App\Core\Request::clientIp, pose par Traefik en production). Chaque test pose sa propre
// adresse de documentation (RFC 5737, 198.51.100.0/24 et 203.0.113.0/24) : les compteurs
// par IP d'un test ne bloquent ni les autres tests ni les autres specs. Les comptes
// verrouilles sont des comptes jetables crees ici (emails uniques).
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const ADMIN_PIN = '4729';
const RUN = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e6).toString(36)}`;
const TEMP_PASSWORD = 'SecBrute2026!x';
const OCTET = Math.floor(Math.random() * 200) + 20;
const ip = (n) => `203.0.113.${(OCTET + n) % 250}`;

async function ctxFrom(ipAddress) {
  return pwRequest.newContext({ extraHTTPHeaders: { 'X-Forwarded-For': ipAddress } });
}

async function apiLogin(ctx, email, password) {
  return ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email, password } });
}

async function apiSession(email, password, ipAddress) {
  const ctx = await ctxFrom(ipAddress);
  const res = await apiLogin(ctx, email, password);
  if (res.status() !== 200) throw new Error(`connexion ${email} : ${res.status()} ${await res.text()}`);
  const { data } = await res.json();
  return { ctx, csrf: data.csrf_token };
}

async function htmlLoginAttempt(ctx, email, password) {
  const page = await (await ctx.get(`${ADMIN}/login`)).text();
  const csrf = page.match(/name="_csrf" value="([^"]+)"/)[1];
  const res = await ctx.post(`${ADMIN}/login`, { form: { _csrf: csrf, email, password }, maxRedirects: 0 });
  return { status: res.status(), body: await res.text() };
}

// Ce que l'utilisateur voit : le bloc d'erreur, sans le jeton CSRF ni l'email re-affiche.
function visibleError(html) {
  const m = html.match(/<[^>]+(?:role="alert"|class="[^"]*(?:alert|error)[^"]*")[^>]*>([\s\S]*?)<\/(?:div|p)>/i);
  return (m ? m[1] : '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
}

function normalise(html, email) {
  return html.replace(/name="_csrf" value="[^"]*"/g, '').split(email).join('<email>');
}

let admin;
let roles;

async function createUser(roleCode, label) {
  const email = `sec-${label}-${RUN}@wakdo.local`;
  const res = await admin.ctx.post(`${ADMIN}/admin/api/users`, {
    headers: { 'X-CSRF-Token': admin.csrf },
    data: { email, first_name: 'Sec', last_name: label, role_id: roles[roleCode], password: TEMP_PASSWORD, pin_email: ADMIN_EMAIL, pin: ADMIN_PIN },
  });
  if (res.status() !== 201) throw new Error(`creation ${email} : ${res.status()} ${await res.text()}`);
  return email;
}

test.describe.configure({ mode: 'serial' });

test.describe('Force brute et enumeration', () => {
  test.beforeAll(async () => {
    admin = await apiSession(ADMIN_EMAIL, ADMIN_PASSWORD, ip(0));
    const pin = await admin.ctx.post(`${ADMIN}/admin/profile/pin`, {
      form: { _csrf: admin.csrf, current_password: ADMIN_PASSWORD, pin: ADMIN_PIN, pin_confirm: ADMIN_PIN }, maxRedirects: 0,
    });
    expect(pin.status()).toBe(302);
    const { data } = await (await admin.ctx.get(`${ADMIN}/admin/api/roles`)).json();
    roles = Object.fromEntries(data.map((r) => [r.code, r.id]));
  });

  test.afterAll(async () => {
    if (admin) await admin.ctx.dispose();
  });

  test('connexion : meme reponse pour un compte existant (mot de passe faux) et pour un compte inconnu', async () => {
    const ctx = await ctxFrom(ip(1));
    const known = await htmlLoginAttempt(ctx, 'manager@wakdo.local', 'mauvais-mot-de-passe');
    const unknown = await htmlLoginAttempt(ctx, `inconnu-${RUN}@wakdo.local`, 'mauvais-mot-de-passe');
    expect(known.status).toBe(unknown.status);
    expect(visibleError(known.body)).not.toBe('');
    expect(visibleError(known.body)).toBe(visibleError(unknown.body));
    expect(normalise(known.body, 'manager@wakdo.local')).toBe(normalise(unknown.body, `inconnu-${RUN}@wakdo.local`));

    const apiKnown = await apiLogin(ctx, 'manager@wakdo.local', 'mauvais-mot-de-passe');
    const apiUnknown = await apiLogin(ctx, `inconnu-${RUN}@wakdo.local`, 'mauvais-mot-de-passe');
    expect(apiKnown.status()).toBe(401);
    expect(await apiKnown.text()).toBe(await apiUnknown.text());
    await ctx.dispose();
  });

  test('verrou par compte : apres 5 echecs, meme le bon mot de passe est refuse, depuis toute adresse, sans message distinct', async () => {
    const email = await createUser('counter', 'lock');
    const attacker = await ctxFrom(ip(2));
    const failures = [];
    for (let i = 0; i < 5; i += 1) {
      const r = await apiLogin(attacker, email, `faux-${i}`);
      failures.push([r.status(), await r.text()]);
    }
    expect(failures.every(([st]) => st === 401)).toBe(true);

    // Le bon mot de passe, depuis une AUTRE adresse : le verrou suit le compte.
    const legit = await ctxFrom(ip(3));
    const locked = await apiLogin(legit, email, TEMP_PASSWORD);
    expect(locked.status()).toBe(401);
    // Indiscernable d'un mot de passe faux (pas de 429, meme corps) : un attaquant ne peut
    // pas se servir du verrou pour confirmer qu'un compte existe.
    expect(await locked.text()).toBe(failures[0][1]);
    expect((await legit.get(`${ADMIN}/admin/api/auth/me`)).status()).toBe(401);

    const html = await htmlLoginAttempt(legit, email, TEMP_PASSWORD);
    expect(html.status).toBe(200);
    expect(html.body).not.toContain('userMenuBtn');
    await attacker.dispose();
    await legit.dispose();
  });

  test('verrou par IP : au-dela de 20 echecs, 429 + Retry-After pour cette IP seulement', async () => {
    const attacker = await ctxFrom(ip(4));
    const statuses = [];
    for (let i = 0; i < 20; i += 1) {
      statuses.push((await apiLogin(attacker, `balayage-${i}-${RUN}@wakdo.local`, 'x')).status());
    }
    expect(statuses.every((st) => st === 401), statuses.join(',')).toBe(true);

    const blocked = await apiLogin(attacker, ADMIN_EMAIL, ADMIN_PASSWORD);
    expect(blocked.status()).toBe(429);
    expect((await blocked.json()).error.code).toBe('TOO_MANY_ATTEMPTS');
    expect(Number(blocked.headers()['retry-after'])).toBeGreaterThan(0);
    // Meme le formulaire HTML est bloque pour cette IP.
    const html = await htmlLoginAttempt(attacker, ADMIN_EMAIL, ADMIN_PASSWORD);
    expect(html.status).not.toBe(302);

    // Une autre adresse n'est pas penalisee.
    const other = await ctxFrom(ip(5));
    expect((await apiLogin(other, ADMIN_EMAIL, ADMIN_PASSWORD)).status()).toBe(200);
    await attacker.dispose();
    await other.dispose();
  });

  test('PIN d action sensible : bloque apres 5 echecs, meme un PIN juste est ensuite refuse', async () => {
    const email = await createUser('counter', 'pin');
    const s = await apiSession(email, TEMP_PASSWORD, ip(6));
    const setPin = await s.ctx.post(`${ADMIN}/admin/profile/pin`, {
      form: { _csrf: s.csrf, current_password: TEMP_PASSWORD, pin: '8642', pin_confirm: '8642' }, maxRedirects: 0,
    });
    expect(setPin.status()).toBe(302);

    const { data: products } = await (await s.ctx.get(`http://kiosk.wakdo.test/api/products`)).json();
    const productId = products.find((p) => p.is_orderable).id;
    const created = await s.ctx.post(`${ADMIN}/admin/api/orders`, {
      headers: { 'X-CSRF-Token': s.csrf },
      data: { service_mode: 'takeaway', items: [{ type: 'product', product_id: productId, quantity: 1 }] },
    });
    expect(created.status()).toBe(201);
    const number = (await created.json()).data.order_number;
    const cancel = (pin) => s.ctx.post(`${ADMIN}/admin/api/orders/${number}/cancel`, {
      headers: { 'X-CSRF-Token': s.csrf }, data: { pin_email: email, pin },
    });

    const wrong = [];
    for (let i = 0; i < 5; i += 1) {
      const r = await cancel(String(1000 + i));
      wrong.push([r.status(), (await r.json()).error.code]);
    }
    expect(wrong.every(([st, code]) => st === 422 && code === 'PIN_INVALID'), JSON.stringify(wrong)).toBe(true);

    const right = await cancel('8642');
    expect(right.status()).toBe(422);
    expect((await right.json()).error.code).toBe('PIN_INVALID');
    const order = await s.ctx.get(`${ADMIN}/admin/api/orders/${number}`);
    expect((await order.json()).data.status).not.toBe('cancelled');
    await s.ctx.dispose();
  });

  test('mot de passe oublie : reponse identique que l adresse existe ou non', async () => {
    const ctx = await ctxFrom(ip(7));
    const ask = async (email) => {
      const page = await (await ctx.get(`${ADMIN}/forgot_password`)).text();
      const csrf = page.match(/name="_csrf" value="([^"]+)"/)[1];
      const res = await ctx.post(`${ADMIN}/forgot_password`, { form: { _csrf: csrf, email }, maxRedirects: 0 });
      return { status: res.status(), body: normalise(await res.text(), email) };
    };
    // Compte jetable dedie : la phase "reset" de tests/e2e/run-security.sh relit ce lien
    // dans le journal de l'application (LogMailer, aucun SMTP sur la pile) et l'utilise.
    const email = await createUser('counter', 'reset');
    const existing = await ask(email);
    const unknown = await ask(`inconnu-${RUN}@wakdo.local`);
    expect(existing.status).toBe(200);
    expect(existing).toEqual(unknown);
    expect(existing.body).toContain('Si un compte correspond à cet email');
    await ctx.dispose();
  });

  test('mot de passe oublie : limitation du nombre de demandes par adresse', async () => {
    // Verrou par ADRESSE (PASSWORD_RESET_EMAIL_THROTTLE_THRESHOLD=5, .env.example) :
    // les 5 premieres demandes passent, les suivantes sont bloquees (429) tant que
    // le verrou tient -- sans quoi 30 demandes de suite pour la meme adresse
    // passeraient toutes et, SMTP configure, inonderaient la boite du compte vise.
    const ctx = await ctxFrom(ip(8));
    const page = await (await ctx.get(`${ADMIN}/forgot_password`)).text();
    const csrf = page.match(/name="_csrf" value="([^"]+)"/)[1];
    const statuses = [];
    for (let i = 0; i < 30; i += 1) {
      const res = await ctx.post(`${ADMIN}/forgot_password`, { form: { _csrf: csrf, email: 'manager@wakdo.local' }, maxRedirects: 0 });
      statuses.push(res.status());
    }
    expect(statuses.slice(0, 5).every((st) => st === 200), statuses.join(',')).toBe(true);
    expect(statuses.slice(20).some((st) => st === 429)).toBe(true);
    await ctx.dispose();
  });

  test('re-authentification profil (D-1) : verrou apres 5 echecs, sans reveler si le mot de passe etait bon', async () => {
    // Compte JETABLE dedie (jamais admin@wakdo.local) : le verrou pose ici est
    // par COMPTE (user.failed_login_attempts/lockout_until, D-1.a) -- l'isoler
    // evite qu'il ne gene un autre spec qui reutiliserait la session admin sur
    // cette meme route, et evite de verrouiller admin@wakdo.local (partage
    // avec le verrou de CONNEXION du compte depuis D-1.a).
    const email = await createUser('counter', 'reauth');
    const s = await apiSession(email, TEMP_PASSWORD, ip(9));

    const wrong = [];
    for (let i = 0; i < 5; i += 1) {
      const r = await s.ctx.post(`${ADMIN}/admin/profile/pin`, {
        form: { _csrf: s.csrf, current_password: `faux-${i}`, pin: '1111', pin_confirm: '1111' }, maxRedirects: 0,
      });
      wrong.push(r.status());
    }
    expect(wrong.every((st) => st === 422), wrong.join(',')).toBe(true);

    // Le verrou tient MEME avec le bon mot de passe (gate AVANT verify) : ni
    // enregistrement du PIN (redirection 302), ni message "mot de passe actuel
    // incorrect" -- avant D-1, cette route n'avait aucune limite ni trace.
    const locked = await s.ctx.post(`${ADMIN}/admin/profile/pin`, {
      form: { _csrf: s.csrf, current_password: TEMP_PASSWORD, pin: '2222', pin_confirm: '2222' }, maxRedirects: 0,
    });
    expect(locked.status()).toBe(422);
    const lockedBody = await locked.text();
    expect(lockedBody).not.toContain('Mot de passe actuel incorrect');
    await s.ctx.dispose();
  });

  test('re-authentification profil (D-1.a) : le verrou ne se remet pas a zero par une action PIN reussie avec l identite d un tiers', async () => {
    // Avant D-1.a, le compteur de re-verification partageait pin_throttle
    // (cle = utilisateur de SESSION) avec le PIN d'action sensible -- remis a
    // zero par TOUTE action PIN reussie, MEME autorisee par l'email+PIN d'un
    // TIERS (PinVerifier::resolveActingUser() accepte tout compte actif). Sur
    // le poste partage, un collegue pouvait alterner des echecs de mot de
    // passe et des actions PIN anodines avec SES PROPRES identifiants pour ne
    // jamais armer le verrou. Le compte JETABLE est distinct de celui du test
    // precedent (compteurs par compte, D-1.a).
    const email = await createUser('counter', 'reauth2');
    const s = await apiSession(email, TEMP_PASSWORD, ip(12));

    for (let i = 0; i < 4; i += 1) {
      const r = await s.ctx.post(`${ADMIN}/admin/profile/pin`, {
        form: { _csrf: s.csrf, current_password: `faux-${i}`, pin: '3333', pin_confirm: '3333' }, maxRedirects: 0,
      });
      expect(r.status()).toBe(422);
    }

    // Action PIN reussie AILLEURS, avec L'IDENTITE D'ADMIN (un tiers pour
    // cette session) : annulation d'une commande jetable, autorisee par
    // admin@wakdo.local + son PIN -- jamais celui de la session courante.
    const { data: products } = await (await s.ctx.get(`http://kiosk.wakdo.test/api/products`)).json();
    const productId = products.find((p) => p.is_orderable).id;
    const created = await s.ctx.post(`${ADMIN}/admin/api/orders`, {
      headers: { 'X-CSRF-Token': s.csrf },
      data: { service_mode: 'takeaway', items: [{ type: 'product', product_id: productId, quantity: 1 }] },
    });
    expect(created.status()).toBe(201);
    const number = (await created.json()).data.order_number;
    const cancelled = await s.ctx.post(`${ADMIN}/admin/api/orders/${number}/cancel`, {
      headers: { 'X-CSRF-Token': s.csrf },
      data: { pin_email: ADMIN_EMAIL, pin: ADMIN_PIN },
    });
    expect(cancelled.status()).toBe(200);

    // Un 5e echec doit desormais verrouiller -- preuve que le succes PIN
    // ci-dessus n'a RIEN remis a zero pour cette session.
    const fifth = await s.ctx.post(`${ADMIN}/admin/profile/pin`, {
      form: { _csrf: s.csrf, current_password: 'faux-4', pin: '3333', pin_confirm: '3333' }, maxRedirects: 0,
    });
    expect(fifth.status()).toBe(422);

    const locked = await s.ctx.post(`${ADMIN}/admin/profile/pin`, {
      form: { _csrf: s.csrf, current_password: TEMP_PASSWORD, pin: '3333', pin_confirm: '3333' }, maxRedirects: 0,
    });
    expect(locked.status()).toBe(422);
    expect(await locked.text()).not.toContain('Mot de passe actuel incorrect');
    await s.ctx.dispose();
  });

  test('verrou par IP (D-2) : une connexion reussie ne remet pas le compteur a zero', async () => {
    // Avant D-2, une connexion reussie remettait le compteur IP a 0 -- un
    // attaquant qui connait un couple valide pouvait alterner 19 echecs et 1
    // succes sans jamais atteindre le plafond de 20.
    const attacker = await ctxFrom(ip(10));
    for (let i = 0; i < 19; i += 1) {
      const r = await apiLogin(attacker, `balayage2-${i}-${RUN}@wakdo.local`, 'x');
      expect(r.status()).toBe(401);
    }

    const ok = await apiLogin(attacker, ADMIN_EMAIL, ADMIN_PASSWORD);
    expect(ok.status()).toBe(200);

    // Un seul echec de plus (19 + 1 = 20) doit desormais suffire a atteindre le
    // plafond, PUISQUE le succes ci-dessus n'a rien remis a zero.
    const twentieth = await apiLogin(attacker, `balayage2-last-${RUN}@wakdo.local`, 'x');
    expect(twentieth.status()).toBe(401);

    const blocked = await apiLogin(attacker, ADMIN_EMAIL, ADMIN_PASSWORD);
    expect(blocked.status()).toBe(429);
    await attacker.dispose();
  });
});
