/*
 * menu-form.js — Builder de slots du formulaire menu (back-office).
 *
 * CSP 'self' : script externe (pas d'inline). Les donnees (produits, types,
 * slots initiaux) sont lues depuis les attributs data-* de #slot-builder.
 * A la soumission, l'etat des slots est serialise en JSON dans le champ cache
 * #slots_json (Request::formBody cote serveur ne garde que les scalaires, d'ou
 * le passage par une chaine JSON). Le serveur revalide tout (RG-T18).
 */
(function () {
    'use strict';

    var builder = document.getElementById('slot-builder');
    var form = document.getElementById('menu-form');
    var hidden = document.getElementById('slots_json');
    var addBtn = document.getElementById('add-slot');
    if (!builder || !form || !hidden || !addBtn) {
        return;
    }

    function parseData(key, fallback) {
        try {
            var v = JSON.parse(builder.dataset[key] || fallback);
            return Array.isArray(v) ? v : JSON.parse(fallback);
        } catch (e) {
            return JSON.parse(fallback);
        }
    }

    var products = parseData('products', '[]');   // [{id, name}]
    var slotTypes = parseData('slotTypes', '[]');  // ['drink', 'side', ...]
    var initialSlots = parseData('slots', '[]');   // [{name, slot_type, is_required, options:[id]}]

    function el(tag, className) {
        var e = document.createElement(tag);
        if (className) {
            e.className = className;
        }
        return e;
    }

    // Construit le bloc DOM d'un slot. `slot` peut etre vide (creation).
    function renderSlot(slot) {
        slot = slot || {};
        var selectedOptions = Array.isArray(slot.options) ? slot.options.map(Number) : [];

        var block = el('fieldset', 'slot-block form-group');
        block.style.border = '1px solid #ddd';
        block.style.padding = '0.75rem';
        block.style.marginBottom = '0.75rem';

        var head = el('div');

        // Nom du slot
        var nameLabel = el('label');
        nameLabel.appendChild(document.createTextNode('Nom du slot '));
        var nameInput = el('input', 'form-input slot-name');
        nameInput.type = 'text';
        nameInput.maxLength = 80;
        nameInput.value = slot.name ? String(slot.name) : '';
        nameLabel.appendChild(nameInput);
        head.appendChild(nameLabel);

        // Type
        var typeLabel = el('label');
        typeLabel.appendChild(document.createTextNode(' Type '));
        var typeSelect = el('select', 'form-input slot-type');
        slotTypes.forEach(function (t) {
            var opt = el('option');
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
        var reqLabel = el('label');
        var reqInput = el('input', 'slot-required');
        reqInput.type = 'checkbox';
        if (Number(slot.is_required) === 1) {
            reqInput.checked = true;
        }
        reqLabel.appendChild(reqInput);
        reqLabel.appendChild(document.createTextNode(' Requis'));
        head.appendChild(reqLabel);

        // Retirer
        var removeBtn = el('button', 'btn btn-secondary slot-remove');
        removeBtn.type = 'button';
        removeBtn.textContent = 'Retirer';
        removeBtn.addEventListener('click', function () {
            block.parentNode.removeChild(block);
        });
        head.appendChild(removeBtn);

        block.appendChild(head);

        // Options : cases a cocher des produits eligibles
        var optWrap = el('div', 'slot-options');
        optWrap.style.maxHeight = '160px';
        optWrap.style.overflowY = 'auto';
        optWrap.style.marginTop = '0.5rem';
        products.forEach(function (p) {
            var lab = el('label');
            lab.style.display = 'block';
            var cb = el('input', 'slot-option');
            cb.type = 'checkbox';
            cb.value = String(p.id);
            if (selectedOptions.indexOf(Number(p.id)) !== -1) {
                cb.checked = true;
            }
            lab.appendChild(cb);
            lab.appendChild(document.createTextNode(' ' + String(p.name)));
            optWrap.appendChild(lab);
        });
        block.appendChild(optWrap);

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
})();
