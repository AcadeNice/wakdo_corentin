# Import CSV de produits et de leurs recettes

Ajoute plusieurs produits (et leurs recettes) en une fois, depuis un tableur
(Excel, LibreOffice, Google Sheets). Service : `App\Catalogue\ProductImportService`
(analyse, validation, application). Contrôleur HTML : `App\Controllers\ProductController`
(actions `importForm`/`importTemplate`/`importPreview`/`importConfirm`). Contrôleur
JSON : `App\Controllers\Admin\Api\ProductApiController` (`apiImportTemplate`/
`apiImportRun`), même service, mêmes règles.

## 1. Format du fichier

Une ligne par couple **produit + ingrédient** ("format long") : la ligne la plus simple
à remplir dans un tableur. Les colonnes du produit sont **répétées** sur chaque ligne
de ce produit. Une ligne sans ingrédient est autorisée (produit sans recette pour
cette ligne).

En-tête exact, dans cet ordre (le fichier est refusé si une colonne manque, est en
trop, ou est mal orthographiée) :

```
categorie;produit;description;prix_ttc;tva;taille_cl;disponible;ingredient;unite;quantite;retirable;ajoutable
```

| Colonne | Contenu | Obligatoire |
|---|---|---|
| `categorie` | Nom de la catégorie existante (insensible à la casse), ou son identifiant numérique. Une catégorie inconnue est une erreur : l'import ne crée pas de catégorie, il se contente de rapprocher une catégorie déjà présente en base. | oui |
| `produit` | Nom du produit (120 caractères max). | oui |
| `description` | Description du produit. | non |
| `prix_ttc` | Prix TTC en euros, virgule ou point (`6,90` ou `6.90`), converti en centimes sans erreur d'arrondi. | oui |
| `tva` | `5,5` ou `10` — les deux seuls taux existants dans Wakdo (`chk_product_vat_rate CHECK (vat_rate IN (55, 100))`). Toute autre valeur (y compris `20`, absent du modèle) est refusée avec un message explicite. | oui |
| `taille_cl` | Volume en centilitres pour une boisson. | non (vide = pas de dimension taille) |
| `disponible` | `oui` ou `non`. | non (vide = `oui`) |
| `ingredient` | Nom de l'ingrédient de cette ligne. | non (vide = pas de ligne de recette pour cette ligne du fichier) |
| `unite` | Unité de l'ingrédient. **Requise si l'ingrédient n'existe pas encore** (il sera créé) ; si l'ingrédient existe déjà et que la colonne est renseignée, elle doit correspondre à l'unité existante. | conditionnel |
| `quantite` | Quantité de l'ingrédient dans la recette, entier ≥ 1. | oui si `ingredient` renseigné |
| `retirable` | `oui`/`non` : le client peut-il retirer cet ingrédient ? | non (vide = `non`) |
| `ajoutable` | `oui`/`non` : le client peut-il l'ajouter en supplément ? | non (vide = `non`) |

Les colonnes du produit (`categorie`, `produit`, `description`, `prix_ttc`, `tva`,
`taille_cl`, `disponible`) doivent porter la **même valeur** sur toutes les lignes
d'un même produit (même clé nom + catégorie) ; une valeur divergente est une erreur
nommée (ligne + colonne).

**Hors périmètre** (choix documenté, pas un oubli) : l'import ne pilote ni les
variantes de taille/Maxi (`base_product_id`/`maxi_variant_product_id`, F9-3) ni
l'image du produit — les deux restent des gestes du formulaire HTML après import.
`quantity_maxi` (recette) est posé égal à `quantite` (pas de colonne dédiée) et
`extra_price_cents` à 0 ; à affiner ensuite via la recette du formulaire produit si
besoin d'un supplément ou d'une quantité Maxi différente.

### Rapprochement d'un produit existant

Clé : **nom + catégorie**, comparaison insensible à la casse. Pour un produit déjà
existant :

- les champs du produit (prix, TVA, description, taille, disponibilité) sont mis à
  jour s'ils diffèrent de la valeur en base (sinon le produit reste classé
  "inchangé" dans l'aperçu, mais voir le point suivant) ;
- **la recette du fichier REMPLACE intégralement la recette actuelle** du produit
  (delete-and-reinsert, même politique que `ProductRepository::setComposition()`),
  **y compris la vider** si le fichier ne porte pas de ligne ingrédient pour ce
  produit. L'aperçu affiche un avertissement explicite ("recette vidée") quand ce
  cas se présente sur un produit à mettre à jour, pour ne pas surprendre.
- tout changement de **prix** exige le PIN à la confirmation (même règle qu'en HTML) ;
- toucher un produit déjà existant (le mettre à jour **ou** le laisser
  "inchangé") exige les permissions `product.update` **et** `ingredient.manage`
  en plus de `product.create` — détail et raison en section 5.

### Ingrédients

Rapprochement par **nom** (insensible à la casse). Inconnu -> créé avec **stock 0**,
`pack_size` 1, `stock_capacity` 100 (référence "100 %" du modèle de stock en
pourcentage), `low_stock_pct`/`critical_stock_pct` aux valeurs par défaut du projet
(10 / 5) — mêmes constantes que la création d'ingrédient depuis le formulaire produit
(`ProductImportService::DEFAULT_*`). Sa liste d'allergènes n'est pas encore revue :
l'aperçu et le message de confirmation le rappellent explicitement plutôt que de
présupposer une absence d'allergène.

## 2. Encodage et séparateur

- UTF-8, avec ou sans BOM (le BOM est retiré automatiquement s'il est présent).
- Séparateur `;` ou `,` détecté automatiquement (compte des deux sur la première
  ligne, `;` par défaut à égalité — convention Excel français).
- Guillemets conformes à la RFC 4180 (un champ contenant le séparateur, un guillemet
  ou un saut de ligne est mis entre guillemets, doublés pour s'échapper).
- Protection contre l'injection de formule tableur (les colonnes de **texte**
  seulement : `categorie`, `produit`, `description`, `ingredient`, `unite`),
  **refusée explicitement** (erreur nommée), pas neutralisée en silence :
  - une cellule commençant par `=`, `@` ou une tabulation est refusée quelle
    que soit la suite (aucune saisie légitime de ces colonnes ne commence
    ainsi) ;
  - une cellule commençant par `+` ou `-` n'est refusée que si le caractère
    suivant prolongerait une expression numérique/de référence qu'un tableur
    évaluerait à la réouverture (un chiffre, un autre opérateur, une
    parenthèse...). Suivi d'une **lettre** (accentuée comprise) ou d'un
    **espace**, ou seul en fin de cellule, `+`/`-` reste une saisie de texte
    normale ("-Sans gluten", "- Sans gluten", "+Supplément inclus") : refuser
    ces cas bloquerait des descriptions ou noms d'ingrédients légitimes pour
    rien. `ProductImportService::looksLikeFormula()` documente cette règle
    (indiquée comme un comportement observé des tableurs courants, pas une
    spécification écrite figée).
  - **Décision assumée** : une remise écrite en chiffres avec un tiret de tête
    (par exemple "-30% aujourd'hui") est **refusée**, pas autorisée. Le tiret
    y est suivi d'un chiffre — exactement la forme qu'un tableur peut
    prolonger en expression numérique, et celle d'une charge utile
    d'injection réelle ("-2+3+cmd|'/c calc'!A0" commence pareil). Il n'existe
    pas de règle simple et sûre qui distinguerait la remise bénigne de la
    charge utile en ne regardant que les premiers caractères de la cellule :
    le coût d'un contournement de sécurité l'emporte sur le confort de
    saisir une remise sous cette forme précise. L'équipier reformule ("30 %
    de réduction", "moins 30 %") ; le message d'erreur le dit explicitement.

## 3. Limites

- 2 Mo maximum, vérifié avant toute lecture du contenu.
- 2000 lignes de données maximum (hors en-tête), vérifié **pendant** la
  lecture ligne à ligne (l'analyse s'arrête dès que la limite est atteinte).
- Dans les deux cas, le fichier est refusé avec un message clair.

## 4. Le flux en 2 temps

1. **Aperçu** (`preview`, aucune écriture) : produits à créer / à mettre à jour /
   inchangés, ingrédients existants / à créer, erreurs ligne par ligne (numéro de
   ligne, colonne, message en français). Le fichier analysé est gardé **en
   session** plutôt que dans un champ caché modifiable par le client, sous un jeton
   à usage unique, pendant 30 minutes.
2. **Confirmation** (`apply`) : rejoue intégralement l'analyse (défense contre un
   état périmé — un autre équipier a pu entre-temps créer la catégorie/le produit/
   l'ingrédient visé) et, seulement si zéro erreur, écrit tout dans **une seule
   transaction** (tout ou rien). Si le fichier contient une erreur, la confirmation
   est refusée. Un changement de prix exige le PIN équipier à la confirmation.

Une ligne d'audit unique (`audit_log`, `action_code = 'product.import'`) résume les
compteurs (créés, mis à jour, inchangés, ingrédients créés, changements de prix).

## 5. Sécurité

### Permissions : trois niveaux, calculés depuis le contenu du fichier

L'import a un seul point d'entrée (`product.create`), mais ce que le fichier
contient peut exiger des droits en plus. La règle, appliquée à l'identique sur
`importPreview`, `importConfirm` (HTML) et `apiImportRun` (JSON), via
`ProductController::guardImportAuthorizations()` :

| Permission | Exigée quand | Pourquoi |
|---|---|---|
| `product.create` | Systématiquement (accès aux 3 écrans/points d'entrée). | C'est le geste de base : ajouter des produits. |
| `product.update` | **En plus**, dès qu'au moins un produit du fichier correspond à un produit **déjà existant** (classé "à mettre à jour" **ou** "inchangé"). | Les deux classifications écrivent réellement la ligne produit (`ProductRepository::update()` est appelé dans les deux cas) : "inchangé" est une étiquette d'affichage, pas une garantie qu'aucune écriture n'aura lieu. |
| `ingredient.manage` | **En plus**, dès qu'au moins un ingrédient serait créé, **ou** dès qu'au moins un produit existant verrait sa recette remplacée. | Remplacer la recette d'un produit déjà en carte (jusqu'à la vider, RG-T21) est le même geste que la page Recette dédiée protège déjà par cette permission ; l'import ne doit pas offrir un chemin plus permissif pour le même effet. |

Une permission manquante bloque l'import avec un message explicite dans le
rapport (demander à un manager, ou retirer les lignes concernées du fichier),
pas un `403` nu.

**Écart assumé avec la page Recette dédiée** (et avec la section "Composition"
du formulaire produit) : composer la recette d'un produit **neuf** — qu'il
soit créé via le formulaire produit ou via l'import — ne demande que
`product.create`/`product.update` (la recette naît avec un produit qui
n'existait pas encore, rien n'est remplacé). Remplacer la recette d'un produit
**déjà existant** demande `ingredient.manage`, que ce soit via la page Recette,
via le formulaire produit (`ProductController::update()`, dès que la recette
soumise diffère de celle enregistrée — `compositionDiffersFromStored()`) ou via
l'import : la frontière est *le produit existait-il déjà*, pas *quel écran est
utilisé*. Un renvoi STRICTEMENT identique à la recette déjà enregistrée
(resoumission sans changement réel) ne déclenche pas l'exigence, quel que soit
l'ordre des lignes soumises.

### Autres protections

- CSRF sur l'envoi et sur la confirmation (jeton `_csrf` en HTML, en-tête
  `X-CSRF-Token` en JSON).
- Taille de fichier plafonnée à 2 Mo, vérifiée avant toute lecture. Le nombre
  de lignes (2000 maximum), lui, est vérifié **pendant** la lecture ligne à
  ligne (dès que la limite est atteinte, l'analyse s'arrête et le fichier est
  refusé) : la boucle d'analyse ne tourne pas au-delà sur un fichier
  surdimensionné.
- Type de fichier vérifié (extension `.csv`, contenu texte).
- Neutralisation de l'injection de formule, sur les colonnes de texte
  **seulement** (`categorie`, `produit`, `description`, `ingredient`, `unite` —
  les colonnes numériques sont de toute façon validées par leurs propres
  bornes). Détail de la règle en section 2.
- Une valeur de nom/unité d'ingrédient trop longue pour la colonne
  (`ingredient.name` 120 caractères, `ingredient.unit` 40 caractères) est
  refusée dès l'aperçu, avec le même message que le formulaire produit
  (`ProductController::parseCompositionLines()`) : l'aperçu ne doit pas
  annoncer "aucune erreur" pour un fichier que la confirmation refuserait
  ensuite d'écrire.
- Pas d'écho brut non échappé dans l'aperçu : tout le texte importé passe par
  l'échappement HTML avant affichage.
- Le fichier temporaire d'upload est supprimé par PHP en fin de requête
  (comportement standard des uploads). L'aperçu lui-même est gardé en session
  plutôt que dans un champ caché modifiable par le client — précision
  honnête : une session PHP vit bien dans un fichier (typiquement sous `/tmp`
  ou l'équivalent configuré), ce n'est donc pas une absence de disque qui la
  protège, mais le fait qu'elle ne soit lisible que par le compte serveur qui
  exécute PHP (permissions du fichier de session), ni par le navigateur du
  client, ni modifiable par lui comme le serait un champ caché.
- Filet de sécurité : `apply()` n'écrit que dans sa propre transaction (tout
  ou rien) ; toute exception imprévue pendant l'écriture est rattrapée
  (`Throwable`) et retombe sur un message lisible, pas une page d'erreur brute.
- L'API JSON (`apiImportRun`) partage désormais les mêmes messages
  d'autorisation en français que l'écran HTML (plus de code de permission brut
  dans la réponse) : la cohérence entre les deux surfaces a été préférée à un
  message technique réservé aux développeurs.

## 6. Écrans HTML

| Méthode | Chemin | Rôle |
|---|---|---|
| GET | `/admin/products/import` | Formulaire de dépôt + aide (colonnes documentées) |
| GET | `/admin/products/import/template` | Télécharge le modèle CSV (en-tête + 2 exemples réels) |
| POST | `/admin/products/import/preview` | Envoie le fichier, reçoit l'aperçu (aucune écriture) |
| POST | `/admin/products/import/confirm` | Confirme (+ PIN si un prix change) : écrit tout ou rien |

## 7. API JSON (démo Postman/Bruno)

Le CSV voyage comme une **chaîne JSON** (champ `csv`), pas en `multipart/form-data` :
ce point d'entrée reste sur le même contrat que le reste de l'API admin
(`docs/api/conventions.md` section 5.3 : session, permission, CSRF par en-tête
`X-CSRF-Token`, PIN dans le corps JSON). Trois requêtes :

**1. Télécharger le modèle**

```
GET /admin/api/products/import/template
```

Réponse : `{ "data": { "csv": "categorie;produit;...", "filename": "wakdo-import-produits.csv" } }`.

**2. Aperçu (dry-run, aucune écriture)**

```
POST /admin/api/products/import?dry_run=1
X-CSRF-Token: <jeton>
Content-Type: application/json

{ "csv": "categorie;produit;description;prix_ttc;tva;taille_cl;disponible;ingredient;unite;quantite;retirable;ajoutable\n3;Cheeseburger;;6,90;10;;oui;Pain;unite;1;non;non\n" }
```

Réponse : `{ "data": { "errors": [...], "productsToCreate": [...], "productsToUpdate": [...], "productsUnchanged": [...], "ingredientsExisting": [...], "ingredientsToCreate": [...], "totalDataLines": 1, "hasPriceChange": false } }`.

**3. Appliquer**

```
POST /admin/api/products/import
X-CSRF-Token: <jeton>
Content-Type: application/json

{ "csv": "...", "pin_email": "<email de l equipier>", "pin": "<son code personnel>" }
```

`pin_email`/`pin` ne sont exigés (`422 PIN_INVALID` sinon) que si l'aperçu signale
`hasPriceChange: true`. Réponse : `{ "data": { "created": 2, "updated": 1, "unchanged": 0, "ingredients_created": 3, "price_changed": 0, "audit_summary": "..." } }`.
Si le fichier contient encore une erreur au moment d'appliquer (état changé entre
l'aperçu et la confirmation) : `422 VALIDATION_ERROR` avec le détail dans
`error.fields.details`, rien n'est écrit.

> La collection Postman/Bruno du projet vit sur une autre branche et n'est pas
> modifiée ici ; ce document sert de référence pour y ajouter les 3 requêtes
> ci-dessus.
