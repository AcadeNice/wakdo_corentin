/*
 * seo.js — Aides de referencement de la borne (Bloc 1, Cr 1.e.3 et 1.e.5).
 *
 * La page produits ne connait sa categorie et ses produits qu'apres le chargement
 * de l'API : ses donnees structurees et son titre sont donc completes ici, a partir
 * des memes objets que ceux qui dessinent la grille (forme data.js : nom, prix en
 * centimes, image relative, commandable). Fonctions pures + une ecriture DOM isolee,
 * testables sans reseau.
 */

const SCHEMA = 'https://schema.org';
const IN_STOCK = 'https://schema.org/InStock';
const OUT_OF_STOCK = 'https://schema.org/OutOfStock';

/**
 * Section de carte schema.org : la categorie affichee et un MenuItem par produit,
 * avec son offre (prix en euros, disponibilite). Un produit en rupture reste a la
 * carte (RG-T21) : son offre le dit indisponible plutot que de le faire disparaitre.
 * @param {{ name: string, url: string, products: Array, baseUrl: string }} params
 * @returns {object}
 */
export function buildMenuSection({ name, url, products, baseUrl }) {
    return {
        '@context': SCHEMA,
        '@type': 'MenuSection',
        '@id': `${url}#section`,
        name,
        url,
        inLanguage: 'fr-FR',
        isPartOf: { '@id': new URL('categories.html#carte', baseUrl).href },
        hasMenuItem: products.map((product) => menuItem(product, baseUrl)),
    };
}

function menuItem(product, baseUrl) {
    const item = {
        '@type': 'MenuItem',
        name: product.nom,
        offers: {
            '@type': 'Offer',
            // schema.org attend un prix decimal a point : 1250 centimes -> "12.50".
            price: (product.prix / 100).toFixed(2),
            priceCurrency: 'EUR',
            availability: product.commandable === false ? OUT_OF_STOCK : IN_STOCK,
        },
    };
    if (product.image) {
        item.image = new URL(product.image, baseUrl).href;
    }
    return item;
}

/**
 * Ecrit un bloc JSON-LD dans <head>, en remplacant celui qui porte deja cet id
 * (le bloc de depart de products.html) au lieu d'en empiler un second.
 * Le chevron ouvrant « < » est encode : un nom de produit contenant « </script> »
 * reste une donnee et ne peut pas fermer la balise si la page est re-serialisee.
 * @param {Document} doc
 * @param {string} id
 * @param {object} data
 */
export function upsertJsonLd(doc, id, data) {
    let script = doc.getElementById(id);
    if (!script) {
        script = doc.createElement('script');
        script.id = id;
        doc.head.appendChild(script);
    }
    script.type = 'application/ld+json';
    script.textContent = JSON.stringify(data).replace(/</g, '\\u003c');
}

/**
 * Titre de la page produits une fois la categorie connue (Cr 1.e.5) : il nomme la
 * categorie ET la borne, dans la fourchette de longueur des autres pages.
 * @param {string} heading — ex. « Nos Burgers »
 * @returns {string}
 */
export function categoryPageTitle(heading) {
    return `${heading} - Borne de commande Wakdo`;
}
