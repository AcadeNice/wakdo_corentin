# Balayage du back-office (`backoffice-sweep.spec.js`)

Filet de vérification de la mise en page et du fonctionnement du back-office, pour
chaque rôle, chaque page atteignable et chaque largeur d'écran. Il complète
`a11y.spec.js` (mesure axe-core) et `responsive.spec.js` (défilement latéral).

## Lancer

Sur une pile jetable uniquement, pas sur la production :

```
_byan-output/outils/e2e.sh <racine du dépôt> <nom-unique> tests/e2e/backoffice-sweep.spec.js
```

Résultats dans `SWEEP_DIR` (par défaut
`_byan-output/dossier-soutenance/annexes/ux-backoffice/sweep/` sous la racine du dépôt,
dossier non versionné) :

| Fichier | Contenu |
|---|---|
| `rapport.md` | synthèse par catégorie, parcours, matrice rôle x page x largeur, échecs regroupés par cause (nouveaux d'abord), exceptions admises du critère 2.5.8, tailles de police relevées, mesures de contraste des messages |
| `tableau-complet.csv` | une ligne par vérification : rôle, page, largeur, vérification, résultat, catégorie, détail, capture, cause probable, bug connu |
| `resultats.json` | les mêmes lignes, enrichies |
| `captures/` | une capture par échec, éléments fautifs encadrés en rouge |
| `reference/<rôle>/` | capture de référence de chaque page à 1366, 768 et 390 px (témoin avant/après refonte) |
| `pages-<rôle>.json` | pages découvertes et pages refusées pour chaque rôle |

Le test reste rouge tant qu'un échec existe : les défauts connus ne sont pas masqués.

## Comptes

Le seed de démonstration ne fournit que l'administrateur (`db/seeds/0001`). Le premier
test crée par l'interface, sur la pile jetable, un compte par rôle (Responsable,
Équipier cuisine, Équipier comptoir, Équipier drive, plus un remplaçant) et le PIN de
chacun. Si la pile est réutilisée, les comptes déjà présents sont conservés.

## Découverte des pages

Pour chaque rôle, on part de la page d'arrivée après connexion et on suit les liens
présents dans les pages (menu latéral, menu du compte, boutons d'action), une page
par forme d'adresse (`/admin/products/{id}/edit` n'est ouverte qu'une fois). Un lien
qui mène à une page refusée (403) ou introuvable (404) est un échec « lien refusé »
sur la page qui le porte ; la page refusée n'est pas balayée.

## Vérifications

| Vérification | Largeurs | Règle |
|---|---|---|
| statut HTTP, texte technique, lien refusé | 1366 | réponse 200 ; aucun code d'enum, référence, code de permission, `snake_case`, chemin interne, « Array », trace PHP ou « Requête invalide » à l'écran |
| console, réseau | les 4, regroupées | aucune erreur console ni réponse 4xx/5xx sur les ressources de la page |
| chevauchement | les 4 | deux éléments interactifs visibles ne se recouvrent pas de plus de 1 px (enfants d'un même contrôle et éléments masqués exclus) |
| masquage fixe | les 4 | aucun élément fixe (bouton « Police adaptée », etc.) ne recouvre un élément interactif à toutes les positions de défilement |
| défilement horizontal | les 4 | ni le document ni une zone de contenu ne défile latéralement, sauf tableau, bande de menu et onglets de caisse |
| sortie de conteneur | les 4 | aucun élément ne dépasse de son cadre (fond, bordure ou ombre) ni de la fenêtre |
| texte coupé | les 4 | aucun texte rogné par un `overflow` caché, aucun texte qui déborde de son bouton ou libellé |
| cible tactile | les 4 | 24 x 24 px minimum (WCAG 2.2, 2.5.8), sauf exceptions du critère (lien dans une phrase, case native, espacement) listées dans le rapport |
| alignement | les 4 | bords gauches communs des libellés et champs d'un formulaire, bord ou axe commun par colonne de tableau, bords haut et bas communs des boutons d'une même barre (tolérance 2 px) |
| échelle de police | les 4 | chaque taille utilisée figure dans les feuilles de style de la page ; les valeurs isolées sont relevées |
| messages (contraste) | 1366 | états vides, erreurs (déclenchées sur les formulaires vides) et confirmations : contraste d'au moins 4,5:1 |
| ordre de tabulation, focus visible | 1366, 768, 390 | le focus suit l'ordre visuel dans chaque région, chaque élément change d'aspect au focus et n'est pas masqué |

## Parcours

Chaque étape vérifie l'effet à l'écran et le message de confirmation : catégorie
(créer, modifier, masquer), produit (créer avec et sans image, prix avec PIN,
suppression avec PIN), menu (créer, modifier), stock (inventaire et ajustement avec
PIN, réapprovisionnement, seuils), comptoir (commande, encaissement, remise), cuisine
(commande prête), annulation avec PIN, drive, comptes (créer un équipier, réinitialiser
son PIN), rôles (créer, cocher des permissions), « Mon PIN » pour chaque compte.

## Options

| Variable | Effet |
|---|---|
| `SWEEP_ROLES=admin,cuisine` | ne balaie que ces rôles |
| `SWEEP_MAX_PAGES=10` | plafond de pages par rôle (défaut 80) |
| `SWEEP_DIR=<dossier>` | dossier de sortie |
| `SWEEP_COMPARE=1` | compare chaque capture aux captures de référence (même comparateur d'images que `toHaveScreenshot`) au lieu de les réécrire ; écarts dans `comparaison/ecarts/` |
| `SWEEP_REFERENCE_DIR=<dossier>` | captures de référence à comparer (défaut `SWEEP_DIR/reference`) |
| `SWEEP_MAX_DIFF=0.02` | part de pixels différents tolérée en comparaison |

La comparaison est désactivée par défaut : les données de la pile (numéros de
commande, dates, stock) changent d'une exécution à l'autre, elle sert de témoin
ponctuel pendant la refonte visuelle, pas de filet de CI. `e2e.sh` ne transmet pas ces
variables ; pour les utiliser, ajouter `-e SWEEP_COMPARE=1` à la commande `docker run`
qui lance Playwright contre la pile jetable.

## Causes et bugs connus

`causes.js` rattache chaque famille d'échec à sa cause probable (fichier:ligne) et, le
cas échéant, au bug déjà documenté dans l'audit UX (`BUG-01`, `ERG-02`...). Une famille
sans règle apparaît « cause à analyser » dans le rapport.
