/*
 * Garde sur l'organisation de la feuille de style de la borne (Cr 1.d.2, node:test).
 *
 * La feuille est decoupee en sections numerotees, annoncees par un sommaire en tete
 * de fichier. Ce test empeche la derive constatee le 2026-09-23 : sections ajoutees
 * apres le lot initial sans numero, ou avec un numero deja pris (deux « 12. »,
 * deux « 13. », deux « 14. », et aucun « 9. »).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../src/public/borne/assets/css/style.css', import.meta.url), 'utf8');

/** Bandeaux de section : « /* ===...\n   N. TITRE\n ... ===... *\/ ». */
function sections() {
    const banner = /\/\* =+\n {3}(\d+)\. ([^\n]+)\n(?:[^\n]*\n)*? {3}=+ \*\//g;
    return [...css.matchAll(banner)].map((match) => ({ number: Number(match[1]), title: match[2].trim() }));
}

/** Sommaire du commentaire d'en-tete : lignes « *   N. TITRE ». */
function tableOfContents() {
    const header = css.slice(0, css.indexOf('*/'));
    return [...header.matchAll(/^ \* {2,}(\d+)\. (.+)$/gm)]
        .map((match) => ({ number: Number(match[1]), title: match[2].trim() }));
}

test('les sections sont numerotees de 1 a N, sans doublon ni trou', () => {
    const numbers = sections().map((section) => section.number);
    assert.ok(numbers.length >= 20, `sections trouvees : ${numbers.length}`);
    assert.deepEqual(numbers, numbers.map((_, index) => index + 1));
});

test('le sommaire d en-tete annonce exactement les sections du fichier, dans l ordre', () => {
    assert.deepEqual(tableOfContents(), sections());
});

test('aucun bandeau hors numerotation ne subsiste', () => {
    // Ancien format d'une ligne, utilise par les sections ajoutees apres le lot initial.
    assert.doesNotMatch(css, /\/\* === /);
    assert.doesNotMatch(css, /^\/\* =+\n \* /m, 'bandeau a etoiles de l ancien format');
});

test('les renvois « section N » des commentaires pointent vers une section existante', () => {
    const count = sections().length;
    for (const match of css.matchAll(/section (\d+)(?![.\d])/gi)) {
        const target = Number(match[1]);
        assert.ok(target >= 1 && target <= count, `renvoi vers la section ${target}, absente`);
    }
});
