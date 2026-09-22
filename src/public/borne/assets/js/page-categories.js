/*
 * page-categories.js — Ecran categories de la borne ("Que souhaitez-vous commander ?").
 *
 * Les cartes viennent de /api/categories (via loadCategories de data.js : seules les
 * categories actives, deja triees par display_order cote serveur). Avant ce module la
 * page portait une liste de 9 cartes codee en dur : une categorie desactivee, renommee,
 * reordonnee ou ajoutee dans le back-office restait invisible ou fausse sur le 1er ecran
 * post-accueil, alors que le bandeau de l'ecran suivant, lui, etait deja juste.
 *
 * Meme decoupage que category-strip.js : buildGridModel (PUR, testable) + renderGridInto
 * (DOM sans reseau, testable jsdom) + renderCategoryGrid (charge et monte). Le chargeur
 * est un parametre pour que la branche d'echec soit testable sans toucher au fetch global.
 */

import { loadCategories } from './data.js';
import { escHtml } from './state.js';
import './img-fallback.js';

/**
 * Capitalise la 1re lettre (libelle de categorie affiche : les noms sont stockes en
 * minuscules en base).
 * @param {string} s
 * @returns {string}
 */
function cap(s) {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

/**
 * Vue-modele PUR. Aucune dependance DOM/reseau. L'ordre recu est conserve tel quel :
 * il vient du serveur (CategoryRepository trie par display_order), le client ne re-trie
 * pas. `name` garde le nom brut (lu par les lecteurs d'ecran via aria-label, comme dans
 * l'echafaudage remplace), `label` porte la forme capitalisee affichee.
 * @param {Array<{id:number,title:string,slug:string,image:string|null}>} categories
 * @returns {Array<{id:number,name:string,label:string,image:string}>}
 */
export function buildGridModel(categories) {
    return categories.map(c => {
        const name = String(c.title ?? c.name ?? '');
        return {
            id: Number(c.id),
            name,
            label: cap(name),
            image: c.image ?? '',
        };
    });
}

/**
 * Rend la grille a partir d'un modele deja construit. Pas de reseau : c'est la cible
 * des tests jsdom. Reutilise les classes existantes (style.css .category-grid /
 * .category-card) -> aucun changement de feuille de style.
 *
 * Le href reste en correspondance EXACTE `products.html?category=<id>` : le mode de
 * consommation n'est PAS propage en parametre ici (contrairement au bandeau), parce que
 * nav.js le persiste deja dans le stockage local sur cette page meme et que le parcours
 * E2E cible ce selecteur au caractere pres.
 * @param {HTMLElement|null} container
 * @param {Array} model — sortie de buildGridModel
 * @param {HTMLElement|null} [emptyEl] — paragraphe "aucune categorie"
 */
export function renderGridInto(container, model, emptyEl = null) {
    if (!container) return;

    container.innerHTML = model.map(c => {
        // image_path est NULLABLE cote API : sans image on n'emet aucune <img> plutot
        // qu'une source vide qui declencherait le repli a chaque affichage.
        const img = c.image
            ? `<img class="category-card__image" src="${escHtml(c.image)}" alt="${escHtml(c.label)}"
                    data-fallback="logo" data-fallback-alt="Image non disponible">`
            : '';
        return `
        <a class="category-card" href="products.html?category=${c.id}" aria-label="Voir les ${escHtml(c.name)}">
            ${img}
            <span class="category-card__label">${escHtml(c.label)}</span>
        </a>`;
    }).join('');

    if (emptyEl) emptyEl.hidden = model.length > 0;
}

/**
 * Charge les categories et monte la grille. Un echec de chargement affiche un message
 * et ne jette pas : l'en-tete et le lien de retour restent utilisables.
 *
 * Un catalogue VIDE et un catalogue INJOIGNABLE sont deux etats distincts a l'ecran :
 * le message de vide ne doit pas s'afficher sur une panne, sinon l'equipier croit a une
 * erreur de saisie alors que l'API ne repond pas.
 * @param {{grid:HTMLElement|null, errorEl:HTMLElement|null, emptyEl:HTMLElement|null}} els
 * @param {() => Promise<Array>} [loader]
 */
export async function renderCategoryGrid(els, loader = loadCategories) {
    const { grid, errorEl, emptyEl } = els;
    if (!grid) return;
    try {
        renderGridInto(grid, buildGridModel(await loader()), emptyEl);
    } catch (e) {
        if (errorEl) {
            errorEl.hidden = false;
            errorEl.textContent = 'Impossible de charger les categories. Veuillez reessayer.';
        }
        console.error('renderCategoryGrid error:', e);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    renderCategoryGrid({
        grid: document.getElementById('category-grid'),
        errorEl: document.getElementById('categories-error'),
        emptyEl: document.getElementById('categories-empty'),
    });
});
