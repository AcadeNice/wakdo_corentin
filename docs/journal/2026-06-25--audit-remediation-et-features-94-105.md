# 2026-06-25 — Synthese : CD prod, SMTP reel, durcissement borne, POS comptoir/drive, dashboard stock (#94-#105)

**Auteur : BYAN.** Retrospective de synthese couvrant douze PR mergees apres la session
du 2026-06-18 (front login + amorce P4 commande). Elles se regroupent en quatre fils :
mise en production reelle (#94-#97), finition du parcours borne client (#98, #99, #101,
#102, #103), et refonte de la saisie comptoir/drive et de la page stock cote back-office
(#100, #104, #105). Entree descriptive : on decrit ce qui est livre, pas ce qui est
promis.

---

## Ce qui a ete livre (PR mergees)

| PR | Bloc | Objet |
|----|------|-------|
| #94 | CD | Deploiement push-based vers Vision (prod) + preuve de version dans `GET /api/health` |
| #95 | CD | Modeles versionnes `docker-compose.prod.yml.example` + `.env.prod.example` |
| #96 | Auth | Envoi reel de l'email de reset via relais SMTP (Brevo) — client SMTP maison |
| #97 | CD | Passage des variables SMTP/MAIL au conteneur `wakdo-app` (correctif #96) |
| #98 | Borne | Menu Maxi agrandit la boisson en 50cl + transport du format choisi |
| #99 | Borne | Produit/menu en rupture de stock rendu non commandable (RG-T21) |
| #100 | Back-office | Refonte saisie comptoir/drive : prix, verrou du mode, navigation, file |
| #101 | Borne | Panier unique = panneau persistant (retrait de `cart.html` et `product.html`) |
| #102 | Borne | Confirmation avant l'abandon de la commande |
| #103 | Borne | Bascule des allergenes sur `/api/allergens` + menage des donnees/docs statiques |
| #104 | Back-office | Saisie comptoir/drive en POS tactile a tuiles (refonte de #100) |
| #105 | Back-office | Page Stock en tableau de bord (alertes + reapprovisionnement en avant) |

---

## Bloc 1 — Mise en production reelle (#94, #95, #96, #97)

### Ce qui a ete fait
- **CD push-based (#94)** : `.forgejo/workflows/deploy.yml` ouvre, sur push `main`, une
  session SSH vers Vision (l'hote de prod). `scripts/deploy.sh` y recupere `main` en
  fast-forward, ecrit un marqueur de version (`src/VERSION` : SHA + date), journalise une
  ligne dans `deploy.log`, puis reconstruit et recree la stack. `GET /api/health` expose
  desormais `version` et `deployed_at`, lus depuis ce marqueur : c'est la preuve cote app
  qu'un deploiement a bien repris le dernier commit. Doc : `docs/architecture/deployment.md`.
- **Modeles de prod versionnes (#95)** : `docker-compose.prod.yml.example` et
  `.env.prod.example` entrent au depot comme gabarits. Le fichier reel reste gitignore
  (specifique a l'hote : Traefik, reseau externe), conformement a ADR-0009 ; le `.example`
  documente la forme attendue.
- **SMTP reel (#96, #97)** : la reinitialisation de mot de passe envoyait jusque-la un mail
  inerte. Un client SMTP maison (`SmtpClient`, `SmtpMailer`, transport via flux PHP
  `StreamSmtpTransport` derriere l'interface `SmtpTransport`) parle a un relais reel
  (Brevo en l'occurrence). `PasswordResetController` s'y branche. #97 corrige un oubli :
  les variables SMTP/MAIL n'etaient pas transmises au conteneur `wakdo-app` (declarees
  dans les deux fichiers compose).

### Pourquoi — decisions et alternatives
- **Decision : CD par SSH, pas par Docker-in-CI.** Le runner Forgejo (sur Stark) n'a pas
  acces au socket Docker, par choix de securite : un job CI ne pilote pas Docker sur son
  hote. Le deploiement vers Vision se fait donc par SSH avec une *forced command* cote
  serveur. *Alternative ecartee* : donner le socket Docker au runner — rejetee pour la
  surface d'attaque. C'est le prolongement de la decision E2E-CI du 2026-06-18 (meme
  contrainte de socket).
- **Decision : client SMTP maison plutot qu'une bibliotheque.** ADR-0001 fige le projet
  sans Composer ni dependance tierce ; un client SMTP minimal (EHLO/AUTH/MAIL/RCPT/DATA
  sur flux) reste coherent avec cette contrainte et reste testable via un faux transport
  (`FakeSmtpTransport`). *Alternative ecartee* : `mail()` de PHP — sans relais
  authentifie, la delivrabilite est aleatoire et la configuration sort du depot.
- **Decision : marqueur de version dans `src/VERSION` lu a chaud.** Le marqueur est sous
  le mount du code (`./src` -> `/var/www/html`), donc relu sans rebuild. Cela donne une
  preuve de deploiement observable de l'exterieur sans instrumentation supplementaire.

### Criteres RNCP couverts
- **Bloc 3 - deploiement** : chaine de livraison continue tracable (`deploy.yml`,
  `deploy.sh`, `deployment.md`), preuve de version exposee.
- **Bloc 1 - securite** : separation runner/prod sans socket Docker ; secrets de prod
  hors du depot (gabarits `.example` seulement).

---

## Bloc 2 — Finition du parcours borne client (#98, #99, #101, #102, #103)

### Ce qui a ete fait
- **Menu Maxi -> boisson 50cl (#98)** : choisir le format Maxi d'un menu fait passer la
  boisson de 33cl a 50cl. La migration `0007_product_size_variant` et le seed
  `0006_drink_maxi_variant` portent la variante en base ; le format choisi est transporte
  jusqu'au paiement (`checkout.js`, `page-product-menu.js`). Tests `OrderRepository` +
  tests JS du composeur.
- **Rupture non commandable (#99, RG-T21)** : un produit (ou un menu dont un composant
  requis) sous le seuil critique de stock est marque indisponible et non ajoutable a la
  borne ; le serveur refuse aussi la creation cote `OrderRepository` (defense en
  profondeur, le client n'est pas seul juge). Visuel d'indisponibilite cote `style.css`.
- **Panier unique = panneau persistant (#101)** : suppression des pages `cart.html` et
  `product.html` ; le panier devient un panneau lateral persistant (`order-panel.js`)
  present sur les pages au lieu d'une page dediee. Le net du diff est negatif
  (-784 lignes) : c'est une simplification de l'architecture front borne.
- **Confirmation d'abandon (#102)** : abandonner la commande ouvre une modale de
  confirmation (`confirm-modal.js`) au lieu de vider le panier au premier clic.
- **Allergenes via API (#103)** : la borne lisait des fichiers JSON statiques
  (`allergens.json`, `categories.json`, `produits.json`) ; elle consomme desormais
  `/api/allergens` (et le catalogue par API). Les JSON statiques et la doc afferente sont
  retires (menage). `docs/api/conventions.md` et `docs/design/maquette-vs-build.md` mis a
  jour.

### Pourquoi — decisions et alternatives
- **Decision : rupture controlee cote serveur ET cote client (#99).** L'indisponibilite
  est calculee au plus pres de la verite (RG-T21, ADR-0003) ; le client la reflete pour
  l'UX, mais `OrderRepository` revalide a la creation. *Alternative ecartee* : masquer
  cote client seulement — laisse une fenetre ou une commande forgee passerait.
- **Decision : panier persistant plutot que page panier (#101).** Sur une borne, l'aller-
  retour vers une page panier ajoute une etape ; un panneau visible en permanence reduit
  la navigation. Le retrait de deux pages reduit aussi la surface a maintenir.
- **Decision : source de verite unique pour les donnees borne (#103).** Les JSON statiques
  dupliquaient le catalogue de la base ; ils pouvaient diverger silencieusement. Passer par
  l'API supprime la duplication et fait converger borne et back-office sur le meme modele.

### Criteres RNCP couverts
- **Bloc 2 - front client** : parcours borne complet (composeur, panier, abandon,
  indisponibilite) sur donnees reelles par API.
- **Bloc 1 - regles metier** : RG-T21 (disponibilite calculee) appliquee de bout en bout.

---

## Bloc 3 — Saisie comptoir/drive en POS tactile (#100 puis #104)

### Ce qui a ete fait
- **#100** a d'abord refondu la saisie comptoir/drive en tant que formulaire enrichi :
  prix affiches, verrou du mode de service au canal drive, navigation, file des commandes
  recentes. `CounterOrderController` derive la `source` (counter/drive) du chemin de la
  requete ; ajout de la liste des commandes recentes par canal.
- **#104** a ensuite remplace ce formulaire-liste par un **POS tactile a tuiles** facon
  borne : onglets categories en haut, grille de tuiles produits/menus, panneau commande
  persistant a droite. Pense pour la tablette (grandes cibles, un tap = ajout). Le panier
  est construit cote client (`counter-order.js`, CSP `'self'`, vanilla, zero handler
  inline) a partir d'un script JSON inerte, puis serialise dans un champ cache
  `items_json`. Le serveur revalide la forme (RG-T18), recalcule les prix (RG-T16) et
  resout les modificateurs : les prix cote client sont indicatifs.

### Pourquoi — decisions et alternatives
- **Decision : POS a tuiles, reutilisant l'UX borne (#104).** Cf. ADR-0011. L'equipier
  comptoir et le client borne font le meme geste (choisir des produits, composer) ; un
  meme paradigme tuiles+panneau reduit l'apprentissage et reutilise les patterns deja
  eprouves. *Alternative ecartee* : garder le formulaire-liste (#100) — moins adapte au
  tactile et a la rapidite attendue d'une caisse.
- **Decision : serveur seul juge des prix et de la composition.** Le client propose, le
  serveur fige (RG-T16). Coherent avec le reste du domaine commande.
- **Decision : un controleur pour deux canaux, source derivee du chemin.** Le decoupage
  par chemin (`/drive...` vs `/counter...`) plutot que par parametre rend les deux canaux
  etanches : un equipier ne peut pas requalifier sa commande en falsifiant un champ.

### Comment — points techniques cles
- `CounterOrderController` : `source()` lue depuis le chemin ; `store()` decode
  `items_json`, revalide, delegue a `createStaffOrder` (commande creee directement `paid`,
  encaissement immediat, sans PIN — la permission `order.create` suffit). Repli legacy
  `qty_<id>` accepte quand `items_json` est absent (degradation sans JS).
- `counter-order.js` : construction des onglets/grille/panneau, modale de composition pour
  produits a modificateurs et menus, serialisation a la soumission.

### Criteres RNCP couverts
- **Bloc 2 - back-office** : ecran de caisse pour equipiers non-techniques (zero jargon),
  CSP-safe sans framework front.
- **Bloc 1 - regles metier** : recalcul/revalidation serveur (RG-T16, RG-T18), etancheite
  des canaux.

---

## Bloc 4 — Page Stock en tableau de bord (#105)

### Ce qui a ete fait
La page d'accueil Ingredients/Stock, jugee trop chargee et opaque, est refondue en
tableau de bord. Elle porte desormais : un bandeau expliquant le lien stock ->
disponibilite borne (un ingredient requis sous le seuil critique rend indisponibles les
produits qui l'utilisent, RG-T21) ; un resume comptant les ingredients critiques / en
alerte / au-dessus du seuil ; une section "A reapprovisionner" mettant en avant les
ingredients bas (critiques d'abord) avec barre de niveau et bouton de reapprovisionnement
direct. La liste complete passe au second plan et le CRUD est relegue. Les sous-pages
(reappro, inventaire, mouvements, creation) restent inchangees. `index()` expose des
compteurs par etat, calcules cote serveur a partir de `stock_band` deja resolu par le
depot, pour garder la vue declarative et la valeur testable.

### Pourquoi — decisions et alternatives
Cf. ADR-0012. **Decision : dashboard oriente action plutot que liste-CRUD.** Le metier
quotidien d'un equipier stock est de voir vite ce qui manque et de reapprovisionner, pas
d'editer des fiches. Mettre les alertes et le bouton de reappro en avant aligne l'ecran
sur ce geste. *Alternative ecartee* : garder une liste exhaustive triable — exhaustive
mais muette sur l'urgence.

### Criteres RNCP couverts
- **Bloc 2 - back-office** : ergonomie orientee tache pour utilisateur non-technique.
- **Bloc 1 - regles metier** : lien stock -> disponibilite (RG-T21) rendu explicite a
  l'ecran.

---

## Verifications
A la cloture du lot : suite JS 135 tests verte (verifiee en local), suites PHPUnit et PHPStan niveau 6 vertes en CI Forgejo,
`php -l` propre. Chaque PR est passee par la CI Forgejo (checks requis) avant merge natif.

## Points d'amelioration conscients
- **#100 puis #104** : la refonte POS (#104) remplace une premiere refonte (#100) du meme
  ecran a quelques jours d'intervalle. Le formulaire enrichi de #100 a servi de palier
  avant le pivot tactile ; l'iteration est assumee plutot que masquee.
- **SMTP** : le client maison couvre le cas d'usage reset (un destinataire, texte). Il
  n'est pas un agent mail generaliste ; tout besoin plus large (pieces jointes, files)
  serait a reevaluer.

## Liens vers artefacts
- Commits : `8c5d942` (#94) -> `03ef99d` (#105).
- ADR associes : `docs/adr/0011-pos-tactile-tuiles-comptoir-drive.md`,
  `docs/adr/0012-page-stock-tableau-de-bord.md`.
- Fichiers principaux : `.forgejo/workflows/deploy.yml`, `scripts/deploy.sh`,
  `src/app/Controllers/HealthController.php`, `src/app/Auth/SmtpClient.php`,
  `src/app/Controllers/CounterOrderController.php`,
  `src/public/admin/assets/js/counter-order.js`,
  `src/app/Controllers/IngredientController.php`,
  `src/app/Views/admin/ingredients/index.php`.
