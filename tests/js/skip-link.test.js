/*
 * Tests du lien d'evitement (RGAA Cr 1.e.11, renforce Cr 1.c.4) sur les 5 pages
 * du front borne.
 *
 * Lit le HTML REELLEMENT servi (fichiers sur disque), pas une reconstruction en
 * memoire : verifie que le lien est le premier element focalisable du <body>,
 * qu'il cible un <main id="main-content"> qui existe vraiment sur la page (pas
 * une ancre morte), et qu'il porte le texte impose par l'audit. Couvre aussi la
 * regle CSS associee (style.css) : hors flot par defaut, visible au focus
 * clavier via :focus-visible (meme convention que le reste du fichier).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { JSDOM } from 'jsdom';

const here = path.dirname(fileURLToPath(import.meta.url));
const borneDir = path.join(here, '../../src/public/borne');

const pages = ['index.html', 'categories.html', 'products.html', 'payment.html', 'confirmation.html'];

for (const page of pages) {
    test(`${page} : le lien d'evitement est le premier element du body et cible un main existant`, () => {
        const html = readFileSync(path.join(borneDir, page), 'utf8');
        const dom = new JSDOM(html);
        const { document } = dom.window;

        const first = document.body.firstElementChild;
        assert.ok(first, 'le body a au moins un element');
        assert.equal(first.tagName, 'A');
        assert.ok(first.classList.contains('skip-link'), 'le premier element porte la classe skip-link');
        assert.equal(first.getAttribute('href'), '#main-content');
        assert.equal(first.textContent.trim(), 'Aller au contenu');

        const target = document.getElementById('main-content');
        assert.ok(target, 'la cible #main-content existe sur la page (pas une ancre morte)');
        assert.equal(target.tagName, 'MAIN');
    });
}

test('style.css : .skip-link est hors flot par defaut et reapparait via :focus-visible', () => {
    const css = readFileSync(path.join(borneDir, 'assets/css/style.css'), 'utf8');

    assert.match(css, /\.skip-link\s*{[^}]*top:\s*-1000px/, 'hors ecran par defaut');
    assert.match(css, /\.skip-link:focus-visible\s*{[^}]*top:\s*0/, 'reapparait au focus clavier');
});
