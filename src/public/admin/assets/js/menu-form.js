/*
 * menu-form.js — Builder de slots du formulaire menu (back-office).
 *
 * CSP 'self' : script externe (pas d'inline). Les donnees (produits, types,
 * mapping slot_type -> categories, slots initiaux) sont lues depuis les attributs
 * data-* de #slot-builder. A la soumission, l'etat des slots est serialise en JSON
 * dans le champ cache #slots_json (Request::formBody cote serveur ne garde que les
 * scalaires, d'ou le passage par une chaine JSON). Le serveur revalide tout (RG-T18).
 *
 * F12 : les options proposees dans un slot sont FILTREES par le type de slot. Chaque
 * produit porte sa categorie (data-products[].category) ; le mapping slot_type ->
 * categories (data-slot-categories) decide quelles categories sont eligibles. Le type
 * etant un <select> modifiable, la liste d'options est RE-RENDUE a chaque changement
 * de type. Le mapping vient de MenuController::SLOT_CATEGORIES (source unique, aussi
 * appliquee par la garde serveur parseSlots).
 *
 * Module CommonJS (admin = racine CommonJS, comme pin-modal.js / counter-order.js) :
 * init(doc) est exporte pour les tests et auto-appele au DOMContentLoaded en prod.
 */
(function () {
    'use strict';

    function el(doc, tag, className) {
        var e = doc.createElement(tag);
        if (className) {
            e.className = className;
        }
        return e;
    }

    // Lit un attribut data-* JSON et retourne un Array, sinon le fallback (forme tableau).
    function parseArray(builder, key, fallback) {
        try {
            var v = JSON.parse(builder.dataset[key] || fallback);
            return Array.isArray(v) ? v : JSON.parse(fallback);
        } catch (e) {
            return JSON.parse(fallback);
        }
    }

    // Lit un attribut data-* JSON et retourne un objet simple (le mapping slot_type ->
    // categories), sinon {} ; tolerant aux entrees non-objet / mal formees.
    function parseObject(builder, key) {
        try {
            var v = JSON.parse(builder.dataset[key] || '{}');
            return (v && typeof v === 'object' && !Array.isArray(v)) ? v : {};
        } catch (e) {
            return {};
        }
    }

    // Categories eligibles pour un slot_type donne (liste, vide si type inconnu).
    function allowedCategories(slotCategories, slotType) {
        var list = slotCategories[slotType];
        return Array.isArray(list) ? list : [];
    }

    // Un produit est-il proposable dans un slot de ce type ? (sa categorie figure dans
    // la liste autorisee). Source unique de la decision UI, miroir de la garde serveur.
    function productAllowed(product, slotCategories, slotType) {
        return allowedCategories(slotCategories, slotType).indexOf(String(product.category)) !== -1;
    }

    function init(doc) {
        var builder = doc.getElementById('slot-builder');
        var form = doc.getElementById('menu-form');
        var hidden = doc.getElementById('slots_json');
        var addBtn = doc.getElementById('add-slot');
        if (!builder || !form || !hidden || !addBtn) {
            return;
        }

        var products = parseArray(builder, 'products', '[]');        // [{id, name, category}]
        var slotTypes = parseArray(builder, 'slotTypes', '[]');      // ['drink', 'side', ...]
        var slotCategories = parseObject(builder, 'slotCategories'); // {drink:['boissons'], ...}
        var initialSlots = parseArray(builder, 'slots', '[]');       // [{name, slot_type, is_required, options:[id]}]

        // (Re)construit la liste des cases a cocher d'options pour un type de slot donne.
        // N'affiche QUE les produits dont la categorie est eligible (F12). Les ids deja
        // coches qui restent eligibles sont conserves coches ; un id devenu non eligible
        // (changement de type) DISPARAIT simplement de la liste : c'est le comportement
        // le plus previsible pour un equipier non technicien (une option absente ne peut
        // pas etre soumise par megarde), et la garde serveur rejetterait de toute facon
        // une option hors categorie. selectedSet : set d'ids coches a preserver.
        function renderOptions(optWrap, slotType, selectedSet) {
            optWrap.textContent = '';
            var shown = 0;
            products.forEach(function (p) {
                if (!productAllowed(p, slotCategories, slotType)) {
                    return;
                }
                shown += 1;
                var lab = el(doc, 'label');
                lab.style.display = 'block';
                var cb = el(doc, 'input', 'slot-option');
                cb.type = 'checkbox';
                cb.value = String(p.id);
                if (selectedSet[String(p.id)]) {
                    cb.checked = true;
                }
                lab.appendChild(cb);
                lab.appendChild(doc.createTextNode(' ' + String(p.name)));
                optWrap.appendChild(lab);
            });
            // Repere visible quand aucun produit n'est eligible (ex. type sans catalogue) :
            // evite une zone vide muette pour l'equipier.
            if (shown === 0) {
                var empty = el(doc, 'p');
                empty.className = 'slot-options-empty';
                empty.textContent = 'Aucun produit disponible pour ce type de slot.';
                optWrap.appendChild(empty);
            }
        }

        // Construit le bloc DOM d'un slot. `slot` peut etre vide (creation).
        function renderSlot(slot) {
            slot = slot || {};
            var selectedSet = {};
            (Array.isArray(slot.options) ? slot.options : []).forEach(function (id) {
                selectedSet[String(Number(id))] = true;
            });

            var block = el(doc, 'fieldset', 'slot-block form-group');
            block.style.border = '1px solid #ddd';
            block.style.padding = '0.75rem';
            block.style.marginBottom = '0.75rem';

            var head = el(doc, 'div');

            // Nom du slot
            var nameLabel = el(doc, 'label');
            nameLabel.appendChild(doc.createTextNode('Nom du slot '));
            var nameInput = el(doc, 'input', 'form-input slot-name');
            nameInput.type = 'text';
            nameInput.maxLength = 80;
            nameInput.value = slot.name ? String(slot.name) : '';
            nameLabel.appendChild(nameInput);
            head.appendChild(nameLabel);

            // Type
            var typeLabel = el(doc, 'label');
            typeLabel.appendChild(doc.createTextNode(' Type '));
            var typeSelect = el(doc, 'select', 'form-input slot-type');
            slotTypes.forEach(function (t) {
                var opt = el(doc, 'option');
                opt.value = String(t);
                opt.textContent = String(t);
                if (String(slot.slot_type) === String(t)) {
                    opt.selected = true;
                }
                typeSelect.appendChild(opt);
            });
            typeLabel.appendChild(typeSelect);
            head.appendChild(typeLabel);

            // Requis
            var reqLabel = el(doc, 'label');
            var reqInput = el(doc, 'input', 'slot-required');
            reqInput.type = 'checkbox';
            if (Number(slot.is_required) === 1) {
                reqInput.checked = true;
            }
            reqLabel.appendChild(reqInput);
            reqLabel.appendChild(doc.createTextNode(' Requis'));
            head.appendChild(reqLabel);

            // Retirer
            var removeBtn = el(doc, 'button', 'btn btn-secondary slot-remove');
            removeBtn.type = 'button';
            removeBtn.textContent = 'Retirer';
            removeBtn.addEventListener('click', function () {
                block.parentNode.removeChild(block);
            });
            head.appendChild(removeBtn);

            block.appendChild(head);

            // Options : cases a cocher des produits eligibles AU TYPE COURANT (F12).
            var optWrap = el(doc, 'div', 'slot-options');
            optWrap.style.maxHeight = '160px';
            optWrap.style.overflowY = 'auto';
            optWrap.style.marginTop = '0.5rem';
            // Type initial : la valeur du slot (edition) ou le 1er type (creation), pour
            // matcher l'option selectionnee par defaut dans le <select> ci-dessus.
            var currentType = String(typeSelect.value || (slotTypes.length ? slotTypes[0] : ''));
            renderOptions(optWrap, currentType, selectedSet);
            block.appendChild(optWrap);

            // Re-filtrage dynamique : changer le type re-rend les options eligibles. On
            // repart des cases actuellement cochees (preservees si encore eligibles), pas
            // de la selection initiale : l'equipier ne reperd pas un choix encore valide.
            typeSelect.addEventListener('change', function () {
                var keep = {};
                Array.prototype.forEach.call(optWrap.querySelectorAll('.slot-option'), function (cb) {
                    if (cb.checked) {
                        keep[String(cb.value)] = true;
                    }
                });
                renderOptions(optWrap, String(typeSelect.value), keep);
            });

            return block;
        }

        // Lit l'etat des blocs et le serialise dans #slots_json.
        function serialize() {
            var slots = [];
            var blocks = builder.querySelectorAll('.slot-block');
            Array.prototype.forEach.call(blocks, function (block) {
                var name = block.querySelector('.slot-name').value.trim();
                var type = block.querySelector('.slot-type').value;
                var required = block.querySelector('.slot-required').checked ? 1 : 0;
                var options = [];
                Array.prototype.forEach.call(block.querySelectorAll('.slot-option'), function (cb) {
                    if (cb.checked) {
                        options.push(Number(cb.value));
                    }
                });
                slots.push({ name: name, slot_type: type, is_required: required, options: options });
            });
            hidden.value = JSON.stringify(slots);
        }

        addBtn.addEventListener('click', function () {
            builder.appendChild(renderSlot(null));
        });

        form.addEventListener('submit', function () {
            serialize();
        });

        // Rendu initial : slots existants (edition) ou un slot vide (creation).
        if (initialSlots.length) {
            initialSlots.forEach(function (s) {
                builder.appendChild(renderSlot(s));
            });
        } else {
            builder.appendChild(renderSlot(null));
        }
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init, productAllowed: productAllowed, allowedCategories: allowedCategories };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
