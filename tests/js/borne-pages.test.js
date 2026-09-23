/*
 * Garde de non-regression sur le balisage des 5 pages de la borne (node:test + jsdom),
 * pour les criteres de referencement et de performance du Bloc 1 :
 *   - Cr 1.e.5 : titre et description uniques par page, de longueur mesuree ;
 *   - Cr 1.e.3 : donnees structurees schema.org adaptees au role de chaque page ;
 *   - Cr 1.e.2 : expressions cles mises en exergue (strong / em) ;
 *   - Cr 1.e.7 : attribut title sur chaque lien, alt sur chaque image ;
 *   - Cr 1.e.8 : banniere d'accueil en format moderne, allegee, chargee en priorite.
 *
 * Les fichiers lus sont ceux que le vhost borne sert tels quels (aucun rendu serveur) :
 * le test porte donc exactement sur ce que recoit le navigateur.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const BORNE = new URL('../../src/public/borne/', import.meta.url);
const PAGES = ['index', 'categories', 'products', 'payment', 'confirmation'];

function page(name) {
    return new JSDOM(readFileSync(new URL(`${name}.html`, BORNE), 'utf8')).window.document;
}

/** Longueur en caracteres (points de code), pas en unites UTF-16. */
const length = (text) => [...text].length;
const clean = (text) => text.replace(/\s+/g, ' ').trim();

function jsonLdBlocks(doc) {
    return [...doc.querySelectorAll('script[type="application/ld+json"]')]
        .map((script) => JSON.parse(script.textContent));
}

/** Noeuds types d'un ensemble de blocs, y compris ceux regroupes sous @graph. */
function typedNodes(blocks) {
    return blocks.flatMap((block) => (Array.isArray(block['@graph']) ? block['@graph'] : [block]));
}

test('chaque page a un titre et une description uniques, de longueur mesuree (Cr 1.e.5)', () => {
    // Reperes d'usage courant dans les guides de referencement, non normatifs : d'ou
    // des fourchettes larges plutot qu'une valeur exacte.
    const titles = new Set();
    const descriptions = new Set();

    for (const name of PAGES) {
        const doc = page(name);
        const titleTags = doc.querySelectorAll('title');
        const descriptionTags = doc.querySelectorAll('meta[name="description"]');
        assert.equal(titleTags.length, 1, `${name} : une seule balise <title>`);
        assert.equal(descriptionTags.length, 1, `${name} : une seule meta description`);

        const title = clean(titleTags[0].textContent);
        const description = clean(descriptionTags[0].getAttribute('content'));
        assert.ok(length(title) >= 30 && length(title) <= 60,
            `${name} : titre de ${length(title)} caracteres, attendu 30 a 60 : « ${title} »`);
        assert.ok(length(description) >= 120 && length(description) <= 160,
            `${name} : description de ${length(description)} caracteres, attendu 120 a 160`);

        titles.add(title);
        descriptions.add(description);
    }

    assert.equal(titles.size, PAGES.length, 'un titre different par page');
    assert.equal(descriptions.size, PAGES.length, 'une description differente par page');
});

test('chaque page porte des donnees schema.org adaptees a son role (Cr 1.e.3)', () => {
    const expected = {
        index: ['WebSite', 'FastFoodRestaurant'],
        categories: ['Menu'],
        products: ['MenuSection'],
        payment: ['CheckoutPage'],
        confirmation: ['WebPage'],
    };

    for (const [name, types] of Object.entries(expected)) {
        const blocks = jsonLdBlocks(page(name));
        assert.ok(blocks.length >= 1, `${name} : au moins un bloc JSON-LD`);
        for (const block of blocks) {
            assert.equal(block['@context'], 'https://schema.org', `${name} : vocabulaire schema.org`);
        }
        const found = typedNodes(blocks).map((node) => node['@type']);
        for (const type of types) {
            assert.ok(found.includes(type), `${name} : type ${type} attendu, trouve : ${found.join(', ')}`);
        }
    }
});

test('le restaurant declare ses vrais moyens de paiement et relie sa carte (Cr 1.e.3)', () => {
    const nodes = typedNodes(jsonLdBlocks(page('index')));
    const restaurant = nodes.find((node) => node['@type'] === 'FastFoodRestaurant');

    // paymentAccepted decrit des moyens de paiement : les modes de consommation
    // (sur place, a emporter, drive) n'ont rien a y faire.
    assert.match(restaurant.paymentAccepted, /carte/i);
    assert.match(restaurant.paymentAccepted, /espèces/i);
    assert.doesNotMatch(restaurant.paymentAccepted, /emporter|sur place|drive/i);

    const menuOnCategories = typedNodes(jsonLdBlocks(page('categories'))).find((node) => node['@type'] === 'Menu');
    assert.equal(restaurant.hasMenu['@id'], menuOnCategories['@id'], 'le restaurant pointe vers la carte decrite sur categories.html');
});

test('chaque lien porte un title qui reprend son intitule, chaque image un alt (Cr 1.e.7)', () => {
    // Regle d'accessibilite retenue : un title de lien reprend au moins l'intitule du
    // lien (aria-label s'il existe, sinon le texte), faute de quoi il contredit ce
    // qu'annonce un lecteur d'ecran.
    for (const name of PAGES) {
        const doc = page(name);
        for (const link of doc.querySelectorAll('a[href]')) {
            const intitule = clean(link.getAttribute('aria-label') || link.textContent);
            const title = clean(link.getAttribute('title') || '');
            assert.notEqual(title, '', `${name} : le lien « ${intitule} » n'a pas de title`);
            assert.ok(title.toLowerCase().includes(intitule.toLowerCase()),
                `${name} : le title « ${title} » doit reprendre l'intitule « ${intitule} »`);
        }
        for (const image of doc.querySelectorAll('img')) {
            assert.ok(image.hasAttribute('alt'), `${name} : image sans alt : ${image.getAttribute('src')}`);
        }
    }
});

test('les expressions cles sont mises en exergue (Cr 1.e.2)', () => {
    const question = [...page('index').querySelectorAll('.welcome__question strong')]
        .map((node) => clean(node.textContent).toLowerCase());
    assert.ok(question.some((text) => text.includes('sur place')), 'accueil : « sur place » en exergue');
    assert.ok(question.some((text) => text.includes('emporter')), 'accueil : « a emporter » en exergue');

    assert.ok(page('categories').querySelector('.categories-main__sub strong'), 'categories : la consigne en exergue');

    const confirmation = page('confirmation');
    assert.equal(confirmation.getElementById('order-number').tagName, 'STRONG', 'confirmation : numero de commande');
    assert.ok(confirmation.querySelector('.confirmation-banner__sub em'), 'confirmation : etat de la commande');
});

test('la banniere d accueil est en format moderne, allegee et chargee en priorite (Cr 1.e.8)', () => {
    const doc = page('index');
    const picture = doc.querySelector('.welcome picture');
    assert.ok(picture, 'banniere servie par un element <picture>');

    const image = picture.querySelector('img.welcome__bg');
    assert.ok(image, 'image de repli dans le <picture>');
    // Fond visible des l'arrivee sur l'ecran : demande en priorite, pas en differe
    // (mesure dans tests/e2e/perf-accueil.spec.js et la preuve 08).
    assert.notEqual(image.getAttribute('loading'), 'lazy');
    assert.equal(image.getAttribute('fetchpriority'), 'high');
    assert.ok(Number(image.getAttribute('width')) > 0 && Number(image.getAttribute('height')) > 0,
        'dimensions intrinseques declarees');

    const fallbackBytes = statSync(new URL(image.getAttribute('src'), BORNE)).size;
    const sources = [...picture.querySelectorAll('source')];
    assert.ok(sources.some((source) => source.getAttribute('type') === 'image/webp'), 'une source WebP');

    for (const source of sources) {
        const file = source.getAttribute('srcset');
        const bytes = statSync(new URL(file, BORNE)).size;
        assert.ok(bytes <= 200 * 1024, `${file} : ${bytes} octets, attendu 200 Kio au plus`);
        assert.ok(bytes * 4 <= fallbackBytes, `${file} : au moins 4 fois plus leger que le PNG de repli`);
    }
});
