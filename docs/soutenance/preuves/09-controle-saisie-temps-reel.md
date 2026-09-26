# Preuve 09 — Controle des saisies en temps reel (C2.b)

**Bloc 1 — Developpement de la partie front-end d'une application web**
**Critere vise : Cr 2.b.1 — Les donnees saisies par les utilisateurs dans les espaces interactifs
sont controlees pendant la saisie en temps reel.**
Perimetre : tous les formulaires du back-office et des pages de connexion. Etat au 2026-09-23
(lot F33, apres deux tours de relecture independante).

## 1. Avant ce lot

Le back-office ne controlait presque rien pendant la saisie : l'ecouteur de frappe d'`admin.js`
filtre des tableaux, et seul le composeur de menu du comptoir ramene a 1 une quantite invalide
quand on quitte le champ (`counter-order.js`). Les regles existaient pourtant deja, a deux
endroits : dans les controleurs PHP (validation serveur, qui reste le juge final) et, en partie,
dans les attributs HTML des champs (`required`, `min`, `max`, `maxlength`).

## 2. Ce qui a ete fait

Un fichier du projet, sans librairie : `src/public/admin/assets/js/form-validation.js`
(JavaScript simple, charge par `admin/layout.php` et par le gabarit des pages de connexion).

- **Pendant la frappe**, apres une pause de 300 ms, le champ est controle et un message en
  francais s'affiche dessous : « Adresse e-mail invalide (exemple : prenom.nom@wakdo.fr). »,
  « La valeur doit etre superieure ou egale a 1. », « Un nombre entier est attendu. »,
  « Saisissez un nombre. », « 8 caracteres minimum (3 saisis). », « Les deux saisies ne
  correspondent pas. »…
- **Un champ obligatoire vide** n'est signale qu'en quittant le champ : on ne reproche pas une
  saisie qui commence.
- **A l'envoi**, tous les champs sont controles ; s'il reste un ecart, l'envoi est bloque et le
  premier champ en erreur recoit le focus. Ce controle passe avant les autres scripts du
  formulaire (le modal PIN ne s'ouvre pas sur une saisie invalide).
- **Clic apres correction** : quand un champ corrige perd le focus, son message ne disparait
  qu'au relachement du bouton de la souris (ou du doigt), quelle que soit la duree de l'appui ;
  au clavier, apres 200 ms. Sans cela, le clic sur « Enregistrer » qui fait perdre le focus
  arrivait a cote du bouton, remonte de 43 px par la disparition du message (mesure du relecteur
  dans Chromium ; un premier correctif a delai fixe perdait encore les appuis de plus de 200 ms).
- **Champs ajoutes apres le chargement** (lignes de recette) : les evenements sont ecoutes sur le
  formulaire, pas champ par champ ; ces champs sont controles comme les autres.
- **Accessibilite** : le message est relie au champ (`aria-describedby`), le champ porte
  `aria-invalid`, la zone de message existe des le chargement et porte `aria-live="polite"`. Le
  bord rouge double le message, il ne le remplace pas (la couleur ne porte pas seule
  l'information).
- **Sans JavaScript**, le comportement reste celui d'avant : les attributs HTML sont la, la
  validation du navigateur s'applique, puis celle du serveur.

## 3. Des regles alignees sur celles du serveur

Le fichier ne cree pas de regle metier : il lit les attributs HTML, completes dans ce lot pour
reprendre ce que les controleurs verifient.

| Champ | Regle serveur (controleur) | Attribut du champ |
|---|---|---|
| Slug de categorie | `^[a-z0-9]+(?:-[a-z0-9]+)*$` apres trim (CategoryController) | `pattern` + message |
| Code de role | `^[a-z][a-z0-9_]{1,39}$` (RoleController) | `pattern` + message |
| Prix (produit, menu normal et Maxi) | entier de 1 a 4 294 967 295 | `min="1"`, `max="4294967295"` |
| Ordre d'affichage (categorie, produit, menu) | entier de 0 a 65 535, valeur vide refusee | `min="0"`, `max="65535"`, `required` |
| Capacite de stock, comptage d'inventaire | entier jusqu'a 2 147 483 647 | `max` |
| Mot de passe d'un compte | 8 caracteres minimum (UserController, mb_strlen) | `minlength="8"` |
| Nouveau mot de passe (lien de reinitialisation) | les deux saisies identiques (PasswordResetController) | `data-match="password"` |
| Ajustement de stock | entier signe NON NUL (IngredientController) | `min`, `max`, `data-not-zero` |
| Nouveau PIN et confirmation | chiffres, longueur `STAFF_PIN_MIN_LENGTH` a `STAFF_PIN_MAX_LENGTH` | `pattern` calcule depuis la meme configuration, `data-match="pin"` |

Pour se comporter comme le serveur plutot que comme le navigateur, le fichier :
- controle les champs texte une fois nettoyes de leurs espaces de bord (le serveur fait `trim`)
  et compte les longueurs en caracteres, comme `mb_strlen` ;
- n'accepte que des chiffres dans un champ nombre sans pas decimal : `ctype_digit` refuse
  « 1e3 », que le navigateur tient pour un nombre valide ; le signe moins n'est admis que si le
  champ accepte des valeurs negatives (« -0 » refuse la ou le minimum vaut 0) ;
- signale une saisie numerique que le navigateur n'a pas su lire (« 33e ») : sa valeur vaut
  `''` et, sans ce controle, un champ facultatif (taille en cl) partait vide et le serveur
  effacait la valeur sans message. Ce defaut a ete introduit par la premiere version du lot et
  corrige a la relecture, avant toute livraison.

Pour le PIN, la longueur est reglable par l'environnement (4 a 12 par defaut). Le motif du champ
est calcule par le serveur (`PinVerifier::minLength()` / `maxLength()`, passes a la vue par
`ProfileController`) : le client applique la regle du serveur meme si la configuration change.

Deux regles n'ont pas d'equivalent HTML et sont portees par des attributs `data-` :
`data-match="<id>"` (confirmation identique au champ `<id>`, recontrolee quand la source change) et
`data-not-zero` (0 refuse, avec le message porte par l'attribut).

**Ecarts residuels connus**, sans consequence sur les donnees puisque le serveur tranche :
- adresse e-mail : le navigateur applique la grammaire HTML, le serveur `FILTER_VALIDATE_EMAIL`
  (UserController) ; les deux peuvent diverger sur des adresses peu courantes `[HYPOTHESIS]` ;
- sans JavaScript, le navigateur applique les motifs a la valeur brute (espaces de bord compris)
  et compte `maxlength` en unites UTF-16 : il est alors un peu plus strict que le serveur ;
- nouveau mot de passe par lien de reinitialisation : le serveur compte 8 OCTETS
  (`strlen`, PasswordResetService), le client 8 caracteres ; avec des lettres accentuees, le
  client est donc plus strict que le serveur (« éééé » : 4 caracteres, 8 octets).

## 4. Anomalie trouvee et corrigee au passage : le modal PIN

Sur **cinq formulaires d'action sensible** (ajustement de stock, inventaire, annulation de
commande, suppression de produit, suppression de menu), les champs e-mail et PIN sont masques
par `pin-modal.js` (le modal les remplit a la confirmation) mais portaient `required`. Le
navigateur refuse alors l'envoi AVANT l'evenement `submit` (un champ invalide masque ne peut pas
recevoir le focus) : le modal ne s'ouvrait pas et le bouton restait sans effet.

- **Constat** : observe dans Chromium sur l'ajustement de stock (pile de test jetable, code de
  `dev` au commit `74d4398`) : saisie de 5, clic sur « Enregistrer l'ajustement », aucun modal
  apres 2 essais de 5 s. Capture : `captures-controle-saisie/modal-pin-avant.png`. Les quatre
  autres formulaires portent le meme balisage (lecture du code), sans observation separee.
- **Deux protections, testees separement** :
  1. `form-validation.js` pose `novalidate` et ignore les champs masques : cela suffit a rouvrir
     le modal (test E2E « l'envoi ouvre le modal PIN ») ;
  2. `pin-modal.js` retire `required` des champs qu'il masque : cela suffit aussi, meme si
     `form-validation.js` n'est pas charge (test E2E qui bloque ce fichier, et test jsdom).
  Capture apres correctif : `captures-controle-saisie/modal-pin-apres.png`.
- **Message du serveur rendu visible** : apres un PIN refuse, le serveur recharge la page avec son
  message DANS le bloc que le modal masque ; l'equipier ne voyait aucune explication. Le message
  est desormais sorti du bloc masque, et le modal l'affiche a sa reouverture. La contre-relecture
  a trouve que la premiere version de ce correctif attrapait une zone de message vide creee par
  `form-validation.js` (charge juste avant) au lieu du message du serveur : `pin-modal.js` cible
  maintenant le seul message du serveur, et deux tests chargent les deux fichiers dans l'ordre de
  la page (jsdom, et Chromium sur la page reelle avec un PIN refuse). Capture :
  `captures-controle-saisie/modal-pin-refuse.png`.
- Le parcours E2E admin existant ne l'avait pas vu : l'administrateur de demonstration n'a pas de
  PIN, aucune action sensible n'y etait jouee.

## 5. Tests

| Test | Ce qu'il verifie |
|---|---|
| `tests/js/form-validation.test.js` (22 tests, jsdom) | messages par type d'ecart ; saisie numerique illisible ; entier en notation scientifique ; signe moins selon le minimum ; espaces de bord et longueur en caracteres ; un message par champ meme sans id, place hors du label ; champ ajoute apres le chargement ; affichage pendant la frappe puis effacement ; obligatoire signale en quittant le champ ; message conserve pendant le clic qui suit une correction et pendant un appui long ; confirmation recontrolee ; envoi bloque avant les autres scripts, focus sur le premier ecart ; champs masques ignores ; message serveur retire des que la saisie change ; remise a zero par `reset` ; zone de message presente des le chargement ; formulaires exclus |
| `tests/js/pin-modal.test.js` (12 tests) | les champs PIN masques perdent `required` ; le message PIN du serveur reste visible et s'affiche dans le modal, y compris avec `form-validation.js` charge avant |
| `tests/Unit/Auth/PinVerifierTest.php`, `tests/Unit/Admin/ProfileControllerTest.php` | bornes du PIN lues dans la configuration ; motif `[0-9]{4,12}` et `data-match` rendus dans la page |
| `tests/e2e/responsive.spec.js` (Chromium) | message affiche et efface dans un vrai navigateur ; modal PIN ouvert a l'envoi, avec et sans `form-validation.js` ; apres un PIN refuse sur la page reelle, message du serveur visible et repris par le modal |

Capture du controle pendant la saisie : `captures-controle-saisie/saisie-en-direct.png`.

**Captures regenerees le 2026-09-26.** Les trois captures « apres » (`saisie-en-direct`,
`modal-pin-apres`, `modal-pin-refuse`) ont ete refaites contre le code courant par
`tests/e2e/run-captures.sh`, apres la refonte du back-office : les anciennes montraient des
formulaires qui n'existent plus. `modal-pin-avant.png` n'est pas regeneree et ne peut pas
l'etre — elle montre le defaut corrige (le modal qui ne s'ouvrait pas), que le code actuel
ne produit plus. Elle reste versionnee telle quelle comme trace du diagnostic.

## 6. Reserves

- Ce controle evite un aller-retour et guide la saisie ; il ne protege rien a lui seul. Toute
  regle reste verifiee par le serveur, qui renvoie ses propres messages.
- Les regles croisees entre champs (seuil critique strictement inferieur au seuil d'alerte) ne
  sont controlees cote client qu'a l'envoi, dans la fenetre de reglage rapide du tableau de bord
  (`stock-thresholds.js`) ; dans le formulaire d'ingredient complet, elles le sont par le serveur.
- L'annonce des messages par un lecteur d'ecran n'a pas ete essayee avec un lecteur reel (meme
  reserve que la preuve 04) ; la zone `aria-live` presente des le chargement est la pratique
  retenue pour lui donner le plus de chances.
