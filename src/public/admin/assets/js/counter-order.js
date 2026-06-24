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

    // Montant en euros formate comme le PHP number_format(.../100, 2, ',', ' ') des
    // vues : virgule decimale ET espace separateur de milliers. Aligne l'affichage
    // client sur le rendu serveur (ex. 1 234,50 EUR) pour eviter une divergence visible
    // sur les montants >= 1000. Indicatif : le serveur recalcule tout (RG-T16).
    function moneyParts(cents) {
        var fixed = (Number(cents) / 100).toFixed(2);
        var dot = fixed.indexOf('.');
        var intPart = fixed.slice(0, dot);
        var decPart = fixed.slice(dot + 1);
        var sign = '';
        if (intPart.charAt(0) === '-') {
            sign = '-';
            intPart = intPart.slice(1);
        }
        // Insere un espace tous les 3 chiffres depuis la droite (separateur de milliers).
        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return sign + intPart + ',' + decPart;
    }

    // Surcout d'un ajout, formate en euros (affichage local indicatif ; le serveur
    // refige extra_price_cents, RG-T16).
    function formatExtra(cents) {
        return '+' + moneyParts(cents) + ' EUR';
    }

    // Montant en euros (sans signe), pour les prix de ligne et le total. Meme format
    // que les vues PHP (cf. moneyParts).
    function formatEuros(cents) {
        return moneyParts(cents) + ' EUR';
    }

    // Somme des surcouts d'ajout (action 'add') d'une liste de modificateurs choisis,
    // resolus via la liste proposable (extra_price_cents). Les retraits ne changent pas
    // le prix indicatif. Pur ; le serveur reste seul juge du surcout reel.
    function modifiersExtra(proposable, chosen) {
        if (!chosen || !chosen.length) {
            return 0;
        }
        var extraById = {};
        (proposable || []).forEach(function (m) {
            extraById[Number(m.ingredient_id)] = Number(m.extra_price_cents) || 0;
        });
        return chosen.reduce(function (sum, c) {
            return c.action === 'add' ? sum + (extraById[Number(c.ingredient_id)] || 0) : sum;
        }, 0);
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

        // Elements de prix (1) : valeur du total + libelle du bouton d'encaissement.
        // Optionnels (le rendu degrade sans eux) -> garde-fous au moment d'ecrire.
        var totalValue = doc.getElementById('order-total-value');
        var submitBtn = doc.getElementById('order-submit');

        // 7a : champ numero de table, visible seulement en sur place (toggle au mode).
        var serviceMode = doc.getElementById('service_mode');
        var serviceTagGroup = doc.getElementById('service_tag_group');

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
        // comptage. Progressive enhancement (4) : le champ qty est EDITABLE dans le HTML
        // (repli sans JS) ; ici, en presence de JS, on le neutralise et on revele
        // l'indice "via Personnaliser" pour que l'equipier sache ou saisir la quantite.
        var configurableIds = {};
        Array.prototype.forEach.call(doc.querySelectorAll('.product-configure'), function (btn) {
            var pid = Number(btn.dataset.productId);
            configurableIds[pid] = true;

            var qtyInput = doc.getElementById('qty_' + pid);
            if (qtyInput) {
                qtyInput.disabled = true;
                qtyInput.classList.add('order-qty--disabled');
                qtyInput.setAttribute('aria-label', (qtyInput.getAttribute('aria-label') || 'Quantite') + ' (via Personnaliser)');
            }
            var hint = doc.querySelector('[data-qty-hint="' + pid + '"]');
            if (hint) {
                hint.hidden = false;
            }
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
        /* Prix indicatifs (1, 6) : par ligne + total + libelle du bouton     */
        /* ----------------------------------------------------------------- */

        // Prix d'une ligne PRODUIT (configuree par la modale) : prix de base + surcout
        // des ajouts, le tout multiplie par la quantite. Indicatif (RG-T16 serveur).
        function productLineTotal(line) {
            var base = (productById[Number(line.productId)] || {}).price || 0;
            var extra = modifiersExtra(line.proposable, line.modifiers);
            return (Number(base) + extra) * Number(line.quantity || 1);
        }

        // Prix d'une ligne MENU : price_maxi si format maxi sinon price_normal, plus le
        // surcout des ajouts sur le burger. Les selections de slot n'ajoutent rien (le
        // prix du menu est forfaitaire cote serveur). Indicatif.
        function menuLineTotal(line) {
            var menu = menus.filter(function (m) { return Number(m.id) === Number(line.menuId); })[0] || {};
            var base = line.format === 'maxi' ? (menu.price_maxi || 0) : (menu.price_normal || 0);
            var extra = modifiersExtra(line.proposable, line.modifiers);
            return Number(base) + extra;
        }

        // Total indicatif du panier : derive des champs qty_<id> (produits simples) +
        // des lignes configurees (produits personnalises + menus). Met a jour le pied
        // de panier ET le libelle du bouton ("Encaisser X,XX EUR").
        function updateTotal() {
            var total = 0;

            Array.prototype.forEach.call(form.querySelectorAll('.order-qty'), function (input) {
                var productId = Number(input.dataset.productId);
                if (configurableIds[productId]) {
                    return; // route par la modale -> compte plus bas (pas de double comptage).
                }
                var quantity = parseInt(input.value, 10);
                if (productId > 0 && quantity >= 1) {
                    total += ((productById[productId] || {}).price || 0) * quantity;
                }
            });

            productLines.forEach(function (line) { total += productLineTotal(line); });
            menuLines.forEach(function (line) { total += menuLineTotal(line); });

            if (totalValue) {
                totalValue.textContent = formatEuros(total);
            }
            if (submitBtn) {
                submitBtn.textContent = 'Encaisser ' + formatEuros(total);
            }
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

                var price = el('span', 'order-cart__price');
                price.textContent = formatEuros(productLineTotal(line));
                li.appendChild(price);

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

                var price = el('span', 'order-cart__price');
                price.textContent = formatEuros(menuLineTotal(line));
                li.appendChild(price);

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

            updateTotal();
        }

        /* ----------------------------------------------------------------- */
        /* Modales de configuration                                           */
        /* ----------------------------------------------------------------- */

        // Handlers de modale courants (un jeu a la fois) : retires a la fermeture pour
        // ne pas accumuler de listeners a chaque ouverture. lastFocused memorise
        // l'element qui avait le focus AVANT l'ouverture, pour le restaurer a la
        // fermeture (a11y : le focus ne doit pas retomber en haut de page).
        var escHandler = null;
        var trapHandler = null;
        var lastFocused = null;

        // Selecteur des controles focusables d'une modale (boutons, champs, selects ;
        // les champs desactives/caches sont exclus). Le trap cycle sur cet ensemble.
        var FOCUSABLE = 'button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

        function focusableIn(root) {
            return Array.prototype.slice.call(root.querySelectorAll(FOCUSABLE));
        }

        function closeComposer() {
            if (escHandler) {
                doc.removeEventListener('keydown', escHandler);
                escHandler = null;
            }
            if (trapHandler) {
                doc.removeEventListener('keydown', trapHandler);
                trapHandler = null;
            }
            modalHost.textContent = '';
            modalHost.setAttribute('hidden', '');

            // Restaure le focus sur l'element declencheur (bouton Personnaliser/Configurer).
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
            lastFocused = null;
        }

        // 7c + a11y : monte un panneau dans la modale avec un overlay (clic = fermeture),
        // pose role=dialog / aria-modal / aria-labelledby (titre h2), gere Echap, piege
        // Tab/Shift+Tab dans le panneau, memorise et restaure le focus. Le panel est
        // deja construit par l'appelant ; on ne fait qu'habiller l'ouverture.
        function openModal(panel) {
            lastFocused = doc.activeElement;

            modalHost.textContent = '';

            // role=dialog modal + libelle = titre h2 de la modale (id stable, partage
            // par les deux composeurs car une seule modale est ouverte a la fois).
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
            var titleEl = panel.querySelector('.menu-composer__title');
            if (titleEl) {
                titleEl.id = 'menu-composer-title';
                panel.setAttribute('aria-labelledby', 'menu-composer-title');
            }

            var overlay = el('div', 'menu-composer__overlay');
            // Clic sur le fond (overlay lui-meme, pas un enfant) -> fermeture.
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) {
                    closeComposer();
                }
            });
            overlay.appendChild(panel);
            modalHost.appendChild(overlay);
            modalHost.removeAttribute('hidden');

            escHandler = function (event) {
                if (event.key === 'Escape' || event.keyCode === 27) {
                    closeComposer();
                }
            };
            doc.addEventListener('keydown', escHandler);

            // Focus-trap : Tab/Shift+Tab cyclent dans les controles focusables du panel.
            trapHandler = function (event) {
                if (event.key !== 'Tab' && event.keyCode !== 9) {
                    return;
                }
                var focusable = focusableIn(panel);
                if (!focusable.length) {
                    return;
                }
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                var active = doc.activeElement;
                if (event.shiftKey && (active === first || !panel.contains(active))) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && active === last) {
                    event.preventDefault();
                    first.focus();
                }
            };
            doc.addEventListener('keydown', trapHandler);

            // Focus sur le premier controle pour la saisie clavier.
            var firstControl = focusableIn(panel)[0];
            if (firstControl && typeof firstControl.focus === 'function') {
                firstControl.focus();
            }
        }

        // Modale d'un produit a la carte : quantite + modificateurs (retrait/ajout).
        function openProductComposer(product) {
            var proposable = product.modifiers || [];
            var state = { quantity: 1, selectedRemove: {}, selectedAdd: {} };

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
            openModal(panel);
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

            // 7c : message inline au lieu d'un return muet quand un slot requis n'est pas
            // choisi. Le <p role=alert> reste present en permanence (non hidden), vide au
            // depart : on ne change que textContent a l'erreur, pour fiabiliser l'annonce
            // lecteur d'ecran (un element revele apres coup peut ne pas etre annonce).
            var inlineError = el('p', 'menu-composer__error');
            inlineError.setAttribute('role', 'alert');
            inlineError.textContent = '';
            panel.appendChild(inlineError);

            // Actions : ajouter (si tous les requis choisis) / annuler.
            var actions = el('div', 'menu-composer__actions');
            var addBtn = el('button', 'btn btn-primary menu-composer__add');
            addBtn.type = 'button';
            addBtn.textContent = 'Ajouter au panier';
            addBtn.addEventListener('click', function () {
                var allRequired = steps.filter(function (s) { return s.isRequired; })
                    .every(function (s) { return state.selections[s.id] != null; });
                if (!allRequired) {
                    inlineError.textContent = 'Choisissez toutes les options obligatoires avant d\'ajouter.';
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
            openModal(panel);
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

        // 1 : le total et le libelle du bouton suivent la saisie des quantites des
        // produits simples (les lignes configurees rafraichissent via renderCart).
        Array.prototype.forEach.call(form.querySelectorAll('.order-qty'), function (input) {
            if (configurableIds[Number(input.dataset.productId)]) {
                return; // champ desactive (route par la modale).
            }
            input.addEventListener('input', updateTotal);
            input.addEventListener('change', updateTotal);
        });

        // 7a : le numero de table n'a de sens qu'en sur place -> visible seulement quand
        // service_mode = dine_in (au comptoir ; au drive le champ n'existe pas).
        function syncServiceTag() {
            if (!serviceTagGroup) {
                return;
            }
            var dineIn = serviceMode && serviceMode.value === 'dine_in';
            if (dineIn) {
                serviceTagGroup.removeAttribute('hidden');
            } else {
                serviceTagGroup.setAttribute('hidden', '');
            }
        }
        if (serviceMode) {
            serviceMode.addEventListener('change', syncServiceTag);
        }
        syncServiceTag();

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
