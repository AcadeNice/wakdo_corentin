/*
 * category-strip.js — Bandeau categories horizontal (maquette : strip en haut de
 * l'ecran de commande, avec fleches ◀ ▶ et la categorie courante surlignee).
 *
 * Permet de changer de categorie sans repasser par categories.html. Les categories
 * viennent de /api/categories (via loadCategories de data.js : seules les categories
 * actives/commandables). La logique est separee en : buildStripModel (PUR, testable)
 * + renderStripInto (DOM sans fetch, testable jsdom) + renderCategoryStrip (lit l'URL,
 * fetch, monte) pour l'auto-montage.
 */

import { loadCategories } from './data.js';
import { escHtml } from './state.js';

/**
 * Vue-modele PUR : marque la categorie active. Aucune dependance DOM/fetch.
 * L'actif est resolu par ID (donnees live de l'API), pas via une table id->slug
 * codee en dur, pour rester aligne sur le catalogue reel.
 * @param {Array<{id:number,title:string,slug:string,image:string}>} categories
 * @param {number} activeId
 * @returns {Array}
 */
export function buildStripModel(categories, activeId) {
    return categories.map(c => ({
        id: Number(c.id),
        slug: c.slug,
        title: c.title,
        image: c.image,
        active: Number(c.id) === activeId,
    }));
}

/**
 * Capitalise la 1re lettre (titre de categorie affiche).
 * @param {string} s
 * @returns {string}
 */
function cap(s) {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

/**
 * Rend le bandeau dans le conteneur a partir d'un modele deja construit. Pas de
 * fetch : c'est la cible des tests jsdom. Chaque carte navigue vers la categorie
 * (en preservant le mode). Les fleches font defiler le scroller horizontalement.
 * @param {HTMLElement} container
 * @param {Array} model — sortie de buildStripModel
 * @param {string|null} modeParam — mode de consommation a propager dans l'URL
 */
export function renderStripInto(container, model, modeParam) {
    if (!container) return;
    const modeQS = modeParam ? `&mode=${encodeURIComponent(modeParam)}` : '';

    const cards = model.map(c => `
        <a
            class="category-strip__item${c.active ? ' is-active' : ''}"
            href="products.html?category=${c.id}${modeQS}"
            aria-label="${escHtml(cap(c.title))}"
            ${c.active ? 'aria-current="true"' : ''}
        >
            <img class="category-strip__img" src="${escHtml(c.image)}" alt="" aria-hidden="true"
                 data-fallback="hide">
            <span class="category-strip__label">${escHtml(cap(c.title))}</span>
        </a>
    `).join('');

    container.innerHTML = `
        <button class="category-strip__arrow category-strip__arrow--prev" type="button" aria-label="Categories precedentes">&#9664;</button>
        <div class="category-strip__scroller">${cards}</div>
        <button class="category-strip__arrow category-strip__arrow--next" type="button" aria-label="Categories suivantes">&#9654;</button>
    `;

    const scroller = container.querySelector('.category-strip__scroller');
    const step = 320;
    const prev = container.querySelector('.category-strip__arrow--prev');
    const next = container.querySelector('.category-strip__arrow--next');
    // scrollBy/scrollIntoView absents de jsdom -> gardes pour ne pas jeter en test.
    if (prev) prev.addEventListener('click', () => scroller.scrollBy?.({ left: -step, behavior: 'smooth' }));
    if (next) next.addEventListener('click', () => scroller.scrollBy?.({ left: step, behavior: 'smooth' }));
    const active = scroller.querySelector('.is-active');
    active?.scrollIntoView?.({ inline: 'center', block: 'nearest' });
}

/**
 * Monte le bandeau : lit ?category=&mode= dans l'URL, charge les categories,
 * construit le modele et rend. Tolerant a un echec de chargement (ne casse pas la page).
 * @param {HTMLElement} container
 */
export async function renderCategoryStrip(container) {
    if (!container) return;
    const params = new URLSearchParams(window.location.search);
    const activeId = parseInt(params.get('category'), 10) || 1;
    const modeParam = params.get('mode');
    try {
        const categories = await loadCategories();
        renderStripInto(container, buildStripModel(categories, activeId), modeParam);
    } catch (e) {
        console.error('renderCategoryStrip error:', e);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-category-strip]').forEach(renderCategoryStrip);
});
