/*
 * Tests de l'animation JS du total panier (node:test, sans DOM reel).
 *
 * Cr 2.a.3/2.a.4 : l'unique animation PILOTEE EN JAVASCRIPT du projet (les trois
 * @keyframes de style.css restent des transitions CSS). Elle fait "compter" le total
 * du panneau de commande d'une valeur a l'autre au lieu de le faire sauter, pour que
 * le client voie que son geste (ajout, retrait, changement de quantite) a ete pris
 * en compte.
 *
 * Aucun jsdom ici : `animateTotalValue` ne touche qu'un `el.textContent` fourni par
 * l'appelant, donc un objet litteral suffit de cible. `raf`/`caf`/`now` sont
 * injectes a chaque test : c'est ce qui rend une animation temporelle testable sans
 * horloge reelle ni navigateur.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatPrice } from '../../src/public/borne/assets/js/state.js';
import {
    easeOutCubic,
    computeFrameValueCents,
    prefersReducedMotion,
    animateTotalValue,
} from '../../src/public/borne/assets/js/cart-total-animation.js';

/* --- easeOutCubic (pur) --------------------------------------------------- */

test('easeOutCubic: bornes exactes 0 -> 0 et 1 -> 1', () => {
    assert.equal(easeOutCubic(0), 0);
    assert.equal(easeOutCubic(1), 1);
});

test('easeOutCubic: croissante et toujours dans [0, 1] sur l intervalle', () => {
    const echantillons = [0, 0.1, 0.25, 0.4, 0.5, 0.6, 0.75, 0.9, 1].map(easeOutCubic);
    for (let i = 1; i < echantillons.length; i += 1) {
        assert.ok(echantillons[i] >= echantillons[i - 1], 'doit rester croissante');
    }
    echantillons.forEach(v => {
        assert.ok(v >= 0 && v <= 1, `hors bornes: ${v}`);
    });
});

test('easeOutCubic: ralentit vers la fin (deceleration), pas une simple droite', () => {
    // Une progression lineaire donnerait 0.5 a t=0.5 ; l attenuation "ease-out" doit
    // etre DEJA plus avancee a mi-parcours (depart rapide, arrivee en douceur).
    assert.ok(easeOutCubic(0.5) > 0.5);
});

test('easeOutCubic: valeurs hors [0,1] sont bornees (repli defensif)', () => {
    assert.equal(easeOutCubic(-1), 0);
    assert.equal(easeOutCubic(2), 1);
});

/* --- computeFrameValueCents (pur) ----------------------------------------- */

test('computeFrameValueCents: progression 0 -> valeur de depart exacte', () => {
    assert.equal(computeFrameValueCents(490, 1290, 0), 490);
});

test('computeFrameValueCents: progression 1 -> valeur d arrivee exacte', () => {
    assert.equal(computeFrameValueCents(490, 1290, 1), 1290);
});

test('computeFrameValueCents: mi-parcours strictement entre les deux valeurs (hausse)', () => {
    const v = computeFrameValueCents(0, 1000, 0.5);
    assert.ok(v > 0 && v < 1000, `attendu entre 0 et 1000, recu ${v}`);
});

test('computeFrameValueCents: fonctionne aussi quand le total BAISSE (retrait/quantite)', () => {
    const v = computeFrameValueCents(1000, 400, 0.5);
    assert.ok(v < 1000 && v > 400, `attendu entre 400 et 1000, recu ${v}`);
    assert.equal(computeFrameValueCents(1000, 400, 1), 400);
});

test('computeFrameValueCents: toujours un entier (centimes), jamais de decimales', () => {
    const v = computeFrameValueCents(333, 777, 0.37);
    assert.equal(Number.isInteger(v), true);
});

/* --- prefersReducedMotion (repli defensif) -------------------------------- */

test('prefersReducedMotion: pas de fenetre fournie -> false (repli, ne jette pas)', () => {
    assert.equal(prefersReducedMotion(undefined), false);
});

test('prefersReducedMotion: matchMedia absent de la fenetre -> false (vieux navigateur)', () => {
    assert.equal(prefersReducedMotion({}), false);
});

test('prefersReducedMotion: matchMedia qui jette -> false, jamais d exception qui remonte', () => {
    const win = { matchMedia() { throw new Error('non supporte'); } };
    assert.doesNotThrow(() => prefersReducedMotion(win));
    assert.equal(prefersReducedMotion(win), false);
});

test('prefersReducedMotion: repercute matches=true', () => {
    const win = { matchMedia: () => ({ matches: true }) };
    assert.equal(prefersReducedMotion(win), true);
});

test('prefersReducedMotion: repercute matches=false', () => {
    const win = { matchMedia: () => ({ matches: false }) };
    assert.equal(prefersReducedMotion(win), false);
});

test('prefersReducedMotion: interroge bien la media query dediee', () => {
    let requete = null;
    const win = { matchMedia: (q) => { requete = q; return { matches: false }; } };
    prefersReducedMotion(win);
    assert.equal(requete, '(prefers-reduced-motion: reduce)');
});

/* --- animateTotalValue (orchestrateur, raf/caf/now injectes) -------------- */

test('animateTotalValue: mouvement reduit -> valeur finale posee directement, aucune image jouee', () => {
    const el = { textContent: '' };
    let rafCalls = 0;
    animateTotalValue(el, 490, 1290, { reducedMotion: true, raf: () => { rafCalls += 1; return 1; } });
    assert.equal(el.textContent, formatPrice(1290));
    assert.equal(rafCalls, 0);
});

test('animateTotalValue: valeurs identiques -> aucune image jouee (rien a animer)', () => {
    const el = { textContent: '' };
    let rafCalls = 0;
    animateTotalValue(el, 890, 890, { raf: () => { rafCalls += 1; return 1; } });
    assert.equal(el.textContent, formatPrice(890));
    assert.equal(rafCalls, 0);
});

test('animateTotalValue: element absent -> ne jette pas', () => {
    assert.doesNotThrow(() => animateTotalValue(null, 0, 100));
    assert.doesNotThrow(() => animateTotalValue(undefined, 0, 100));
});

test('animateTotalValue: progresse puis atteint la valeur finale EXACTE (pas d arrondi qui derive)', () => {
    const el = { textContent: '' };
    let tick;
    const raf = (cb) => { tick = cb; return 1; };
    let t = 0;
    animateTotalValue(el, 0, 1000, { now: () => t, raf, caf: () => {}, duration: 100 });
    assert.equal(typeof tick, 'function', 'une premiere image doit avoir ete programmee');

    t = 50;
    tick(); // a mi-duree : ni la valeur de depart, ni celle d arrivee
    const milieu = el.textContent;
    assert.notEqual(milieu, formatPrice(0));
    assert.notEqual(milieu, formatPrice(1000));

    t = 100;
    tick(); // duree ecoulee : doit tomber PILE sur la valeur finale formatee
    assert.equal(el.textContent, formatPrice(1000));
});

test('animateTotalValue: un raf synchrone et une horloge figee ne bloquent jamais (plafond d images)', () => {
    // Reproduit exactement l'environnement de tests/js/order-panel.test.js, ou
    // `global.requestAnimationFrame = (cb) => cb()` rappelle IMMEDIATEMENT et
    // SANS avancer le temps. Sans plafond independant du temps ecoule, la boucle
    // recursive ne se terminerait qu apres avoir accumule 400ms d horloge reelle
    // via des appels synchrones — des dizaines de milliers d appels avant d y
    // parvenir, largement au-dela de la pile d appel disponible.
    const el = { textContent: '' };
    let calls = 0;
    const raf = (cb) => { calls += 1; cb(); return calls; };
    const caf = () => {};
    assert.doesNotThrow(() => {
        animateTotalValue(el, 490, 1290, { now: () => 0, raf, caf, duration: 400 });
    });
    assert.equal(el.textContent, formatPrice(1290), 'doit tout de meme atteindre la valeur finale exacte');
    assert.ok(calls > 1, 'plusieurs images doivent avoir ete jouees');
    assert.ok(calls <= 60, `le nombre d appels doit rester borne (recu ${calls})`);
});

test('animateTotalValue: une nouvelle animation annule net celle encore en vol', () => {
    const scheduled = [];
    let nextId = 1;
    const raf = (cb) => { const id = nextId++; scheduled.push({ id, cb }); return id; };
    const cancelled = [];
    const caf = (id) => cancelled.push(id);
    let t = 0;
    const now = () => t;
    const el = { textContent: '' };

    animateTotalValue(el, 0, 1000, { now, raf, caf, duration: 400 });
    assert.equal(scheduled.length, 1);
    const premiereImage = scheduled[0];

    t = 100; // 25% de la duree
    premiereImage.cb();
    assert.equal(scheduled.length, 2, 'la 1re image doit avoir programme sa suite');
    const imageEnAttente = scheduled[1];
    const valeurIntermediaire = el.textContent;
    assert.notEqual(valeurIntermediaire, formatPrice(1000));

    // Un second ajout au panier arrive avant la fin de la 1re animation.
    animateTotalValue(el, 1000, 2000, { now, raf, caf, duration: 400 });
    assert.deepEqual(cancelled, [imageEnAttente.id], 'l image en attente de la 1re animation doit etre annulee explicitement');

    const texteApresDemarrageDe2 = el.textContent;
    // Repli de securite : meme si le navigateur invoquait quand meme ce callback
    // perime malgre l annulation, il ne doit plus rien ecrire (jeton de generation).
    imageEnAttente.cb();
    assert.equal(el.textContent, texteApresDemarrageDe2, 'un callback perime ne doit plus rien ecrire');
});

test('animateTotalValue: formatValue injectable (decouple de formatPrice pour le test)', () => {
    const el = { textContent: '' };
    const formatValue = (c) => `${c}c`;
    animateTotalValue(el, 100, 100, { formatValue });
    assert.equal(el.textContent, '100c');
});
