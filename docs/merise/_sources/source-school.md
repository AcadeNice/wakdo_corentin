# Source de donnees - brief ecole "wacdo"

Cette matiere brute provient du brief ecole sur lequel ce projet **Wakdo** est construit.
Conservee non modifiee dans ce dossier pour la tracabilite jury : le MCD/MLD de la phase
P1 derivera de cette source, et garder l'original permet de retracer les choix de modelisation
(qu'est-ce que j'ai garde tel quel, qu'est-ce que j'ai normalise, qu'est-ce que j'ai enrichi).

## Provenance

- Auteur : brief ecole (projet pedagogique baptise "wacdo" cote ecole)
- Date d'arrivee : 2026-04-30 (Session 4)
- Format : 2 fichiers JSON + assets visuels + maquette PDF
- **Notre projet s'appelle Wakdo** (avec un k), pas wacdo. Le naming "wacdo" reste uniquement
  dans ce dossier `_sources/` pour la tracabilite. Tout le reste du repo utilise Wakdo.

## Contenu du dossier

- `categories.json` : 9 categories de produits
- `produits.json` : 66 produits repartis sur les 9 categories

## Schemas observes

`categories.json` :

```json
[
  { "id": int, "title": string, "image": string }
]
```

9 entrees. Le champ `image` pointe sur un chemin relatif type `/categories/menus.png`.

`produits.json` :

```json
{
  "<categorie>": [
    { "id": int, "nom": string, "prix": float, "image": string }
  ]
}
```

Cles de premier niveau (correspond a une categorie) : `menus`, `boissons`, `burgers`, `frites`,
`encas`, `wraps`, `salades`, `desserts`, `sauces`. Comptes par categorie :

| Categorie | Nb produits |
|---|---|
| menus | 13 |
| burgers | 13 |
| desserts | 9 |
| boissons | 8 |
| sauces | 7 |
| frites | 5 |
| encas | 4 |
| wraps | 4 |
| salades | 3 |
| **Total** | **66** |

## Typos detectes dans la source (laisses tels quels, corriges en aval)

Les JSON sources contiennent des typos sur les chemins d'images (le fichier physique est correct,
seule la reference JSON est buguee) :

| JSON ref | Fichier reel apres normalisation | Note |
|---|---|---|
| `/burgers/280.png.png` | `/burgers/280.png` | double extension |
| `/frites/PETITE_FRITE.png.png` | `/frites/petite-frite.png` | double extension |
| `/frites/MOYENNE_FRITE.png.png` | `/frites/moyenne-frite.png` | double extension |
| `/frites/GRANDE_FRITE.png.png` | `/frites/grande-frite.png` | double extension |
| `/frites/POTATOES.png.png` | `/frites/potatoes.png` | double extension |
| `/encas/cheeseburger.png.png` | `/encas/cheeseburger.png` | double extension (le fichier source avait aussi le typo, corrige a la copie) |
| `/frites/GRANDE_POTATOES.jpg.png` | `/frites/grande-potatoes.png` | extensions `.jpg.png` accolees, fichier reel en `.png` simple |

La fonction de normalisation (kebab-case + lowercase + collapse extensions doubles `.png.png`)
recupere automatiquement les 6 premieres. Le 7eme cas (`.jpg.png` chez Grande Potatoes) n'est
pas couvert par la normalisation et serait detecte comme MISS si on utilisait ce JSON tel quel
en runtime - resolu en P1 par regeneration du seed depuis le filesystem canonique.

Conclusion : les JSON sources ne sont **pas utilisables en l'etat** pour servir de fallback
runtime ou pour piloter le seed. Ils servent uniquement de matiere de reference humaine. Le
seed sera construit en croisant la source (pour les libelles, prix, ids) avec le filesystem
images normalise (pour les chemins).

## Ecarts identifies a corriger lors du passage au MCD

- **Pas de FK explicite produit -> categorie** : la relation passe par la cle d'objet
  (`"burgers": [...]`). Au MCD, ajouter `category_id` dans l'entite Produit avec FK vers
  Categorie.
- **Prix en flottants** (8.80, 1.9, 10.60) : risque d'arrondi. Decision a graver au MCD :
  conversion en centimes `INT UNSIGNED` au DDL pour stockage exact.
- **Pas de composition de menu** : "Menu Big Mac" (id=4) coute 8.00 EUR mais la source ne
  precise pas son contenu (burger + accompagnement + boisson + sauce). PROJECT_CONTEXT
  evoque cette composition, donc enrichissement majeur a faire au MCD (entite Composition,
  ou tables de jointure Menu_Produit avec roles).
- **Champs metier absents** : description, allergenes, valeurs nutritionnelles, stock, TVA,
  ordre d'affichage. A enrichir selon les besoins exprimes au CDCF.

## Reference design

- PDF de la maquette : `docs/design/maquette-borne.pdf`
- Figma public : `https://www.figma.com/design/0qnd0pH4qryZqjzXcB4qjN/borne?node-id=97-775`

## Lien avec les images

Les chemins d'images dans ces 2 JSON pointent sur les noms originaux (ex : `/burgers/BIGMAC.png`,
`/wraps/mcwrap-chevre.png`). La source ecole melange 3 conventions de casse (MAJUSCULES,
minuscules, mixed). Lors de la copie vers `src/public/borne/assets/images/`, les fichiers ont
ete normalises en kebab-case minuscule (`bigmac.png`, `mcwrap-chevre.png`) pour eviter le
piege case-sensitive Linux (le serveur tourne dans Docker sur Linux, ou `BIGMAC.png` !=
`bigmac.png`).

Consequence : ces JSON ne peuvent pas servir directement de fallback runtime - leurs paths
pointent sur la convention source. La generation des fichiers JSON fallback en P1 partira
du seed normalise, pas de ces fichiers.

## Utilisation prevue en P1

1. Extraction des entites + attributs vers le **dictionnaire de donnees**
2. Derivation du **MCD** (entites + relations) en enrichissant les ecarts ci-dessus
3. Generation du **DDL** (`db/migrations/0001_init_schema.sql`)
4. Transformation en **seed** (`db/seeds/0001_demo_data.sql`) avec normalisation des prix
5. Export des **JSON fallback** (`src/public/borne/data/*.json`) depuis le seed
