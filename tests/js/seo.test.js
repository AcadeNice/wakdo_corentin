/*
 * Tests des aides de referencement de la borne (node:test + jsdom) : la section de
 * carte schema.org construite depuis les produits charges (Cr 1.e.3), son insertion
 * dans la page, et le titre de la page produits (Cr 1.e.5).
 *
 * Import dynamique apres pose des globals jsdom, comme les autres modules borne.
 */
import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let buildMenuSection, upsertJsonLd, categoryPageTitle;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', {
        url: 'https://kiosk.test/products.html?category=3',
    });
    global.window = dom.window;
    global.document = dom.window.document;
    ({ buildMenuSection, upsertJsonLd, categoryPageTitle } =
        await import('../../src/public/borne/assets/js/seo.js'));
});

/* Forme borne des produits (data.js) : prix en centimes, image relative, commandable. */
const products = () => ([
    { id: 12, nom: 'Big Tasty', prix: 1250, image: 'assets/images/produits/burgers/big-tasty.png', commandable: true },
    { id: 14, nom: 'Wrap chevre', prix: 590, image: '', commandable: false },
]);

test('buildMenuSection decrit la categorie et chaque produit en MenuItem avec son offre en euros', () => {
    const section = buildMenuSection({
        name: 'Nos Burgers',
        url: 'https://kiosk.test/products.html?category=3',
        products: products(),
        baseUrl: 'https://kiosk.test/products.html?category=3',
    });

    assert.equal(section['@context'], 'https://schema.org');
    assert.equal(section['@type'], 'MenuSection');
    assert.equal(section.name, 'Nos Burgers');
    assert.equal(section.url, 'https://kiosk.test/products.html?category=3');
    assert.equal(section.hasMenuItem.length, 2);

    const [burger, wrap] = section.hasMenuItem;
    assert.equal(burger['@type'], 'MenuItem');
    assert.equal(burger.name, 'Big Tasty');
    assert.equal(burger.image, 'https://kiosk.test/assets/images/produits/burgers/big-tasty.png');
    assert.deepEqual(burger.offers, {
        '@type': 'Offer',
        price: '12.50',
        priceCurrency: 'EUR',
        availability: 'https://schema.org/InStock',
    });

    // Rupture (RG-T21) : le produit reste a la carte, son offre dit qu'il manque.
    assert.equal(wrap.offers.availability, 'https://schema.org/OutOfStock');
    assert.equal('image' in wrap, false, 'pas d image vide dans les donnees');
});

test('upsertJsonLd remplace le bloc existant au lieu d en ajouter un', () => {
    const initial = document.createElement('script');
    initial.type = 'application/ld+json';
    initial.id = 'ld-menu-section';
    initial.textContent = '{"@context":"https://schema.org","@type":"MenuSection","name":"Produits"}';
    document.head.appendChild(initial);

    upsertJsonLd(document, 'ld-menu-section', { '@context': 'https://schema.org', '@type': 'MenuSection', name: 'Nos Burgers' });
    upsertJsonLd(document, 'ld-menu-section', { '@context': 'https://schema.org', '@type': 'MenuSection', name: 'Nos Boissons' });

    const blocks = document.querySelectorAll('script#ld-menu-section');
    assert.equal(blocks.length, 1);
    assert.equal(blocks[0].getAttribute('type'), 'application/ld+json');
    assert.equal(JSON.parse(blocks[0].textContent).name, 'Nos Boissons');
});

test('upsertJsonLd cree le bloc absent et neutralise les chevrons d un nom de produit', () => {
    upsertJsonLd(document, 'ld-test', { name: 'Menu </script><b>' });

    const block = document.getElementById('ld-test');
    assert.equal(block.parentNode, document.head);
    // Un nom qui fermerait la balise <script> doit rester une donnee.
    assert.doesNotMatch(block.textContent, /</);
    assert.equal(JSON.parse(block.textContent).name, 'Menu </script><b>');
});

test('categoryPageTitle donne un titre qui nomme la categorie et la borne (Cr 1.e.5)', () => {
    assert.equal(categoryPageTitle('Nos Burgers'), 'Nos Burgers - Borne de commande Wakdo');
});
