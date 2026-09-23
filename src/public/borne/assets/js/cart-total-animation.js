/*
 * cart-total-animation.js — Animation JS du total du panneau de commande (Cr 2.a.3).
 *
 * C'est l'UNIQUE animation du projet pilotee en JavaScript (les @keyframes de
 * style.css restent des transitions CSS classiques). Au lieu de sauter d'une valeur
 * a l'autre a chaque ajout/retrait/changement de quantite, le total "compte" jusqu'a
 * sa nouvelle valeur : un retour visuel immediat que le geste du client a ete pris
 * en compte, utile en particulier sur une borne ou l'on enchaine les ajouts vite.
 *
 * Design : trois fonctions PURES (easeOutCubic, computeFrameValueCents,
 * prefersReducedMotion) testables sans horloge ni navigateur, plus un orchestrateur
 * (animateTotalValue) qui accepte `now`/`raf`/`caf`/`formatValue` en injection pour
 * rester testable sans navigateur reel.
 *
 * Securite d'execution : le nombre d'images est plafonne INDEPENDAMMENT du temps
 * ecoule (voir maxFrames). Sans ce plafond, un environnement dont
 * requestAnimationFrame rappelle immediatement et de facon synchrone (c'est le cas
 * du repli pose par les tests jsdom du projet, `(cb) => cb()`) ferait boucler la
 * recursion jusqu'a epuiser la pile d'appel avant que l'horloge reelle n'ait
 * accumule la duree cible. Le plafond garantit une fin en un nombre borne d'appels,
 * quel que soit l'environnement.
 *
 * Mouvement reduit (Cr respect prefers-reduced-motion) : quand il est demande, la
 * valeur finale est posee directement, sans la moindre image intermediaire.
 */

import { formatPrice } from './state.js';

/**
 * Attenuation "ease-out" cubique : demarre vite puis se pose en douceur sur la
 * valeur finale, comme une aiguille qui s'arrete. Plus lisible pour un nombre qui
 * defile qu'une progression lineaire (qui donne une impression de vitesse
 * constante et un arret sec).
 * @param {number} t - progression brute, attendue dans [0, 1]
 * @returns {number} progression attenuee, dans [0, 1]
 */
export function easeOutCubic(t) {
    const borne = Math.min(1, Math.max(0, t));
    return 1 - Math.pow(1 - borne, 3);
}

/**
 * Valeur (en centimes entiers) affichee a une progression donnee de l'animation.
 * Pure : aucune dependance a une horloge ou au DOM, donc testable en isolation.
 * Fonctionne dans les deux sens (le total peut aussi bien monter que descendre,
 * puisqu'un retrait ou une baisse de quantite passe par le meme chemin de rendu).
 * @param {number} fromCents - valeur de depart
 * @param {number} toCents - valeur d'arrivee
 * @param {number} progress - progression brute, dans [0, 1]
 * @returns {number} centimes, toujours un entier
 */
export function computeFrameValueCents(fromCents, toCents, progress) {
    const attenuee = easeOutCubic(progress);
    return Math.round(fromCents + (toCents - fromCents) * attenuee);
}

/**
 * Interroge la preference systeme de mouvement reduit. Une personne qui l'a
 * demandee doit etre respectee pour cette animation comme pour les transitions CSS
 * existantes.
 *
 * Repli defensif : `matchMedia` n'existe pas dans tous les environnements (vieux
 * navigateur, ou environnement de test sans DOM complet — c'est le cas de jsdom
 * utilise par ce depot, qui ne l'implemente pas). Son absence est traitee comme
 * "aucune preference exprimee" plutot que de faire jeter l'appelant : c'est la
 * lecture recommandee par la specification Media Queries, ou une fonctionnalite
 * absente n'implique jamais une correspondance positive ni negative forcee.
 * @param {Window} [win] - fenetre a interroger (injectable pour les tests)
 * @returns {boolean}
 */
export function prefersReducedMotion(win = (typeof window !== 'undefined' ? window : undefined)) {
    if (!win || typeof win.matchMedia !== 'function') return false;
    try {
        return win.matchMedia('(prefers-reduced-motion: reduce)').matches === true;
    } catch {
        return false;
    }
}

/* Jeton de generation + reference d'annulation de l'animation EN COURS. Un seul
   panneau de commande est affiche a la fois (products.html n'en expose qu'un) :
   un simple compteur suffit a invalider net une animation encore en vol des qu'un
   nouvel appel arrive, sans avoir a suivre plusieurs elements en parallele. Une
   page qui exposerait un jour plusieurs totaux anime simultanement devrait faire
   porter ce jeton par l'appelant plutot que par le module. */
let currentToken = 0;
let pendingCancel = null;

/**
 * Anime le texte d'un element de `fromCents` vers `toCents` ("compte jusqu'a").
 * Pose toujours la valeur EXACTE en fin de course, y compris quand le mouvement
 * est reduit ou quand les deux valeurs sont identiques (alors sans la moindre
 * image intermediaire : rien a montrer).
 *
 * @param {HTMLElement} el - element dont le textContent est remplace a chaque image
 * @param {number} fromCents - valeur de depart, en centimes
 * @param {number} toCents - valeur d'arrivee, en centimes (garantie exacte a la fin)
 * @param {Object} [options]
 * @param {number} [options.duration=400] - duree totale en millisecondes
 * @param {boolean} [options.reducedMotion=false] - pose la valeur finale directement
 * @param {(cents: number) => string} [options.formatValue] - par defaut formatPrice
 * @param {() => number} [options.now] - horloge injectable (tests)
 * @param {(cb: Function) => number} [options.raf] - requestAnimationFrame injectable
 * @param {(id: number) => void} [options.caf] - cancelAnimationFrame injectable
 */
export function animateTotalValue(el, fromCents, toCents, options = {}) {
    if (!el) return;
    const {
        duration = 400,
        reducedMotion = false,
        formatValue = formatPrice,
        now = () => performance.now(),
        raf = (cb) => requestAnimationFrame(cb),
        caf = (id) => cancelAnimationFrame(id),
    } = options;

    // Une animation deja en vol est immediatement stoppee : le geste le plus recent
    // du client prime toujours (deux ajouts rapproches ne doivent pas faire courir
    // deux compteurs en meme temps sur le meme total).
    if (pendingCancel) {
        try {
            pendingCancel.caf(pendingCancel.id);
        } catch {
            /* id deja consomme ou caf injectee qui rejette : sans consequence, on
               repose de toute facon sur le jeton de generation ci-dessous. */
        }
        pendingCancel = null;
    }

    // Rien a animer : une seule ecriture, immediate.
    if (reducedMotion || fromCents === toCents) {
        el.textContent = formatValue(toCents);
        return;
    }

    const myToken = ++currentToken;
    const start = now();
    // Plafond INDEPENDANT du temps ecoule (voir le commentaire d'en-tete du fichier) :
    // borne le nombre d'images meme si l'horloge injectee n'avance jamais.
    const maxFrames = Math.ceil(duration / 16) + 10;
    let frame = 0;

    function tick() {
        if (myToken !== currentToken) return; // une animation plus recente a pris le relais
        frame += 1;
        const elapsed = now() - start;
        const progressTemps = duration > 0 ? elapsed / duration : 1;
        const progress = frame >= maxFrames ? 1 : Math.min(1, progressTemps);
        el.textContent = formatValue(computeFrameValueCents(fromCents, toCents, progress));
        if (progress < 1) {
            const id = raf(tick);
            pendingCancel = { id, caf };
        } else {
            pendingCancel = null;
        }
    }

    const id = raf(tick);
    pendingCancel = { id, caf };
}
