/*
 * page-product-menu.js — Composeur de menu PILOTE PAR LES SLOTS (P5 L2).
 *
 * Importe par page-products.js quand le produit clique est un menu (type === 'menu').
 *
 * Avant L2 : le composeur composait LIBREMENT a partir des categories (burgers,
 * frites, boissons, sauces) sans tenir compte du menu reel. Desormais il consomme
 * GET /api/menus/{id} : le burger est IMPOSE (burger_product_id), et chaque slot
 * (slot_type drink/side/sauce, option_product_ids) devient une etape. Le prix vient
 * du menu (Normal vs Maxi), pas d'un supplement arbitraire.
 *
 * Etapes : Format (Normal/Maxi, burger impose affiche) -> 1 pas par slot (dans
 * l'ordre display_order ; requis = choix obligatoire, optionnel = "sans") -> recap.
 *
 * La forme de `composition` produite reste compatible avec order-panel.js (burger /
 * accompagnement / boisson / sauce + taille), le slot_type mappant vers le bon champ ;
 * Maxi pose taille 'G' + supplement = prix_maxi - prix_normal.
 *
 * A11y : role=dialog, aria-modal, focus-trap, ESC annule, focus au 1er interactif.
 */

import { loadMenu, loadProductsById } from './data.js';
import { addToCart, computeMenuLineCents, formatPrice, escHtml } from './state.js';
import { refreshCartBadge } from './nav.js';

/* slot_type de l'API -> champ de composition attendu par le rendu panier existant. */
const SLOT_FIELD = { side: 'accompagnement', drink: 'boisson', sauce: 'sauce' };

/**
 * Libelle a afficher pour une option selon le format. En Maxi ('M'), un
 * accompagnement a une variante agrandie (maxiNom, ex. "Grande Frite") : c'est ce
 * nom que le client doit voir au moment de CHOISIR, pas le "Moyenne Frite" de base.
 * Sans maxiNom (ex. les boissons, que le menu Maxi n'agrandit pas) ou en Normal,
 * on garde le nom de base. Pur.
 * @param {Object} option — produit borne {nom, maxiNom?}
 * @param {'N'|'M'} size
 * @returns {string}
 */
export function optionLabel(option, size) {
    return (size === 'M' && option.maxiNom) ? option.maxiNom : option.nom;
}

/* ------------------------------------------------------------------ */
/* Fonctions PURES (cible des tests, sans DOM ni fetch)                 */
/* ------------------------------------------------------------------ */

/**
 * Construit le modele d'etapes a partir du detail menu (slots) et de l'index
 * produit par id. Resout les option_product_ids en produits affichables, trie les
 * slots par display_order. Pur.
 * @param {Object} detail — sortie de loadMenu()
 * @param {Object<number,Object>} byId — sortie de loadProductsById()
 * @returns {{burger: Object|null, slots: Array, priceNormalCents: number, priceMaxiCents: number}}
 */
export function buildComposerSteps(detail, byId) {
    const burger = byId[detail.burger_product_id] ?? null;
    const slots = [...(detail.slots ?? [])]
        // SLOT_FIELD fait foi : un slot_type non mappe (l'enum DB autorise aussi
        // dessert/extra) ne devient PAS une etape -> pas de choix perdu silencieusement.
        .filter(slot => {
            if (SLOT_FIELD[slot.slot_type]) return true;
            console.warn(`Menu composer: slot_type non gere, slot ignore: ${slot.slot_type}`);
            return false;
        })
        .sort((a, b) => (a.display_order ?? 0) - (b.display_order ?? 0))
        .map(slot => ({
            id: slot.id,
            name: slot.name,
            slotType: slot.slot_type,
            isRequired: !!slot.is_required,
            options: (slot.option_product_ids ?? []).map(pid => byId[pid]).filter(Boolean),
        }));
    return {
        burger,
        slots,
        priceNormalCents: detail.price_normal_cents,
        priceMaxiCents: detail.price_maxi_cents,
    };
}

/**
 * Construit l'item panier du menu compose. `composition` reste compatible avec le
 * rendu existant (burger/accompagnement/boisson/sauce). Maxi -> taille 'G' sur les
 * items dimensionnables + supplement = prix_maxi - prix_normal (prix_cents = normal).
 * @param {Object} menu — produit borne {id, nom, image, prix?}
 * @param {Object} model — sortie de buildComposerSteps
 * @param {{size: 'N'|'M', selections: Object<number, number>}} choice
 * @returns {Object} item panier
 */
export function buildMenuCartItem(menu, model, { size, selections }) {
    const isMaxi = size === 'M';
    const taille = isMaxi ? 'G' : 'N';
    const supplement = isMaxi
        ? Math.max(0, (model.priceMaxiCents ?? 0) - (model.priceNormalCents ?? 0))
        : 0;

    const composition = {
        burger: { id: model.burger?.id, libelle: model.burger?.nom ?? menu.nom, options: [] },
    };

    for (const slot of model.slots) {
        const chosen = slot.options.find(o => o.id === selections[slot.id]);
        if (!chosen) continue; // slot optionnel laisse "sans"
        const field = SLOT_FIELD[slot.slotType];
        if (!field) continue;
        // libelle PORTE le nom affiche : en Maxi, l'accompagnement prend sa variante
        // ("Grande Frite") ; la boisson n'a pas de maxiNom (le menu Maxi ne l'agrandit
        // pas) donc garde son nom de base. Plus de suffixe " grande" cote rendu.
        const libelle = (isMaxi && chosen.maxiNom) ? chosen.maxiNom : chosen.nom;
        composition[field] = field === 'sauce'
            ? { id: chosen.id, libelle: chosen.nom }
            : { id: chosen.id, libelle, taille };
    }

    return {
        id: menu.id,
        type: 'menu',
        categorie: 'menus',
        libelle: menu.nom,
        prix_cents: model.priceNormalCents,
        quantite: 1,
        image: menu.image,
        supplement_cents: supplement,
        // format PORTE le choix Normal/Maxi de l'utilisateur, transporte tel quel
        // jusqu'au contrat API. Le serveur l'utilise pour le prix Maxi ET la
        // substitution des variantes (accompagnement Grande, boisson 50 cl). A NE
        // PAS re-deviner depuis supplement_cents (faux negatif si maxi == normal).
        format: isMaxi ? 'maxi' : 'normal',
        composition,
    };
}

/**
 * Indique si toutes les etapes obligatoires ont une selection. Pur.
 * @param {Object} model
 * @param {Object<number,number>} selections
 * @returns {boolean}
 */
export function selectionsComplete(model, selections) {
    return model.slots
        .filter(s => s.isRequired)
        .every(s => s.options.some(o => o.id === selections[s.id]));
}

/**
 * Le menu est-il composable ? Faux si le burger impose est introuvable, ou si un
 * slot REQUIS n'a aucune option resolue (catalogue desync). Pur. Garde-fou : eviter
 * d'ouvrir une modale ou une etape requise serait impassable.
 * @param {Object} model
 * @returns {boolean}
 */
export function composerIsViable(model) {
    if (!model.burger) return false;
    return model.slots.filter(s => s.isRequired).every(s => s.options.length > 0);
}

/* ------------------------------------------------------------------ */
/* Entree publique — appelee par page-products.js                      */
/* ------------------------------------------------------------------ */

/**
 * Initialise et ouvre la modale du composeur pour un menu.
 * @param {Object} menu — produit borne avec type === 'menu'
 * @param {string} returnCategory — slug de categorie de retour apres ajout/annulation
 */
export async function openMenuComposer(menu, returnCategory) {
    let detail, byId;
    try {
        [detail, byId] = await Promise.all([loadMenu(menu.id), loadProductsById()]);
    } catch (err) {
        console.error('Menu composer: chargement /api/menus echoue', err);
        return;
    }
    if (!detail) {
        console.error('Menu composer: detail menu introuvable', menu.id);
        return;
    }

    const model = buildComposerSteps(detail, byId);
    if (!composerIsViable(model)) {
        console.error('Menu composer: menu non composable (burger absent ou slot requis sans option)', menu.id);
        return;
    }

    const state = {
        menu,
        returnCategory,
        model,
        size: 'N',                  // 'N' (Normal) | 'M' (Maxi)
        selections: {},             // slotId -> productId ; pre-selection du 1er requis
        currentStep: 0,             // 0 = format ; 1..N = slots ; N+1 = recap
    };
    for (const slot of model.slots) {
        if (slot.isRequired && slot.options[0]) state.selections[slot.id] = slot.options[0].id;
    }

    const modal = buildModalShell(menu);
    modal._prevOverflow = document.body.style.overflow;
    document.body.appendChild(modal);
    modal.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    // Focus-trap : neutralise le fond pour les lecteurs d'ecran tant que la modale
    // est ouverte (freres de l'overlay : header, .order-layout).
    modal._bgSiblings = Array.from(document.body.children).filter(el => el !== modal);
    modal._bgSiblings.forEach(el => el.setAttribute('aria-hidden', 'true'));

    renderStep(modal, state);
    trapFocus(modal);

    const escHandler = (e) => {
        if (e.key === 'Escape') cancelComposer(modal, returnCategory, escHandler);
    };
    document.addEventListener('keydown', escHandler);
    modal._escHandler = escHandler;
}

/* ------------------------------------------------------------------ */
/* Coque modale                                                         */
/* ------------------------------------------------------------------ */

function totalSteps(state) {
    return state.model.slots.length + 2; // format + slots + recap
}

function buildModalShell(menu) {
    const overlay = document.createElement('div');
    overlay.className = 'composer-overlay';
    overlay.hidden = true;
    overlay.innerHTML = `
        <div class="composer-container" role="dialog" aria-modal="true" aria-labelledby="composer-title">
            <div class="composer-header">
                <h2 class="composer-title" id="composer-title">${escHtml(menu.nom)}</h2>
                <div class="composer-progress" aria-label="Progression">
                    <span class="composer-progress__text" id="composer-step-indicator" aria-live="polite"></span>
                    <div class="composer-progress__bar">
                        <div class="composer-progress__fill" id="composer-progress-fill"></div>
                    </div>
                </div>
            </div>
            <div class="composer-body" id="composer-body"></div>
            <div class="composer-footer" id="composer-footer"></div>
        </div>
    `;
    return overlay;
}

/* ------------------------------------------------------------------ */
/* Rendu d'etape                                                        */
/* ------------------------------------------------------------------ */

function renderStep(modal, state) {
    const body = modal.querySelector('#composer-body');
    const footer = modal.querySelector('#composer-footer');
    const stepEl = modal.querySelector('#composer-step-indicator');
    const fillEl = modal.querySelector('#composer-progress-fill');

    const total = totalSteps(state);
    stepEl.textContent = `Etape ${state.currentStep + 1} / ${total}`;
    fillEl.style.width = `${((state.currentStep + 1) / total) * 100}%`;

    if (state.currentStep === 0) {
        renderFormatStep(body, footer, modal, state);
    } else if (state.currentStep <= state.model.slots.length) {
        renderSlotStep(body, footer, modal, state, state.model.slots[state.currentStep - 1]);
    } else {
        renderRecapStep(body, footer, modal, state);
    }

    requestAnimationFrame(() => {
        const first = modal.querySelector('button:not([disabled]), [tabindex="0"]');
        if (first) first.focus();
    });
}

/* Etape 0 — Format Normal / Maxi (burger impose affiche) */
function renderFormatStep(body, footer, modal, state) {
    const { model } = state;
    const burgerName = model.burger ? escHtml(model.burger.nom) : escHtml(state.menu.nom);
    body.innerHTML = `
        <p class="composer-step__subtitle">Votre menu : ${burgerName}</p>
        <div class="composer-taille" role="group" aria-label="Format du menu">
            <button class="composer-card ${state.size === 'N' ? 'composer-card--selected' : ''}"
                type="button" data-size="N" aria-pressed="${state.size === 'N'}">
                <span class="composer-card__name">Normal</span>
                <span class="composer-card__price">${formatPrice(model.priceNormalCents)}</span>
            </button>
            <button class="composer-card ${state.size === 'M' ? 'composer-card--selected' : ''}"
                type="button" data-size="M" aria-pressed="${state.size === 'M'}">
                <span class="composer-card__name">Maxi</span>
                <span class="composer-card__price">${formatPrice(model.priceMaxiCents)}</span>
            </button>
        </div>
    `;
    body.querySelectorAll('[data-size]').forEach(btn => {
        btn.addEventListener('click', () => {
            state.size = btn.dataset.size;
            body.querySelectorAll('[data-size]').forEach(b => {
                const active = b.dataset.size === state.size;
                b.classList.toggle('composer-card--selected', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });
    renderFooter(footer, modal, state, { canAdvance: () => true });
}

/* Etapes 1..N — un slot (drink/side/sauce) */
function renderSlotStep(body, footer, modal, state, slot) {
    const optional = !slot.isRequired;
    body.innerHTML = `
        <p class="composer-step__subtitle">${escHtml(slot.name)}${optional ? ' (optionnel)' : ''}</p>
        <ul class="composer-grid" role="list" id="slot-grid">
            ${optional ? `
                <li>
                    <button class="composer-card ${state.selections[slot.id] == null ? 'composer-card--selected' : ''}"
                        type="button" data-pid="" aria-pressed="${state.selections[slot.id] == null}">
                        <span class="composer-card__name">Sans</span>
                    </button>
                </li>` : ''}
            ${slot.options.map(o => {
                // En Maxi, l'accompagnement s'affiche sous sa variante agrandie
                // ("Grande Frite") : le client choisit en connaissance de cause.
                const label = optionLabel(o, state.size);
                return `
                <li>
                    <button class="composer-card ${state.selections[slot.id] === o.id ? 'composer-card--selected' : ''}"
                        type="button" data-pid="${o.id}"
                        aria-pressed="${state.selections[slot.id] === o.id}"
                        aria-label="${escHtml(label)}">
                        <img class="composer-card__image" src="${escHtml(o.image)}" alt="${escHtml(label)}"
                             data-fallback="logo">
                        <span class="composer-card__name">${escHtml(label)}</span>
                    </button>
                </li>
            `;
            }).join('')}
        </ul>
    `;
    body.querySelectorAll('#slot-grid .composer-card').forEach(btn => {
        btn.addEventListener('click', () => {
            const raw = btn.dataset.pid;
            if (raw === '') delete state.selections[slot.id];
            else state.selections[slot.id] = parseInt(raw, 10);
            body.querySelectorAll('#slot-grid .composer-card').forEach(b => {
                const active = (b.dataset.pid === '' && state.selections[slot.id] == null)
                    || parseInt(b.dataset.pid, 10) === state.selections[slot.id];
                b.classList.toggle('composer-card--selected', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });
    renderFooter(footer, modal, state, {
        canAdvance: () => optional || state.selections[slot.id] != null,
    });
}

/* Etape finale — recap + ajout */
function renderRecapStep(body, footer, modal, state) {
    const item = buildMenuCartItem(state.menu, state.model, {
        size: state.size, selections: state.selections,
    });
    const total = computeMenuLineCents(item);
    const c = item.composition;
    const lines = [];
    lines.push(`${escHtml(c.burger.libelle)}`);
    if (c.accompagnement) lines.push(`${escHtml(c.accompagnement.libelle)}${c.accompagnement.taille === 'G' ? ' (Maxi)' : ''}`);
    if (c.boisson) lines.push(`${escHtml(c.boisson.libelle)}${c.boisson.taille === 'G' ? ' (Maxi)' : ''}`);
    if (c.sauce) lines.push(escHtml(c.sauce.libelle));

    body.innerHTML = `
        <p class="composer-step__subtitle">Recapitulatif (${state.size === 'M' ? 'Maxi' : 'Normal'})</p>
        <ul class="composer-recap" aria-label="Composition du menu">
            ${lines.map(l => `<li class="composer-recap__line"><span class="composer-recap__icon" aria-hidden="true">&#9632;</span><span class="composer-recap__label">${l}</span></li>`).join('')}
        </ul>
        <div class="composer-recap__totals">
            <span class="composer-recap__total-line">Total : <strong>${formatPrice(total)}</strong></span>
        </div>
    `;
    footer.innerHTML = `
        <div class="composer-footer__row">
            <button class="btn btn--secondary composer-footer__cancel" type="button" id="composer-cancel">Annuler</button>
            <button class="btn btn--secondary composer-footer__prev" type="button" id="composer-prev">Precedent</button>
            <button class="btn btn--primary composer-footer__add" type="button" id="composer-add">Ajouter a ma commande</button>
        </div>
    `;
    footer.querySelector('#composer-cancel').addEventListener('click', () => cancelComposer(modal, state.returnCategory, modal._escHandler));
    footer.querySelector('#composer-prev').addEventListener('click', () => { state.currentStep--; renderStep(modal, state); });
    footer.querySelector('#composer-add').addEventListener('click', () => {
        addToCart(item);
        refreshCartBadge();
        closeComposer(modal);
        window.location.href = `products.html?category=${state.returnCategory}`;
    });
}

/* ------------------------------------------------------------------ */
/* Footer de navigation (etapes non-recap)                              */
/* ------------------------------------------------------------------ */

function renderFooter(footer, modal, state, opts) {
    const isFirst = state.currentStep === 0;
    footer.innerHTML = `
        <div class="composer-footer__row">
            <button class="btn btn--secondary composer-footer__cancel" type="button" id="composer-cancel">Annuler</button>
            ${!isFirst ? `<button class="btn btn--secondary composer-footer__prev" type="button" id="composer-prev">Precedent</button>` : ''}
            <button class="btn btn--primary composer-footer__next" type="button" id="composer-next">Suivant</button>
        </div>
    `;
    footer.querySelector('#composer-cancel').addEventListener('click', () => cancelComposer(modal, state.returnCategory, modal._escHandler));
    if (!isFirst) {
        footer.querySelector('#composer-prev').addEventListener('click', () => { state.currentStep--; renderStep(modal, state); });
    }
    footer.querySelector('#composer-next').addEventListener('click', () => {
        if (!opts.canAdvance()) return;
        state.currentStep++;
        renderStep(modal, state);
    });
}

/* ------------------------------------------------------------------ */
/* Focus trap + fermeture                                               */
/* ------------------------------------------------------------------ */

function trapFocus(modal) {
    modal.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), [tabindex="0"]'))
            .filter(el => !el.closest('[hidden]'));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
}

function closeComposer(modal) {
    if (modal._escHandler) document.removeEventListener('keydown', modal._escHandler);
    if (modal._bgSiblings) modal._bgSiblings.forEach(el => el.removeAttribute('aria-hidden'));
    modal.remove();
    document.body.style.overflow = modal._prevOverflow ?? '';
}

function cancelComposer(modal, returnCategory, escHandler) {
    if (escHandler) document.removeEventListener('keydown', escHandler);
    closeComposer(modal);
    window.location.href = `products.html?category=${returnCategory}`;
}
