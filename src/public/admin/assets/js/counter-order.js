/*
 * counter-order.js — POS tactile a tuiles (comptoir / drive, back-office).
 *
 * CSP 'self' : script externe (pas d'inline, zero handler dans le HTML). Les donnees
 * (produits commandables + leurs modificateurs, menus + slots + format + modificateurs
 * du burger) sont lues depuis deux scripts JSON inertes (#pos-products, #pos-menus,
 * type="application/json") du formulaire #counter-order-form. L'ecran imite la borne
 * client : des onglets de categories en haut, une grille de tuiles a gauche, un panneau
 * commande persistant a droite. Un tap sur une tuile de produit simple ajoute le produit
 * (qty 1) ; un tap sur un menu ou un produit a modificateurs ouvre la modale de
 * composition (slots + format + retrait / ajout d'ingredients). Le panneau commande
 * affiche les lignes (qty x nom + prix de ligne) avec +/- et retrait, le total, et un
 * bouton "Encaisser X,XX EUR".
 *
 * A la soumission, le panier est serialise en JSON dans le champ cache #items_json
 * (Request::formBody cote serveur ne garde que les scalaires, d'ou le passage par une
 * chaine JSON). Le serveur revalide la forme (RG-T18), revalide chaque modificateur
 * metier (resolveModifiers) et recalcule les prix (RG-T16) : les libelles / prix
 * affiches ici restent indicatifs, pas une source de verite.
 *
 * La logique de slots (un pas par slot, requis / optionnel, format) calque
 * page-product-menu.js (borne) ; la logique de modificateurs (cases "retirer" pour les
 * ingredients is_removable, "ajouter +X.XX EUR" pour les is_addable) calque l'UX borne ;
 * le panneau commande calque order-panel.js (lignes, stepper +/-, total). Seul le rendu
 * differe (idiome back-office, palette admin).
 *
 * Module CommonJS (admin = racine CommonJS, comme pin-modal.js) : init(doc) est
 * exporte pour les tests et auto-appele au DOMContentLoaded en production.
 */
(function () {
    'use strict';

    // SLOT_LABEL : seuls les slot_type geres deviennent une etape (l'enum DB autorise
    // aussi dessert/extra). Aligne sur page-product-menu.js (anti-perte silencieuse).
    var SLOT_LABEL = { side: 'Accompagnement', drink: 'Boisson', sauce: 'Sauce' };

    // Lit un script JSON inerte (type="application/json") par id et retourne le tableau
    // decode. Tolerant : un script absent / mal forme retombe sur un tableau vide.
    function parseJsonScript(doc, id) {
        var node = doc.getElementById(id);
        if (!node) {
            return [];
        }
        try {
            var v = JSON.parse(node.textContent || '[]');
            return Array.isArray(v) ? v : [];
        } catch (e) {
            return [];
        }
    }

    // Montant en euros formate comme le PHP number_format(.../100, 2, ',', ' ') des
    // vues : virgule decimale ET espace separateur de milliers. Aligne l'affichage
    // client sur le rendu serveur (ex. 1 234,50 EUR) pour eviter une divergence visible
    // sur les montants superieurs a 1000. Indicatif : le serveur recalcule tout (RG-T16).
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

    // Onglets de categories construits depuis les produits ET menus : une entree par
    // categorie distincte, dans l'ordre d'apparition du catalogue (deja trie par
    // categorie / display_order cote serveur). Pur ; chaque entree porte le libelle et
    // le nombre de tuiles. La cle est l'id de categorie (0 = "Autres" par defaut).
    function buildCategoryTabs(products, menus) {
        var order = [];
        var byKey = {};
        function add(row) {
            var key = Number(row.category_id) || 0;
            if (!byKey[key]) {
                byKey[key] = { id: key, name: row.category_name || 'Autres', count: 0 };
                order.push(key);
            }
            byKey[key].count += 1;
        }
        (products || []).forEach(add);
        (menus || []).forEach(add);
        return order.map(function (key) { return byKey[key]; });
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

        // Conteneurs du POS : onglets categories + grille de tuiles. Optionnels (rendu
        // degrade sans eux) -> gardes au moment d'ecrire.
        var tabsHost = doc.getElementById('pos-tabs');
        var grid = doc.getElementById('pos-grid');

        // Elements de prix (1) : valeur du total + libelle du bouton d'encaissement.
        var totalValue = doc.getElementById('order-total-value');
        var submitBtn = doc.getElementById('order-submit');

        // Region live concise (C) : un message court (total + nombre d'articles) annonce
        // a chaque mutation du panier, sans deballer toute la liste au lecteur d'ecran.
        var announce = doc.getElementById('pos-announce');

        // 7a : champ numero de table, visible seulement en sur place (toggle au mode).
        var serviceMode = doc.getElementById('service_mode');
        var serviceTagGroup = doc.getElementById('service_tag_group');

        // [{id, name, price, image, category_id, category_name, modifiers:[...]}]
        var products = parseJsonScript(doc, 'pos-products');
        // [{id, name, price_normal, price_maxi, image, category_id, category_name,
        //   burger_modifiers:[...], slots:[...]}]
        var menus = parseJsonScript(doc, 'pos-menus');

        // Index produit par id : resolution des libelles d'options de slot + acces aux
        // modificateurs proposables d'un produit a la carte.
        var productById = {};
        products.forEach(function (p) {
            productById[Number(p.id)] = p;
        });

        // Panier unifie : une liste de lignes. Chaque ligne porte un kind :
        //  - 'product' simple  : { kind, localId, productId, productName, quantity }
        //  - 'product' modifie : ... + proposable, modifiers (config par la modale)
        //  - 'menu'            : { kind, localId, menuId, menuName, format,
        //                          selections, proposable, modifiers } (quantity ajustable)
        // Le tap d'une tuile simple FUSIONNE avec une ligne simple existante (meme
        // produit) en incrementant la quantite, comme une caisse ; les lignes
        // configurees (modifiers / menu) restent distinctes (compositions differentes).
        var cartLines = [];
        var lineSeq = 0;

        // Categorie active (filtre la grille) : 1er onglet par defaut.
        var activeCategory = null;

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

        // Vrai si la ligne porte au moins un modificateur (produit personnalise).
        function hasMods(line) {
            return line.modifiers && line.modifiers.length;
        }

        /* ----------------------------------------------------------------- */
        /* Serialisation du panier -> #items_json                             */
        /* ----------------------------------------------------------------- */

        // Forme calquee sur ce qu'attend OrderRepository::resolveLine (revalide cote
        // serveur). Produits (simples ou personnalises) -> {type:'product', ...} ;
        // menus -> {type:'menu', ...}. La quantite d'un menu vaut sa quantite de ligne
        // (N menus identiques = un menu x N, facture par quantite cote serveur).
        function serialize() {
            var items = [];
            cartLines.forEach(function (line) {
                if (line.kind === 'menu') {
                    items.push({
                        type: 'menu',
                        menu_id: line.menuId,
                        quantity: line.quantity,
                        format: line.format,
                        selections: line.selections.map(function (s) {
                            return { menu_slot_id: s.slotId, product_id: s.productId };
                        }),
                        modifiers: line.modifiers.map(function (m) {
                            return { ingredient_id: m.ingredient_id, action: m.action };
                        }),
                    });
                    return;
                }
                items.push({
                    type: 'product',
                    product_id: line.productId,
                    quantity: line.quantity,
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

        // Prix d'une ligne PRODUIT : prix de base + surcout des ajouts, le tout
        // multiplie par la quantite. Indicatif (RG-T16 serveur).
        function productLineTotal(line) {
            var base = (productById[Number(line.productId)] || {}).price || 0;
            var extra = modifiersExtra(line.proposable, line.modifiers);
            return (Number(base) + extra) * Number(line.quantity || 1);
        }

        // Prix d'une ligne MENU : price_maxi si format maxi sinon price_normal, plus le
        // surcout des ajouts sur le burger, multiplie par la quantite. Les selections de
        // slot n'ajoutent rien (le prix du menu est forfaitaire cote serveur). Indicatif.
        function menuLineTotal(line) {
            var menu = menus.filter(function (m) { return Number(m.id) === Number(line.menuId); })[0] || {};
            var base = line.format === 'maxi' ? (menu.price_maxi || 0) : (menu.price_normal || 0);
            var extra = modifiersExtra(line.proposable, line.modifiers);
            return (Number(base) + extra) * Number(line.quantity || 1);
        }

        function lineTotal(line) {
            return line.kind === 'menu' ? menuLineTotal(line) : productLineTotal(line);
        }

        // Total indicatif du panier : somme des lignes. Met a jour le pied de panier, le
        // libelle du bouton ("Encaisser X,XX EUR") ET la region live concise (C) : un
        // message court "Total X EUR, N articles" tient le lecteur d'ecran informe de
        // l'essentiel a chaque mutation, sans re-annoncer toute la liste du panier.
        function updateTotal() {
            var total = cartLines.reduce(function (sum, line) { return sum + lineTotal(line); }, 0);
            var count = cartLines.reduce(function (sum, line) { return sum + Number(line.quantity || 0); }, 0);
            if (totalValue) {
                totalValue.textContent = formatEuros(total);
            }
            if (submitBtn) {
                submitBtn.textContent = 'Encaisser ' + formatEuros(total);
            }
            if (announce) {
                announce.textContent = count === 0
                    ? 'Panier vide'
                    : 'Total ' + formatEuros(total) + ', ' + count + (count > 1 ? ' articles' : ' article');
            }
        }

        /* ----------------------------------------------------------------- */
        /* Panier (panneau commande : lignes + stepper +/- + retrait)         */
        /* ----------------------------------------------------------------- */

        // Libelle d'une ligne du panneau (nom + composition recap).
        function lineLabel(line) {
            if (line.kind === 'menu') {
                var parts = [line.menuName + ' (' + (line.format === 'maxi' ? 'Maxi' : 'Normal') + ')'];
                line.selections.forEach(function (s) {
                    var p = productById[Number(s.productId)];
                    if (p) {
                        parts.push(p.name);
                    }
                });
                var text = parts.join(' - ');
                var modLabel = modifierLabel(line.proposable, line.modifiers);
                return modLabel ? (text + ' (' + modLabel + ')') : text;
            }
            var label = line.productName;
            var pm = modifierLabel(line.proposable, line.modifiers);
            return pm ? (label + ' (' + pm + ')') : label;
        }

        // Ajuste la quantite d'une ligne (delta +1 / -1). Tomber a 0 retire la ligne
        // (comme order-panel.js borne : decrementer a zero = retrait).
        function adjustQuantity(line, delta) {
            var next = Number(line.quantity || 1) + delta;
            if (next <= 0) {
                cartLines = cartLines.filter(function (l) { return l.localId !== line.localId; });
            } else {
                line.quantity = next;
            }
            renderCart();
        }

        function removeLine(line) {
            cartLines = cartLines.filter(function (l) { return l.localId !== line.localId; });
            renderCart();
        }

        // Construit une ligne du panneau : libelle + prix + stepper (-/qty/+) + retrait.
        function cartLineNode(line) {
            var li = el('li', 'order-cart__line');

            var main = el('div', 'order-cart__main');
            var label = el('span', 'order-cart__label');
            label.textContent = lineLabel(line);
            main.appendChild(label);
            var price = el('span', 'order-cart__price');
            price.textContent = formatEuros(lineTotal(line));
            main.appendChild(price);
            li.appendChild(main);

            var controls = el('div', 'order-cart__controls');

            var stepper = el('div', 'order-cart__qty');
            stepper.setAttribute('role', 'group');
            stepper.setAttribute('aria-label', 'Quantite de ' + lineLabel(line));

            var dec = el('button', 'order-cart__qty-btn');
            dec.type = 'button';
            dec.textContent = '−'; // signe moins
            dec.setAttribute('aria-label', 'Diminuer la quantite de ' + lineLabel(line));
            dec.addEventListener('click', function () { adjustQuantity(line, -1); });
            stepper.appendChild(dec);

            var qty = el('span', 'order-cart__qty-value');
            qty.textContent = String(line.quantity);
            stepper.appendChild(qty);

            var inc = el('button', 'order-cart__qty-btn');
            inc.type = 'button';
            inc.textContent = '+';
            inc.setAttribute('aria-label', 'Augmenter la quantite de ' + lineLabel(line));
            inc.addEventListener('click', function () { adjustQuantity(line, 1); });
            stepper.appendChild(inc);

            controls.appendChild(stepper);

            var removeBtn = el('button', 'btn btn-secondary order-cart__remove');
            removeBtn.type = 'button';
            removeBtn.textContent = 'Retirer';
            removeBtn.setAttribute('aria-label', 'Retirer ' + lineLabel(line) + ' de la commande');
            removeBtn.addEventListener('click', function () { removeLine(line); });
            controls.appendChild(removeBtn);

            li.appendChild(controls);
            return li;
        }

        function renderCart() {
            Array.prototype.forEach.call(cart.querySelectorAll('.order-cart__line'), function (node) {
                node.parentNode.removeChild(node);
            });

            cartLines.forEach(function (line) {
                cart.appendChild(cartLineNode(line));
            });

            if (cartEmpty) {
                cartEmpty.style.display = cartLines.length ? 'none' : '';
            }

            updateTotal();
        }

        /* ----------------------------------------------------------------- */
        /* Ajout au panier                                                    */
        /* ----------------------------------------------------------------- */

        // Tap d'une tuile produit simple (sans modificateur) : fusionne avec une ligne
        // simple existante du meme produit (increment), sinon cree une ligne qty 1.
        function addSimpleProduct(product) {
            var existing = cartLines.filter(function (l) {
                return l.kind === 'product' && l.productId === Number(product.id) && !hasMods(l);
            })[0];
            if (existing) {
                existing.quantity += 1;
            } else {
                cartLines.push({
                    kind: 'product',
                    localId: ++lineSeq,
                    productId: Number(product.id),
                    productName: product.name,
                    quantity: 1,
                    proposable: product.modifiers || [],
                    modifiers: [],
                });
            }
            renderCart();
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
        // les champs desactives / caches sont exclus). Le trap cycle sur cet ensemble.
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

            // Restaure le focus sur l'element declencheur (tuile produit / menu).
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

        // Modale d'un produit a la carte : quantite + modificateurs (retrait / ajout).
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
                // E : une saisie invalide (0 / vide / non numerique) est ramenee a 1 ; on
                // reaffiche la valeur corrigee pour que l'equipier voie ce qui sera ajoute.
                qtyInput.value = String(state.quantity);
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
                cartLines.push({
                    kind: 'product',
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

            // Modificateurs du burger support (retrait / ajout d'ingredients).
            panel.appendChild(renderModifierControls(proposable, state.selectedRemove, state.selectedAdd));

            // 7c : message inline au lieu d'un return muet quand un slot requis n'est pas
            // choisi. Le <p role=alert> reste present en permanence (non hidden), vide au
            // depart : on ne change que textContent a l'erreur, pour fiabiliser l'annonce
            // lecteur d'ecran (un element revele apres coup peut ne pas etre annonce).
            var inlineError = el('p', 'menu-composer__error');
            inlineError.setAttribute('role', 'alert');
            inlineError.textContent = '';
            panel.appendChild(inlineError);

            // Impasse : un slot requis sans aucune option resoluble rend le menu non
            // composable. On desactive l'ajout et on affiche un message clair plutot que
            // de laisser l'equipier buter sur "options obligatoires" sans pouvoir corriger.
            var deadEnd = steps.some(function (s) { return s.isRequired && !s.options.length; });

            // Actions : ajouter (si tous les requis choisis) / annuler.
            var actions = el('div', 'menu-composer__actions');
            var addBtn = el('button', 'btn btn-primary menu-composer__add');
            addBtn.type = 'button';
            addBtn.textContent = 'Ajouter au panier';
            if (deadEnd) {
                addBtn.disabled = true;
                inlineError.textContent = 'Ce menu n\'est pas composable : une option obligatoire est indisponible.';
            }
            addBtn.addEventListener('click', function () {
                if (deadEnd) {
                    return;
                }
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
                cartLines.push({
                    kind: 'menu',
                    localId: ++lineSeq,
                    menuId: Number(menu.id),
                    menuName: menu.name,
                    quantity: 1,
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
        /* Grille de tuiles + onglets categories                              */
        /* ----------------------------------------------------------------- */

        // Pastille de repli : initiale du nom sur fond colore, quand aucune image
        // exploitable n'est disponible cote back-office (image_path vide ou injoignable).
        function buildPastille(name) {
            var pastille = el('span', 'pos-tile__pastille');
            pastille.setAttribute('aria-hidden', 'true');
            var initial = (String(name || '').trim().charAt(0) || '?').toUpperCase();
            pastille.textContent = initial;
            return pastille;
        }

        // Construit une tuile. kind : 'product' | 'menu'. Le tap declenche onTap. Une
        // image n'est tentee que si image_path est non vide ; sur erreur de chargement,
        // un listener (CSP-safe, pas d'onerror inline) masque l'image et revele la
        // pastille de repli (le back-office n'a pas garantie d'image exploitable).
        function buildTile(entry, kind, priceLabel, onTap) {
            var tile = el('button', 'pos-tile');
            tile.type = 'button';

            // Une tuile qui ouvre la modale (menu ou produit a modificateurs) annonce
            // l'intention dans son nom accessible (D) et porte aria-haspopup=dialog : le
            // lecteur d'ecran sait qu'un tap ouvre une boite de dialogue de composition,
            // pas un ajout sec. Le badge visuel "Menu"/"A composer" reste decoratif.
            var opensModal = kind === 'menu' || (entry.modifiers && entry.modifiers.length);
            var intent = opensModal ? (kind === 'menu' ? ', menu a composer' : ', a composer') : '';
            tile.setAttribute('aria-label', entry.name + ', ' + priceLabel + intent);
            if (opensModal) {
                tile.setAttribute('aria-haspopup', 'dialog');
            }

            var media = el('span', 'pos-tile__media');
            var pastille = buildPastille(entry.name);
            media.appendChild(pastille);

            var src = String(entry.image || '');
            if (src !== '') {
                var img = el('img', 'pos-tile__image');
                img.src = src;
                img.alt = '';
                img.setAttribute('aria-hidden', 'true');
                img.setAttribute('loading', 'lazy');
                // CSP-safe : pas d'onerror inline. Sur echec, masque l'image (la
                // pastille dessous redevient visible).
                img.addEventListener('error', function () {
                    img.style.display = 'none';
                });
                media.appendChild(img);
            }
            tile.appendChild(media);

            var body = el('span', 'pos-tile__body');
            var nameEl = el('span', 'pos-tile__name');
            nameEl.textContent = entry.name;
            body.appendChild(nameEl);
            var priceEl = el('span', 'pos-tile__price');
            priceEl.textContent = priceLabel;
            body.appendChild(priceEl);
            tile.appendChild(body);

            // Badge visuel "Menu"/"A composer" (decoratif : l'intention est deja dans
            // l'aria-label ci-dessus ; aria-hidden evite la double annonce).
            if (opensModal) {
                var badge = el('span', 'pos-tile__badge');
                badge.setAttribute('aria-hidden', 'true');
                badge.textContent = kind === 'menu' ? 'Menu' : 'A composer';
                tile.appendChild(badge);
            }

            tile.addEventListener('click', onTap);
            return tile;
        }

        // Rend la grille pour la categorie active : produits puis menus de cette
        // categorie. Un produit simple -> ajout direct ; un produit a modificateurs ou
        // un menu -> modale.
        function renderGrid() {
            if (!grid) {
                return;
            }
            grid.textContent = '';

            var catProducts = products.filter(function (p) { return (Number(p.category_id) || 0) === activeCategory; });
            var catMenus = menus.filter(function (m) { return (Number(m.category_id) || 0) === activeCategory; });

            if (!catProducts.length && !catMenus.length) {
                var empty = el('p', 'pos__nojs');
                empty.textContent = 'Aucun produit dans cette categorie.';
                grid.appendChild(empty);
                return;
            }

            catProducts.forEach(function (product) {
                var tile = buildTile(product, 'product', formatEuros(product.price), function () {
                    if (product.modifiers && product.modifiers.length) {
                        openProductComposer(product);
                    } else {
                        addSimpleProduct(product);
                    }
                });
                grid.appendChild(tile);
            });

            catMenus.forEach(function (menu) {
                var label = 'Normal ' + formatEuros(menu.price_normal) + ' / Maxi ' + formatEuros(menu.price_maxi);
                var tile = buildTile(menu, 'menu', label, function () {
                    openComposer(menu);
                });
                grid.appendChild(tile);
            });
        }

        // Boutons d'onglet (references stables), construits UNE fois au demarrage. On les
        // garde pour MUTER l'etat actif (A) plutot que de reconstruire la barre au clic :
        // reconstruire detruisait le bouton focalise et faisait retomber le focus sur body.
        var tabButtons = [];

        // Bascule la categorie active : mute les boutons existants (classe is-active +
        // aria-selected + roving tabindex), met a jour activeCategory et le tabpanel
        // (aria-labelledby vers l'onglet actif), rerend la grille. Si moveFocus, pose le
        // focus sur l'onglet actif (navigation clavier : le focus suit la selection).
        function setActiveCategory(catId, moveFocus) {
            activeCategory = catId;
            tabButtons.forEach(function (btn) {
                var selected = Number(btn.dataset.categoryId) === Number(catId);
                btn.classList.toggle('is-active', selected);
                btn.setAttribute('aria-selected', selected ? 'true' : 'false');
                // Roving tabindex (B) : seul l'onglet actif est dans l'ordre de tabulation ;
                // les autres sont atteints par les fleches une fois la barre focalisee.
                btn.tabIndex = selected ? 0 : -1;
                if (selected) {
                    // Le tabpanel (grille) est libelle par l'onglet actif (B).
                    if (grid) {
                        grid.setAttribute('aria-labelledby', btn.id);
                    }
                    if (moveFocus && typeof btn.focus === 'function') {
                        btn.focus();
                    }
                }
            });
            renderGrid();
        }

        // Navigation clavier WAI-ARIA tablist (B) : Fleche gauche/droite (cycliques) +
        // Home/Fin deplacent le focus ET activent l'onglet (le focus suit la selection).
        function onTabsKeydown(event) {
            var idx = tabButtons.indexOf(event.target);
            if (idx < 0 || !tabButtons.length) {
                return;
            }
            var next = null;
            var key = event.key;
            if (key === 'ArrowRight' || key === 'ArrowDown') {
                next = (idx + 1) % tabButtons.length;
            } else if (key === 'ArrowLeft' || key === 'ArrowUp') {
                next = (idx - 1 + tabButtons.length) % tabButtons.length;
            } else if (key === 'Home') {
                next = 0;
            } else if (key === 'End') {
                next = tabButtons.length - 1;
            }
            if (next === null) {
                return;
            }
            event.preventDefault();
            setActiveCategory(Number(tabButtons[next].dataset.categoryId), true);
        }

        // Construit la barre d'onglets UNE fois (A) et cable clic + clavier.
        function buildTabs() {
            if (!tabsHost) {
                return;
            }
            tabsHost.textContent = '';
            tabButtons = [];
            var tabs = buildCategoryTabs(products, menus);
            if (!tabs.length) {
                return;
            }
            if (activeCategory === null) {
                activeCategory = tabs[0].id;
            }

            tabs.forEach(function (tab, i) {
                var selected = tab.id === activeCategory;
                var btn = el('button', 'pos__tab' + (selected ? ' is-active' : ''));
                btn.type = 'button';
                btn.id = 'pos-tab-' + tab.id;
                btn.dataset.categoryId = String(tab.id);
                btn.setAttribute('role', 'tab');
                btn.setAttribute('aria-selected', selected ? 'true' : 'false');
                // aria-controls relie l'onglet au tabpanel unique (la grille filtree, B).
                if (grid && grid.id) {
                    btn.setAttribute('aria-controls', grid.id);
                }
                btn.tabIndex = selected ? 0 : -1;
                btn.textContent = tab.name;
                btn.addEventListener('click', function () {
                    setActiveCategory(tab.id, false);
                });
                tabButtons.push(btn);
                tabsHost.appendChild(btn);
            });
            tabsHost.addEventListener('keydown', onTabsKeydown);

            // Pose le libelle initial du tabpanel sur l'onglet actif.
            if (grid) {
                grid.setAttribute('aria-labelledby', 'pos-tab-' + activeCategory);
            }
        }

        /* ----------------------------------------------------------------- */
        /* Cablage                                                            */
        /* ----------------------------------------------------------------- */

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

        buildTabs();
        renderGrid();
        renderCart();
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init, composerSteps: composerSteps, buildCategoryTabs: buildCategoryTabs };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
