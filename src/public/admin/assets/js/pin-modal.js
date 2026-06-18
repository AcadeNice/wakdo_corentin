/**
 * pin-modal.js — Re-autorisation par PIN au moment de l'action sensible.
 *
 * Les formulaires d'action sensible portent un fieldset inline (email equipier + PIN,
 * RG-T13). Plutot que ce bloc noye en bas du formulaire, on le masque et on le remplace
 * par un MODAL clair qui surgit au clic sur "Enregistrer/Supprimer" : l'equipier confirme
 * avec son email + PIN (ou ceux d'un responsable), on reinjecte dans les champs caches,
 * puis on soumet. Le contrat serveur ne change pas (il lit toujours pin_email + pin).
 *
 * CSP 'self' : script externe, aucun handler inline, le DOM du modal est construit ici.
 */
(function () {
    'use strict';

    function init(doc) {
        var emailInput = doc.getElementById('pin_email');
        var pinInput = doc.getElementById('pin');
        // Seuls les formulaires de RE-AUTORISATION ont pin_email (la page set-PIN ne
        // l'a pas : on ne l'intercepte donc pas).
        if (!emailInput || !pinInput) {
            return;
        }
        var form = pinInput.closest('form');
        if (!form) {
            return;
        }

        var fieldset = pinInput.closest('fieldset');
        if (fieldset) {
            fieldset.hidden = true;
        }

        // Email de l'utilisateur connecte (expose sur <body data-user-email>) : pre-remplit
        // le modal pour le cas courant ou l'on valide sa PROPRE action ; reste modifiable
        // pour validation par un responsable.
        var prefillEmail = (doc.body && doc.body.getAttribute('data-user-email')) || '';

        var overlay = buildModal(doc);
        doc.body.appendChild(overlay);

        var modalEmail = overlay.querySelector('#pm-email');
        var modalPin = overlay.querySelector('#pm-pin');
        var modalError = overlay.querySelector('[data-pm-error]');
        var confirmed = false;

        form.addEventListener('submit', function (e) {
            if (confirmed) {
                return; // deja valide via le modal -> soumission reelle
            }
            e.preventDefault();
            openModal();
        });

        overlay.querySelector('[data-pm-cancel]').addEventListener('click', closeModal);
        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) {
                closeModal();
            }
        });
        doc.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('open')) {
                closeModal();
            }
        });

        overlay.querySelector('[data-pm-form]').addEventListener('submit', function (e) {
            e.preventDefault();
            var email = modalEmail.value.trim();
            var pin = modalPin.value;
            if (email === '' || pin === '') {
                modalError.textContent = 'Email et PIN requis pour confirmer.';
                modalError.hidden = false;
                return;
            }
            emailInput.value = email;
            pinInput.value = pin;
            confirmed = true;
            closeModal();
            form.submit();
        });

        function openModal() {
            modalError.hidden = true;
            modalEmail.value = emailInput.value || prefillEmail || '';
            modalPin.value = '';
            overlay.classList.add('open');
            (modalEmail.value === '' ? modalEmail : modalPin).focus();
        }

        function closeModal() {
            overlay.classList.remove('open');
        }
    }

    function buildModal(doc) {
        var overlay = doc.createElement('div');
        overlay.className = 'pin-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Confirmation par PIN');
        overlay.innerHTML =
            '<div class="pin-modal">' +
            '  <div class="pin-modal-head">' +
            '    <span class="pin-modal-ico" aria-hidden="true">' +
            '      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>' +
            '    </span>' +
            '    <div>' +
            '      <h2 class="pin-modal-title">Action a confirmer</h2>' +
            '      <p class="pin-modal-sub">Saisissez vos identifiants equipier (ou ceux d\'un responsable).</p>' +
            '    </div>' +
            '  </div>' +
            '  <form data-pm-form novalidate>' +
            '    <div class="form-group">' +
            '      <label class="form-label" for="pm-email">Email equipier</label>' +
            '      <input class="form-input" type="email" id="pm-email" autocomplete="off">' +
            '    </div>' +
            '    <div class="form-group">' +
            '      <label class="form-label" for="pm-pin">PIN</label>' +
            '      <input class="form-input" type="password" id="pm-pin" inputmode="numeric" autocomplete="off">' +
            '    </div>' +
            '    <p class="form-error" data-pm-error hidden></p>' +
            '    <div class="pin-modal-actions">' +
            '      <button class="btn btn-secondary" type="button" data-pm-cancel>Annuler</button>' +
            '      <button class="btn btn-primary" type="submit">Confirmer</button>' +
            '    </div>' +
            '  </form>' +
            '</div>';
        return overlay;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init, buildModal: buildModal };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
