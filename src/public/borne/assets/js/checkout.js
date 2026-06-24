/*
 * checkout.js — Soumission reelle de la commande a l'API (P5 L4).
 *
 * Avant : payment.html simulait (redirection directe vers confirmation). Desormais
 * le panier est traduit vers le contrat /api/orders et POSTe (creation pending_payment
 * puis encaissement -> paid + decrement stock RG-T20).
 *
 * Traduction panier borne -> contrat API :
 *   - produit simple -> { type:'product', product_id, quantity }
 *   - menu           -> { type:'menu', menu_id, quantity, format, selections }
 *     format = cartItem.format (choix Normal/Maxi porte par l'item panier) ; repli
 *     historique sur supplement_cents>0 pour un panier serialise avant cette version.
 *     selections = [{menu_slot_id, product_id}] reconstruites depuis la composition
 *     (accompagnement/boisson/sauce) mappee aux slots reels du menu (re-fetch).
 *   - service_mode : 'sur-place' -> 'dine_in', 'a-emporter' -> 'takeaway'.
 *   - service_tag (numero de chevalet) : requis en dine_in.
 *
 * Les fonctions de traduction sont PURES (testables) ; submitOrder fait les I/O.
 */

import { getCart, getMode } from './state.js';
import { loadMenu } from './data.js';

const MODE_MAP = { 'sur-place': 'dine_in', 'a-emporter': 'takeaway' };

/**
 * Mode de consommation borne -> service_mode du contrat API. null si inconnu.
 * @param {string|null} mode
 * @returns {string|null}
 */
export function mapServiceMode(mode) {
    return MODE_MAP[mode] ?? null;
}

/**
 * Reconstruit les selections [{menu_slot_id, product_id}] d'un menu a partir de sa
 * composition (produits choisis) et de ses slots reels (option_product_ids). Pur.
 * Un produit choisi est rattache au slot dont les options le contiennent.
 * @param {Object|undefined} composition
 * @param {Array<{id:number, option_product_ids:number[]}>} slots
 * @returns {Array<{menu_slot_id:number, product_id:number}>}
 */
export function buildSelections(composition, slots) {
    const out = [];
    if (!composition) return out;
    const chosenIds = ['accompagnement', 'boisson', 'sauce']
        .map(k => composition[k]?.id)
        .filter(id => id != null);
    for (const pid of chosenIds) {
        const slot = (slots || []).find(s => (s.option_product_ids || []).includes(pid));
        if (slot) out.push({ menu_slot_id: slot.id, product_id: pid });
    }
    return out;
}

/**
 * Traduit une ligne de panier en item du contrat API. Pur.
 * @param {Object} cartItem
 * @param {Object<number, Array>} menuSlotsById — slots par id de menu (pour les menus)
 * @returns {Object}
 */
export function buildOrderItem(cartItem, menuSlotsById) {
    if (cartItem.type === 'menu') {
        return {
            type: 'menu',
            menu_id: cartItem.id,
            quantity: cartItem.quantite,
            // Format choisi par l'utilisateur, transporte explicitement. Repli sur
            // l'ancienne inference (supplement_cents>0) pour un panier serialise en
            // sessionStorage avant l'ajout du champ format.
            format: cartItem.format ?? ((cartItem.supplement_cents ?? 0) > 0 ? 'maxi' : 'normal'),
            selections: buildSelections(cartItem.composition, menuSlotsById[cartItem.id] || []),
        };
    }
    return { type: 'product', product_id: cartItem.id, quantity: cartItem.quantite };
}

/**
 * Construit la charge utile complete du POST /api/orders. Pur.
 * service_tag n'est inclus qu'en dine_in.
 * @returns {Object}
 */
export function buildOrderPayload(cart, mode, serviceTag, menuSlotsById, idempotencyKey) {
    const serviceMode = mapServiceMode(mode);
    const payload = {
        idempotency_key: idempotencyKey,
        service_mode: serviceMode,
        items: cart.map(it => buildOrderItem(it, menuSlotsById)),
    };
    if (serviceMode === 'dine_in') {
        payload.service_tag = serviceTag;
    }
    return payload;
}

/** Cle d'idempotence brute : crypto.randomUUID si dispo, repli horodate. */
function newIdempotencyKey() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
    return `k-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Cle d'idempotence STABLE pour la tentative de paiement courante : memorisee en
 * sessionStorage. Un retry (apres echec reseau du pay) reutilise la MEME cle ->
 * l'API renvoie la commande pending existante (findByIdempotencyKey) au lieu d'en
 * creer un doublon (RG-T19). Effacee au succes ; regeneree a chaque entree sur la
 * page de paiement (page-payment.js efface la cle au chargement).
 */
function checkoutKey() {
    try {
        let k = sessionStorage.getItem('wakdo_order_key');
        if (!k) {
            k = newIdempotencyKey();
            sessionStorage.setItem('wakdo_order_key', k);
        }
        return k;
    } catch {
        return newIdempotencyKey();
    }
}

/** POST JSON avec enveloppe ; jette une Error(code) en cas d'echec, payload attache. */
async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => null);
    if (!res.ok) {
        const err = new Error(json?.error?.code || `HTTP ${res.status}`);
        err.payload = json;
        throw err;
    }
    return json;
}

/**
 * Soumet la commande : re-fetch les slots des menus du panier, construit la charge,
 * POST /api/orders (creation) puis POST /api/orders/{number}/pay (encaissement).
 * @param {{ serviceTag?: string }} [opts]
 * @returns {Promise<{order_number: string, total_ttc_cents: number|null}>}
 */
export async function submitOrder({ serviceTag = '' } = {}) {
    const cart = getCart();
    if (!cart.length) throw new Error('EMPTY_CART');

    const menuIds = [...new Set(cart.filter(i => i.type === 'menu').map(i => i.id))];
    const menuSlotsById = {};
    for (const id of menuIds) {
        const detail = await loadMenu(id);
        menuSlotsById[id] = detail?.slots ?? [];
    }

    const payload = buildOrderPayload(cart, getMode(), serviceTag, menuSlotsById, checkoutKey());

    const created = await postJson('/api/orders', payload);
    const number = created?.data?.order_number;
    if (!number) throw new Error(created?.error?.code || 'ORDER_FAILED');

    const paid = await postJson(`/api/orders/${encodeURIComponent(number)}/pay`, {});
    // Succes : la cle d'idempotence a joue son role, on la libere pour la commande suivante.
    try { sessionStorage.removeItem('wakdo_order_key'); } catch { /* sessionStorage indispo : noop */ }
    return { order_number: number, total_ttc_cents: paid?.data?.total_ttc_cents ?? null };
}
