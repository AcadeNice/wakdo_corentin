/*
 * Tests de confirm-modal.js (node:test + jsdom). Modale de confirmation d'un geste
 * destructeur : onConfirm n'est appele QUE sur confirmation explicite ; Annuler /
 * Echap / clic-fond ferment sans agir. Import dynamique apres pose des globals jsdom.
 *
 * Depuis l'integration d'a11y-dialog 8.1.5 (assets/vendor/a11y-dialog/, voir
 * NOTICE.md pour la provenance), une partie du comportement est deleguee a la
 * librairie plutot qu'ecrite a la main : piege de tabulation, fermeture par
 * Echap, restauration du focus au declencheur, pose de role="dialog" +
 * aria-modal sur le conteneur. Ce que la librairie NE couvre PAS reste ecrit a
 * la main (documente dans 05-librairies-js-c2d.md) : le blocage du defilement
 * du corps et le aria-hidden pose sur les elements de fond ; ces deux points
 * restent donc verifies ici explicitement, comme avant.
 *
 * Deux particularites de jsdom, decouvertes en ecrivant ces tests et a
 * connaitre pour ne pas les reintroduire par erreur :
 *
 * 1. a11y-dialog ecoute Echap/Tab sur `this.$el` (le conteneur), pas sur
 *    `document` (a la difference de l'ancien code maison). Un evenement doit
 *    donc etre distribue depuis l'element ACTIF (ou un descendant du
 *    conteneur), jamais directement sur `document`, sinon il ne traverse
 *    jamais la modale et le gestionnaire ne se declenche pas.
 * 2. a11y-dialog 8 ne considere un element "focusable" que s'il a une boite
 *    visible (`offsetWidth`/`offsetHeight`/`getClientRects().length`, voir
 *    focusable-selectors bundle : fonction isHidden). jsdom ne fait AUCUNE
 *    mise en page (ces trois valeurs restent a 0 par defaut : limite connue et
 *    documentee de jsdom, absente d'un vrai moteur de rendu) : sans le stub
 *    ci-dessous, le piege de tabulation trouve 0 element "focusable" et ne
 *    fait rien. C'est d'ailleurs pour cette meme raison que a11y-dialog teste
 *    SA PROPRE librairie avec Cypress (navigateur reel), pas jsdom
 *    (package.json du paquet : "test": "cypress run --browser chrome").
 */
import { test, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

let confirmAction;

before(async () => {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'https://kiosk.test/products.html' });
    global.window = dom.window;
    global.document = dom.window.document;
    global.localStorage = dom.window.localStorage;
    global.requestAnimationFrame = (cb) => cb();
    // a11y-dialog appelle `new CustomEvent(...)` sans prefixe (fire(), dans son
    // module). Sans ce global pose sur le MEME realm que `document`, le
    // CustomEvent construit vient du global Node natif : document.dispatchEvent
    // (jsdom) le rejette alors ("parameter 1 is not of type 'Event'"), les deux
    // ne partageant pas la meme classe Event en interne.
    global.CustomEvent = dom.window.CustomEvent;
    global.Event = dom.window.Event;
    // Stub de mise en page (point 2 ci-dessus) : une valeur non nulle constante
    // suffit, aucun test ici ne distingue un element visible d'un element
    // masque par CSS (jsdom n'applique de toute facon pas style.css).
    Object.defineProperty(dom.window.HTMLElement.prototype, 'offsetHeight', {
        configurable: true,
        get() { return 40; },
    });
    ({ confirmAction } = await import('../../src/public/borne/assets/js/confirm-modal.js'));
});

beforeEach(() => { document.body.innerHTML = ''; });

test('confirmAction: affiche une modale (role=dialog pose par a11y-dialog) avec le message', () => {
    confirmAction({ message: 'Abandonner ?', onConfirm: () => {} });
    const modal = document.querySelector('.confirm-overlay[role="dialog"]');
    assert.ok(modal, 'a11y-dialog doit poser role="dialog" sur le conteneur (.confirm-overlay = this.$el)');
    assert.equal(modal.getAttribute('aria-modal'), 'true');
    assert.match(document.querySelector('.confirm-modal__message').textContent, /Abandonner/);
});

test('confirmAction: la boite interne porte role=document (recommandation de balisage a11y-dialog)', () => {
    confirmAction({ message: 'x', onConfirm: () => {} });
    const box = document.querySelector('.confirm-overlay .confirm-modal');
    assert.ok(box);
    assert.equal(box.getAttribute('role'), 'document');
});

test('confirmAction: Confirmer appelle onConfirm puis ferme', () => {
    let called = 0;
    confirmAction({ message: 'x', onConfirm: () => { called++; } });
    document.querySelector('.confirm-modal__confirm').click();
    assert.equal(called, 1);
    assert.equal(document.querySelector('.confirm-overlay'), null);
});

test('confirmAction: Annuler ferme sans appeler onConfirm', () => {
    let called = 0;
    confirmAction({ message: 'x', onConfirm: () => { called++; } });
    document.querySelector('.confirm-modal__cancel').click();
    assert.equal(called, 0);
    assert.equal(document.querySelector('.confirm-overlay'), null);
});

test('confirmAction: Echap ferme sans appeler onConfirm', () => {
    let called = 0;
    confirmAction({ message: 'x', onConfirm: () => { called++; } });
    // a11y-dialog ecoute keydown sur le conteneur de la modale, pas sur
    // `document` : on distribue depuis l'element actif (autofocus = Annuler),
    // comme le ferait une vraie frappe clavier (voir note de tete de fichier).
    document.activeElement.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    assert.equal(called, 0);
    assert.equal(document.querySelector('.confirm-overlay'), null);
});

test('confirmAction: clic sur le fond ferme sans appeler onConfirm', () => {
    let called = 0;
    confirmAction({ message: 'x', onConfirm: () => { called++; } });
    const overlay = document.querySelector('.confirm-overlay');
    overlay.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    assert.equal(called, 0);
    assert.equal(document.querySelector('.confirm-overlay'), null);
});

test('confirmAction: le message est echappe (anti-XSS)', () => {
    confirmAction({ message: '<img src=x onerror=alert(1)>', onConfirm: () => {} });
    assert.equal(document.querySelectorAll('img[onerror]').length, 0);
});

test('confirmAction: le focus initial est sur Annuler (attribut autofocus, defaut sur = anti-confirmation-accidentelle)', () => {
    confirmAction({ message: 'x', onConfirm: () => {} });
    assert.equal(document.activeElement, document.querySelector('.confirm-modal__cancel'));
});

test('confirmAction: le focus revient au declencheur a la fermeture', () => {
    const trigger = document.createElement('button');
    trigger.textContent = 'Abandon';
    document.body.appendChild(trigger);
    trigger.focus();

    confirmAction({ message: 'x', onConfirm: () => {} });
    assert.notEqual(document.activeElement, trigger);

    document.querySelector('.confirm-modal__cancel').click();
    assert.equal(document.activeElement, trigger);
});

test('confirmAction: Tab depuis Confirmer (dernier bouton) boucle sur Annuler (premier)', () => {
    confirmAction({ message: 'x', onConfirm: () => {} });
    const cancelBtn = document.querySelector('.confirm-modal__cancel');
    const confirmBtn = document.querySelector('.confirm-modal__confirm');
    confirmBtn.focus();
    confirmBtn.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    assert.equal(document.activeElement, cancelBtn);
});

test('confirmAction: Shift+Tab depuis Annuler (premier bouton) boucle sur Confirmer (dernier)', () => {
    confirmAction({ message: 'x', onConfirm: () => {} });
    const cancelBtn = document.querySelector('.confirm-modal__cancel');
    const confirmBtn = document.querySelector('.confirm-modal__confirm');
    cancelBtn.focus();
    cancelBtn.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
    assert.equal(document.activeElement, confirmBtn);
});

test('confirmAction: pose aria-hidden sur les freres du corps et le retire a la fermeture (hors perimetre a11y-dialog, ecrit a la main)', () => {
    const sibling = document.createElement('div');
    sibling.id = 'app-root';
    document.body.appendChild(sibling);

    confirmAction({ message: 'x', onConfirm: () => {} });
    assert.equal(sibling.getAttribute('aria-hidden'), 'true');

    document.querySelector('.confirm-modal__cancel').click();
    assert.equal(sibling.hasAttribute('aria-hidden'), false);
});

test("confirmAction: bloque le defilement du corps pendant l'affichage et le restaure a la fermeture (hors perimetre a11y-dialog, branche sur dialog.on)", () => {
    document.body.style.overflow = '';
    confirmAction({ message: 'x', onConfirm: () => {} });
    assert.equal(document.body.style.overflow, 'hidden');

    document.querySelector('.confirm-modal__cancel').click();
    assert.equal(document.body.style.overflow, '');
});

test('confirmAction: instance a usage unique - le listener document(click) pose par le constructeur a11y-dialog est libere apres fermeture', async () => {
    const originalAdd = document.addEventListener.bind(document);
    const originalRemove = document.removeEventListener.bind(document);
    let added = 0;
    let removed = 0;
    document.addEventListener = (type, handler, options) => {
        if (type === 'click' && options === true) added++;
        return originalAdd(type, handler, options);
    };
    document.removeEventListener = (type, handler, options) => {
        if (type === 'click' && options === true) removed++;
        return originalRemove(type, handler, options);
    };

    try {
        confirmAction({ message: 'x', onConfirm: () => {} });
        document.querySelector('.confirm-modal__cancel').click();
        // dialog.destroy() est differe en microtask (voir confirm-modal.js) pour
        // laisser hide() se terminer avant de rappeler destroy->hide. On laisse
        // la file de microtaches s'ecouler avant de verifier.
        await Promise.resolve();
        await Promise.resolve();
        assert.equal(added, 1, 'un seul listener document(click,capture) pose par instance');
        assert.equal(removed, 1, 'il doit etre libere a la fermeture (destroy), pas laisse pendu');
    } finally {
        document.addEventListener = originalAdd;
        document.removeEventListener = originalRemove;
    }
});
