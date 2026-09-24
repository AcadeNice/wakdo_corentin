/*
 * order-panel.js — Panneau de commande persistant (maquette : recap a droite de
 * l'ecran de commande). Rendu sur l'ecran de commande (products) pour que le panier
 * reste visible en permanence, comme sur la maquette borne.
 *
 * C'est l'UNIQUE vue panier : il montre lignes + total + Abandon/Payer, permet
 * d'ajuster la quantite de chaque ligne (+/-) et de la retirer. La logique de mise
 * en forme est extraite en fonctions PURES (buildPanelModel, compositionLabels)
 * pour etre testable sans DOM.
 */

import {
    getCart,
    removeFromCart,
    updateQuantity,
    computeMenuLineCents,
    clearCart,
    formatPrice,
    escHtml,
    getMode,
} from './state.js';
import { refreshCartBadge } from './nav.js';
import { confirmAction } from './confirm-modal.js';
import { clearCheckoutKey } from './checkout.js';
import { animateTotalValue, prefersReducedMotion } from './cart-total-animation.js';

/*
 * Dernier total AFFICHE par conteneur (Cr 2.a.3 : le total "compte" jusqu'a sa
 * nouvelle valeur au lieu de sauter). Un WeakMap plutot qu'une variable simple :
 * products.html n'expose qu'un seul panneau, mais rien n'empeche une page future
 * d'en avoir plusieurs, et un WeakMap laisse le conteneur etre collecte normalement
 * s'il quitte le DOM. Sans entree pour ce conteneur (premier rendu de la page), la
 * valeur est posee directement -- animer un 0 -> total au chargement animerait un
 * geste que le client n'a pas fait a l'instant.
 */
const lastTotalByContainer = new WeakMap();

/**
 * Calcule le total d'une ligne en centimes (menu : avec supplement de taille ;
 * produit simple : prix * quantite). Pur.
 * @param {Object} item
 * @returns {number}
 */
export function lineCents(item) {
    return item.type === 'menu'
        ? computeMenuLineCents(item)
        : item.prix_cents * item.quantite;
}

/**
 * Construit les libelles des options d'un menu (puces sous le nom de ligne).
 * Sans le supplement (le panneau affiche le total de ligne, pas le detail TVA).
 * Tolerant aux composants absents.
 * @param {Object|undefined} c — objet composition de l'item menu
 * @returns {string[]}
 */
export function compositionLabels(c) {
    if (!c) return [];
    const out = [];
    if (c.burger) {
        const opts = c.burger.options && c.burger.options.length
            ? ` (${c.burger.options.map(o => o === 'sans-oignon' ? 'sans oignon' : 'avec fromage').join(', ')})`
            : '';
        out.push(`${c.burger.libelle}${opts}`);
    }
    // libelle fait foi : en Maxi l'accompagnement porte deja sa variante par nom
    // ("Grande Frite"). Plus de suffixe " grande" -- il doublait le nom ("Grande Frite
    // grande") et mentait pour la boisson (le menu Maxi ne l'agrandit pas).
    if (c.accompagnement) {
        out.push(c.accompagnement.libelle);
    }
    if (c.boisson) {
        out.push(c.boisson.libelle);
    }
    if (c.sauce) {
        out.push(c.sauce.libelle);
    }
    return out;
}

/**
 * Vue-modele PUR du panneau a partir d'un panier. Aucune dependance DOM/localStorage :
 * c'est la cible des tests unitaires.
 * @param {Array} cart
 * @returns {{lines: Array, totalCents: number, count: number, empty: boolean}}
 */
export function buildPanelModel(cart) {
    const lines = cart.map((item, index) => ({
        index,
        libelle: item.libelle,
        quantite: item.quantite,
        lineCents: lineCents(item),
        options: item.type === 'menu' ? compositionLabels(item.composition) : [],
    }));
    const totalCents = cart.reduce((sum, item) => sum + lineCents(item), 0);
    const count = cart.reduce((sum, item) => sum + item.quantite, 0);
    return { lines, totalCents, count, empty: cart.length === 0 };
}

/**
 * Libelle lisible du mode de consommation pour l'en-tete du panneau.
 * @returns {string}
 */
function modeLabel() {
    return getMode() === 'a-emporter' ? 'À emporter' : 'Sur place';
}

/**
 * Construit le HTML d'une ligne du panneau. Toute valeur derivee du catalogue est
 * echappee (RG-T15 anti-XSS).
 * @param {Object} line — element de buildPanelModel().lines
 * @returns {string}
 */
function lineHtml(line) {
    const options = line.options.length
        ? `<ul class="order-panel__options">${line.options
              .map(o => `<li>${escHtml(o)}</li>`)
              .join('')}</ul>`
        : '';
    return `
        <li class="order-panel__line">
            <div class="order-panel__line-main">
                <span class="order-panel__line-name">${escHtml(line.libelle)}</span>
                <span class="order-panel__line-price">${formatPrice(line.lineCents)}</span>
            </div>
            ${options}
            <div class="order-panel__line-controls">
                <div class="order-panel__qty" role="group" aria-label="Quantité de ${escHtml(line.libelle)}">
                    <button
                        class="order-panel__qty-btn"
                        data-action="dec"
                        data-index="${line.index}"
                        type="button"
                        aria-label="Diminuer la quantité de ${escHtml(line.libelle)}"
                    >&minus;</button>
                    <span class="order-panel__qty-value">${line.quantite}</span>
                    <button
                        class="order-panel__qty-btn"
                        data-action="inc"
                        data-index="${line.index}"
                        type="button"
                        aria-label="Augmenter la quantité de ${escHtml(line.libelle)}"
                    >+</button>
                </div>
                <button
                    class="order-panel__remove"
                    data-index="${line.index}"
                    type="button"
                    aria-label="Retirer ${escHtml(line.libelle)} de la commande"
                >
                    <img src="assets/images/ui/trash.png" alt="" aria-hidden="true" width="20" height="20">
                </button>
            </div>
        </li>
    `;
}

/**
 * Rend le panneau dans le conteneur fourni et cable les interactions (retrait de
 * ligne, Abandon, Payer). Lit le panier courant via getCart(). Re-rend apres chaque
 * mutation pour rester synchrone avec localStorage.
 *
 * Abandon = annuler toute la commande -> retour accueil (index.html), semantique borne.
 * Payer   = aller a la page de paiement ; desactive (aria-disabled) panier vide.
 *
 * @param {HTMLElement} container — l'element [data-order-panel]
 */
export function renderOrderPanel(container) {
    if (!container) return;
    const model = buildPanelModel(getCart());
    refreshCartBadge();
    // Lu AVANT le re-rendu : innerHTML va remplacer l'ancien noeud total-value, donc
    // c'est le seul moment ou la valeur precedemment affichee est encore disponible.
    const previousCents = lastTotalByContainer.get(container);

    const body = model.empty
        ? '<p class="order-panel__empty">Votre commande est vide.<br>Ajoutez un produit pour commencer.</p>'
        : `<ul class="order-panel__lines">${model.lines.map(lineHtml).join('')}</ul>`;

    // total-value porte aria-live="off" : ce conteneur (.order-panel) est lui-meme
    // aria-live="polite", et l'animation qui suit va reecrire ce texte plusieurs
    // fois en une fraction de seconde. Sans cette derogation, chaque image
    // intermediaire serait un candidat a l'annonce vocale. Le total reste dans
    // l'arbre d'accessibilite (juste pas comme flux d'annonces individuelles) et,
    // que l'animation tourne ou non, ce noeud est toujours ecrit avec la valeur
    // FINALE exacte des sa creation -- une personne qui consulte l'ecran juste
    // apres un ajout lit toujours le bon total, anime ou pas.
    container.innerHTML = `
        <div class="order-panel__head">
            <img class="order-panel__logo" src="assets/images/ui/logo.png" alt="Wakdo">
            <span class="order-panel__title">Ma commande</span>
            <span class="order-panel__mode">${escHtml(modeLabel())}</span>
        </div>
        <div class="order-panel__body">${body}</div>
        <div class="order-panel__foot">
            <div class="order-panel__total">
                <span>TOTAL (ttc)</span>
                <span class="order-panel__total-value" aria-live="off">${formatPrice(model.totalCents)}</span>
            </div>
            <div class="order-panel__actions">
                <button class="order-panel__abandon" type="button">Abandon</button>
                <a
                    class="order-panel__pay"
                    href="payment.html"
                    role="button"
                    aria-disabled="${model.empty ? 'true' : 'false'}"
                >Payer</a>
            </div>
        </div>
    `;

    // Anime le total de l'ancienne valeur vers la nouvelle (Cr 2.a.3) -- jamais au
    // tout premier rendu d'un conteneur (previousCents alors undefined : rien a
    // comparer, la valeur posee par le template ci-dessus suffit) ni quand le total
    // n'a pas change. Respecte le mouvement reduit du systeme (Cr respect
    // prefers-reduced-motion) : la valeur finale est alors posee sans transition.
    lastTotalByContainer.set(container, model.totalCents);
    if (previousCents !== undefined && previousCents !== model.totalCents) {
        animateTotalValue(
            container.querySelector('.order-panel__total-value'),
            previousCents,
            model.totalCents,
            { reducedMotion: prefersReducedMotion() },
        );
    }

    // Stepper +/- : ajuste la quantite de la ligne. Decrementer a 0 retire la ligne
    // (updateQuantity supprime quand qty <= 0). Couvre produits ET menus (un menu a
    // quantite > 1 = N menus identiques, facture par quantite cote serveur).
    container.querySelectorAll('.order-panel__qty-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const index = parseInt(btn.dataset.index, 10);
            const cart = getCart();
            const current = cart[index] ? cart[index].quantite : 0;
            updateQuantity(index, btn.dataset.action === 'inc' ? current + 1 : current - 1);
            renderOrderPanel(container);
        });
    });

    container.querySelectorAll('.order-panel__remove').forEach(btn => {
        btn.addEventListener('click', () => {
            removeFromCart(parseInt(btn.dataset.index, 10));
            renderOrderPanel(container);
        });
    });

    const abandon = container.querySelector('.order-panel__abandon');
    if (abandon) {
        // Geste destructeur (efface toute la commande) -> confirmation explicite
        // avant d'agir, plutot qu'un effacement immediat au moindre tap.
        abandon.addEventListener('click', () => {
            confirmAction({
                message: 'Abandonner toute la commande ? Votre sélection sera perdue.',
                confirmLabel: 'Oui, abandonner',
                cancelLabel: 'Continuer ma commande',
                onConfirm: () => {
                    clearCart();
                    // La cle d'idempotence vit le temps du PANIER, pas le temps de
                    // l'onglet (F18). Sans cette ligne, un client qui abandonne le panier
                    // d'un precedent laisse la cle en place : sa propre commande serait
                    // alors installee dans la commande en attente de l'autre, dont elle
                    // heriterait le mode de service, le numero deja affiche a l'autre
                    // client, et l'horloge d'expiration.
                    clearCheckoutKey();
                    window.location.href = 'index.html';
                },
            });
        });
    }

    // Payer desactive sur panier vide : un <a> ignore `disabled`, on bloque le clic
    // via aria-disabled (parade a11y, cf. fix E2E #45).
    const pay = container.querySelector('.order-panel__pay');
    if (pay) {
        pay.addEventListener('click', e => {
            if (pay.getAttribute('aria-disabled') === 'true') e.preventDefault();
        });
    }
}

/* Auto-montage sur les ecrans qui exposent un conteneur [data-order-panel]. */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-order-panel]').forEach(renderOrderPanel);
});
