// Tests de securite -- televersement d'images. OWASP Top 10 2021 A04 Insecure Design /
// A05 Security Misconfiguration (execution de fichier depose) ; OWASP ASVS 4.0 V12
// (fichiers et ressources).
//
// Base existante : tests/Unit/Core/ImageUploaderTest.php verifie App\Core\ImageUploader
// fonction par fonction (type lu dans le contenu, nom regenere, traversee ignoree, octet
// nul, taille, signature PNG seule). Ce fichier rejoue les memes attaques DE BOUT EN BOUT :
// vrai envoi multipart au formulaire de creation de produit (le seul chemin d'envoi
// d'image, avec celui des categories qui partage la meme classe), puis tentative d'appeler
// le fichier depose sur les deux hotes pour verifier qu'il n'est pas execute.
const { test, expect, request: pwRequest } = require('@playwright/test');

const ADMIN = 'http://admin.wakdo.test';
const KIOSK = 'http://kiosk.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';
const RUN = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4).toString(36)}`;

const PNG_1PX = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
);
const PHP_CODE = "<?php echo 'EXEC' . 'UTE' . 'D'; phpinfo(); ?>";
const UPLOADED_PATH = /^uploads\/products\/[a-f0-9]{32}\.(png|jpg|webp)$/;

let s;

async function sendProduct(label, file) {
  const name = `Up ${label} ${RUN}`;
  const res = await s.ctx.post(`${ADMIN}/admin/products`, {
    multipart: {
      _csrf: s.csrf, category_id: '8', name, description: '', price_cents: '1,50', vat_rate: '100',
      display_order: '65535', image_path: '', image_file: file,
    },
    maxRedirects: 0,
  });
  return { res, name, body: await res.text() };
}

// La liste de l'API ne renvoie pas image_path (ProductRepository::all() ne lit pas la
// colonne) : on y cherche l'id, puis on relit la fiche complete.
async function productByName(name) {
  const { data } = await (await s.ctx.get(`${ADMIN}/admin/api/products`)).json();
  const row = data.find((p) => p.name === name);
  if (!row) return null;
  return (await (await s.ctx.get(`${ADMIN}/admin/api/products/${row.id}`)).json()).data;
}

async function expectRefused(label, file, message) {
  const { res, name, body } = await sendProduct(label, file);
  expect(res.status(), label).toBe(422);
  expect(body, label).toMatch(message);
  expect(await productByName(name), `${label} : aucun produit cree`).toBeNull();
}

test.describe.configure({ mode: 'serial' });

test.describe('Televersement d images', () => {
  test.beforeAll(async () => {
    const ctx = await pwRequest.newContext();
    const login = await ctx.post(`${ADMIN}/admin/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
    expect(login.status()).toBe(200);
    s = { ctx, csrf: (await login.json()).data.csrf_token };
  });

  test.afterAll(async () => {
    if (s) await s.ctx.dispose();
  });

  test('un script PHP renomme en .jpg est refuse (type lu dans le contenu)', async () => {
    await expectRefused('php-jpg', { name: 'photo.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(PHP_CODE) }, /Format d&#039;image non accepté/);
  });

  test('une signature PNG suivie de PHP (pas une image decodable) est refusee', async () => {
    const stub = Buffer.concat([PNG_1PX.subarray(0, 8), Buffer.from(PHP_CODE)]);
    await expectRefused('png-stub', { name: 'image.png', mimeType: 'image/png', buffer: stub }, /Format d&#039;image non accepté|pas une image exploitable/);
  });

  test('un SVG porteur de script est refuse', async () => {
    const svg = Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(document.cookie)</script></svg>');
    await expectRefused('svg', { name: 'logo.svg', mimeType: 'image/svg+xml', buffer: svg }, /Format d&#039;image non accepté/);
  });

  test('un type MIME annonce mensonger (texte annonce image/png) est refuse', async () => {
    await expectRefused('mime', { name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from('juste du texte, pas une image') }, /Format d&#039;image non accepté/);
  });

  test('un fichier trop gros (6 Mo, plafond 5 Mo) est refuse', async () => {
    const big = Buffer.concat([PNG_1PX, Buffer.alloc(6 * 1024 * 1024, 0x41)]);
    await expectRefused('big', { name: 'grosse.png', mimeType: 'image/png', buffer: big }, /taille maximale de 5 Mo/);
  });

  test('polyglotte (vraie image + code PHP) : accepte comme image, stocke sous un nom neuf, jamais execute', async () => {
    const polyglot = Buffer.concat([PNG_1PX, Buffer.from(PHP_CODE)]);
    const { res, name } = await sendProduct('polyglot', { name: 'shell.php', mimeType: 'application/x-php', buffer: polyglot });
    expect(res.status()).toBe(302);
    const product = await productByName(name);
    expect(product).not.toBeNull();
    // Le nom et l'extension viennent du serveur : 32 hexadecimaux + extension du type REEL.
    expect(product.image_path).toMatch(UPLOADED_PATH);
    expect(product.image_path.endsWith('.png')).toBe(true);

    const anon = await pwRequest.newContext();
    for (const host of [KIOSK, ADMIN]) {
      const file = await anon.get(`${host}/${product.image_path}`);
      expect(file.status(), host).toBe(200);
      expect(file.headers()['content-type'], host).toBe('image/png');
      expect(file.headers()['x-content-type-options'], host).toBe('nosniff');
      const body = await file.body();
      // Servi tel quel, octet pour octet : le code est la, inerte, et n'a pas tourne.
      expect(body.equals(polyglot), host).toBe(true);
      expect(body.toString('latin1'), host).not.toContain('EXECUTED');
      // Meme fichier demande avec une extension executable : refuse par le vhost.
      const asPhp = await anon.get(`${host}/${product.image_path.replace(/\.png$/, '.php')}`);
      expect(asPhp.status(), `${host} .php`).toBe(403);
      const pathInfo = await anon.get(`${host}/${product.image_path}/x.php`);
      expect(pathInfo.status(), `${host} path-info`).toBeGreaterThanOrEqual(400);
      expect(await pathInfo.text()).not.toContain('EXECUTED');
    }
    await anon.dispose();
  });

  test('nom de fichier avec ../ et double extension : ignore, le fichier reste dans uploads/ sous un nom neuf', async () => {
    for (const [label, clientName] of [['trav', '../../../public/admin/evil.php'], ['dbl', 'photo.php.png'], ['nul', 'photo.php\u0000.png']]) {
      const { res, name } = await sendProduct(label, { name: clientName, mimeType: 'image/png', buffer: PNG_1PX });
      expect(res.status(), label).toBe(302);
      const product = await productByName(name);
      expect(product.image_path, label).toMatch(UPLOADED_PATH);
    }
    const anon = await pwRequest.newContext();
    expect((await anon.get(`${ADMIN}/evil.php`)).status()).toBe(404);
    expect((await anon.get(`${ADMIN}/uploads/evil.php`)).status()).toBe(403);
    await anon.dispose();
  });

  test('le dossier des images ne se liste pas', async () => {
    const anon = await pwRequest.newContext();
    for (const host of [KIOSK, ADMIN]) {
      for (const dir of ['/uploads/', '/uploads/products/', '/uploads/categories/']) {
        const res = await anon.get(`${host}${dir}`);
        expect(res.status(), `${host}${dir}`).toBe(403);
        expect(await res.text()).not.toContain('Index of');
      }
    }
    await anon.dispose();
  });
});
