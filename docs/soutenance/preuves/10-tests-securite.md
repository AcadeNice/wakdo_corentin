# Preuve 10 — Tests de securite executables (borne + back-office)

Titre professionnel RNCP 37805 — Bloc 2 (back-office, API), axe securite.
Perimetre : les deux surfaces de Wakdo, telles qu'elles tournent en production —
la borne (site statique + API publique `/api/*`, hote kiosk) et le back-office (pages HTML
`/admin/*`, API JSON `/admin/api/*`, hote admin). Etat au 2026-09-29, code du commit
`fddc26c` (branche `dev`, fusionne dans `main` par `dc1829d`, celui que sert la production).

**Ce que cette preuve apporte.** Les protections du projet etaient decrites (ADR, modele de
menaces, `SECURITY.md`) et verifiees en grande partie par des tests unitaires et par la
capture route par route de la page Sante. Il manquait une suite qui attaque l'application
**de l'exterieur**, comme le ferait un client malveillant, sur une pile complete (Apache +
PHP-FPM + MariaDB) et dans un vrai navigateur, et qui dise pour chaque protection : prouvee,
ou refutee. Cette suite existe maintenant : 100 tests Playwright et 5 tests PHP contre une
base reelle. Elle a trouve **13 ecarts**, laisses visibles (tests marques, section 6) ;
aucun correctif applicatif n'a ete fait dans ce lot.

## 1. La base existante (reprise, pas dupliquee)

| Preuve deja en place | Ce qu'elle couvre |
|---|---|
| `src/app/Health/captured-responses.json` (158 routes) + `tests/Unit/Health/CapturedResponsesTest.php`, `RouteSecurityCoverageTest.php`, `RouteMatrixTest.php` | pour chaque route, la vraie reponse sans session, sans permission, sans jeton CSRF, avec un corps mal type, avec un PIN faux ; une route ajoutee sans sa ligne de securite fait echouer la CI |
| `tests/Unit/Admin/HtmlRouteCsrfTest.php`, `HtmlRouteSessionTest.php`, `HtmlRoutePermissionTest.php`, `HtmlRoutePinTest.php`, `tests/Unit/Auth/CsrfTest.php` | gardes CSRF / session / permission / PIN de chaque page HTML, en unitaire |
| `tests/Integration/RouteMatrixRoleDbTest.php`, `tests/e2e/rbac-demo.spec.js`, `tests/e2e/rbac-channel.spec.js` | grille role x permission contre une vraie base ; refus 403 par compte de demonstration ; cloisonnement des pages comptoir / drive |
| `tests/Unit/Auth/ThrottlePolicyTest.php`, `PinThrottleTest.php`, `tests/Integration/AuthServiceDbTest.php`, `PinThrottleDbTest.php` | courbe de blocage (seuil, delai, plafond) de la connexion et du PIN |
| `tests/Unit/Auth/PasswordResetServiceTest.php`, `PasswordResetControllerTest.php` | reinitialisation neutre, jeton hache, usage unique (base simulee) |
| `tests/Unit/Core/ImageUploaderTest.php` | envoi d'image : type lu dans le contenu, nom regenere, traversee et octet nul ignores |
| `tests/Unit/Core/CorsTest.php` | politique CORS du middleware |
| `tests/e2e/admin-error-pages.spec.js`, `tests/Unit/Core/ErrorResponseTest.php`, `ErrorDisplayTest.php` | pages d'erreur lisibles, 500 generique hors mode debogage |

## 2. Ce qui est ajoute

| Fichier | Tests | Ce qu'il attaque |
|---|---|---|
| `tests/e2e/security-headers.spec.js` | 15 | en-tetes HTTP des deux hotes, par type de reponse |
| `tests/e2e/security-session.spec.js` | 9 | cookie de session, fixation, deconnexion, session sur l'hote borne |
| `tests/e2e/security-injection.spec.js` | 5 | injection SQL : chemin, requete, corps, connexion, API d'administration |
| `tests/e2e/security-xss.spec.js` | 5 | XSS stockee (borne, back-office, ecran cuisine) et reflechie |
| `tests/e2e/security-csrf.spec.js` | 6 | jeton d'une autre session, rotation a la connexion, connexion JSON |
| `tests/e2e/security-access.spec.js` | 11 | controle d'acces horizontal et vertical, comptes et roles desactives, methodes |
| `tests/e2e/security-bruteforce.spec.js` | 6 | verrou par compte, par IP, PIN, enumeration, mot de passe oublie |
| `tests/e2e/security-reset.spec.js` | 4 | lien de reinitialisation reel (relu dans le journal), usage unique |
| `tests/e2e/security-upload.spec.js` | 8 | envoi d'images piegees de bout en bout, execution du fichier depose |
| `tests/e2e/security-mass-assignment.spec.js` | 7 | champs non prevus dans les corps (RG-T16) |
| `tests/e2e/security-info-leak.spec.js` | 7 | pages d'erreur, 500 en mode production, fichiers sensibles, listing, sonde |
| `tests/e2e/security-borne.spec.js` | 6 | isolement du back-office, secrets dans le JS, stockage navigateur, CORS |
| `tests/e2e/security-order-integrity.spec.js` | 7 | idempotence, prix serveur, quantites, deni de service sur le stock |
| `tests/e2e/security-dbdown.spec.js` | 4 | base arretee : reponses generiques, connexion en echec ferme |
| `tests/e2e/run-security.sh` | — | lanceur : pile jetable `APP_DEBUG=false`, trois phases |
| `tests/Integration/Security/PasswordResetExpiryDbTest.php` | 5 | expiration du lien contre une vraie MariaDB, horloge injectee |

**Methode commune.** Chaque test monte son propre jeu de donnees par l'API d'administration
(comptes, role, produits, ingredient jetables, noms uniques par execution) et relit l'etat
apres chaque refus : un « 403 » ne suffit pas, il faut que rien n'ait bouge. Les comptes de
demonstration publics (`docs/demo/comptes-demo.md`) servent d'acteurs, comme dans les autres
specs ; aucun n'est modifie. Pour la force brute, chaque test pose sa propre adresse IP de
documentation (RFC 5737) dans `X-Forwarded-For` : ses compteurs ne bloquent pas les autres
tests. Pour la XSS, le test ne se contente pas de verifier qu'aucun script n'a tourne (la CSP
l'empecherait de toute facon) : il verifie qu'aucun element injecte n'existe dans le DOM et
qu'aucune violation CSP n'est journalisee, ce qui prouve l'echappement lui-meme.

**Un ecart = un test marque.** Quand une protection attendue manque, le test garde
l'assertion attendue et est marque `test.fail()` avec la raison et le fichier en cause, place
APRES la preparation (qui, elle, doit reussir). Il compte comme « rouge attendu ». Le jour ou
le correctif est livre, il devient « vert inattendu » et le rapport le signale : il faut alors
retirer la marque.

## 3. Comment rejouer

```bash
tests/e2e/run-security.sh
```

Le lanceur monte une pile jetable isolee (projet compose `wakdosec`, hotes
`kiosk.wakdo.test` / `admin.wakdo.test`, **APP_DEBUG=false** comme en production), joue la
suite dans l'image Playwright du projet (`mcr.microsoft.com/playwright:v1.49.1-jammy`), sans
nouvelle retentative (`--retries=0`), puis demonte tout, volumes compris. Trois phases :

1. `main` : les 14 fichiers `security-*.spec.js` ;
2. `reset` : le lien de reinitialisation demande en phase 1 est relu dans le journal de
   l'application (pas de SMTP sur la pile : `App\Auth\LogMailer` l'y ecrit) puis rejoue ;
3. `db-down` : la base de la pile jetable est arretee, les reponses d'erreur sont relues.

Un sous-ensemble : `tests/e2e/run-security.sh security-xss.spec.js security-csrf.spec.js`.
La suite tourne aussi avec le lanceur E2E habituel (valeurs de `.env.example`,
`APP_DEBUG=true`) : les 8 tests des phases 2 et 3 et le test de la 500 « mode production » se
sautent alors d'eux-memes.

Test PHP (base MariaDB jetable, comme le job CI `static-tests`) :
`WAKDO_DB_TESTS=1 DB_HOST=<base> ... php phpunit.phar -c phpunit.xml tests/Integration/Security`.

La production n'est pas sollicitee : toutes les ecritures (comptes, produits, commandes,
fichiers deposes) visent la pile jetable. Seule exception, en lecture : deux `GET
/api/health` sur l'hote de production le 2026-09-29, pour confirmer un constat (section 6).

## 4. Resultats dates

| Execution (2026-09-29, UTC) | Lanceur | Tests | Verts | Rouges marques (ecarts) | Rouges inattendus | Sautes |
|---|---|---|---|---|---|---|
| n° 1, 07:30 | `run-security.sh` (APP_DEBUG=false, 3 phases) | 100 | 87 | 13 | 0 | 0 |
| n° 2, 07:31 | `run-security.sh` (APP_DEBUG=false, 3 phases) | 100 | 87 | 13 | 0 | 0 |
| controle | lanceur E2E habituel (APP_DEBUG=true, phase 1) | 100 | 79 | 12 | 0 | 9 |
| PHP | PHPUnit 11.5, MariaDB 11.4 jetable | 5 (36 assertions) | 5 | 0 | 0 | 0 |

Duree d'une execution complete : environ 1 min 30 s, montage de la pile compris. Les deux
executions officielles donnent le **meme statut pour chacun des 100 tests** (comparaison des
deux journaux ligne a ligne, durees exclues) : la suite ne depend ni de l'ordre ni d'un etat
laisse par une execution precedente. Elle a aussi ete jouee plusieurs fois de suite sur une
pile d'essai non remise a zero pendant sa mise au point, apres correction de sa seule
dependance a l'etat (un libelle non unique dans `security-injection.spec.js`).
`PasswordResetExpiryDbTest.php` passe PHPStan niveau 6 (configuration du projet).

## 5. Tableau des controles

Reference : OWASP Top 10 2021 (A01 a A07) et, quand une exigence precise s'applique, OWASP
ASVS 4.0 (chapitre ou exigence).

| # | Controle | Reference OWASP | Test(s) | Resultat |
|---|---|---|---|---|
| 1 | En-tetes : `nosniff`, anti-encadrement (`X-Frame-Options` ou `frame-ancestors`), `Referrer-Policy`, CSP `script-src 'self'`, sur 9 types de reponse des deux hotes | A05 ; ASVS V14.4 | `security-headers` | prouve |
| 1 | Aucune version dans `Server` / `X-Powered-By` | A05 ; ASVS V14.3 | `security-headers` | prouve (en-tetes) — mais la sonde publique donne la version de PHP, ecart m5 |
| 1 | CSP de la borne complete (frame-ancestors, object-src, base-uri, form-action, styles) | A05 ; ASVS V14.4 | `security-headers` | prouve |
| 1 | CSP du back-office : `base-uri`, `form-action` | A05 ; ASVS V14.4 | `security-headers` | **ecart m3** |
| 1 | `Permissions-Policy` | A05 | `security-headers` | **ecart m2** (deux hotes) |
| 2 | Cookie : HttpOnly, SameSite=Strict ; Secure seulement en HTTPS (ADR-0010) | A07 ; ASVS V3.4 | `security-session` | prouve |
| 2 | Fixation : identifiant impose refuse, nouvel identifiant a la connexion, ancien inutilisable | A07 ; ASVS V3.2.1 | `security-session` | prouve |
| 2 | Deconnexion (HTML et JSON) : l'ancien cookie ne donne plus acces | A07 ; ASVS V3.3.1 | `security-session` | prouve |
| 2 | Pas de session sur l'hote borne | A05 | `security-session` | **ecart m1** |
| 3 | Injection SQL : 13 charges (guillemet, OR 1=1, UNION, commentaire, point-virgule, SLEEP) dans les chemins, requetes et corps publics et d'administration ; pas de 500, pas de trace SQL, pas de delai, compteurs inchanges, libelles relus a l'identique | A03 ; ASVS V5.3.4 | `security-injection` | prouve |
| 4 | XSS stockee : categorie, 2 produits, ingredient, chevalet de commande ; borne (grilles, modale, panier, paiement), back-office (17 ecrans dont ecran cuisine et caisse) | A03 ; ASVS V5.3.3 | `security-xss` | prouve |
| 4 | XSS reflechie : jeton de reinitialisation, surlignage comptoir, page 404, formulaire en erreur | A03 ; ASVS V5.3.3 | `security-xss` | prouve |
| 5 | CSRF : jeton de la session A avec le cookie de la session B (formulaire et API), jeton hors en-tete, jeton d'avant connexion | A01 ; ASVS V4.2.2 | `security-csrf` | prouve |
| 5 | Connexion JSON sans jeton : protegee par le type de contenu impose (415 pour un formulaire) | A01 | `security-csrf` | prouve |
| 6 | Horizontal : comptoir / drive ne lisent ni ne modifient la commande de l'autre canal (API et HTML, etat relu) | A01 ; ASVS V4.2.1 | `security-access` | prouve |
| 6 | Vertical : cuisine n'ecrit que « prete » (13 ecritures refusees, API et HTML) ; responsable ne cree ni compte ni role | A01 ; ASVS V4.1 | `security-access` | prouve |
| 6 | Compte desactive, role desactive : acces coupe sur une session deja ouverte | A01 ; ASVS V4.1 | `security-access` | prouve |
| 6 | Compte change de role : droits de l'ancien role conserves jusqu'a la reconnexion | A01 | `security-access` | **ecart i1** |
| 6 | 44 pages d'administration sans session : redirection `/login`, corps vide | A01 | `security-access` | prouve |
| 6 | Methodes non prevues : 405, ressource relue inchangee | A05 ; ASVS V14.5.1 | `security-access` | prouve |
| 6 | Methode `TRACE` | A05 ; ASVS V14.5.1 | `security-access` | **ecart m4** |
| 7 | Message identique compte existant / inconnu (HTML et JSON) | A07 ; ASVS V2.2.1 | `security-bruteforce` | prouve |
| 7 | Verrou par compte apres 5 echecs, depuis toute IP, indiscernable d'un echec ordinaire | A07 ; ASVS V2.2.1 | `security-bruteforce` | prouve |
| 7 | Verrou par IP apres 20 echecs (429 + Retry-After), les autres IP non penalisees | A07 ; ASVS V2.2.1 | `security-bruteforce` | prouve |
| 7 | PIN bloque apres 5 echecs, meme juste ensuite, commande non annulee | A07 | `security-bruteforce` | prouve |
| 7 | Mot de passe oublie : reponse identique ; lien forge refuse ; lien a usage unique ; expire apres sa duree ; jeton hache au repos | A07 ; ASVS V2.5 | `security-bruteforce`, `security-reset`, `PasswordResetExpiryDbTest` | prouve |
| 7 | Mot de passe oublie : limitation du nombre de demandes | A07 | `security-bruteforce` | **ecart m6** |
| 7 | Sessions fermees apres reinitialisation | A07 ; ASVS V3.3 | `security-reset` | **ecart m7** |
| 8 | Envoi d'image : PHP renomme en .jpg, signature PNG + PHP, SVG avec script, type mensonger, 6 Mo refuses ; polyglotte accepte comme image mais servi octet pour octet en `image/png` + `nosniff`, non interprete (le code PHP ressort tel quel), et refuse (403) sous une extension executable ; `../` et double extension ignores ; pas de listing | A04 / A05 ; ASVS V12 | `security-upload` | prouve |
| 9 | Affectation de masse : id, is_active, role_id, prix, stock, password_hash, pin_hash, statut, canal, total ignores (11 routes, relecture) | A01 ; ASVS V5.1.2 | `security-mass-assignment` | prouve |
| 10 | Pages d'erreur 400 / 403 / 404 / 405 des deux hotes : ni pile, ni chemin, ni SQL | A05 ; ASVS V7.4.1 | `security-info-leak` | prouve |
| 10 | Vraie 500 avec APP_DEBUG=false : message generique ; base arretee : 500 generique, connexion en echec ferme | A05 ; ASVS V7.4.1 | `security-info-leak`, `security-dbdown` | prouve |
| 10 | 39 chemins sensibles (.env, .git, composer, sources, captures, sauvegardes, phpinfo, server-status) sur les deux hotes : non servis ; listing desactive (10 dossiers) | A05 ; ASVS V14.3 | `security-info-leak` | prouve |
| 10 | `/api/health` : aucun secret | A05 | `security-info-leak` | prouve — version de PHP exposee, ecart m5 |
| 11 | L'hote borne ne sert ni page ni API du back-office (15 chemins + 2 connexions) | A01 | `security-borne` | prouve |
| 11 | Aucun secret ni donnee personnelle dans les JS et JSON charges par 6 ecrans de la borne | A05 | `security-borne` | prouve |
| 11 | Stockage du navigateur apres une commande complete : ni e-mail, ni jeton, ni cookie | A05 | `security-borne` | prouve |
| 11 | CORS : origine etrangere, sosie, `null` sans aucun en-tete ; origine autorisee exacte, pas de `*`, pas d'Allow-Credentials, pas sur `/admin/*` | A05 ; ASVS V14.5.3 | `security-borne` | prouve |
| 11 | Suivi public : canal kiosk seul, numeros comptoir / drive repondus comme inconnus | A01 | `security-borne` | prouve |
| 12 | Cle d'idempotence : 36 acceptes, 37 refuses (422) ; prix et total du client ignores ; encaissement rejoue debite le stock une fois | A04 ; ASVS V11.1 | `security-order-integrity` | prouve |
| 12 | Quantite negative ou nulle refusee | A04 ; ASVS V5.1 | `security-order-integrity` | **ecart m8** |
| 12 | Quantite enorme refusee ; stock non vidable par une commande anonyme | A04 ; ASVS V11.1 | `security-order-integrity` | **ecart I1** |
| 12 | Corps mal forme (ligne non objet, cle en tableau) refuse en 422 | A04 ; ASVS V5.1 | `security-order-integrity` | **ecart m9** |

## 6. Ecarts trouves

Classement : **critique** (compromission sans prerequis) — aucun trouve ; **important** ;
**mineur** ; **information**. Aucun n'a ete corrige dans ce lot : chaque test marque indique
le fichier a reprendre.

### Importants

**I1 — Commande anonyme sans plafond de quantite : le stock d'un produit se vide en deux
requetes.** `OrderRepository::resolveLine()` (`src/app/Order/OrderRepository.php:1095`) ne
borne la quantite qu'en bas. `POST /api/orders` avec `"quantity": 65535` est accepte (201),
puis `POST /api/orders/{numero}/pay` — anonyme, `OrderController::pay`,
`src/app/Controllers/OrderController.php:51` — debite le stock : il devient negatif et la
rupture calculee (RG-T21) retire le produit de la borne pour tous les clients. Sur la pile de
test, une seule paire de requetes sur le burger n° 1 a rendu 14 produits indisponibles
(ingredients partages). Au-dela de 65 535 (colonne `SMALLINT UNSIGNED`,
`db/migrations/0001_init_schema.sql:355`), l'exception PDO remonte en 500 ; avec
`APP_DEBUG=true` le message SQL part dans la reponse. Test :
`security-order-integrity.spec.js` (deux tests marques).

**i1 — Changement de role d'un compte connecte non applique a sa session.**
`SessionGuard::check()` relit `is_active` en base a chaque requete
(`src/app/Auth/SessionGuard.php:57`) mais prend `role_id` dans la session
(`src/app/Auth/SessionGuard.php:36`), pose une fois a la connexion
(`src/app/Auth/AuthService.php:146`). Un responsable retrograde en equipier cuisine garde
l'acces aux statistiques jusqu'a sa deconnexion (au plus 10 h). La desactivation du compte ou
du role, elle, coupe l'acces aussitot (prouve). Test : `security-access.spec.js`.

### Mineurs

- **m1 — Session ouverte pour chaque appel anonyme.** `src/public/admin/index.php:56` demarre la
  session avant le dispatch, pour toute requete : chaque appel de la borne a `/api/*` (et la
  sonde `/api/health`, y compris en production) recoit un `Set-Cookie: WAKDO_SID` et cree un
  fichier de session. Pas de droit gagne ; stockage serveur qui grossit avec le trafic anonyme.
- **m2 — `Permissions-Policy` absente** sur les deux hotes (`docker/apache/httpd.conf`,
  `docker/apache/vhost.conf`).
- **m3 — CSP du back-office sans `base-uri` ni `form-action`** (`docker/apache/vhost.conf:247`) ;
  ces deux directives ne retombent pas sur `default-src`. La borne les pose
  (`docker/apache/vhost.conf:145`).
- **m4 — `TRACE` accepte par Apache** (200, la requete est renvoyee en echo), faute de
  `TraceEnable Off` dans `docker/apache/httpd.conf`. Les navigateurs actuels interdisent
  `TRACE` depuis une page ; non verifie derriere Traefik.
- **m5 — Version de PHP publiee par la sonde publique** :
  `src/app/Controllers/HealthController.php:48` (`"php_version": "8.3.22"`), lisible
  anonymement, aussi par l'hote borne. Observe en production le 2026-09-29.
- **m6 — `/forgot_password` sans limitation** : 30 demandes de suite pour la meme adresse
  passent (`src/app/Controllers/PasswordResetController.php:48`). Avec un SMTP configure :
  inondation de la boite visee.
- **m7 — Sessions conservees apres une reinitialisation du mot de passe** :
  `PasswordResetService::confirmReset()` (`src/app/Auth/PasswordResetService.php:98`) change le
  hash sans fermer les sessions ouvertes du compte.
- **m8 — Quantite negative ou nulle acceptee et ramenee a 1**
  (`src/app/Order/OrderRepository.php:1095`, `max(1, ...)`) : une saisie invalide passe en
  silence (le total reste calcule par le serveur).
- **m9 — Corps de commande mal forme mal traite** : une ligne qui n'est pas un objet provoque
  un `TypeError` (500) (`src/app/Order/OrderRepository.php:457`) ; une cle d'idempotence
  envoyee en tableau devient la chaine `"Array"` (`src/app/Order/OrderRepository.php:397`) :
  avec `APP_DEBUG=true` l'avertissement PHP, chemin du fichier compris, part dans la reponse ;
  sinon la commande est creee sous une cle partagee par tous ceux qui envoient un tableau.

### Information (hors defaut, ou dependant du deploiement)

- **IP cliente lue dans `X-Forwarded-For`** (dernier element, `src/app/Core/Request.php:290`).
  C'est ce qui permet aux tests d'isoler leurs compteurs ; en production, la valeur fiable
  suppose que Traefik est le seul point d'entree et ajoute lui-meme l'en-tete — a verifier
  sur la configuration Traefik, non testee ici. Le verrou par compte ne depend pas de l'IP.
- **Hote inconnu** : le premier vhost (sonde `/healthz`, `docker/apache/vhost.conf:26`) sert la
  page par defaut d'Apache ; aucun contenu applicatif (prouve). En production, Traefik ne relaie
  que les deux noms declares.
- **Identifiants de chemin lus comme entiers** : `/api/products/1' OR 1=1` renvoie le produit 1
  (conversion `(int)`), sans injection (prouve) ; une validation stricte renverrait 404.
- Hors securite, releves en passant : la liste `GET /admin/api/products` renvoie `image_path`
  et `description` a `null` (`src/app/Catalogue/ProductRepository.php:47`, colonnes non lues) ;
  la borne associe ses pages produits aux 9 categories du seed par une table fixe
  (`src/public/borne/assets/js/data.js:235`, `page-products.js:20`) : une categorie creee au
  back-office s'affiche sur la borne mais ouvre la page des menus.
- Le role cuisine peut faire l'inventaire (`stock.count`, avec PIN), comme le documente
  `docs/demo/comptes-demo.md` : « cuisine n'ecrit que “prete” » vaut pour les commandes et le
  catalogue, pas pour l'inventaire, qui n'a pas ete teste ici.

## 7. Limites

- **Pas de scanner automatique** (ZAP, sqlmap, Burp) : les charges sont ecrites a la main et
  la liste est bornee (13 charges SQL, 9 charges XSS stockees, 4 reflechies). Un scanner essaierait bien plus de
  variantes ; il n'a pas ete installe, par choix de perimetre.
- **Pas de test de charge ni de deni de service reseau** (lenteur volontaire, rafales) : seul
  le deni de service applicatif I1 est demontre.
- **HTTPS, HSTS, TLS** relevent de Traefik en production et ne se testent pas sur la pile
  locale en HTTP. Le cookie `Secure` est teste en simulant l'en-tete `X-Forwarded-Proto` que
  pose Traefik ; la lecture de production du 2026-09-29 montre `Secure` et un HSTS
  `includeSubDomains; preload`, sans autre verification.
- **Comportement de Traefik** (methodes relayees, `X-Forwarded-For`) non teste : la suite ne
  touche pas la production.
- **Un seul navigateur** (Chromium 131) pour les tests XSS et de stockage.
- **Pas de revue de dependances** : le projet n'embarque pas de bibliotheque tierce cote
  serveur ni cote borne ; les images Docker ne sont pas analysees ici.
- **Temps de reponse** : l'egalite des messages est prouvee ; l'egalite des durees (compte
  existant ou non) est traitee par le code (leurres de calcul) et ses tests unitaires, pas
  mesuree ici — une mesure en reseau local serait trop bruitee pour conclure.
- Les tests `security-reset` et `security-dbdown` ne tournent que dans `run-security.sh`
  (ils lisent le journal et arretent la base de la pile jetable).
