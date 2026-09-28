# ADR-0020 — Le responsable peut annuler une commande

- Statut : Accepte
- Date : 2026-09-27
- Remplace : la decision D5 du 2026-06-04, sur ce seul point (« `manager` ne recoit pas
  `order.cancel` »), consignee dans `docs/journal/2026-06-04--p1-merise-v0.2-rewrite-and-forgejo-migration.md`
  et dans les commentaires de `db/seeds/0001_rbac_and_reference.sql`.

## Contexte

La decision D5 avait fixe le catalogue des 23 permissions et leur repartition par role. Elle
retirait au role `manager` (libelle « Responsable ») toute permission sur les commandes, y
compris l'annulation, au nom d'une separation des pouvoirs : « celui qui gere le catalogue et le
stock ne decide pas d'annuler une vente ». L'annulation etait detenue par l'administrateur, le
comptoir et le drive.

Relue a la lumiere du fonctionnement d'un restaurant rapide, cette repartition pose deux
problemes.

- **Elle inverse l'usage de la salle.** L'annulation d'une vente est l'acte qu'on reserve au
  responsable, pas celui qu'on lui retire. C'est la parade classique contre la fraude de caisse
  la plus simple : encaisser, puis annuler la vente. Chez Wakdo, l'equipier du comptoir peut
  annuler, sous son code personnel et avec une ligne d'audit ; le responsable ne le peut pas. La
  fraude est tracee, mais pas empechee par le role qui devrait la controler.
- **Elle rend le responsable aveugle sur les commandes.** Sans aucune permission `order.*`, il
  ne voit meme pas la liste des commandes de son service.

## Decision

Le role `manager` recoit `order.read` et `order.cancel`.

- `order.read` est indispensable : sans elle, le responsable aurait le droit d'annuler sans
  pouvoir ouvrir la liste des commandes pour trouver celle a annuler.
- L'annulation reste soumise aux memes garde-fous que pour tous les roles : code personnel de
  l'equipier qui agit, verifie independamment de la session (ADR-0004), ligne d'audit dans la
  meme transaction, remise en stock si la commande etait payee.
- Le responsable n'a aucun filtre de canal : il voit et peut annuler les commandes de la borne,
  du comptoir et du drive, comme l'administrateur.
- **Le comptoir et le drive gardent leur annulation.** Cette fiche ajoute un droit, elle n'en
  retire aucun.
- Aucune permission n'est creee : le catalogue reste a 23.

## Alternatives ecartees

**Garder D5.** Defendable devant un jury comme separation des pouvoirs, mais au prix d'un role de
responsable qui ne voit pas les commandes de son propre service. Ecarte sur decision de l'auteur :
le realisme metier l'emporte.

**Donner l'annulation au responsable et la retirer au comptoir et au drive.** C'est le modele le
plus fidele a une vraie salle, ou l'equipier ne peut pas annuler seul. Ecarte pour l'instant : le
comptoir perdrait la possibilite de corriger une erreur de saisie immediate, et le modele
realiste demande davantage qu'un retrait de permission (voir ci-dessous).

**Faire autoriser l'annulation par le code personnel d'un responsable.** L'equipier lance
l'annulation, et c'est le code d'un responsable qui l'autorise. C'est la bonne cible metier, et
l'architecture s'y prete deja : le code personnel resout un acteur distinct de la session
(ADR-0004). Ecarte pour ce lot parce que c'est un changement de conception, pas une permission de
plus : il faudrait verifier la permission de l'acteur resolu par le code, et non plus celle de la
session, sur toutes les actions sensibles. Garde comme evolution nommee.

## Consequences

- (+) Le role de responsable correspond a son usage reel : il voit les commandes de son service
  et peut annuler une vente.
- La permission `order.read` accordee ici est aussi celle qui garde l'ecran de cuisine
  (`GET /kitchen/display`) et le geste « marquer prete » (`POST /admin/orders/{number}/ready`,
  et son equivalent JSON `POST /admin/api/orders/{number}/ready`) : le responsable, qui n'avait
  acces ni a l'un ni a l'autre avant cette fiche, y accede desormais aussi. Ce n'est pas une
  permission distincte ajoutee pour ce lot, mais une consequence de celle qui l'est.
- (+) Aucun garde-fou n'est affaibli : code personnel, audit, remise en stock et limitation des
  essais s'appliquent au responsable comme aux autres.
- (+) Le catalogue de permissions reste a 23.
- (-) **La separation des pouvoirs recule d'un cran.** Le responsable gere desormais catalogue,
  stock et annulation. La trace d'audit reste la garantie : chaque annulation porte le nom de
  l'equipier dont le code l'a autorisee.
- (-) **Le comptoir garde la possibilite d'annuler seul.** La fraude « encaisser puis annuler »
  reste possible pour un equipier du comptoir, tracee mais pas empechee. L'evolution nommee plus
  haut la fermerait.
- (-) **Ce qui change pour la demonstration.** Le refus « le responsable tente d'annuler, 403 »
  disparait. La demonstration de separation des pouvoirs passe par un autre refus reel, documente
  dans `docs/demo/matrice-rbac.md` et dans le plan d'oral.
- Fichiers : `db/migrations/0018_manager_order_cancel.sql` (installation en service),
  `db/seeds/0001_rbac_and_reference.sql` (installation neuve), `tests/Integration/RouteMatrixRoleDbTest.php`,
  `docs/demo/matrice-rbac.md`, `docs/demo/comptes-demo.md`, `docs/api/demo-api.md`, collections
  Postman et Bruno regenerees depuis `scripts/gen_postman.py`.
