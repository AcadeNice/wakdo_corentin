# ADR-0018 — Familles d'ingredients et filtre souple du constructeur de recette

- Statut : Accepte
- Date : 2026-09-27

## Contexte

Le constructeur de recette du formulaire produit proposait les **50 ingredients du
catalogue a plat**, dans un selecteur unique, quelle que soit la categorie du produit en
cours de creation. En composant un burger, l'equipier se voyait donc proposer `Brownie`,
`Gobelet`, `Dose Coca` et `Cheesecake` au milieu des pains et des steaks. Signale depuis
l'usage reel : « si on ajoute un nouveau produit, on ne va pas ajouter un brownie ».

Le reflexe serait de retirer ces lignes du catalogue d'ingredients. Ce serait une erreur,
et la lecture des donnees le montre :

```
produit "Brownie"    -> recette : 1x ingredient "Brownie"
produit "Coca Cola"  -> recette : 1x "Dose Coca" + 1x "Gobelet"
```

Chaque produit porte une recette, y compris les desserts et les boissons, parce que c'est
la recette qui decremente le stock au paiement (RG-T20) et qui alimente la disponibilite
calculee (RG-T21). Retirer `Brownie` du catalogue d'ingredients supprimerait le suivi de
stock des desserts.

Le defaut n'est donc pas la presence de ces ingredients : c'est **l'absence de toute
classification** permettant de savoir a quoi chacun sert. La table `ingredient` ne portait
aucune notion de nature ou de famille, et `ProductController::renderForm()` appelait
`IngredientRepository::all()` sans filtre.

Ce manque avait deja ete identifie. La fiche ADR-0015, en consequences, nommait le cas
limite et sa correction possible :

> `Gobelet` est porte comme ingredient de recette (pour le stock) alors que ce n'est pas un
> aliment mais un materiau au contact des denrees (reglement 1935/2004) [...] Un drapeau
> `is_food` sur `ingredient` serait la correction propre si d'autres emballages entrent au
> catalogue.

La presente decision repond a cette limite, en la generalisant.

## Decision

### (a) Classer, pas retirer

On ajoute une **famille** a chaque ingredient et on filtre ce que le selecteur propose,
selon la categorie du produit. Le catalogue d'ingredients reste complet, les recettes
existantes restent intactes, le suivi de stock n'est pas touche.

Dix familles, choisies a partir des 50 ingredients reels plutot que d'une taxonomie
theorique : `pain`, `viande`, `fromage`, `legume`, `sauce`, `feculent`, `dose_boisson`,
`contenant`, `dessert`, `dosette`.

La famille `contenant` isole exactement le cas que l'ADR-0015 laissait ouvert. Elle rend
inutile le drapeau `is_food` envisage alors : un drapeau binaire aurait separe l'aliment
du non-aliment sans rien dire du reste, la ou dix familles repondent a la fois a la
question de l'ADR-0015 et a celle du filtrage. Le besoin « est-ce un aliment ? » se derive
de la famille plutot que d'une seconde colonne qui pourrait la contredire.

A dire tel quel : la methode `IngredientFamily::isFood()` qui porte cette derivation
**n'est appelee par aucun code de production a ce jour**. Verifie, pas suppose. C'est une
reponse disponible a la question que l'ADR-0015 posait, pas une fonctionnalite branchee ;
elle le deviendra le jour ou un emballage entrera au catalogue et ou le traitement des
allergenes devra distinguer l'aliment du materiau. La nommer ici evite de laisser croire
qu'un comportement a change alors que seule la possibilite existe.

### (b) La classification est une donnee, pas un tableau ecrit dans le code

Deux objets de modele :

- `ingredient.family` (VARCHAR(32), NULL autorise) — a quelle famille appartient cet
  ingredient.
- `category_ingredient_family (category_id, family)` — quelles familles une categorie de
  produits accepte. Cle primaire composite, cle etrangere sur `category` en cascade.

Trois facons de porter la correspondance categorie vers familles ont ete pesees :

| Option | Souplesse | Charge de saisie | Coherence avec le modele |
|---|---|---|---|
| tableau ecrit en dur dans une classe PHP | faible (redeploiement) | nulle | moyenne : la regle vit hors du modele |
| **table `category_ingredient_family`** | **bonne** | **un jeu de donnees** | **forte : une vraie relation** |
| autorisation ingredient par ingredient | maximale | 50 x 9 lignes a tenir | faible : aucun regroupement |

La table l'emporte. Le surcout par rapport au tableau en dur est d'un objet de modele et
d'un jeu de donnees ; le gain est que la regle devient une donnee lisible, interrogeable
et modifiable par migration, au lieu d'une constante enfouie dans une classe. L'option par
ingredient reporte le travail sur l'equipier : 450 decisions a tenir a jour pour dire ce
que dix familles disent.

### (c) Le filtre est souple, pas un blocage

Le selecteur montre par defaut les familles pertinentes pour la categorie choisie, et une
case a cocher « Afficher tous les ingredients » desactive le filtre. Aucune validation
serveur ne refuse une composition qui sortirait des familles attendues.

La raison est que la classification finira par rencontrer un cas qu'elle n'avait pas
prevu : une salade avec des croutons, un dessert servi dans un contenant, une recette
promotionnelle. Un blocage dur transformerait ce cas en impasse, sans recours pour
l'equipier et sans autre issue qu'une migration. Le filtre resout le probleme constate
— on ne voit plus le Brownie en composant un burger — sans construire un mur devant un cas
qu'on n'a pas su anticiper.

Corollaire assume : le filtre est une aide a la saisie, pas une regle de gestion. Il ne
garantit pas qu'une recette soit coherente ; il rend la recette coherente plus facile a
saisir que l'incoherente.

### (d) L'inconnu est montre, pas cache

Deux absences se comportent de la meme facon, et c'est deliberement le cote permissif :

- un ingredient sans famille (`family` a NULL) reste visible dans toutes les categories ;
- une categorie absente de `category_ingredient_family` n'est pas filtree du tout.

Meme posture cote navigateur : si les donnees de correspondance sont absentes ou
illisibles, le comportement retenu est « aucun filtre, on montre les 50 », pas « liste
vide ».

Ce choix repond a un incident du jour meme. Un defaut de cache avait fait servir une
ancienne version du fichier `product-recipe.js`, et l'equipier s'est retrouve devant un
constructeur de recette ou rien ne se passait, sans message. Un filtre qui viderait
silencieusement le selecteur en cas de donnee manquante reproduirait exactement cette
classe de defaut, cette fois par conception. Sur doute, on montre trop plutot que rien.

Dans le meme esprit, le nombre d'ingredients montres sur le total est affiche a cote du
selecteur quand le filtre est actif : un filtre invisible qui cache des lignes est un
piege pour celui qui cherche un ingredient qu'il sait exister.

### (e) Les recettes deja enregistrees ne sont pas reecrites

Une recette existante dont un ingredient sort du filtre de sa categorie est conservee
telle quelle, sans retrait ni signalement d'erreur. La classification est posee apres coup
sur des donnees qui etaient correctes avant elle ; elle n'a pas autorite pour les corriger.

De meme, la migration ne reclasse pas un ingredient qui porterait deja une famille : une
installation en service a pu etre ajustee a la main, et la reprise ne doit pas ecraser ce
travail.

## Alternatives ecartees

**Retirer les non-aliments du catalogue d'ingredients.** La lecture naive de la demande.
Ecartee : `Brownie`, `Gobelet` et les doses de boisson sont les lignes de recette qui
decrementent le stock des desserts et des boissons. Les retirer casserait le suivi de
stock de ces deux categories, variantes de taille comprises.

**Un drapeau `is_food` binaire**, tel qu'envisage en ADR-0015. Ecarte : il aurait resolu
le cas `Gobelet` sans rien apporter au probleme signale, puisqu'un `Brownie` est un
aliment et resterait propose pour un burger. Deux colonnes auraient fini par se
contredire ; une seule classification les remplace.

**Une deduction sur le nom de l'ingredient** (tout ce qui commence par « Dose » est une
boisson). Ecartee : aucune donnee derriere, invalide des la premiere creation
d'ingredient par un equipier, et invisible a la relecture. C'est de la devinette habillee
en regle.

**Un blocage dur cote serveur.** Ecarte pour les raisons de la decision (c). A noter que
le refus serveur existe deja la ou il est justifie : la composition est revalidee cote
serveur contre le catalogue reel (RG-T18, RG-T16), et un ingredient inconnu est rejete.
Le filtre par famille se place en amont de cette garde, il ne la remplace pas et ne la
durcit pas.

**Rendre la correspondance modifiable depuis le back-office.** Ecarte pour l'instant : la
table `category_ingredient_family` est peuplee par le jeu de donnees
`db/seeds/0010_ingredient_families.sql` (23 lignes, reparties sur 8 des 9 categories --
`menus` n'en recoit volontairement aucune, cf. decision (d)) et reste modifiable par
migration, ce qui couvre le besoin reel constate. Un ecran d'administration pour ces 23
lignes de correspondance ajouterait une surface a tester et a rendre accessible sans
demande qui le justifie. La decision est reversible : la donnee est deja au bon endroit
pour qu'un ecran vienne se poser dessus.

## Consequences

- (+) Le probleme signale disparait : composer un burger ne propose plus de dessert ni de
  gobelet.
- (+) La limite de modelisation nommee en ADR-0015 est levee, et levee une seule fois pour
  les deux besoins.
- (+) La regle est une donnee : elle se lit en SQL, se modifie par migration, et se montre
  comme une relation du modele plutot que comme une constante dans une classe.
- (+) Aucune permission ajoutee : le catalogue reste a 23. La famille se saisit dans
  l'ecran ingredient existant, sous `ingredient.manage`.
- (+) Le comportement sur donnee manquante penche du cote qui montre trop, ce qui est
  verifiable par test et ne peut pas produire un ecran ou rien ne se passe.
- (-) Le filtre est une aide, pas une garantie. Une recette incoherente reste saisissable,
  volontairement. Quiconque attend de ce lot une validation metier des recettes sera decu,
  et c'est le bon comportement.
- (-) La classification des 50 ingredients existants est portee a deux endroits : la
  migration, pour les installations en service, et le jeu de donnees `0003`, pour les
  installations neuves. Contrainte du mecanisme de suivi par nom de fichier, deja rencontree
  avec la migration `0016`. Un test verifie que les deux disent la meme chose.
- (-) Une categorie creee apres coup n'est pas filtree tant qu'aucune ligne de
  correspondance ne lui est associee. Degradation sure, mais silencieuse : celui qui cree
  une categorie ne verra pas que le filtre ne s'y applique pas.
- (+) Les deux surfaces d'ecriture disent la meme chose : le formulaire HTML et l'API JSON
  d'administration lisent et ecrivent la famille avec la meme validation, un refus rendant un
  422 comme toute autre validation (ADR-0006). Les collections Postman et Bruno sont
  regenerees depuis leur script source, pas retouchees a la main.
- (-) **L'API JSON remplace la ressource entiere.** Une modification qui omet `family` la
  remet a non classe. Ce n'est pas propre a la famille : c'est le comportement deja en place
  pour les autres champs optionnels de cet objet, verifie sur `pack_label` avant d'y aligner
  la famille plutot que d'inventer une exception. Le changer serait un choix a trancher pour
  l'objet entier, pas pour un champ.
- (-) **L'import de produits par fichier ne connait pas la famille.** L'en-tete attendu est
  une liste fermee et un fichier qui s'en ecarte est refuse : y ajouter une colonne casserait
  tout gabarit deja distribue. Un ingredient cree par import reste donc non classe, visible
  partout. Degradation sure, mais un import massif laisserait un catalogue a reclasser a la
  main.
- (-) Dix familles sont un compromis. Elles collent aux 50 ingredients d'aujourd'hui ; un
  catalogue qui grossirait beaucoup demanderait de les revoir, et cette fiche serait alors
  remplacee plutot que modifiee.
