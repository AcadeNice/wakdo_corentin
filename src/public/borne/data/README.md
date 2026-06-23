# Donnees statiques de la borne (repli P5)

`categories.json` et `produits.json` sont un **repli statique fige** consomme par
le front de la borne (Bloc 1 / P5) tant que l'API REST n'existe pas. Ils sont
copies du jeu de donnees source de l'ecole (`docs/merise/_sources/`), **pas**
generes depuis la base.

## Ces fichiers ne refletent pas la base

Le catalogue servi ici est le jeu source complet (66 produits) ; le seed de la
base (`db/seeds/0002_catalogue.sql`) en est un sous-ensemble curate (53 produits).
Les categories, elles, coincident (9 de chaque cote). La borne est une demo front
sur donnees statiques : un ecart de comptage produits avec la table `product` est
**attendu**, ce n'est pas une incoherence a corriger.

## Point de bascule (P4)

`assets/js/data.js` lit ces fichiers via les constantes `CATEGORIES_URL` /
`PRODUCTS_URL`. En P4, ces constantes pointeront vers `/api/categories` et
`/api/products` (memes formes de retour, le reste du code est agnostique). La
borne refletera alors la base via l'API, et ces fichiers deviendront obsoletes
(a retirer a ce moment-la).
