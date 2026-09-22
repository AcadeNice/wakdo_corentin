/*
 * confirm-modal.js — Modale de confirmation reutilisable pour un geste destructeur
 * (ex. Abandon de toute la commande). CSP-safe (createElement + addEventListener,
 * aucun handler inline). Public non-technique : message + 2 boutons clairs.
 *
 * Implementee avec a11y-dialog 8.1.5 (MIT, embarquee en local et servie en
 * meme origine : assets/vendor/a11y-dialog/ — provenance et raison dans
 * NOTICE.md du meme dossier ; explication complete dans
 * docs/soutenance/preuves/05-librairies-js-c2d.md). La librairie gere : le
 * piege de tabulation (Tab/Shift+Tab cyclent dans la modale), la fermeture par
 * Echap, la restauration du focus sur l'element declencheur a la fermeture, et
 * pose role="dialog" + aria-modal="true" + aria-hidden sur le conteneur
 * (.confirm-overlay). L'attribut HTML `autofocus` sur le bouton Annuler est LA
 * technique documentee par a11y-dialog pour choisir la cible du focus initial
 * (defaut sur Annuler : un appui accidentel sur Entree n'execute pas l'action
 * destructrice).
 *
 * Deux choses restent ecrites a la main, PAS par choix de facilite mais parce
 * que la documentation d'a11y-dialog les decrit explicitement comme hors de
 * son perimetre :
 *   - le blocage du defilement du corps (page "Advanced > Scroll lock" de la
 *     doc : "the library does not handle scroll locking automatically") ;
 *   - le aria-hidden pose sur les elements de fond (la librairie protege le
 *     fond via aria-modal="true" sur le conteneur, une technique differente ;
 *     on garde l'aria-hidden manuel en plus pour ne rien retirer au
 *     comportement deja teste avant cette integration).
 * Les deux sont branches sur le cycle de vie de la librairie (dialog.on('show'
 * | 'hide', ...)) plutot qu'inlines dans le flux d'ouverture/fermeture.
 */

import { escHtml } from './state.js';
import A11yDialog from '../vendor/a11y-dialog/a11y-dialog.esm.min.js';

/**
 * Affiche une demande de confirmation. onConfirm n'est appele que si l'utilisateur
 * confirme explicitement ; Annuler / Echap / clic-fond ferment sans rien faire.
 * @param {{message:string, confirmLabel?:string, cancelLabel?:string, onConfirm:Function}} opts
 * @returns {{close:Function}}
 */
export function confirmAction({ message, confirmLabel = 'Confirmer', cancelLabel = 'Annuler', onConfirm }) {
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.id = 'confirm-modal';
    // aria-labelledby : purement semantique, a11y-dialog ne le gere pas. Le
    // conteneur (.confirm-overlay) devient this.$el ; role="dialog" et
    // aria-modal="true" y seront poses par le constructeur A11yDialog
    // ci-dessous — on ne les duplique pas ici.
    overlay.setAttribute('aria-labelledby', 'confirm-modal-msg');
    overlay.innerHTML = `
        <div class="confirm-modal" role="document">
            <p class="confirm-modal__message" id="confirm-modal-msg">${escHtml(message)}</p>
            <div class="confirm-modal__actions">
                <button type="button" class="confirm-modal__cancel btn btn--secondary" data-a11y-dialog-hide autofocus>${escHtml(cancelLabel)}</button>
                <button type="button" class="confirm-modal__confirm btn btn--primary" data-a11y-dialog-hide>${escHtml(confirmLabel)}</button>
            </div>
        </div>
    `;

    const prevOverflow = document.body.style.overflow;
    document.body.appendChild(overlay);

    // Fond mis aria-hidden : hors perimetre d'a11y-dialog (cf. commentaire de
    // tete). Calcule une fois, restaure a la fermeture.
    const bgSiblings = Array.from(document.body.children).filter(el => el !== overlay);
    bgSiblings.forEach(el => el.setAttribute('aria-hidden', 'true'));

    // Instanciation manuelle (pas d'attribut data-a11y-dialog) : c'est la
    // methode documentee par a11y-dialog pour un dialogue cree dynamiquement,
    // absent du DOM au chargement de la page (usage/instantiation de sa doc).
    const dialog = new A11yDialog(overlay);

    // Clic hors de la boite = annuler. a11y-dialog documente un div de fond
    // dedie portant data-a11y-dialog-hide (usage/markup) mais cela demande son
    // propre positionnement CSS ; .confirm-overlay joue deja ce role (position
    // fixed + inset 0 + centrage flex, style.css) donc on detecte le clic
    // direct sur le fond comme avant l'integration, et on delegue la
    // fermeture a la librairie plutot qu'a une fonction maison.
    overlay.addEventListener('click', (e) => { if (e.target === overlay) dialog.hide(); });

    // Les deux boutons portent data-a11y-dialog-hide (fermeture declarative,
    // geree par la librairie) ; onConfirm reste un cablage manuel, la
    // librairie n'a pas de notion de "confirmer".
    overlay.querySelector('.confirm-modal__confirm').addEventListener('click', () => { onConfirm(); });

    dialog.on('show', () => { document.body.style.overflow = 'hidden'; });
    dialog.on('hide', () => {
        document.body.style.overflow = prevOverflow;
        bgSiblings.forEach(el => el.removeAttribute('aria-hidden'));
        overlay.remove();
        // Instance a usage unique (une nouvelle est creee a chaque appel) :
        // destroy() libere le listener 'click' que le constructeur pose sur
        // document, sinon il reste pose indefiniment (une borne tourne des
        // heures sans rechargement de page). differe en microtask : destroy()
        // rappelle hide() en interne, et `shown` ne repasse a false qu'APRES
        // la diffusion de cet evenement (dist/a11y-dialog.js) — un appel
        // synchrone ici boucierait (hide -> destroy -> hide -> destroy...).
        queueMicrotask(() => dialog.destroy());
    });

    dialog.show();

    return { close: () => dialog.hide() };
}
