# Preuve 06 — Audit d'accessibilite MESURE (competence C1.c)

Titre professionnel RNCP 37805 — Bloc 1 (developpement front-end)

**Ce que ce document comble.** La preuve `04-accessibilite-rgaa.md` conclut le critere
Cr 1.c.3 par une reserve explicite : « les ratios de contraste exacts n'ont pas ete
mesures avec un outil dedie ([UNVERIFIED], section 8) ». Le meme aveu revient en
section 8, reserve n° 2, et dans les reserves consolidees du `README.md` du dossier.
Ce document remplace cette reserve par **407 ratios de contraste mesures** sur 11 ecrans
reels, et par le detail des 10 elements qui passent sous le seuil.

**Avertissement de lecture.** Ce rapport n'est pas vert. Il liste 10 noeuds de texte
sous le seuil WCAG AA, dont 9 dans le back-office. C'est volontaire : un audit qui ne
trouve rien est un audit qu'on n'a pas fait. Chaque defaut est nomme, chiffre, et
accompagne d'une correction calculee.

---

## 1. Outil, version, et regles activees

| Element | Valeur |
|---|---|
| Moteur d'analyse | `axe-core` **4.13.0** (licence MPL-2.0) |
| Liaison navigateur | `@axe-core/playwright` **4.13.0** (version exacte epinglee, pas une plage) |
| Navigateur | Chromium 131.0.6778.33, image officielle `mcr.microsoft.com/playwright:v1.49.1-jammy` |
| Familles de regles | `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` |
| Date de la campagne | 2026-09-22 |
| Ecrans analyses | 11 (6 borne, 5 back-office) |

### Pourquoi exactement ces quatre familles de regles

Le RGAA n'est pas un jeu de regles concurrent de WCAG : sa methodologie teste les
criteres de succes WCAG, jusqu'au niveau AA. Les quatre etiquettes activees couvrent
donc precisement le perimetre opposable :

- `wcag2a` / `wcag2aa` — WCAG 2.0, niveaux A et AA.
- `wcag21a` / `wcag21aa` — les criteres ajoutes par WCAG 2.1 (dont 1.4.11, contraste
  des elements non textuels).

Les regles qu'`axe` etiquette `best-practice` sont **volontairement exclues**. Elles ne
correspondent a aucun critere RGAA opposable ; les inclure aurait gonfle le nombre de
violations avec des remarques de confort et rendu le compte-rendu trompeur. Le choix est
ecrit dans le code : `tests/e2e/a11y.spec.js` (cherchez `TAGS_WCAG_AA`).

### Pourquoi un navigateur reel etait indispensable

Les tests d'accessibilite deja presents dans le depot (`tests/js/a11y.test.js`,
`tests/js/skip-link.test.js`) tournent sous jsdom. jsdom analyse le balisage mais **ne
fait aucun rendu** : il n'y a ni couleur calculee, ni geometrie, donc aucun ratio de
contraste possible. C'est la raison structurelle pour laquelle la preuve 04 ne pouvait
pas chiffrer ses contrastes. `axe-core` s'execute a l'interieur de la page dans un vrai
Chromium, lit les styles calcules et compose les fonds : c'est ce qui produit les
chiffres ci-dessous.

---

## 2. Reproductibilite

```bash
# Monte une stack jetable isolee, lance axe sur les 11 ecrans, depose les artefacts,
# puis demonte tout. Aucune dependance Node/Playwright sur l'hote.
tests/e2e/run-a11y.sh
```

- **La production n'est jamais jointe.** Le script monte sa propre stack Docker
  (`-p wakdoa11y`, surcouche `tests/e2e/docker-compose.a11y.yml`) en parallele de toute
  stack existante, sans collision de nom ni de port. `corentin-wakdo.stark.a3n.fr` n'est
  pas sollicite.
- **Aucune commande n'est creee.** L'audit lit le document rendu. Les ecrans profonds
  exigent un etat client (sans mode de consommation, `nav.js` renvoie a l'accueil ;
  panier vide, `page-payment.js` renvoie aux categories) : cet etat est **seme dans
  `localStorage` / `sessionStorage`**, cote navigateur. `checkout.submitOrder` n'est
  jamais appele, aucun POST de commande ne part.
- **Double passage identique.** La campagne a ete jouee deux fois de suite. Les deux
  fichiers `contrastes-mesures.csv` sont **identiques octet pour octet** (407 lignes de
  mesure), et les verdicts des 11 ecrans sont inchanges.
- **Controle independant du calcul.** Les ratios rendus par axe ont ete recalcules a la
  main avec la formule WCAG (luminance relative sRGB, `(L1 + 0,05) / (L2 + 0,05)`) :
  `#767676` sur `#FFFFFF` -> 4,54 (axe : 4,54) ; `#767676` sur `#F5F5F5` -> 4,17
  (axe : 4,16) ; `#6B7280` sur `#F5F5F5` -> 4,43 (axe : 4,43) ; `#C8920A` sur `#FFFFFF`
  -> 2,77 (axe : 2,77). L'outil n'est pas cru sur parole.

### Etat de l'arbre au moment de la mesure

Les ratios dependent du CSS servi. Pour que le chiffre reste verifiable, voici
l'empreinte SHA-256 des fichiers determinants au moment de la campagne :

| Fichier | SHA-256 (16 premiers caracteres) |
|---|---|
| `src/public/borne/assets/css/style.css` | `04c27ae6d22ab2e8` |
| `src/public/admin/assets/css/admin.css` | `04cd4a9e8bf2d690` |
| `src/app/Views/admin/layout.php` | `cfe3a277b0389ef8` |

Toute modification de ces feuilles de style invalide les chiffres : relancer
`tests/e2e/run-a11y.sh`.

---

## 3. Perimetre analyse

Onze ecrans, chacun dans un etat **representatif** et non a vide.

| Ecran | Adresse | Etat impose |
|---|---|---|
| accueil | `/index.html` | etat initial |
| categories | `/categories.html` | grille reellement peuplee depuis `GET /api/categories` |
| produits | `/products.html?category=2` | grille peuplee + panneau de commande a 2 lignes |
| produits-modale-options | idem, modale ouverte | modale d'options ouverte, **animation d'ouverture terminee** |
| paiement | `/payment.html` | recapitulatif calcule sur un panier non vide |
| confirmation | `/confirmation.html` | numero de commande et montant affiches |
| admin-connexion | `/login` | formulaire, avant authentification |
| admin-tableau-de-bord | `/admin/dashboard` | apres connexion, avec graphique |
| admin-ingredients | `/admin/ingredients` | vue la plus longue du back-office, avec son sommaire d'ancres |
| admin-produits | `/admin/products` | liste du catalogue |
| admin-commandes | `/admin/orders` | liste (etat vide du jour) |

Resolutions retenues : **1080x1920** pour la borne (l'ecran tactile fixe portrait
reellement cible, et non le 1280x720 du profil « Desktop Chrome » par defaut), et
**1440x900** pour le back-office (poste de gestion). Les regles sensibles a la mise en
page doivent etre evaluees sur la geometrie que l'utilisateur voit.

Deux surfaces meritent d'etre signalees, parce qu'aucune lecture statique du depot ne
peut les atteindre : la **modale d'options produit** et le **panneau de commande** sont
integralement construits en JavaScript ; leur balisage n'existe dans aucun fichier
`.html`. Ils sont ici audites tels qu'ils s'affichent.

---

## 4. Resultats par ecran

Gravites `axe` : `critical` > `serious` > `moderate` > `minor`. Le comptage porte sur le
**nombre de noeuds** en faute, pas sur le nombre de regles.

| Ecran | critical | serious | moderate | minor | Regles en violation |
|---|---|---|---|---|---|
| accueil | 0 | **0** | 0 | 0 | — |
| categories | 0 | **0** | 0 | 0 | — |
| produits | 0 | **0** | 0 | 0 | — |
| produits-modale-options | 0 | **0** | 0 | 0 | — |
| paiement | 0 | **0** | 0 | 0 | — |
| confirmation | 0 | **1** | 0 | 0 | `color-contrast` |
| admin-connexion | 0 | **0** | 0 | 0 | — |
| admin-tableau-de-bord | 0 | **3** | 0 | 0 | `color-contrast` |
| admin-ingredients | 0 | **2** | 0 | 0 | `color-contrast` |
| admin-produits | 0 | **2** | 0 | 0 | `color-contrast` |
| admin-commandes | 0 | **2** | 0 | 0 | `color-contrast` |
| **Total** | **0** | **10** | **0** | **0** | 1 regle distincte |

Lecture honnete de ce tableau :

- **Zero violation de gravite `critical`** sur les 11 ecrans.
- **Une seule regle** est mise en defaut sur tout le perimetre : `color-contrast`
  (WCAG 1.4.3, niveau AA). Aucune violation sur les alternatives textuelles, les roles
  ARIA, les etiquettes de formulaire, les reperes de structure, la langue ou l'ordre des
  titres — c'est-a-dire sur tout ce que la preuve 04 affirmait, et qui se trouve ici
  confirme par la mesure.
- **9 des 10 noeuds en faute sont dans le back-office.** Le front borne, qui est le
  perimetre evalue au titre du Bloc 1, n'en porte qu'un seul.
- Cinq ecrans sur six cote borne sont a zero violation.

---

## 5. Les 10 violations reelles, une par une

Toutes relevent de la meme regle `color-contrast` (`axe` : « Elements must meet minimum
color contrast ratio thresholds »), rattachee a **WCAG 1.4.3 Contraste (minimum)**,
niveau AA. Les seuils appliques sont ceux que le moteur
rend lui-meme, noeud par noeud, dans le champ `seuil_attendu` des artefacts :
**4,5:1** pour le texte courant et **3:1** pour le texte dit large. Dans les mesures de
cette campagne, la bascule vers 3:1 se produit a partir de 20 px gras.

### 5.1 Borne — confirmation : le libelle du numero de commande

| | |
|---|---|
| Ecran | `confirmation.html` |
| Element | `<span class="confirmation-banner__number-label">Votre numero de commande</span>` |
| Mesure | **4,16:1** — texte `#767676` sur fond `#F5F5F5`, 14 px normal |
| Seuil | 4,5:1 |
| Ecart | manque 0,34 |

**Cause exacte, et elle est instructive.** Le token concerne est
`--color-text-muted: #767676`, declare dans `style.css` avec le commentaire
`min WCAG AA contrast on white` (cherchez `--color-text-muted`). Ce commentaire est
**exact** : la mesure sur fond blanc donne **4,54:1**, soit AA tout juste atteint avec
0,04 de marge. Le probleme n'est pas le token, c'est son emploi : ici il est pose sur le
bloc numero de la banniere, dont le fond calcule est `#F5F5F5`. Le meme gris, sur un
fond legerement plus sombre, tombe a 4,16 et sort du seuil.

C'est exactement le genre de defaut qu'une relecture de code ne voit pas et qu'une
mesure attrape : le token etait calibre pour un fond, il est utilise sur un autre.

**Correction proposee (deux options, la premiere recommandee).**

1. **Corriger le token, pas l'usage.** Remplacer `#767676` par **`#6E6E6E`** :
   5,10:1 sur blanc et **4,68:1 sur `#F5F5F5`**. Un seul changement, correct sur les deux
   fonds du design system, ecart visuel negligeable. Le commentaire
   `min WCAG AA contrast on white` doit alors etre reecrit, puisqu'il documente une
   hypothese (fond blanc) qui n'est pas tenue partout.
2. **Changer la couleur de ce seul libelle** pour le token deja existant
   `--color-text-secondary` (`#4A4A4A`), mesure a **8,13:1 sur `#F5F5F5`**. Correction
   locale et sure, mais elle laisse le piege du token muted intact ailleurs.

### 5.2 Back-office — le « do » du nom de marque dans la barre laterale (4 ecrans)

| | |
|---|---|
| Ecrans | tableau de bord, ingredients, produits, commandes (1 noeud chacun) |
| Element | `.sidebar-brand-name > span` — le `<span>do</span>` de « Wak**do** » |
| Mesure | **2,77:1** — texte `#C8920A` sur fond `#FFFFFF`, 21 px gras |
| Seuil | 3:1 (texte large) |
| Ecart | manque 0,23 |

Le token en cause est `--color-yellow-ink: #C8920A` (cherchez `--color-yellow-ink` dans
`admin.css`), applique par la regle `.sidebar-brand-name span`.

**Argument de defense a preparer, et sa limite.** Le critere WCAG 1.4.3 comporte une
exception pour les logotypes : le texte qui fait partie d'un logo ou d'un nom de marque
echappe a l'exigence de contraste minimum. `.sidebar-brand-name` est bien le nom de
marque « Wakdo » rendu en texte, l'exception est donc plaidable. Deux precautions
avant de s'appuyer dessus a l'oral : **relire le texte exact de l'exception** dans WCAG
2.1 et sa reprise RGAA (elle n'a pas ete rouverte pour ce rapport, la citation ci-dessus
est de memoire) ; et admettre qu'elle repose sur une appreciation — est-ce un logotype,
ou un titre stylise ? L'ecart n'etant que de 0,23, il coute moins cher de corriger que
de plaider.

**Correction proposee.** Passer `--color-yellow-ink` de `#C8920A` a **`#B8860B`** :
**3,25:1** sur blanc, soit le seuil atteint avec une marge reelle. Attention, le token
`--color-yellow-dark: #B8900B` deja present dans la palette admin **ne suffit pas** :
calcule a 2,99:1 par la meme formule, il reste sous le seuil de 0,01. C'est une fausse
solution, verifiee avant d'etre proposee.

**Consequence a verifier.** `--color-yellow-ink` est utilise a trois autres endroits
d'`admin.css` (cherchez `var(--color-yellow-ink)`). Assombrir le token les modifie
aussi ; c'est souhaitable pour le contraste, mais la relecture visuelle reste a faire.

### 5.3 Back-office — les sous-titres de page sur le fond gris (5 noeuds, 4 ecrans)

| | |
|---|---|
| Ecrans | tableau de bord (2 noeuds), ingredients (1), produits (1), commandes (1) |
| Elements | `.page-subtitle` (3 ecrans), et `.admin-empty` sur la liste des commandes |
| Mesure | **4,43:1** — texte `#6B7280` sur fond `#F5F5F5`, 13 px et 14 px normal |
| Seuil | 4,5:1 |
| Ecart | manque 0,07 |

Textes concernes, releves tels quels : « Bienvenue, Wakdo Admin — voici l'essentiel de
votre restaurant aujourd'hui. », « 0,00 EUR aujourd'hui — 0 commande(s) payee(s). »,
« Ce qui est bas a reapprovisionner, en un coup d oeil », « Gestion des produits du
catalogue », « Aucune commande pour le moment. ».

**Meme cause structurelle que 5.1.** Le token `--color-text-muted: #6B7280` d'`admin.css`
est parfaitement conforme sur les fonds clairs du back-office — la campagne le mesure a
**4,83:1 sur `#FFFFFF`** (46 occurrences) et **4,62:1 sur `#F9FAFB`** (8 occurrences).
Il ne tombe que sur `#F5F5F5`, le fond de page (`--color-page`). Le meme piege que sur la
borne, dans l'autre feuille de style : un gris calibre sur les cartes blanches, employe
sur le fond de page.

**Correction proposee.** Remplacer `#6B7280` par **`#69707D`** : 4,98:1 sur blanc,
**4,57:1 sur `#F5F5F5`**. C'est un assombrissement de 2 %, invisible a l'oeil,
qui rend le token correct sur les trois fonds du back-office.

### Recapitulatif des corrections

| Perimetre | Token | Actuel | Propose | Gain mesure |
|---|---|---|---|---|
| borne | `--color-text-muted` | `#767676` (4,16 sur gris) | `#6E6E6E` | 4,68 sur gris |
| admin | `--color-text-muted` | `#6B7280` (4,43 sur gris) | `#69707D` | 4,57 sur gris |
| admin | `--color-yellow-ink` | `#C8920A` (2,77 sur blanc) | `#B8860B` | 3,25 sur blanc |

Ces trois valeurs sont calculees, pas estimees : la formule de contraste WCAG a ete
appliquee a chaque candidat, du plus clair au plus fonce, et la valeur retenue est la
moins assombrie qui franchisse le seuil avec de la marge. **Ces corrections ne sont pas
appliquees dans ce lot** : ce document est un rapport de mesure, la modification des
feuilles de style releve d'un lot distinct.

---

## 6. Les ratios mesures, en clair

C'est le trou que ce document comble. **407 ratios mesures**, sur 11 ecrans, soit
**65 combinaisons distinctes** (couleur de texte, couleur de fond composee, taille,
graisse). Le detail complet est dans `rapports/contrastes-mesures.csv`.

### Borne — les combinaisons reellement rendues

| Ratio mesure | Texte | Fond | Contexte | Verdict |
|---|---|---|---|---|
| 17,40:1 | `#1A1A1A` | `#FFFFFF` | texte principal sur carte | conforme |
| 16,42:1 | `#1A1A1A` | `#FFF8E6` | categorie active du bandeau | conforme |
| 15,96:1 | `#1A1A1A` | `#F5F5F5` | titres sur fond de page | conforme |
| 11,15:1 | `#1A1A1A` | `#FFC72C` | texte sur le jaune de marque | conforme |
| 8,86:1 | `#4A4A4A` | `#FFFFFF` | texte secondaire sur carte | conforme |
| 8,12:1 | `#4A4A4A` | `#F5F5F5` | texte secondaire sur fond de page | conforme |
| 4,54:1 | `#767676` | `#FFFFFF` | texte attenue sur carte | conforme, marge 0,04 |
| **4,16:1** | `#767676` | `#F5F5F5` | libelle du numero de commande | **sous le seuil (4,5:1)** |

Le point notable est la **derniere marche** : le token attenu de la borne passe AA sur
blanc avec 0,04 de marge, et echoue des que le fond n'est plus blanc. La preuve 04
affirmait que ce token etait « choisi pour le seuil de contraste AA sur blanc » : la
mesure **confirme l'affirmation sur blanc** et **revele qu'elle ne couvre pas les autres
fonds du design system**.

### Back-office — les combinaisons reellement rendues

| Ratio mesure | Texte | Fond | Contexte | Verdict |
|---|---|---|---|---|
| 17,40:1 | `#1A1A1A` | `#FFFFFF` | texte principal | conforme |
| 16,65:1 | `#1A1A1A` | `#F9FAFB` | texte sur surface | conforme |
| 15,96:1 | `#1A1A1A` | `#F5F5F5` | titres sur fond de page | conforme |
| 11,15:1 | `#FFC72C` / `#1A1A1A` | `#1A1A1A` / `#FFC72C` | etiquettes de marque | conforme |
| 9,36:1 | `#374151` | `#F3F4F6` | badge neutre | conforme |
| 8,86:1 | `#4A4A4A` | `#FFFFFF` | texte secondaire (99 occurrences) | conforme |
| 8,31:1 | `#991B1B` | `#FFFFFF` | compteur `.stock-summary__count` en danger (Ingredients) | conforme |
| 7,68:1 | `#065F46` | `#FFFFFF` | compteur `.stock-summary__count` au vert (Ingredients) | conforme |
| 7,09:1 | `#92400E` | `#FFFFFF` | compteur `.stock-summary__count` en alerte (Ingredients) | conforme |
| 6,77:1 | `#065F46` | `#D1FAE5` | badge de succes | conforme |
| 6,36:1 | `#92400E` | `#FEF3C7` | badge d'alerte | conforme |
| 4,83:1 | `#6B7280` | `#FFFFFF` | texte attenue sur carte | conforme |
| 4,62:1 | `#6B7280` | `#F9FAFB` | texte attenue sur surface | conforme |
| **4,43:1** | `#6B7280` | `#F5F5F5` | sous-titres de page | **sous le seuil (4,5:1)** |
| **2,77:1** | `#C8920A` | `#FFFFFF` | « do » du nom de marque | **sous le seuil (3:1)** |

Observation qui merite d'etre dite a l'oral : **les couleurs d'etat du back-office
(succes, alerte, danger, neutre) sont toutes largement conformes**, entre 6,36 et 9,36.
Ce sont precisement celles qui portent de l'information — la mesure confirme que
l'information d'etat n'est pas rendue par une couleur faible.

---

## 7. Ce que l'outil n'a pas pu trancher (11 noeuds)

`axe` distingue trois verdicts : conforme, en violation, et **indetermine** — quand la
regle s'applique mais que le moteur ne peut pas conclure seul. Ces cas ne sont ni des
succes ni des echecs, et les passer sous silence serait malhonnete.

### 7.1 Sept etiquettes du graphique du tableau de bord

Motif rendu par axe : « Element's background color could not be determined because
element contains an image node ». Ce sont les sept `<text class="dash-label">` de l'axe
des dates du graphique SVG (`16/09` a `22/09`). Le fond etant un element SVG et non une
couleur unie, le moteur refuse de calculer. **A verifier a la main**, ou a rendre
calculable en posant un fond uni explicite derriere les etiquettes.

### 7.2 Quatre noeuds `aria-hidden-focus` sur la modale d'options

Motif rendu par axe : « Check that focusable elements are not tabbable in the current
state ». Quand la modale d'options s'ouvre, `product-options.js` pose
`aria-hidden="true"` sur les elements freres de l'arriere-plan (cherchez
`bgSiblings.forEach` dans ce fichier) : le lien d'evitement, l'en-tete, la zone de
commande et le bouton de bascule de police. Ces quatre elements restent techniquement
focalisables ; axe ne peut pas savoir si la tabulation peut reellement les atteindre.

**Lecture honnete.** Le fichier implemente bien un piege de focus (cherchez
`Piege Tab/Shift+Tab a l'interieur de la modale`), mais l'ecouteur est pose **sur la
modale elle-meme**. Tant que le focus part de l'interieur, la boucle tient. Si le focus
sortait par un autre chemin, plus rien n'empecherait la tabulation de parcourir un
arriere-plan declare invisible aux technologies d'assistance — un utilisateur clavier
atteindrait alors du contenu que le lecteur d'ecran ignore.

**Piste de durcissement** : poser l'attribut `inert` sur l'arriere-plan plutot que le
seul `aria-hidden`. `inert` retire les elements de l'ordre de tabulation au niveau du
navigateur ; la propriete devient alors verifiable par l'outil au lieu de dependre d'un
ecouteur. La meme remarque vaut pour `confirm-modal.js`, qui applique le meme motif.

### 7.3 Deux boutons de quantite du panneau de commande

Motif : « Element content contains only non-text characters ». Les boutons
`.order-panel__qty-btn` de decrement contiennent le caractere `&minus;`. `axe` ne
calcule pas de contraste sur un contenu qui n'est pas du texte. Ce n'est pas un defaut :
ces boutons portent par ailleurs un `aria-label` explicite (« Diminuer la quantite de
... »), verifie conforme par la meme campagne.

---

## 8. Un enseignement de methode : ne pas mesurer pendant une animation

Le premier passage de cette campagne a rapporte **4 violations de contraste sur la modale
d'options**, avec des couleurs de fond de `#E9E9E9` ; le passage suivant en rapportait
encore 4, mais avec un fond de `#E3E3E3`. Deux chiffres differents pour le meme element :
la mesure n'etait pas reproductible, donc elle ne valait rien.

**Cause.** La modale entre par une animation (`composer-fade-in` dans `style.css`). Le
moteur lisait un **fond composite transitoire**, c'est-a-dire l'arriere-plan translucide
en cours de fondu, pas la couleur finale.

**Correction.** Le test attend desormais la fin de toutes les animations de la modale
avant de mesurer (`tests/e2e/a11y.spec.js`, cherchez `getAnimations`). Une fois la
mesure faite sur l'etat stable, la modale d'options ressort a **zero violation**, avec un
ratio minimum mesure de 8,12:1.

Ce point vaut d'etre defendu a l'oral : les quatre violations initiales n'etaient pas un
defaut de la page, c'etait un **defaut de protocole de mesure**. Un rapport qui les
aurait publiees telles quelles aurait accuse le code a tort.

---

## 9. Ce que cette campagne ne demontre PAS

Les limites sont structurelles, pas des oublis.

1. **Un moteur automatique ne couvre pas tout le RGAA.** `axe` verifie ce qui est
   decidable par programme. La **pertinence** d'une alternative textuelle, la logique de
   l'ordre de lecture, la clarte d'un intitule de bouton, la coherence d'un parcours :
   rien de tout cela n'est mesurable ici. Un ecran a zero violation `axe` n'est pas un
   ecran conforme RGAA.
2. **Aucun audit avec un lecteur d'ecran reel.** La reserve n° 1 de la preuve 04 reste
   entiere : le rendu sous NVDA, VoiceOver ou TalkBack n'a pas ete teste. Cette campagne
   ne la leve pas, elle ne portait pas sur ce point.
3. **Un seul moteur de rendu.** Chromium 131. Les couleurs calculees peuvent differer a
   la marge sur un autre moteur, notamment sur les fonds composites.
4. **Onze ecrans, pas toute l'application.** Le back-office compte 34 vues ; cinq ont ete
   auditees. Les formulaires de creation et d'edition, les modales de confirmation admin
   et l'ecran cuisine n'ont pas ete mesures.
5. **La mesure est datee et liee a l'arbre.** Les empreintes de la section 2 la pinnent.
   Une retouche de couleur invalide les chiffres.
6. **Zoom et redimensionnement non evalues.** La borne pose `touch-action: manipulation`
   et vise un ecran fixe ; le critere de redimensionnement du texte reste traite comme en
   section 7 de la preuve 04.

---

## 10. Barriere de non-regression

Ce n'est pas qu'un rapport : la campagne est **rejouee a chaque execution de
`tests/e2e/run.sh`**, parce que `a11y.spec.js` vit dans le meme dossier de tests de bout
en bout.

- Le fichier porte une liste `ACCEPTE` des regles **actuellement mesurees** par ecran, avec
  la cause en commentaire. Si une regle WCAG AA **nouvelle** apparait sur un ecran, le test
  echoue et nomme la regle.
- La liste consigne l'etat mesure, pas un etat souhaite. Une entree se retire quand la
  correction est faite, jamais pour faire passer un test.
- La barriere est appliquee **apres** l'audit de tous les ecrans, pas pendant : une
  assertion qui tombe au premier ecran priverait le rapport des suivants.
- `run.sh` joue la barriere mais **n'ecrit aucun fichier** dans `docs/` ; seul
  `run-a11y.sh` depose les artefacts (il pose la variable `A11Y_OUT`). Le dossier de
  preuves ne peut donc pas etre reecrit par accident.

---

## 11. Artefacts versionnes

Tout est sous `rapports/` :

| Fichier | Contenu |
|---|---|
| `resume.json` | synthese machine : 11 ecrans, violations par gravite, nombre de mesures, ratio minimum, nombre de noeuds non calculables |
| `contrastes-mesures.csv` | **407 lignes** de mesure : ecran, compartiment, selecteur, couleur de texte, couleur de fond, ratio, seuil attendu, taille, graisse, extrait. Separateur point-virgule (ouverture directe en tableur francais) |
| `axe-<ecran>.json` (x11) | sortie par ecran : violations et indetermines **integraux**, toutes les mesures de contraste, decompte des regles conformes, liste des regles non applicables |

**Une reduction, annoncee.** Les fichiers `axe-<ecran>.json` ne portent pas le compartiment
`passes` d'`axe` dans son integralite : il contient un noeud par element teste par chaque
regle, soit plusieurs megaoctets par ecran, et un dossier de preuves illisible n'est pas
une preuve. Ce qui porte l'information est conserve tel quel (violations, indetermines, et
**toutes** les mesures de contraste, y compris celles des elements conformes) ; le reste
est reduit a un decompte par regle. La reduction est faite dans
`tests/e2e/a11y.spec.js` (cherchez `Reduit un resultat axe a un artefact versionnable`).

---

## 12. Effet sur la preuve 04 et points de defense

### Ce qui change dans la preuve 04

| Point de la preuve 04 | Etat apres mesure |
|---|---|
| Cr 1.c.3, verdict « conforme sur les etats identifies. Reserve : ratios non mesures » | **Reserve levee.** 407 ratios mesures. Le principe « pas la couleur seule » reste conforme ; un libelle de la confirmation est sous le seuil (section 5.1) |
| Section 6, ligne « Contraste texte suffisant », verdict `partiel`, `[UNVERIFIED]` | **Verifie.** Les tokens `#1A1A1A`, `#4A4A4A` et le jaune de marque sont largement conformes ; `#767676` est conforme sur blanc (4,54) et non conforme sur `#F5F5F5` (4,16) |
| Section 8, reserve n° 2 « Ratios de contraste non mesures a l'outil » | **Obsolete**, a remplacer par le renvoi a ce document |
| Section 8, reserve n° 1 « Aucun audit avec lecteur d'ecran reel » | **Inchangee.** Cette campagne ne la traite pas |

Les sections correspondantes de `04-accessibilite-rgaa.md` et les reserves consolidees du
`README.md` du dossier restent a mettre a jour ; ce lot ne les modifie pas.

### Points de defense a l'oral

1. **Montrer que la mesure a servi a quelque chose.** La preuve 04 affirmait que
   `#767676` visait AA sur blanc. La mesure le confirme (4,54) **et** montre que le meme
   token tombe a 4,16 des qu'on le pose sur le gris de fond. C'est un defaut qu'aucune
   relecture n'aurait attrape : c'est l'argument le plus fort du document.
2. **Assumer le rapport rouge.** Dix noeuds sous le seuil, nommes, chiffres, avec trois
   corrections calculees. Un dossier qui affirme la perfection se fait demonter en deux
   questions ; celui-ci annonce ses defauts et leur remede.
3. **Expliquer le partage borne / back-office.** Neuf des dix defauts sont cote
   back-office, qui n'est pas le perimetre principal du Bloc 1. Cinq des six ecrans de la
   borne sont a zero violation.
4. **Raconter l'incident de mesure (section 8).** Quatre fausses violations produites par
   une mesure prise pendant une animation, detectees parce que deux passages ne donnaient
   pas le meme chiffre. C'est une demonstration de rigueur : on ne publie pas un chiffre
   qu'on ne sait pas reproduire.
5. **Nommer les limites avant qu'on ne les nomme pour vous.** Un moteur automatique ne
   couvre pas tout le RGAA, et zero violation `axe` ne vaut pas conformite (section 9).
6. **Montrer la barriere.** L'audit n'est pas une capture d'ecran datee : il tourne a
   chaque campagne de tests de bout en bout et echoue si une regle nouvelle apparait.

---

Perimetre couvert : Cr 1.c.3 (contraste, mesure a l'outil — reserve de la preuve 04
levee), et en renfort Cr 1.c.1 / Cr 1.c.4 (aucune violation mesuree sur les alternatives
textuelles, les roles, les etiquettes et la structure des 11 ecrans). Les references au
code se font par citation de texte cherchable, conformement a la convention du dossier.
