/**
 * form-validation.js — Controle de saisie en temps reel des formulaires du back-office
 * (Bloc 1, Cr 2.b.1).
 *
 * Les champs portent leurs regles en HTML (required, type="email", min, max, step,
 * pattern, minlength, maxlength), alignees sur celles que les controleurs PHP
 * appliquent. Ce fichier les verifie PENDANT la saisie et affiche sous le champ un
 * message en francais, relie au champ pour les lecteurs d'ecran (aria-invalid,
 * aria-describedby). Le serveur reste le juge final : ce controle evite un
 * aller-retour, il ne protege rien a lui seul.
 *
 * Pour coller au serveur plutot qu'au navigateur :
 *   - les champs texte sont controles une fois nettoyes de leurs espaces de bord
 *     (le serveur fait trim) ; les longueurs se comptent en caracteres, comme
 *     mb_strlen, pas en unites UTF-16 ;
 *   - un champ nombre sans pas decimal n'accepte que des chiffres (ctype_digit cote
 *     serveur refuse « 1e3 », que le navigateur tient pour un nombre valide) ;
 *   - une saisie numerique que le navigateur n'a pas su lire (« 33e ») est signalee :
 *     sa valeur vaut '' et partirait vide sans cela.
 *
 * Regles d'affichage :
 *   - pendant la frappe, apres une courte pause, tout ecart de format est signale ;
 *     un champ obligatoire laisse vide ne l'est qu'en quittant le champ, pour ne pas
 *     reprocher une saisie qui commence ;
 *   - quand un champ corrige perd le focus, son message ne disparait qu'une fois le
 *     bouton relache (ou apres un court delai au clavier) : le clic sur « Enregistrer »
 *     qui a fait perdre le focus doit arriver sur le bouton, pas a l'endroit ou le
 *     bouton se trouvait avant que la page remonte ;
 *   - a l'envoi, tous les champs sont controles ; s'il reste un ecart, l'envoi est
 *     bloque et le premier champ en erreur recoit le focus.
 *
 * Deux regles sans equivalent HTML s'ajoutent : data-match="<id>" (saisie identique a
 * celle du champ <id>, pour les confirmations) et data-not-zero (0 refuse, avec le
 * message porte par l'attribut).
 *
 * Les evenements sont ecoutes sur le formulaire, pas champ par champ : un champ ajoute
 * apres le chargement (ligne de recette) est controle comme les autres.
 *
 * Tous les formulaires sont concernes, sauf ceux qui s'en excluent : un formulaire
 * qui porte deja novalidate (il gere sa validation lui-meme) ou
 * data-live-validate="off" est laisse tel quel. Les champs masques ([hidden]) sont
 * ignores : ceux du PIN d'action sensible sont controles par le modal de
 * pin-modal.js.
 *
 * CSP 'self' : script externe, aucun handler inline. Style CommonJS testable + navigateur.
 */
(function () {
    'use strict';

    var DEFAULT_DELAY = 300;
    var DEFAULT_HIDE_DELAY = 200;
    var SKIPPED_TYPES = ['hidden', 'submit', 'button', 'reset', 'image', 'file', 'checkbox', 'radio'];
    var TRIMMED_TYPES = ['text', 'email', 'search', 'tel', 'url'];
    var RULE_ATTRIBUTES = ['pattern', 'minlength', 'maxlength', 'min', 'max', 'data-match', 'data-not-zero'];

    var typingTimers = new WeakMap();
    var hideTimers = new WeakMap();
    var errorElements = new WeakMap();
    var pointers = new WeakMap(); // document -> { down: bool, pending: champs a masquer }
    var sequence = 0;

    function init(doc, options) {
        var delay = options && typeof options.delay === 'number' ? options.delay : DEFAULT_DELAY;
        var hideDelay = options && typeof options.hideDelay === 'number' ? options.hideDelay : DEFAULT_HIDE_DELAY;
        var forms = doc.querySelectorAll('form');
        var managed = 0;

        for (var i = 0; i < forms.length; i++) {
            var form = forms[i];
            if (form.hasAttribute('novalidate') || form.getAttribute('data-live-validate') === 'off') {
                continue;
            }
            if (form.getAttribute('data-live-validate') === 'on' || fieldsOf(form).length === 0) {
                continue;
            }
            attach(form, delay, hideDelay);
            managed++;
        }

        if (managed > 0 && !doc.__wakdoLiveValidation) {
            // Capture sur le document : ce controle passe AVANT les ecouteurs poses sur le
            // formulaire (modal PIN, serialisation du composeur de menu), qui ne doivent
            // pas s'executer sur une saisie invalide.
            doc.addEventListener('submit', onSubmit, true);
            watchPointer(doc);
            doc.__wakdoLiveValidation = true;
        }

        return managed;
    }

    function attach(form, delay, hideDelay) {
        form.setAttribute('data-live-validate', 'on');
        // Les messages de ce fichier remplacent les bulles du navigateur. Sans JavaScript,
        // l'attribut n'est pas pose et la validation native reste active.
        form.noValidate = true;

        var fields = fieldsOf(form);
        for (var i = 0; i < fields.length; i++) {
            // Zone de message creee des le depart : une region aria-live presente avant
            // que son texte change a plus de chances d'etre annoncee.
            errorElementFor(fields[i]);
        }

        var onTyping = function (event) {
            var field = event.target;
            if (!isWatched(field, form)) {
                return;
            }
            hideServerError(field);
            schedule(field, delay);
            recheckConfirmations(field);
        };
        form.addEventListener('input', onTyping);
        form.addEventListener('change', onTyping);
        form.addEventListener('focusout', function (event) {
            if (isWatched(event.target, form)) {
                leave(event.target, hideDelay);
            }
        });

        // form.reset() (fenetre des seuils rouverte pour un autre ingredient) : les
        // messages de la saisie precedente n'ont plus lieu d'etre.
        form.addEventListener('reset', function () {
            var all = fieldsOf(form);
            for (var j = 0; j < all.length; j++) {
                clear(all[j]);
            }
        });
    }

    /** Sortie du champ : controle complet, obligatoire compris. */
    function leave(field, hideDelay) {
        cancelTyping(field);
        var message = messageFor(field);
        if (message !== '' || !isShown(field)) {
            render(field, message);
            return;
        }
        // Champ corrige dont le message est affiche : il est annonce valide tout de suite,
        // mais le message ne quitte l'ecran qu'apres le clic qui a fait perdre le focus.
        field.setAttribute('aria-invalid', 'false');
        var state = pointers.get(field.ownerDocument);
        if (state && state.down) {
            state.pending.push(field);
        } else if (hideDelay > 0) {
            hideTimers.set(field, setTimeout(function () {
                hideTimers.delete(field);
                render(field, '');
            }, hideDelay));
        } else {
            render(field, '');
        }
    }

    /** Suit l'appui en cours (souris, doigt, stylet) pour masquer apres le relachement. */
    function watchPointer(doc) {
        var state = { down: false, pending: [] };
        pointers.set(doc, state);
        doc.addEventListener('pointerdown', function () {
            state.down = true;
        }, true);
        var release = function () {
            state.down = false;
            var fields = state.pending.splice(0);
            if (fields.length === 0) {
                return;
            }
            // Apres la fin de l'evenement : le clic en cours part d'abord vers son bouton.
            setTimeout(function () {
                for (var i = 0; i < fields.length; i++) {
                    if (messageFor(fields[i]) === '') {
                        render(fields[i], '');
                    }
                }
            }, 0);
        };
        doc.addEventListener('pointerup', release, true);
        doc.addEventListener('pointercancel', release, true);
    }

    function schedule(field, delay) {
        cancelTyping(field);
        if (delay === 0) {
            render(field, typingMessage(field));
            return;
        }
        typingTimers.set(field, setTimeout(function () {
            typingTimers.delete(field);
            render(field, typingMessage(field));
        }, delay));
    }

    function cancelTyping(field) {
        if (typingTimers.has(field)) {
            clearTimeout(typingTimers.get(field));
            typingTimers.delete(field);
        }
    }

    function cancelHide(field) {
        if (hideTimers.has(field)) {
            clearTimeout(hideTimers.get(field));
            hideTimers.delete(field);
        }
    }

    /** Pendant la frappe : tout sauf « obligatoire », reserve a la sortie du champ. */
    function typingMessage(field) {
        var message = messageFor(field);
        return message === 'Ce champ est obligatoire.' ? '' : message;
    }

    /**
     * Message d'ecart d'un champ, ou '' s'il est valide.
     * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} field
     * @returns {string}
     */
    function messageFor(field) {
        var validity = field.validity || {};
        // Avant le test du vide : une saisie numerique illisible a pour valeur ''.
        if (validity.badInput) {
            return 'Saisissez un nombre.';
        }

        var value = TRIMMED_TYPES.indexOf(field.type) !== -1 ? field.value.trim() : field.value;
        if (value === '') {
            return field.required ? 'Ce champ est obligatoire.' : '';
        }

        if (validity.typeMismatch) {
            return field.type === 'email'
                ? 'Adresse e-mail invalide (exemple : prenom.nom@wakdo.fr).'
                : 'Format invalide.';
        }

        var pattern = field.getAttribute('pattern');
        if (pattern !== null && !matchesPattern(pattern, value)) {
            return field.getAttribute('data-pattern-message') || 'Format invalide.';
        }

        var length = Array.from(value).length;
        var min = lengthLimit(field, 'minlength');
        if (min !== null && length < min) {
            return min + ' caractères minimum (' + length + ' saisis).';
        }
        var max = lengthLimit(field, 'maxlength');
        if (max !== null && length > max) {
            return max + ' caractères maximum (' + length + ' saisis).';
        }

        if (field.type === 'number' && hasIntegerStep(field) && !integerPattern(field).test(value)) {
            return 'Un nombre entier est attendu.';
        }
        if (validity.rangeUnderflow) {
            return 'La valeur doit être supérieure ou égale à ' + field.min + '.';
        }
        if (validity.rangeOverflow) {
            return 'La valeur doit être inférieure ou égale à ' + field.max + '.';
        }
        if (validity.stepMismatch) {
            return 'Valeur attendue par pas de ' + field.getAttribute('step') + '.';
        }

        // Regle metier sans equivalent HTML : un ajustement de stock de 0 ne change rien,
        // le serveur le refuse (IngredientController::adjust).
        if (field.hasAttribute('data-not-zero') && Number(value) === 0) {
            return field.getAttribute('data-not-zero') || 'La valeur 0 n\'est pas acceptée.';
        }

        var matchId = field.getAttribute('data-match');
        if (matchId) {
            var source = field.ownerDocument.getElementById(matchId);
            if (source && source.value !== field.value) {
                return 'Les deux saisies ne correspondent pas.';
            }
        }

        return '';
    }

    /** Motif HTML applique a toute la valeur, comme le fait le navigateur. */
    function matchesPattern(pattern, value) {
        var regex;
        try {
            regex = new RegExp('^(?:' + pattern + ')$', 'v');
        } catch (unsupported) {
            try {
                regex = new RegExp('^(?:' + pattern + ')$', 'u');
            } catch (invalid) {
                return true; // motif illisible : le navigateur l'ignore aussi
            }
        }
        return regex.test(value);
    }

    /** Chiffres seuls des que le minimum est positif ou nul (ctype_digit refuse « -0 »
     *  cote serveur) ; signe moins admis sans minimum ou avec un minimum negatif. */
    function integerPattern(field) {
        return field.min !== '' && Number(field.min) >= 0 ? /^[0-9]+$/ : /^-?[0-9]+$/;
    }

    /** Pas absent (1 par defaut) ou entier : la saisie doit etre un entier. */
    function hasIntegerStep(field) {
        var step = field.getAttribute('step');
        return step === null || /^[0-9]+$/.test(step);
    }

    function lengthLimit(field, attribute) {
        var raw = field.getAttribute(attribute);
        if (raw === null || !/^[0-9]+$/.test(raw)) {
            return null;
        }
        return parseInt(raw, 10);
    }

    /** Affiche (ou retire) le message du champ et met a jour ses etats ARIA. */
    function render(field, message) {
        cancelHide(field);
        var error = errorElementFor(field);
        if (message === '') {
            error.textContent = '';
            error.hidden = true;
            field.setAttribute('aria-invalid', 'false');
            return;
        }
        error.textContent = message;
        error.hidden = false;
        field.setAttribute('aria-invalid', 'true');
    }

    /** Retour a l'etat initial : aucun message, aucun etat de validite annonce. */
    function clear(field) {
        cancelTyping(field);
        cancelHide(field);
        var error = errorElementFor(field);
        error.textContent = '';
        error.hidden = true;
        field.removeAttribute('aria-invalid');
    }

    function isShown(field) {
        var error = errorElements.get(field);
        return !!error && !error.hidden;
    }

    function errorElementFor(field) {
        var existing = errorElements.get(field);
        if (existing) {
            return existing;
        }
        var doc = field.ownerDocument;
        // Un identifiant par champ : les lignes de recette n'ont ni id ni name.
        sequence++;
        var id = (field.id ? field.id : 'champ-' + sequence) + '-live-error';
        var error = doc.createElement('p');
        error.id = id;
        error.className = 'form-error form-error--live';
        error.setAttribute('aria-live', 'polite');
        error.hidden = true;
        // Hors du <label> qui englobe parfois le champ (lignes de recette) : un <p> n'y a
        // pas sa place et son texte s'ajouterait au nom du champ.
        (field.closest('label') || field).insertAdjacentElement('afterend', error);
        errorElements.set(field, error);

        var described = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (described.indexOf(id) === -1) {
            described.push(id);
            field.setAttribute('aria-describedby', described.join(' '));
        }
        return error;
    }

    /** Le message rendu par le serveur ne vaut plus des que la saisie change. */
    function hideServerError(field) {
        var group = field.closest('.form-group');
        if (!group) {
            return;
        }
        var errors = group.querySelectorAll('.form-error:not(.form-error--live)');
        for (var i = 0; i < errors.length; i++) {
            errors[i].hidden = true;
        }
    }

    /** Un champ de confirmation (data-match) est recontrole quand sa source change. */
    function recheckConfirmations(source) {
        if (!source.id || !source.form) {
            return;
        }
        var confirmations = source.form.querySelectorAll('[data-match="' + source.id + '"]');
        for (var i = 0; i < confirmations.length; i++) {
            if (confirmations[i].value !== '') {
                render(confirmations[i], messageFor(confirmations[i]));
            }
        }
    }

    function onSubmit(event) {
        var form = event.target;
        if (!form || form.getAttribute('data-live-validate') !== 'on') {
            return;
        }
        var firstInvalid = null;
        var fields = fieldsOf(form);
        for (var i = 0; i < fields.length; i++) {
            cancelTyping(fields[i]);
            var message = messageFor(fields[i]);
            render(fields[i], message);
            if (message !== '' && firstInvalid === null) {
                firstInvalid = fields[i];
            }
        }
        if (firstInvalid !== null) {
            event.preventDefault();
            event.stopPropagation();
            firstInvalid.focus();
        }
    }

    /** Champs saisissables et visibles du formulaire, porteurs d'au moins une regle. */
    function fieldsOf(form) {
        var elements = form.querySelectorAll('input, select, textarea');
        var fields = [];
        for (var i = 0; i < elements.length; i++) {
            if (isWatched(elements[i], form)) {
                fields.push(elements[i]);
            }
        }
        return fields;
    }

    function isWatched(field, form) {
        if (!field || field.form !== form || !/^(INPUT|SELECT|TEXTAREA)$/.test(field.tagName)) {
            return false;
        }
        if (field.disabled || SKIPPED_TYPES.indexOf(field.type) !== -1 || field.closest('[hidden]')) {
            return false;
        }
        return hasRule(field);
    }

    function hasRule(field) {
        return field.required || field.type === 'email' || field.type === 'number'
            || RULE_ATTRIBUTES.some(function (attribute) {
                return field.hasAttribute(attribute);
            });
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init, messageFor: messageFor };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
