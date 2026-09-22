# Soutenance orale - Plan 40 min (oral blanc)

> Auteur : BYAN
> Projet : Wakdo - borne de commande fast-food
> Cadre : RNCP 37805 - Titre Developpeur Web - Bloc 1 (Front) + Bloc 2 (Back) + Bloc 5 (DevOps, option 3)
> Jury : mixte RNCP - Duree cible : 40 min de presentation
> Statut doc : support de preparation + aide-memoire orateur (non destine a etre projete tel quel)

---

## 0. Fiche de cadrage (a relire 5 min avant de passer)

| Element | Valeur |
|---|---|
| Titre vise | Developpeur Web - RNCP 37805 |
| Blocs | B1 Front-End + B2 Back-End (tronc commun) + B5 DevOps (option choisie) |
| Seuil de validation | 50 % minimum par bloc ET 50 % de moyenne globale |
| Referentiel | certifpro.francecompetences.fr (fiche 37805) |
| Deux FQDN publics | borne (B1) + back-office/API (B2), un seul codebase |
| Message en une phrase | "Wakdo est une borne de commande fast-food complete - front client, back-office + API, et chaine DevOps - construite from scratch sans framework pour demontrer la maitrise des trois blocs." |

### Chiffres a connaitre par coeur

| Metrique | Valeur | Sert a prouver |
|---|---|---|
| Commits Conventional | 161 | Git rigoureux (Cr 4.f) |
| Lignes PHP back | ~15 000 sur ~99 fichiers | Volume back (B2) |
| Lignes JS front | ~2 370 | Front vanilla (B1) |
| Methodes de test PHP | ~498 (43 fichiers unit + 15 integration) | TDD / qualite (Cr 4.g) |
| Fichiers de tests JS | 13 + E2E Playwright | Tests front |
| Entites Merise | 22 | Modelisation donnees (Cr 3.a) |
| Migrations SQL | 6 (idempotentes, trou 0004 documente) | Construction BDD (Cr 3.b) |
| Seeds SQL | 6 (idempotents) | Donnees de reference |
| Roles RBAC / permissions | 5 roles / 23 permissions | Securite back (Cr 4.e) |
| Regles transverses securite | 22 (RG-T01 a RG-T22) | Security-by-design |
| ADR (decisions archi) | 12 | Tracabilite des choix |
| Entrees journal de bord | 11 | Demarche projet |
| Services Docker | 5 (web, app, db, cron, migrate one-shot) | Conteneurisation (Cr 7.c) |
| Etapes CI | 5 (secret-scan, php-lint, phpstan, phpunit, js-tests) | CI/CD (Cr 7.d) |

> Regle d'or : annoncer un chiffre seulement si on en est sur. En cas de doute, dire "de l'ordre de".

---

## 1. Fil rouge de la presentation

Trois angles, un seul produit. Le jury doit repartir avec trois convictions :

1. **Parcours utilisateur (B1)** : le client compose et paie une commande sur une borne tactile fluide, pensee accessibilite.
2. **Architecture technique (B2)** : derriere l'ecran, une API REST sur un modele de donnees Merise solide, securisee by-design (le client ne peut pas tricher sur les prix ni sur les stocks).
3. **Methodo & competences (B5 + transverse)** : un seul codebase livre en une commande Docker, teste en continu (CI), trace (Git + journal + ADR + Merise), conduit en Merise Agile + TDD.

Phrase de transition recurrente : *"Ca, c'est ce que voit le client / l'equipier. Voyons maintenant ce qui se passe derriere."*

---

## 2. Plan minute par minute

| Plage | Section | Bloc | Support visuel |
|---|---|---|---|
| 00:00 - 02:00 | Ouverture + projet en une phrase | - | Slide titre |
| 02:00 - 05:00 | Contexte metier & perimetre | - | Slide scope (3 canaux, in/out) |
| 05:00 - 08:00 | Demarche & methodologie | transverse | Slide Merise Agile + TDD + Git |
| 08:00 - 18:00 | Parcours utilisateur borne (DEMO) | B1 | Demo live + captures de secours |
| 18:00 - 22:00 | Sous le capot du front | B1 | Slide front vanilla + schema fetch |
| 22:00 - 31:00 | Architecture back : Merise + API + securite | B2 | MCD + sequence commande + RG-T |
| 31:00 - 36:00 | DevOps : Docker + CI/CD | B5 | Schema conteneurs + pipeline CI |
| 36:00 - 39:00 | Bilan competences + difficultes + perspectives | - | Slide mapping blocs |
| 39:00 - 40:00 | Conclusion + ouverture Q&A | - | Slide merci |

> Marge : viser 38 min de parole pour absorber les imprevus de demo. Si retard, compresser la section "Sous le capot du front" (18:00-22:00) qui est la plus sacrifiable.

---

## 3. Detail des sections (contenu + notes orateur + criteres)

### Section A - Ouverture (00:00 - 02:00)

**A dire :**
- Qui je suis, le titre vise, les 3 blocs.
- Le projet en une phrase (cf. fiche de cadrage).
- Le plan en 3 temps (parcours / archi / methodo).

**Note orateur :** poser le cadre tot pour que le jury sache quel bloc est demontre a chaque instant. Annoncer qu'une demo live aura lieu.

---

### Section B - Contexte metier & perimetre (02:00 - 05:00)

**A dire :**
- Wakdo = borne de commande pour un fast-food (pastiche McDonald's). Tous les modes de service (sur place / a emporter / drive) sont en emballages papier sur plateau ou en sac ; la distinction sur place / a emporter est surtout fiscale (TVA).
- Trois canaux de prise de commande : `kiosk` (borne client autonome), `counter` (comptoir, equipier), `drive`.
- Perimetre IN : catalogue, composition de menus, panier, paiement simule (numero de commande), back-office de gestion, file cuisine (KDS), stock.
- Perimetre OUT (assume et argumente) : paiement bancaire reel, compte fidelite client, multi-langue, mode hors-ligne, upload d'images produits, monitoring type Prometheus, Kubernetes.

**Note orateur :** afficher OUT autant que IN. Un perimetre maitrise et justifie est un signe de maturite ; le jury valorise "j'ai choisi de ne pas faire X parce que...".

**Criteres servis :** cadrage projet, analyse du besoin (Cr 3.a amont).

---

### Section C - Demarche & methodologie (05:00 - 08:00)

**A dire :**
- **Merise Agile** : modele de donnees pose tot (data dictionary first), puis enrichi sprint par sprint. Source de verite unique : `docs/PROJECT_CONTEXT.md` (19 sections) + le dossier `docs/merise/` (dictionnaire, MCD, MLD, MLT).
- **TDD** : tests avant code sur les chemins critiques (commande, stock, securite). ~498 methodes de test PHP + tests JS + E2E.
- **Git discipline** : Conventional Commits, branches `feat/*`, PR vers `dev`, hooks protegeant `main`/`dev`, 161 commits.
- **Tracabilite** : 12 ADR (decisions d'architecture datees et justifiees) + 11 entrees de journal de bord.
- **Transparence IA** : le projet est conduit avec une methodologie outillee (agents BYAN) ; les decisions d'architecture restent les miennes, documentees en ADR. La transparence est portee dans la doc, pas masquee.

**Note orateur :** c'est ici qu'on plante la credibilite "ingenieur". Montrer un ADR a l'ecran (ex. ADR 0001 "PHP from scratch sans Composer") rend la demarche concrete.

**Criteres servis :** Cr 4.f (Git), Cr 4.a (conceptualisation), Cr 3.a (analyse/modele).

---

### Section D - Parcours utilisateur borne / DEMO (08:00 - 18:00)

> C'est le coeur de la demonstration Bloc 1. Privilegier la demo live ; garder les 10 captures (`docs/design/screens/`) comme filet de securite.

**Scenario de demo (a derouler ecran par ecran) :**
1. **Accueil** (`index.html`) - ecran d'attente, appel a l'action "Commander".
2. **Categories** (`categories.html`) - navigation tactile par categorie.
3. **Produits / menus** (`products.html`) - grille de tuiles ; taper un menu.
4. **Composition de menu** - modales successives : taille (menu / Maxi), accompagnement (frite / potatoes ; la variante Maxi associe automatiquement la grande), boisson (format 30/40/50 cl).
5. **Panier** - panneau persistant, stepper de quantite, total mis a jour.
6. **Paiement** (`payment.html`) - choix du mode de service + numero de chevalet.
7. **Confirmation** (`confirmation.html`) - numero de commande affiche, reset automatique au bout de ~15 s.

**Points a verbaliser pendant la demo :**
- Accessibilite (Cr 1.c) : police adaptee (dyslexie), cibles tactiles larges, contrastes, navigation au clavier, information non portee par la seule couleur.
- HTML semantique + meta/SEO (Cr 1.e) : balises `<main>`, `<nav>`, `<article>`, donnees structurees.
- Le panier vit en `localStorage` : aucune requete reseau tant que le client n'a pas paye (point d'architecture a souligner - voir Section E).

**Note orateur :** repeter le geste sur la borne reelle si possible (tactile = effet "produit fini"). Si la demo plante : basculer sur les captures sans s'excuser longuement, commenter chaque ecran.

**Criteres servis :** Cr 1.a (integration conforme maquette), Cr 1.c (accessibilite), Cr 1.e (SEO/semantique), Cr 2.a (JS ES6+), Cr 2.b (validation formulaire), Cr 2.c (Ajax async).

---

### Section E - Sous le capot du front (18:00 - 22:00)

**A dire :**
- Front 100 % vanilla : HTML5 + CSS3 + JavaScript ES6+ modulaire, **zero dependance** (ni jQuery, ni framework, ni bundler). Choix assume a argumenter : maitrise des fondamentaux et chargement leger (le critere Cr 2.d "librairies externes" reste evaluable - je l'argumente, ce n'est pas une dispense).
- Organisation des modules JS (~2 370 lignes) : `data.js` (recuperation catalogue), `state.js` (panier localStorage), `page-products.js` / `product-options.js` / `page-product-menu.js` (UI + composition), `order-panel.js` (panier), `page-payment.js` + `checkout.js` (paiement), `page-confirmation.js`.
- Flux reseau : le front consomme l'API via `fetch` (`GET /api/categories`, `/api/products`, `/api/menus`, `/api/allergens`). Le panier ne declenche aucun appel ; le paiement envoie le payload de commande.

**Note orateur :** montrer un module JS court a l'ecran (ex. `state.js`) pour prouver le code reel. Insister : "le front ne se fie pas a lui-meme pour le prix - c'est le serveur qui recalcule" (transition vers B2).

**Criteres servis :** Cr 2.a, Cr 2.c, Cr 2.d (argumente).

---

### Section F - Architecture back : Merise + API + securite (22:00 - 31:00)

> Coeur Bloc 2. Trois temps : le modele, l'API, la securite.

**F.1 - Modele de donnees Merise (22:00 - 25:00)**
- 22 entites organisees en domaines : Catalogue (category, product, menu, menu_slot, menu_slot_option), Ingredients & Stock (ingredient, product_ingredient, allergen, ingredient_allergen, stock_movement), Order (customer_order, order_item, order_item_selection, order_item_modifier), RBAC (user, role, permission, role_permission, role_visible_source), Security-by-design (audit_log, login_throttle, pin_throttle).
- Construction BDD : 6 migrations versionnees + 6 seeds, toutes idempotentes, suivi par une table de migrations. Le "trou" 0004 est documente (decision tracee, pas un oubli).
- Montrer le MCD (diagramme `docs/merise/_diagrams/mcd-order.mmd` rendu).

**F.2 - API REST (25:00 - 27:00)**
- Routeur maison (`src/public/admin/index.php`) : methode + chemin -> [Controller, action].
- Endpoints publics borne : `GET /api/categories|products|menus|allergens`.
- Endpoints commande : `POST /api/orders` (creation), `POST /api/orders/{n}/pay` (encaissement), `GET /api/orders/{n}` (consultation).
- Enveloppe de reponse standard `{ data: ... }` / `{ data: null, error: ... }` ; codes HTTP coherents (201 creation, 200 ok, 409 conflit, 422 validation, 403 permission/CSRF, 404).
- Demo possible : `curl http://admin.localhost:8080/api/products` pour prouver que l'API tourne sans le front (utile pour un jury Bloc 2 autonome).

**F.3 - Securite by-design (27:00 - 31:00)** - le moment fort pour un jury technique
- **Revalidation serveur (RG-T16/RG-T18)** : le payload ne transporte que `product_id` + `quantity`. Le serveur recalcule le prix depuis la base ; un client ne peut pas imposer un prix.
- **Snapshots (RG-T05)** : a la commande, le libelle, le prix unitaire et le taux de TVA sont figes dans `order_item` ; l'historique reste fidele meme si le catalogue change ensuite.
- **Idempotence (RG-T19)** : `idempotency_key` UNIQUE en base -> anti double-facturation si le client retape "Payer".
- **Stock atomique (RG-T20)** : le decrement se fait dans la meme transaction que la transition de statut, via un `UPDATE ... stock_quantity - :q` auto-verrouillant (pas de lecture-puis-ecriture, donc pas de course critique).
- **SQLi (RG-T06)** : requetes preparees PDO de bout en bout.
- **XSS (RG-T15)** : echappement en sortie cote admin, `textContent` cote JS.
- **RBAC permission-driven (RG-T03)** : on teste une permission, pas un nom de role ; 5 roles, 23 permissions rechargees a chaque requete.
- **PIN d'action sensible (RG-T13) + throttle dedie (RG-T22)** : les actions sensibles (suppression, changement de prix, RBAC, inventaire) demandent une re-autorisation par PIN, avec un backoff anti-brute-force separe de celui du login.
- **Audit immuable (RG-T14)** : journal append-only ecrit dans la meme transaction que l'action.
- **Machine a etats commande** : 4 etats seulement - `pending_payment` -> `paid` -> `delivered`, plus `cancelled`. Simplicite et immuabilite plutot qu'un cycle preparing/ready non necessaire pour ce perimetre.

**Note orateur :** c'est la section qui distingue un projet d'ecole d'un projet pense production. Choisir 3-4 regles RG-T a expliquer a fond plutot que de reciter les 22. La revalidation prix + l'idempotence + le stock atomique forment un trio percutant.

**Criteres servis :** Cr 3.a (modele), Cr 3.b (construction BDD), Cr 3.c (SQL), Cr 3.d (RGPD via argon2id + anonymisation), Cr 4.c (POO/heritage des controleurs), Cr 4.d (MVC), Cr 4.e (securite).

---

### Section G - DevOps : Docker + CI/CD (31:00 - 36:00)

**A dire :**
- **Conteneurisation (Cr 7.c)** : 5 services orchestres - `wakdo-web` (Apache 2.4), `wakdo-app` (PHP-FPM 8.3), `wakdo-db` (MariaDB 11.4), `wakdo-cron` (dcron), `wakdo-migrate` (one-shot migrations + seeds).
- **Une commande (Cr 7.c.4)** : `docker compose up -d` lance la stack, applique les migrations et les seeds (idempotents) via le service one-shot, puis demarre l'app. Reseaux segmentes : la base n'est pas exposee, seul le web est public.
- **Scripts bash (Cr 7.b)** : `db/migrate.sh`, `db/seed.sh`, entrypoints, `set -euo pipefail`.
- **Crons (Cr 7.b.3)** : sauvegarde BDD + purges RGPD (audit, throttle) dans une fenetre de maintenance nocturne.
- **CI/CD (Cr 7.d)** : pipeline Forgejo Actions en 5 etapes - scan de secrets (gitleaks), lint PHP, PHPStan niveau 6, PHPUnit (avec MariaDB ephemere migree), tests JS. Auto-merge en squash quand les checks requis passent au vert.

**Note orateur :** si reseau dispo, faire `docker compose up -d` puis ouvrir la borne ; sinon montrer le `docker-compose.yml` (court) et le fichier CI. L'argument fort : "la meme commande qui marche sur ma machine est celle qui deploie."

**Criteres servis :** Cr 7.a (analyse infra/securite), Cr 7.b (scripts + cron), Cr 7.c (conteneurisation + une commande), Cr 7.d (archi serveur, tests avant deploy, CI/CD).

---

### Section H - Bilan competences + difficultes + perspectives (36:00 - 39:00)

**A dire :**
- Mapping rapide : ce que la presentation vient de demontrer, bloc par bloc (cf. tableau Section 4).
- **Difficultes rencontrees** (preparer 2 exemples concrets et honnetes) :
  - ex. la course critique sur le stock -> resolue par le decrement atomique en une instruction SQL.
  - ex. la double-facturation possible sur le flux create+pay -> resolue par l'idempotency_key.
- **Apprentissages** : la valeur d'un modele de donnees pose tot ; la securite pensee des la conception coute moins cher qu'ajoutee apres.
- **Perspectives** : paiement reel, monitoring, finalisation du CD automatique, completion du batch P3 (categories dynamiques, allergenes par produit, gate de couverture CI).

**Note orateur :** assumer ce qui n'est pas fini. Un candidat qui connait ses dettes techniques rassure plus qu'un candidat qui pretend que tout est acheve.

---

### Section I - Conclusion + Q&A (39:00 - 40:00)

**A dire :**
- Reprendre les 3 convictions du fil rouge en une phrase chacune.
- "Je reste a votre disposition pour des questions ou une demonstration plus approfondie de n'importe quelle partie."

---

## 4. Mapping competences -> moment de la soutenance (pour le jury)

| Bloc | Critere (exemples) | Demontre en section | Preuve montrable |
|---|---|---|---|
| B1 | Cr 1.a integration maquette | D | Demo + maquette PDF |
| B1 | Cr 1.c accessibilite | D | Demo (police, contraste, clavier) |
| B1 | Cr 1.e SEO/semantique | D, E | Source HTML |
| B1 | Cr 2.a JS ES6+ vanilla | D, E | Modules JS |
| B1 | Cr 2.c Ajax async | E | `data.js`, `checkout.js` |
| B2 | Cr 3.a analyse/modele | C, F.1 | Dictionnaire + MCD |
| B2 | Cr 3.b construction BDD | F.1 | Migrations + seeds |
| B2 | Cr 3.c SQL | F.2, F.3 | Repositories PDO |
| B2 | Cr 3.d RGPD | F.3 | argon2id + anonymisation |
| B2 | Cr 4.c POO/heritage | F.2 | Hierarchie controleurs |
| B2 | Cr 4.d MVC | F.2 | Controllers / Repository / Views |
| B2 | Cr 4.e securite | F.3 | RG-T01 a RG-T22 |
| B2 | Cr 4.f Git | C | 161 commits, PR, hooks |
| B2 | Cr 4.g livraison | C, G | Tests verts, deploye |
| B5 | Cr 7.b scripts/cron | G | `db/*.sh`, crontab |
| B5 | Cr 7.c conteneurisation | G | 5 services, une commande |
| B5 | Cr 7.d CI/CD | G | Pipeline Forgejo 5 etapes |

---

## 5. Questions de jury anticipees (preparer les reponses)

**Sur les choix techniques**
- *Pourquoi pas de framework (Symfony/Laravel) ?* -> Le sujet Bloc 2 impose le "from scratch" ; cela force la maitrise des fondamentaux (routage, MVC, PDO, securite). ADR 0001 le trace.
- *Pourquoi pas de framework JS (React/Vue) ?* -> Choix assume ; la borne a peu d'ecrans, le vanilla suffit et reste leger. Le critere "librairies" est argumente, pas evite.
- *Pourquoi PHP et pas Node ?* -> Adequation au referentiel et a la stack visee ; PHP 8.3 moderne (typage, enums).

**Sur la securite**
- *Comment empechez-vous qu'un client modifie le prix ?* -> Le serveur recalcule depuis la base (RG-T16) ; le payload ne contient que l'id produit et la quantite.
- *Que se passe-t-il si le client double-clique sur Payer ?* -> `idempotency_key` UNIQUE : la 2e requete renvoie la commande existante, pas une nouvelle.
- *Et la concurrence sur le stock ?* -> Decrement atomique en une instruction SQL gardee, dans la transaction de paiement (RG-T20).
- *Mots de passe ?* -> argon2id ; les hash et PIN sont classes RESTRICTED, hors logs et hors API.

**Sur les donnees / RGPD**
- *Et la suppression d'un utilisateur ?* -> Anonymisation (tombstone) plutot que suppression dure pour preserver l'integrite des commandes (ADR 0007).

**Sur le DevOps**
- *Comment deployez-vous ?* -> CI Forgejo (5 etapes) sur PR ; auto-merge au vert ; conteneurs ; CD scripte (pull-based) en cours de finalisation.
- *Vos tests tournent ou ?* -> En CI sur une base MariaDB ephemere migree, a chaque PR.

**Sur la honnetete du perimetre**
- *Le paiement est-il reel ?* -> Non, simule par un numero de commande ; c'est hors perimetre assume, le reste du flux (revalidation, stock, idempotence) est reel.
- *Qu'est-ce qui n'est pas fini ?* -> categories dynamiques cote borne, allergenes par produit, gate de couverture CI, CD automatique. Trace dans le backlog.

---

## 6. Checklist pre-vol (la veille + le jour J)

**La veille**
- [ ] `docker compose down -v && docker compose up -d` puis verifier borne + admin + `curl /api/products`.
- [ ] Verifier les identifiants de demo admin.
- [ ] Exporter / imprimer les 10 captures de secours (`docs/design/screens/`).
- [ ] Rendre les diagrammes Merise (`.mmd` -> PNG) pour les slides.
- [ ] Relire les 12 ADR (titres) pour repondre vite.
- [ ] Repeter la demo en 10 min chrono.

**Le jour J**
- [ ] Tester le branchement ecran/projecteur tot.
- [ ] Ouvrir a l'avance : borne, admin (connecte), terminal avec la commande curl prete, MCD a l'ecran.
- [ ] Avoir les slides ET les captures en local (pas de dependance reseau).
- [ ] Bouteille d'eau, montre/chrono visible.

**Plan B demo**
- Si la stack ne demarre pas : derouler le parcours sur les 10 captures, commenter chaque ecran, puis montrer le code (modules JS, repository PDO, RG-T dans `mlt.md`).

---

## 7. Fichiers sources (pour preparer les slides et repondre)

| Besoin | Fichier |
|---|---|
| Source unique projet | `docs/PROJECT_CONTEXT.md` |
| Parcours borne detaille | `docs/architecture/flux-borne-selection-vers-commande.md` |
| Architecture / topologie | `docs/ARCHITECTURE.md` |
| Securite | `docs/SECURITY.md` |
| Modele de donnees | `docs/merise/dictionary.md`, `docs/merise/mcd.md`, `docs/merise/mld.md` |
| Regles transverses (RG-T) | `docs/merise/mlt.md` |
| Diagrammes | `docs/merise/_diagrams/*.mmd` |
| Decisions d'architecture | `docs/adr/0001..0012` |
| Journal de bord | `docs/journal/` |
| Captures borne | `docs/design/screens/` + `docs/design/maquette-borne.pdf` |
| CI | `.forgejo/workflows/ci.yml` |
| Conteneurs | `docker-compose.yml`, `docker/*/Dockerfile` |

---

## 8. Questions de defense - concepts & code (le jury ouvre les fichiers)

> Le jury peut ouvrir un fichier au hasard et demander "expliquez-moi ca". Ces reponses
> sont ancrees sur le vrai code. Reperer chaque chemin sur ma machine AVANT l'oral.

### 8.1 "C'est quoi le MVC ? Montrez-le dans votre projet."

Le MVC separe trois responsabilites. Dans Wakdo :
- **Model (les donnees + l'acces aux donnees)** : les classes Repository - par exemple `src/app/Catalogue/CategoryRepository.php`, `src/app/Order/OrderRepository.php`. Elles parlent a la base via PDO et ne contiennent pas de HTML.
- **View (l'affichage)** : les templates PHP sous `src/app/Views/` - par exemple `src/app/Views/admin/categories/index.php` et le gabarit commun `src/app/Views/admin/layout.php`. Cote API (borne), la "vue" est le JSON renvoye.
- **Controller (le chef d'orchestre)** : les classes sous `src/app/Controllers/` - par exemple `CategoryController`. Il recoit la requete, appelle le bon Repository, choisit la vue, renvoie la reponse.

Phrase a retenir : *"Le controleur ne sait pas parler SQL (c'est le repository) et ne sait pas dessiner du HTML (c'est la vue) ; il coordonne."*

### 8.2 "Ou sont vos controleurs ?"

Dossier `src/app/Controllers/` - 22 classes. Exemples a citer : `CategoryController`, `ProductController`, `OrderController`, `KitchenController`, `CounterOrderController`.
Ils heritent d'une base commune : `src/app/Core/Controller.php` (classe abstraite) -> `src/app/Controllers/AdminController.php` (back-office) -> controleurs concrets. C'est l'heritage POO (Cr 4.c) : le code commun (rendu de vue, garde de permission, flash) vit dans les classes parentes.

### 8.3 "Comment une requete traverse l'application ?" (le trajet complet)

1. **Point d'entree (front controller)** : `src/public/admin/index.php`. Il cree le routeur et declare les routes :
   `$router->add('GET', '/api/products', [CatalogueController::class, 'products']);`
2. **Routeur** : `src/app/Core/Router.php`. Il compile chaque chemin en expression reguliere, compare la methode + le chemin de la requete, et distingue 404 (aucun chemin) de 405 (chemin trouve, mauvaise methode).
3. **Instanciation** : le routeur fait `new ControllerClass(...)` puis appelle l'action (ex. `->products()`), en passant les parametres d'URL (ex. `{id}`).
4. **Controleur** : verifie la permission (`guard()`), valide le CSRF + les entrees en ecriture, appelle le Repository.
5. **Repository (Model)** : requete PDO preparee -> MariaDB.
6. **Reponse** : vue HTML (admin) ou JSON (`{ data: ... }`) avec le bon code HTTP.

Schema mental a dire a voix haute : *"index.php -> Router -> Controller -> Repository -> base, puis retour en vue ou JSON."*

### 8.4 "C'est quoi une route ?"

Une association entre (methode HTTP + chemin d'URL) et (un controleur + une action). Toutes declarees dans `src/public/admin/index.php`. Exemple :
`$router->add('POST', '/api/orders', [OrderController::class, 'create']);`
Les segments dynamiques s'ecrivent `{number}` (ex. `/api/orders/{number}/pay`) et arrivent dans l'action comme parametre.

### 8.5 "Comment evitez-vous les injections SQL ?"

Requetes preparees PDO partout (RG-T06). On ne concatene pas les valeurs dans le SQL : on met des marqueurs (`:id`) et on lie les valeurs separement. La couche d'acces a la base est `src/app/Core/Database.php`. A montrer : n'importe quel Repository (ex. `CategoryRepository`).

### 8.6 "Comment gerez-vous les droits ?"

RBAC permission-driven (RG-T03). Chaque action sensible commence par `$guard = $this->guard('category.manage');` (visible en haut de chaque methode de `CategoryController`). On teste une **permission**, pas un nom de role. Les permissions sont liees aux roles en base (`role_permission`) et rechargees a chaque requete. 5 roles, 23 permissions.

### 8.7 "C'est quoi le CSRF et ou le gerez-vous ?"

Une protection contre les requetes forgees : chaque formulaire du back-office porte un jeton `_csrf`, revalide cote serveur avant toute ecriture. Dans le code : `Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)` au debut des actions `store`/`update`/`toggle` de `CategoryController` (classe `src/app/Auth/Csrf.php`).

### 8.8 "Pourquoi des `_snapshot` dans `order_item` ?"

Pour figer le libelle, le prix et la TVA au moment de la commande. Si le prix du catalogue change demain, l'historique de la commande d'hier reste fidele (RG-T05). C'est de l'immuabilite des donnees comptables.

---

## 9. Questions de defense - modifications en direct

> Le jury peut demander "ajoutez-moi X" pour verifier que je sais naviguer dans MON code.
> Pour chaque cas : les fichiers a toucher, dans l'ordre. A repeter en amont au moins une fois.

### 9.1 "Ajoutez un champ a une entite" (ex. un champ `description` sur `category`)

Chemin complet (du bas vers le haut) :
1. **Base** : nouvelle migration `db/migrations/0008_category_description.sql` -> `ALTER TABLE category ADD COLUMN description VARCHAR(255) NULL;` (garde idempotente avec `information_schema`).
2. **Model** : dans `src/app/Catalogue/CategoryRepository.php`, ajouter la colonne aux requetes `create`/`update`/`all`/`find` (et a l'allowlist de colonnes, RG-T16).
3. **Controleur** : dans `CategoryController::validate()`, valider le nouveau champ (longueur), et l'ajouter au tableau `$data`.
4. **Vue** : dans `src/app/Views/admin/categories/form.php`, ajouter le champ de formulaire ; dans `index.php`, l'afficher.
5. **Test** : ajouter une assertion dans le test du repository/controleur.

A dire : *"Je remonte la pile : migration, repository, controleur, vue, test."*

### 9.2 "Ajoutez un endpoint / une route" (ex. `GET /api/categories/{id}`)

1. **Route** : dans `src/public/admin/index.php` -> `$router->add('GET', '/api/categories/{id}', [CatalogueController::class, 'category']);`
2. **Action** : ajouter la methode `category(array $params)` dans `src/app/Controllers/CatalogueController.php`, qui lit `$params['id']`, appelle le repository, renvoie `$this->json([...])`.
3. **Test** : test d'integration sur la nouvelle route.

### 9.3 "Changez une regle de validation" (ex. libelle max 80 au lieu de 60)

Un seul endroit : `CategoryController::validate()`, la condition `mb_strlen($name) > 60`. Montrer que la validation serveur est centralisee (RG-T18) - le client peut mentir, le serveur tranche.

### 9.4 "Ajoutez une categorie / un produit"

Via le back-office en live : `/admin/categories` -> Nouvelle -> remplir -> le formulaire POST passe par `CategoryController::store()` (CSRF + validation + `repo->create()`), puis redirection avec message flash. Montrer le parcours a l'ecran, pas seulement le code.

### 9.5 Si je bloque sur une modif

Etre honnete et methodique plutot que paniquer : *"Je commence par localiser le fichier concerne - ici ce serait le controleur X - puis je suis la pile model/vue. Laissez-moi ouvrir le fichier."* Le jury evalue la demarche de navigation autant que la rapidite.

---

## 10. Justifier l'usage de l'IA (section sensible - a maitriser a fond)

> Base documentaire : `docs/PROJECT_CONTEXT.md` section 17 (transparence methodologie et usage IA).
> Principe : l'usage de l'IA est autorise et documente. La regle est de ne presenter
> comme acquis QUE ce que je peux demontrer a froid (expliquer, naviguer, modifier).

### 10.1 Le cadre (a connaitre)

- **Autorisation** : le centre Acadenice autorise explicitement l'usage d'assistants IA pour le projet (PROJECT_CONTEXT 17.1).
- **Tracabilite** : la methodologie est versionnee et visible (`.claude/CLAUDE.md`, `.claude/rules/`, journal, ADR).
- **Repartition** : l'IA redige/code/propose/relit ; l'auteur decide (architecture, scope, securite, modelisation Merise) - PROJECT_CONTEXT 17.3 et 17.4.

### 10.2 Le message central

*"J'ai utilise l'IA comme un pair-programmeur et un assistant de redaction, dans un cadre autorise et trace. Je n'ai pas sous-traite mon jugement : les decisions d'architecture, de perimetre et de securite sont les miennes, documentees en ADR. Et je suis en mesure d'expliquer et de modifier n'importe quelle partie du code - je peux vous le montrer maintenant."*

La derniere phrase est la cle : elle transforme une question piege en opportunite de demonstration.

### 10.3 Questions probables et reponses

- *"Avez-vous utilise de l'IA pour ce projet ?"*
  -> Oui, et c'est documente (section 17 du dossier). Voici comment : l'IA m'a assiste pour rediger, coder et relire ; j'ai dirige la conception et valide chaque livrable. C'est la pratique d'un developpeur aujourd'hui.

- *"Avez-vous vraiment ecrit ce code vous-meme ?"*
  -> Honnete : le code a ete produit avec assistance IA, sous ma direction et ma validation. Ce qui compte pour vous, c'est que je le comprenne et que je sache le faire evoluer. Ouvrons un fichier, je vous explique.

- *"Si je vous enleve l'IA, savez-vous coder ?"*
  -> Oui. Demandez-moi d'expliquer ou de modifier une partie. (Puis le faire - cf. sections 8 et 9.)

- *"Comment savez-vous que le code de l'IA est correct ?"*
  -> Trois garde-fous : je lis et teste avant de committer ; un protocole de fact-check oblige a sourcer les affirmations techniques ; et la CI (lint, PHPStan niveau 6, ~498 tests PHP) verifie a chaque PR.

- *"Qui a pris la decision de ne pas utiliser de framework ?"*
  -> Moi - c'est trace dans l'ADR 0001. L'IA a mis en oeuvre ; le choix et sa justification (sujet "from scratch", maitrise des fondamentaux) sont les miens.

- *"L'IA a-t-elle decide de l'architecture de la base ?"*
  -> Non. Le modele Merise part du dictionnaire de donnees et des user stories ; chaque cardinalite et chaque transition de statut est validee par moi (PROJECT_CONTEXT 17.4). L'IA a formalise les diagrammes a partir de mes arbitrages.

- *"Et si vous n'avez plus de tokens / plus acces a l'IA, comment faites-vous ?"* (question piege sur la dependance)
  -> L'IA est un accelerateur, pas ma competence. Sans elle je suis plus lent, pas incapable. Je m'appuie sur ce qu'utilisait un developpeur avant l'IA : la documentation officielle (php.net, MDN), la lecture d'une stack trace, le debug pas a pas, et la documentation de mon propre projet (ADR, Merise, journal). Les patterns du projet sont en tete parce que je l'ai construit : ajouter une route, ecrire une requete PDO preparee, suivre la pile controleur -> repository -> vue, je le fais a la main. On peut le verifier maintenant si vous voulez.
  - Analogie : *"l'IA, c'est comme l'autocompletion de l'editeur ou une calculatrice pour un ingenieur - me l'enlever me ralentit, ca ne me rend pas incompetent. La vitesse de frappe n'est pas le coeur du metier ; comprendre le probleme, le modele de donnees et la securite, si."*
  - Renfort : le projet est documente (12 ADR), teste (CI + ~498 tests rattrapent une regression introduite a la main) et conventionne - donc reprenable sans assistance.
  - A ne pas dire : *"je n'ai pas besoin de l'IA"* (arrogant et invalidable). Dire : *"plus efficace avec, comme tout le monde aujourd'hui, mais la comprehension est a moi."*

### 10.4 A ne pas faire

- Ne pas pretendre avoir tape chaque caractere a la main - c'est invalidable en une question.
- Ne pas minimiser l'IA au point de paraitre malhonnete ("juste un peu d'autocompletion") si l'usage a ete plus large.
- Ne pas affirmer une maitrise que je ne peux pas demontrer dans la minute. Le contrat de la section 17.8 du dossier, c'est : *je peux expliquer et modifier en direct*. Cette capacite se prepare (sections 8 et 9).

### 10.5 Le reflexe gagnant

Face a toute question sur l'IA, repondre par la **demonstration** : *"Laissez-moi vous montrer."* Un candidat qui ouvre son code, explique un chemin et fait une petite modif devant le jury a deja gagne le debat sur l'authenticite - quel que soit l'outil utilise pour ecrire les lignes.
