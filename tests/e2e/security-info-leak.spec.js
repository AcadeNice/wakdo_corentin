// Tests de securite -- fuite d'informations. OWASP Top 10 2021 A05 Security
// Misconfiguration ; OWASP ASVS 4.0 V7.4.1 (message d'erreur generique) et V14.3
// (pas de divulgation involontaire de configuration).
//
// Base existante : tests/e2e/admin-error-pages.spec.js (page 404 lisible et accessible,
// enveloppe JSON de l'API), tests/Unit/Core/ErrorResponseTest.php et ErrorDisplayTest.php
// (reponse 500 generique hors mode debogage), et le cas "boom" de
// src/app/Health/captured-responses.json (vraie reponse 500, base arretee).
//
// Ajoute ici :
//  - pages 404 / 405 / 403 des deux hotes sans pile, chemin serveur, SQL ni version ;
//  - reponse 500 reelle de l'API publique quand APP_DEBUG=false (valeur de production) :
//    le lanceur tests/e2e/run-security.sh monte la pile ainsi et pose SEC_APP_DEBUG=false.
//    Sous APP_DEBUG=true (.env.example, lanceur e2e.sh), ce test est saute ;
//  - fichiers sensibles demandes sur les DEUX hotes (.env, .git, composer, sources PHP,
//    captured-responses.json, sauvegardes, phpinfo, server-status) ;
//  - listing de repertoire desactive ;
//  - contenu de la sonde publique /api/health.
const { test, expect, request: pwRequest } = require('@playwright/test');

const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN = 'http://admin.wakdo.test';
const DEBUG = (process.env.SEC_APP_DEBUG || '').toLowerCase();

// Traces qu'une page d'erreur ne doit pas contenir.
const LEAK = /Stack trace|#0 \/|\/var\/www|\.php on line|\.php:\d+|Fatal error|Warning:|Uncaught|SQLSTATE|PDOException|TypeError|Apache\/\d|PHP\/\d|X-Powered-By/i;
// Marqueurs de contenu sensible (fichiers d'environnement, depot git, code, captures).
const SECRET = /DB_PASSWORD|DB_ROOT_PASSWORD|MARIADB_|APP_DEBUG=|SMTP_PASSWORD|\[core\]|repositoryformatversion|ref: refs\/|<\?php|declare\(strict_types|"captured_at"|"routes"\s*:|CREATE TABLE|INSERT INTO|phpinfo\(\)|PHP Version|Server Version:/;

const SENSITIVE = [
  '/.env', '/.env.example', '/.env.prod.example', '/.git/config', '/.git/HEAD', '/.gitignore', '/.htaccess',
  '/composer.json', '/package.json', '/phpunit.xml', '/docker-compose.yml', '/README.md', '/SECURITY.md',
  '/app/Core/Config.php', '/src/app/Core/Config.php', '/app/Core/routes.php', '/app/Health/captured-responses.json',
  '/src/app/Health/captured-responses.json', '/Health/captured-responses.json', '/index.php', '/admin/index.php',
  '/public/admin/index.php', '/db/migrations/0001_init_schema.sql', '/db/seeds/0001_rbac_and_reference.sql',
  '/backup.sql', '/dump.sql', '/var/backups/', '/wakdo.sql.gz', '/index.php.bak', '/index.html~', '/.DS_Store',
  '/phpinfo.php', '/info.php', '/server-status', '/server-info', '/tests/e2e/rbac-demo.spec.js',
  '/docs/demo/comptes-demo.md', '/uploads/.htaccess', '/assets/../../app/Core/Config.php',
];

async function get(ctx, url, opts = {}) {
  const res = await ctx.get(url, { maxRedirects: 0, ...opts });
  return { res, body: await res.text() };
}

test.describe('Fuite d informations', () => {
  test('pages d erreur des deux hotes : ni pile, ni chemin serveur, ni SQL, ni version', async () => {
    const ctx = await pwRequest.newContext();
    const cases = [
      ['GET', `${ADMIN}/admin/adresse-inconnue`, 404],
      ['GET', `${ADMIN}/admin/products/1`, 405],
      ['DELETE', `${ADMIN}/admin/products/1`, 405],
      ['GET', `${ADMIN}/admin/api/adresse-inconnue`, 404],
      ['PATCH', `${ADMIN}/admin/api/products/1`, 405],
      ['GET', `${ADMIN}/api/adresse-inconnue`, 404],
      ['GET', `${ADMIN}/.env`, 403],
      ['GET', `${KIOSK}/api/adresse-inconnue`, 404],
      ['PUT', `${KIOSK}/api/orders`, 405],
      ['GET', `${KIOSK}/index.php`, 403],
      ['GET', `${KIOSK}/uploads/`, 403],
      // Traversee : selon que le client normalise l'adresse ou non, Apache repond 400, le
      // routeur 404, ou la borne sa page d'accueil (repli 200) ; jamais le fichier systeme.
      ['GET', `${ADMIN}/%2e%2e/%2e%2e/etc/passwd`, [400, 404]],
      ['GET', `${KIOSK}/%2e%2e/%2e%2e/etc/passwd`, [200, 400, 404]],
    ];
    for (const [method, url, status] of cases) {
      const res = await ctx.fetch(url, { method, maxRedirects: 0 });
      const body = await res.text();
      expect([].concat(status), `${method} ${url}`).toContain(res.status());
      expect(body, `${method} ${url}`).not.toMatch(LEAK);
      expect(body, `${method} ${url}`).not.toContain('root:x:0:0');
    }
    await ctx.dispose();
  });

  test('ancienne erreur 500 de l API publique (quantite hors colonne) : refus 422 propre, rien d interne', async () => {
    // Cette charge provoquait une 500 (SQLSTATE 22003) avant la borne de quantite
    // (fce3085) : elle doit maintenant etre refusee proprement. La vraie 500 avec
    // APP_DEBUG=false est prouvee base arretee, dans security-dbdown.spec.js.
    const ctx = await pwRequest.newContext();
    const { data: products } = await (await ctx.get(`${KIOSK}/api/products`)).json();
    const target = products.find((p) => p.is_orderable);
    const res = await ctx.post(`${KIOSK}/api/orders`, {
      data: { service_mode: 'takeaway', items: [{ type: 'product', product_id: target.id, quantity: 70000 }] },
    });
    expect(res.status()).toBe(422);
    const body = await res.text();
    expect(body).not.toMatch(LEAK);
    expect(body).not.toMatch(/SQLSTATE|out of range|column/i);
    expect(JSON.parse(body).error.code).toBe('INVALID_QUANTITY');
    await ctx.dispose();
  });

  for (const host of [KIOSK, ADMIN]) {
    test(`${host} : fichiers sensibles non servis`, async () => {
      const ctx = await pwRequest.newContext();
      for (const path of SENSITIVE) {
        const { res, body } = await get(ctx, `${host}${path}`);
        expect(body, `${host}${path}`).not.toMatch(SECRET);
        // Sur la borne, une adresse inconnue retombe sur index.html (repli d'application a
        // page unique) : 200 avec la page d'accueil, jamais le fichier demande.
        if (res.status() === 200) {
          expect(res.headers()['content-type'], `${host}${path}`).toMatch(/^text\/html/);
          expect(body, `${host}${path}`).toContain('<!DOCTYPE html>');
        } else {
          expect([301, 302, 400, 403, 404], `${host}${path}`).toContain(res.status());
        }
      }
      await ctx.dispose();
    });
  }

  test('listing de repertoire desactive sur les deux hotes', async () => {
    const ctx = await pwRequest.newContext();
    for (const url of [
      `${KIOSK}/assets/`, `${KIOSK}/assets/js/`, `${KIOSK}/assets/images/`, `${KIOSK}/data/`, `${KIOSK}/uploads/`,
      `${ADMIN}/assets/`, `${ADMIN}/assets/js/`, `${ADMIN}/assets/css/`, `${ADMIN}/uploads/`, `${ADMIN}/uploads/products/`,
    ]) {
      const { res, body } = await get(ctx, url);
      expect(body, url).not.toMatch(/Index of|Parent Directory/i);
      expect([403, 404], url).toContain(res.status());
    }
    await ctx.dispose();
  });

  test('sonde publique /api/health : aucun secret (mot de passe, hote, utilisateur de base)', async () => {
    const ctx = await pwRequest.newContext();
    for (const host of [KIOSK, ADMIN]) {
      const res = await ctx.get(`${host}/api/health`);
      expect(res.status()).toBe(200);
      const body = await res.text();
      expect(body).not.toMatch(/password|secret|token|wakdo-db|DB_|smtp|@/i);
      const keys = Object.keys(JSON.parse(body)).sort();
      expect(keys).toEqual(['app_env', 'categories', 'db', 'deployed_at', 'status', 'version']);
    }
    await ctx.dispose();
  });

  test('sonde publique /api/health : pas de numero de version du moteur PHP', async () => {
    const ctx = await pwRequest.newContext();
    const body = await (await ctx.get(`${KIOSK}/api/health`)).json();
    expect(body.status).toBe('ok');
    expect(body.php_version).toBeUndefined();
    await ctx.dispose();
  });
});
