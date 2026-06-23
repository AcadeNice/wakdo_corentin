/*
 * counter-order.js — Composeur de commande comptoir/drive (back-office, sous-lot 3c).
 *
 * CSP 'self' : script externe (pas d'inline, zero handler dans le HTML). Les donnees
 * (produits commandables + leurs modificateurs, menus + slots + format + modificateurs
 * du burger) sont lues depuis les attributs data-* de #counter-order-form. L'equipier
 * ajoute des produits (champ quantite), personnalise un produit a la carte (retrait/
 * ajout d'ingredients) ou configure un menu (slots + format + retrait/ajout sur le
 * burger). A la soumission, le panier est serialise en JSON dans le champ cache
 * #items_json (Request::formBody cote serveur ne garde que les scalaires, d'ou le
 * passage par une chaine JSON). Le serveur revalide la forme (RG-T18), revalide chaque
 * modificateur metier (resolveModifiers) et recalcule les prix (RG-T16) : les libelles/
 * prix affiches ici sont indicatifs, jamais source de verite.
 *
 * La logique de slots (un pas par slot, requis/optionnel, format) calque
 * page-product-menu.js (borne) ; la logique de modificateurs (cases "retirer" pour les
 * ingredients is_removable, "ajouter +X.XX EUR" pour les is_addable) calque l'UX
 * borne. Seul le rendu differe (idiome back-office, pas de style borne). Les lignes
 * configurees (produit personnalise / menu) vivent dans un etat JS et sont rendues dans
 * le panier ; les produits sans modificateur sont derives a la soumission depuis les
 * champs qty_<id> (repli sans JS conserve : le serveur accepte aussi qty_<id> si
 * #items_json est vide). Un produit personnalisable est routé par la modale (sa
 * quantite directe est ignoree quand JS s'execute) pour ne pas le compter deux fois.
 *
 * Module CommonJS (admin = racine CommonJS, comme pin-modal.js) : init(doc) est
 * exporte pour les tests et auto-appele au DOMContentLoaded en production.
 */
(function () {
    'use strict';

    // SLOT_LABEL : seuls les slot_type geres deviennent une etape (l'enum DB autorise
    // aussi dessert/extra). Aligne sur page-product-menu.js (anti-perte silencieuse).
    var SLOT_LABEL = { side: 'Accompagnement', drink: 'Boisson', sauce: 'Sauce' };

    function parseData(form, key, fallback) {
        try {
            var v = JSON.parse(form.dataset[key] || fallback);
            return Array.isArray(v) ? v : JSON.parse(fallback);
        } catch (e) {
            return JSON.parse(fallback);
        }
    }

    // Surcout d'un ajout, formate en euros (affichage local indicatif ; le serveur
    // refige extra_price_cents, RG-T16).
    function formatExtra(cents) {
        return '+' + (Number(cents) / 100).toFixed(2).replace('.', ',') + ' EUR';
    }

    // Etapes composables d'un menu : burger impose ignore (non choisi ici), un pas par
    // slot gere, trie par display_order, options resolues via l'index produit. Pur.
    function composerSteps(menu, productById) {
        return (menu.slots || [])
            .filter(function (slot) {
                return Object.prototype.hasOwnProperty.call(SLOT_LABEL, slot.slot_type);
            })
            .slice()
            .sort(function (a, b) {
                return (Number(a.display_order) || 0) - (Number(b.display_order) || 0);
            })
            .map(function (slot) {
                var options = (slot.option_product_ids || [])
                    .map(function (pid) { return productById[Number(pid)]; })
                    .filter(Boolean);
                return {
                    id: Number(slot.id),
                    name: slot.name || SLOT_LABEL[slot.slot_type],
                    slotType: slot.slot_type,
                    isRequired: Number(slot.is_required) === 1,
                    options: options,
                };
            });
    }

    function init(doc) {
        var form = doc.getElementById('counter-order-form');
        var hidden = doc.getElementById('items_json');
        var cart = doc.getElementById('order-cart');
        var cartEmpty = doc.getElementById('order-cart-empty');
        var modalHost = doc.getElementById('menu-composer-modal');
        if (!form || !hidden || !cart || !modalHost) {
            return;
        }

        var products = parseData(form, 'products', '[]'); // [{id, name, price, modifiers:[...]}]
        var menus = parseData(form, 'menus', '[]');       // [{id, name, price_normal, price_maxi, burger_modifiers:[...], slots:[...]}]

        // Index produit par id : resolution des libelles d'options de slot + acces aux
        // modificateurs proposables d'un produit a la carte.
        var productById = {};
        products.forEach(function (p) {
            productById[Number(p.id)] = p;
        });

        // Lignes configurees par l'equipier : items prets a serialiser, avec libelle recap.
        // menuLines : menus configures ; productLines : produits personnalises (modifiers).
        var menuLines = [];
        var productLines = [];
        var lineSeq = 0;

        // Produits routes par la modale (ils portent un bouton "Personnaliser") : leur
        // quantite directe qty_<id> est ignoree a la serialisation pour eviter le double
        // comptage (le champ reste present pour le repli sans JS).
        var configurableIds = {};
        Array.prototype.forEach.call(doc.querySelectorAll('.product-configure'), function (btn) {
            configurableIds[Number(btn.dataset.productId)] = true;
        });

        function el(tag, className) {
            var e = doc.createElement(tag);
            if (className) {
                e.className = className;
            }
            return e;
        }

        /* ----------------------------------------------------------------- */
        /* Modificateurs : cases "retirer" / "ajouter +X.XX EUR" (UX borne)   */
        /* ----------------------------------------------------------------- */

        // Rend les controles de modificateurs d'un produit support dans un conteneur.
        // selectedRemove/selectedAdd : maps ingredient_id -> bool, mutees au changement.
        // Calque la borne : un ingredient is_removable propose "retirer", un is_addable
        // propose "ajouter (+surcout)". Un meme ingredient peut etre les deux.
        function renderModifierControls(modifiers, selectedRemove, selectedAdd) {
            var block = el('div', 'menu-composer__modifiers');
            if (!modifiers || !modifiers.length) {
                return block;
            }
            var legend = el('p', 'menu-composer__legend');
            legend.textContent = 'Personnalisation';
            block.appendChild(legend);

            modifiers.forEach(function (mod) {
                var ingId = Number(mod.ingredient_id);
                if (Number(mod.is_removable) === 1) {
                    var remLab = el('label', 'menu-composer__modifier');
                    var remBox = el('input');
                    remBox.type = 'checkbox';
                    remBox.className = 'menu-composer__modifier-remove';
                    remBox.dataset.ingredientId = String(ingId);
                    remBox.addEventListener('change', function () {
                        if (remBox.checked) {
                            selectedRemove[ingId] = true;
                        } else {
                            delete selectedRemove[ingId];
                        }
                    });
                    remLab.appendChild(remBox);
                    remLab.appendChild(doc.createTextNode(' Sans ' + String(mod.name)));
                    block.appendChild(remLab);
                }
                if (Number(mod.is_addable) === 1) {
                    var addLab = el('label', 'menu-composer__modifier');
                    var addBox = el('input');
                    addBox.type = 'checkbox';
                    addBox.className = 'menu-composer__modifier-add';
                    addBox.dataset.ingredientId = String(ingId);
                    addBox.addEventListener('change', function () {
                        if (addBox.checked) {
                            selectedAdd[ingId] = true;
                        } else {
                            delete selectedAdd[ingId];
                        }
                    });
                    addLab.appendChild(addBox);
                    addLab.appendChild(doc.createTextNode(' Extra ' + String(mod.name) + ' (' + formatExtra(mod.extra_price_cents) + ')'));
                    block.appendChild(addLab);
                }
            });

            return block;
        }

        // Construit la liste serialisable [{ingredient_id, action}] depuis les maps
        // selectedRemove / selectedAdd (remove d'abord, puis add ; un ingredient a la
        // fois retire et ajoute resterait deux entrees, mais l'UX coche rarement les deux).
        function buildModifiers(selectedRemove, selectedAdd) {
            var out = [];
            Object.keys(selectedRemove).forEach(function (id) {
                out.push({ ingredient_id: Number(id), action: 'remove' });
            });
            Object.keys(selectedAdd).forEach(function (id) {
                out.push({ ingredient_id: Number(id), action: 'add' });
            });
            return out;
        }

        // Libelle recap des modificateurs choisis (ex. "sans Oignon, +Bacon"), resolu
        // via la liste de modificateurs proposables (pour le nom de l'ingredient).
        function modifierLabel(modifiers, chosen) {
            if (!chosen || !chosen.length) {
                return '';
            }
            var nameById = {};
            (modifiers || []).forEach(function (m) {
                nameById[Number(m.ingredient_id)] = String(m.name);
            });
            var parts = chosen.map(function (c) {
                var name = nameById[Number(c.ingredient_id)] || ('#' + c.ingredient_id);
                return c.action === 'add' ? ('+' + name) : ('sans ' + name);
            });
            return parts.join(', ');
        }

        /* ----------------------------------------------------------------- */
        /* Serialisation du panier -> #items_json                             */
        /* ----------------------------------------------------------------- */

        // Produits sans modificateur : derives des champs qty_<id> (>= 1) NON routes par
        // la modale. Produits personnalises : productLines. Menus : menuLines. La forme
        // calque ce qu'attend OrderRepository::resolveLine (revalide cote serveur).
        function serialize() {
            var items = [];

            Array.prototype.forEach.call(form.querySelectorAll('.order-qty'), function (input) {
                var productId = Number(input.dataset.productId);
                if (configurableIds[productId]) {
                    return; // route par la modale -> pas de double comptage.
                }
                var quantity = parseInt(input.value, 10);
                if (productId > 0 && quantity >= 1) {
                    items.push({ type: 'product', product_id: productId, quantity: quantity });
                }
            });

            productLines.forEach(function (line) {
                items.push({
                    type: 'product',
                    product_id: line.productId,
                    quantity: line.quantity,
                    modifiers: line.modifiers.map(function (m) {
                        return { ingredient_id: m.ingredient_id, action: m.action };
                    }),
                });
            });

            menuLines.forEach(function (line) {
                items.push({
                    type: 'menu',
                    menu_id: line.menuId,
                    quantity: 1,
                    format: line.format,
                    selections: line.selections.map(function (s) {
                        return { menu_slot_id: s.slotId, product_id: s.productId };
                    }),
                    modifiers: line.modifiers.map(function (m) {
                        return { ingredient_id: m.ingredient_id, action: m.action };
                    }),
                });
            });

            hidden.value = JSON.stringify(items);
        }

        /* ----------------------------------------------------------------- */
        /* Rendu du panier (recap des lignes configurees)                     */
        /* ----------------------------------------------------------------- */

        function renderCart() {
            Array.prototype.forEach.call(cart.querySelectorAll('.order-cart__line'), function (node) {
                node.parentNode.removeChild(node);
            });

            productLines.forEach(function (line) {
                var li = el('li', 'order-cart__line');

                var label = el('span', 'order-cart__label');
                var text = line.productName + ' x' + line.quantity;
                var modLabel = modifierLabel(line.proposable, line.modifiers);
                if (modLabel) {
                    text += ' (' + modLabel + ')';
                }
                label.textContent = text;
                li.appendChild(label);

                var removeBtn = el('button', 'btn btn-secondary order-cart__remove');
                removeBtn.type = 'button';
                removeBtn.textContent = 'Retirer';
                removeBtn.addEventListener('click', function () {
                    productLines = productLines.filter(function (l) { return l.localId !== line.localId; });
                    renderCart();
                });
                li.appendChild(removeBtn);

                cart.appendChild(li);
            });

            menuLines.forEach(function (line) {
                var li = el('li', 'order-cart__line');

                var label = el('span', 'order-cart__label');
                var parts = [line.menuName + ' (' + (line.format === 'maxi' ? 'Maxi' : 'Normal') + ')'];
                line.selections.forEach(function (s) {
                    var p = productById[Number(s.productId)];
                    if (p) {
                        parts.push(p.name);
                    }
                });
                var text = parts.join(' - ');
                var modLabel = modifierLabel(line.proposable, line.modifiers);
                if (modLabel) {
                    text += ' (' + modLabel + ')';
                }
                label.textContent = text;
                li.appendChild(label);

                var removeBtn = el('button', 'btn btn-secondary order-cart__remove');
                removeBtn.type = 'button';
                removeBtn.textContent = 'Retirer';
                removeBtn.addEventListener('click', function () {
                    menuLines = menuLines.filter(function (l) { return l.localId !== line.localId; });
                    renderCart();
                });
                li.appendChild(removeBtn);

                cart.appendChild(li);
            });

            if (cartEmpty) {
                cartEmpty.style.display = (productLines.length || menuLines.length) ? 'none' : '';
            }
        }

        /* ----------------------------------------------------------------- */
        /* Modales de configuration                                           */
        /* ----------------------------------------------------------------- */

        function closeComposer() {
            modalHost.textContent = '';
            modalHost.setAttribute('hidden', '');
        }

        // Modale d'un produit a la carte : quantite + modificateurs (retrait/ajout).
        function openProductComposer(product) {
            var proposable = product.modifiers || [];
            var state = { quantity: 1, selectedRemove: {}, selectedAdd: {} };

            modalHost.textContent = '';
            var panel = el('div', 'menu-composer');

            var title = el('h2', 'menu-composer__title');
            title.textContent = product.name;
            panel.appendChild(title);

            // Quantite
            var qtyBlock = el('div', 'menu-composer__slot');
            var qtyLab = el('label', 'menu-composer__legend');
            qtyLab.textContent = 'Quantite';
            qtyLab.setAttribute('for', 'composer-product-qty');
            qtyBlock.appendChild(qtyLab);
            var qtyInput = el('input', 'form-input menu-composer__qty');
            qtyInput.type = 'number';
            qtyInput.id = 'composer-product-qty';
            qtyInput.min = '1';
            qtyInput.value = '1';
            qtyInput.addEventListener('change', function () {
                var v = parseInt(qtyInput.value, 10);
                state.quantity = v >= 1 ? v : 1;
            });
            qtyBlock.appendChild(qtyInput);
            panel.appendChild(qtyBlock);

            // Modificateurs
            panel.appendChild(renderModifierControls(proposable, state.selectedRemove, state.selectedAdd));

            var actions = el('div', 'menu-composer__actions');
            var addBtn = el('button', 'btn btn-primary menu-composer__add');
            addBtn.type = 'button';
            addBtn.textContent = 'Ajouter au panier';
            addBtn.addEventListener('click', function () {
                productLines.push({
                    localId: ++lineSeq,
                    productId: Number(product.id),
                    productName: product.name,
                    quantity: state.quantity,
                    proposable: proposable,
                    modifiers: buildModifiers(state.selectedRemove, state.selectedAdd),
                });
                renderCart();
                closeComposer();
            });
            actions.appendChild(addBtn);

            var cancelBtn = el('button', 'btn btn-secondary menu-composer__cancel');
            cancelBtn.type = 'button';
            cancelBtn.textContent = 'Annuler';
            cancelBtn.addEventListener('click', closeComposer);
            actions.appendChild(cancelBtn);

            panel.appendChild(actions);
            modalHost.appendChild(panel);
            modalHost.removeAttribute('hidden');
        }

        // Ouvre la modale d'un menu : choix du format, une selection par slot, puis les
        // modificateurs du burger. Pre-selectionne le 1er choix de chaque slot requis.
        function openComposer(menu) {
            var steps = composerSteps(menu, productById);
            var proposable = menu.burger_modifiers || [];
            var state = { format: 'normal', selections: {}, selectedRemove: {}, selectedAdd: {} };
            steps.forEach(function (step) {
                if (step.isRequired && step.options[0]) {
                    state.selections[step.id] = step.options[0].id;
                }
            });

            modalHost.textContent = '';
            var panel = el('div', 'menu-composer');

            var title = el('h2', 'menu-composer__title');
            title.textContent = menu.name;
            panel.appendChild(title);

            // Format Normal / Maxi
            var formatGroup = el('div', 'menu-composer__format');
            var formatLegend = el('p', 'menu-composer__legend');
            formatLegend.textContent = 'Format';
            formatGroup.appendChild(formatLegend);
            [
                { value: 'normal', label: 'Normal' },
                { value: 'maxi', label: 'Maxi' },
            ].forEach(function (fmt) {
                var lab = el('label', 'menu-composer__radio');
                var radio = el('input');
                radio.type = 'radio';
                radio.name = 'composer-format';
                radio.value = fmt.value;
                radio.className = 'menu-composer__format-input';
                if (state.format === fmt.value) {
                    radio.checked = true;
                }
                radio.addEventListener('change', function () {
                    state.format = fmt.value;
                });
                lab.appendChild(radio);
                lab.appendChild(doc.createTextNode(' ' + fmt.label));
                formatGroup.appendChild(lab);
            });
            panel.appendChild(formatGroup);

            // Un bloc par slot : select des options (+ "Sans" si optionnel).
            steps.forEach(function (step) {
                var block = el('div', 'menu-composer__slot');
                var lab = el('label', 'menu-composer__legend');
                lab.textContent = step.name + (step.isRequired ? '' : ' (optionnel)');
                block.appendChild(lab);

                var select = el('select', 'form-input menu-composer__slot-select');
                select.dataset.slotId = String(step.id);
                if (!step.isRequired) {
                    var none = el('option');
                    none.value = '';
                    none.textContent = 'Sans';
                    select.appendChild(none);
                }
                step.options.forEach(function (opt) {
                    var o = el('option');
                    o.value = String(opt.id);
                    o.textContent = String(opt.name);
                    if (state.selections[step.id] === opt.id) {
                        o.selected = true;
                    }
                    select.appendChild(o);
                });
                select.addEventListener('change', function () {
                    var raw = select.value;
                    if (raw === '') {
                        delete state.selections[step.id];
                    } else {
                        state.selections[step.id] = parseInt(raw, 10);
                    }
                });
                block.appendChild(select);
                panel.appendChild(block);
            });

            // Modificateurs du burger support (retrait/ajout d'ingredients).
            panel.appendChild(renderModifierControls(proposable, state.selectedRemove, state.selectedAdd));

            // Actions : ajouter (si tous les requis choisis) / annuler.
            var actions = el('div', 'menu-composer__actions');
            var addBtn = el('button', 'btn btn-primary menu-composer__add');
            addBtn.type = 'button';
            addBtn.textContent = 'Ajouter au panier';
            addBtn.addEventListener('click', function () {
                var allRequired = steps.filter(function (s) { return s.isRequired; })
                    .every(function (s) { return state.selections[s.id] != null; });
                if (!allRequired) {
                    return;
                }
                var selections = [];
                steps.forEach(function (step) {
                    var chosen = state.selections[step.id];
                    if (chosen != null) {
                        selections.push({ slotId: step.id, productId: chosen });
                    }
                });
                menuLines.push({
                    localId: ++lineSeq,
                    menuId: Number(menu.id),
                    menuName: menu.name,
                    format: state.format,
                    selections: selections,
                    proposable: proposable,
                    modifiers: buildModifiers(state.selectedRemove, state.selectedAdd),
                });
                renderCart();
                closeComposer();
            });
            actions.appendChild(addBtn);

            var cancelBtn = el('button', 'btn btn-secondary menu-composer__cancel');
            cancelBtn.type = 'button';
            cancelBtn.textContent = 'Annuler';
            cancelBtn.addEventListener('click', closeComposer);
            actions.appendChild(cancelBtn);

            panel.appendChild(actions);
            modalHost.appendChild(panel);
            modalHost.removeAttribute('hidden');
        }

        /* ----------------------------------------------------------------- */
        /* Cablage                                                            */
        /* ----------------------------------------------------------------- */

        Array.prototype.forEach.call(doc.querySelectorAll('.product-configure'), function (btn) {
            btn.addEventListener('click', function () {
                var productId = Number(btn.dataset.productId);
                var product = productById[productId];
                if (product) {
                    openProductComposer(product);
                }
            });
        });

        Array.prototype.forEach.call(doc.querySelectorAll('.menu-configure'), function (btn) {
            btn.addEventListener('click', function () {
                var menuId = Number(btn.dataset.menuId);
                var menu = menus.filter(function (m) { return Number(m.id) === menuId; })[0];
                if (menu) {
                    openComposer(menu);
                }
            });
        });

        form.addEventListener('submit', function () {
            serialize();
        });

        renderCart();
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init, composerSteps: composerSteps };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
