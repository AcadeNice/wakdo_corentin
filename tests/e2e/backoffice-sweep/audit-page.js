// Mesures faites DANS la page (page.evaluate) pour le balayage du back-office.
//
// Chaque fonction exportee est envoyee telle quelle au navigateur par Playwright :
// elle doit donc rester autonome (aucune variable du module, aucun require). Les
// aides communes sont redefinies dans chaque fonction pour cette raison.
//
// Coordonnees : getBoundingClientRect (fenetre). Pour comparer deux elements qui
// defilent dans des zones differentes, on ramene chaque boite a la partie visible de
// sa zone ; pour deux elements de la meme zone, la position relative ne depend pas du
// defilement, on compare donc les boites telles quelles.

/**
 * Mesures de mise en page d'une page a la largeur courante. Renvoie un objet
 * { verification: { ok, details: [...] } } et laisse dans window.__sweepMarks les
 * elements fautifs de chaque verification (pour la capture d'echec).
 */
function auditLayout(options) {
  const TOL = 1;
  const ALIGN_TOL = 2;
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const marks = {};
  window.__sweepMarks = marks;

  const INTERACTIVE = [
    'a[href]', 'button', 'input:not([type="hidden"])', 'select', 'textarea', 'summary',
    '[role="button"]', '[role="link"]', '[role="tab"]', '[role="checkbox"]', '[role="menuitem"]',
    '[tabindex]:not([tabindex="-1"])',
  ].join(',');

  function describe(el) {
    if (!el || !el.tagName) return String(el);
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    const cls = (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).slice(0, 3);
    if (cls.length) s += '.' + cls.join('.');
    let txt = el.getAttribute('aria-label') || '';
    if (!txt) txt = (el.innerText || '').trim();
    if (!txt && 'value' in el && el.type !== 'password') txt = String(el.value || '');
    if (!txt) txt = el.getAttribute('name') || el.getAttribute('href') || '';
    txt = txt.replace(/\s+/g, ' ').trim().slice(0, 50);
    if (txt) s += ' "' + txt + '"';
    return s;
  }
  function mark(check, el, text) {
    (marks[check] = marks[check] || []).push(el);
    return text;
  }
  function visible(el) {
    if (!el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) return false;
    if (el.closest('[aria-hidden="true"], [inert]')) return false;
    const r = el.getBoundingClientRect();
    if (r.width <= 1 || r.height <= 1) return false;
    // Hors ecran volontaire (lien d'evitement a top:-1000px, etc.).
    if (r.bottom <= 0 || r.right <= 0) return false;
    return true;
  }
  function box(r) { return { left: r.left, top: r.top, right: r.right, bottom: r.bottom }; }
  function inter(a, b) {
    const w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
    const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
    return { w, h };
  }
  function clipTo(r, c) {
    return {
      left: Math.max(r.left, c.left), top: Math.max(r.top, c.top),
      right: Math.min(r.right, c.right), bottom: Math.min(r.bottom, c.bottom),
    };
  }
  function empty(r) { return r.right - r.left <= TOL || r.bottom - r.top <= TOL; }
  function clientBox(el) {
    const r = el.getBoundingClientRect();
    return {
      left: r.left + el.clientLeft, top: r.top + el.clientTop,
      right: r.left + el.clientLeft + el.clientWidth, bottom: r.top + el.clientTop + el.clientHeight,
    };
  }
  const root = document.scrollingElement || document.documentElement;
  function isYScroller(el) {
    if (el === root) return true;
    const cs = getComputedStyle(el);
    return (cs.overflowY === 'auto' || cs.overflowY === 'scroll') && el.scrollHeight > el.clientHeight + 1;
  }
  function isXScroller(el) {
    const cs = getComputedStyle(el);
    return (cs.overflowX === 'auto' || cs.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1;
  }
  function scrollerOf(el) {
    for (let a = el.parentElement; a; a = a.parentElement) {
      if (a === document.body || a === document.documentElement) break;
      if (isYScroller(a)) return a;
    }
    return root;
  }
  function viewOf(scroller) {
    if (scroller === root) return { left: 0, top: 0, right: vw, bottom: vh };
    return clientBox(scroller);
  }
  // Partie visible d'un element : rognee par les ancetres qui coupent (overflow non
  // visible) jusqu'a sa zone de defilement exclue.
  function visibleRect(el, scroller) {
    let r = box(el.getBoundingClientRect());
    for (let a = el.parentElement; a && a !== scroller && a !== document.body; a = a.parentElement) {
      const cs = getComputedStyle(a);
      if (cs.position === 'fixed') break;
      const cb = clientBox(a);
      if (cs.overflowX !== 'visible') r = { ...r, left: Math.max(r.left, cb.left), right: Math.min(r.right, cb.right) };
      if (cs.overflowY !== 'visible') r = { ...r, top: Math.max(r.top, cb.top), bottom: Math.min(r.bottom, cb.bottom) };
    }
    return r;
  }
  function fixedAncestor(el) {
    for (let a = el; a && a !== document.body; a = a.parentElement) {
      if (getComputedStyle(a).position === 'fixed') return a;
    }
    return null;
  }
  // Conteneurs prevus pour defiler lateralement (tableaux larges, bande de menu sur
  // telephone, barre d'onglets de caisse) : leur defilement n'est pas un defaut.
  function allowedXScroller(el) {
    return el.matches('.table-wrapper, nav.sidebar, .pos__tabs') || !!el.querySelector(':scope > table');
  }
  function inAllowedXScroller(el) {
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
      if (isXScroller(a) && allowedXScroller(a)) return true;
    }
    return false;
  }
  function ownText(el) {
    let t = '';
    for (const n of el.childNodes) if (n.nodeType === 3) t += n.nodeValue;
    return t.replace(/\s+/g, ' ').trim();
  }

  const all = Array.from(document.querySelectorAll(INTERACTIVE)).filter(visible);
  const items = all.map((el) => {
    const scroller = scrollerOf(el);
    return { el, scroller, fixed: fixedAncestor(el), r: box(el.getBoundingClientRect()), vr: visibleRect(el, scroller) };
  }).filter((i) => !empty(i.vr));
  const result = {};

  // 1. Chevauchement entre elements interactifs.
  {
    const details = [];
    for (let i = 0; i < items.length; i++) {
      for (let j = i + 1; j < items.length; j++) {
        const a = items[i]; const b = items[j];
        if (a.fixed || b.fixed) continue;
        if (a.el.contains(b.el) || b.el.contains(a.el)) continue;
        const la = a.el.closest('label'); const lb = b.el.closest('label');
        if (la && la === lb) continue;
        let ra = a.vr; let rb = b.vr;
        if (a.scroller !== b.scroller) {
          ra = clipTo(ra, viewOf(a.scroller));
          rb = clipTo(rb, viewOf(b.scroller));
        }
        const x = inter(ra, rb);
        if (x.w > TOL && x.h > TOL) {
          mark('chevauchement', a.el); mark('chevauchement', b.el);
          details.push(describe(a.el) + ' recouvre ' + describe(b.el) + ' (' + Math.round(x.w) + 'x' + Math.round(x.h) + ' px)');
        }
      }
    }
    result['chevauchement'] = { ok: details.length === 0, details };
  }

  // 2. Element fixe ou flottant qui masque un element interactif, quel que soit le
  //    defilement : on cherche une position de defilement ou l'element est entierement
  //    visible dans sa zone sans etre sous un element fixe.
  {
    const floats = Array.from(document.querySelectorAll('body *')).filter((el) => {
      if (getComputedStyle(el).position !== 'fixed') return false;
      if (!visible(el)) return false;
      const r = el.getBoundingClientRect();
      // Voile plein ecran (fenetre modale ouverte) : il masque tout volontairement.
      if (r.width >= vw - 2 && r.height >= vh - 2) return false;
      return true;
    }).map((el) => ({ el, r: box(el.getBoundingClientRect()) }));
    const details = [];
    for (const it of items) {
      if (it.fixed) continue;
      const covering = floats.filter((f) => !f.el.contains(it.el) && !it.el.contains(f.el)
        && f.r.left < it.r.right - TOL && f.r.right > it.r.left + TOL);
      if (!covering.length) continue;
      const s = it.scroller;
      const view = viewOf(s);
      const scrollTop = s === root ? window.scrollY : s.scrollTop;
      const maxScroll = Math.max(0, (s === root ? root.scrollHeight - vh : s.scrollHeight - s.clientHeight));
      const top = it.r.top + scrollTop; const bottom = it.r.bottom + scrollTop;
      if (bottom - top > view.bottom - view.top) continue;
      let lo = Math.max(0, bottom - view.bottom); let hi = Math.min(maxScroll, top - view.top);
      if (lo > hi + 0.5) continue; // jamais entierement visible (autre defaut, pas un masquage)
      let segments = [[lo, Math.max(lo, hi)]];
      for (const f of covering) {
        const fl = top - f.r.bottom + TOL; const fh = bottom - f.r.top - TOL;
        const next = [];
        for (const [a, b] of segments) {
          if (fh <= a || fl >= b) { next.push([a, b]); continue; }
          if (fl > a) next.push([a, fl]);
          if (fh < b) next.push([fh, b]);
        }
        segments = next;
      }
      const single = lo >= hi - 0.5;
      const free = single ? segments.length > 0 : segments.some(([a, b]) => b - a >= 0.5);
      if (!free) {
        mark('masquage fixe', it.el);
        details.push(describe(it.el) + ' reste sous ' + covering.map((f) => describe(f.el)).join(', ') + ' quel que soit le défilement');
      }
    }
    result['masquage fixe'] = { ok: details.length === 0, details };
  }

  // 3. Defilement horizontal de la page (document et zone de contenu), hors conteneurs
  //    de tableau prevus pour defiler.
  {
    const details = [];
    if (root.scrollWidth > root.clientWidth + 1) {
      details.push('document : ' + root.scrollWidth + ' px de large pour ' + root.clientWidth + ' px visibles');
    }
    for (const el of document.querySelectorAll('body *')) {
      if (!isXScroller(el) || allowedXScroller(el)) continue;
      if (!el.checkVisibility()) continue;
      // Liste d'options a defilement vertical dont la barre prend un peu de largeur :
      // on ne retient que les vrais debordements lateraux (plus de 1 px de contenu cache).
      mark('défilement horizontal', el);
      details.push(describe(el) + ' défile latéralement : ' + el.scrollWidth + ' px pour ' + el.clientWidth + ' px visibles');
    }
    result['défilement horizontal'] = { ok: details.length === 0, details };
  }

  // Elements porteurs de texte (feuilles) + interactifs, pour les verifications 4 a 6.
  const texty = Array.from(document.querySelectorAll('body *')).filter((el) => {
    if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'OPTION'].includes(el.tagName)) return false;
    return ownText(el) !== '' && visible(el);
  });

  // 4. Sortie de conteneur ou de la fenetre.
  {
    const details = [];
    const seen = new Set();
    const candidates = Array.from(new Set([...items.map((i) => i.el), ...texty]))
      .sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));
    const outOfWindow = [];
    function boxed(el) {
      const cs = getComputedStyle(el);
      if (cs.display === 'inline' || cs.display === 'contents') return false;
      const bg = cs.backgroundColor;
      const hasBg = bg && bg !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(bg);
      const hasBorder = ['Top', 'Right', 'Bottom', 'Left'].some((s) => parseFloat(cs['border' + s + 'Width']) > 0);
      const r = el.getBoundingClientRect();
      return (hasBg || hasBorder || cs.boxShadow !== 'none') && r.width >= 40 && r.height >= 16;
    }
    for (const el of candidates) {
      if (fixedAncestor(el)) continue;
      const cs = getComputedStyle(el);
      if (cs.position === 'absolute') continue;
      const r = el.getBoundingClientRect();
      // Fenetre : un element qui depasse a droite ou a gauche, hors zone prevue pour
      // defiler lateralement, est coupe ou inatteignable.
      if ((r.right > vw + TOL || r.left < -TOL) && !inAllowedXScroller(el)) {
        // Seul l'element le plus englobant est signale (une tuile, pas son nom et son prix).
        if (outOfWindow.some((o) => o.contains(el))) continue;
        outOfWindow.push(el);
        mark('sortie de conteneur', el);
        details.push(describe(el) + ' sort de la fenêtre (' + Math.round(r.left) + ' à ' + Math.round(r.right) + ' px pour ' + vw + ' px)');
        continue;
      }
      // Conteneur encadre le plus proche (fond, bordure ou ombre).
      let container = null;
      for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
        if (isYScroller(a) || isXScroller(a)) break;
        if (getComputedStyle(a).position === 'absolute') break;
        if (boxed(a)) { container = a; break; }
      }
      if (!container) continue;
      const c = container.getBoundingClientRect();
      const out = Math.max(c.left - r.left, r.right - c.right, c.top - r.top, r.bottom - c.bottom);
      if (out > TOL) {
        const key = describe(el) + '|' + describe(container);
        if (seen.has(key)) continue;
        seen.add(key);
        mark('sortie de conteneur', el);
        details.push(describe(el) + ' dépasse de ' + Math.round(out) + ' px de son cadre ' + describe(container));
      }
    }
    result['sortie de conteneur'] = { ok: details.length === 0, details };
  }

  // 5. Texte coupe : element a overflow cache dont le contenu depasse, ou texte d'un
  //    bouton / lien / libelle qui deborde de sa boite.
  {
    const details = [];
    for (const el of texty) {
      const cs = getComputedStyle(el);
      const clipsX = cs.overflowX === 'hidden' || cs.overflowX === 'clip' || cs.textOverflow === 'ellipsis';
      const clipsY = cs.overflowY === 'hidden' || cs.overflowY === 'clip';
      if (clipsX && el.scrollWidth > el.clientWidth + TOL) {
        mark('texte coupé', el);
        details.push(describe(el) + ' coupé en largeur (' + el.scrollWidth + ' px de texte pour ' + el.clientWidth + ' px)');
        continue;
      }
      if (clipsY && el.scrollHeight > el.clientHeight + TOL && el.clientHeight > 0) {
        mark('texte coupé', el);
        details.push(describe(el) + ' coupé en hauteur (' + el.scrollHeight + ' px pour ' + el.clientHeight + ' px)');
        continue;
      }
      if (el.matches('button, a.btn, label, .form-label, .pill, th, .pos-tile__name, .pos__tab, .sidebar-item')) {
        // Texte seul (les champs imbriques dans un libelle ont leur propre boite).
        const rects = [];
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
        for (let n = walker.nextNode(); n; n = walker.nextNode()) {
          if (!n.nodeValue.trim() || n.parentElement.closest('select, option, textarea, button, input') !== el.closest('select, option, textarea, button, input')) continue;
          const range = document.createRange();
          range.selectNodeContents(n);
          for (const q of range.getClientRects()) if (q.width > 0 && q.height > 0) rects.push(q);
        }
        if (!rects.length) continue;
        const tr = {
          left: Math.min(...rects.map((q) => q.left)), right: Math.max(...rects.map((q) => q.right)),
          top: Math.min(...rects.map((q) => q.top)), bottom: Math.max(...rects.map((q) => q.bottom)),
        };
        const r = el.getBoundingClientRect();
        const spill = Math.max(r.left - tr.left, tr.right - r.right, r.top - tr.top, tr.bottom - r.bottom);
        if (spill > TOL + 1) {
          mark('texte coupé', el);
          details.push(describe(el) + ' : le texte déborde de sa boîte de ' + Math.round(spill) + ' px');
        }
      }
    }
    result['texte coupé'] = { ok: details.length === 0, details };
  }

  // 6. Cibles tactiles (WCAG 2.2 critere 2.5.8, 24 x 24 px) et exceptions du critere.
  {
    const details = [];
    const exceptions = [];
    const small = items.filter((i) => i.r.right - i.r.left < 24 - 0.5 || i.r.bottom - i.r.top < 24 - 0.5);
    function center(r) { return { x: (r.left + r.right) / 2, y: (r.top + r.bottom) / 2 }; }
    function distToRect(p, r) {
      const dx = Math.max(r.left - p.x, 0, p.x - r.right);
      const dy = Math.max(r.top - p.y, 0, p.y - r.bottom);
      return Math.hypot(dx, dy);
    }
    for (const it of small) {
      const el = it.el;
      const w = Math.round(it.r.right - it.r.left); const h = Math.round(it.r.bottom - it.r.top);
      const cs = getComputedStyle(el);
      const size = w + 'x' + h + ' px';
      if (el.tagName === 'A' && cs.display === 'inline') {
        const parent = el.parentElement;
        const parentText = parent ? (parent.innerText || '').trim() : '';
        const ownLinkText = (el.innerText || '').trim();
        if (parentText.length > ownLinkText.length + 3) {
          exceptions.push(describe(el) + ' (' + size + ') : lien dans une phrase, exception « en ligne »');
          continue;
        }
      }
      if (el.matches('input[type="checkbox"], input[type="radio"]') && cs.appearance !== 'none') {
        const lab = el.closest('label') || (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]'));
        const lr = lab ? lab.getBoundingClientRect() : null;
        const labSize = lr ? Math.round(lr.width) + 'x' + Math.round(lr.height) + ' px' : 'aucun';
        exceptions.push(describe(el) + ' (' + size + ') : case native dessinée par le navigateur, exception « agent utilisateur » ; libellé cliquable ' + labSize);
        continue;
      }
      // Exception d'espacement : un disque de 24 px centre sur la cible ne touche aucune
      // autre cible, ni le disque d'une autre petite cible.
      const c = center(it.r);
      let spaced = true;
      for (const o of items) {
        if (o === it || o.el.contains(el) || el.contains(o.el)) continue;
        const isSmall = small.includes(o);
        if (distToRect(c, o.r) < 12) { spaced = false; break; }
        if (isSmall) {
          const oc = center(o.r);
          if (Math.hypot(oc.x - c.x, oc.y - c.y) < 24) { spaced = false; break; }
        }
      }
      if (spaced) {
        exceptions.push(describe(el) + ' (' + size + ') : espacement suffisant, exception « espacement »');
        continue;
      }
      mark('cible tactile', el);
      details.push(describe(el) + ' mesure ' + size + ' et touche une cible voisine');
    }
    result['cible tactile'] = { ok: details.length === 0, details, exceptions };
  }

  // 7. Alignement : formulaires (bords gauches libelles/champs), colonnes de tableau,
  //    boutons d'une meme barre.
  {
    const details = [];
    // 7a. Formulaires : dans un meme groupe (parent commun des .form-group), les bords
    //     gauches des libelles et des champs doivent coincider. Un ecart de 3 a 24 px est
    //     un defaut d'alignement ; au-dela, c'est une autre colonne ou un retrait voulu.
    const fields = Array.from(document.querySelectorAll('.form-group > .form-label, .form-group > label.form-label, .form-group > .form-input, .form-group > input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), .form-group > select, .form-group > textarea'))
      .filter((el) => visible(el) && !el.closest('table') && !el.querySelector('input[type="checkbox"], input[type="radio"]'));
    const groups = new Map();
    for (const el of fields) {
      const g = el.closest('.form-group').parentElement;
      if (!groups.has(g)) groups.set(g, []);
      groups.get(g).push(el);
    }
    for (const [, els] of groups) {
      const lefts = els.map((el) => ({ el, left: el.getBoundingClientRect().left }));
      const reported = new Set();
      for (let i = 0; i < lefts.length; i++) {
        for (let j = i + 1; j < lefts.length; j++) {
          const d = Math.abs(lefts[i].left - lefts[j].left);
          if (d > ALIGN_TOL && d <= 24) {
            const key = describe(lefts[i].el) + '|' + describe(lefts[j].el);
            if (reported.has(key)) continue;
            reported.add(key);
            mark('alignement', lefts[i].el); mark('alignement', lefts[j].el);
            details.push('formulaire : ' + describe(lefts[i].el) + ' et ' + describe(lefts[j].el) + ' décalés de ' + Math.round(d) + ' px à gauche');
          }
        }
      }
    }
    // 7b. Colonnes de tableau : le contenu de chaque colonne (en-tete compris) suit un
    //     meme bord (gauche, droit) ou un meme axe (centre).
    for (const table of document.querySelectorAll('table')) {
      if (!visible(table)) continue;
      const rows = Array.from(table.rows).filter((row) => visible(row) && !Array.from(row.cells).some((c) => c.colSpan > 1));
      if (rows.length < 2) continue;
      const cols = Math.max(...rows.map((row) => row.cells.length));
      for (let c = 0; c < cols; c++) {
        const boxes = [];
        for (const row of rows) {
          const cell = row.cells[c];
          if (!cell || (cell.innerText || '').trim() === '') continue;
          const range = document.createRange();
          range.selectNodeContents(cell);
          const rects = Array.from(range.getClientRects()).filter((r) => r.width > 0 && r.height > 0);
          if (!rects.length) continue;
          boxes.push({ cell, left: Math.min(...rects.map((r) => r.left)), right: Math.max(...rects.map((r) => r.right)) });
        }
        if (boxes.length < 2) continue;
        const spread = (k) => Math.max(...boxes.map((b) => b[k])) - Math.min(...boxes.map((b) => b[k]));
        const centers = boxes.map((b) => (b.left + b.right) / 2);
        const centerSpread = Math.max(...centers) - Math.min(...centers);
        if (spread('left') > ALIGN_TOL && spread('right') > ALIGN_TOL && centerSpread > ALIGN_TOL) {
          const head = table.rows[0] && table.rows[0].cells[c] ? (table.rows[0].cells[c].innerText || '').trim() : '';
          mark('alignement', boxes[0].cell);
          details.push('tableau : colonne ' + (c + 1) + (head ? ' « ' + head.slice(0, 30) + ' »' : '') + ' sans bord commun (écart gauche ' + Math.round(spread('left')) + ' px, droit ' + Math.round(spread('right')) + ' px)');
        }
      }
    }
    // 7c. Barres de boutons : les boutons d'une meme barre, sur une meme ligne, partagent
    //     leurs bords haut et bas.
    const buttons = items.filter((i) => !i.fixed && i.el.matches('button, a.btn, input[type="submit"], input[type="button"]')).map((i) => i.el);
    const bars = new Map();
    for (const el of buttons) {
      const bar = el.closest('[class*="actions"], [class*="footer"], [class*="-foot"], td, .page-header, .toolbar');
      if (!bar) continue;
      if (!bars.has(bar)) bars.set(bar, []);
      bars.get(bar).push(el);
    }
    for (const [bar, els] of bars) {
      if (els.length < 2) continue;
      const rs = els.map((el) => ({ el, r: el.getBoundingClientRect() }));
      const reported = new Set();
      for (let i = 0; i < rs.length; i++) {
        for (let j = i + 1; j < rs.length; j++) {
          const a = rs[i].r; const b = rs[j].r;
          const overlapY = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
          if (overlapY < Math.min(a.height, b.height) * 0.5) continue; // pas sur la meme ligne
          // Axe central commun : deux boutons de hauteurs differentes centres sur la meme
          // ligne restent alignes ; un decalage de l'axe ne l'est pas.
          const dCenter = Math.abs((a.top + a.bottom) / 2 - (b.top + b.bottom) / 2);
          const dTop = Math.abs(a.top - b.top); const dBottom = Math.abs(a.bottom - b.bottom);
          if (dCenter > ALIGN_TOL) {
            const key = describe(bar);
            if (reported.has(key)) continue;
            reported.add(key);
            mark('alignement', rs[i].el); mark('alignement', rs[j].el);
            details.push('barre ' + describe(bar).slice(0, 60) + ' : ' + describe(rs[i].el) + ' et ' + describe(rs[j].el) + ' décalés (axe ' + Math.round(dCenter) + ' px, haut ' + Math.round(dTop) + ' px, bas ' + Math.round(dBottom) + ' px)');
          }
        }
      }
    }
    result['alignement'] = { ok: details.length === 0, details };
  }

  // 8. Echelle de police : valeurs reellement utilisees, comparees a celles declarees
  //    dans les feuilles de style de la page.
  {
    const rootPx = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
    const scale = new Set([Math.round(rootPx * 100) / 100]);
    function resolve(v) {
      if (!v) return null;
      v = v.trim();
      const vm = v.match(/^var\((--[\w-]+)/);
      if (vm) v = getComputedStyle(document.documentElement).getPropertyValue(vm[1]).trim();
      let m = v.match(/^([\d.]+)px$/);
      if (m) return parseFloat(m[1]);
      m = v.match(/^([\d.]+)rem$/);
      if (m) return parseFloat(m[1]) * rootPx;
      return null;
    }
    function walk(rules) {
      for (const rule of rules) {
        if (rule.style) {
          const px = resolve(rule.style.getPropertyValue('font-size'));
          if (px) scale.add(Math.round(px * 100) / 100);
        }
        if (rule.cssRules) walk(rule.cssRules);
      }
    }
    for (const sheet of document.styleSheets) {
      try { walk(sheet.cssRules); } catch (e) { /* feuille d'une autre origine */ }
    }
    const used = new Map();
    for (const el of texty) {
      const px = Math.round(parseFloat(getComputedStyle(el).fontSize) * 100) / 100;
      if (!used.has(px)) used.set(px, []);
      used.get(px).push(el);
    }
    const scaleList = Array.from(scale).sort((a, b) => a - b);
    const onScale = (px) => scaleList.some((s) => Math.abs(s - px) <= 0.25);
    const details = [];
    const histogram = Array.from(used.entries()).sort((a, b) => a[0] - b[0]).map(([px, els]) => px + ' px x' + els.length);
    const isolated = [];
    for (const [px, els] of used) {
      if (!onScale(px)) {
        els.slice(0, 5).forEach((el) => mark('échelle de police', el));
        details.push(px + ' px hors échelle (' + els.length + ' élément(s), ex. ' + els.slice(0, 3).map(describe).join(' ; ') + ')');
      } else if (els.length === 1) {
        isolated.push(px + ' px (' + describe(els[0]) + ')');
      }
    }
    result['échelle de police'] = { ok: details.length === 0, details, histogram, isolated, scale: scaleList };
  }

  return result;
}

/**
 * Contraste et presence des messages (etats vides, erreurs, confirmations).
 * Renvoie { ok, details, mesures } ; seuil 4,5:1 sur le texte du message.
 */
function auditMessages() {
  const marks = window.__sweepMarks || (window.__sweepMarks = {});
  const SEL = '.flash, .flash-error, .form-error, [role="alert"], [role="status"], .alert, .admin-empty, .stock-empty, .order-cart__empty, .slot-options-empty, .pos__nojs, .empty-state, [data-pm-error], [data-threshold-error]';
  function describe(el) {
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    const cls = (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
    if (cls.length) s += '.' + cls.join('.');
    const t = (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 60);
    return t ? s + ' "' + t + '"' : s;
  }
  function parse(c) {
    const m = c.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  }
  function over(top, bottom) {
    const a = top.a + bottom.a * (1 - top.a);
    if (a === 0) return { r: 0, g: 0, b: 0, a: 0 };
    return {
      r: (top.r * top.a + bottom.r * bottom.a * (1 - top.a)) / a,
      g: (top.g * top.a + bottom.g * bottom.a * (1 - top.a)) / a,
      b: (top.b * top.a + bottom.b * bottom.a * (1 - top.a)) / a,
      a,
    };
  }
  function lum(c) {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
  }
  function background(el) {
    const layers = [];
    for (let a = el; a; a = a.parentElement) {
      const cs = getComputedStyle(a);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') return { image: true };
      const c = parse(cs.backgroundColor);
      if (c && c.a > 0) {
        layers.push(c);
        if (c.a >= 1) break;
      }
    }
    let bg = { r: 255, g: 255, b: 255, a: 1 };
    for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
    return { color: bg };
  }
  const details = [];
  const mesures = [];
  for (const el of document.querySelectorAll(SEL)) {
    if (!el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
    const r = el.getBoundingClientRect();
    if (r.width <= 1 || r.height <= 1) continue;
    const text = (el.innerText || '').trim();
    if (!text) continue;
    const cs = getComputedStyle(el);
    const bg = background(el);
    if (bg.image) {
      mesures.push(describe(el) + ' : fond en image, contraste non mesurable');
      continue;
    }
    const fg = over(parse(cs.color), bg.color);
    const l1 = lum(fg); const l2 = lum(bg.color);
    const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const px = parseFloat(cs.fontSize);
    mesures.push(describe(el) + ' : ' + ratio.toFixed(2) + ':1, ' + px + ' px');
    if (ratio < 4.5) {
      (marks['messages (contraste)'] = marks['messages (contraste)'] || []).push(el);
      details.push(describe(el) + ' : contraste ' + ratio.toFixed(2) + ':1 (minimum 4,5:1)');
    }
  }
  return { ok: details.length === 0, details, mesures };
}

/**
 * Texte technique brut visible a l'ecran. codes : jetons techniques connus (codes
 * d'enum, codes de role, references de categorie) qui ne doivent jamais apparaitre
 * seuls dans un noeud de texte.
 */
function auditTechnicalText(codes) {
  const marks = window.__sweepMarks || (window.__sweepMarks = {});
  const found = [];
  const patterns = [
    [/\bArray\b/, 'mot « Array » (tableau PHP converti en texte)'],
    [/\[object Object\]/, '[object Object]'],
    [/\b(undefined|NaN)\b/, 'valeur JavaScript brute'],
    [/(^|\s)null(\s|$)/, 'valeur null brute'],
    [/Requ[eê]te invalide/i, 'réponse d\'erreur brute « Requête invalide »'],
    [/(Fatal error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught|Parse error)/, 'trace ou alerte PHP'],
    [/\bon line \d+\b|\.php\b/, 'chemin de fichier PHP'],
    [/INTERNAL_ERROR|Internal server error/i, 'erreur interne brute'],
    [/\b(product|menu|category|ingredient|stock|order|user|role|stats)\.(create|read|update|delete|manage|count|deliver|cancel|deactivate)\b/, 'code de permission'],
    [/\b[a-z]+(_[a-z]+)+\b/, 'identifiant technique (snake_case)'],
    [/(^|\s)\/(admin|counter|drive|kitchen|api)\/[\w/{}.-]*/, 'chemin d\'URL interne'],
  ];
  const codeSet = new Set(codes);
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const seen = new Set();
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const el = n.parentElement;
    if (!el || ['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE'].includes(el.tagName)) continue;
    const raw = n.nodeValue.replace(/\s+/g, ' ').trim();
    if (!raw) continue;
    if (!el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
    const r = el.getBoundingClientRect();
    if (r.width <= 1 || r.height <= 1) continue;
    // Les adresses e-mail sont des donnees legitimes (liste des comptes).
    const text = raw.replace(/[\w.+-]+@[\w.-]+/g, '');
    const hits = [];
    for (const [re, label] of patterns) if (re.test(text)) hits.push(label + ' : « ' + (text.match(re) || [''])[0].trim() + ' »');
    if (codeSet.has(text.trim())) hits.push('code technique affiché seul : « ' + text.trim() + ' »');
    for (const h of hits) {
      const key = h + '|' + el.tagName;
      if (seen.has(key)) continue;
      seen.add(key);
      (marks['texte technique'] = marks['texte technique'] || []).push(el);
      found.push(h + ' dans ' + el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(/\s+/)[0] : ''));
    }
  }
  return { ok: found.length === 0, details: found };
}

/** Installe l'enregistrement des prises de focus (ordre de tabulation, style au focus). */
function installFocusRecorder() {
  window.__sweepFocus = [];
  // Transitions coupees : le style de focus est lu tout de suite, pas en cours
  // d'animation (une bordure qui change en 0,12 s serait lue avant son changement).
  const still = document.createElement('style');
  still.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; }';
  document.head.appendChild(still);
  function scrollsY(a) {
    const cs = getComputedStyle(a);
    return (cs.overflowY === 'auto' || cs.overflowY === 'scroll') && a.scrollHeight > a.clientHeight + 1;
  }
  // Position dans la page, independante du defilement de la zone de contenu. Un element
  // pris dans une liste a defilement interne est represente par la boite de cette liste
  // (sa position propre dans la liste n'a pas de sens visuel hors de celle-ci).
  function pageBox(el) {
    const scrollers = [];
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) if (scrollsY(a)) scrollers.push(a);
    const outer = scrollers.length ? scrollers[scrollers.length - 1] : null;
    const inner = scrollers.length > 1 ? scrollers[scrollers.length - 2] : null;
    const r = (inner || el).getBoundingClientRect();
    // Horizontalement, toute bande qui defile (menu en bande sur telephone, tableau
    // large) deplace ses elements : on rajoute son decalage pour garder l'ordre visuel.
    let dx = window.scrollX;
    for (let a = (inner || el).parentElement; a && a !== document.body; a = a.parentElement) dx += a.scrollLeft;
    const dy = window.scrollY + (outer ? outer.scrollTop : 0);
    return { left: r.left + dx, top: r.top + dy, right: r.right + dx, bottom: r.bottom + dy };
  }
  function styleOf(el) {
    const cs = getComputedStyle(el);
    return {
      outline: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0 && !/rgba\([^)]*,\s*0\)$/.test(cs.outlineColor)
        ? cs.outlineStyle + ' ' + cs.outlineWidth + ' ' + cs.outlineColor : 'none',
      boxShadow: cs.boxShadow,
      border: cs.borderTopColor + ' ' + cs.borderRightColor + ' ' + cs.borderBottomColor + ' ' + cs.borderLeftColor + ' ' + cs.borderTopWidth,
      background: cs.backgroundColor,
      color: cs.color,
      decoration: cs.textDecorationLine,
    };
  }
  document.addEventListener('focusin', (e) => {
    const el = e.target;
    if (!(el instanceof Element)) return;
    const r = el.getBoundingClientRect();
    const inView = r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth;
    // Neuf points de l'element : combien sont recouverts par un autre element ?
    let covered = 0; let sampled = 0; let obscuredBy = null;
    if (inView) {
      for (const fx of [0.15, 0.5, 0.85]) {
        for (const fy of [0.2, 0.5, 0.8]) {
          const x = r.left + r.width * fx; const y = r.top + r.height * fy;
          if (x < 0 || y < 0 || x >= window.innerWidth || y >= window.innerHeight) continue;
          sampled++;
          const hit = document.elementFromPoint(x, y);
          if (!hit || hit === el || el.contains(hit) || hit.contains(el)) continue;
          const lab = el.closest('label');
          if (lab && lab.contains(hit)) continue;
          covered++;
          if (!obscuredBy) obscuredBy = hit.closest('button, a, [class]') || hit;
        }
      }
    }
    window.__sweepFocus.push({
      el, box: pageBox(el), focused: styleOf(el), inView, obscuredBy,
      coverage: sampled ? covered / sampled : 0,
      region: el.closest('[role="dialog"], aside, nav, header, main, footer'),
    });
  }, true);
  window.__sweepStyleOf = styleOf;
}

/** Analyse les prises de focus enregistrees (a appeler apres les appuis sur Tab). */
function analyseFocus() {
  const marks = window.__sweepMarks || (window.__sweepMarks = {});
  function describe(el) {
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    const cls = (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
    if (cls.length) s += '.' + cls.join('.');
    const t = (el.getAttribute('aria-label') || el.innerText || el.getAttribute('name') || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    return t ? s + ' "' + t + '"' : s;
  }
  const log = window.__sweepFocus || [];
  const seq = [];
  const seen = new Set();
  for (const f of log) {
    if (seen.has(f.el)) break; // retour au debut du cycle
    seen.add(f.el);
    seq.push(f);
  }
  if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
  const order = [];
  const focus = [];
  for (let i = 0; i < seq.length; i++) {
    const f = seq[i];
    const rest = window.__sweepStyleOf(f.el);
    const same = Object.keys(rest).every((k) => rest[k] === f.focused[k]);
    if (same && f.focused.outline === 'none') {
      (marks['focus visible'] = marks['focus visible'] || []).push(f.el);
      focus.push(describe(f.el) + ' : aucun changement visible au focus');
    } else if (f.obscuredBy && f.coverage >= 0.3) {
      // Critere 2.4.11 (AA) : l'element ne doit pas etre entierement cache ; au-dela d'un
      // tiers masque, l'indicateur de focus n'est plus lisible pour l'equipier.
      (marks['focus visible'] = marks['focus visible'] || []).push(f.el);
      const part = f.coverage >= 0.99 ? 'entièrement' : Math.round(f.coverage * 100) + ' %';
      focus.push(describe(f.el) + ' : focalisé mais masqué (' + part + ') par ' + describe(f.obscuredBy));
    }
    if (i === 0) continue;
    const p = seq[i - 1];
    // Ordre compare a l'interieur d'une meme region (bandeau, menu, contenu, dialogue) :
    // les elements hors region (lien d'evitement, bouton flottant) n'ont pas de voisin
    // visuel a respecter.
    if (!p.region || p.region !== f.region) continue;
    const a = p.box; const b = f.box;
    const sameLine = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 0;
    const goesUp = b.bottom <= a.top - 2 && b.left < a.right - 2;
    const goesLeft = sameLine && b.right <= a.left + 2;
    if (goesUp || goesLeft) {
      (marks['ordre de tabulation'] = marks['ordre de tabulation'] || []).push(f.el);
      order.push(describe(p.el) + ' puis ' + describe(f.el) + (goesUp ? ' : le focus remonte' : ' : le focus revient à gauche sur la même ligne'));
    }
  }
  return {
    steps: seq.length,
    order: { ok: order.length === 0, details: order },
    focus: { ok: focus.length === 0, details: focus },
  };
}

/** Nombre approximatif d'elements atteignables a la tabulation (borne des appuis). */
function countTabbables() {
  const sel = 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
  return Array.from(document.querySelectorAll(sel)).filter((el) => el.checkVisibility() && el.tabIndex >= 0).length;
}

/** Encadre les elements fautifs d'une verification avant la capture d'echec. */
function highlight(check) {
  document.querySelectorAll('.__sweep-mark').forEach((n) => n.remove());
  const els = ((window.__sweepMarks || {})[check] || []).filter((el) => el && el.isConnected);
  if (!els.length) return 0;
  if (check === 'focus visible' || check === 'ordre de tabulation') {
    // Meme geste que la tabulation : le navigateur replace l'element comme au focus.
    els[0].focus();
    const r0 = els[0].getBoundingClientRect();
    const m0 = document.createElement('div');
    m0.className = '__sweep-mark';
    m0.style.cssText = 'position:fixed;pointer-events:none;z-index:2147483647;border:3px solid #e00;box-sizing:border-box;'
      + 'left:' + (r0.left - 2) + 'px;top:' + (r0.top - 2) + 'px;width:' + (r0.width + 4) + 'px;height:' + (r0.height + 4) + 'px;';
    document.body.appendChild(m0);
    return els.length;
  }
  // Defilement vertical seulement, dans la zone qui defile : la capture garde le cadrage
  // horizontal reel de la page.
  const first = els[0];
  for (let a = first.parentElement; a && a !== document.body; a = a.parentElement) {
    const cs = getComputedStyle(a);
    if ((cs.overflowY === 'auto' || cs.overflowY === 'scroll') && a.scrollHeight > a.clientHeight + 1) {
      const r = first.getBoundingClientRect(); const c = a.getBoundingClientRect();
      a.scrollTop += (r.top + r.height / 2) - (c.top + c.height / 2);
      break;
    }
  }
  for (const el of els.slice(0, 40)) {
    const r = el.getBoundingClientRect();
    const m = document.createElement('div');
    m.className = '__sweep-mark';
    m.style.cssText = 'position:fixed;pointer-events:none;z-index:2147483647;border:3px solid #e00;box-sizing:border-box;'
      + 'left:' + (r.left - 2) + 'px;top:' + (r.top - 2) + 'px;width:' + (r.width + 4) + 'px;height:' + (r.height + 4) + 'px;';
    document.body.appendChild(m);
  }
  return els.length;
}

function clearHighlight() {
  document.querySelectorAll('.__sweep-mark').forEach((n) => n.remove());
}

module.exports = {
  auditLayout, auditMessages, auditTechnicalText, installFocusRecorder, analyseFocus,
  countTabbables, highlight, clearHighlight,
};
