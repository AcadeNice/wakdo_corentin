/*
 * w3c-capture.spec.js — Capture du DOM RENDU de la borne, pour le validateur W3C Nu.
 *
 * POURQUOI ce fichier existe : la borne construit une partie de son contenu cote client
 * (grille de categories, cartes produit, modale allergenes). Valider les fichiers
 * `.html` servis ne dit donc rien du balisage que le client voit reellement. La preuve
 * `docs/soutenance/preuves/01-validation-w3c.md` appelle ca le « niveau 2 » : on
 * serialise `document.documentElement.outerHTML` apres execution du JavaScript, et on
 * soumet CE document au moteur Nu.
 *
 * Jusqu'ici la capture etait faite a la main, sans script versionne : les fichiers de
 * `w3c/dom-rendu/` ne pouvaient donc pas etre refaits a l'identique. Ce fichier rend
 * l'operation reproductible ; `tests/e2e/run-w3c.sh` l'enchaine avec la validation.
 *
 * Le test ne s'execute QUE si `W3C_OUT` est defini (le script le pose). Sans cette
 * variable il est saute : un `tests/e2e/run.sh` ordinaire ne reecrit pas le dossier de
 * preuves par accident. Meme garde que `A11Y_OUT` dans `a11y.spec.js`.
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

const SORTIE = process.env.W3C_OUT || '';

/*
 * Etat client seme avant chargement, identique a celui de `a11y.spec.js` : sans mode de
 * consommation memorise, `nav.js` renvoie toute page profonde a l'accueil. AUCUNE
 * commande n'est creee — on lit le document, on ne le fait pas muter cote serveur.
 */
const ETAT_CLIENT = { mode: 'sur-place' };

/**
 * Serialise le document courant et le depose sous W3C_OUT.
 * @param {import('@playwright/test').Page} page
 * @param {string} nom nom de fichier, sans extension
 */
async function capturer(page, nom) {
    const html = await page.evaluate(() => document.documentElement.outerHTML);
    // `outerHTML` ne porte pas la declaration de type de document : le validateur la
    // reclamerait comme une erreur, alors que la page servie la declare bien. On la
    // repose donc telle quelle, exactement comme les captures d'origine du dossier.
    fs.writeFileSync(path.join(SORTIE, `${nom}.html`), `<!DOCTYPE html>${html}\n`);
}

test.describe('capture du DOM rendu de la borne (validation W3C niveau 2)', () => {
    test.skip(!SORTIE, 'capture desactivee : poser W3C_OUT pour ecrire les fichiers');
    test.describe.configure({ retries: 0 });

    test('accueil, categories, produits, et produits avec la modale allergenes', async ({ page }) => {
        test.setTimeout(120000);
        fs.mkdirSync(SORTIE, { recursive: true });

        // Geometrie reelle de la borne : la capture doit refleter ce que le client voit.
        await page.setViewportSize({ width: 1080, height: 1920 });
        await page.addInitScript(etat => {
            try {
                localStorage.setItem('wakdo_mode', etat.mode);
            } catch {
                // Stockage indisponible : la page retombe sur son etat vide. La capture
                // reste valide sur ce qui s'affiche.
            }
        }, ETAT_CLIENT);

        await page.goto('/index.html');
        await expect(page.locator('#welcome-heading')).toBeVisible();
        await capturer(page, 'accueil');

        // Grille peuplee depuis GET /api/categories : attendre une carte reelle, sinon
        // on capturerait un conteneur vide en croyant capturer l'ecran.
        await page.goto('/categories.html');
        await expect(page.locator('#category-grid a.category-card').first()).toBeVisible();
        await capturer(page, 'categories');

        await page.goto('/products.html?category=2');
        const premiereCarte = page.locator('#products-grid a.product-card').first();
        await expect(premiereCarte).toBeVisible();
        await capturer(page, 'produits');

        // Modale allergenes : son balisage est entierement genere au clic et n'existe
        // dans aucun fichier .html. C'est la surface que la capture d'etat ferme
        // laissait hors validation (01-validation-w3c.md, section 2).
        await page.locator('.allergen-info-btn').first().click();
        await expect(page.locator('.allergen-modal-overlay[role="dialog"] .allergen-modal')).toBeVisible();
        await capturer(page, 'produits-modale-allergenes');
    });
});
