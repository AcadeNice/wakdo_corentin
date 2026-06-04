# Conception P1 — revue d'alignement + revision prod-like du modele de donnees

**Date** : 2026-06-04
**Branche** : `feat/p1-conception`
**PR** : a venir (apres reecriture des docs Merise)
**Duree estimee** : session de decision (point par point)

---

## Ce qui a ete fait

1. **Revue d'alignement complete** de tous les `.md` du projet (PROJECT_CONTEXT, dictionary, mcd, mct, mlt, mld, UML, les 13 notes de `docs/notes/`, le journal) pour verifier que la conception P1 ne derive pas du cadrage. Synthese dans `docs/notes/revue-alignement-p1.md` (non versionne).
2. **Session de decision point par point** sur le modele de donnees. Les decisions ci-dessous remplacent ou precisent plusieurs choix du dictionnaire / MCD / MLD v0.1.
3. **Principe directeur acte** : le produit vise est **prod-like, pas MVP**. Tout ce qui est decide est implemente dans le livrable final.

Aucune reecriture des docs Merise n'a encore ete faite : cette session fige les decisions, la propagation dans les 5 docs Merise + PROJECT_CONTEXT se fera en une passe une fois les points D4-D8 tranches.

---

## Pourquoi — decisions et alternatives

### Decision 1 — Suppression de `commande_event`, traçabilite par timestamps de phase

- **Decision** : abandon de la table d'audit append-only `commande_event`. La traçabilite passe par `commande.status` (etat courant) + une colonne `DATETIME` par phase (`paid_at`, `preparing_at`, `ready_at`, `delivered_at`), plus `created_at`.
- **Alternatives** : (A) garder `commande_event` ; (B) colonnes `created_by_user_id` denormalisees ; (C) retrait total de la traçabilite.
- **Raison** : en restaurant, le compte back-office est **partage par poste** (cuisine, accueil), pas individuel. L'attribution par personne n'a donc pas de valeur. Le besoin reel est : compter par canal et mesurer les **durees entre phases**. Les timestamps par phase couvrent durees + heures de la journee (stats `service_day`, KPI), sans la complexite d'un journal d'evenements. Mantra #37 (Ockham).

### Decision 2 — Convention de nommage anglaise, par couche

- **Decision** : tout en anglais. BDD en `snake_case`, classes PHP en `PascalCase`, methodes/proprietes PHP et JS en `camelCase`, JSON/API en `camelCase`.
- **Alternatives** : conserver le francais (dictionnaire v0.1) ; `camelCase` jusque dans les noms de tables SQL.
- **Raison** : le `snake_case` reste la convention courante en SQL et evite les pieges de sensibilite a la casse des noms de tables sous Linux/Docker (deja rencontres en Session 4 sur les noms d'images). Le `camelCase` reste la ou il est standard (code PHP/JS). Resout le point D3 (valeur `source` : `comptoir` -> `counter`).

### Decision 3 — Machine a etats avec phase de paiement

- **Decision** : conservation de la machine deux phases `pending_payment -> paid -> preparing -> ready -> delivered` (+ `cancelled`). Transition `pending_payment -> paid` atomique a la creation dans le cadre RNCP (saisie du numero = substitut de paiement).
- **Raison** : une borne fast-food reelle encaisse a la borne ; modeliser la phase paiement reflete le metier et laisse la porte ouverte a un vrai paiement sans migration destructive. Cout d'une valeur d'ENUM.

### Decision 4 — TVA portee par le produit, pas par le mode de consommation (apres fact-check)

- **Decision** : la TVA devient un attribut du produit (`vat_rate`), 10 % par defaut, 5,5 % sur les items en contenant conservable (eau, jus en bouteille). La TVA de la commande se calcule ligne par ligne, taux snapshote sur `order_item`. `mode_consommation` est renomme `service_mode` et conserve **uniquement** pour les stats/KPI (sur place / a emporter / drive), sans role fiscal.
- **Alternative ecartee** : la regle initiale du dictionnaire « 10 % sur place / 5,5 % a emporter ».
- **Raison** : fact-check de la regle initiale contre la doctrine fiscale officielle. Resultat : le taux depend de la **nature consommation immediate vs differee**, pas du mode sur place / a emporter. Voir bloc FACT-CHECK ci-dessous.

```
FACT-CHECK
Claim audite : "TVA 10% sur place / 5,5% a emporter" (dictionnaire note 9, mlt RG-2)
Domaine      : compliance (fiscal)
Verdict      : le claim initial est INEXACT
Source       : BOFiP BOI-ANNX-000495 + BOI-TVA-LIQ-30-10-10 (doctrine officielle impots.gouv.fr)
Regle reelle : 10% pour la consommation immediate (sur place OU a emporter) ;
               5,5% pour les produits en contenant conservable (bouteille, canette) / consommation differee
Confiance    : 95% (L1, texte officiel)
```

### Decision 5 (D1) — Personnalisation reelle des menus

- **Decision** : un menu est bati autour d'un **burger fixe** ; le client choisit accompagnement, boisson, sauce. Format **Normal / Maxi** au niveau du menu, qui fait basculer accompagnement + boisson en grande taille et change le prix (deux prix par menu : `price_normal_cents`, `price_maxi_cents`). A la carte, la taille existe la ou la donnee la porte (frites, potatoes). Modele relationnel : table `menu_slot` (emplacements a choix) + `order_item_selection` (choix du client).
- **Alternative ecartee** : stocker les choix en bloc JSON ; menu combo fige.
- **Raison** : une borne sans choix reel ne reflete pas le metier. Le relationnel est interrogeable (stats KPI : boisson la plus prise, % grandes tailles) et plus defendable au jury Bloc 2 que du JSON opaque.
- **Calibrage prix Maxi** : le supplement Maxi est derive de la donnee (Grande Frite 3,50 − Moyenne Frite 2,75 = 0,75) plus un upsize boisson comparable, soit ~1,50 €. Cross-check marche reel (McDonald's France, ecart Best Of -> Maxi Best Of ~1,50-2 € en 2026) : coherent. Wakdo etant un pastiche fictif, on derive de la donnee plutot que copier les prix reels.

### Decision 6 (D2) — Configurateur d'ingredients complet

- **Decision** : personnalisation au niveau ingredient (retirer = gratuit, ajouter = supplement) sur **tous les sandwichs composes** (burgers, wraps, cheeseburger), aussi bien a la carte que dans un menu. Tables : `ingredient`, `product_ingredient` (composition par defaut + retirable + ajoutable + supplement), `order_item_modifier` (modifications a la commande).
- **Alternatives** : note texte libre ; jeu d'options legeres ; report post-MVP.
- **Raison** : choix prod-like assume par l'auteur. Les compositions reelles seront saisies en seed (recuperees publiquement, coherent avec un catalogue deja calque sur des produits connus).

### Decision 7 — Modal allergenes derivee des ingredients

- **Decision** : table `allergen` + `ingredient_allergen`. Les allergenes d'un produit sont **calcules** par jointure sur sa composition, sans ressaisie manuelle. Affichage en modal sur la borne pour chaque produit.
- **Raison** : reutilise la donnee ingredient (Decision 6) sans duplication ; coherence garantie. Aligne avec le reglement INCO (UE) 1169/2011 (declaration des 14 allergenes ; liste officielle a confirmer au seed). Nourrit l'accessibilite du Bloc 1.

---

## Comment — points techniques cles

- **Taille / format unifies** : une notion `normal` / `maxi`. A la carte, la taille existe via des produits distincts (la donnee ecole modelise « Petite/Moyenne/Grande Frite » comme 3 produits). En menu, le format est un attribut de la ligne menu qui cascade sur les composants (pas de prix individuel, compris dans le prix combo).
- **Snapshots** : prix unitaire ET taux TVA sont snapshotes sur `order_item` au moment de la commande (integrite historique, meme logique que le snapshot de libelle deja prevu).
- **Personnalisation du burger dans un menu** : les modifications (`order_item_modifier`) doivent pouvoir s'attacher au burger qu'il soit pris seul ou comme burger fixe d'un menu. Materialisation a preciser au DDL.
- **Couleurs KDS back-office** : calculees a l'affichage (`maintenant − paid_at` vs seuil SLA global ~10 min en config), aucune donnee supplementaire a stocker.

---

## Criteres RNCP couverts

- **Bloc 2 - Cr 3.a / 3.b** : analyse et modelisation des donnees (dictionnaire, MCD, MLD), passage relationnel, contraintes referentielles, polymorphisme, snapshots.
- **Bloc 2 - Cr 3.d** : la TVA correcte et le calcul ligne par ligne demontrent la rigueur sur la donnee.
- **Bloc 1 - Cr 1.c** : la modal allergenes renforce l'accessibilite / l'information utilisateur.
- **Compliance / fact-check** : la regle TVA est sourcee L1 (BOFiP), conforme au protocole `.claude/rules/fact-check.md`.

---

## Questions anticipees du jury

- **Q** : "Pourquoi avoir abandonne le journal d'evenements de commande ?"
  **R** : Le compte back-office est partage par poste, donc l'attribution individuelle d'une transition n'a pas de valeur metier. Le besoin reel (durees entre phases, heures) est couvert par des timestamps par phase sur la commande, sans la complexite d'un event store.

- **Q** : "Vous appliquez 5,5 % a l'emporter ?"
  **R** : Non. Apres verification du BOFiP, le taux depend de la consommation immediate ou differee, pas du mode sur place / a emporter. En fast-food, ce qui est chaud / en gobelet est a 10 % dans les deux cas ; le 5,5 % concerne les contenants conservables (bouteille, canette). La TVA est donc portee par le produit.

- **Q** : "Comment gerez-vous les allergenes sans les ressaisir pour chaque produit ?"
  **R** : Ils sont modelises au niveau ingredient. Les allergenes d'un produit sont calcules par jointure sur sa composition. Modifier un ingredient met a jour tous les produits concernes.

---

## Points d'amelioration conscients

- **Scope volontairement etendu** : le modele passe de ~11 a ~16 entites (configurateur d'ingredients + allergenes + selections de menu). Choix prod-like assume. Consequence : `PROJECT_CONTEXT` §7 (scope, mot « MVP » a retirer, items a deplacer en IN scope) et §11 (planning / budget heures) sont a rechiffrer pour rester honnetes.
- **Docs Merise a reecrire** : dictionary, mcd, mct, mlt, mld doivent etre repris en une passe (anglais, 16 entites, prod-like) une fois les decisions restantes tranchees. Reecriture differee volontairement pour ne pas toucher ces docs deux fois.
- **Decisions encore ouvertes** (a trancher avant la reecriture) : D4 (liste des roles unifiee), D5 (vocabulaire des permissions), D6 (correction de la formule `service_day` — coupure a 10h, pas 4h30), D7 (subnet Docker : doc vs realite), D8 (prefixe du numero de commande).
- **Diagrammes** : MCD et MLD a regenerer pour refleter le modele a 16 entites.

---

## Liens vers artefacts

- Revue d'alignement : `docs/notes/revue-alignement-p1.md` (non versionne)
- Docs impactes a venir : `docs/merise/{dictionary,mcd,mct,mlt,mld}.md`, `docs/PROJECT_CONTEXT.md`
- Sources fact-check TVA : BOFiP BOI-ANNX-000495, BOI-TVA-LIQ-30-10-10 (impots.gouv.fr)
- Reference prix Maxi : mcdonalds.fr (menus Best Of / Maxi Best Of), cross-check de magnitude uniquement
