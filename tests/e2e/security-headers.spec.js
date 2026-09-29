// Tests de securite -- en-tetes HTTP (OWASP Top 10 2021 A05 Security Misconfiguration).
//
// Verifie, sur les DEUX hotes de la pile jetable, ce que la configuration Apache annonce
// (docker/apache/httpd.conf, docker/apache/vhost.conf) et ce que le front controller pose
// (src/public/admin/index.php) : CSP, X-Content-Type-Options, protection contre
// l'encadrement (X-Frame-Options ou frame-ancestors), Referrer-Policy, et l'absence
// d'en-tete qui revelerait une version (PHP, Apache).
//
// Un en-tete ABSENT alors qu'on l'attend est un constat : le test correspondant est
// marque test.fail() avec la raison, il reste visible dans le rapport et deviendra
// "rouge inattendu" (donc signale) le jour ou le correctif sera livre.
//
// Lecture seule : aucune ecriture, aucun compte, aucun etat laisse derriere.
const { test, expect } = require('@playwright/test');

const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN = 'http://admin.wakdo.test';

// Une adresse par type de reponse : document statique, JSON relaye, page PHP, erreur.
const TARGETS = [
  { name: 'borne - accueil statique', url: `${KIOSK}/` },
  { name: 'borne - page produits', url: `${KIOSK}/products.html` },
  { name: 'borne - API relayee', url: `${KIOSK}/api/categories` },
  { name: 'borne - API 404', url: `${KIOSK}/api/adresse-inconnue` },
  { name: 'back-office - connexion', url: `${ADMIN}/login` },
  { name: 'back-office - page protegee (redirection)', url: `${ADMIN}/admin/dashboard` },
  { name: 'back-office - API JSON', url: `${ADMIN}/admin/api/categories` },
  { name: 'back-office - 404 HTML', url: `${ADMIN}/admin/adresse-inconnue` },
  { name: 'sonde publique /api/health', url: `${ADMIN}/api/health` },
];

async function headersOf(request, url) {
  const res = await request.get(url, { maxRedirects: 0 });
  return { status: res.status(), headers: res.headers() };
}

function cspDirectives(csp) {
  const out = {};
  for (const part of (csp || '').split(';')) {
    const tokens = part.trim().split(/\s+/).filter(Boolean);
    if (tokens.length > 0) out[tokens[0].toLowerCase()] = tokens.slice(1);
  }
  return out;
}

test.describe('En-tetes de securite HTTP', () => {
  for (const target of TARGETS) {
    test(`${target.name} : nosniff, anti-encadrement, Referrer-Policy, CSP, aucune version divulguee`, async ({ request }) => {
      const { headers } = await headersOf(request, target.url);

      expect(headers['x-content-type-options']).toBe('nosniff');

      const csp = headers['content-security-policy'];
      expect(csp, 'Content-Security-Policy present').toBeTruthy();
      const directives = cspDirectives(csp);
      expect(directives['default-src']).toEqual(["'self'"]);
      // Aucune source de script externe ni 'unsafe-inline' / 'unsafe-eval' sur les scripts.
      expect(directives['script-src']).toEqual(["'self'"]);

      const frameAncestors = directives['frame-ancestors'];
      const xfo = (headers['x-frame-options'] || '').toUpperCase();
      expect(
        (frameAncestors && frameAncestors.join(' ') === "'none'") || xfo === 'DENY' || xfo === 'SAMEORIGIN',
        'X-Frame-Options ou frame-ancestors',
      ).toBe(true);

      expect(headers['referrer-policy']).toBe('strict-origin-when-cross-origin');

      // ServerTokens Prod : "Apache" seul, sans numero ; expose_php = Off : pas de X-Powered-By.
      expect(headers['server'] || '').not.toMatch(/\d/);
      expect(headers['x-powered-by']).toBeUndefined();
    });
  }

  test('borne : CSP stricte (frame-ancestors none, object-src none, base-uri self, form-action self, styles sans unsafe-inline)', async ({ request }) => {
    const { headers } = await headersOf(request, `${KIOSK}/`);
    const d = cspDirectives(headers['content-security-policy']);
    expect(d['frame-ancestors']).toEqual(["'none'"]);
    expect(d['object-src']).toEqual(["'none'"]);
    expect(d['base-uri']).toEqual(["'self'"]);
    expect(d['form-action']).toEqual(["'self'"]);
    expect(d['style-src']).toEqual(["'self'"]);
  });

  test('back-office : les en-tetes du front controller sont poses aussi sur une page de connexion (X-Robots-Tag)', async ({ request }) => {
    const { headers } = await headersOf(request, `${ADMIN}/login`);
    expect(headers['x-robots-tag']).toBe('noindex, nofollow');
    expect(headers['cache-control']).toContain('no-store');
  });

  test('back-office : la CSP borne aussi base-uri et form-action (directives sans repli sur default-src)', async ({ request }) => {
    // CONSTAT (mineur) : la CSP du vhost admin (docker/apache/vhost.conf, bloc <IfModule
    // mod_headers.c> du vhost ${APP_HOST_ADMIN}) ne porte ni base-uri ni form-action. Ces
    // deux directives ne retombent PAS sur default-src (CSP niveau 3) : une balise <base>
    // ou un formulaire injectes pourraient viser une autre origine. Le risque reste borne
    // (l'echappement HTML est prouve par security-xss.spec.js), c'est une defense en
    // profondeur manquante, que la borne, elle, pose.
    const { headers } = await headersOf(request, `${ADMIN}/login`);
    expect(headers['content-security-policy']).toBeTruthy();
    test.fail(true, 'CSP admin sans base-uri ni form-action (docker/apache/vhost.conf, vhost admin)');
    const d = cspDirectives(headers['content-security-policy']);
    expect(d['base-uri']).toEqual(["'self'"]);
    expect(d['form-action']).toEqual(["'self'"]);
  });

  for (const host of [KIOSK, ADMIN]) {
    test(`${host} : Permissions-Policy est posee`, async ({ request }) => {
      // CONSTAT (mineur) : aucun des deux vhosts ne pose Permissions-Policy
      // (docker/apache/httpd.conf et vhost.conf). Ni la borne ni le back-office n'utilisent
      // camera, micro, geolocalisation ou paiement : les couper explicitement ne coute rien.
      const { status, headers } = await headersOf(request, `${host}/`);
      expect(status).toBeLessThan(500);
      test.fail(true, 'Permissions-Policy absente (docker/apache/httpd.conf / vhost.conf)');
      expect(headers['permissions-policy']).toBeTruthy();
    });
  }

  test('un nom d hote inconnu ne sert ni la borne ni le back-office', async ({ request }) => {
    // Le premier vhost (catch-all de la sonde /healthz) repond a tout Host inconnu. En
    // production, Traefik ne relaie que les deux FQDN declares ; sur la pile locale on
    // verifie seulement qu'aucun contenu applicatif ne fuit par ce chemin.
    const res = await request.get(`${ADMIN}/login`, { headers: { Host: 'hote-inconnu.wakdo.test' }, maxRedirects: 0 });
    const body = await res.text();
    expect(body).not.toContain('Wakdo');
    expect(body).not.toContain('name="_csrf"');
  });
});
