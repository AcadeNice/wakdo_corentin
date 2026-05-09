/* Wakdo Admin — Vanilla JS
 * No framework dependency. Handles:
 * - User dropdown toggle (topbar)
 * - Action menu (kebab) open/close
 * - Sortable table columns (client-side)
 * - Inline table search
 * - Tab switching (catalogue)
 * - Clock display (cuisine)
 */

(function () {
    'use strict';

    /* ---- Utility ---- */
    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.from((root || document).querySelectorAll(selector));
    }

    /* ---- User dropdown (topbar) ---- */
    function initUserMenu() {
        var btn = qs('#userMenuBtn');
        var menu = qs('#userMenu');
        if (!btn || !menu) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = menu.classList.contains('open');
            closeAllDropdowns();
            if (!isOpen) {
                menu.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    }

    /* ---- Action menus (kebab per table row) ---- */
    function initActionMenus() {
        qsa('.action-menu-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var dropdown = btn.nextElementSibling;
                if (!dropdown) return;
                var isOpen = dropdown.classList.contains('open');
                closeAllDropdowns();
                if (!isOpen) {
                    dropdown.classList.add('open');
                    btn.classList.add('open');
                }
            });
        });
    }

    function closeAllDropdowns() {
        qsa('.dropdown-menu.open, .action-menu-dropdown.open').forEach(function (el) {
            el.classList.remove('open');
        });
        qsa('.action-menu-btn.open').forEach(function (el) {
            el.classList.remove('open');
        });
        var userBtn = qs('#userMenuBtn');
        if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
    }

    document.addEventListener('click', closeAllDropdowns);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllDropdowns();
    });

    /* ---- Sortable tables ---- */
    function initSortableTables() {
        qsa('table').forEach(function (table) {
            var headers = qsa('th.sortable', table);
            if (!headers.length) return;

            headers.forEach(function (th) {
                th.addEventListener('click', function () {
                    var colIndex = parseInt(th.getAttribute('data-col'), 10);
                    var currentDir = th.getAttribute('data-dir') || 'none';
                    var newDir = currentDir === 'asc' ? 'desc' : 'asc';

                    /* reset other headers */
                    headers.forEach(function (h) {
                        h.removeAttribute('data-dir');
                        h.classList.remove('sort-asc', 'sort-desc');
                    });

                    th.setAttribute('data-dir', newDir);
                    th.classList.add('sort-' + newDir);

                    sortTableByCol(table, colIndex, newDir);
                });
            });
        });
    }

    function sortTableByCol(table, colIndex, dir) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort(function (a, b) {
            var cellA = getCellText(a, colIndex);
            var cellB = getCellText(b, colIndex);

            /* detect numeric (strip currency, spaces) */
            var numA = parseFloat(cellA.replace(/[^0-9,.-]/g, '').replace(',', '.'));
            var numB = parseFloat(cellB.replace(/[^0-9,.-]/g, '').replace(',', '.'));

            var cmp;
            if (!isNaN(numA) && !isNaN(numB)) {
                cmp = numA - numB;
            } else {
                cmp = cellA.localeCompare(cellB, 'fr');
            }

            return dir === 'asc' ? cmp : -cmp;
        });

        rows.forEach(function (row) {
            tbody.appendChild(row);
        });
    }

    function getCellText(row, index) {
        var cell = row.cells[index];
        if (!cell) return '';
        return cell.textContent.trim();
    }

    /* ---- Inline table search ---- */
    function initTableSearch() {
        var searchInputs = [
            { inputId: 'orderSearch', tableId: 'ordersTable' },
            { inputId: 'productSearch', tableId: 'productTable' },
            { inputId: 'cmdSearch', tableId: 'cmdTable' },
            { inputId: 'userSearch', tableId: 'userTable' }
        ];

        searchInputs.forEach(function (pair) {
            var input = qs('#' + pair.inputId);
            var table = qs('#' + pair.tableId);
            if (!input || !table) return;

            input.addEventListener('input', function () {
                var term = input.value.trim().toLowerCase();
                var rows = qsa('tbody tr', table);
                rows.forEach(function (row) {
                    var text = row.textContent.toLowerCase();
                    row.style.display = term === '' || text.includes(term) ? '' : 'none';
                });
            });
        });
    }

    /* ---- Tabs (catalogue) ---- */
    function initTabs() {
        var tabDefs = [
            { btnId: 'tabCategories', panelId: 'panelCategories' },
            { btnId: 'tabProduits',   panelId: 'panelProduits'   },
            { btnId: 'tabMenus',      panelId: 'panelMenus'      }
        ];

        var btns   = tabDefs.map(function (d) { return qs('#' + d.btnId); }).filter(Boolean);
        var panels = tabDefs.map(function (d) { return qs('#' + d.panelId); }).filter(Boolean);

        if (!btns.length) return;

        btns.forEach(function (btn, i) {
            btn.addEventListener('click', function () {
                btns.forEach(function (b)   { b.classList.remove('active'); });
                panels.forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                if (panels[i]) panels[i].classList.add('active');
            });
        });
    }

    /* ---- Kitchen clock ---- */
    function initKitchenClock() {
        var clockEl = qs('#kitchenTime');
        if (!clockEl) return;

        function tick() {
            var now = new Date();
            var h = String(now.getHours()).padStart(2, '0');
            var m = String(now.getMinutes()).padStart(2, '0');
            var s = String(now.getSeconds()).padStart(2, '0');
            clockEl.textContent = h + ':' + m + ':' + s;
        }

        tick();
        setInterval(tick, 1000);
    }

    /* ---- Refresh button (visual feedback only — no real fetch) ---- */
    function initRefreshButtons() {
        qsa('#refreshBtn, #kitchenRefresh').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var svg = btn.querySelector('svg');
                if (svg) {
                    svg.style.transition = 'transform 0.6s';
                    svg.style.transform = 'rotate(360deg)';
                    setTimeout(function () {
                        svg.style.transition = '';
                        svg.style.transform = '';
                    }, 650);
                }
            });
        });
    }

    /* ---- Bootstrap ---- */
    function init() {
        initUserMenu();
        initActionMenus();
        initSortableTables();
        initTableSearch();
        initTabs();
        initKitchenClock();
        initRefreshButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
