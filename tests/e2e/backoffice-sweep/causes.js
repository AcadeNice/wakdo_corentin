// Cause probable (fichier:ligne) de chaque famille d'echec du balayage, et
// rattachement aux bugs deja documentes dans l'audit UX du 2026-09-26
// (_byan-output/dossier-soutenance/annexes/ux-backoffice/rapport.md).
//
// Chaque regle a ete etablie en lisant le code a partir des echecs observes le
// 2026-09-26 (copie dca02b4) : elle sert a trier, elle ne prouve pas la cause. Une
// famille sans regle apparait « cause à analyser » dans le rapport. Les numeros de
// ligne suivent le code de cette copie et vieilliront avec les corrections.
//
// Mis a jour au lot 0 (systeme de design, meme date) : les regles admin.css
// ci-dessous ont ete corrigees (voir les commentaires "Lot 0" a chaque endroit
// cite) ; les numeros de ligne suivent desormais admin.css apres ces corrections
// (jetons d'espacement/typographie ajoutes en tete de :root, +22 lignes). Le
// texte de chaque regle deja corrigee dit ce qui a change, pour qu'un lecteur du
// rapport du 26/09 (genere AVANT le lot 0) comprenne pourquoi la famille a
// disparu, sans que la regle mente sur l'etat actuel du code.
const KNOWN = {
  'BUG-01': 'BUG-01 (formulaire multipart ignoré, « Requête invalide. »)',
  'BUG-02': 'BUG-02 (pas de choix de taille au comptoir/drive)',
  'ERG-01': 'ERG-01 (composeur de menu en listes déroulantes)',
  'ERG-02': 'ERG-02 (pas de statut dans « En cours » au comptoir)',
  'ERG-03': 'ERG-03 (menu latéral et bouton « Police adaptée » sur la caisse)',
};

const text = (r) => (r.details || []).join(' ');

const RULES = [
  {
    when: (r) => r.page.startsWith('parcours') && /Requ[eê]te invalide/.test(text(r)),
    cause: 'src/app/Core/Request.php:168 formBody() renvoie [] pour multipart/form-data (products/form.php:45, categories/form.php:33) : le jeton CSRF est lu vide, réponse 403 en texte brut',
    connu: KNOWN['BUG-01'],
  },
  {
    // Encaisser recouvert par le bouton fixe : ERG-03 le pressentait a 768 px ; mesure ici.
    // CORRIGE au lot 0 : ne devrait plus produire d'echec (garde par regression, pas
    // suppression de la regle, au cas ou le padding-shorthand reviendrait).
    when: (r) => r.verification === 'masquage fixe' && /order-submit/.test(text(r)),
    cause: 'src/public/admin/assets/css/admin.css:2775 .a11y-toggle fixe en bas à droite ; le seuil de bascule sidebar est desormais 900 px (admin.css:478) et .content y conserve explicitement la marge basse de 80 px (admin.css:547, "padding: 16px 16px 80px" au lieu du raccourci "padding: 16px" qui l\'annulait) — corrige au lot 0',
    connu: KNOWN['ERG-03'],
  },
  {
    when: (r) => r.verification === 'masquage fixe' && /a11y-toggle/.test(text(r)),
    cause: 'src/public/admin/assets/css/admin.css:547 : sous 900 px (seuil remonte de 640, admin.css:478), .content garde desormais "padding: 16px 16px 80px" — le cote explicite au lieu du raccourci "padding: 16px" ne l\'annule plus, la marge basse (admin.css:460) qui protège du bouton fixe .a11y-toggle (admin.css:2775) tient de nouveau sur telephone — corrige au lot 0',
    connu: null,
  },
  {
    when: (r) => r.verification === 'focus visible' && /masqué .* par .*a11y-toggle/.test(text(r)),
    cause: 'src/public/admin/assets/css/admin.css:449 .content porte desormais scroll-padding-bottom: 80px (admin.css:467) en plus de padding-bottom: 80px (admin.css:460) — ce dernier ne protegeait que la fin du defilement, scroll-padding-bottom applique la meme marge quand le navigateur amene un element focalise dans la zone visible (Tab), sous le bouton fixe .a11y-toggle (admin.css:2775) — corrige au lot 0',
    connu: null,
  },
  {
    when: (r) => r.verification === 'focus visible' && /pos__tab\.is-active/.test(text(r)),
    cause: 'src/public/admin/assets/css/admin.css:1808-1812 : l\'onglet actif a deja la bordure et le fond jaunes que :focus-visible applique (outline: none) ; .pos__tab.is-active:focus-visible (admin.css:1820, lot 0) ajoute desormais un anneau outline sombre (--color-text) distinct des deux, mesure 15,96:1 sur le fond de la barre — corrige au lot 0',
    connu: null,
  },
  {
    // Dormante a ce jour (0 occurrence dans le balayage) : aucune tuile is-active/is-selected
    // n'est simultanement survolee et focalisee sur les parcours couverts. Gardee en garde-fou.
    when: (r) => r.verification === 'focus visible' && /pos-tile/.test(text(r)),
    cause: 'src/public/admin/assets/css/admin.css:1854 : :focus-visible de .pos-tile identique au survol et outline: none ; si la tuile est deja survolee, le focus ne change rien. .pos-tile.is-selected:focus-visible (lot 0, section finale du fichier) couvre deja le cas ou une tuile SELECTIONNEE recoit le focus (outline --color-text) ; ce cas-ci (tuile non selectionnee, survolee ET focalisee) reste ouvert si observe',
    connu: null,
  },
  {
    when: (r) => ['défilement horizontal', 'sortie de conteneur'].includes(r.verification) && /pos|order-cart|\/(counter|drive)\/orders\/new/.test(text(r) + ' ' + r.page),
    cause: 'src/public/admin/assets/css/admin.css:2077 : sous 860 px, .pos__main (base admin.css:1768) passe en colonne et fixe desormais aussi align-items: stretch (admin.css:2088, lot 0 — la base porte align-items: flex-start, pensee pour la ligne desktop) ; le catalogue ne prend plus la largeur de la barre d\'onglets sans retour à la ligne (.pos__tabs, admin.css:1779) — corrige au lot 0',
    connu: null,
  },
  {
    when: (r) => ['défilement horizontal', 'sortie de conteneur'].includes(r.verification) && r.page === '/admin/dashboard',
    cause: 'src/public/admin/assets/css/admin.css:1541 .dash-tiles ; un palier repeat(2, 1fr) a ete ajoute a 1024 px (admin.css:1589, lot 0) entre le plein desktop et l\'ancien seuil telephone (640 px, admin.css:1602) — a 768 px, avec le menu lateral (colonne fixe au-dessus de 900 px, bande en dessous), les tuiles tiennent desormais a 2 par ligne — corrige au lot 0',
    connu: null,
  },
  {
    when: (r) => r.verification === 'lien refusé' && /\/admin\/products/.test(text(r)),
    cause: 'src/app/Views/admin/products/index.php:26 et :82-84 : « Nouveau produit », « Modifier », « Recette » et « Supprimer » affichés sans tester product.create / product.update / ingredient.manage / product.delete',
    connu: null,
  },
  {
    when: (r) => r.verification === 'lien refusé' && /\/admin\/menus/.test(text(r)),
    cause: 'src/app/Views/admin/menus/index.php:25, :64 et :69 : « Nouveau menu », « Modifier » et « Supprimer » affichés sans tester menu.create / menu.update / menu.delete',
    connu: null,
  },
  {
    when: (r) => r.verification === 'texte technique' && r.page === '/admin/categories',
    cause: 'src/app/Views/admin/categories/index.php:56 : colonne affichant la référence technique (slug) de chaque catégorie',
    connu: null,
  },
  {
    when: (r) => r.verification === 'texte technique' && r.page === '/admin/roles',
    cause: 'src/app/Views/admin/roles/index.php:71 : colonne affichant le code technique du rôle (admin, manager, kitchen...)',
    connu: null,
  },
  {
    // CORRIGE au lot 0 : min-width/min-height 24px + margin 2px (admin.css:2711).
    when: (r) => r.verification === 'cible tactile' && /btn-order/.test(text(r)),
    cause: 'src/public/admin/assets/css/admin.css:2711 .btn-order porte desormais min-width/min-height: 24px et margin: 2px (lot 0) — les fleches Monter/Descendre mesuraient environ 26 x 20 px et se touchaient sur telephone — corrige au lot 0',
    connu: null,
  },
  {
    when: (r) => r.verification === 'alignement' && /catalogue-card__actions/.test(text(r)),
    cause: 'src/public/admin/assets/css/admin.css:2711 .btn-order (24x24 minimum depuis le lot 0) contre .btn-sm (28px de haut) dans la même barre .catalogue-card__actions : l\'ecart residuel est plus faible qu\'avant le lot 0, a reverifier si cette famille apparait',
    connu: null,
  },
  {
    when: (r) => r.page.startsWith('parcours') && /référencé par des commandes/.test(text(r)),
    cause: 'produit de secours déjà utilisé par une commande : la suppression est refusée à juste titre (mise en place du test, pas un défaut)',
    connu: null,
  },
];

function explain(r) {
  for (const rule of RULES) {
    if (rule.when(r)) return { cause: rule.cause, connu: rule.connu || null };
  }
  return { cause: null, connu: null };
}

module.exports = { explain, KNOWN, RULES };
