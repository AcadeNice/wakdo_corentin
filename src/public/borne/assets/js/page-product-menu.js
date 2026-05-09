/*
 * page-product-menu.js — Multi-step menu composer for the Wakdo kiosk.
 *
 * Imported by page-product.js only when the loaded product has type === 'menu'.
 * Keeping the composer in its own module avoids bloating page-product.js and
 * makes future unit-testing of the composition logic straightforward.
 *
 * Steps:
 *   1 — Burger selection + personalisation options (sans oignon / avec fromage)
 *   2 — Accompagnement (frites or salades) + taille toggle
 *   3 — Boisson + taille toggle
 *   4 — Sauce
 *   5 — Recap + "Ajouter au panier"
 *
 * Price rule: grande taille = +50 centimes per sized item (accompagnement + boisson).
 *
 * A11y: role=dialog, aria-modal=true, focus-trap (Tab cycles inside the modal),
 * ESC closes/cancels, focus is moved to the first interactive element on each step.
 */

import { getProductsByCategory } from './data.js';
import { addToCart, computeMenuLineCents, formatPrice } from './state.js';
import { refreshCartBadge } from './nav.js';

const SUPPLEMENT_GRANDE_CENTS = 50;
const TOTAL_STEPS = 5;

/* ------------------------------------------------------------------ */
/* Public entry-point — called from page-product.js                    */
/* ------------------------------------------------------------------ */

/**
 * Initialises and opens the menu composer modal.
 * Fetches required category products, builds the initial state, then renders.
 *
 * @param {Object} menu  — product object with type === 'menu'
 * @param {string} returnCategory — category slug to redirect to after add/cancel
 */
export async function openMenuComposer(menu, returnCategory) {
    let burgers, frites, salades, boissons, sauces;
    try {
        [burgers, frites, salades, boissons, sauces] = await Promise.all([
            getProductsByCategory('burgers'),
            getProductsByCategory('frites'),
            getProductsByCategory('salades'),
            getProductsByCategory('boissons'),
            getProductsByCategory('sauces')
        ]);
    } catch (err) {
        console.error('Menu composer: failed to load category products', err);
        return;
    }

    const accompagnements = [...frites, ...salades];

    /* Heuristic pre-selection: if the menu name contains a burger name, pre-select it.
     * "Menu CBO" -> first burger whose nom equals "CBO".
     * Fallback: first burger in the list. */
    const menuNameUpper = menu.nom.toUpperCase();
    const preselectedBurger =
        burgers.find(b => menuNameUpper.includes(b.nom.toUpperCase())) ?? burgers[0] ?? null;

    /* Composer internal state — single mutable object, re-read on each render. */
    const state = {
        currentStep: 1,
        menu,
        returnCategory,
        burgers,
        accompagnements,
        boissons,
        sauces,
        /* Selections */
        burger:         preselectedBurger,
        burgerOptions:  [],            // subset of ['sans-oignon', 'avec-fromage']
        accompagnement: accompagnements[0] ?? null,
        accompTaille:   'N',           // 'N' or 'G'
        boisson:        boissons[0] ?? null,
        boissonTaille:  'N',
        sauce:          sauces[0] ?? null
    };

    const modal = buildModalShell(menu);
    document.body.appendChild(modal);
    modal.removeAttribute('hidden');

    /* Prevent background scroll while composer is open. */
    document.body.style.overflow = 'hidden';

    renderStep(modal, state);
    trapFocus(modal);

    /* ESC closes the modal and returns to product list. */
    const escHandler = (e) => {
        if (e.key === 'Escape') {
            cancelComposer(modal, returnCategory, escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);
}

/* ------------------------------------------------------------------ */
/* Modal shell builder                                                  */
/* ------------------------------------------------------------------ */

function buildModalShell(menu) {
    const overlay = document.createElement('div');
    overlay.className   = 'composer-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'composer-title');
    overlay.hidden = true;

    overlay.innerHTML = `
        <div class="composer-container" role="document">
            <div class="composer-header">
                <h2 class="composer-title" id="composer-title">${escHtml(menu.nom)}</h2>
                <div class="composer-progress" aria-label="Progression">
                    <span class="composer-progress__text" id="composer-step-indicator" aria-live="polite">Etape 1 / ${TOTAL_STEPS}</span>
                    <div class="composer-progress__bar">
                        <div class="composer-progress__fill" id="composer-progress-fill" style="width: 20%"></div>
                    </div>
                </div>
            </div>
            <div class="composer-body" id="composer-body">
                <!-- step content injected here -->
            </div>
            <div class="composer-footer" id="composer-footer">
                <!-- navigation buttons injected here -->
            </div>
        </div>
    `;
    return overlay;
}

/* ------------------------------------------------------------------ */
/* Step renderer — decides which step to paint                          */
/* ------------------------------------------------------------------ */

function renderStep(modal, state) {
    const body   = modal.querySelector('#composer-body');
    const footer = modal.querySelector('#composer-footer');
    const stepEl = modal.querySelector('#composer-step-indicator');
    const fillEl = modal.querySelector('#composer-progress-fill');

    stepEl.textContent = `Etape ${state.currentStep} / ${TOTAL_STEPS}`;
    fillEl.style.width = `${(state.currentStep / TOTAL_STEPS) * 100}%`;

    /* Each step renderer returns {bodyHTML, canAdvance()} and may attach
     * its own event listeners after DOM insertion. */
    switch (state.currentStep) {
        case 1: renderStep1(body, footer, modal, state); break;
        case 2: renderStep2(body, footer, modal, state); break;
        case 3: renderStep3(body, footer, modal, state); break;
        case 4: renderStep4(body, footer, modal, state); break;
        case 5: renderStep5(body, footer, modal, state); break;
    }

    /* Move focus to the first interactive element so keyboard users and
     * screen readers start at the right place after each step transition. */
    requestAnimationFrame(() => {
        const first = modal.querySelector(
            'button:not([disabled]), input:not([disabled]), [tabindex="0"]'
        );
        if (first) first.focus();
    });
}

/* ------------------------------------------------------------------ */
/* Step 1 — Burger + personalisation options                            */
/* ------------------------------------------------------------------ */

function renderStep1(body, footer, modal, state) {
    body.innerHTML = `
        <p class="composer-step__subtitle">Choisissez votre burger</p>
        <ul class="composer-grid" role="list" id="burger-grid">
            ${state.burgers.map(b => `
                <li>
                    <button
                        class="composer-card ${state.burger && state.burger.id === b.id ? 'composer-card--selected' : ''}"
                        type="button"
                        data-id="${b.id}"
                        aria-pressed="${state.burger && state.burger.id === b.id ? 'true' : 'false'}"
                        aria-label="${escHtml(b.nom)}, ${formatPrice(b.prix)}"
                    >
                        <img
                            class="composer-card__image"
                            src="${escHtml(b.image)}"
                            alt="${escHtml(b.nom)}"
                            onerror="this.src='assets/images/ui/logo.png';"
                        >
                        <span class="composer-card__name">${escHtml(b.nom)}</span>
                        <span class="composer-card__price">${formatPrice(b.prix)}</span>
                    </button>
                </li>
            `).join('')}
        </ul>

        <fieldset class="composer-options" id="burger-options">
            <legend class="composer-options__legend">Personnalisation</legend>
            <label class="composer-option-label">
                <input type="checkbox" name="burger-opt" value="sans-oignon"
                    ${state.burgerOptions.includes('sans-oignon') ? 'checked' : ''}>
                Sans oignon
            </label>
            <label class="composer-option-label">
                <input type="checkbox" name="burger-opt" value="avec-fromage"
                    ${state.burgerOptions.includes('avec-fromage') ? 'checked' : ''}>
                Avec fromage
            </label>
        </fieldset>
    `;

    /* Burger card selection */
    body.querySelectorAll('#burger-grid .composer-card').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id, 10);
            state.burger = state.burgers.find(b => b.id === id) ?? state.burger;
            /* Update pressed states without full re-render to preserve scroll position */
            body.querySelectorAll('#burger-grid .composer-card').forEach(b => {
                const active = parseInt(b.dataset.id, 10) === state.burger.id;
                b.classList.toggle('composer-card--selected', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });

    /* Personalisation checkboxes */
    body.querySelectorAll('input[name="burger-opt"]').forEach(cb => {
        cb.addEventListener('change', () => {
            state.burgerOptions = Array.from(
                body.querySelectorAll('input[name="burger-opt"]:checked')
            ).map(el => el.value);
        });
    });

    renderFooter(footer, modal, state, {
        canAdvance: () => state.burger !== null
    });
}

/* ------------------------------------------------------------------ */
/* Step 2 — Accompagnement + taille toggle                              */
/* ------------------------------------------------------------------ */

function renderStep2(body, footer, modal, state) {
    body.innerHTML = `
        <p class="composer-step__subtitle">Choisissez votre accompagnement</p>
        <ul class="composer-grid" role="list" id="accomp-grid">
            ${state.accompagnements.map(a => `
                <li>
                    <button
                        class="composer-card ${state.accompagnement && state.accompagnement.id === a.id ? 'composer-card--selected' : ''}"
                        type="button"
                        data-id="${a.id}"
                        aria-pressed="${state.accompagnement && state.accompagnement.id === a.id ? 'true' : 'false'}"
                        aria-label="${escHtml(a.nom)}"
                    >
                        <img
                            class="composer-card__image"
                            src="${escHtml(a.image)}"
                            alt="${escHtml(a.nom)}"
                            onerror="this.src='assets/images/ui/logo.png';"
                        >
                        <span class="composer-card__name">${escHtml(a.nom)}</span>
                    </button>
                </li>
            `).join('')}
        </ul>
        ${renderTailleToggle('accomp', state.accompTaille)}
    `;

    body.querySelectorAll('#accomp-grid .composer-card').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id, 10);
            state.accompagnement = state.accompagnements.find(a => a.id === id) ?? state.accompagnement;
            body.querySelectorAll('#accomp-grid .composer-card').forEach(b => {
                const active = parseInt(b.dataset.id, 10) === state.accompagnement.id;
                b.classList.toggle('composer-card--selected', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });

    attachTailleToggle(body, 'accomp', state, 'accompTaille');

    renderFooter(footer, modal, state, {
        canAdvance: () => state.accompagnement !== null
    });
}

/* ------------------------------------------------------------------ */
/* Step 3 — Boisson + taille toggle                                     */
/* ------------------------------------------------------------------ */

function renderStep3(body, footer, modal, state) {
    body.innerHTML = `
        <p class="composer-step__subtitle">Choisissez votre boisson</p>
        <ul class="composer-grid" role="list" id="boisson-grid">
            ${state.boissons.map(b => `
                <li>
                    <button
                        class="composer-card ${state.boisson && state.boisson.id === b.id ? 'composer-card--selected' : ''}"
                        type="button"
                        data-id="${b.id}"
                        aria-pressed="${state.boisson && state.boisson.id === b.id ? 'true' : 'false'}"
                        aria-label="${escHtml(b.nom)}"
                    >
                        <img
                            class="composer-card__image"
                            src="${escHtml(b.image)}"
                            alt="${escHtml(b.nom)}"
                            onerror="this.src='assets/images/ui/logo.png';"
                        >
                        <span class="composer-card__name">${escHtml(b.nom)}</span>
                    </button>
                </li>
            `).join('')}
        </ul>
        ${renderTailleToggle('boisson', state.boissonTaille)}
    `;

    body.querySelectorAll('#boisson-grid .composer-card').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id, 10);
            state.boisson = state.boissons.find(b => b.id === id) ?? state.boisson;
            body.querySelectorAll('#boisson-grid .composer-card').forEach(b => {
                const active = parseInt(b.dataset.id, 10) === state.boisson.id;
                b.classList.toggle('composer-card--selected', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });

    attachTailleToggle(body, 'boisson', state, 'boissonTaille');

    renderFooter(footer, modal, state, {
        canAdvance: () => state.boisson !== null
    });
}

/* ------------------------------------------------------------------ */
/* Step 4 — Sauce                                                       */
/* ------------------------------------------------------------------ */

function renderStep4(body, footer, modal, state) {
    body.innerHTML = `
        <p class="composer-step__subtitle">Choisissez votre sauce</p>
        <ul class="composer-grid" role="list" id="sauce-grid">
            ${state.sauces.map(s => `
                <li>
                    <button
                        class="composer-card ${state.sauce && state.sauce.id === s.id ? 'composer-card--selected' : ''}"
                        type="button"
                        data-id="${s.id}"
                        aria-pressed="${state.sauce && state.sauce.id === s.id ? 'true' : 'false'}"
                        aria-label="${escHtml(s.nom)}"
                    >
                        <img
                            class="composer-card__image"
                            src="${escHtml(s.image)}"
                            alt="${escHtml(s.nom)}"
                            onerror="this.src='assets/images/ui/logo.png';"
                        >
                        <span class="composer-card__name">${escHtml(s.nom)}</span>
                    </button>
                </li>
            `).join('')}
        </ul>
    `;

    body.querySelectorAll('#sauce-grid .composer-card').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id, 10);
            state.sauce = state.sauces.find(s => s.id === id) ?? state.sauce;
            body.querySelectorAll('#sauce-grid .composer-card').forEach(b => {
                const active = parseInt(b.dataset.id, 10) === state.sauce.id;
                b.classList.toggle('composer-card--selected', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });

    renderFooter(footer, modal, state, {
        canAdvance: () => state.sauce !== null
    });
}

/* ------------------------------------------------------------------ */
/* Step 5 — Recap + add to cart                                         */
/* ------------------------------------------------------------------ */

function renderStep5(body, footer, modal, state) {
    const supplement = computeSupplement(state);
    const baseItem   = buildCartItem(state, supplement);
    const totalLine  = computeMenuLineCents(baseItem);

    const optionsText = state.burgerOptions.length
        ? state.burgerOptions.map(o => o === 'sans-oignon' ? 'sans oignon' : 'avec fromage').join(', ')
        : null;

    body.innerHTML = `
        <p class="composer-step__subtitle">Recapitulatif de votre menu</p>
        <ul class="composer-recap" aria-label="Composition du menu">
            <li class="composer-recap__line">
                <span class="composer-recap__icon" aria-hidden="true">&#9632;</span>
                <span class="composer-recap__label">
                    ${escHtml(state.burger.nom)}
                    ${optionsText ? `<span class="composer-recap__opts">(${escHtml(optionsText)})</span>` : ''}
                </span>
            </li>
            <li class="composer-recap__line">
                <span class="composer-recap__icon" aria-hidden="true">&#9632;</span>
                <span class="composer-recap__label">
                    ${escHtml(state.accompagnement.nom)}
                    <span class="composer-recap__taille">${state.accompTaille === 'G' ? 'grande' : 'normale'}</span>
                    ${state.accompTaille === 'G' ? '<span class="composer-recap__suppl">+0,50 EUR</span>' : ''}
                </span>
            </li>
            <li class="composer-recap__line">
                <span class="composer-recap__icon" aria-hidden="true">&#9632;</span>
                <span class="composer-recap__label">
                    ${escHtml(state.boisson.nom)}
                    <span class="composer-recap__taille">${state.boissonTaille === 'G' ? 'grande' : 'normale'}</span>
                    ${state.boissonTaille === 'G' ? '<span class="composer-recap__suppl">+0,50 EUR</span>' : ''}
                </span>
            </li>
            <li class="composer-recap__line">
                <span class="composer-recap__icon" aria-hidden="true">&#9632;</span>
                <span class="composer-recap__label">${escHtml(state.sauce.nom)}</span>
            </li>
        </ul>
        <div class="composer-recap__totals">
            <span class="composer-recap__base">Menu de base : ${formatPrice(state.menu.prix_cents ?? state.menu.prix)}</span>
            ${supplement > 0 ? `<span class="composer-recap__suppl-total">Supplement grande(s) taille(s) : +${formatPrice(supplement)}</span>` : ''}
            <span class="composer-recap__total-line">Total : <strong>${formatPrice(totalLine)}</strong></span>
        </div>
    `;

    footer.innerHTML = `
        <div class="composer-footer__row">
            <button class="btn btn--secondary composer-footer__cancel" type="button" id="composer-cancel">
                Annuler
            </button>
            <button class="btn btn--secondary composer-footer__prev" type="button" id="composer-prev">
                Precedent
            </button>
            <button class="btn btn--primary composer-footer__add" type="button" id="composer-add">
                Ajouter au panier
            </button>
        </div>
    `;

    footer.querySelector('#composer-cancel').addEventListener('click', () => {
        cancelComposer(modal, state.returnCategory, null);
    });

    footer.querySelector('#composer-prev').addEventListener('click', () => {
        state.currentStep--;
        renderStep(modal, state);
    });

    footer.querySelector('#composer-add').addEventListener('click', () => {
        addToCart(baseItem);
        refreshCartBadge();
        closeComposer(modal);
        window.location.href = `products.html?category=${state.returnCategory}`;
    });
}

/* ------------------------------------------------------------------ */
/* Footer renderer (steps 1-4)                                          */
/* ------------------------------------------------------------------ */

/**
 * Renders the navigation footer for steps 1 through 4.
 * @param {HTMLElement} footer
 * @param {HTMLElement} modal
 * @param {Object} state
 * @param {{ canAdvance: () => boolean }} opts
 */
function renderFooter(footer, modal, state, opts) {
    const isFirst = state.currentStep === 1;

    footer.innerHTML = `
        <div class="composer-footer__row">
            <button class="btn btn--secondary composer-footer__cancel" type="button" id="composer-cancel">
                Annuler
            </button>
            ${!isFirst ? `
            <button class="btn btn--secondary composer-footer__prev" type="button" id="composer-prev">
                Precedent
            </button>` : ''}
            <button class="btn btn--primary composer-footer__next" type="button" id="composer-next">
                Suivant
            </button>
        </div>
    `;

    footer.querySelector('#composer-cancel').addEventListener('click', () => {
        cancelComposer(modal, state.returnCategory, null);
    });

    if (!isFirst) {
        footer.querySelector('#composer-prev').addEventListener('click', () => {
            state.currentStep--;
            renderStep(modal, state);
        });
    }

    footer.querySelector('#composer-next').addEventListener('click', () => {
        if (!opts.canAdvance()) return;
        state.currentStep++;
        renderStep(modal, state);
    });
}

/* ------------------------------------------------------------------ */
/* Taille toggle — shared between accompagnement and boisson steps      */
/* ------------------------------------------------------------------ */

/**
 * Generates the HTML for the Normale/Grande toggle.
 * @param {string} prefix — 'accomp' or 'boisson', used for IDs
 * @param {'N'|'G'} currentTaille
 * @returns {string}
 */
function renderTailleToggle(prefix, currentTaille) {
    return `
        <div class="composer-taille" role="group" aria-label="Taille">
            <button
                class="composer-taille__btn ${currentTaille === 'N' ? 'composer-taille__btn--active' : ''}"
                type="button"
                data-taille="N"
                id="${prefix}-taille-n"
                aria-pressed="${currentTaille === 'N' ? 'true' : 'false'}"
            >
                Normale
            </button>
            <button
                class="composer-taille__btn ${currentTaille === 'G' ? 'composer-taille__btn--active' : ''}"
                type="button"
                data-taille="G"
                id="${prefix}-taille-g"
                aria-pressed="${currentTaille === 'G' ? 'true' : 'false'}"
            >
                Grande <span class="composer-taille__price-hint">+0,50 EUR</span>
            </button>
        </div>
    `;
}

/**
 * Attaches click handlers to the taille toggle buttons and keeps state in sync.
 * @param {HTMLElement} body
 * @param {string} prefix
 * @param {Object} state
 * @param {'accompTaille'|'boissonTaille'} stateKey
 */
function attachTailleToggle(body, prefix, state, stateKey) {
    body.querySelectorAll('.composer-taille__btn').forEach(btn => {
        btn.addEventListener('click', () => {
            state[stateKey] = btn.dataset.taille;
            body.querySelectorAll('.composer-taille__btn').forEach(b => {
                const active = b.dataset.taille === state[stateKey];
                b.classList.toggle('composer-taille__btn--active', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });
}

/* ------------------------------------------------------------------ */
/* Cart item assembly + supplement calculation                          */
/* ------------------------------------------------------------------ */

/**
 * Counts how many grande-taille choices were made (0, 1, or 2).
 * @param {Object} state
 * @returns {number} centimes
 */
function computeSupplement(state) {
    let suppl = 0;
    if (state.accompTaille === 'G') suppl += SUPPLEMENT_GRANDE_CENTS;
    if (state.boissonTaille  === 'G') suppl += SUPPLEMENT_GRANDE_CENTS;
    return suppl;
}

/**
 * Builds the cart item object from the current composer state.
 * prix_cents is the base menu price; supplement_cents accumulates size upgrades.
 *
 * @param {Object} state
 * @param {number} supplement
 * @returns {Object}
 */
function buildCartItem(state, supplement) {
    /* Support both raw produits.json field (prix) and normalised (prix_cents) */
    const prixCents = state.menu.prix_cents ?? state.menu.prix;

    return {
        id:               state.menu.id,
        type:             'menu',
        categorie:        'menus',
        libelle:          state.menu.nom,
        prix_cents:       prixCents,
        quantite:         1,
        image:            state.menu.image,
        supplement_cents: supplement,
        composition: {
            burger: {
                id:      state.burger.id,
                libelle: state.burger.nom,
                options: [...state.burgerOptions]
            },
            accompagnement: {
                id:        state.accompagnement.id,
                libelle:   state.accompagnement.nom,
                categorie: state.accompagnement.categorie ?? 'frites',
                taille:    state.accompTaille
            },
            boisson: {
                id:      state.boisson.id,
                libelle: state.boisson.nom,
                taille:  state.boissonTaille
            },
            sauce: {
                id:      state.sauce.id,
                libelle: state.sauce.nom
            }
        }
    };
}

/* ------------------------------------------------------------------ */
/* Focus trap                                                            */
/* ------------------------------------------------------------------ */

/**
 * Traps Tab / Shift+Tab inside the modal container.
 * The handler is attached to the modal element itself; it is removed
 * automatically when the modal is removed from the DOM.
 */
function trapFocus(modal) {
    modal.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;

        const focusable = Array.from(modal.querySelectorAll(
            'button:not([disabled]), input:not([disabled]), [tabindex="0"]'
        )).filter(el => !el.closest('[hidden]'));

        if (!focusable.length) return;

        const first = focusable[0];
        const last  = focusable[focusable.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
}

/* ------------------------------------------------------------------ */
/* Close helpers                                                         */
/* ------------------------------------------------------------------ */

function closeComposer(modal) {
    modal.remove();
    document.body.style.overflow = '';
}

function cancelComposer(modal, returnCategory, escHandler) {
    if (escHandler) {
        document.removeEventListener('keydown', escHandler);
    }
    closeComposer(modal);
    window.location.href = `products.html?category=${returnCategory}`;
}

/* ------------------------------------------------------------------ */
/* Utilities                                                             */
/* ------------------------------------------------------------------ */

/**
 * Minimal HTML escaping to prevent XSS when injecting product names/paths
 * into innerHTML. Applied to all data-derived strings.
 */
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
