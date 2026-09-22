# ADR-0013 — Vue back-office du catalogue groupee par categorie (en plus de la liste plate)

- Statut : Accepte
- Date : 2026-07-31

## Contexte
Le back-office ne montrait le catalogue que par une **liste plate** (`ProductController::index`)
triee sur `p.display_order, p.name`, ou la categorie n'etait qu'une colonne de texte. Trois
ecarts avec ce que le client voit sur la borne :

1. **L'ordre differe.** La borne trie par `c.display_order, c.name, p.display_order, p.name`
   (`ProductRepository::availableForCatalogue`) : le decoupage en onglets et leur ordre ne se
   lisaient nulle part cote back-office.
2. **Les variantes de taille apparaissent comme des articles autonomes.** Une ligne dont
   `base_product_id` est non nul (R4 : « Coca Cola 50cl ») figurait dans la liste au meme rang
   que sa base, seulement marquee « Variante de X ». Sur la borne, la taille se choisit APRES
   le produit : ce n'est pas un article de la grille.
3. **Les menus n'y figurent pas.** Un menu vit dans la table `menu`, pas `product` : la liste
   des produits ne le remonte pas, alors que la borne l'affiche en tuile a cote des produits de
   sa categorie.

Consequence concrete pour un equipier non-technique : repondre a « qu'est-ce que le client voit
dans l'onglet Boissons, et dans quel ordre ? » supposait de lire le code.

Options envisagees : (a) enrichir la liste plate d'un tri par categorie ; (b) **remplacer** la
liste plate par une vue groupee, comme l'ADR-0012 a remplace la liste-CRUD du stock ; (c) ajouter
une **seconde lecture** de la meme ressource.

## Decision
Option (c) : une **page de lecture supplementaire** `/admin/products/by-category`, gardee par la
meme permission `product.read`, qui range le catalogue comme la borne le presente.

Le remplacement (option b) a ete ecarte : contrairement au cas du stock, la liste plate n'est pas
redondante — elle reste la seule surface qui montre et gere les variantes de taille ligne par
ligne, ainsi que le CRUD complet. Les deux vues se renvoient l'une a l'autre.

Trois choix de mise en oeuvre :

- **Quatre lectures a nombre fixe**, et non une par article : les categories (qui servent
  d'**ossature ordonnee** des sections, donc portent l'ordre des onglets), les produits de base
  groupes par categorie (`ProductRepository::basesByCategory`), le set des produits en rupture
  calculee (RG-T21), et les menus. Le nombre de tailles d'une base est une **sous-requete
  correlee**, pas une requete par produit : le catalogue peut grossir sans N+1.
- **Les variantes sont repliees** sur leur base via le meme predicat `base_product_id IS NULL`
  que ses voisines (`basesOnly`, `availableForCatalogue`, `findForCatalogue`), et comptees en
  pastille « n tailles ».
- **Produits et menus sont normalises cote serveur** en une seule forme d'article, avec leur etat
  de disponibilite deja resolu en trois valeurs (disponible / rupture auto / indisponible). La vue
  reste declarative et l'etat directement testable — meme parti que l'ADR-0012 pour les compteurs
  du tableau de bord stock. Un menu suit la disponibilite de son burger impose, comme sur la borne.

## Consequences
- (+) L'ordre et le decoupage affiches sont ceux de la borne : la question « que voit le client »
  se repond a l'ecran.
- (+) Une taille ne se presente plus comme un produit a part.
- (+) Les menus sont visibles dans une vue catalogue du back-office.
- (+) Une categorie desactivee est signalee en clair (« Masquee sur la borne ») avec la phrase qui
  explique pourquoi ses articles marques disponibles restent introuvables a la commande — cause
  d'incomprehension recurrente pour un equipier.
- (+) Etats et compteurs calcules cote serveur : +17 cas de test (6 depot, 11 controleur) plus un
  cas d'integration qui verifie le predicat anti-variante contre le vrai schema seede.
- (-) Deux vues de la meme ressource a maintenir : un changement de forme d'article touche les deux.
- (-) Pas de tri ni de filtre sur cette page (l'ordre est celui de la borne, c'est son interet) ;
  le besoin de tri reste servi par la liste plate.
- (-) Aucune vignette produit : l'afficher demanderait un repli d'image sans handler en ligne
  (contrainte CSP), donc un fichier de script, ce qu'une page de lecture ne justifie pas.
- Coherent avec ADR-0002 (MVC rendu serveur), ADR-0003 (disponibilite calculee depuis le stock) et
  ADR-0011/0012 (les deux refontes de reference du back-office). Fichiers :
  `src/app/Catalogue/ProductRepository.php`, `src/app/Controllers/ProductController.php`,
  `src/app/Views/admin/products/by_category.php`, `src/app/Views/admin/layout.php`,
  `src/public/admin/index.php`, `src/public/admin/assets/css/admin.css`.
