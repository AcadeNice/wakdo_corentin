/*
 * counter-order.js — Composeur de commande comptoir/drive (back-office, sous-lot 3b).
 *
 * CSP 'self' : script externe (pas d'inline, zero handler dans le HTML). Les donnees
 * (produits commandables, menus + slots + options) sont lues depuis les attributs
 * data-* de #counter-order-form. L'equipier ajoute des produits (champ quantite) et
 * configure des menus (slots accompagnement/boisson/sauce + format Normal/Maxi). A la
 * soumission, le panier est serialise en JSON dans le champ cache #items_json
 * (Request::formBody cote serveur ne garde que les scalaires, d'ou le passage par une
 * chaine JSON). Le serveur revalide la forme (RG-T18) et recalcule les prix (RG-T16) :
 * les libelles/prix affiches ici sont indicatifs, jamais source de verite.
 *
 * La logique de slots (un pas par slot, requis/optionnel, format) calque
 * page-product-menu.js (borne) ; seul le rendu differe (idiome back-office, pas de
 * style borne). Les menus configures vivent dans un etat JS et sont rendus dans le
 * panier ; les produits sont derives a la soumission depuis les champs qty_<id> (repli
 * sans JS conserve : le serveur accepte aussi qty_<id> si #items_json est vide).
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

        var products = parseData(form, 'products', '[]'); // [{id, name, price}]
        var menus = parseData(form, 'menus', '[]');       // [{id, name, price_normal, price_maxi, slots:[...]}]

        // Index produit par id : resolution des libelles d'options de slot (affichage).
        var productById = {};
        products.forEach(function (p) {
            productById[Number(p.id)] = p;
        });

        // Menus configures par l'equipier : items prets a serialiser, avec libelle recap.
        var menuLines = [];
        var lineSeq = 0;

        function el(tag, className) {
            var e = doc.createElement(tag);
            if (className) {
                e.className = className;
            }
            return e;
        }

        /* ----------------------------------------------------------------- */
        /* Serialisation du panier -> #items_json                             */
        /* ----------------------------------------------------------------- */

        // Produits : derives des champs qty_<id> (>= 1). Menus : items configures. La
        // forme calque ce qu'attend OrderRepository::resolveLine (revalide cote serveur).
        function serialize() {
            var items = [];

            Array.prototype.forEach.call(form.querySelectorAll('.order-qty'), function (input) {
                var productId = Number(input.dataset.productId);
                var quantity = parseInt(input.value, 10);
                if (productId > 0 && quantity >= 1) {
                    items.push({ type: 'product', product_id: productId, quantity: quantity });
                }
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
                });
            });

            hidden.value = JSON.stringify(items);
        }

        /* ----------------------------------------------------------------- */
        /* Rendu du panier (recap des menus configures)                       */
        /* ----------------------------------------------------------------- */

        function renderCart() {
            Array.prototype.forEach.call(cart.querySelectorAll('.order-cart__line'), function (node) {
                node.parentNode.removeChild(node);
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
                label.textContent = parts.join(' - ');
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
                cartEmpty.style.display = menuLines.length ? 'none' : '';
            }
        }

        /* ----------------------------------------------------------------- */
        /* Modale de configuration d'un menu                                  */
        /* ----------------------------------------------------------------- */

        function closeComposer() {
            modalHost.textContent = '';
            modalHost.setAttribute('hidden', '');
        }

        // Ouvre la modale : choix du format puis une selection par slot. Pre-selectionne
        // le 1er choix de chaque slot requis (calque page-product-menu.js).
        function openComposer(menu) {
            var steps = composerSteps(menu, productById);
            var state = { format: 'normal', selections: {} };
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
