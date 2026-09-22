/*
 * a11y.spec.js — Audit d'accessibilite MESURE (moteur axe-core) sur la borne et le
 * back-office.
 *
 * POURQUOI ce fichier existe : la preuve `04-accessibilite-rgaa.md` posait une reserve
 * explicite — « les ratios de contraste exacts n'ont pas ete mesures avec un outil
 * dedie ». Les tests jsdom du depot lisent le balisage, ils ne calculent aucun ratio
 * (jsdom ne fait pas de rendu, donc pas de couleur calculee). Il faut un navigateur
 * reel : axe-core tourne DANS la page, lit les styles calcules, et rend un ratio
 * chiffre par noeud de texte. C'est ce chiffre qui comble le trou.
 *
 * Le build CommonJS de @axe-core/playwright promeut son export par defaut en
 * module.exports : aucun `.default` a dereferencer ici (contrairement au build ESM).
 *
 * Ce fichier a DEUX roles, volontairement separes :
 *  1. Une barriere de non-regression, active a chaque `tests/e2e/run.sh`. Elle echoue
 *     si une regle WCAG AA NOUVELLE apparait sur une page (voir ACCEPTE).
 *  2. Un producteur d'artefacts pour le dossier de soutenance. L'ecriture n'a lieu
 *     que si A11Y_OUT est defini (`tests/e2e/run-a11y.sh` le pose). Sans cette
 *     variable, un `run.sh` ordinaire ne depose aucun fichier dans docs/ : la
 *     barriere joue, le dossier de preuves n'est pas reecrit par accident.
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright');

/*
 * Le RGAA n'est pas un jeu de regles a part : sa methodologie teste les criteres de
 * succes WCAG jusqu'au niveau AA. On active donc exactement les familles de regles
 * axe correspondantes, ni plus ni moins :
 *   wcag2a / wcag2aa   -> WCAG 2.0 niveaux A et AA
 *   wcag21a / wcag21aa -> les ajouts de WCAG 2.1 (dont 1.4.11 contraste non-texte)
 * Les regles axe taguees `best-practice` sont volontairement HORS de ce jeu : elles
 * ne sont opposables sous aucun critere RGAA, et les melanger gonflerait le compte de
 * violations avec des remarques de confort. Une campagne informative les activerait
 * separement.
 */
const TAGS_WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const ADMIN = 'http://admin.wakdo.test';
const ADMIN_EMAIL = 'admin@wakdo.local';
const ADMIN_PASSWORD = 'WakdoAdmin2026!';

const SORTIE = process.env.A11Y_OUT || '';

/*
 * Etat client injecte AVANT le chargement de chaque page borne.
 *
 * POURQUOI : trois des cinq ecrans refusent de s'afficher a vide. `nav.js` renvoie a
 * l'accueil toute page profonde sans mode de consommation memorise, et
 * `page-payment.js` renvoie aux categories si le panier est vide. Sans etat, on
 * auditerait trois fois l'accueil en croyant auditer trois ecrans.
 *
 * On seme donc localStorage/sessionStorage, c'est-a-dire de l'etat purement client.
 * AUCUNE commande n'est creee : `checkout.submitOrder` n'est jamais appele, aucun
 * POST ne part. L'audit lit le document, il ne le fait pas muter cote serveur.
 */
const ETAT_CLIENT = {
    mode: 'sur-place',
    // Deux lignes, dont une a quantite 2 : le panneau de commande rend alors ses
    // steppers de quantite et ses boutons de retrait, qui sont eux-memes une surface
    // d'accessibilite a auditer (aria-label, role=group).
    panier: [
        { id: 1, type: 'produit', categorie: 2, libelle: 'Audit A11Y - article 1', prix_cents: 250, quantite: 2, image: null },
        { id: 2, type: 'produit', categorie: 2, libelle: 'Audit A11Y - article 2', prix_cents: 200, quantite: 1, image: null },
    ],
    derniereCommande: { order_number: 'WK-AUDIT-A11Y', total_ttc_cents: 700 },
};

/*
 * Violations DEJA MESUREES et assumees, page par page, avec leur plan de correction
 * dans `docs/soutenance/preuves/06-audit-accessibilite-mesure.md`.
 *
 * POURQUOI une liste plutot qu'un `expect(violations).toEqual([])` : un audit honnete
 * part de l'etat reel, pas d'un etat souhaite. Figer ici ce qui a ete constate rend
 * la barriere utile tout de suite — toute regle NOUVELLE fait echouer le test — sans
 * mentir sur une conformite qui ne serait pas acquise. Une entree se retire quand la
 * correction est faite, jamais pour faire passer un test.
 */
const ACCEPTE = {
    accueil: [],
    categories: [],
    produits: [],
    // Zero violation une fois l'animation d'ouverture terminee. Les violations de
    // contraste vues avant l'attente d'animation etaient un artefact de mesure (fond
    // composite transitoire), pas un defaut de la page.
    'produits-modale-options': [],
    paiement: [],
    // Libelle "Votre numero de commande", #767676 sur #f5f5f5 -> 4,16:1 (seuil 4,5:1).
    // Le token vise le blanc pur, ou il passe ; c'est le fond gris de la banniere qui
    // le fait tomber sous le seuil.
    confirmation: ['color-contrast'],
    'admin-connexion': [],
    // Sidebar "do" (#c8920a sur blanc, 2,77:1) et sous-titres de page
    // (#6b7280 sur #f5f5f5, 4,43:1). Memes deux causes sur les quatre ecrans admin.
    'admin-tableau-de-bord': ['color-contrast'],
    'admin-ingredients': ['color-contrast'],
    'admin-produits': ['color-contrast'],
    'admin-commandes': ['color-contrast'],
};

const GRAVITES = ['critical', 'serious', 'moderate', 'minor'];

/** Accumulateur inter-tests : alimente le resume et le CSV de contrastes en fin de run. */
const resultats = [];

/**
 * Compte les violations par gravite axe (critical / serious / moderate / minor).
 * @param {Array} violations results.violations
 * @returns {Object}
 */
function compterParGravite(violations) {
    const compte = Object.fromEntries(GRAVITES.map(g => [g, 0]));
    for (const v of violations) {
        const noeuds = v.nodes.length;
        // `impact` peut etre null sur une regle sans gravite declaree : on la range en
        // `minor` plutot que de la perdre silencieusement du comptage.
        const g = GRAVITES.includes(v.impact) ? v.impact : 'minor';
        compte[g] += noeuds;
    }
    return compte;
}

/**
 * Extrait les mesures de contraste de TOUS les compartiments du resultat axe.
 *
 * POURQUOI lire aussi `passes` : c'est le seul endroit ou vivent les ratios des
 * elements CONFORMES. C'est precisement ce que la preuve 04 disait ne pas avoir —
 * un chiffre pour le texte attenue `#767676`, pour le jaune d'accent, etc. Ne lire
 * que `violations` produirait un rapport qui ne parle que de ce qui casse.
 *
 * @param {Object} resultats objet rendu par AxeBuilder.analyze()
 * @returns {Array} lignes de mesure
 */
function extraireContrastes(resultats) {
    const lignes = [];
    const compartiments = [
        ['conforme', resultats.passes],
        ['violation', resultats.violations],
        ['indetermine', resultats.incomplete],
    ];
    for (const [compartiment, regles] of compartiments) {
        for (const regle of regles) {
            if (regle.id !== 'color-contrast' && regle.id !== 'color-contrast-enhanced') continue;
            for (const noeud of regle.nodes) {
                const controles = [...(noeud.any || []), ...(noeud.all || []), ...(noeud.none || [])];
                for (const controle of controles) {
                    const d = controle.data;
                    if (!d || typeof d.contrastRatio !== 'number') continue;
                    // axe rend un ratio de 0 avec des couleurs `undefined` quand il n'a
                    // PAS pu determiner le fond (image, degrade, SVG). Ce n'est pas une
                    // mesure a 0 : c'est une absence de mesure. La laisser entrer ferait
                    // afficher un contraste nul la ou il n'y a rien de mesure. Ces cas
                    // sont comptes a part, via les resultats indetermines.
                    if (!d.fgColor || !d.bgColor) continue;
                    lignes.push({
                        compartiment,
                        selecteur: (noeud.target || []).join(' '),
                        extrait: String(noeud.html || '').replace(/\s+/g, ' ').slice(0, 100),
                        avant_plan: d.fgColor,
                        arriere_plan: d.bgColor,
                        ratio: Number(d.contrastRatio.toFixed(2)),
                        seuil_attendu: d.expectedContrastRatio || '',
                        taille_px: d.fontSize || '',
                        graisse: d.fontWeight || '',
                    });
                }
            }
        }
    }
    return lignes;
}

/**
 * Reduit un resultat axe a un artefact versionnable.
 *
 * POURQUOI ne pas ecrire le JSON brut integral : `passes` contient un noeud par
 * element teste par chaque regle, soit plusieurs megaoctets par page. Un dossier de
 * preuves illisible n'est pas une preuve. On conserve INTEGRALEMENT ce qui porte
 * l'information (violations, indetermines, et la regle de contraste avec toutes ses
 * mesures) et on reduit le reste a un decompte. La reduction est annoncee dans le
 * rapport, elle n'est pas silencieuse.
 */
function artefact(cle, url, res) {
    return {
        page: cle,
        url,
        date: new Date().toISOString(),
        moteur: { axe: res.testEngine, regles: TAGS_WCAG_AA },
        navigateur: res.testEnvironment,
        violations: res.violations,
        indetermines: res.incomplete,
        contrastes_mesures: extraireContrastes(res),
        conformes_resume: res.passes
            .map(p => ({ id: p.id, noeuds: p.nodes.length }))
            .sort((a, b) => a.id.localeCompare(b.id)),
        non_applicables: res.inapplicable.map(p => p.id).sort(),
    };
}

/**
 * Lance axe sur la page courante, accumule le resultat, et applique la barriere.
 * @param {import('@playwright/test').Page} page
 * @param {string} cle identifiant court de l'ecran (sert de nom de fichier)
 */
async function auditer(page, cle) {
    const res = await new AxeBuilder({ page }).withTags(TAGS_WCAG_AA).analyze();
    const url = page.url();
    const compte = compterParGravite(res.violations);
    const regles = [...new Set(res.violations.map(v => v.id))].sort();

    // Remplacement plutot qu'ajout : sous CI, Playwright rejoue une fois un test en
    // echec. Sans cette deduplication, le resume porterait deux fois les memes ecrans.
    const dejaVu = resultats.findIndex(r => r.page === cle);
    const mesure = {
        page: cle,
        url,
        total_violations: res.violations.length,
        noeuds_en_faute: Object.values(compte).reduce((a, b) => a + b, 0),
        par_gravite: compte,
        regles,
        regles_indeterminees: [...new Set(res.incomplete.map(v => v.id))].sort(),
        regles_non_documentees: regles.filter(r => !(ACCEPTE[cle] || []).includes(r)),
    };
    if (dejaVu >= 0) resultats[dejaVu] = mesure; else resultats.push(mesure);

    if (SORTIE) {
        fs.mkdirSync(SORTIE, { recursive: true });
        fs.writeFileSync(
            path.join(SORTIE, `axe-${cle}.json`),
            JSON.stringify(artefact(cle, url, res), null, 2),
        );
    }

}

/**
 * Barriere de non-regression, appliquee APRES que tous les ecrans ont ete audites.
 *
 * POURQUOI a la fin et pas dans auditer() : une assertion qui echoue interrompt le
 * test. Si elle tombait sur le premier ecran, les suivants ne seraient jamais
 * mesures et le rapport serait tronque au premier probleme. Un audit doit rendre
 * l'image complete, PUIS echouer.
 *
 * @param {string[]} cles ecrans a controler
 */
function verifierBarriere(cles) {
    const fautifs = resultats
        .filter(r => cles.includes(r.page) && r.regles_non_documentees.length)
        .map(r => `${r.page} -> ${r.regles_non_documentees.join(', ')}`);
    expect(fautifs, `regles WCAG AA non documentees dans ACCEPTE :\n${fautifs.join('\n')}`).toEqual([]);
}

/** Injecte l'etat client avant tout script de page (rejoue a chaque navigation). */
async function semerEtatBorne(page) {
    await page.addInitScript(etat => {
        try {
            localStorage.setItem('wakdo_mode', etat.mode);
            localStorage.setItem('wakdo_cart', JSON.stringify(etat.panier));
            sessionStorage.setItem('wakdo_last_order', JSON.stringify(etat.derniereCommande));
        } catch {
            // Stockage indisponible : la page retombe sur son etat vide. L'audit reste
            // valide sur ce qui s'affiche, il ne doit pas planter pour autant.
        }
    }, ETAT_CLIENT);
}

test.describe('audit d\'accessibilite mesure (axe-core, regles WCAG AA)', () => {
    /*
     * Pas de relance : un scan axe est deterministe (meme page, meme resultat), donc
     * rejouer un echec ne fait que doubler la duree. Surtout, Playwright repart d'un
     * worker NEUF a chaque relance : l'etat de module du worker precedent est perdu.
     * Avec une relance, le resume final ne portait plus que le dernier test joue.
     */
    test.describe.configure({ retries: 0 });

    /*
     * Pas de mode `serial` : les deux tests sont independants (la configuration tourne
     * deja sur un seul worker, l'ordre est donc garanti). En serie, un echec cote borne
     * ferait SAUTER l'audit du back-office, et le rapport perdrait la moitie de son
     * perimetre alors que rien ne l'empechait d'etre mesure.
     */
    test('borne : les 5 ecrans client, plus la modale d\'options', async ({ page }) => {
        test.setTimeout(180000);
        // Resolution reelle de la borne (ecran tactile fixe portrait), et non le
        // 1280x720 du profil Desktop Chrome : les regles sensibles a la mise en page
        // (cible tactile, chevauchement, contenu reflowe) doivent etre evaluees sur la
        // geometrie que le client voit vraiment.
        await page.setViewportSize({ width: 1080, height: 1920 });
        await semerEtatBorne(page);

        await page.goto('/index.html');
        await expect(page.locator('#welcome-heading')).toBeVisible();
        await auditer(page, 'accueil');

        await page.goto('/categories.html');
        // La grille est peuplee depuis GET /api/categories : on attend une carte reelle,
        // sinon on auditerait un conteneur vide et on croirait la page propre.
        await expect(page.locator('#category-grid a.category-card').first()).toBeVisible();
        await auditer(page, 'categories');

        await page.goto('/products.html?category=2');
        const premiereCarte = page.locator('#products-grid a.product-card:not(.product-card--unavailable)').first();
        await expect(premiereCarte).toBeVisible();
        await expect(page.locator('[data-order-panel] .order-panel__line').first()).toBeVisible();
        await auditer(page, 'produits');

        // La modale d'options est entierement construite en JavaScript : son balisage
        // n'existe dans aucun fichier .html et echappe donc a toute lecture statique.
        await premiereCarte.click();
        const modale = page.locator('.composer-overlay');
        await expect(modale.locator('[role="dialog"]')).toBeVisible();
        // La modale entre par une animation (`composer-fade-in`, style.css). Mesurer
        // pendant l'animation donne un fond COMPOSITE transitoire : deux passages du
        // meme audit ont rendu #e9e9e9 puis #e3e3e3 pour le meme element. Un chiffre
        // non reproductible n'est pas une preuve. On attend donc que toutes les
        // animations de la modale soient terminees avant de lire les couleurs.
        await modale.evaluate(el => Promise.all(el.getAnimations({ subtree: true }).map(a => a.finished)));
        await auditer(page, 'produits-modale-options');

        await page.goto('/payment.html');
        await expect(page.locator('#payment-recap .payment-recap__total')).toBeVisible();
        await auditer(page, 'paiement');

        await page.goto('/confirmation.html');
        await expect(page.locator('.confirmation-banner__title')).toBeVisible();
        await auditer(page, 'confirmation');

        verifierBarriere(['accueil', 'categories', 'produits', 'produits-modale-options', 'paiement', 'confirmation']);
    });

    test('back-office : connexion, tableau de bord, ingredients, produits, commandes', async ({ page }) => {
        test.setTimeout(180000);
        // Le back-office cible un poste de gestion (desktop/tablette paysage), pas la
        // borne : on l'audite donc a une resolution de bureau courante.
        await page.setViewportSize({ width: 1440, height: 900 });

        await page.goto(`${ADMIN}/login`);
        await expect(page.locator('#email')).toBeVisible();
        await auditer(page, 'admin-connexion');

        await page.fill('#email', ADMIN_EMAIL);
        await page.fill('#password', ADMIN_PASSWORD);
        await page.locator('form[action="/login"] button[type="submit"]').click();
        await expect(page).toHaveURL(/\/admin\/dashboard/);
        await auditer(page, 'admin-tableau-de-bord');

        // Vue la plus longue du back-office, et la seule porteuse d'un sommaire d'ancres
        // (Cr 1.e.11) : c'est la que la mesure a le plus de chances de trouver quelque
        // chose.
        await page.goto(`${ADMIN}/admin/ingredients`);
        await expect(page.locator('main.content')).toBeVisible();
        await auditer(page, 'admin-ingredients');

        await page.goto(`${ADMIN}/admin/products`);
        await expect(page.locator('main.content')).toBeVisible();
        await auditer(page, 'admin-produits');

        await page.goto(`${ADMIN}/admin/orders`);
        await expect(page.locator('main.content')).toBeVisible();
        await auditer(page, 'admin-commandes');

        verifierBarriere(['admin-connexion', 'admin-tableau-de-bord', 'admin-ingredients', 'admin-produits', 'admin-commandes']);
    });

    test.afterAll(() => {
        if (!SORTIE || !fs.existsSync(SORTIE)) return;

        /*
         * Le resume se reconstruit depuis les artefacts DEPOSES SUR DISQUE, pas depuis
         * l'accumulateur en memoire. Playwright peut repartir les tests sur plusieurs
         * processus : un accumulateur de module ne voit alors qu'une partie des ecrans,
         * et le resume mentirait par omission. Le disque, lui, a tout.
         */
        const artefacts = fs
            .readdirSync(SORTIE)
            .filter(f => f.startsWith('axe-') && f.endsWith('.json'))
            .map(f => JSON.parse(fs.readFileSync(path.join(SORTIE, f), 'utf8')))
            .sort((a, b) => a.page.localeCompare(b.page));
        if (!artefacts.length) return;

        const total = Object.fromEntries(GRAVITES.map(g => [g, 0]));
        const pages = artefacts.map(a => {
            const compte = compterParGravite(a.violations);
            for (const g of GRAVITES) total[g] += compte[g];
            const contrastes = a.contrastes_mesures;
            const enFaute = contrastes.filter(c => c.compartiment === 'violation');
            return {
                page: a.page,
                url: a.url,
                total_violations: a.violations.length,
                noeuds_en_faute: Object.values(compte).reduce((x, y) => x + y, 0),
                par_gravite: compte,
                regles: [...new Set(a.violations.map(v => v.id))].sort(),
                regles_indeterminees: [...new Set(a.indetermines.map(v => v.id))].sort(),
                contrastes_mesures: contrastes.length,
                // Noeuds de texte qu'axe n'a PAS pu chiffrer (fond image, degrade, SVG) :
                // ils ne sont ni conformes ni fautifs, ils restent a verifier a la main.
                contrastes_non_calculables: a.indetermines
                    .filter(v => v.id === 'color-contrast')
                    .reduce((n, v) => n + v.nodes.length, 0),
                contraste_minimum_mesure: contrastes.length
                    ? Math.min(...contrastes.map(c => c.ratio))
                    : null,
                contrastes_sous_le_seuil: enFaute.length,
            };
        });

        fs.writeFileSync(
            path.join(SORTIE, 'resume.json'),
            JSON.stringify(
                {
                    date: new Date().toISOString(),
                    outil: artefacts[0].moteur,
                    ecrans: pages.length,
                    pages,
                    total_noeuds_en_faute_par_gravite: total,
                },
                null,
                2,
            ),
        );

        // CSV separe par point-virgule : separateur attendu par un tableur en locale
        // francaise, pour que le jury ouvre le fichier sans manipulation.
        const entete = 'page;compartiment;selecteur;avant_plan;arriere_plan;ratio;seuil_attendu;taille_px;graisse;extrait';
        const lignes = [entete];
        for (const a of artefacts) {
            for (const c of a.contrastes_mesures) {
                lignes.push(
                    [
                        a.page, c.compartiment, c.selecteur, c.avant_plan, c.arriere_plan,
                        String(c.ratio).replace('.', ','), c.seuil_attendu, c.taille_px, c.graisse,
                        String(c.extrait).replace(/[;\r\n]/g, ' '),
                    ].join(';'),
                );
            }
        }
        fs.writeFileSync(path.join(SORTIE, 'contrastes-mesures.csv'), lignes.join('\n') + '\n');
    });
});
