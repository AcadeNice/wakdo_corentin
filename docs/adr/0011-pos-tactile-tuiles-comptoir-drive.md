# ADR-0011 — POS tactile a tuiles pour la saisie comptoir/drive

- Statut : Accepte
- Date : 2026-06-25

## Contexte
La saisie de commande comptoir/drive (mlt 4.1, `CREATE_COUNTER_ORDER`) avait d'abord ete
livree comme formulaire-liste enrichi (#100) : une liste de produits avec champs de
quantite, prix, verrou du mode de service au canal drive, file des commandes recentes.
Cet ecran est destine a des equipiers non-techniques, sur tablette, dans un contexte de
caisse ou la rapidite compte. Le formulaire-liste se prete mal au tactile (petites cibles,
defilement) et ne ressemble pas au geste deja appris cote borne client. Options :
conserver et raffiner le formulaire-liste ; adopter un paradigme de caisse (POS) a tuiles
reutilisant l'UX borne ; integrer une bibliotheque de POS tierce.

## Decision
La saisie comptoir/drive devient un **POS tactile a tuiles** (#104) calque sur la borne
client : onglets categories en haut, grille de tuiles produits/menus, panneau commande
persistant a droite ; un tap ajoute le produit, un produit a modificateurs ou un menu
ouvre une modale de composition. Le panier est construit cote client en vanilla JS
CSP-safe (`'self'`, zero handler inline) a partir d'un script JSON inerte, puis serialise
dans un champ cache `items_json`. Le **serveur reste seul juge** : il revalide la forme
(RG-T18), recalcule les prix et resout les modificateurs (RG-T16) ; les prix affiches cote
client sont indicatifs. Un **seul controleur** sert les deux canaux, la `source`
(counter/drive) etant derivee du chemin de la requete.

## Consequences
- (+) Geste unifie borne/comptoir : moins d'apprentissage, reutilisation des patterns
  composeur deja eprouves cote borne.
- (+) Cibles tactiles larges adaptees a la tablette ; zero jargon dev a l'ecran
  (utilisateurs non-techniques).
- (+) Decoupage par chemin (`/drive...` vs `/counter...`) : les canaux restent etanches,
  un equipier ne peut pas requalifier sa commande via un champ falsifie.
- (+) Sans framework front (coherent ADR-0002) ; coherent avec ADR-0001 (pas de
  dependance tierce, donc pas de POS externe).
- (-) Le POS est interactif par nature : sans JS, la grille ne s'affiche pas (un message
  invite a activer JS). Un repli legacy `qty_<id>` reste accepte quand `items_json` est
  absent, mais l'experience cible suppose JS.
- (-) Cet ecran a ete refondu deux fois a court intervalle (#100 puis #104) ; le palier
  #100 est assume comme etape, pas comme dette cachee.
- Fichiers : `src/app/Controllers/CounterOrderController.php`,
  `src/app/Views/admin/counter/new.php`, `src/public/admin/assets/js/counter-order.js`.
  Detail : journal `docs/journal/2026-06-25--audit-remediation-et-features-94-105.md`.
