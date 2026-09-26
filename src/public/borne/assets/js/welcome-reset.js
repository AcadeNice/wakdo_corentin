/*
 * welcome-reset.js — Repart d'une session propre a l'accueil (bug borne : un panier
 * ou un mode residuels (rechargement, coupure/redemarrage) faisaient apparaitre une
 * commande "fantome" des l'arrivee sur l'ecran de choix sur place/à emporter).
 *
 * L'accueil est le point d'entree unique de la borne. Contrairement au lien "Retour
 * a l'accueil" ou au bouton Precedent (voir abandon-guard.js), ARRIVER ici n'est pas
 * un geste de sortie qu'un client vient de poser : personne n'est present pour
 * confirmer un abandon. C'est donc un nettoyage silencieux, pas une confirmation.
 *
 * La cle de paiement (F18) suit le meme sort. Si elle portait une commande
 * pending_payment reelle, celle-ci n'est pas annulee ici : elle expire proprement
 * cote serveur via le planificateur (order-expire.php, F10) ; liberer la cle ici
 * evite seulement qu'une commande future vienne s'y greffer par erreur.
 */

import { clearCart, clearMode } from './state.js';
import { clearCheckoutKey } from './checkout.js';

/** Efface tout etat de commande residuel. Exporte pour etre testable sans DOM. */
export function resetWelcomeSession() {
    clearCart();
    clearMode();
    clearCheckoutKey();
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', resetWelcomeSession);
}
