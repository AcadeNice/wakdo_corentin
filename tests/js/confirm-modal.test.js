/*
 * Tests de confirm-modal.js (node:test + jsdom). Modale de confirmation d'un geste
 * destructeur : onConfirm n'est appele QUE sur confirmation explicite ; Annuler /
 * Echap / clic-fond ferment sans agir. Import dynamique apres pose des globals jsdom.
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
    ({ confirmAction } = await import('../../src/public/borne/assets/js/confirm-modal.js'));
});

beforeEach(() => { document.body.innerHTML = ''; });

test('confirmAction: affiche une modale role=dialog avec le message', () => {
    confirmAction({ message: 'Abandonner ?', onConfirm: () => {} });
    const modal = document.querySelector('.confirm-overlay .confirm-modal[role="dialog"]');
    assert.ok(modal);
    assert.equal(modal.getAttribute('aria-modal'), 'true');
    assert.match(modal.querySelector('.confirm-modal__message').textContent, /Abandonner/);
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
    document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
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
