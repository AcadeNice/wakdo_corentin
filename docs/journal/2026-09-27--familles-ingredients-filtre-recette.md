# Classement des ingredients par famille et filtrage du constructeur de recette

**Date** : 2026-09-27
**Branche** : `feat/ingredient-families`
**PR** : #174
**Duree estimee** : 1h30 de travail assiste

---

## Ce qui a ete fait

Le constructeur de recette du back-office proposait les 50 ingredients du catalogue a plat,
quelle que soit la categorie du produit en cours de creation. En composant un burger,
l'equipier se voyait proposer `Brownie`, `Gobelet`, `Dose Coca` et `Cheesecake` au milieu des
pains et des steaks. Signale depuis l'usage reel.

Livre :

- une colonne `ingredient.family` (dix familles) et une table `category_ingredient_family`
  qui dit quelles familles chaque categorie de produits accepte ;
- le filtrage du selecteur sur les deux ecrans qui composent une recette (le formulaire
  produit et la page recette dediee), avec regroupement par famille, compteur visible et case
  « afficher tous les ingredients » ;
- le champ famille dans le formulaire ingredient, et la lecture-ecriture de la famille dans
  l'API JSON d'administration, avec la meme validation des deux cotes ;
- la reprise des 50 ingredients deja en base par migration, et leur classement dans le jeu de
  donnees pour une installation neuve ;
- la fiche de decision ADR-0018, le dictionnaire, le modele conceptuel et le modele logique.

Mesures du dernier passage complet : **1725 tests PHP, 5172 assertions, 0 echec** ; analyse
statique PHPStan niveau 6 sans erreur ; **374 tests JavaScript, 0 echec**. Avant ce lot :
1698 tests PHP et 356 tests JavaScript.

## Pourquoi — decisions et alternatives

**Classer plutot que retirer.** Le premier reflexe serait de sortir le Brownie du catalogue
d'ingredients. La lecture des donnees l'interdit : le produit Brownie a pour recette 1x
ingredient Brownie, et c'est cette ligne qui decremente le stock au paiement (RG-T20) et
alimente la disponibilite calculee (RG-T21). Le produit Coca Cola, lui, consomme une dose et
un gobelet. Retirer ces lignes casserait le suivi de stock des desserts et des boissons. Le
defaut n'etait donc pas leur presence, mais l'absence de toute classification.

**La correspondance est une donnee, pas un tableau ecrit dans le code.** Trois options
pesees : un tableau en dur dans une classe PHP, une table de correspondance, une autorisation
ingredient par ingredient. La table l'emporte : le surcout est d'un objet de modele et d'un
jeu de donnees, le gain est que la regle se lit en SQL et se montre comme une relation du
modele. L'option par ingredient aurait demande 450 decisions a tenir a jour pour dire ce que
dix familles disent.

**Le filtre est souple, pas un blocage.** Une case « afficher tous les ingredients » le
desactive, et aucune validation serveur ne refuse une composition hors famille. Une
classification finit par rencontrer un cas qu'elle n'avait pas prevu — une salade avec des
croutons — et un blocage dur transformerait ce cas en impasse, sans recours pour l'equipier.
Corollaire assume : le filtre est une aide a la saisie, pas une regle de gestion.

**Sur doute, on montre trop plutot que rien.** Un ingredient sans famille reste visible
partout ; une categorie sans correspondance n'est pas filtree ; des donnees de correspondance
absentes ou illisibles rendent le catalogue entier sans filtre. Ce choix repond a un incident
du matin meme : un defaut de cache avait fait servir une ancienne version du programme du
navigateur, et l'equipier s'est retrouve devant un constructeur ou rien ne se passait, sans
message. Un filtre qui viderait le selecteur en silence reproduirait cette classe de defaut,
cette fois par conception.

**Une limite ouverte depuis juillet, fermee au passage.** La fiche ADR-0015, sur les
allergenes calcules, notait en consequences que `Gobelet` est un ingredient de recette alors
que ce n'est pas un aliment mais un materiau au contact des denrees, et proposait un drapeau
`is_food`. La famille `contenant` couvre ce cas plus generalement : un drapeau binaire aurait
regle le Gobelet sans rien faire pour le probleme signale, puisqu'un Brownie est un aliment
et resterait propose pour un burger. Une classification repond aux deux questions et evite
deux colonnes qui finiraient par se contredire.

## Comment — points techniques cles

**Le piege du suivi des jeux de donnees par nom de fichier.** Un jeu de donnees deja applique
est enregistre dans `seeds_applied` et n'est pas rejoue : modifier
`0003_ingredients_recipes.sql` n'a aucun effet sur une installation existante. La reprise des
50 ingredients vit donc dans la migration `0017`, et le jeu de donnees ne sert qu'a une
installation neuve. Les deux doivent classer identiquement les 50 noms, ce qu'un test
d'integration verifie. Meme contrainte que la migration `0016` sur les accents.

**L'ordre migrations puis jeux de donnees a dicte le decoupage.** Toutes les migrations
s'executent avant tous les jeux de donnees. Sur une installation neuve, la table `category`
est donc encore vide au moment de la migration `0017` : les lignes de correspondance ne
pouvaient pas y etre posees. Elles vivent dans un nouveau jeu de donnees `0010`, dont le nom
est inconnu de `seeds_applied` et qui s'execute donc aussi bien a l'installation qu'en
reprise.

**Idempotence et non-ecrasement.** La colonne est gardee par `information_schema`, la table
par `IF NOT EXISTS`, et la reprise des ingredients par `family IS NULL`. Consequence voulue :
un ingredient reclasse a la main en production n'est pas reecrit par un rejeu de la
migration.

**Un choix par defaut du navigateur pris pour un choix de l'equipier.** En ecrivant les
tests, un cas rouge a revele que la regle « on conserve l'ingredient deja selectionne »
figeait la premiere option choisie d'office par le navigateur sur une ligne vide : le filtre
n'aurait alors plus rien recalcule. Le programme distingue desormais une valeur issue d'une
vraie composition d'un defaut du navigateur.

**Le transtypage en objet avant l'encodage JSON.** La correspondance est indexee par
identifiant de categorie. Si ces identifiants partaient de zero en suite continue, PHP
encoderait une liste au lieu d'un objet, et le programme du navigateur chercherait une cle
dans un tableau. Le cas ne se produit pas avec les categories actuelles, dont les
identifiants commencent a 1 ; il se produirait sur une base reconstruite autrement. Verifie
a l'execution plutot que deduit.

## Criteres RNCP couverts

- **Modele de donnees** : une entite de plus (23), une relation reelle avec sa cardinalite
  justifiee, un arbitrage explicite entre domaine de valeurs et entite, et la mise en
  coherence du dictionnaire, du modele conceptuel et du modele logique dans le meme lot que
  le code.
- **Developpement par les tests** : tests ecrits avant le code, etat rouge constate, 27 tests
  PHP et 18 tests JavaScript ajoutes.
- **Securite et robustesse** : validation serveur du domaine de valeurs, degradation sure sur
  donnee manquante, aucun script en ligne (politique de securite de contenu du back-office).
- **Accessibilite** : regroupement par famille avec etiquettes, case a cocher reellement
  etiquetee, compteur annonce aux technologies d'assistance et visible a l'ecran.
- **API** : la surface JSON et le formulaire HTML disent la meme chose, collections Postman
  et Bruno regenerees depuis leur script source.

## Questions anticipees du jury

**« Pourquoi le Brownie est-il un ingredient ? »** Parce que chaque produit porte une recette,
y compris les desserts, et que c'est la recette qui decremente le stock. Le montrer dans les
donnees repond mieux que l'expliquer.

**« Pourquoi ne pas avoir cree une entite FAMILLE ? »** Une entite se justifie quand elle
porte des attributs propres, un cycle de vie ou une identite que le metier manipule. La
famille n'a qu'un libelle et une liste fermee decidee a la conception. La contrepartie est
assumee et ecrite : la base ne rejetterait pas une valeur inventee inseree en SQL direct, un
test d'integration le couvre a la place.

**« Le filtre empeche-t-il une recette absurde ? »** Non, et c'est voulu. Il rend la recette
coherente plus facile a saisir que l'incoherente. Une regle de gestion aurait ferme la porte
au cas qu'on n'a pas su anticiper.

**« Qu'est-ce qui garantit que la migration ne casse pas une base en service ? »** Trois
gardes, et des tests qui les exercent : rejeu sans effet, non-ecrasement d'une correction
manuelle, coherence entre la migration et le jeu de donnees sur les 50 noms.

## Points d'amelioration conscients

- **Une categorie creee apres coup n'est pas filtree** tant que personne n'a renseigne ses
  familles. Degradation sure, mais silencieuse : rien ne le signale a celui qui cree la
  categorie.
- **La correspondance ne se modifie pas depuis le back-office.** Elle se change par
  migration. Suffisant pour le besoin constate ; la donnee est deja au bon endroit pour qu'un
  ecran vienne se poser dessus.
- **L'import de produits par fichier ne connait pas la famille.** L'en-tete attendu est une
  liste fermee ; y ajouter une colonne casserait les gabarits deja distribues. Un ingredient
  importe reste non classe.
- **L'API JSON remplace la ressource entiere** : une modification qui omet la famille la remet
  a non classe. Comportement deja en place pour les autres champs optionnels de cet objet,
  aligne plutot qu'exceptionnel.
- **`IngredientFamily::isFood()` n'est appelee par aucun code de production.** C'est une
  reponse disponible a la question laissee ouverte par l'ADR-0015, pas une fonctionnalite
  branchee. Dit tel quel pour ne pas laisser croire qu'un comportement a change.
- **Dix familles sont un compromis** cale sur les 50 ingredients d'aujourd'hui. Un catalogue
  qui grossirait beaucoup demanderait de les revoir.

## Liens vers artefacts

- Fiche de decision : `docs/adr/0018-familles-ingredients-filtre-recette.md`
- Modele : `docs/merise/dictionary.md` (3.6, 3.23, note 16), `docs/merise/mcd.md` (5.1, I8, 5.3),
  `docs/merise/mld.md` (4.6, 4.23)
- Base : `db/migrations/0017_ingredient_family.sql`, `db/seeds/0010_ingredient_families.sql`,
  `db/seeds/0003_ingredients_recipes.sql`
- Code : `src/app/Catalogue/IngredientFamily.php`,
  `src/app/Catalogue/CategoryIngredientFamilyRepository.php`,
  `src/public/admin/assets/js/product-recipe.js`
- Fiche de decision liee : `docs/adr/0015-allergenes-calcules-par-produit.md` (la limite que
  ce lot ferme)
