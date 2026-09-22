/*
 * confirm-modal.js — Modale de confirmation reutilisable pour un geste destructeur
 * (ex. Abandon de toute la commande). CSP-safe (createElement + addEventListener,
 * aucun handler inline). Accessible : role="dialog" aria-modal, fond mis aria-hidden,
 * Echap et clic sur le fond = annuler, focus piege dans la modale et rendu au
 * declencheur a la fermeture. Defaut sur Annuler : un appui accidentel sur Entree
 * n'execute pas l'action destructrice. Public non-technique : message + 2 boutons clairs.
 */

import { escHtml } from './state.js';

/**
 * Affiche une demande de confirmation. onConfirm n'est appele que si l'utilisateur
 * confirme explicitement ; Annuler / Echap / clic-fond ferment sans rien faire.
 * @param {{message:string, confirmLabel?:string, cancelLabel?:string, onConfirm:Function}} opts
 * @returns {{close:Function}}
 */
export function confirmAction({ message, confirmLabel = 'Confirmer', cancelLabel = 'Annuler', onConfirm }) {
    const previouslyFocused = document.activeElement;

    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = `
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-msg">
            <p class="confirm-modal__message" id="confirm-modal-msg">${escHtml(message)}</p>
            <div class="confirm-modal__actions">
                <button type="button" class="confirm-modal__cancel btn btn--secondary">${escHtml(cancelLabel)}</button>
                <button type="button" class="confirm-modal__confirm btn btn--primary">${escHtml(confirmLabel)}</button>
            </div>
        </div>
    `;

    const prevOverflow = document.body.style.overflow;
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    const bgSiblings = Array.from(document.body.children).filter(el => el !== overlay);
    bgSiblings.forEach(el => el.setAttribute('aria-hidden', 'true'));

    const close = () => {
        document.removeEventListener('keydown', onKey);
        bgSiblings.forEach(el => el.removeAttribute('aria-hidden'));
        overlay.remove();
        document.body.style.overflow = prevOverflow;
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus();
        }
    };

    // Echap = annuler ; Tab/Shift+Tab pieges sur les boutons de la modale.
    const onKey = (e) => {
        if (e.key === 'Escape') { close(); return; }
        if (e.key !== 'Tab') return;
        const focusable = Array.from(overlay.querySelectorAll('button:not([disabled])'));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey);

    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    overlay.querySelector('.confirm-modal__cancel').addEventListener('click', close);
    overlay.querySelector('.confirm-modal__confirm').addEventListener('click', () => {
        close();
        onConfirm();
    });

    // Focus initial sur Annuler (defaut sur), pour ne pas confirmer par inadvertance.
    requestAnimationFrame(() => overlay.querySelector('.confirm-modal__cancel').focus());

    return { close };
}
