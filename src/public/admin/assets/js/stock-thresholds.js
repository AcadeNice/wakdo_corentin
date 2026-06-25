/**
 * stock-thresholds.js — Reglage rapide des seuils de stock depuis le tableau de bord (F13).
 *
 * Chaque carte/ligne ingredient porte un bouton "Regler les seuils" (data-threshold-open)
 * decore de ses valeurs courantes (data-id, data-name, data-capacity, data-low,
 * data-critical). Au clic, on pre-remplit l'unique modale rendue serveur (un VRAI form POST
 * avec CSRF, pas de fetch), on pointe son action sur /admin/ingredients/{id}/thresholds, et
 * on l'ouvre. La validation finale reste cote serveur (validateThresholds) ; on ajoute ici
 * un garde-fou client leger (capacite >= 1, % 0-100, critique < alerte strict) pour eviter
 * un aller-retour evident. Sans JS, la modale reste cachee et le reste de la page marche.
 *
 * CSP 'self' : script externe, aucun handler inline. Style CommonJS testable + browser-safe.
 */
(function () {
    'use strict';

    function init(doc) {
        var overlay = doc.querySelector('[data-threshold-modal]');
        if (!overlay) {
            return; // role sans stock.manage : la modale n'est pas rendue.
        }

        var form = overlay.querySelector('[data-threshold-form]');
        var inCapacity = doc.getElementById('th-capacity');
        var inLow = doc.getElementById('th-low');
        var inCritical = doc.getElementById('th-critical');
        var nameLabel = overlay.querySelector('[data-threshold-name]');
        var errorBox = overlay.querySelector('[data-threshold-error]');
        if (!form || !inCapacity || !inLow || !inCritical) {
            return;
        }

        var openers = doc.querySelectorAll('[data-threshold-open]');
        for (var i = 0; i < openers.length; i++) {
            openers[i].addEventListener('click', function (e) {
                openModal(e.currentTarget);
            });
        }

        overlay.querySelector('[data-threshold-cancel]').addEventListener('click', closeModal);
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

        // Garde-fou client : on ne bloque que les cas evidents (le serveur reste l'autorite).
        form.addEventListener('submit', function (e) {
            var error = validate(inCapacity.value, inLow.value, inCritical.value);
            if (error !== null) {
                e.preventDefault();
                if (errorBox) {
                    errorBox.textContent = error;
                    errorBox.hidden = false;
                }
            }
        });

        function openModal(button) {
            var id = button.getAttribute('data-id') || '';
            form.setAttribute('action', '/admin/ingredients/' + id + '/thresholds');
            if (nameLabel) {
                var name = button.getAttribute('data-name') || '';
                nameLabel.textContent = name === '' ? '' : 'Ingredient : ' + name;
            }
            inCapacity.value = button.getAttribute('data-capacity') || '';
            inLow.value = button.getAttribute('data-low') || '';
            inCritical.value = button.getAttribute('data-critical') || '';
            if (errorBox) {
                errorBox.hidden = true;
            }
            overlay.classList.add('open');
            inCapacity.focus();
        }

        function closeModal() {
            overlay.classList.remove('open');
        }
    }

    /**
     * Validation cliente legere, miroir de validateThresholds() cote serveur :
     * capacite entiere >= 1 ; seuils entiers 0-100 ; critique STRICTEMENT < alerte.
     * Renvoie un message d'erreur (string) ou null si tout est coherent.
     */
    function validate(capacityRaw, lowRaw, criticalRaw) {
        var capacity = toInt(capacityRaw);
        var low = toInt(lowRaw);
        var critical = toInt(criticalRaw);

        if (capacity === null || capacity < 1) {
            return 'La capacite (reference 100%) doit etre un entier superieur ou egal a 1.';
        }
        if (low === null || low < 0 || low > 100) {
            return 'Le seuil d alerte doit etre un entier entre 0 et 100.';
        }
        if (critical === null || critical < 0 || critical > 100) {
            return 'Le seuil critique doit etre un entier entre 0 et 100.';
        }
        if (critical >= low) {
            return 'Le seuil critique doit etre strictement inferieur au seuil d alerte.';
        }

        return null;
    }

    /** Entier strict (suite de chiffres) ou null : refuse "", " 5", "5.0", "abc". */
    function toInt(raw) {
        var value = String(raw === undefined || raw === null ? '' : raw).trim();
        if (!/^[0-9]+$/.test(value)) {
            return null;
        }

        return parseInt(value, 10);
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init, validate: validate };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
