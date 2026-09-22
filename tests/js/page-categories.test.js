/*
 * Tests de l'ecran categories de la borne (node:test + jsdom).
 *
 * Import dynamique apres pose des globals jsdom (le module enregistre un
 * DOMContentLoaded au chargement, comme category-strip.js). Cible :
 * buildGridModel (PUR) + renderGridInto (DOM sans fetch) + renderCategoryGrid
 * (chargeur injecte, donc branche d'erreur testable sans toucher au fetch global).
 */
import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let buildGridModel, renderGridInto, renderCategoryGrid;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
        url: 'https://kiosk.test/categories.html?mode=sur-place',
    });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    ({ buildGridModel, renderGridInto, renderCategoryGrid } =
        await import('../../src/public/borne/assets/js/page-categories.js'));
});

/* Forme borne renvoyee par loadCategories (data.js:52) : id, title, slug, image.
 * Les noms sont en minuscules en base (db/seeds/0002_catalogue.sql), d'ou la
 * capitalisation cote client. L'ordre vient du serveur (display_order). */
const cats = () => ([
    { id: 1, title: 'menus', slug: 'menus', image: 'assets/images/categories/menus.png' },
    { id: 3, title: 'burgers', slug: 'burgers', image: 'assets/images/categories/burgers.png' },
    { id: 2, title: 'boissons', slug: 'boissons', image: 'assets/images/categories/boissons.png' },
]);

/** Coquille DOM equivalente a celle de categories.html. */
function shell() {
    const root = document.createElement('div');
    root.innerHTML = `
        <p id="categories-error" class="products-error" hidden role="alert"></p>
        <p id="categories-empty" class="products-empty" hidden>Aucune categorie disponible pour le moment.</p>
        <nav id="category-grid" class="category-grid" aria-label="Navigation par categorie"></nav>
    `;
    return {
        root,
        grid: root.querySelector('#category-grid'),
        errorEl: root.querySelector('#categories-error'),
        emptyEl: root.querySelector('#categories-empty'),
    };
}

/* --- buildGridModel (pur) ------------------------------------------------- */

test('buildGridModel: capitalise le libelle, preserve id/nom/image et l ordre recu', () => {
    const m = buildGridModel(cats());
    assert.equal(m.length, 3);
    assert.deepEqual(m[0], { id: 1, name: 'menus', label: 'Menus', image: 'assets/images/categories/menus.png' });
    // L'ordre du serveur (display_order) est conserve tel quel : pas de re-tri client.
    assert.deepEqual(m.map(c => c.id), [1, 3, 2]);
});

test('buildGridModel: image absente -> image vide, pas de plantage', () => {
    const m = buildGridModel([{ id: 9, title: 'sauces', slug: 'sauces', image: null }]);
    assert.equal(m[0].image, '');
    assert.equal(m[0].label, 'Sauces');
});

/* --- renderGridInto (jsdom, sans reseau) --------------------------------- */

test('renderGridInto: une carte par categorie, dans l ordre du modele', () => {
    const s = shell();
    renderGridInto(s.grid, buildGridModel(cats()), s.emptyEl);
    const cards = s.grid.querySelectorAll('.category-card');
    assert.equal(cards.length, 3);
    assert.deepEqual(
        [...cards].map(a => a.querySelector('.category-card__label').textContent.trim()),
        ['Menus', 'Burgers', 'Boissons']
    );
});

test('renderGridInto: href exactement products.html?category=<id>, sans mode', () => {
    const s = shell();
    renderGridInto(s.grid, buildGridModel(cats()), s.emptyEl);
    // Correspondance EXACTE attendue par tests/e2e/borne.spec.js:23
    // (a[href="products.html?category=2"]) : ne jamais propager &mode= ici. Le mode est
    // deja persiste dans localStorage par nav.js sur cette page meme.
    assert.equal(s.grid.querySelector('.category-card').getAttribute('href'), 'products.html?category=1');
    const hrefs = [...s.grid.querySelectorAll('.category-card')].map(a => a.getAttribute('href'));
    assert.deepEqual(hrefs, ['products.html?category=1', 'products.html?category=3', 'products.html?category=2']);
    assert.ok(hrefs.every(h => !h.includes('mode=')), 'aucun href ne doit porter mode=');
});

test('renderGridInto: aria-label sur le nom brut, alt et libelle capitalises', () => {
    const s = shell();
    renderGridInto(s.grid, buildGridModel(cats()), s.emptyEl);
    const first = s.grid.querySelector('.category-card');
    // Reproduit le balisage de l'echafaudage remplace (categories.html:54, :59, :61).
    assert.equal(first.getAttribute('aria-label'), 'Voir les menus');
    assert.equal(first.querySelector('.category-card__image').getAttribute('alt'), 'Menus');
    assert.equal(first.querySelector('.category-card__label').textContent.trim(), 'Menus');
});

test('renderGridInto: repli d image par data-fallback, aucun handler en ligne (CSP)', () => {
    const s = shell();
    renderGridInto(s.grid, buildGridModel(cats()), s.emptyEl);
    const img = s.grid.querySelector('.category-card__image');
    assert.equal(img.getAttribute('data-fallback'), 'logo');
    assert.equal(img.getAttribute('data-fallback-alt'), 'Image non disponible');
    // CSP stricte script-src 'self' sans unsafe-inline (docker/apache/vhost.conf:120).
    const html = s.grid.innerHTML;
    assert.ok(!/onerror=/i.test(html), 'aucun onerror en ligne');
    assert.ok(!/onclick=/i.test(html), 'aucun onclick en ligne');
    assert.ok(!/\sstyle=/i.test(html), 'aucun style en ligne');
});

test('renderGridInto: categorie sans image -> aucune balise img, la carte reste un lien', () => {
    const s = shell();
    renderGridInto(s.grid, buildGridModel([{ id: 7, title: 'salades', slug: 'salades', image: null }]), s.emptyEl);
    const card = s.grid.querySelector('.category-card');
    // image_path est NULLABLE cote API (CatalogueController.php:212) : mieux vaut pas
    // d'image qu'une <img src=""> qui declenche le repli a chaque affichage.
    assert.equal(card.querySelectorAll('img').length, 0);
    assert.equal(card.getAttribute('href'), 'products.html?category=7');
    assert.equal(card.querySelector('.category-card__label').textContent.trim(), 'Salades');
});

test('renderGridInto: nom contenant du HTML echappe (anti-XSS RG-T15)', () => {
    const s = shell();
    renderGridInto(s.grid, buildGridModel([{ id: 9, title: '<b>x</b>', slug: 'x', image: 'i.png' }]), s.emptyEl);
    assert.match(s.grid.innerHTML, /&lt;b&gt;/);
    assert.equal(s.grid.querySelectorAll('b').length, 0);
});

test('renderGridInto: liste vide -> aucune carte, message de vide visible, erreur cachee', () => {
    const s = shell();
    renderGridInto(s.grid, buildGridModel([]), s.emptyEl);
    assert.equal(s.grid.querySelectorAll('.category-card').length, 0);
    assert.equal(s.emptyEl.hidden, false);
    assert.equal(s.errorEl.hidden, true, 'une liste vide n est pas une erreur');
});

test('renderGridInto: liste non vide -> le message de vide reste cache', () => {
    const s = shell();
    s.emptyEl.hidden = false;
    renderGridInto(s.grid, buildGridModel(cats()), s.emptyEl);
    assert.equal(s.emptyEl.hidden, true);
});

/* --- renderCategoryGrid (chargeur injecte) ------------------------------- */

test('renderCategoryGrid: chargeur resolu -> cartes montees, aucune erreur affichee', async () => {
    const s = shell();
    await renderCategoryGrid(s, async () => cats());
    assert.equal(s.grid.querySelectorAll('.category-card').length, 3);
    assert.equal(s.errorEl.hidden, true);
    assert.equal(s.emptyEl.hidden, true);
});

test('renderCategoryGrid: chargeur rejete -> message clair, ne jette pas, grille vide', async () => {
    const s = shell();
    await assert.doesNotReject(() => renderCategoryGrid(s, async () => { throw new Error('HTTP 500'); }));
    assert.equal(s.errorEl.hidden, false);
    assert.match(s.errorEl.textContent, /categories/i);
    assert.equal(s.grid.querySelectorAll('.category-card').length, 0);
    assert.equal(s.emptyEl.hidden, true, 'un echec de chargement n est pas un catalogue vide');
});

test('renderCategoryGrid: conteneur absent -> ne jette pas', async () => {
    await assert.doesNotReject(() => renderCategoryGrid({ grid: null, errorEl: null, emptyEl: null }, async () => cats()));
});

/* --- Cablage du fichier servi (regression) ------------------------------- */

test('categories.html charge page-categories.js et expose le conteneur de grille', () => {
    // Sans ce garde-fou, un renommage de script ou d'identifiant casserait le 1er ecran
    // post-accueil sans faire echouer aucun test (motif de nav.test.js:47-53).
    const html = readFileSync(new URL('../../src/public/borne/categories.html', import.meta.url), 'utf8');
    assert.match(html, /assets\/js\/page-categories\.js/);
    assert.match(html, /id="category-grid"/);
    assert.match(html, /id="categories-error"/);
    assert.match(html, /id="categories-empty"/);
    // nav.js reste charge : c'est lui qui persiste ?mode= sur cette page (nav.test.js).
    assert.match(html, /assets\/js\/nav\.js/);
    // L'echafaudage statique ne doit pas revenir : plus aucune carte en dur.
    assert.ok(!/class="category-card"/.test(html), 'aucune carte codee en dur dans le HTML');
});
