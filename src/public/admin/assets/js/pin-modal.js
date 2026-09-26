/**
 * pin-modal.js — Re-autorisation par PIN au moment de l'action sensible.
 *
 * Les formulaires d'action sensible portent un fieldset inline (email equipier + PIN,
 * RG-T13). Plutot que ce bloc noye en bas du formulaire, on le masque et on le remplace
 * par un MODAL clair qui surgit au clic sur "Enregistrer/Supprimer" : l'equipier confirme
 * avec son email + PIN (ou ceux d'un responsable), on reinjecte dans les champs caches,
 * puis on soumet. Le contrat serveur ne change pas (il lit toujours pin_email + pin).
 *
 * CONFIRMATION CONDITIONNELLE (data-pin-when-changed) : sur la plupart des ecrans,
 * l'action ENTIERE est sensible (supprimer un produit, annuler une commande) et le
 * modal s'ouvre a chaque soumission. Le formulaire produit, lui, ne l'est que par
 * endroits : le serveur n'exige le PIN que si le prix ou la TVA a change
 * (ProductController::update, RG-T13/8.2) -- renommer un produit ou changer sa
 * recette ne l'est pas. Un formulaire peut donc declarer
 * data-pin-when-changed="price_cents,vat_rate" : le modal ne s'ouvre alors que si
 * l'un de ces champs a bouge depuis l'ouverture de la page. Attribut absent =
 * comportement historique inchange (modal systematique).
 *
 * Le serveur reste l'autorite : s'il a refuse un PIN, son message est present dans
 * le bloc et le modal s'arme quoi qu'il arrive -- la condition cliente ne peut donc
 * pas faire passer une action sensible sans confirmation.
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
        // Message du serveur apres un PIN refuse : il est rendu DANS le bloc qu'on masque.
        // On le sort du bloc pour qu'il reste lisible, et le modal le reprend a l'ouverture.
        var serverError = null;
        if (fieldset) {
            // :not(.form-error--live) : form-validation.js, charge avant ce fichier, cree
            // dans le bloc des zones de message vides ; seul le message du serveur compte.
            serverError = fieldset.querySelector('.form-error:not(.form-error--live)');
            if (serverError) {
                fieldset.parentNode.insertBefore(serverError, fieldset.nextSibling);
            }
            fieldset.hidden = true;
        }
        // Champs masques = champs non focalisables : s'ils restaient required, le
        // navigateur refuserait l'envoi AVANT l'evenement submit et le modal ne
        // s'ouvrirait pas (observe le 2026-09-23 dans Chromium sur l'ajustement de
        // stock ; meme balisage sur l'inventaire, l'annulation de commande et les
        // suppressions de produit et de menu). Le modal controle leur presence
        // lui-meme ; sans JavaScript, le bloc reste visible et required s'applique.
        emailInput.required = false;
        pinInput.required = false;

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

        // Champs surveilles (data-pin-when-changed) et leur valeur a l'ouverture de
        // la page. Liste vide = aucune condition, le modal s'ouvre toujours.
        var watched = watchedFields(doc, form);
        // Le serveur a deja refuse un PIN sur cette page : la condition cliente est
        // neutralisee, il en faut un de toute facon.
        var pinWasRefused = serverError !== null && serverError.textContent.trim() !== '';

        form.addEventListener('submit', function (e) {
            if (confirmed) {
                return; // deja valide via le modal -> soumission reelle
            }
            if (!pinWasRefused && watched.length > 0 && !hasChanged(watched)) {
                return; // rien de sensible n'a bouge : pas de confirmation a demander
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
            if (serverError && serverError.textContent.trim() !== '') {
                modalError.textContent = serverError.textContent.trim();
                modalError.hidden = false;
            }
            modalEmail.value = emailInput.value || prefillEmail || '';
            modalPin.value = '';
            overlay.classList.add('open');
            (modalEmail.value === '' ? modalEmail : modalPin).focus();
        }

        function closeModal() {
            overlay.classList.remove('open');
        }
    }

    /**
     * Champs declares dans data-pin-when-changed (identifiants separes par des
     * virgules), avec la valeur qu'ils portaient a l'ouverture de la page. Un
     * identifiant inconnu est ignore : un attribut mal ecrit ne doit pas rendre
     * le formulaire inutilisable.
     */
    function watchedFields(doc, form) {
        var raw = form.getAttribute('data-pin-when-changed') || '';
        var fields = [];
        raw.split(',').forEach(function (name) {
            var id = name.trim();
            if (id === '') {
                return;
            }
            var input = doc.getElementById(id);
            if (input) {
                fields.push({ input: input, initial: currentValue(input) });
            }
        });

        return fields;
    }

    function currentValue(input) {
        if (input.type === 'checkbox' || input.type === 'radio') {
            return input.checked ? '1' : '0';
        }

        return String(input.value);
    }

    function hasChanged(fields) {
        for (var i = 0; i < fields.length; i++) {
            if (currentValue(fields[i].input) !== fields[i].initial) {
                return true;
            }
        }

        return false;
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
            '      <h2 class="pin-modal-title">Action à confirmer</h2>' +
            '      <p class="pin-modal-sub">Saisissez vos identifiants équipier (ou ceux d\'un responsable).</p>' +
            '    </div>' +
            '  </div>' +
            '  <form data-pm-form novalidate>' +
            '    <div class="form-group">' +
            '      <label class="form-label" for="pm-email">Email équipier</label>' +
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
        module.exports = { init: init, buildModal: buildModal, watchedFields: watchedFields, hasChanged: hasChanged };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
