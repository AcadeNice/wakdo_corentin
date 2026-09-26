// Ecriture du rapport du balayage : rapport.md (lisible), tableau-complet.csv (une
// ligne par verification) et resultats.json. Chaque echec est classe, rattache a sa
// cause probable et marque « connu » s'il recoupe un bug deja documente.
const fs = require('fs');
const path = require('path');
const { explain } = require('./causes');

const CATEGORY = {
  'statut HTTP': 'erreur',
  console: 'erreur',
  'réseau': 'erreur',
  'lien refusé': 'erreur',
  chevauchement: 'chevauchement',
  'masquage fixe': 'chevauchement',
  'défilement horizontal': 'débordement',
  'sortie de conteneur': 'débordement',
  'texte coupé': 'débordement',
  'cible tactile': 'cible trop petite',
  'texte technique': 'texte technique',
  alignement: 'alignement',
  'échelle de police': 'police hors échelle',
  'messages (contraste)': 'lisibilité des messages',
  'ordre de tabulation': 'clavier',
  'focus visible': 'clavier',
  'comparaison référence': 'écart visuel',
};
const ROLE_LABEL = {
  public: 'Public (non connecté)', admin: 'Administrateur', responsable: 'Responsable',
  cuisine: 'Équipier cuisine', comptoir: 'Équipier comptoir', drive: 'Équipier drive',
};
const ROLE_ORDER = ['public', 'admin', 'responsable', 'cuisine', 'comptoir', 'drive'];

function categoryOf(r) {
  if (r.categorie) return r.categorie;
  if (r.page.startsWith('parcours')) return 'action sans effet';
  return CATEGORY[r.verification] || 'autre';
}

function md(s) {
  return String(s == null ? '' : s).replace(/\|/g, '\\|').replace(/\n/g, ' ');
}

function csv(s) {
  const v = String(s == null ? '' : s);
  return /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
}

function writeReport(out, rows, options) {
  const enriched = rows.map((r) => {
    const cat = r.ok ? null : categoryOf(r);
    const why = r.ok ? { cause: null, connu: null } : explain({ ...r, categorie: cat });
    return { ...r, categorie: cat, cause: why.cause, connu: why.connu };
  });
  const failures = enriched.filter((r) => !r.ok);
  const byCat = {};
  for (const f of failures) byCat[f.categorie] = (byCat[f.categorie] || 0) + 1;
  const checksByCat = {};
  for (const r of enriched) {
    const c = r.page.startsWith('parcours') ? 'action sans effet' : (CATEGORY[r.verification] || 'autre');
    checksByCat[c] = (checksByCat[c] || 0) + 1;
  }

  fs.writeFileSync(path.join(out, 'resultats.json'), JSON.stringify(enriched, null, 1));
  const head = ['role', 'page', 'url', 'largeur', 'verification', 'resultat', 'categorie', 'detail', 'capture', 'cause probable', 'connu'];
  const csvLines = [head.join(';')].concat(enriched.map((r) => [
    r.role, r.page, r.url, r.largeur, r.verification, r.ok ? 'ok' : 'échec', r.categorie || '',
    (r.details || []).join(' / '), r.capture || '', r.cause || '', r.connu || '',
  ].map(csv).join(';')));
  fs.writeFileSync(path.join(out, 'tableau-complet.csv'), csvLines.join('\n') + '\n');

  const L = [];
  const now = new Date().toISOString().slice(0, 16).replace('T', ' ');
  L.push('# Balayage du back-office : chevauchements, débordements, fonctionnement');
  L.push('');
  L.push(`Généré par \`tests/e2e/backoffice-sweep.spec.js\` le ${now} (UTC), sur une pile jetable.`);
  L.push('Chaque ligne du tableau complet (`tableau-complet.csv`) est une vérification : rôle × page × largeur × vérification × résultat.');
  L.push('Les captures d\'échec (`captures/`) encadrent en rouge les éléments fautifs ; les captures de référence sont dans `reference/<rôle>/`.');
  L.push('');
  L.push('## Synthèse');
  L.push('');
  L.push(`- Vérifications : **${enriched.length}**, dont **${failures.length}** en échec.`);
  const newOnes = failures.filter((f) => !f.connu);
  L.push(`- Échecs nouveaux (ne recoupant aucun bug déjà documenté) : **${newOnes.length}** ; échecs déjà connus : **${failures.length - newOnes.length}**.`);
  const pagesByRole = {};
  for (const r of enriched) {
    if (r.page.startsWith('parcours')) continue;
    (pagesByRole[r.role] = pagesByRole[r.role] || new Set()).add(r.page);
  }
  L.push('- Pages balayées par rôle : ' + ROLE_ORDER.filter((k) => pagesByRole[k]).map((k) => `${ROLE_LABEL[k]} ${pagesByRole[k].size}`).join(', ') + '.');
  L.push(`- Largeurs : ${options.viewports.join(', ')} (parcours au clavier aux largeurs 1366, 768 et 390).`);
  L.push('');
  L.push('| Catégorie | Vérifications | Échecs | dont nouveaux |');
  L.push('|---|---:|---:|---:|');
  const cats = Array.from(new Set([...Object.keys(checksByCat), ...Object.keys(byCat)])).sort();
  for (const c of cats) {
    const n = failures.filter((f) => f.categorie === c && !f.connu).length;
    L.push(`| ${c} | ${checksByCat[c] || 0} | ${byCat[c] || 0} | ${n} |`);
  }
  L.push('');

  // Parcours
  const journeys = enriched.filter((r) => r.page.startsWith('parcours'));
  if (journeys.length) {
    L.push('## Parcours des actions principales');
    L.push('');
    L.push('| Parcours | Rôle | Étape | Résultat | Détail | Capture |');
    L.push('|---|---|---|---|---|---|');
    for (const r of journeys) {
      L.push(`| ${md(r.page.replace('parcours : ', ''))} | ${md(ROLE_LABEL[r.role] || r.role)} | ${md(r.verification)} | ${r.ok ? 'ok' : '**échec**'} | ${md((r.details || []).join(' / ').slice(0, 300))} | ${r.capture ? `[capture](${r.capture})` : ''} |`);
    }
    L.push('');
  }

  // Matrice par role
  L.push('## Matrice rôle × page × largeur');
  L.push('');
  L.push('Chaque case liste les vérifications en échec à cette largeur (« ok » sinon). La colonne « toutes » regroupe les vérifications faites une fois par page (console, réseau, liens).');
  const widths = options.viewports.concat(['toutes']);
  for (const role of ROLE_ORDER) {
    const rs = enriched.filter((r) => r.role === role && !r.page.startsWith('parcours'));
    if (!rs.length) continue;
    L.push('');
    L.push(`### ${ROLE_LABEL[role]}`);
    L.push('');
    L.push('| Page | ' + widths.join(' | ') + ' |');
    L.push('|---|' + widths.map(() => '---').join('|') + '|');
    const pages = Array.from(new Set(rs.map((r) => r.page)));
    for (const p of pages) {
      const cells = widths.map((w) => {
        const here = rs.filter((r) => r.page === p && (r.largeur === w || (w === 'toutes' ? false : false)));
        if (!here.length) return '';
        const bad = here.filter((r) => !r.ok).map((r) => r.verification);
        return bad.length ? bad.join(', ') : 'ok';
      });
      L.push(`| ${md(p)} | ${cells.map(md).join(' | ')} |`);
    }
  }
  L.push('');

  // Liste des echecs, nouveaux d'abord, regroupes par cause
  const groups = new Map();
  for (const f of failures) {
    const key = [f.connu ? 1 : 0, f.categorie, f.verification, f.cause || '(cause à analyser)'].join('|');
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(f);
  }
  const sorted = Array.from(groups.entries()).sort((a, b) => {
    const [ka] = a; const [kb] = b;
    if (ka[0] !== kb[0]) return ka[0] < kb[0] ? -1 : 1;
    return b[1].length - a[1].length;
  });
  L.push('## Échecs, regroupés par cause (nouveaux d\'abord)');
  L.push('');
  for (const [, list] of sorted) {
    const f = list[0];
    L.push(`### ${f.connu ? '[connu : ' + f.connu + ']' : '[nouveau]'} ${f.categorie} : ${f.verification} (${list.length} échec(s))`);
    L.push('');
    L.push(`Cause probable : ${f.cause || 'à analyser'}`);
    L.push('');
    for (const r of list.slice(0, 30)) {
      const d = (r.details || []).slice(0, 3).join(' / ');
      L.push(`- ${ROLE_LABEL[r.role] || r.role} · \`${r.page}\` · ${r.largeur} : ${md(d).slice(0, 400)}${r.capture ? ` ([capture](${r.capture}))` : ''}`);
    }
    if (list.length > 30) L.push(`- ... et ${list.length - 30} autre(s), voir tableau-complet.csv`);
    L.push('');
  }

  // Exceptions justifiees du critere 2.5.8
  const exc = new Map();
  for (const r of enriched) {
    for (const e of (r.extra && r.extra.exceptions) || []) {
      const key = e.replace(/\(\d+x\d+ px\)/, '').replace(/"[^"]*"/, '').trim();
      if (!exc.has(key)) exc.set(key, { text: e, pages: new Set() });
      exc.get(key).pages.add(r.page);
    }
  }
  if (exc.size) {
    L.push('## Cibles tactiles sous 24 x 24 px admises (exceptions du critère 2.5.8)');
    L.push('');
    for (const [, v] of exc) L.push(`- ${md(v.text)} (${v.pages.size} page(s))`);
    L.push('');
  }

  // Echelle de police relevee
  const fontRows = enriched.filter((r) => r.verification === 'échelle de police' && r.largeur === '1366x768' && r.extra);
  if (fontRows.length) {
    L.push('## Tailles de police relevées (1366 px)');
    L.push('');
    L.push(`Échelle déclarée dans les feuilles de style : ${fontRows[0].extra.scale.join(', ')} px.`);
    L.push('');
    L.push('| Rôle | Page | Valeurs utilisées (px x éléments) | Valeurs isolées (1 seul élément) |');
    L.push('|---|---|---|---|');
    for (const r of fontRows) {
      L.push(`| ${ROLE_LABEL[r.role] || r.role} | ${md(r.page)} | ${md(r.extra.histogram.join(', '))} | ${md((r.extra.isolated || []).join(' ; ').slice(0, 200))} |`);
    }
    L.push('');
  }

  // Mesures de contraste des messages
  const msgRows = enriched.filter((r) => r.verification === 'messages (contraste)' && r.extra && r.extra.mesures);
  if (msgRows.length) {
    L.push('## Messages mesurés (états vides, erreurs, confirmations)');
    L.push('');
    for (const r of msgRows) {
      L.push(`- ${ROLE_LABEL[r.role] || r.role} · \`${r.page}\`${r.extra.erreursDeclenchees ? ' (erreurs de formulaire déclenchées)' : ''} : ${md(r.extra.mesures.join(' ; ')).slice(0, 600)}`);
    }
    L.push('');
  }

  L.push('## Ce que ce balayage ne démontre pas');
  L.push('');
  L.push('- Les mesures portent sur les boîtes calculées par Chromium ; elles ne remplacent ni un lecteur d\'écran réel ni un test sur appareil tactile.');
  L.push('- Le contraste n\'est mesuré que sur les messages (états vides, erreurs, confirmations) ; la mesure complète de la page reste celle d\'axe-core (`tests/e2e/a11y.spec.js`).');
  L.push('- Une page n\'est balayée que si un lien de la navigation réelle du rôle y mène ; une page existante sans lien n\'est pas couverte.');
  L.push('');
  fs.writeFileSync(path.join(out, 'rapport.md'), L.join('\n'));

  const lines = [`${enriched.length} vérifications, ${failures.length} échecs (${newOnes.length} nouveaux)`];
  for (const c of cats) lines.push(`  ${c} : ${byCat[c] || 0} / ${checksByCat[c] || 0}`);
  return lines.join('\n');
}

module.exports = { writeReport, categoryOf };
