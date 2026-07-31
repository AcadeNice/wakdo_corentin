# ADR-0015 — Allergenes calcules par produit, avec etat de revue explicite

- Statut : Accepte
- Date : 2026-07-31

## Contexte

La borne affichait, derriere le bouton "i" de chaque carte produit, la liste des **14
categories d'allergenes** du reglement (UE) 1169/2011. Une information vraie mais
inutilisable : la meme pour tous les produits, elle ne disait rien du produit consulte.
Le client allergique devait donc demander a l'equipe de toute facon.

Le modele portait deja ce qu'il fallait pour faire mieux. La table de liaison
`ingredient_allergen` existe depuis la migration 0001, et le dictionnaire (3.8) posait
noir sur blanc que « les allergenes d'un produit sont **calcules** en joignant
`product_ingredient` -> `ingredient_allergen` -> `allergen` ; pas de ressaisie manuelle
par produit ». Deux choses manquaient : la table etait **vide**, et le code ne faisait
pas la jointure. Le document decrivait donc une derivation que l'application n'avait
pas — un ecart de plus entre la conception et le livre.

Trois questions a trancher : comment distinguer une absence verifiee d'une absence
ignoree, d'ou viennent les donnees, et qui peut les corriger.

## Decision

### (a) Un etat de revue par ingredient, parce qu'une liaison vide est ambigue

Sans marqueur, un ingredient sans ligne dans `ingredient_allergen` peut vouloir dire
deux choses opposees : « verifie, il ne contient aucun des 14 » ou « personne n'a
regarde ». Les afficher pareil revient a annoncer « sans gluten » a quelqu'un a qui
rien n'a ete verifie. C'est le defaut que ce lot existe pour eviter.

La migration 0011 ajoute donc `ingredient.allergens_reviewed_at` (NULL = non revu) et
`ingredient.allergens_source` (provenance). Meme couple horodatage + provenance que la
migration 0005 pour la nutrition, sur la meme table : la forme n'est pas inventee pour
l'occasion.

Cote produit, l'API expose `allergens` (la liste calculee, dedupliquee en SQL) et
`allergens_complete` (faux des qu'un ingredient de la recette n'est pas revu). La borne
en tire **trois etats distincts**, que rien ne doit confondre :

| Etat | Ce que la borne dit |
|---|---|
| revu, avec allergenes | « Ce produit contient : ... » |
| revu, sans allergene | « Verifie : aucun des 14 allergenes a declaration obligatoire » |
| non revu | « Information non disponible : demandez a l'equipe avant de commander » |

Un avertissement de traces accompagne les trois : une cuisine de restauration rapide
manipule ces allergenes sur le meme plan de travail, et une absence a la recette n'est
pas une absence dans l'assiette.

### (b) Les donnees viennent d'une recherche sur sources publiques, pas du nom des ingredients

La contrainte etait explicite : chercher les allergenes reels plutot que les deduire.
La revue a couvert les 50 ingredients du catalogue via **108 pages consultees** (tables
allergenes publiees par des chaines, fiches techniques fabricant, bases communautaires,
texte du reglement), puis **deux relectures adversariales croisees** : coherence entre
familles d'ingredients, et chasse a la sur-declaration.

Les relectures ont **modifie les donnees**, ce qui est leur seule justification :

- **Sauce barbecue : celeri ajoute.** Le document d'une chaine ne declarait que la
  moutarde ; la fiche du meme produit sur le marche francais porte « epices (dont
  moutarde, celeri) ». Un client allergique au celeri lisait « moutarde uniquement ».
- **Donut : oeuf retire.** Le livret allergenes officiel classe l'oeuf en **traces**,
  pas en ingredient, sur les deux variantes. Une trace promue en ingredient est une
  sur-declaration, et le projet porte deja un avertissement general sur les traces.
- **Dosette Barbecue : soja retire.** Un enregistrement du meme produit l'omet ; celui
  qui le porte dit « huile de soja en proportion variable ».
- **Six niveaux de preuve abaisses** pour refleter la source qui porte reellement
  l'affirmation, au lieu de la source la plus flatteuse du lot.

Resultats qu'une deduction sur le nom aurait manques, et qui restent apres verification :
le **jambon** porte du celeri (bouillon de cuisson), les **nuggets** aussi (declare
comme ingredient dans la liste officielle), le **cornichon** porte moutarde et sulfites
(la saumure), et la **frite nature** n'en porte aucun.

### (c) Une regle de decision unique, parce qu'elle changeait de ligne en ligne

La premiere relecture a releve le defaut de fond : la regle etait protectrice pour la
galette de pomme de terre, non protectrice pour la frite, inversee entre deux dosettes.
`(aucun)` ne voulait donc pas dire la meme chose partout. Une seule regle s'applique :

> Chaque ligne **epingle une reference produit nommee** dans son commentaire (la ligne
> devient reproductible) et declare les allergenes de cette reference. Quand le nom du
> catalogue couvre des recettes qui **divergent** sur un allergene et qu'aucune reference
> ne peut etre epinglee, la ligne reste **non revue**.

Cinq ingredients restent donc non revus a dessein : `Sauce deluxe` (deux recettes qui
s'excluent, l'une avec lait sans moutarde, l'autre avec moutarde et soja sans lait),
`Macaron` (gluten, lait et soja viennent des inclusions, pas de la coque), `Glace
McFleury` (les parfums a base de noisette apportent des fruits a coque), `Dosette
Ketchup` (la dosette de chaine ne declare rien, le format detail declare le celeri) et
`Dosette Pommes Frites` (lait contre jaune d'oeuf selon la recette).

Ce n'est **pas un travail inacheve** : c'est le chemin honnete du dispositif. Les 8
produits concernes affichent « information non disponible » au lieu d'unir deux recettes
incompatibles. Le mecanisme se demontre donc en vrai, sur de vraies donnees.

### (d) L'equipe peut corriger, et la correction est tracee

La donnee serait fausse le jour ou un fournisseur change de recette. Un seed fige ne
suffit pas. L'ecran ingredient du back-office porte donc la matrice des 14 cases plus un
champ **source obligatoire** — une revue dont on ne peut pas dire d'ou elle vient n'est
pas verifiable. La permission reutilisee est `ingredient.manage`, dont le libelle du seed
RBAC couvre deja explicitement « allergen mapping » : **le catalogue de permissions reste
a 23**, aucune n'est ajoutee.

`IngredientRepository::setAllergens()` remplace l'ensemble (delete-and-reinsert, comme
`setComposition`), pose la date et la source, et ecrit une ligne `audit_log`
(`ingredient.allergens`) dans la **meme transaction** (ADR-0004). Les colonnes disent
QUAND et D'OU ; l'audit dit QUI. Les autres ecritures d'ingredient (creation,
modification, import nutritionnel) ne tracent pas ; celle-ci si, parce qu'elle change une
information de securite alimentaire montree au client.

## Alternatives ecartees

**Import automatique des allergenes depuis l'API Open Food Facts**, sur le modele de
l'enrichissement nutritionnel deja en place (`OpenFoodFactsGateway`). Tentant, et
techniquement a portee. Ecarte : la passerelle resout un ingredient par **recherche sur
son nom**, ce qui sur des noms generiques francais ("Sauce deluxe", "Pain signature")
renvoie un produit de marque arbitraire. Ce serait exactement la deduction habillee en
fait que ce lot combat, et sur un sujet ou l'erreur blesse. Le critere « exploitation
d'informations issues d'une API externe » est deja porte par la nutrition ; rien
n'obligeait a le porter deux fois, et surtout pas ici.

**Un 15e etat "traces" dans la table de liaison.** Ecarte : le projet porte un
avertissement de traces **general** sur les trois etats, ce qui est la posture d'une
cuisine de restauration rapide ou tous les allergenes sont manipules ensemble. Un
"contient des traces de X" par ingredient donnerait une precision que le procede de
fabrication ne soutient pas.

**Recalculer les allergenes apres personnalisation** (le client retire le cheddar, la
liste doit-elle perdre le lait ?). Ecarte, et c'est defendable par une propriete du
calcul : la liste couvre l'integralite des lignes de `product_ingredient`, y compris les
ingredients retirables et les extras ajoutables. Elle est donc un **sur-ensemble de
toute configuration client** — retirer un ingredient ne peut qu'enlever des allergenes,
pas en ajouter. L'ecart va dans le sens de la prudence. Recalculer introduirait au
contraire le risque de dire « sans lait » apres retrait du fromage alors que le pain en
contient.

**Allergenes du menu = union de toutes les options possibles.** Ecarte : un menu
afficherait alors presque les 14, information inexploitable. Le menu porte les
allergenes de son **burger impose**, meme granularite que `is_orderable` (RG-T21). Chaque
accompagnement et chaque boisson porte les siens, consultables dans le composeur.

## Consequences

- (+) Le dictionnaire 3.8 devient **vrai** : la derivation qu'il decrivait est
  implementee et servie. Un ecart conception/code de moins.
- (+) Une absence verifiee et une absence ignoree ne se ressemblent plus, ni en base ni
  a l'ecran. C'est la valeur centrale du lot.
- (+) La liste affichee est un sur-ensemble de toute personnalisation : l'ecart penche
  du cote prudent, verifiable a la lecture du SQL (aucun filtre sur `is_removable`).
- (+) Deux requetes groupees pour tout le catalogue (58 produits), pas une par produit :
  pas de N+1 sur le chemin le plus chaud de la borne. Verrouille par test.
- (+) Tracabilite complete : la source par ingredient est lisible **depuis
  l'application**, sans ouvrir un fichier de seed.
- (+) Zero permission ajoutee (catalogue gele a 23).
- (-) 8 produits sur 53 affichent « information non disponible ». Assume : c'est le
  resultat correct de la regle, pas un manque. Chacun se leve en lisant la fiche du
  produit reellement achete.
- (-) **Donnees de demonstration datees.** Ce n'est pas un substitut aux fiches
  techniques fournisseur d'un etablissement reel : une mise en service exige de relire
  chaque ligne. Le seed le dit, le back-office permet la correction.
- (-) **Limite de modelisation connue** : `Gobelet` est porte comme ingredient de recette
  (pour le stock) alors que ce n'est pas un aliment mais un materiau au contact des
  denrees (reglement 1935/2004), hors du champ de l'annexe II. Il est marque revu, avec
  une source qui dit exactement cela, pour ne pas rendre les 12 boissons « information
  non disponible » a cause de leur emballage — ce qui serait faux dans l'autre sens. Un
  drapeau `is_food` sur `ingredient` serait la correction propre si d'autres emballages
  (sac, plateau, paille) entrent au catalogue.
- (-) La granularite menu (burger impose seul) demande au client d'ouvrir chaque option
  pour le detail complet de son menu compose.

Fichiers : `db/migrations/0011_ingredient_allergen_review.sql`,
`db/seeds/0008_ingredient_allergens.sql`, `src/app/Catalogue/AllergenRepository.php`,
`src/app/Catalogue/IngredientRepository.php`, `src/app/Controllers/CatalogueController.php`,
`src/app/Controllers/IngredientController.php`, `src/app/Views/admin/ingredients/form.php`,
`src/public/borne/assets/js/allergens.js`, `src/public/borne/assets/js/data.js`,
`src/public/borne/assets/js/page-products.js`. Modele :
`docs/merise/dictionary.md` note 15, `docs/merise/mld.md` 4.6.
