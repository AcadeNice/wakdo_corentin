/*
 * nav.test.js — Garde du mode de consommation borne (helpers PURS de nav.js).
 *
 * nav.js n'enregistre son listener DOMContentLoaded que derriere `typeof document`,
 * donc l'import est sans effet de bord en node pur : on teste needsModeRedirect et
 * modeLabel sans jsdom. Couvre la cause du 422 INVALID_SERVICE_MODE : atteindre une
 * page de commande sans mode memorise (localStorage vide) doit renvoyer a l'accueil,
 * et le badge ne doit jamais afficher un faux "Sur place" quand aucun mode n'est choisi.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { needsModeRedirect, modeLabel } from '../../src/public/borne/assets/js/nav.js';

/* --- modeLabel ----------------------------------------------------------- */

test('modeLabel: libelle humain ; vide si mode absent ou inconnu (ne ment pas)', () => {
    assert.equal(modeLabel('sur-place'), 'Sur place');
    assert.equal(modeLabel('a-emporter'), 'A emporter');
    assert.equal(modeLabel(null), '');
    assert.equal(modeLabel(undefined), '');
    assert.equal(modeLabel('bidon'), '');
});

/* --- needsModeRedirect --------------------------------------------------- */

test('needsModeRedirect: page profonde SANS mode valide -> redirige', () => {
    assert.equal(needsModeRedirect('/payment.html', null), true);
    assert.equal(needsModeRedirect('/products.html', undefined), true);
    assert.equal(needsModeRedirect('/cart.html', 'bidon'), true);
    assert.equal(needsModeRedirect('/categories.html', ''), true);
});

test('needsModeRedirect: mode valide -> pas de redirection', () => {
    assert.equal(needsModeRedirect('/payment.html', 'sur-place'), false);
    assert.equal(needsModeRedirect('/products.html', 'a-emporter'), false);
});

test('needsModeRedirect: ecran d accueil jamais redirige (le mode s y choisit)', () => {
    assert.equal(needsModeRedirect('/', null), false);
    assert.equal(needsModeRedirect('/index.html', null), false);
    assert.equal(needsModeRedirect('/index.html', 'bidon'), false);
});

/* --- Cablage de la chaine de persistance (regression) -------------------- */

test('categories.html charge nav.js (sinon le mode recu en ?mode= n est jamais persiste)', () => {
    // 1re page post-accueil : elle recoit ?mode= depuis index.html et DOIT le persister
    // via syncModeFromURL. Sans nav.js ici, le mode n atteint pas localStorage et la
    // garde renverrait en boucle un utilisateur legitime vers l accueil (regression revue).
    const html = readFileSync(new URL('../../src/public/borne/categories.html', import.meta.url), 'utf8');
    assert.match(html, /assets\/js\/nav\.js/);
});
