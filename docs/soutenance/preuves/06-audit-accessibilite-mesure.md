# Preuve 06 — Audit d'accessibilite MESURE (competence C1.c)

Titre professionnel RNCP 37805 — Bloc 1 (developpement front-end)

**Ce que ce document comble.** La preuve `04-accessibilite-rgaa.md` conclut le critere
Cr 1.c.3 par une reserve explicite : « les ratios de contraste exacts n'ont pas ete
mesures avec un outil dedie ([UNVERIFIED], section 8) ». Le meme aveu revient en
section 8, reserve n° 2, et dans les reserves consolidees du `README.md` du dossier.
Ce document remplace cette reserve par **407 ratios de contraste mesures** sur 11 ecrans
reels, et par le detail des 10 elements qui passaient sous le seuil.

**Avertissement de lecture.** Ce rapport documente **deux campagnes**, pas une seule :

1. **Campagne AVANT correction** (sections 4 a 6, telles qu'ecrites au premier passage) :
   10 noeuds de texte sous le seuil WCAG AA, dont 9 dans le back-office. C'est volontaire :
   un audit qui ne trouve rien est un audit qu'on n'a pas fait. Chaque defaut y est nomme,
   chiffre, et accompagne d'une correction calculee.
2. **Campagne APRES correction** (section 5 bis) : les trois couleurs corrigees, puis le
   meme outillage rejoue a l'identique sur les memes 11 ecrans. Resultat :
   **0 violation, toutes gravites confondues**. La section 5 bis documente aussi un
   ecart trouve en verifiant les autres usages des tokens corriges, traite avant meme
   d'etre mesure par une campagne dediee.

Les deux campagnes sont conservees telles quelles, l'une a la suite de l'autre : un
dossier qui montre « 10 violations trouvees, voici les corrections, voici la remesure a
0 » a plus de valeur devant un jury qu'un dossier qui n'aurait rien trouve des le premier
passage, ou qu'un dossier qui aurait efface la trace du probleme initial.

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
l'empreinte SHA-256 des fichiers determinants, **avant** puis **apres** la correction de
la section 5 bis (memes 16 premiers caracteres, memes fichiers) :

| Fichier | SHA-256 AVANT (16 premiers) | SHA-256 APRES (16 premiers) |
|---|---|---|
| `src/public/borne/assets/css/style.css` | `04c27ae6d22ab2e8` | `6450730a9fa4953a` |
| `src/public/admin/assets/css/admin.css` | `04cd4a9e8bf2d690` | `c3421eef51250ca6` |
| `src/app/Views/admin/layout.php` | `cfe3a277b0389ef8` | `cfe3a277b0389ef8` (inchange) |

`layout.php` n'a pas bouge : seules les trois couleurs de token ont ete touchees, dans les
deux feuilles de style. Toute modification future de ces feuilles invalide les chiffres :
relancer `tests/e2e/run-a11y.sh`.

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

## 4. Resultats par ecran (campagne AVANT correction)

Gravites `axe` : `critical` > `serious` > `moderate` > `minor`. Le comptage porte sur le
**nombre de noeuds** en faute, pas sur le nombre de regles. Ce tableau est celui du
**premier passage**, avant toute correction ; le resultat apres correction est en
section 5 bis.

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

## 5. Les 10 violations reelles, une par une (campagne AVANT correction)

Cette section est le compte-rendu du **premier passage**, ecrit avant toute correction ;
elle est conservee intacte comme trace du diagnostic. Chaque sous-section porte
desormais un encart « Corrige » qui renvoie a la section 5 bis, ou la couleur reellement
appliquee et sa remesure sont donnees.

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

**Corrige.** L'option 1 a ete retenue telle quelle : `--color-text-muted` vaut
desormais `#6E6E6E` dans `style.css`. Remesure par la campagne apres correction
(section 5 bis) : **4,67:1** sur `#F5F5F5` (l'ecart de 0,01 avec le 4,68 calcule a la
main vient de l'arrondi, comme deja observe section 2 entre `axe` et le calcul manuel).

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

**Corrige, avec un ecart par rapport a la valeur proposee ici.** La relecture visuelle
annoncee ci-dessus a ete faite, et elle a change la couleur finale. Les trois autres
emplois sont deux icones decoratives (`.feed-ico`, du CSS mort — aucune trace dans le
balisage ; `.pin-modal-ico`, une icone SVG `aria-hidden="true"`, sans texte, donc hors du
perimetre de la regle `color-contrast`) et surtout **`.pos-tile__pastille`** : la lettre
de repli (24 px, graisse 800) affichee sur les tuiles produit du POS comptoir/drive
(`counter-order.js`, ecran hors des 11 audites ici) quand aucune image n'est disponible.
Elle utilise le meme token, sur le fond `--color-yellow-soft` (`#FFF3D1`), plus sombre
que le blanc. Calcul : `#B8860B` sur `#FFF3D1` -> **2,94:1**, sous le seuil 3:1 de
texte large. La valeur proposee ici aurait donc corrige le « do » tout en laissant une
combinaison differente, du meme token, sous le seuil — juste en dehors du perimetre
mesure par cette campagne. Valeur retenue a la place : **`#AE7F09`**, qui tient sur les
deux fonds reels : **3,60:1 sur blanc** (`do`) et **3,25:1 sur `#FFF3D1`** (pastille).
Remesure par `axe` (section 5 bis) sur le « do » : **3,59:1** (la pastille n'est pas
mesurable ici, son ecran n'etant pas dans le perimetre des 11).

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

**Corrige.** Valeur appliquee telle quelle. Remesure par la campagne apres correction
(section 5 bis) : **4,57:1** sur `#F5F5F5`, **4,98:1** sur blanc, **4,76:1** sur
`#F9FAFB` (les 0,01 d'ecart eventuels avec le calcul manuel sont le meme arrondi que
partout ailleurs dans ce document).

### Recapitulatif des corrections

| Perimetre | Token | Avant | Propose ici | **Applique** | Remesure `axe` |
|---|---|---|---|---|---|
| borne | `--color-text-muted` | `#767676` (4,16 sur gris) | `#6E6E6E` | `#6E6E6E` | 4,67 sur gris |
| admin | `--color-text-muted` | `#6B7280` (4,43 sur gris) | `#69707D` | `#69707D` | 4,57 sur gris |
| admin | `--color-yellow-ink` | `#C8920A` (2,77 sur blanc) | `#B8860B` | **`#AE7F09`** | 3,59 sur blanc |

Deux valeurs sur trois sont appliquees exactement comme calculees ici. La troisieme
(`--color-yellow-ink`) diverge, pour la raison exposee en section 5.2 : la valeur
proposee dans cette section ne tenait pas sur un usage du meme token situe hors du
perimetre des 11 ecrans mesures (`.pos-tile__pastille`). Le detail chiffre des trois
corrections, et le pourquoi de cet ecart, est en section 5 bis.

Ces trois valeurs (colonne « Propose ici ») etaient calculees, pas estimees : la formule
de contraste WCAG a ete appliquee a chaque candidat, du plus clair au plus fonce, et la
valeur retenue est la moins assombrie qui franchisse le seuil avec de la marge.

**Mise a jour.** Au moment d'ecrire cette section, les corrections n'etaient pas encore
appliquees : ce document etait un rapport de mesure pur, la modification des feuilles de
style etait renvoyee a un lot distinct. Ce lot distinct a depuis eu lieu : les trois
couleurs sont appliquees dans le code (colonne « Applique » ci-dessus), et la campagne de
remesure est en section 5 bis. Le paragraphe precedent est laisse tel quel pour la trace :
il montre le calcul fait AVANT toute modification du code, donc sans biais de
confirmation.

---

## 5 bis. Campagne APRES correction — remesure a 0 violation

Les trois couleurs de la section 5 (colonne « Applique » du recapitulatif) ont ete
changees dans le code, puis `tests/e2e/run-a11y.sh` a ete rejoue a l'identique : meme
outillage (`axe-core` 4.13.0, meme image Playwright v1.49.1-jammy), memes 11 ecrans,
meme protocole (stack jetable, etat client seme, animations de la modale attendues avant
mesure). Seul le CSS servi a change — voir les empreintes SHA-256 de la section 2.

### Ce qui a ete trouve en verifiant les AUTRES usages des tokens corriges

Avant de rejouer la mesure, chaque token corrige a ete relu dans son integralite (toutes
ses regles CSS, tous les fonds sur lesquels il atterrit), pas seulement au point qui avait
echoue — parce qu'un token sert a plusieurs endroits, et que le corriger a un seul endroit
en laissant un autre casser serait une regression deguisee en correction :

- **`--color-text-muted` (borne, `style.css`), 9 regles.** Sept ne sont pas mesurees par
  cette campagne (etats non atteints par les 5 ecrans audites : bouton primaire desactive,
  etapes du composeur non ouvertes ce jour-la, etc.). Chacune a ete relue : elles
  atterrissent toutes sur `#FFFFFF` ou `#F5F5F5` (`--color-bg-page`), les deux fonds deja
  verifies conformes pour `#6E6E6E`. Un cas merite d'etre signale par honnetete : la regle
  `.btn--primary[aria-disabled="true"]` pose ce token sur `--color-border-default`
  (`#D1D1D1`, un TROISIEME fond). Calcul : `#767676` (ancien) y valait 2,97:1, deja sous le
  seuil large-text (3:1, le bouton faisant 20px gras) — un defaut PREEXISTANT, independant
  de ce lot. `#6E6E6E` (nouveau) y vaut 3,34:1, donc corrige au passage. Cette regle n'est
  cependant appliquee nulle part dans le code actuel : les 5 endroits qui instancient un
  `.btn--primary` (4 fichiers JS — `confirm-modal.js`, `product-options.js`,
  `page-product-menu.js` a deux reprises, `page-payment.js` — plus le bouton statique de
  `confirmation.html`) ont ete relus un par un ; aucun ne pose `disabled` ni
  `aria-disabled="true"` sur ce bouton — c'est du CSS mort, sans impact utilisateur
  aujourd'hui, mais desormais correct s'il devenait un jour atteignable.
- **`--color-text-muted` (admin, `admin.css`), 44 regles CSS** referencent le token, dont
  une seule hors du perimetre texte (`::-webkit-scrollbar-thumb:hover`, qui l'emploie en
  fond de barre de defilement, pas en couleur de texte). Les 43 regles de texte restantes
  ont ete relues individuellement (voir methode ci-dessous). Aucune ne pose ce token sur
  un fond colore : les badges d'etat (succes, alerte, danger, info) observes dans ce
  fichier utilisent chacun leur propre paire couleur/fond dediee, distincte du token
  muted. Tous les fonds neutres du back-office ont ete classes par luminance pour
  verifier qu'aucun n'est plus sombre que `#F5F5F5` parmi ceux effectivement utilises avec
  ce token ; c'est le cas.
- **`--color-yellow-ink` (admin, `admin.css`), 4 regles.** Voir le detail en section 5.2 :
  c'est ici qu'a ete trouve le seul reel ecart, sur `.pos-tile__pastille`, en dehors du
  perimetre des 11 ecrans mesures. Traite avant la remesure, pas apres — la valeur
  appliquee (`#AE7F09`) tient sur les deux fonds des le premier passage de cette campagne.

Methode de relecture : extraction programmatique de chaque regle CSS consommant le token
(analyseur respectant la profondeur des accolades, pas une simple recherche ligne a ligne)
puis lecture du fond effectif de chaque regle (declare localement, ou herite du conteneur
le plus proche qui en declare un). Complementaire du CSV, qui ne peut voir que les etats
reellement rendus lors des 11 ecrans audites.

### Resultat, ecran par ecran

| Ecran | critical | serious | moderate | minor | Contrastes mesures | Minimum mesure |
|---|---|---|---|---|---|---|
| accueil | 0 | 0 | 0 | 0 | 6 | 17,40:1 |
| categories | 0 | 0 | 0 | 0 | 14 | 8,12:1 |
| produits | 0 | 0 | 0 | 0 | 50 | 8,12:1 |
| produits-modale-options | 0 | 0 | 0 | 0 | 58 | 8,12:1 |
| paiement | 0 | 0 | 0 | 0 | 11 | 5,09:1 |
| confirmation | 0 | 0 | 0 | 0 | 11 | 4,67:1 |
| admin-connexion | 0 | 0 | 0 | 0 | 8 | 4,98:1 |
| admin-tableau-de-bord | 0 | 0 | 0 | 0 | 50 | 3,59:1 |
| admin-ingredients | 0 | 0 | 0 | 0 | 90 | 3,59:1 |
| admin-produits | 0 | 0 | 0 | 0 | 82 | 3,59:1 |
| admin-commandes | 0 | 0 | 0 | 0 | 27 | 3,59:1 |
| **Total** | **0** | **0** | **0** | **0** | **407** | — |

**0 violation, sur les 11 ecrans, toutes gravites confondues.** Les 407 mesures de
contraste (memes 65 combinaisons distinctes qu'a la campagne initiale) sont toutes
`conforme` dans `rapports/contrastes-mesures.csv` — plus une seule ligne `violation`. Le
minimum mesure de 3,59:1 sur les quatre ecrans admin est le « do » de la barre laterale
(`.sidebar-brand-name > span`, 21px gras, seuil large-text 3:1) : c'est la marge la plus
etroite de toute la campagne, et elle reste au-dessus du seuil de 0,59.

Les noeuds indetermines (fond SVG du graphique, `aria-hidden-focus` de la modale,
boutons de quantite a caractere non textuel) sont **inchanges** : 7 + 4 + 2 = 13 comme a
la campagne initiale (section 7 ; correction du 2026-09-24, l'addition etait ecrite a
tort a 11). Cette campagne ne portait pas sur eux ; ils restent
consignes tels quels dans les artefacts `axe-<ecran>.json`.

### Les trois combinaisons corrigees, remesurees

| Ratio mesure (`axe`) | Texte | Fond | Contexte | Ancien ratio |
|---|---|---|---|---|
| 4,67:1 | `#6e6e6e` | `#f5f5f5` | libelle du numero de commande (borne) | 4,16:1 |
| 3,59:1 | `#ae7f09` | `#ffffff` | « do » du nom de marque (admin) | 2,77:1 |
| 4,57:1 | `#69707d` | `#f5f5f5` | sous-titres de page (admin) | 4,43:1 |

### Non-regression sur le reste de la mesure

Les 62 autres combinaisons (sur les 65 mesurees) gardent le meme statut conforme : les
tokens non touches par ce lot (`#1A1A1A`, `#4A4A4A`, le jaune de marque, les couleurs
d'etat succes/alerte/danger/info) restent aux memes valeurs et aux memes ratios qu'a la
campagne initiale. `npm run test:js` (217 tests), la suite PHPUnit
(`docker run --rm -v "$PWD":/app -w /app wakdo-wakdo-app php phpunit.phar -c
phpunit.xml`, 755 tests) et PHPStan niveau 6 restent sans regression.

---

## 6. Les ratios mesures, en clair (campagne AVANT correction)

C'est le trou que ce document comble. **407 ratios mesures**, sur 11 ecrans, soit
**65 combinaisons distinctes** (couleur de texte, couleur de fond composee, taille,
graisse). Le detail complet etait, a ce moment-la, dans `rapports/contrastes-mesures.csv`
— ce fichier porte aujourd'hui la campagne APRES correction (section 5 bis), le CSV du
premier passage n'etant pas conserve tel quel par le script (il ecrit la derniere
mesure, pas un historique). Les deux tableaux ci-dessous restent la trace texte du
premier passage.

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
| **4,16:1** | `#767676` | `#F5F5F5` | libelle du numero de commande | **sous le seuil (4,5:1)**, corrige en 5 bis (4,67:1) |

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
| **4,43:1** | `#6B7280` | `#F5F5F5` | sous-titres de page | **sous le seuil (4,5:1)**, corrige en 5 bis (4,57:1) |
| **2,77:1** | `#C8920A` | `#FFFFFF` | « do » du nom de marque | **sous le seuil (3:1)**, corrige en 5 bis (3,59:1) |

Observation qui merite d'etre dite a l'oral : **les couleurs d'etat du back-office
(succes, alerte, danger, neutre) sont toutes largement conformes**, entre 6,36 et 9,36.
Ce sont precisement celles qui portent de l'information — la mesure confirme que
l'information d'etat n'est pas rendue par une couleur faible.

---

## 7. Ce que l'outil n'a pas pu trancher (13 noeuds)

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
  correction est faite, pas pour faire passer un test artificiellement.
- **Mise a jour post-correction.** Les cinq entrees qui portaient `['color-contrast']`
  (`confirmation`, `admin-tableau-de-bord`, `admin-ingredients`, `admin-produits`,
  `admin-commandes`) sont retournees a `[]` une fois la remesure de la section 5 bis
  confirmee a 0 violation. Le commentaire au-dessus de chaque entree explique desormais
  la cause de l'ancienne tolerance et le token corrige, pour garder la trace sans garder
  la tolerance. Une regression future de `color-contrast` sur ces ecrans fera donc a
  nouveau echouer le test, au meme titre qu'un ecran qui n'a pas eu de tolerance.
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

**Ces artefacts portent la campagne la plus recente.** `run-a11y.sh` purge et regenere ces
fichiers a chaque execution (il n'existe pas d'historique automatique) : depuis la
correction, ils refletent la campagne APRES (section 5 bis), 0 violation. Les chiffres de
la campagne AVANT (sections 4 a 6) restent lisibles dans ce document en texte, mais leurs
fichiers sources d'origine ne sont plus sur disque.

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

| Point de la preuve 04 | Etat apres mesure | Etat apres correction (5 bis) |
|---|---|---|
| Cr 1.c.3, verdict « conforme sur les etats identifies. Reserve : ratios non mesures » | **Reserve levee.** 407 ratios mesures. Le principe « pas la couleur seule » reste conforme ; un libelle de la confirmation est sous le seuil (section 5.1) | **Reserve levee ET defaut corrige.** Le libelle de confirmation, comme les 9 autres noeuds, est remesure conforme |
| Section 6, ligne « Contraste texte suffisant », verdict `partiel`, `[UNVERIFIED]` | **Verifie.** Les tokens `#1A1A1A`, `#4A4A4A` et le jaune de marque sont largement conformes ; `#767676` est conforme sur blanc (4,54) et non conforme sur `#F5F5F5` (4,16) | **Conforme.** `#767676` est remplace par `#6E6E6E` (borne), `#6B7280` par `#69707D` et `#C8920A` par `#AE7F09` (admin) ; les trois tiennent AA/large-text sur tous leurs fonds reels |
| Section 8, reserve n° 2 « Ratios de contraste non mesures a l'outil » | **Obsolete**, a remplacer par le renvoi a ce document | **Obsolete**, idem |
| Section 8, reserve n° 1 « Aucun audit avec lecteur d'ecran reel » | **Inchangee.** Cette campagne ne la traite pas | **Inchangee.** Cette correction ne la traite pas non plus |

Les sections correspondantes de `04-accessibilite-rgaa.md` et les reserves consolidees du
`README.md` du dossier ont ete mises a jour dans la foulee de cette correction (la
reserve sur les ratios non mesures y est levee et pointe vers ce document).

### Points de defense a l'oral

1. **Montrer que la mesure a servi a quelque chose.** La preuve 04 affirmait que
   `#767676` visait AA sur blanc. La mesure le confirme (4,54) **et** montre que le meme
   token tombe a 4,16 des qu'on le pose sur le gris de fond. C'est un defaut qu'aucune
   relecture n'aurait attrape : c'est l'argument le plus fort du document.
2. **Assumer le rapport rouge, puis montrer qu'il a ete traite.** Dix noeuds sous le
   seuil au premier passage, nommes, chiffres, avec trois corrections calculees — puis
   appliquees et remesurees a 0 (section 5 bis). Un dossier qui affirme la perfection des
   le debut se fait demonter en deux questions ; celui-ci montre le defaut, la correction,
   et la preuve que la correction a marche.
3. **Expliquer le partage borne / back-office.** Neuf des dix defauts initiaux etaient
   cote back-office, qui n'est pas le perimetre principal du Bloc 1. Cinq des six ecrans
   de la borne etaient deja a zero violation avant meme la correction.
4. **Raconter l'incident de mesure (section 8).** Quatre fausses violations produites par
   une mesure prise pendant une animation, detectees parce que deux passages ne donnaient
   pas le meme chiffre. C'est une demonstration de rigueur : on ne publie pas un chiffre
   qu'on ne sait pas reproduire.
5. **Nommer les limites avant qu'on ne les nomme pour vous.** Un moteur automatique ne
   couvre pas tout le RGAA, et zero violation `axe` ne vaut pas conformite (section 9) —
   meme apres la correction de cette section.
6. **Montrer la barriere.** L'audit n'est pas une capture d'ecran datee : il tourne a
   chaque campagne de tests de bout en bout et echoue si une regle nouvelle apparait. La
   liste `ACCEPTE` est aujourd'hui vide sur les cinq ecrans qui portaient une tolerance :
   la moindre regression de contraste y ferait a nouveau echouer le test.
7. **Montrer que verifier un token, c'est verifier tous ses usages.** La correction de
   `--color-yellow-ink` proposait d'abord `#B8860B` (section 5.2) ; relire les AUTRES
   endroits ou ce token est utilise a revele qu'il echouait encore sur la pastille de
   repli du POS comptoir/drive, un ecran hors des 11 mesures ici. La valeur finalement
   appliquee (`#AE7F09`) tient sur les deux usages. C'est la preuve qu'une correction
   locale, verifiee uniquement sur le point qui a echoue, peut laisser un angle mort.

---

Perimetre couvert : Cr 1.c.3 (contraste, mesure a l'outil puis corrige — reserve de la
preuve 04 levee, 0 violation `color-contrast` sur les 11 ecrans apres correction), et en
renfort Cr 1.c.1 / Cr 1.c.4 (aucune violation mesuree sur les alternatives textuelles,
les roles, les etiquettes et la structure des 11 ecrans). Les references au code se font
par citation de texte cherchable, conformement a la convention du dossier.
