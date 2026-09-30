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
  // D-7.b (2e revue adverse, contre-audit 30/09) : ces deux reponses ne
  // passent JAMAIS par PHP (`App\Core\Response`) -- l'une est un fichier
  // statique servi directement par Apache, l'autre une erreur Apache seule
  // (`Require all denied`, docker/apache/vhost.conf) -- c'est precisement la
  // regression que D-7.b corrige (Referrer-Policy avait disparu de ces deux
  // categories de reponses sur l'hote admin).
  { name: 'back-office - fichier statique (assets)', url: `${ADMIN}/assets/css/admin.css` },
  { name: 'back-office - erreur Apache (fichier protege)', url: `${ADMIN}/.env` },
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

  /**
   * D-7.b (2e revue adverse, contre-audit 30/09) : le vhost admin pose
   * desormais Referrer-Policy par defaut ("expr=-z resp(...)", ne s'applique
   * QUE si la reponse n'en porte pas deja une) -- il ne doit donc JAMAIS
   * ajouter une SECONDE ligne a cote de celle posee par PHP sur /reset_password.
   * `headersArray()` (contrairement a `headers()`, qui fusionnerait deux
   * valeurs) expose chaque occurrence de l'en-tete separement : on verifie
   * qu'il n'y en a bien qu'UNE, avec la BONNE valeur, sur une page ordinaire
   * ET sur /reset_password.
   */
  test('back-office : Referrer-Policy n a jamais deux valeurs (Apache + application)', async ({ request }) => {
    const login = await request.get(`${ADMIN}/login`, { maxRedirects: 0 });
    const loginEntries = login.headersArray().filter((h) => h.name.toLowerCase() === 'referrer-policy');
    expect(loginEntries, JSON.stringify(loginEntries)).toHaveLength(1);
    expect(loginEntries[0].value).toBe('strict-origin-when-cross-origin');

    const reset = await request.get(`${ADMIN}/reset_password?token=abc`, { maxRedirects: 0 });
    const resetEntries = reset.headersArray().filter((h) => h.name.toLowerCase() === 'referrer-policy');
    expect(resetEntries, JSON.stringify(resetEntries)).toHaveLength(1);
    expect(resetEntries[0].value).toBe('no-referrer');
  });

  test('back-office : les en-tetes du front controller sont poses aussi sur une page de connexion (X-Robots-Tag)', async ({ request }) => {
    const { headers } = await headersOf(request, `${ADMIN}/login`);
    expect(headers['x-robots-tag']).toBe('noindex, nofollow');
    expect(headers['cache-control']).toContain('no-store');
  });

  test('back-office : la CSP borne aussi base-uri et form-action (directives sans repli sur default-src)', async ({ request }) => {
    const { headers } = await headersOf(request, `${ADMIN}/login`);
    expect(headers['content-security-policy']).toBeTruthy();
    const d = cspDirectives(headers['content-security-policy']);
    expect(d['base-uri']).toEqual(["'self'"]);
    expect(d['form-action']).toEqual(["'self'"]);
  });

  for (const host of [KIOSK, ADMIN]) {
    test(`${host} : Permissions-Policy est posee`, async ({ request }) => {
      const { status, headers } = await headersOf(request, `${host}/`);
      expect(status).toBeLessThan(500);
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
