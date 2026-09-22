# Preuve 05 — Librairies JavaScript (C2.d)

Titre RNCP 37805, Bloc 1 (developpement front-end).
Competence C2.d verbatim : « Optimiser les temps de developpement en utilisant
des ressources externes (librairies JavaScript) pour resoudre des
problematiques de developpement complexes ».

Criteres evalues : Cr 2.d.1 (les librairies utilisees repondent a une
problematique specifique), Cr 2.d.2 (la librairie est correctement implementee
d'apres les recommandations d'utilisation de sa documentation), Cr 2.d.3 (le
candidat peut clairement expliquer le fonctionnement global de la librairie et
son utilisation).

Convention de ce document : toute reference au code se fait par **citation de
texte cherchable** (`grep`), pas par numero de ligne — les lignes se
deplacent a chaque commit, un texte cite reste verifiable.

Ce document garde la meme discipline que sa version precedente : une position
assumee, defendue sans la survendre. Ce qui a change depuis cette version
precedente est nomme en premier.

---

## 0. Ce qui a change depuis la version precedente de cette preuve

La version precedente de ce document constatait, honnetement, une absence
totale de dependance front externe, et defendait l'esprit de C2.d par des
bibliotheques ES6 internes (`state.js`, `data.js`) — une defense qui ne
remplissait pas la lettre du critere (« externes »). Ce gap est desormais
ferme : le projet embarque une premiere dependance front tierce,
**a11y-dialog 8.1.5**, sur la modale de confirmation d'abandon de commande
(`confirm-modal.js`). L'architecture interne `state.js`/`data.js` reste en
place et reste une preuve valable de reutilisation de code ; elle n'est plus
la piece maitresse de la reponse a C2.d.

---

## 1. Le probleme complexe, mesure avant d'etre resolu

Le motif « fenetre modale accessible » — piege de tabulation (Tab/Shift+Tab
cyclent sans en sortir), fermeture par Echap, restauration du focus sur
l'element declencheur, `aria-hidden` pose sur le reste de la page pendant que
la modale est ouverte, blocage du defilement du corps — est un probleme
d'accessibilite reconnu comme complexe (c'est precisement pour cela que le
W3C lui consacre un pattern ARIA dedie, le *Dialog (Modal) Pattern* de l'ARIA
Authoring Practices Guide). Avant cette integration, ce probleme etait
resolu **a la main, independamment, dans quatre fichiers distincts** de la
borne, avec le meme idiome recopie a l'identique :

```js
if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
```

Cette paire de lignes, verbatim, cherchable par
`grep -rn "e.shiftKey && document.activeElement === first" src/public/borne/assets/js/`,
se trouve dans :

| Fichier | Piege Tab/Shift+Tab | `aria-hidden` pose sur les freres du corps | Restauration du focus | Blocage du defilement |
|---|---|---|---|---|
| `confirm-modal.js` (avant integration) | oui (`// Echap = annuler ; Tab/Shift+Tab pieges sur les boutons de la modale.`) | oui (`bgSiblings.forEach(el => el.setAttribute('aria-hidden', 'true'));`) | oui (`previouslyFocused.focus();`) | oui (`document.body.style.overflow = 'hidden';`) |
| `page-payment.js` | oui (`// Focus-trap : Tab/Shift+Tab cyclent dans la modale (coherent L2/L3).`) | non | non | oui |
| `page-product-menu.js` | oui (fonction `function trapFocus(modal) {`) | oui (`modal._bgSiblings.forEach(el => el.setAttribute('aria-hidden', 'true'));`) | non explicite | oui |
| `product-options.js` | oui (`/** Piege Tab/Shift+Tab a l'interieur de la modale. */`) | oui (`bgSiblings.forEach(el => el.setAttribute('aria-hidden', 'true'));`) | non explicite | oui |
| `allergens.js` | **non** | **non** | **non** | **non** |

Precision honnete sur la 5e modale (`allergens.js`) : son implementation est
plus simple que les quatre autres — `role="dialog"` + `aria-modal="true"` +
fermeture par Echap (`document.addEventListener('keydown', onKeydown)`, avec
`function onKeydown(event) { if (event.key === 'Escape') { closeAllergenModal(); } }`)
+ clic-fond, mais **sans** piege de tabulation ni `aria-hidden` de fond ni
restauration du focus. Elle n'est donc pas un cinquieme exemplaire du meme
probleme complexe ; elle reste citee ici pour l'exhaustivite de l'audit, pas
comme preuve de duplication.

**Le fait mesure et verifiable** : quatre fichiers independants dupliquent, au
caractere pres pour le coeur de l'algorithme, une logique non triviale de
gestion du focus. C'est exactement le type de probleme que C2.d demande de
resoudre par une ressource externe plutot que par du code maison recopie.

---

## 2. La librairie choisie et sa provenance

| Champ | Valeur |
|---|---|
| Nom | `a11y-dialog` |
| Version | `8.1.5`, epinglee (pas de plage semver) |
| Licence | MIT — Copyright (c) 2025 Kitty Giraudel |
| Poids servi au navigateur | 4562 octets minifie (mesure : `wc -c`), format ESM |
| Documentation officielle | https://a11y-dialog.netlify.app/ |
| Depot source | https://github.com/KittyGiraudel/a11y-dialog |
| Emplacement dans le depot | `src/public/borne/assets/vendor/a11y-dialog/a11y-dialog.esm.min.js` |
| Provenance detaillee (registre, integrite, SHA-256) | `src/public/borne/assets/vendor/NOTICE.md` |

Obtenue via `npm pack a11y-dialog@8.1.5` (registre npm officiel), copiee
**octet pour octet** dans le depot (verifie par `cmp` au moment de la copie,
consigne dans `NOTICE.md`) : le fichier vendu n'a pas ete modifie, pas meme
pour y ajouter un commentaire d'en-tete — un ajout aurait change son
empreinte SHA-256 et rendu la comparaison avec le paquet publie moins directe.
La provenance vit donc dans un fichier voisin (`NOTICE.md`), pas dans le
fichier vendu lui-meme.

### Pourquoi embarquee en local, pas depuis un CDN

Le vhost de la borne impose une politique de securite de contenu stricte,
meme origine. Texte exact, cherchable par
`grep -n "script-src" docker/apache/vhost.conf` (directive Apache `Header ... set`,
module mod_headers ; reformule ici en « Nom: valeur » pour la lisibilite, la
valeur de la politique est copiee sans modification) :

```
Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'
```

pose dans le bloc `<VirtualHost>` dont le commentaire precedent dit,
verbatim : « CSP STRICTE : la borne ne charge que du same-origin (scripts,
styles, polices, images auto-heberges, aucun CDN) ». Un `<script
src="https://cdn...">` serait bloque par le navigateur sur ce vhost. C'est la
raison technique, active et verifiee (pas seulement une intention), du choix
de vendoring local plutot que d'un lien CDN.

### La dependance de la dependance : focusable-selectors, sans fichier separe

a11y-dialog declare `focusable-selectors` comme dependance de production dans
son `package.json` (`"dependencies": {"focusable-selectors": "^0.8.0"}`,
resolue en `0.8.4`). Point verifie et a ne pas presenter de travers :
**focusable-selectors n'est pas charge comme un fichier separe par le
navigateur.** Le build (rollup) d'a11y-dialog l'INLINE dans chaque fichier
`dist/*` qu'il publie. Verification faite en inspectant le fichier vendu
lui-meme : la liste des selecteurs CSS de focusable-selectors (`a[href]`,
`area[href]`, `:not([inert])`, etc.) y est presente telle quelle
(`grep -o "area\[href\]" src/public/borne/assets/vendor/a11y-dialog/a11y-dialog.esm.min.js`
la trouve). Sa licence MIT est neanmoins conservee
(`assets/vendor/focusable-selectors/LICENSE`) pour l'attribution complete,
puisque son code est physiquement present dans ce que le navigateur execute —
mais aucune balise ni import ne le charge separement, il n'y a rien de plus a
servir.

---

## 3. Perimetre assume : une seule des cinq modales

Seule `confirm-modal.js` (confirmation d'abandon de commande) a ete migree
vers a11y-dialog. Les quatre autres (`page-payment.js`, `page-product-menu.js`,
`product-options.js`, `allergens.js`) gardent leur implementation manuelle,
qui fonctionne et qui est testee. Choix delibere, pas un oubli : remplacer
cinq implementations eprouvees a treize jours de l'oral aurait ete
disproportionne par rapport a ce que le critere demande (Rasoir d'Ockham :
prouver la competence sur un cas reel et bien maitrise est plus solide que
de la disperser sur cinq). `confirm-modal.js` a ete choisie parce qu'elle porte le
geste le plus a risque (effacer toute la commande), sur l'ecran ou le
panier est le plus rempli.

---

## 4. Implementation d'apres la documentation (Cr 2.d.2)

Sources consultees : la documentation officielle (`usage/markup`,
`usage/instantiation`, `usage/styling`, `advanced/scroll-lock` sur
https://a11y-dialog.netlify.app/) et le fichier source publie
(`dist/a11y-dialog.js`, lisible, non-minifie — la seule maniere de repondre
avec certitude a des questions que la documentation ne couvre pas, comme la
liberation memoire d'une instance a usage unique, section 5).

### Balisage : avant / apres

Avant (l'ancien `confirm-modal.js`) :

```html
<div class="confirm-overlay">
  <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-msg">
    <p class="confirm-modal__message" id="confirm-modal-msg">...</p>
    <div class="confirm-modal__actions">
      <button class="confirm-modal__cancel">Annuler</button>
      <button class="confirm-modal__confirm">Confirmer</button>
    </div>
  </div>
</div>
```

Apres, conforme au balisage documente par a11y-dialog (page `usage/markup` :
conteneur porteur de `aria-labelledby`, boite interne en `role="document"`
« pour ameliorer le support des lecteurs d'ecran comme NVDA », boutons de
fermeture portant `data-a11y-dialog-hide`) :

```html
<div class="confirm-overlay" id="confirm-modal" aria-labelledby="confirm-modal-msg">
  <div class="confirm-modal" role="document">
    <p class="confirm-modal__message" id="confirm-modal-msg">...</p>
    <div class="confirm-modal__actions">
      <button class="confirm-modal__cancel" data-a11y-dialog-hide autofocus>Annuler</button>
      <button class="confirm-modal__confirm" data-a11y-dialog-hide>Confirmer</button>
    </div>
  </div>
</div>
```

Le changement structurel important : `role="dialog"` et `aria-modal="true"`
se deplacent du bloc interne (`.confirm-modal`) vers le CONTENEUR
(`.confirm-overlay`). Ce n'est pas arbitraire : dans le modele d'a11y-dialog,
c'est l'element passe a `new A11yDialog(...)` qui EST « le dialogue » aux yeux
de l'assistance technique ; la boite interne n'est qu'un habillage de
contenu. Le constructeur pose lui-meme ces attributs
(`dist/a11y-dialog.js` : `this.$el.setAttribute('aria-modal', 'true');` et
`if (!this.$el.hasAttribute('role')) { this.$el.setAttribute('role', 'dialog'); }`)
— ils ne sont donc plus ecrits a la main cote projet, la librairie les
possede.

### Instanciation : le cas documente du dialogue cree dynamiquement

`confirm-modal.js` construit sa modale a chaque appel (`document.createElement`
+ `innerHTML`) plutot que de la garder presente dans la page. La page
`usage/instantiation` de la documentation traite explicitement ce cas :
omettre l'attribut `data-a11y-dialog` (reserve a l'auto-instanciation des
dialogues presents des le chargement de la page) et instancier a la main
juste apres la creation :

```js
const element = document.querySelector('#your-dialog-id')
const dialog = new A11yDialog(element)
```

C'est exactement le patron suivi : `const dialog = new A11yDialog(overlay);`
juste apres `document.body.appendChild(overlay)`.

### Le focus initial : l'attribut `autofocus` comme detrompeur documente

Exigence produit inchangee depuis la version manuelle : le focus initial doit
tomber sur **Annuler**, pas sur Confirmer, pour qu'un appui reflexe sur
Entree n'efface pas la commande par accident. a11y-dialog documente
precisement ce mecanisme : sa fonction interne de focus
(`dist/a11y-dialog.js` : `function focus(el) { (el.querySelector('[autofocus]') || el).focus(); }`)
cherche un descendant portant l'attribut HTML `autofocus` et le prend en
priorite. Poser `autofocus` (bare, sans valeur) sur le bouton Annuler suffit
— aucune ligne de JavaScript maison n'est plus necessaire pour ce
comportement (l'ancien code appelait
`requestAnimationFrame(() => overlay.querySelector('.confirm-modal__cancel').focus())`
a la main).

### Fermeture declarative : `data-a11y-dialog-hide`

Les deux boutons portent `data-a11y-dialog-hide` (sans valeur — la
documentation autorise la forme implicite des lors que l'element est a
l'interieur du conteneur `aria-modal`). La fermeture proprement dite n'est
plus une fonction maison : un clic sur l'un ou l'autre est intercepte par le
gestionnaire delegue de la librairie
(`handleTriggerClicks`, pose sur `document` en phase de capture des la
construction) qui appelle `this.hide(event)`. Le seul code encore ecrit a la
main sur le bouton Confirmer est l'appel a `onConfirm` — la librairie n'a
aucune notion de « confirmer », seulement de « montrer » / « cacher ».

### Une reserve nommee : le clic sur le fond

La documentation illustre le clic-hors-de-la-boite avec un `<div
data-a11y-dialog-hide></div>` DEDIE, premier enfant du conteneur, qui a
besoin de son propre positionnement CSS (`position: fixed; inset: 0`) pour
couvrir tout l'ecran. Cette regle CSS est hors du perimetre de fichiers
autorise pour ce lot (`assets/css/` n'y figure pas). `.confirm-overlay` joue
deja ce role de zone de fond cliquable — il a deja `position: fixed; inset:
0` dans `style.css` — donc le clic direct sur le fond est detecte comme avant
l'integration (`overlay.addEventListener('click', (e) => { if (e.target ===
overlay) dialog.hide(); })`), en deleguant desormais la fermeture a
`dialog.hide()` plutot qu'a une fonction `close()` maison. Le resultat
observable est identique ; le detail de balisage documente n'a pas ete suivi
a la lettre sur ce seul point, pour une raison de perimetre de fichiers, pas
de facilite.

---

## 5. Couverture point par point (verifiee, pas supposee)

| Comportement exige | Couvert par a11y-dialog ? | Preuve |
|---|---|---|
| Piege de tabulation (Tab/Shift+Tab) | **Oui** | `function trapTabKey(el, event) { ... }` dans le fichier source publie ; verifie par les tests `Tab depuis Confirmer...` et `Shift+Tab depuis Annuler...` de `confirm-modal.test.js` |
| Fermeture par Echap | **Oui**, avec une nuance | La verification se fait dans `bindKeypress`, ecoute posee sur **le conteneur de la modale** (`this.$el.addEventListener('keydown', this.bindKeypress, true)`), pas sur `document` comme le faisait l'ancien code. Consequence concrete : Echap ne ferme que si le focus est reellement dans la modale — assure par le piege de tabulation des lors que l'utilisateur reste au clavier. Verifie par le test `Echap ferme sans appeler onConfirm`. |
| Restauration du focus au declencheur | **Oui** | `this.previouslyFocused = getActiveEl();` a l'ouverture (avec un contournement documente d'un bug Safari), `this.previouslyFocused?.focus?.();` a la fermeture ; verifie par le test `le focus revient au declencheur a la fermeture` |
| `aria-hidden` sur les elements de fond | **Non** — technique differente | a11y-dialog protege le fond via `aria-modal="true"` pose sur le conteneur (technique recommandee par l'ARIA APG, plus recente que le `aria-hidden` manuel sur chaque frere). Il ne touche aucun attribut des freres du corps (verifie en lisant l'integralite du fichier source : aucune occurrence de `aria-hidden` en dehors de celui du conteneur lui-meme). Le code manuel est donc **garde tel quel**, branche sur `dialog.on('hide', ...)`, et verifie par le test `pose aria-hidden sur les freres du corps...`. |
| Blocage du defilement du corps | **Non** — documente comme hors perimetre | Page `advanced/scroll-lock` de la documentation, verbatim : « the library does not handle scroll locking automatically ». Le code manuel est **garde**, mais recable sur le cycle de vie documente de la librairie (`dialog.on('show', ...)` / `dialog.on('hide', ...)`) plutot qu'inline dans le flux d'ouverture ; verifie par le test `bloque le defilement du corps...`. |

Sur les cinq comportements exiges, la librairie en couvre trois entierement
et n'en couvre sciemment aucun sur les deux derniers, ce que sa propre
documentation dit explicitement. Un point de divergence concret, releve en
lisant le code source : le piege de tabulation d'a11y-dialog exclut les
elements focusables mais rendus invisibles (verification par boite
geometrique — voir section 6), un filtre absent de l'ancien
`querySelectorAll('button:not([disabled])')`. Aucune regression : les deux
comportements non couverts restent testes avec la meme rigueur qu'avant
l'integration.

### Une sixieme chose verifiee, que la consigne initiale ne demandait pas : la fuite d'ecouteur

En lisant le code source (pas seulement la documentation), un point n'apparaissait
dans aucune des cinq exigences mais merite d'etre nomme : le constructeur
`A11yDialog` pose un ecouteur `click` sur `document` (`document.addEventListener('click',
this.handleTriggerClicks, true)`), et seul `.destroy()` le retire — `.hide()` ne le
fait pas. `confirm-modal.js` cree une INSTANCE NEUVE a chaque appel et ne la
reutilise pas : sans appel a `.destroy()`, chaque ouverture-fermeture de la
modale laisserait un ecouteur `document` pendu indefiniment. Sur une borne qui
tourne des heures sans rechargement de page, ce n'est pas theorique. Le code
appelle donc `dialog.destroy()` a la fermeture — differe d'un microtask,
parce qu'appeler `destroy()` (qui rappelle `hide()` en interne) SYNCHRONEMENT
depuis le gestionnaire `hide` boucierait (`shown` ne repasse a `false`
qu'apres la diffusion de cet evenement). Verifie par le test `instance a
usage unique...`, qui espionne `document.addEventListener`/`removeEventListener`
et verifie qu'ils s'equilibrent apres ecoulement de la file de microtaches.

---

## 6. Ce que jsdom a appris au passage (utile a l'oral, Cr 2.d.3)

Deux particularites de jsdom (le DOM simule utilise par les tests JS du
projet, sans moteur de rendu reel) sont apparues en ecrivant les tests, et
auraient pu faire passer un comportement correct pour un bug :

1. **La portee des ecouteurs.** a11y-dialog ecoute Echap/Tab sur le conteneur
   de la modale, pas sur `document`. Un test qui distribue l'evenement
   directement sur `document` ne traverse pas la modale et ne declenche
   rien — il faut distribuer depuis l'element actif (comme le ferait une
   vraie frappe clavier).
2. **L'absence de mise en page.** a11y-dialog 8 ne considere un element
   « focusable » que s'il a une boite visible
   (`offsetWidth`/`offsetHeight`/`getClientRects().length`). jsdom ne calcule
   aucune mise en page : ces trois valeurs restent a 0 par defaut (limite
   connue et documentee de jsdom, pas un bug du test). Sans un stub de
   `offsetHeight`, le piege de tabulation trouve zero element « focusable » et
   ne fait rien. C'est d'ailleurs pour cette meme raison qu'a11y-dialog teste
   sa propre librairie avec Cypress, un navigateur reel, et pas avec jsdom
   (`package.json` du paquet : `"test": "cypress run --browser chrome"`).

Consequence assumee : les tests unitaires de ce lot verifient que
`confirm-modal.js` cable correctement la librairie (bon evenement, bon
element cible, bon attribut) sous un DOM simule avec mise en page forcee. Ils
ne remplacent pas une verification en navigateur reel de la mise en page
effective (tests/e2e, Playwright) — hors perimetre de ce lot (fichiers
`tests/e2e/` non touches).

---

## 7. Points de defense a l'oral (Cr 2.d.3 — expliquer le fonctionnement)

- **Le cycle de vie d'une instance.** `new A11yDialog(el)` initialise les
  attributs ARIA et pose un seul ecouteur permanent (`click` sur `document`,
  phase de capture) qui reste actif jusqu'a `.destroy()`. `.show()` memorise
  l'element actif, retire `aria-hidden`, deplace le focus (cible `[autofocus]`
  ou le conteneur), puis pose deux ecouteurs supplementaires, actifs
  uniquement pendant que la modale est ouverte : un `focus` sur `document.body`
  (rattrape le focus s'il s'evade) et un `keydown` sur le conteneur (Echap +
  Tab). `.hide()` fait l'inverse et restaure le focus. Savoir dessiner cette
  frise (construct -> show -> [ouvert] -> hide -> destroy) est le coeur de
  Cr 2.d.3.
- **Pourquoi le conteneur et pas la boite interne porte `role="dialog"`.**
  Parce que c'est l'element passe au constructeur qui devient, du point de vue
  de la librairie ET de l'assistance technique, « le dialogue » — la boite
  `role="document"` n'est qu'un habillage de contenu recommande pour
  certains lecteurs d'ecran plus anciens (NVDA).
  `aria-modal="true"` sur ce conteneur remplace le `aria-hidden` pose a la
  main sur chaque frere : c'est la technique que recommande aujourd'hui
  l'ARIA Authoring Practices Guide.
- **Pourquoi l'ordre `hide()` puis `onConfirm()`.** Le bouton Confirmer porte
  `data-a11y-dialog-hide` : au clic, l'ecouteur de la librairie (capture, sur
  `document`, donc traverse en premier, avant que l'evenement n'atteigne le
  bouton lui-meme) ferme la modale ; le second ecouteur, pose sur le bouton et
  declenche en phase de cible, appelle ensuite `onConfirm()`. C'est la meme
  sequence que l'ancien code (`close(); onConfirm();`), obtenue ici par
  l'ordonnancement natif capture-puis-cible des evenements DOM plutot que par
  un appel sequentiel ecrit a la main.
- **Pourquoi `destroy()` est differe d'un microtask.** Voir section 5,
  dernier paragraphe — un appel synchrone depuis le gestionnaire `hide`
  boucierait, puisque `destroy()` rappelle `hide()` en interne et que le
  drapeau `shown` ne repasse a `false` qu'apres la diffusion complete de
  l'evenement `hide`.
- **La difference avec l'ancien code, en une phrase.** L'ancien code
  ecoutait Tab/Echap sur `document` (portee large, active des la pose de
  l'ecouteur) ; la librairie les ecoute sur le conteneur de la modale (portee
  etroite, coherente avec un piege de tabulation concu pour retenir le focus
  a l'interieur). C'est un choix de conception assume par la librairie,
  documente dans son code source (gestion explicite des dialogues imbriques),
  pas une regression.

---

## Recapitulatif de couverture

| Critere | Statut | Fondement |
|---|---|---|
| Cr 2.d.1 (probleme specifique) | **Couvert** | Piege de tabulation + `aria-hidden` de fond dupliques a l'identique dans 4 fichiers (section 1, citation verbatim) ; probleme reconnu par l'ARIA APG. |
| Cr 2.d.2 (selon la doc) | **Couvert, avec une reserve nommee** | Balisage, instanciation dynamique, attribut `autofocus`, fermeture declarative : suivent la documentation officielle (section 4). Seul le clic-fond devie du div dedie documente, pour une raison de perimetre de fichiers explicitement nommee. |
| Cr 2.d.3 (expliquer le fonctionnement) | **Couvert** | Cycle de vie de l'instance, raison du deplacement de `role="dialog"`, ordonnancement capture/cible, subtilite `destroy()`/microtask, limites de jsdom : sections 5 a 7. |

Chiffres de non-regression (mesures, pas estimes) :
- Tests JS : 209 -> 217 (`npm run test:js` ; 6 tests de l'ancienne
  `confirm-modal.test.js` remplaces par 14 nouveaux couvrant en plus le piege
  de tabulation et la liberation de l'ecouteur `document`).
- Tests PHP : 755, inchange (`phpunit.phar -c phpunit.xml`) — aucun fichier
  PHP n'a ete touche par ce lot.

Confiance globale sur la validation stricte de C2.d : assumee et argumentee,
avec deux reserves nommees plutot que masquees (le clic-fond en section 4, le
perimetre a une seule modale en section 3).
