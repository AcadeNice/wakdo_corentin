/*
 * abandon-guard.js — Garde de sortie du parcours de commande (bug borne : quitter
 * puis revenir laissait la commande en place, sans jamais demander confirmation).
 *
 * Dans ce parcours multi-pages, seul le passage categories.html -> index.html
 * QUITTE reellement la commande en cours (retour a l'ecran de choix sur
 * place/à emporter). Les liens "retour" entre categories/produits/paiement restent
 * DANS le parcours : le panier y persiste normalement, ce n'est pas un abandon.
 *
 * Deux chemins de sortie sont couverts ici, tous deux uniquement quand le panier
 * n'est pas vide (rien a perdre -> rien a confirmer) :
 *  - le lien "Retour a l'accueil" ;
 *  - le bouton Precedent du navigateur, piege via history.pushState + popstate
 *    (une navigation multi-pages classique ne declenche pas 'popstate' d'elle-meme ;
 *    on pousse une entree d'historique factice a l'arrivee sur la page pour que le
 *    premier retour soit intercepte au lieu de quitter la page directement).
 *
 * Le nettoyage residuel (panier vide "fantome" apres coupure/rechargement direct de
 * l'accueil) n'est PAS traite ici : voir welcome-reset.js, qui repart d'une session
 * propre a chaque arrivee sur l'accueil, sans confirmation (aucun geste de sortie
 * n'a ete pose par le client present).
 */

import { getCart, clearCart, clearMode } from './state.js';
import { confirmAction } from './confirm-modal.js';
import { clearCheckoutKey } from './checkout.js';

/** Vide tout etat de commande en cours : panier, mode, cle de paiement. */
export function abandonOrder() {
    clearCart();
    clearMode();
    clearCheckoutKey();
}

// Reference du dernier lien/handler installes : installLeaveGuard() est appele a
// chaque 'pageshow' (chargement normal ET restauration bfcache -- voir categories.html),
// donc potentiellement plusieurs fois sur le MEME document. Sans cette memoire, un
// aller-retour bfcache empilerait un nouveau listener 'popstate' a chaque restauration
// (autant de modales que de passages), et re-attacherait un handler de clic sur un lien
// deja cable.
let installedOnLink = null;
let installedPopstateHandler = null;

/**
 * Cable la confirmation d'abandon sur le lien de retour a l'accueil et sur le
 * bouton Precedent du navigateur. N'agit que si le panier n'est pas vide au moment
 * du geste : un panier deja vide n'a rien a perdre, la confirmation serait un
 * frottement inutile pour l'equipier comme pour le client. Idempotent : peut etre
 * appele plusieurs fois sur le meme document (voir plus haut) sans dupliquer d'ecoute.
 * @param {string} homeLinkSelector — selecteur du lien "Retour a l'accueil"
 */
export function installLeaveGuard(homeLinkSelector) {
    const confirmAndLeave = (afterConfirm) => {
        confirmAction({
            message: 'Abandonner toute la commande ? Votre sélection sera perdue.',
            confirmLabel: 'Oui, abandonner',
            cancelLabel: 'Continuer ma commande',
            onConfirm: () => {
                abandonOrder();
                afterConfirm();
            },
        });
    };

    const homeLink = document.querySelector(homeLinkSelector);
    if (homeLink && homeLink !== installedOnLink) {
        installedOnLink = homeLink;
        homeLink.addEventListener('click', (e) => {
            if (getCart().length === 0) return; // rien a perdre : laisser filer le lien natif
            e.preventDefault();
            const target = homeLink.href;
            confirmAndLeave(() => { window.location.href = target; });
        });
    }

    if (installedPopstateHandler) {
        window.removeEventListener('popstate', installedPopstateHandler);
        installedPopstateHandler = null;
    }

    if (getCart().length > 0) {
        history.pushState({ wakdoLeaveGuard: true }, '', window.location.href);
        installedPopstateHandler = () => {
            if (getCart().length === 0) return; // panier vide entre temps : laisser filer le retour reel
            // Repousse une entree factice pour re-armer le piege : sans confirmation, le
            // client reste sur cette page (annuler = rester la ou il etait).
            history.pushState({ wakdoLeaveGuard: true }, '', window.location.href);
            confirmAndLeave(() => { window.location.href = 'index.html'; });
        };
        window.addEventListener('popstate', installedPopstateHandler);
    }
}
