/*
 * Tests de image-drop.js (node:test + jsdom). Zone de glisser-deposer en
 * complement du champ <input type="file"> du formulaire produit/categorie.
 *
 * Couvre : formatage lisible(), pre-remplissage du message + apercu au choix
 * d'un fichier (change), alerte "trop lourd" au-dela de MAX_MB, remise a l'etat
 * initial quand le champ se vide, retour visuel du survol (dragenter/dragover/
 * dragleave/dragend), et le depot (drop) -- y compris son repli explicite quand
 * le navigateur ne fournit pas DataTransfer (le cas de cet environnement de test :
 * jsdom 26 n'implemente ni DataTransfer ni DragEvent, verifie empiriquement).
 *
 * FileReader.readAsDataURL exige un vrai Blob/File (jsdom le refuse sur un objet
 * litteral) : les fichiers "image" des tests sont donc de vrais window.File ; les
 * fichiers "trop lourds"/"non-image" restent des objets litteraux, puisque ces
 * chemins ne touchent jamais FileReader.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

// image-drop.js est du CommonJS (admin = racine CommonJS) ; import par defaut.
import imageDrop from '../../src/public/admin/assets/js/image-drop.js';

const HINT_INITIAL = 'Glissez une image ici, ou choisissez un fichier.';

function setup(zones = 1) {
    let markup = '';
    for (let i = 0; i < zones; i++) {
        markup +=
            `<div data-image-drop class="image-drop" id="zone-${i}">` +
            `  <input type="file" name="image_file_${i}">` +
            `  <p data-image-drop-hint>${HINT_INITIAL}</p>` +
            '  <img data-image-drop-preview hidden alt="Apercu">' +
            '</div>';
    }

    return new JSDOM(`<!DOCTYPE html><html><body>${markup}</body></html>`);
}

function fire(dom, el, type) {
    el.dispatchEvent(new dom.window.Event(type, { bubbles: true, cancelable: true }));
}

function setFiles(input, files) {
    Object.defineProperty(input, 'files', { value: files, configurable: true });
}

/** Attend qu'une condition devienne vraie (FileReader est asynchrone meme en jsdom). */
function attend(predicat, delaiMaxMs = 500) {
    return new Promise((resolve, reject) => {
        const debut = Date.now();
        (function verifie() {
            if (predicat()) {
                resolve();
                return;
            }
            if (Date.now() - debut > delaiMaxMs) {
                reject(new Error('timeout en attente de la condition'));
                return;
            }
            setTimeout(verifie, 5);
        }());
    });
}

/* --- lisible() (pur) ----------------------------------------------------- */

test('lisible : affiche en Ko sous le seuil de 0.1 Mo, en Mo avec une decimale au-dessus', () => {
    assert.equal(imageDrop.lisible(2 * 1024 * 1024), '2.0 Mo');
    assert.equal(imageDrop.lisible(50 * 1024), '50 Ko');
    assert.equal(imageDrop.lisible(512), '1 Ko');
});

/* --- init() : orchestration, tolerance au balisage incomplet ------------- */

test('init : une zone sans champ fichier ni message ne fait pas planter l initialisation', () => {
    const dom = new JSDOM('<!doctype html><body><div data-image-drop></div></body></html>');
    assert.doesNotThrow(() => imageDrop.init(dom.window.document));
});

test('init : cable independamment chaque zone [data-image-drop] de la page', () => {
    const dom = setup(2);
    const doc = dom.window.document;
    imageDrop.init(doc);

    const champ0 = doc.querySelector('#zone-0 input[type="file"]');
    setFiles(champ0, [{ name: 'a.zip', size: 100, type: 'application/zip' }]);
    fire(dom, champ0, 'change');

    assert.match(doc.querySelector('#zone-0 [data-image-drop-hint]').textContent, /a\.zip/);
    // La deuxieme zone n'a recu aucun evenement : son message reste l'initial.
    assert.equal(doc.querySelector('#zone-1 [data-image-drop-hint]').textContent, HINT_INITIAL);
});

/* --- champ fichier (change) : message + alerte de taille ----------------- */

test('change : un fichier non-image met a jour le message sans toucher l apercu', () => {
    const dom = setup();
    const doc = dom.window.document;
    imageDrop.init(doc);
    const champ = doc.querySelector('input[type="file"]');
    const apercu = doc.querySelector('[data-image-drop-preview]');

    setFiles(champ, [{ name: 'facture.pdf', size: 2048, type: 'application/pdf' }]);
    fire(dom, champ, 'change');

    assert.equal(doc.querySelector('[data-image-drop-hint]').textContent, 'facture.pdf (2 Ko)');
    assert.equal(apercu.hidden, true);
});

test('change : un fichier au-dela de MAX_MB (5 Mo) affiche l alerte de refus serveur', () => {
    const dom = setup();
    const doc = dom.window.document;
    imageDrop.init(doc);
    const champ = doc.querySelector('input[type="file"]');

    setFiles(champ, [{ name: 'gros.zip', size: 6 * 1024 * 1024, type: 'application/zip' }]);
    fire(dom, champ, 'change');

    assert.match(
        doc.querySelector('[data-image-drop-hint]').textContent,
        /trop lourd, le serveur refusera ce fichier/,
    );
});

test('change : un fichier image met a jour le message ET produit un apercu en data:', async () => {
    const dom = setup();
    const doc = dom.window.document;
    // image-drop.js reference le global FileReader (comme un vrai navigateur en
    // fournit toujours un) ; Node n'en expose pas par defaut, on retombe donc sur
    // celui de jsdom pour CE dom precis, meme technique que confirm-modal.test.js
    // pour window/localStorage/requestAnimationFrame.
    global.FileReader = dom.window.FileReader;
    imageDrop.init(doc);
    const champ = doc.querySelector('input[type="file"]');
    const apercu = doc.querySelector('[data-image-drop-preview]');

    const fichier = new dom.window.File(['contenu-image'], 'produit.png', { type: 'image/png' });
    setFiles(champ, [fichier]);
    fire(dom, champ, 'change');

    assert.match(doc.querySelector('[data-image-drop-hint]').textContent, /produit\.png/);

    await attend(() => apercu.hidden === false);
    assert.equal(apercu.hidden, false);
    assert.match(apercu.getAttribute('src'), /^data:image\/png;base64,/);
});

test('change : vider le champ restaure le message initial et masque l apercu', async () => {
    const dom = setup();
    const doc = dom.window.document;
    global.FileReader = dom.window.FileReader;
    imageDrop.init(doc);
    const champ = doc.querySelector('input[type="file"]');
    const apercu = doc.querySelector('[data-image-drop-preview]');

    // D'abord une image choisie (apercu visible)...
    const fichier = new dom.window.File(['contenu-image'], 'produit.png', { type: 'image/png' });
    setFiles(champ, [fichier]);
    fire(dom, champ, 'change');
    await attend(() => apercu.hidden === false);

    // ... puis le champ est vide (l'utilisateur a annule sa selection).
    setFiles(champ, []);
    fire(dom, champ, 'change');

    assert.equal(doc.querySelector('[data-image-drop-hint]').textContent, HINT_INITIAL);
    assert.equal(apercu.hidden, true);
    assert.equal(apercu.hasAttribute('src'), false);
});

/* --- survol (dragenter/dragover/dragleave/dragend) ------------------------ */

test('dragenter puis dragover ajoutent la classe visuelle de survol', () => {
    const dom = setup();
    const doc = dom.window.document;
    imageDrop.init(doc);
    const zone = doc.querySelector('[data-image-drop]');

    fire(dom, zone, 'dragenter');
    assert.equal(zone.classList.contains('is-dragover'), true);

    fire(dom, zone, 'dragover');
    assert.equal(zone.classList.contains('is-dragover'), true);
});

test('dragleave retire la classe de survol', () => {
    const dom = setup();
    const doc = dom.window.document;
    imageDrop.init(doc);
    const zone = doc.querySelector('[data-image-drop]');

    fire(dom, zone, 'dragenter');
    fire(dom, zone, 'dragleave');

    assert.equal(zone.classList.contains('is-dragover'), false);
});

test('dragend retire aussi la classe de survol', () => {
    const dom = setup();
    const doc = dom.window.document;
    imageDrop.init(doc);
    const zone = doc.querySelector('[data-image-drop]');

    fire(dom, zone, 'dragenter');
    fire(dom, zone, 'dragend');

    assert.equal(zone.classList.contains('is-dragover'), false);
});

/* --- depot (drop) ---------------------------------------------------------- */

test('drop sans fichier ne change ni le message ni l etat, et retire le survol', () => {
    const dom = setup();
    const doc = dom.window.document;
    imageDrop.init(doc);
    const zone = doc.querySelector('[data-image-drop]');

    fire(dom, zone, 'dragenter');
    const evenement = new dom.window.Event('drop', { bubbles: true, cancelable: true });
    evenement.dataTransfer = { files: [] };
    zone.dispatchEvent(evenement);

    assert.equal(zone.classList.contains('is-dragover'), false);
    assert.equal(doc.querySelector('[data-image-drop-hint]').textContent, HINT_INITIAL);
});

test('drop degrade proprement quand le navigateur ne fournit pas DataTransfer', () => {
    // jsdom 26 (verifie empiriquement) n'implemente pas DataTransfer : le code
    // passe reellement par son repli, pas par un mock qui le contournerait.
    assert.equal(typeof globalThis.DataTransfer, 'undefined');

    const dom = setup();
    const doc = dom.window.document;
    imageDrop.init(doc);
    const zone = doc.querySelector('[data-image-drop]');

    fire(dom, zone, 'dragenter');
    const evenement = new dom.window.Event('drop', { bubbles: true, cancelable: true });
    evenement.dataTransfer = { files: [{ name: 'photo.png', size: 10, type: 'image/png' }] };
    zone.dispatchEvent(evenement);

    assert.equal(zone.classList.contains('is-dragover'), false);
    assert.match(
        doc.querySelector('[data-image-drop-hint]').textContent,
        /Votre navigateur ne gere pas le depot/,
    );
});
