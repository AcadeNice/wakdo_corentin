/**
 * stock-thresholds.js — Reglage rapide des seuils de stock depuis le tableau de bord (F13),
 * ET repere visuel "ligne modifiee" (design-system.md §2.6, lot 3). Deux responsabilites
 * SANS lien thematique, regroupees dans un seul fichier par contrainte de perimetre : ce
 * lot n'a le droit d'ecrire que dans ce fichier JS (pas de nouveau fichier, pas de
 * <script> supplementaire dans layout.php). Ce module est charge globalement (toutes les
 * pages admin, layout.php) ; chaque fonction se desactive elle-meme si son marqueur DOM est
 * absent, donc le cout sur une page qui n'utilise ni l'un ni l'autre est nul. A separer en
 * deux fichiers si un lot futur a le perimetre pour le faire.
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
            // Une seule fenetre sert tous les ingredients : reset() efface aussi les
            // messages du controle de saisie laisses par l'ingredient precedent
            // (form-validation.js ecoute l'evenement reset).
            form.reset();
            var id = button.getAttribute('data-id') || '';
            form.setAttribute('action', '/admin/ingredients/' + id + '/thresholds');
            // Repere la ligne pour initRowHighlight() : un reglage de seuils reussi
            // redirige vers /admin/ingredients, ou cette cle sert a retrouver la ligne.
            form.setAttribute('data-row-key', 'ingredient:' + id);
            if (nameLabel) {
                var name = button.getAttribute('data-name') || '';
                nameLabel.textContent = name === '' ? '' : 'Ingrédient : ' + name;
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
            return 'La capacité (référence 100%) doit être un entier supérieur ou égal à 1.';
        }
        if (low === null || low < 0 || low > 100) {
            return 'Le seuil d\'alerte doit être un entier entre 0 et 100.';
        }
        if (critical === null || critical < 0 || critical > 100) {
            return 'Le seuil critique doit être un entier entre 0 et 100.';
        }
        if (critical >= low) {
            return 'Le seuil critique doit être strictement inférieur au seuil d\'alerte.';
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

    /**
     * Repere visuel "ligne modifiee" (plan.md §2.6 / design-system.md §2.6). Mecanisme
     * generique, sans rapport avec les seuils : n'importe quelle ligne de liste (produit,
     * ingredient) porte un attribut `data-row-key="<portee>:<id>"` (ex. "ingredient:42").
     * Quand un formulaire situe dans cette ligne (ou portant lui-meme la cle, cas des
     * pages de confirmation restock/inventaire/ajustement/suppression) est soumis, sa cle
     * est deposee en sessionStorage juste avant la navigation. Au chargement suivant de
     * n'importe quelle page admin, la cle est lue puis EFFACEE (a usage unique) et la ou
     * les lignes correspondantes recoivent .row-highlight (CSS : voir le <style> en tete
     * de ingredients/index.php et products/index.php - anime, s'efface seule, pas
     * uniquement une couleur : cf. commentaire CSS pour le detail RGAA).
     *
     * Aucun controleur ne fournit cet identifiant par redirection (hors perimetre de ce
     * lot, qui ne touche que des vues) : ce mecanisme cote-client est donc silencieux et
     * sans effet si la ligne cible a disparu (suppression reussie) ou si sessionStorage
     * est indisponible (navigation privee) - jamais bloquant.
     */
    function initRowHighlight(doc) {
        var win = doc.defaultView;
        if (!win) {
            return;
        }

        // Pose de la cle : delegation au niveau document (l'evenement submit remonte),
        // couvre tout formulaire present ou futur sans cablage page par page.
        doc.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || typeof form.closest !== 'function') {
                return;
            }
            var carrier = form.hasAttribute('data-row-key') ? form : form.closest('[data-row-key]');
            if (!carrier) {
                return;
            }
            try {
                win.sessionStorage.setItem('wakdo-row-highlight', carrier.getAttribute('data-row-key') || '');
            } catch (storageError) {
                // Navigation privee ou quota : le reste du formulaire fonctionne normalement.
            }
        });

        applyPendingHighlight(doc, win);
    }

    /** Cle valide : "<lettres/chiffres/tirets>:<entier>", ex. "ingredient:42". Rejette tout le reste. */
    var ROW_KEY_PATTERN = /^[a-z-]+:[0-9]+$/;

    function applyPendingHighlight(doc, win) {
        var key;
        try {
            key = win.sessionStorage.getItem('wakdo-row-highlight');
            if (key) {
                win.sessionStorage.removeItem('wakdo-row-highlight'); // usage unique
            }
        } catch (storageError) {
            return;
        }
        if (!key || !ROW_KEY_PATTERN.test(key)) {
            return;
        }

        var rows = doc.querySelectorAll('[data-row-key="' + key + '"]');
        for (var i = 0; i < rows.length; i++) {
            rows[i].classList.add('row-highlight');
        }
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init, validate: validate, initRowHighlight: initRowHighlight };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
            initRowHighlight(document);
        });
    }
})();
