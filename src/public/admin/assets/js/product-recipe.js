/*
 * product-recipe.js — Builder de composition (recette) du formulaire produit.
 *
 * CSP 'self' : script externe (pas d'inline). Les donnees (catalogue d'ingredients,
 * composition initiale) sont lues depuis les attributs data-* de #recipe-builder.
 * A la soumission, l'etat est serialise en JSON dans le champ cache #composition_json
 * (Request::formBody cote serveur ne garde que les scalaires). Le serveur revalide
 * tout (RG-T18) : bornes, existence de l'ingredient, dedup par PK composite.
 *
 * Une composition VIDE est valide (un produit peut n'avoir aucune recette definie).
 */
(function () {
    'use strict';

    var builder = document.getElementById('recipe-builder');
    var form = document.getElementById('recipe-form');
    var hidden = document.getElementById('composition_json');
    var addBtn = document.getElementById('add-ingredient');
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

    var ingredients = parseData('ingredients', '[]'); // [{id, name, unit}]
    var initial = parseData('composition', '[]');     // [{ingredient_id, quantity_normal, ...}]

    function el(tag, className) {
        var e = document.createElement(tag);
        if (className) {
            e.className = className;
        }
        return e;
    }

    function numberInput(className, value, min) {
        var input = el('input', 'form-input ' + className);
        input.type = 'number';
        input.min = String(min);
        input.value = String(value);
        input.style.width = '7rem';
        return input;
    }

    // Construit le bloc DOM d'une ligne de composition. `line` peut etre vide (ajout).
    function renderLine(line) {
        line = line || {};

        var block = el('fieldset', 'recipe-line form-group');
        block.style.border = '1px solid #ddd';
        block.style.padding = '0.75rem';
        block.style.marginBottom = '0.75rem';

        // Ingredient (picker)
        var ingLabel = el('label');
        ingLabel.appendChild(document.createTextNode('Ingredient '));
        var ingSelect = el('select', 'form-input recipe-ingredient');
        ingredients.forEach(function (i) {
            var opt = el('option');
            opt.value = String(i.id);
            opt.textContent = String(i.name) + (i.unit ? ' (' + String(i.unit) + ')' : '');
            if (Number(line.ingredient_id) === Number(i.id)) {
                opt.selected = true;
            }
            ingSelect.appendChild(opt);
        });
        ingLabel.appendChild(ingSelect);
        block.appendChild(ingLabel);

        // Quantites
        var qnLabel = el('label');
        qnLabel.appendChild(document.createTextNode(' Qte normale '));
        qnLabel.appendChild(numberInput('recipe-qn', line.quantity_normal != null ? line.quantity_normal : 1, 1));
        block.appendChild(qnLabel);

        var qmLabel = el('label');
        qmLabel.appendChild(document.createTextNode(' Qte maxi '));
        qmLabel.appendChild(numberInput('recipe-qm', line.quantity_maxi != null ? line.quantity_maxi : 1, 1));
        block.appendChild(qmLabel);

        // Supplement (centimes)
        var extraLabel = el('label');
        extraLabel.appendChild(document.createTextNode(' Supplement (cts) '));
        extraLabel.appendChild(numberInput('recipe-extra', line.extra_price_cents != null ? line.extra_price_cents : 0, 0));
        block.appendChild(extraLabel);

        // Retirable / Ajoutable
        var remLabel = el('label');
        var remInput = el('input', 'recipe-removable');
        remInput.type = 'checkbox';
        if (Number(line.is_removable) === 1) {
            remInput.checked = true;
        }
        remLabel.appendChild(remInput);
        remLabel.appendChild(document.createTextNode(' Retirable'));
        block.appendChild(remLabel);

        var addLabel = el('label');
        var addInput = el('input', 'recipe-addable');
        addInput.type = 'checkbox';
        if (Number(line.is_addable) === 1) {
            addInput.checked = true;
        }
        addLabel.appendChild(addInput);
        addLabel.appendChild(document.createTextNode(' Ajoutable'));
        block.appendChild(addLabel);

        // Retirer la ligne
        var removeBtn = el('button', 'btn btn-secondary recipe-remove');
        removeBtn.type = 'button';
        removeBtn.textContent = 'Retirer';
        removeBtn.addEventListener('click', function () {
            block.parentNode.removeChild(block);
        });
        block.appendChild(removeBtn);

        return block;
    }

    // Lit l'etat des lignes et le serialise dans #composition_json.
    function serialize() {
        var lines = [];
        var blocks = builder.querySelectorAll('.recipe-line');
        Array.prototype.forEach.call(blocks, function (block) {
            var ingredientId = Number(block.querySelector('.recipe-ingredient').value);
            if (!ingredientId) {
                return;
            }
            lines.push({
                ingredient_id: ingredientId,
                quantity_normal: Number(block.querySelector('.recipe-qn').value),
                quantity_maxi: Number(block.querySelector('.recipe-qm').value),
                extra_price_cents: Number(block.querySelector('.recipe-extra').value),
                is_removable: block.querySelector('.recipe-removable').checked ? 1 : 0,
                is_addable: block.querySelector('.recipe-addable').checked ? 1 : 0
            });
        });
        hidden.value = JSON.stringify(lines);
    }

    addBtn.addEventListener('click', function () {
        if (!ingredients.length) {
            return; // aucun ingredient au catalogue : rien a composer
        }
        builder.appendChild(renderLine(null));
    });

    form.addEventListener('submit', function () {
        serialize();
    });

    // Rendu initial : lignes existantes (edition). Composition vide -> aucune ligne
    // (l'utilisateur ajoute a la demande, ou enregistre une recette vide).
    initial.forEach(function (l) {
        builder.appendChild(renderLine(l));
    });
})();
