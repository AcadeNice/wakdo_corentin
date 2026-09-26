# Preuve 07 — Animation JavaScript du total panier (Cr 2.a.3 / Cr 2.a.4)

Titre professionnel RNCP 37805 — Bloc 1 (developpement front-end).

Criteres verbatim :

- **Cr 2.a.3** : « Les animations JavaScript developpees permettent une
  meilleure experience utilisateur. »
- **Cr 2.a.4** : « Les animations sont fonctionnelles et leurs comportements
  sont geres sur les differents navigateurs. »

Perimetre : la borne de commande client (`src/public/borne/`), comme le reste
du dossier Bloc 1.

---

## 1. Etat du projet avant ce lot

Une lecture du code montrait trois faits qui, ensemble, laissaient le critere
non couvert :

1. **Trois `@keyframes`** dans `assets/css/style.css` (cherchez `check-pop`,
   `composer-fade-in`, `composer-slide-up`) : des transitions d'entree de
   modale et d'icone de confirmation. Ce sont des animations **CSS**, pas des
   animations **JavaScript** — le critere demande explicitement les secondes.
2. **Quatre appels a `requestAnimationFrame`** dans les modules de la borne
   (`product-options.js`, `page-product-menu.js`, `page-payment.js`,
   `confirm-modal.js` — cherchez `requestAnimationFrame` dans chacun). Les
   quatre servent a differer un `focus()` d'une image d'affichage, le temps que
   le navigateur ait fini de poser l'element dans le DOM. Aucun ne fait varier
   une valeur dans le temps : ce n'est pas une animation, c'est une
   temporisation.
3. **Zero occurrence de `prefers-reduced-motion`** dans la feuille de style de
   la borne : les trois animations CSS existantes s'imposaient a tout le monde,
   y compris a une personne ayant demande a son systeme de reduire les
   animations.

Ce lot ajoute une animation reellement pilotee en JavaScript, la fait
respecter le mouvement reduit, et etend ce respect aux trois animations CSS
deja en place — pour que la reserve ne se rouvre pas des qu'on regarde
ailleurs que la nouveaute.

---

## 2. L'animation ajoutee : le total du panier qui compte

### 2.1 Ce qu'elle fait

Le panneau de commande persistant (`order-panel.js`, le recap a droite de
l'ecran de commande) affiche un total qui, jusqu'ici, **sautait** d'une valeur
a l'autre a chaque ajout, retrait ou changement de quantite : le texte etait
simplement remplace au prochain rendu. Il **compte** desormais de l'ancienne
valeur vers la nouvelle, comme un compteur qui defile, avant de se poser
exactement sur le nouveau total.

### 2.2 Pourquoi c'est une amelioration pour l'utilisateur, pas un effet

Une borne de commande fast-food enchaine les gestes vite : composer un menu,
ajouter un produit, ajuster une quantite, recommencer. Le total est la seule
information qui confirme, a chaque geste, que la commande a bien change. Un
texte qui saute est facile a manquer du coin de l'oeil, surtout si le geste
precedent (fermeture d'une modale, retour a l'ecran produits) deplace deja le
regard. Un total qui **defile visiblement** pendant quelques centaines de
millisecondes donne un signal plus difficile a manquer, et son sens de
variation (monte ou descend) confirme, sans avoir a lire le chiffre, que le
bon type de geste a ete enregistre (ajout vs retrait). C'est presente ici
comme un renforcement raisonne de la confiance dans l'interface, pas comme une
decoration : l'animation ne retarde aucune action (le client peut continuer a
composer sa commande pendant qu'elle tourne) et la valeur affichee est de
toute facon deja correcte des la premiere image, cf. 2.4. Ce lien entre
animation et perception du geste pris en compte est un choix de conception
argumente dans ce document, pas une conclusion issue d'un test utilisateur
mene sur cette borne (voir les limites, section 8).

### 2.3 Architecture : des fonctions pures, un orchestrateur mince

Le code vit dans `src/public/borne/assets/js/cart-total-animation.js`, un
nouveau module autonome, dans le style deja etabli par le depot (fonctions
pures separees du code qui touche le DOM, comme `buildPanelModel` dans
`order-panel.js`) :

- `easeOutCubic(t)` — attenuation "ease-out" : la progression demarre vite
  puis se pose en douceur sur 1. Fonction pure, testee independamment.
- `computeFrameValueCents(fromCents, toCents, progress)` — la valeur (en
  centimes entiers) a afficher pour une progression donnee. Pure : ni horloge,
  ni DOM. Fonctionne dans les deux sens (le total peut aussi bien monter que
  descendre).
- `prefersReducedMotion(win)` — lit la preference systeme (section 4).
- `animateTotalValue(el, fromCents, toCents, options)` — le seul point qui
  touche le DOM (`el.textContent`) et le temps (`requestAnimationFrame`,
  `performance.now`). `now`, `raf`, `caf` et `formatValue` sont tous
  injectables : c'est ce qui rend une animation temporelle testable sans
  navigateur ni horloge reelle (cherchez ces quatre noms dans
  `tests/js/cart-total-animation.test.js`).

Le cablage cote `order-panel.js` (cherchez `lastTotalByContainer`) : la valeur
precedemment affichee est memorisee par conteneur (un `WeakMap`, pour ne rien
retenir si le conteneur quitte le DOM). Au rendu suivant, si une valeur
precedente existe et differe de la nouvelle, l'animation part de l'une vers
l'autre. Au tout premier rendu d'une page (aucune valeur precedente
memorisee), aucune animation ne se declenche : animer un 0 vers le total
memorise en `localStorage` au chargement de la page aurait anime un geste que
le client n'a pas fait a cet instant precis.

Aucune librairie n'est utilisee : conformement a la demande de ce lot, tout le
code est ecrit a la main avec les API natives du navigateur — coherent avec le
choix vanilla deja documente pour l'ensemble de la borne
(`05-librairies-js-c2d.md`).

### 2.4 Correction garantie, animee ou non

`animateTotalValue` n'est pas la source de verite du total : le `textContent`
du noeud est deja pose a la valeur finale exacte par le gabarit HTML de
`renderOrderPanel` (cherchez `formatPrice(model.totalCents)`) avant meme que
l'animation ne demarre. L'animation ne fait que reecrire ce texte plusieurs
fois par-dessus, temporairement, pendant sa duree — et sa derniere image pose
exactement `formatPrice(toCents)` (par construction :
`computeFrameValueCents(from, to, 1)` renvoie `to`, verifie en test). Si le
script echouait ou etait interrompu avant d'avoir pu s'executer, l'utilisateur
verrait quand meme le bon total : l'animation est une amelioration posee
au-dessus d'un etat correct par defaut, pas une condition pour l'obtenir.

---

## 3. Robustesse : annulable, non bloquante

Le lot demandait explicitement une duree bornee et une animation annulable si
une nouvelle demarre avant la fin. Deux mecanismes, testes separement dans
`tests/js/cart-total-animation.test.js` :

### 3.1 Annulation

Un seul panneau de commande est expose a la fois par cette application
(`products.html` n'en contient qu'un — cherchez `data-order-panel` dans les
fichiers `.html` de la borne, une seule occurrence). Le module retient donc un
seul jeton de generation et une seule reference d'annulation (cherchez
`currentToken` et `pendingCancel`). Un nouvel appel a `animateTotalValue` :

1. Annule explicitement, via `cancelAnimationFrame` (ou l'equivalent injecte
   en test), l'image encore programmee de l'animation precedente.
2. Incremente le jeton de generation : si le navigateur invoquait quand meme
   cette image perimee malgre l'annulation, elle se reconnait perimee et
   n'ecrit plus rien (repli de securite, teste explicitement — cherchez
   « callback perime » dans le fichier de test).

Par construction de ce mecanisme, deux ajouts rapproches au panier ne font pas
courir deux compteurs en parallele sur le meme total.

### 3.2 Un plafond d'images independant du temps ecoule

Point le plus delicat de ce lot, decouvert en lisant l'environnement de test
existant avant d'ecrire le code : `tests/js/order-panel.test.js` pose
`global.requestAnimationFrame = (cb) => cb()` (cherchez cette ligne) pour
pouvoir tester le reste du panneau sans navigateur. Ce repli rappelle tout de
suite et de facon synchrone, sans laisser de temps reel s'ecouler entre deux
appels.

Une boucle d'animation naive, qui reprogrammerait une image tant que
`Date.now() - debut < duree`, boucle alors sous ce repli : le temps reel
n'avancant quasiment pas entre deux appels synchrones, il aurait fallu
accumuler des dizaines de milliers d'appels recursifs avant d'atteindre la
duree cible — largement au-dela de ce que la pile d'appel JavaScript autorise
en pratique (`RangeError: Maximum call stack size exceeded` attendu). Comme
`order-panel.js` declenche desormais l'animation a chaque changement de
total, ce repli existant aurait fait echouer la suite de tests actuelle des
le premier changement de quantite.

La parade : `animateTotalValue` plafonne le nombre d'images
(`maxFrames = Math.ceil(duration / 16) + 10`, cherchez `maxFrames`) —
independamment du temps mesure. Passe ce plafond, la progression est forcee a
1 et l'animation se termine, quelle que soit l'horloge. Sur un navigateur reel
(images environ toutes les 16 ms), le plafond ne devrait pas jouer avant la
fin naturelle de l'animation ; sous un repli synchrone comme celui des tests,
il borne le nombre d'appels. Verifie par un test dedie qui reproduit
exactement ce repli (cherchez « plafond d'images » dans
`cart-total-animation.test.js`) et par deux tests d'integration ajoutes a
`order-panel.test.js` qui font tourner un vrai changement de quantite dans cet
environnement exact.

---

## 4. Mouvement reduit (`prefers-reduced-motion`)

Respecte a deux endroits distincts, l'un ne couvrant pas l'autre :

### 4.1 L'animation JavaScript

`prefersReducedMotion(win)` interroge
`win.matchMedia('(prefers-reduced-motion: reduce)').matches`. Quand la
reponse est vraie, `animateTotalValue` pose `formatValue(toCents)`
immediatement et ne programme aucune image : pas de version ralentie, pas de
saut cache, une seule ecriture directe — le meme comportement qu'avant ce lot
pour la mise a jour du total.

Repli defensif : `jsdom` (utilise par les tests de ce depot) n'implemente pas
`matchMedia` — verifie directement (`typeof window.matchMedia` vaut
`undefined` sous `jsdom` 26.0.0, la version figee dans ce projet). Un vieux
navigateur peut se trouver dans une situation comparable. Plutot que de
laisser l'appelant recevoir une exception, `prefersReducedMotion` traite
l'absence de `matchMedia` comme « aucune preference exprimee » (retourne
`false`) : c'est la lecture recommandee par la specification Media Queries,
ou l'absence d'une fonctionnalite n'implique pas un resultat impose dans un
sens ou dans l'autre. Teste explicitement (fenetre sans `matchMedia`,
`matchMedia` qui leve une exception).

### 4.2 Les trois animations CSS existantes

`assets/css/style.css` gagne une regle universelle (cherchez `MOUVEMENT
REDUIT`) plutot que trois surcharges nominatives :

```css
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }
}
```

Deux choix motives, pas copies sans reflexion :

- **Une regle universelle plutot que cibler `check-pop`, `composer-fade-in` et
  `composer-slide-up` par leur nom** : une regle unique ne peut pas se
  desynchroniser d'une animation CSS ajoutee plus tard ailleurs dans les 2100
  lignes de ce fichier. Cibler par nom aurait fonctionne aujourd'hui et aurait
  pu se perimer silencieusement au premier `@keyframes` oublie lors d'un futur
  ajout.
- **`0.01ms` plutot que `0` strict** : `tests/e2e/a11y.spec.js` attend la fin
  des animations du composer avant de mesurer un contraste (cherchez
  `getAnimations`), via l'evenement de fin d'animation. Une duree strictement
  a zero risque, selon le moteur de rendu, de ne pas declencher cet evenement
  [UNVERIFIED — prudence de conception, non reconfirmee sur une matrice de
  navigateurs pour cette preuve] ; une duree quasi nulle a ete preferee par
  precaution, en restant imperceptible. Cette regle ne s'active que si le
  systeme demande le mouvement reduit — elle n'affecte donc pas la mesure
  existante (menee sans cette preference active) ni son resultat.

`animation-fill-mode: both`, deja pose sur les trois animations existantes,
garantit que l'element atteint immediatement son etat final visible : rien ne
reste bloque a l'etat de depart (invisible ou deplace) le temps quasi nul de
l'animation raccourcie.

`src/public/admin/assets/css/admin.css` (back-office) a ete verifie et ne
contient aucun `@keyframes` ni `animation:` : il n'y avait rien d'autre a
couvrir pour que le mouvement reduit soit respecte sur l'ensemble du code CSS
de ce depot.

---

## 5. Accessibilite du panneau anime

Le conteneur `.order-panel` porte `aria-live="polite"` (cherchez cet attribut
dans `products.html`) : toute mutation de son contenu est un candidat a une
annonce par une aide technique. Une animation qui reecrit un texte plusieurs
fois par seconde a l'interieur d'une telle region risquait de multiplier les
evenements consideres pour l'annonce vocale, la ou une seule annonce du total
final est utile.

Le noeud `.order-panel__total-value` porte desormais `aria-live="off"`
(cherchez cet attribut dans `order-panel.js`). Un attribut `aria-live` pose
sur un descendant prend le pas sur celui de son ancetre pour les mutations qui
lui sont propres : les images intermediaires de l'animation ne sont donc plus
traitees comme un flux d'annonces individuelles. Le total n'est pas retire de
l'arbre d'accessibilite pour autant — il reste lisible normalement, et,
d'apres la section 2.4, il porte de toute facon la valeur finale exacte des sa
creation dans le DOM, anime ou non.

**Lecture honnete.** Ce choix est un raisonnement d'ingenierie fonde sur la
specification ARIA (le mecanisme de priorite d'un `aria-live` imbrique y est
documente), pas une mesure sur un lecteur d'ecran reel : ce depot n'a, a ce
jour, pas ete audite avec NVDA ou VoiceOver (reserve deja posee dans
`04-accessibilite-rgaa.md` et dans le `README.md` du dossier, non levee ici).
`axe-core`, l'outil qui mesure l'audit automatise de ce projet, ne simule pas
le comportement d'annonce d'une aide technique — il verifie la validite
structurelle des attributs ARIA, et `aria-live="off"` en est une valeur
autorisee sur n'importe quel element. Le present choix ne peut donc pas etre
verifie par l'outillage existant ; il est documente ici en toute transparence
plutot que passe sous silence.

**Non-regression verifiee.** Aucun des ecrans ni interactions couverts par
`tests/e2e/a11y.spec.js` ne declenche de changement de total sur un panneau
deja rendu : le panier y est seme directement en `localStorage` avant le
chargement de la page, et la modale d'options y est ouverte sans qu'aucun clic
ne soit envoye sur « Ajouter a ma commande » (cherchez `ETAT_CLIENT` et
`po-add` dans ce fichier). Le chemin anime n'est exerce par aucun des 11
ecrans mesures. La barriere `ACCEPTE` de ce test (zero violation) reste donc
sans lien de cause a effet avec ce lot, et a ete rejouee sans regression
(section 7).

---

## 6. Compatibilite navigateurs (Cr 2.a.4)

| API utilisee | Role dans ce lot | Support |
|---|---|---|
| `requestAnimationFrame` / `cancelAnimationFrame` | Boucle d'animation | Disponible dans les moteurs de rendu evergreen (Chromium, Firefox, WebKit) depuis plusieurs annees d'apres la connaissance generale du redacteur ; deja utilise quatre fois ailleurs dans ce meme code source avant ce lot (section 1). [UNVERIFIED — non reconfirme sur caniuse.com au moment de la redaction, dans la continuite assumee de `03-conformite-cross-browser.md`]. |
| `performance.now()` | Horloge de l'animation | Meme lecture que ci-dessus. [UNVERIFIED, meme reserve]. |
| `window.matchMedia` | Detection de `prefers-reduced-motion` | Meme lecture. Repli ecrit et teste pour le cas ou elle serait absente (section 4.1) : ce n'est donc pas un point de rupture si l'hypothese de support s'averait fausse sur un moteur donne. |
| `WeakMap` | Memoire du dernier total par panneau (`order-panel.js`) | Fonctionnalite JavaScript standard ancienne (ES2015) ; aucun repli ecrit pour ce point. |

Ce tableau documente le choix et le repli a partir de la lecture du code, dans
la continuite assumee de `03-conformite-cross-browser.md` : ce depot n'a pas
execute de campagne de tests sur un parc de navigateurs reels, ni interroge
caniuse.com en direct pour cette preuve (aucune recherche web n'a ete
effectuee pendant sa redaction). Le point qui distingue ce lot du reste du
projet : la seule API des quatre pour laquelle une absence de support est
prise en compte explicitement (`matchMedia`) est precisement celle pour
laquelle un repli est ecrit et teste (section 4.1) — la fonctionnalite ne se
degrade donc pas en erreur si l'hypothese de support est fausse quelque part,
elle se degrade en « animation normale, sans la preference de mouvement
reduit prise en compte ».

Le comportement fonctionnel (Cr 2.a.4, deuxieme moitie du critere) est, lui,
verifie par des tests automatises qui ne dependent d'aucun navigateur
particulier : `tests/js/cart-total-animation.test.js` exerce la logique de
l'animation (progression, valeur finale exacte, annulation, plafond d'images)
independamment de tout moteur de rendu, en injectant des reimplementations de
`raf`/`caf`/`now` — la meme logique s'execute donc a l'identique quel que soit
le navigateur qui l'invoque, puisque rien dans son comportement ne depend
d'une particularite d'un moteur donne (pas de prefixe vendeur, pas d'API
experimentale).

---

## 7. Preuves executables

Trois commandes, toutes vertes apres ce lot :

```
npm run test:js
docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php -d memory_limit=-1 phpstan.phar analyse
docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c phpunit.xml
```

| Commande | Resultat |
|---|---|
| `npm run test:js` | 233 tests, 233 reussis (209 preexistants + 24 nouveaux : 22 dans `tests/js/cart-total-animation.test.js`, 2 d'integration ajoutes a `tests/js/order-panel.test.js`) |
| PHPStan (niveau 6, `phpstan.neon`) | `[OK] No errors` |
| PHPUnit (`phpunit.xml`) | 755 tests, 1962 assertions, `OK` (84 marques Skipped, preexistantes — aucun fichier PHP n'a ete touche par ce lot) |

Ces chiffres sont ceux du lot d'animation, a sa date. **Au 2026-09-26**, apres la refonte
du back-office : `npm run test:js` **356 tests**, PHPUnit **1 677 tests, 4 855 assertions,
`OK`**, PHPStan niveau 6 sans erreur. Les 22 tests de `cart-total-animation.test.js`
restent verts ; le module d'animation du total n'a pas ete modifie depuis ce lot.

Aucun fichier PHP n'a ete modifie : ce lot est strictement front-end (un
module JS, une feuille de style, deux fichiers de tests JS, ce document).

Le validateur W3C Nu (utilise par `01-validation-w3c.md`) n'a pas ete rejoue
dans le cadre de ce lot : aucun fichier `.html` n'a ete modifie, et le seul
balisage ajoute par ce lot en dehors du HTML statique (l'attribut
`aria-live="off"`, genere par `order-panel.js` au rendu) suit le meme motif —
un attribut `aria-live` sur un `<span>` — que celui deja present et deja
valide sur `.order-panel` lui-meme et sur `#po-qty` /
`.product-options__total` dans le DOM rendu couvert par cette preuve. Ce
raisonnement par analogie ne remplace pas une re-execution de l'outil ; il est
presente ici comme tel, pas comme une nouvelle mesure.

---

## 8. Limites et perimetre de verification

- **Pas d'audit avec un lecteur d'ecran reel** (NVDA, VoiceOver) sur le
  comportement `aria-live="off"` decrit en section 5 : reserve deja ouverte
  pour l'ensemble du dossier, non levee par ce lot.
- **Support navigateur documente a partir de la lecture du code et de la
  connaissance generale des API utilisees, pas d'une campagne de tests sur un
  parc reel ni d'une consultation en direct de caniuse.com** (section 6),
  dans la continuite assumee de `03-conformite-cross-browser.md`.
- **Le validateur W3C Nu n'a pas ete re-execute** pour ce lot precis (section
  7) : le raisonnement par motif deja valide est documente comme tel, pas
  presente comme une nouvelle mesure.
- **La duree (400 ms) et l'attenuation (ease-out cubique) sont un choix de
  conception argumente**, pas une valeur mesuree aupres d'utilisateurs reels
  de la borne : aucun test utilisateur n'a ete mene sur ce point precis.

---

Perimetre couvert : Cr 2.a.3 (animation JavaScript developpee — le compteur du
total panier — documentee et justifiee du point de vue de l'experience
utilisateur) et Cr 2.a.4 (fonctionnelle, testee automatiquement de facon
independante du navigateur, mouvement reduit respecte pour cette animation et
pour les trois animations CSS preexistantes). Les references au code se font
par citation de texte cherchable, conformement a la convention du dossier.
