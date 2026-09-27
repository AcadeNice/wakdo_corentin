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
 *
 * Filtre du picker d'ingredients par famille (chantier "recette filtree par
 * categorie du produit") : le catalogue propose par defaut est restreint aux
 * familles pertinentes de la categorie du produit, plus tous les ingredients
 * de famille inconnue (toujours visibles). Filtre SOUPLE, jamais une
 * interdiction : une case "Afficher tous les ingredients" le desactive, et une
 * ligne de recette deja presente n'est jamais retiree ni mise en erreur au nom
 * d'une classification posee apres coup (une salade avec des croutons reste
 * possible). Sur doute (donnees de correspondance absentes ou illisibles), le
 * comportement est "aucun filtre, on montre tout" -- jamais une liste vide.
 *
 * Deux ecrans partagent ce builder, avec deux facons de connaitre la
 * categorie :
 * - admin/products/form.php : #category_id est un <select> que l'equipier
 *   change en direct (produit pas encore enregistre, ou categorie en cours de
 *   choix) -- le filtre se recalcule a chaque changement.
 * - admin/products/recipe.php : la categorie du produit est deja fixee (page
 *   d'edition de recette dediee, sans selecteur de categorie), transmise en
 *   data-product-category-id sur #recipe-builder -- rien a ecouter, un seul
 *   calcul au chargement.
 * #category_id gagne s'il existe (formulaire produit) ; sinon
 * data-product-category-id sert de repli (page recette dediee) ; sinon aucune
 * categorie n'est connue -- aucun filtre.
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

    // Une famille null/absente est TOUJOURS visible (RG du filtre souple) : une
    // classification finit toujours par rencontrer un cas qu'elle n'avait pas
    // prevu, elle ne doit jamais faire disparaitre un ingredient non classe.
    function ingredientMatchesFamilies(ingredient, allowedSlugs) {
        if (allowedSlugs === null) {
            return true; // pas de restriction pour cette categorie
        }
        var slug = ingredient && ingredient.family;
        if (!slug) {
            return true;
        }
        return allowedSlugs.indexOf(slug) !== -1;
    }

    // Familles autorisees pour une categorie donnee. null = aucune restriction
    // (categorie absente de categoryFamilies, ou aucune categorie choisie) --
    // c'est le comportement par defaut si le contrat n'est pas au rendez-vous.
    function allowedFamiliesFor(categoryFamilies, categoryId) {
        if (!categoryId) {
            return null;
        }
        var key = String(categoryId);
        if (!Object.prototype.hasOwnProperty.call(categoryFamilies, key)) {
            return null;
        }
        var list = categoryFamilies[key];
        return Array.isArray(list) ? list : null;
    }

    function buildIngredientOption(doc, ingredient, selectedId) {
        var opt = el(doc, 'option');
        opt.value = String(ingredient.id);
        opt.textContent = String(ingredient.name) + (ingredient.unit ? ' (' + String(ingredient.unit) + ')' : '');
        if (selectedId != null && selectedId !== '' && Number(selectedId) === Number(ingredient.id)) {
            opt.selected = true;
        }
        return opt;
    }

    /**
     * Remplit un <select> d'ingredients. Sans libelles de familles
     * (`hasFamilyLabels` faux -- taxonomie absente ou illisible), rendu plat
     * identique au comportement historique : aucun filtre, aucun groupe.
     *
     * Avec une taxonomie : options groupees par famille (<optgroup>, dans
     * l'ordre d'ingredientFamilyLabels), les non classes dans un groupe dedie
     * en fin de liste. L'ingredient DEJA selectionne (`selectedId`) reste
     * toujours dans la liste meme s'il sort du filtre courant -- une ligne de
     * recette existante, ou un choix deja fait par l'equipier, n'est jamais
     * retire au nom d'un filtre pose apres coup.
     */
    function populateIngredientOptions(doc, select, ingredients, selectedId, allowedSlugs, ingredientFamilyLabels, hasFamilyLabels) {
        while (select.firstChild) {
            select.removeChild(select.firstChild);
        }

        if (!hasFamilyLabels) {
            ingredients.forEach(function (i) {
                select.appendChild(buildIngredientOption(doc, i, selectedId));
            });
            return;
        }

        var order = Object.keys(ingredientFamilyLabels);
        var groups = {};
        order.forEach(function (slug) {
            groups[slug] = [];
        });
        var unclassified = [];

        ingredients.forEach(function (ing) {
            var isKept = selectedId != null && selectedId !== '' && Number(selectedId) === Number(ing.id);
            if (!ingredientMatchesFamilies(ing, allowedSlugs) && !isKept) {
                return;
            }
            var slug = ing && ing.family;
            if (slug && Object.prototype.hasOwnProperty.call(groups, slug)) {
                groups[slug].push(ing);
            } else {
                unclassified.push(ing);
            }
        });

        order.forEach(function (slug) {
            if (!groups[slug].length) {
                return; // groupe vide (aucun ingredient de cette famille visible) : omis
            }
            var optgroup = el(doc, 'optgroup');
            optgroup.label = ingredientFamilyLabels[slug];
            groups[slug].forEach(function (ing) {
                optgroup.appendChild(buildIngredientOption(doc, ing, selectedId));
            });
            select.appendChild(optgroup);
        });

        if (unclassified.length) {
            var unclassifiedGroup = el(doc, 'optgroup');
            unclassifiedGroup.label = 'Ingrédients non classés';
            unclassified.forEach(function (ing) {
                unclassifiedGroup.appendChild(buildIngredientOption(doc, ing, selectedId));
            });
            select.appendChild(unclassifiedGroup);
        }
    }

    // Combien d'ingredients du catalogue passent le filtre (pour le compteur
    // "X sur Y" pres du picker). Ne compte pas les exceptions "deja
    // selectionne" d'un select particulier : c'est l'etat du filtre lui-meme.
    function countVisibleIngredients(ingredients, allowedSlugs) {
        if (allowedSlugs === null) {
            return ingredients.length;
        }
        var count = 0;
        ingredients.forEach(function (ing) {
            if (ingredientMatchesFamilies(ing, allowedSlugs)) {
                count += 1;
            }
        });
        return count;
    }

    /**
     * Case "Afficher tous les ingredients" + compteur "X sur Y" en region live
     * discrete. L'etiquette entoure la case (association implicite, meme
     * technique que .recipe-check) ; le compteur est visible (pas sr-only) et
     * annonce aussi aux technologies d'assistance (role="status" + aria-live) :
     * un filtre invisible qui cache des lignes est un piege pour tout le monde,
     * pas seulement pour un lecteur d'ecran.
     */
    function buildFilterControls(doc) {
        var wrap = el(doc, 'div', 'recipe-filter');

        var label = el(doc, 'label', 'recipe-check recipe-filter__toggle');
        var checkbox = el(doc, 'input', 'recipe-show-all');
        checkbox.type = 'checkbox';
        checkbox.id = 'recipe-show-all-ingredients';
        var caption = el(doc, 'span');
        caption.textContent = 'Afficher tous les ingrédients';
        label.appendChild(checkbox);
        label.appendChild(caption);
        wrap.appendChild(label);

        var counter = el(doc, 'p', 'recipe-filter__count');
        counter.id = 'recipe-filter-count';
        counter.setAttribute('role', 'status');
        counter.setAttribute('aria-live', 'polite');
        wrap.appendChild(counter);

        return { wrap: wrap, checkbox: checkbox, counter: counter };
    }

    // Ligne "ingredient existant" : picker + quantites. `line` peut etre vide (ajout).
    function renderExistingLine(doc, ingredients, line, allowedSlugs, ingredientFamilyLabels, hasFamilyLabels) {
        line = line || {};

        var block = el(doc, 'fieldset', 'recipe-line recipe-line-existing form-group');

        var legend = el(doc, 'legend');
        legend.textContent = 'Ingrédient du catalogue';
        block.appendChild(legend);

        var fields = el(doc, 'div', 'recipe-line__fields');
        block.appendChild(fields);

        var ingSelect = el(doc, 'select', 'form-input recipe-ingredient');
        populateIngredientOptions(doc, ingSelect, ingredients, line.ingredient_id, allowedSlugs || null, ingredientFamilyLabels || {}, Boolean(hasFamilyLabels));
        // Marque si la valeur du select est un choix REEL (donnee de composition
        // deja enregistree, ou selection manuelle) plutot que le premier <option>
        // choisi par defaut par le navigateur faute de mieux. Seul un choix reel
        // doit survivre a un recalcul de filtre (changement de categorie, case
        // "afficher tout") -- sinon le filtre ne recalculerait jamais rien, une
        // ligne fraichement ajoutee gardant pour toujours son option par defaut.
        ingSelect.dataset.userSet = line.ingredient_id != null ? '1' : '0';
        ingSelect.addEventListener('change', function () {
            ingSelect.dataset.userSet = '1';
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

    // Meme prudence que parseData, pour une correspondance (objet JSON) plutot
    // qu'une liste : attribut absent, JSON illisible, ou valeur qui n'est pas un
    // objet -> {} (objet vide). Un objet vide desactive naturellement tout
    // filtre qui s'appuie dessus (aucune cle ne matche jamais) : c'est le
    // comportement "sur doute, on montre tout" demande, sans cas particulier.
    function parseObject(builder, key) {
        var raw = builder.dataset[key];
        if (!raw) {
            return {};
        }
        try {
            var v = JSON.parse(raw);
            if (v && typeof v === 'object') {
                return v;
            }
            return {};
        } catch (e) {
            return {};
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
        var ingredients = parseData(builder, 'ingredients', '[]'); // [{id, name, unit, family}]
        var initial = parseData(builder, 'composition', '[]');     // [{ingredient_id, quantity_normal, ...}]

        // Filtre par famille (categorie -> familles autorisees). Sans libelles de
        // familles (attribut absent ou JSON illisible), la fonctionnalite entiere
        // reste inerte : rendu plat historique, aucune commande de filtre affichee
        // -- c'est le cas de recipe.php (page recette dediee, hors perimetre de ce
        // chantier) et le repli "sur doute" impose pour ce chantier.
        var categoryFamilies = parseObject(builder, 'categoryFamilies');
        var ingredientFamilyLabels = parseObject(builder, 'ingredientFamilies');
        var hasFamilyLabels = Object.keys(ingredientFamilyLabels).length > 0;
        var categorySelect = doc.getElementById('category_id');
        var showAllIngredients = false;
        var filterControls = null;

        // #category_id (formulaire produit, categorie encore modifiable) gagne
        // s'il existe ; sinon data-product-category-id (page recette dediee,
        // categorie du produit deja fixee) ; sinon aucune categorie connue.
        function currentCategoryId() {
            if (categorySelect) {
                return categorySelect.value;
            }
            return builder.dataset.productCategoryId || '';
        }

        function currentAllowedSlugs() {
            if (!hasFamilyLabels || showAllIngredients) {
                return null;
            }
            return allowedFamiliesFor(categoryFamilies, currentCategoryId());
        }

        function updateCounter() {
            if (!filterControls) {
                return;
            }
            var allowedSlugs = currentAllowedSlugs();
            var total = ingredients.length;
            if (allowedSlugs === null) {
                filterControls.counter.textContent = total + ' ingrédient(s) affiché(s), aucun filtre.';
                return;
            }
            var shown = countVisibleIngredients(ingredients, allowedSlugs);
            filterControls.counter.textContent = shown + ' ingrédient(s) affiché(s) sur ' + total + ', filtrés selon la catégorie.';
        }

        // Recalcule les options de tous les selects d'ingredients deja montes,
        // en conservant la valeur COURANTE de chacun (choix deja fait par
        // l'equipier, ou ingredient d'une ligne existante) meme si elle sort du
        // filtre recalcule : on ne detruit jamais un choix deja fait.
        function refreshIngredientPickers() {
            var allowedSlugs = currentAllowedSlugs();
            var selects = builder.querySelectorAll('.recipe-ingredient');
            Array.prototype.forEach.call(selects, function (select) {
                // Seul un choix reel (dataset.userSet) doit survivre au recalcul --
                // pas le premier <option> retenu par defaut sur une ligne fraiche.
                var keepValue = select.dataset.userSet === '1' ? select.value : null;
                populateIngredientOptions(doc, select, ingredients, keepValue, allowedSlugs, ingredientFamilyLabels, hasFamilyLabels);
            });
            updateCounter();
        }

        if (hasFamilyLabels) {
            filterControls = buildFilterControls(doc);
            builder.parentNode.insertBefore(filterControls.wrap, builder);
            filterControls.checkbox.addEventListener('change', function () {
                showAllIngredients = filterControls.checkbox.checked;
                refreshIngredientPickers();
            });

            if (categorySelect) {
                categorySelect.addEventListener('change', function () {
                    refreshIngredientPickers();
                });
            }
        }

        addBtn.addEventListener('click', function () {
            if (!ingredients.length) {
                return; // aucun ingredient au catalogue : rien a composer
            }
            builder.appendChild(renderExistingLine(doc, ingredients, null, currentAllowedSlugs(), ingredientFamilyLabels, hasFamilyLabels));
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
        // Chaque ligne garde son ingredient d'origine meme s'il sort du filtre de
        // la categorie actuelle (RG : jamais de perte d'une donnee correcte).
        initial.forEach(function (l) {
            builder.appendChild(renderExistingLine(doc, ingredients, l, currentAllowedSlugs(), ingredientFamilyLabels, hasFamilyLabels));
        });

        updateCounter();
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
