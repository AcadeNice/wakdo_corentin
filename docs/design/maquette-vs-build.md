# Maquette borne vs kiosk construit — decomposition et tracabilite

> Auteur : BYAN. Note de tracabilite maquette -> code (appui oral RNCP Bloc 1 :
> "comment etes-vous passe de la maquette au code ?").
> Source : `docs/design/maquette-borne.pdf` (export Figma de l'ecole, 10 ecrans,
> format 1440x1024). Ecrans exportes un a un dans `docs/design/screens/`.

## 1. Lecture d'ensemble

La maquette decrit un **parcours de type McDonald's** (Big Mac, Best Of, McCafe,
arches M, Coca) : c'est la base de reference a rebrander en Wakdo.

Point central : les **10 "ecrans" ne sont pas 10 pages**. Ce sont en realite
~4 ecrans de base plus un systeme de modales qui s'ouvrent par-dessus l'ecran de
commande :

```
Accueil
   -> Ecran de commande UNIQUE
        (bandeau categories en haut + grille produits + panneau commande persistant a droite)
        sur lequel s'ouvrent les modales de composition (taille menu, accompagnement, boisson, format/quantite)
   -> Chevalet (sur place : saisie du numero)
   -> Remerciement
```

Le kiosk construit, lui, eclate cet ecran unique en **pages distinctes** et n'a
pas de panneau de commande persistant. C'est l'origine du sentiment "ca ne
correspond pas".

## 2. Decomposition ecran par ecran

### Ecran 1 — Accueil
![Accueil](screens/01-accueil.png)
- "Bonjour," + "Souhaitez-vous consommer votre menu sur place ou preferez-vous l'emporter ?"
- Deux grandes cartes : **Sur Place** (icone table) / **A Emporter** (icone sac).
- Fond : arches + Big Mac + Coca.

### Ecran 2 — Ecran de commande (pivot)
![Ecran de commande menus](screens/02-commande-menus.png)
- **Bandeau categories horizontal** (Menus actif, Sandwiches, Wraps, Frites, Boissons Froides, Encas, Desserts...) avec fleches rouges ◀ ▶ pour faire defiler.
- Titre de section "Nos menus" + sous-titre + **grille de produits** (image, nom, prix).
- **Panneau de commande persistant a droite** : numero de commande (72), "Sur place : 326", lignes de commande avec options en puces et icone corbeille, "TOTAL (ttc) 36,50 EUR", boutons "Abandon" / "Payer", logo W en haut.
- Cet ecran est le coeur de la maquette : tout le reste (sauf accueil/chevalet/remerciement) se joue ici ou en modale par-dessus.

### Ecran 3 — Modale "Une grosse faim ?" (composition menu, etape 1)
![Modale taille menu](screens/03-modale-taille-menu.png)
- Choix de la taille : **Menu Maxi Best Of** / **Menu Best Of**.
- Bouton "Etape Suivante". Premiere etape d'un assistant en modale.

### Ecran 4 — Modale "Choisissez votre accompagnement" (etape 2)
![Modale accompagnement](screens/04-modale-accompagnement.png)
- **Frites** / **Potatoes**.
- Boutons "Retour" + "Etape Suivante".

### Ecran 5 — Modale "Choisissez votre boisson" (etape 3)
![Modale boisson](screens/05-modale-boisson.png)
- Carrousel de boissons (Eau, Coca, Coca Zero, Jus de pomme BIO, The...) avec ◀ ▶.
- Bouton "Ajouter le menu a ma commande" (fin de l'assistant).

### Ecran 6 — Ecran de commande, categorie Boissons Froides (a la carte)
![Commande boissons](screens/06-commande-boissons.png)
- Meme ecran pivot, categorie "Boissons Froides" active.
- Grille de 8 boissons avec prix unitaires (Eau 1 EUR, Coca 1.90 EUR, Fanta 1.90 EUR, Jus de pomme BIO 2.30 EUR...).

### Ecran 7 — Selection d'un produit (etat)
![Boisson selectionnee](screens/07-boissons-selection.png)
- Meme grille, "Coca Cola" entoure en jaune : etat visuel de selection.

### Ecran 8 — Modale "Une petite soif ?" (option produit a la carte)
![Modale format quantite](screens/08-modale-format-quantite.png)
- Taille **30Cl / 50Cl** (+0.50 EUR pour le 50Cl).
- **Stepper de quantite** (- 1 +).
- Boutons "Annuler" / "Ajouter a ma commande".

### Ecran 9 — Chevalet (sur place)
![Chevalet](screens/09-chevalet.png)
- "Pour etre servis a table," + "Recuperez un chevalet et indiquez ici le numero inscrit dessus".
- Grands chiffres `2 6 1` + bouton "Enregistrer le numero".

### Ecran 10 — Remerciement
![Remerciement](screens/10-remerciement.png)
- "Toute l'equipe vous remercie, Et vous souhaite un bon appetit dans nos restaurants, A bientot !"
- Bouton "Nouvelle commande".

## 3. Maquette -> kiosk construit (mapping)

| Maquette | Kiosk construit | Verdict |
|----------|-----------------|---------|
| 1. Accueil sur place / a emporter | `index.html` | conforme |
| 2 + 6. Ecran de commande unique (bandeau + grille + **panneau persistant**) | eclate en `categories.html` -> `products.html` -> `cart.html` | divergence structurante : multi-pages, et **pas de panneau de commande persistant** |
| (pas de page categories separee) | `categories.html` plein ecran "Que souhaitez-vous commander ?" | ecran **ajoute** (la maquette met les categories en bandeau) |
| 3-5. Composeur menu = **assistant modal en etapes** | `page-product-menu.js` = composition **libre** | divergence (le refactor "consommer les slots /api/menus" est deja en file P4) |
| 8. Modale d'option produit (taille + quantite) | `product.html` (page) | divergence : page au lieu de modale |
| 9. Ecran **chevalet** dedie (saisie numero) | numero gere par l'API (chunk 1a), affiche en confirmation | manquant cote ecran |
| (aucun ecran de paiement) | `payment.html` "Carte bancaire / Especes" | ecran **ajoute** par le build |
| 10. Remerciement | `confirmation.html` | conforme |

## 4. Ecarts structurants (le fond du sujet)

1. **Paradigme inverse.** Maquette = **mono-ecran** (un plan de commande avec
   categories en bandeau et un panneau recapitulatif persistant a droite, modales
   par-dessus). Build = **multi-pages** classiques (categories -> produits ->
   produit -> panier). C'est l'ecart structurant principal.
2. **Panneau de commande lateral absent.** La piece centrale de la maquette
   (numero de commande, lignes editables avec corbeille, TOTAL ttc, Abandon /
   Payer, visible en permanence) n'est pas presente dans le build.
3. **Composition de menu.** Maquette = assistant modal en etapes ; build =
   composition libre cote client (`page-product-menu.js`).

## 5. Rebrand McDonald's -> Wakdo

Le visuel de la maquette est du McDonald's litteral (Big Mac, Best Of, McCafe,
arches, "Tous a l'eau by M"). Le rebrand vers Wakdo (logo W, catalogue propre)
est attendu et legitime : le branding McDo n'est pas livrable. Le sujet de cette
note n'est donc pas le rebrand mais la **structure** des ecrans.

## 6. Suite

Re-alignement du kiosk sur la maquette (panneau persistant + bandeau categories +
composeur en modale) = chantier UI conduit via un cycle FD dedie. Backlog des
divergences = section 3 ci-dessus.
