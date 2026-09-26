/*
 * product-recipe.js — Builder de composition (recette), partage par la page
 * recette dediee (admin/products/recipe.php) ET la section "Composition" du
 * formulaire produit (admin/products/form.php) : meme builder, meme contrat de
 * donnees, aucune divergence entre les deux ecrans.
 *
 * CSP 'self' : script externe (pas d'inline). Les donnees (catalogue
 * d'ingredients, composition initiale, permission de creer un ingredient) sont
 * lues depuis les attributs data-* de #recipe-builder. A la soumission, l'etat
 * est serialise en JSON dans le champ cache #composition_json (Request::formBody
 * cote serveur ne garde que les scalaires). Le serveur revalide tout (RG-T18) :
 * bornes, existence/nom de l'ingredient, dedup par PK composite.
 *
 * Une composition VIDE est valide (un produit peut n'avoir aucune recette
 * definie). Une ligne "nouvel ingredient" (new_ingredient: {name, unit,
 * pack_size, pack_label, stock_capacity}) cree l'ingredient A LA VOLEE cote
 * serveur (stock 0, seuils par defaut du projet) ; le bouton qui l'ajoute
 * n'apparait que si data-can-create-ingredient="1" (permission ingredient.manage),
 * et le serveur revalide cette permission de toute facon (defense en profondeur).
 *
 * Module CommonJS (admin = racine CommonJS, comme menu-form.js/pin-modal.js) :
 * init(doc) est exporte pour les tests (node --test + jsdom) et auto-appele au
 * DOMContentLoaded en production.
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

    function numberInput(doc, className, value, min) {
        var input = el(doc, 'input', 'form-input ' + className);
        input.type = 'number';
        input.min = String(min);
        input.value = String(value);
        return input;
    }

    function textInput(doc, className, value, placeholder) {
        var input = el(doc, 'input', 'form-input ' + className);
        input.type = 'text';
        input.value = value || '';
        if (placeholder) {
            input.placeholder = placeholder;
        }
        return input;
    }

    /**
     * Un champ = <label class="recipe-field"> qui ENTOURE son controle (association
     * implicite : plusieurs lignes coexistent sur la page, un for/id demanderait un
     * compteur d'identifiants uniques cote script pour le meme resultat). `variant`
     * donne la largeur au systeme de design : num (7rem), text (12rem), wide (extensible).
     */
    function field(doc, labelText, variant, control) {
        var label = el(doc, 'label', 'recipe-field recipe-field--' + variant);
        var caption = el(doc, 'span', 'recipe-field__label');
        caption.textContent = labelText;
        label.appendChild(caption);
        label.appendChild(control);
        return label;
    }

    /** Case a cocher + son intitule, cible portee a 24x24px par .recipe-check (CSS). */
    function checkField(doc, labelText, className, checked) {
        var label = el(doc, 'label', 'recipe-check');
        var input = el(doc, 'input', className);
        input.type = 'checkbox';
        if (checked) {
            input.checked = true;
        }
        var caption = el(doc, 'span');
        caption.textContent = labelText;
        label.appendChild(input);
        label.appendChild(caption);
        return label;
    }

    // Champs communs a toute ligne (quantites, supplement, retirable/ajoutable),
    // partages entre une ligne "ingredient existant" et une ligne "nouvel
    // ingredient" : la recette elle-meme (combien, retirable, ajoutable) ne
    // depend pas de l'origine de l'ingredient.
    function appendCommonFields(doc, block, fields, line) {
        fields.appendChild(field(doc, 'Qté normale', 'num',
            numberInput(doc, 'recipe-qn', line.quantity_normal != null ? line.quantity_normal : 1, 1)));
        fields.appendChild(field(doc, 'Qté maxi', 'num',
            numberInput(doc, 'recipe-qm', line.quantity_maxi != null ? line.quantity_maxi : 1, 1)));
        // "Supplément" en euros a l'ecran (l'equipier ne compte pas en centimes) ;
        // la conversion vers l'entier attendu par le serveur se fait a la
        // serialisation, comme le prix du formulaire produit.
        fields.appendChild(field(doc, 'Supplément (€)', 'num',
            euroInput(doc, 'recipe-extra', line.extra_price_cents != null ? line.extra_price_cents : 0)));

        fields.appendChild(checkField(doc, 'Retirable', 'recipe-removable', Number(line.is_removable) === 1));
        fields.appendChild(checkField(doc, 'Ajoutable', 'recipe-addable', Number(line.is_addable) === 1));

        var removeBtn = el(doc, 'button', 'btn btn-secondary recipe-remove');
        removeBtn.type = 'button';
        removeBtn.textContent = 'Retirer';
        removeBtn.addEventListener('click', function () {
            block.parentNode.removeChild(block);
        });
        fields.appendChild(removeBtn);
    }

    /**
     * Montant en euros, saisi avec virgule ou point. Le serveur attend des
     * centimes : centsToEuros a l'affichage, eurosToCents a la serialisation.
     */
    function euroInput(doc, className, cents) {
        var input = el(doc, 'input', 'form-input ' + className);
        input.type = 'text';
        input.inputMode = 'decimal';
        input.value = centsToEuros(cents);
        return input;
    }

    function centsToEuros(cents) {
        var n = Number(cents);
        if (!isFinite(n) || n <= 0) {
            return '0';
        }
        return String(Math.round(n) / 100).replace('.', ',');
    }

    function eurosToCents(raw) {
        var n = Number(String(raw == null ? '' : raw).trim().replace(',', '.'));
        if (!isFinite(n) || n < 0) {
            return 0;
        }
        return Math.round(n * 100);
    }

    // Ligne "ingredient existant" : picker + quantites. `line` peut etre vide (ajout).
    function renderExistingLine(doc, ingredients, line) {
        line = line || {};

        var block = el(doc, 'fieldset', 'recipe-line recipe-line-existing form-group');

        var legend = el(doc, 'legend');
        legend.textContent = 'Ingrédient du catalogue';
        block.appendChild(legend);

        var fields = el(doc, 'div', 'recipe-line__fields');
        block.appendChild(fields);

        var ingSelect = el(doc, 'select', 'form-input recipe-ingredient');
        ingredients.forEach(function (i) {
            var opt = el(doc, 'option');
            opt.value = String(i.id);
            opt.textContent = String(i.name) + (i.unit ? ' (' + String(i.unit) + ')' : '');
            if (Number(line.ingredient_id) === Number(i.id)) {
                opt.selected = true;
            }
            ingSelect.appendChild(opt);
        });
        fields.appendChild(field(doc, 'Ingrédient', 'wide', ingSelect));

        appendCommonFields(doc, block, fields, line);

        return block;
    }

    // Ligne "nouvel ingredient" : nom + unite (requis), conditionnement +
    // capacite (optionnels, defauts poses cote serveur). Cree l'ingredient a la
    // volee (stock 0) a l'enregistrement du produit.
    function renderNewIngredientLine(doc) {
        var block = el(doc, 'fieldset', 'recipe-line recipe-line-new form-group');

        var legend = el(doc, 'legend');
        legend.textContent = 'Nouvel ingrédient (créé à stock 0)';
        block.appendChild(legend);

        var fields = el(doc, 'div', 'recipe-line__fields');
        block.appendChild(fields);

        fields.appendChild(field(doc, 'Nom', 'text', textInput(doc, 'recipe-new-name', '', 'ex. Sauce maison')));
        fields.appendChild(field(doc, 'Unité', 'num', textInput(doc, 'recipe-new-unit', '', 'ex. g, unité, cl')));
        fields.appendChild(field(doc, 'Taille du conditionnement (optionnel)', 'num', numberInput(doc, 'recipe-new-pack', '', 1)));
        fields.appendChild(field(doc, 'Capacité (optionnel, 100 par défaut)', 'num', numberInput(doc, 'recipe-new-capacity', '', 1)));
        fields.appendChild(field(doc, 'Nom du conditionnement (optionnel)', 'text', textInput(doc, 'recipe-new-packlabel', '', 'ex. sachet 1kg')));

        var note = el(doc, 'p', 'recipe-note');
        note.textContent = 'Cet ingrédient sera créé dans Stock avec 0 en stock : pensez à le réapprovisionner après enregistrement. Sa liste d\'allergènes n\'est pas encore revue.';
        fields.appendChild(note);

        appendCommonFields(doc, block, fields, {});

        return block;
    }

    function parseData(builder, key, fallback) {
        try {
            var v = JSON.parse(builder.dataset[key] || fallback);
            return Array.isArray(v) ? v : JSON.parse(fallback);
        } catch (e) {
            return JSON.parse(fallback);
        }
    }

    // Lit l'etat des lignes et le serialise dans #composition_json.
    function serialize(builder, hidden) {
        var lines = [];
        var blocks = builder.querySelectorAll('.recipe-line');
        Array.prototype.forEach.call(blocks, function (block) {
            var qn = Number(block.querySelector('.recipe-qn').value);
            var qm = Number(block.querySelector('.recipe-qm').value);
            var extra = eurosToCents(block.querySelector('.recipe-extra').value);
            var removable = block.querySelector('.recipe-removable').checked ? 1 : 0;
            var addable = block.querySelector('.recipe-addable').checked ? 1 : 0;

            if (block.classList.contains('recipe-line-new')) {
                var name = block.querySelector('.recipe-new-name').value.trim();
                if (!name) {
                    return; // ligne laissee vide : ignoree (pas d'ingredient sans nom)
                }
                var packRaw = block.querySelector('.recipe-new-pack').value;
                var capRaw = block.querySelector('.recipe-new-capacity').value;
                lines.push({
                    new_ingredient: {
                        name: name,
                        unit: block.querySelector('.recipe-new-unit').value.trim(),
                        pack_size: packRaw ? Number(packRaw) : null,
                        stock_capacity: capRaw ? Number(capRaw) : null,
                        pack_label: block.querySelector('.recipe-new-packlabel').value.trim()
                    },
                    quantity_normal: qn,
                    quantity_maxi: qm,
                    extra_price_cents: extra,
                    is_removable: removable,
                    is_addable: addable
                });
                return;
            }

            var ingredientId = Number(block.querySelector('.recipe-ingredient').value);
            if (!ingredientId) {
                return;
            }
            lines.push({
                ingredient_id: ingredientId,
                quantity_normal: qn,
                quantity_maxi: qm,
                extra_price_cents: extra,
                is_removable: removable,
                is_addable: addable
            });
        });
        hidden.value = JSON.stringify(lines);

        return lines;
    }

    function init(doc) {
        var builder = doc.getElementById('recipe-builder');
        var hidden = doc.getElementById('composition_json');
        var addBtn = doc.getElementById('add-ingredient');
        var addNewBtn = doc.getElementById('add-new-ingredient');
        if (!builder || !hidden || !addBtn) {
            return;
        }
        // closest('form') plutot qu'un id fixe : marche a la fois sur recipe.php
        // (id="recipe-form") et sur form.php (formulaire produit, sans id dedie).
        var form = builder.closest('form');
        if (!form) {
            return;
        }

        var canCreateIngredient = builder.dataset.canCreateIngredient === '1';
        var ingredients = parseData(builder, 'ingredients', '[]'); // [{id, name, unit}]
        var initial = parseData(builder, 'composition', '[]');     // [{ingredient_id, quantity_normal, ...}]

        addBtn.addEventListener('click', function () {
            if (!ingredients.length) {
                return; // aucun ingredient au catalogue : rien a composer
            }
            builder.appendChild(renderExistingLine(doc, ingredients, null));
        });

        if (addNewBtn && canCreateIngredient) {
            addNewBtn.addEventListener('click', function () {
                builder.appendChild(renderNewIngredientLine(doc));
            });
        }

        form.addEventListener('submit', function () {
            serialize(builder, hidden);
        });

        // Rendu initial : lignes existantes (edition). Composition vide -> aucune
        // ligne (l'utilisateur ajoute a la demande, ou enregistre une recette vide).
        initial.forEach(function (l) {
            builder.appendChild(renderExistingLine(doc, ingredients, l));
        });
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
