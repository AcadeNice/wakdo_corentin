/*
 * img-fallback.test.js — Repli d'image CSP-safe (assets/js/img-fallback.js).
 *
 * Verifie que le repli, deplace des attributs onerror inline vers un listener
 * delegue (pour permettre une CSP script-src 'self' sans 'unsafe-inline'),
 * conserve le comportement : bascule sur le logo, garde-fou anti-boucle, masquage
 * par classe, et branchement delegue idempotent en phase de capture.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { handleImageError, initImageFallback } from '../../src/public/borne/assets/js/img-fallback.js';

const LOGO = 'assets/images/ui/logo.png';

function makeDoc() {
    return new JSDOM('<!doctype html><body></body>').window.document;
}

function makeImg(doc, attrs = {}) {
    const img = doc.createElement('img');
    for (const [k, v] of Object.entries(attrs)) img.setAttribute(k, v);
    doc.body.appendChild(img);
    return img;
}

/* --- handleImageError (pur) --------------------------------------------- */

test('mode logo : bascule la source sur le logo + alt de repli', () => {
    const doc = makeDoc();
    const img = makeImg(doc, { src: 'casse.jpg', 'data-fallback': 'logo', 'data-fallback-alt': 'Image non disponible' });
    handleImageError(img);
    assert.equal(img.getAttribute('src'), LOGO);
    assert.equal(img.alt, 'Image non disponible');
});

test('mode logo : garde-fou anti-boucle (source deja = logo -> inchange)', () => {
    const doc = makeDoc();
    const img = makeImg(doc, { src: LOGO, 'data-fallback': 'logo' });
    handleImageError(img);
    assert.equal(img.getAttribute('src'), LOGO);
});

test('mode logo sans data-fallback-alt : alt non force', () => {
    const doc = makeDoc();
    const img = makeImg(doc, { src: 'casse.jpg', alt: 'Frites', 'data-fallback': 'logo' });
    handleImageError(img);
    assert.equal(img.getAttribute('src'), LOGO);
    assert.equal(img.alt, 'Frites');
});

test('mode hide : ajoute la classe de masquage (pas de style inline)', () => {
    const doc = makeDoc();
    const img = makeImg(doc, { src: 'casse.jpg', 'data-fallback': 'hide' });
    handleImageError(img);
    assert.ok(img.classList.contains('img-fallback-hidden'));
    assert.equal(img.getAttribute('style'), null);
});

test('sans data-fallback : aucune modification', () => {
    const doc = makeDoc();
    const img = makeImg(doc, { src: 'casse.jpg' });
    handleImageError(img);
    assert.equal(img.getAttribute('src'), 'casse.jpg');
});

test('cible non-IMG : ignoree sans erreur', () => {
    const doc = makeDoc();
    const div = doc.createElement('div');
    assert.doesNotThrow(() => handleImageError(div));
    assert.doesNotThrow(() => handleImageError(null));
});

/* --- initImageFallback (delegation capture, idempotence) ----------------- */

test('listener delegue : un error en capture declenche le repli', () => {
    const doc = makeDoc();
    initImageFallback(doc);
    const img = makeImg(doc, { src: 'casse.jpg', 'data-fallback': 'hide' });
    img.dispatchEvent(new doc.defaultView.Event('error'));
    assert.ok(img.classList.contains('img-fallback-hidden'));
});

test('initImageFallback : idempotent (pose le drapeau, second appel sans effet)', () => {
    const doc = makeDoc();
    initImageFallback(doc);
    assert.equal(doc.__wakdoImgFallback, true);
    assert.doesNotThrow(() => initImageFallback(doc));
});

test('initImageFallback : doc null tolere', () => {
    assert.doesNotThrow(() => initImageFallback(null));
});
