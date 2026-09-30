# Preuve 10 — Tests de securite executables (borne + back-office)

Titre professionnel RNCP 37805 — Bloc 2 (back-office, API), axe securite.
Perimetre : les deux surfaces de Wakdo, telles qu'elles tournent en production —
la borne (site statique + API publique `/api/*`, hote kiosk) et le back-office (pages HTML
`/admin/*`, API JSON `/admin/api/*`, hote admin). Etat au 2026-09-29, code du commit
`fddc26c` (branche `dev`, fusionne dans `main` par `dc1829d`). La production sert depuis
`aab4e96` (release du 29/09, deployee 13:50 UTC, champ `version` de `/api/health`), qui
inclut aussi les correctifs de la revue adversariale du 30/09 detailles en section 8.

**Ce que cette preuve apporte.** Les protections du projet etaient decrites (ADR, modele de
menaces, `SECURITY.md`) et verifiees en grande partie par des tests unitaires et par la
capture route par route de la page Sante. Il manquait une suite qui attaque l'application
**de l'exterieur**, comme le ferait un client malveillant, sur une pile complete (Apache +
PHP-FPM + MariaDB) et dans un vrai navigateur, et qui dise pour chaque protection : prouvee,
ou refutee. Cette suite existe maintenant : 100 tests Playwright et 5 tests PHP contre une
base reelle. Elle a trouve **13 tests marques (11 ecarts distincts, section 6)** ; a la date
de cette execution (2026-09-29, 07:30-07:31), aucun correctif applicatif n'avait ete fait.

**Mise a jour du 2026-09-29 (apres cette execution).** Trois commits de correctif ont depuis
ete fusionnes dans cette branche et retirent la marque `test.fail()` des 13 tests (0 marque
restant dans `tests/e2e/security-*.spec.js` a la date de redaction de cette mise a jour) :
`08d7a96` (en-tetes de securite : `Permissions-Policy`, CSP `base-uri`/`form-action`,
`TraceEnable Off`, sonde publique sans version PHP — ecarts m2, m3, m4, m5), `ef7fd37`
(session : role et epoch relus en base a chaque requete, aucune session sur `/api/*`, throttle
de `/forgot_password`, sessions invalidees a la reinitialisation — ecarts m1, m6, m7, i1) et
`fce3085` (branche `fix/sec-order`, fusionnee par `86306ef` : quantite/nombre de lignes
bornes, type de corps strict, option de menu en rupture refusee et grisee — ecarts I1, m8, m9).
Un quatrieme commit, `33538c6`, ajoute une borne complementaire sur le TOTAL d'articles par
commande (`MAX_ITEMS_PER_ORDER` = 50, code `ORDER_TOO_LARGE`) avec un nouveau test e2e dedie
(non compris dans les 13 tests marques de l'execution datee, puisqu'il n'existait pas encore) —
voir la note dans le detail de I1 (section 6). Le detail par ecart (section 6) porte la mention
« corrige » avec le commit, en gardant le constat original (c'est l'interet de la fiche).

**Rejeu du 2026-09-29, apres ces quatre commits (commit `2fe8a4a`).** La suite a ete relancee
par `bash tests/e2e/run-security.sh` (3 phases, `APP_DEBUG=false`) : phase principale
95 reussis (8 sautes, joues dans les phases suivantes), phase reinitialisation 4 reussis,
phase base arretee 4 reussis ; **0 echec, 0 test marque en echec restant** dans
`tests/e2e/security-*.spec.js` — plus 5 tests PHP de securite (`tests/Integration/Security`),
egalement verts. Les 13 tests marques de l'execution datee (section 4) et les lignes
« ecart » de la section 5 decrivent donc un etat AVANT correctif qui n'est plus celui du
depot ; ce rejeu confirme chacun des 11 ecarts corrige, sans exception restante.

**Mise a jour de l'apres-midi du 2026-09-29 (revue adversariale des correctifs).** Une relecture
par des agents qui n'avaient pas ecrit les correctifs a trouve des manques, corriges en TDD :
`680820f` (la table `password_reset_throttle` n'etait purgee par aucun cron : ajoutee a
`docker/cron/scripts/purge-throttle.sh` ; l'adresse y etait gardee en clair : empreinte SHA-256,
migration `0021` ; un changement de mot de passe fait par l'administrateur ferme desormais les
sessions du compte ; chevalet et mode de service en types stricts), `e9f00d8` (modification d'un
menu : emplacements apparies par type et nom puis par position, lignes verrouillees, commande
concurrente traduite en `409`), `186c5d7` (option de menu disponible ou non selon le format servi,
`option_is_orderable_maxi` ; au comptoir, quantite hors 1-20 refusee en `422` au lieu d'etre
ramenee a 1, y compris sur `POST /admin/api/orders` ; plafond de 20 par ligne sur la borne et au
comptoir). **Rejeu sur `c2b8c1c`** (`bash tests/e2e/run-security.sh`, `APP_DEBUG=false`) : phase
principale 99 reussis + 8 sautes (107 tests), phase reinitialisation 4 sur 4, phase base arretee
4 sur 4, 0 echec. **Statut en production** : tout ce qui precede est en production depuis la
release `aab4e96` du 29/09 (deployee 13:50 UTC, champ `version` de `/api/health`).

## 1. La base existante (reprise, pas dupliquee)

| Preuve deja en place | Ce qu'elle couvre |
|---|---|
| `src/app/Health/captured-responses.json` (158 routes) + `tests/Unit/Health/CapturedResponsesTest.php`, `RouteSecurityCoverageTest.php`, `tests/Unit/Admin/Api/RouteMatrixTest.php` | pour chaque route, la vraie reponse sans session, sans permission, sans jeton CSRF, avec un corps mal type, avec un PIN faux ; une route ajoutee sans sa ligne de securite fait echouer la CI |
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
| `tests/e2e/security-order-integrity.spec.js` | 7 (14 au rejeu du 29/09 apres-midi) | idempotence, prix serveur, quantites, deni de service sur le stock ; puis `ORDER_TOO_LARGE` et disponibilite d'option par format |
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
`APP_DEBUG=true`) : les 8 tests des phases 2 et 3 se sautent alors d'eux-memes. L'ancien test
de la 500 « mode production » ne se saute plus : depuis `2fe8a4a`, il verifie que sa charge est
refusee proprement (`422 INVALID_QUANTITY`) ; la vraie 500 n'est plus prouvee que base arretee,
par `security-dbdown.spec.js`.

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
| rejeu, phase principale (29/09 matin, sur `2fe8a4a`) | `run-security.sh` (APP_DEBUG=false) | 103 | 95 | 0 | 0 | 8 (joues dans les phases suivantes) |
| rejeu, phase reinitialisation | `run-security.sh` (APP_DEBUG=false) | 4 | 4 | 0 | 0 | 0 |
| rejeu, phase base arretee | `run-security.sh` (APP_DEBUG=false) | 4 | 4 | 0 | 0 | 0 |
| rejeu, PHP | PHPUnit, MariaDB jetable | 5 | 5 | 0 | 0 | 0 |
| rejeu de l'apres-midi, phase principale (sur `c2b8c1c`) | `run-security.sh` (APP_DEBUG=false) | 107 | 99 | 0 | 0 | 8 (joues dans les phases suivantes) |
| rejeu de l'apres-midi, phase reinitialisation | `run-security.sh` (APP_DEBUG=false) | 4 | 4 | 0 | 0 | 0 |
| rejeu de l'apres-midi, phase base arretee | `run-security.sh` (APP_DEBUG=false) | 4 | 4 | 0 | 0 | 0 |

**Rejoue le 2026-09-29, sur le commit `2fe8a4a` — confirme.** Les quatre premieres lignes
datent de 07:30-07:31 le 2026-09-29, AVANT les commits `08d7a96`, `ef7fd37`, `fce3085` et
`33538c6` (voir la mise a jour en tete de fiche). Les quatre lignes « rejeu » ci-dessus, jouees
apres ces quatre commits, confirment que les 13 tests marques (m1, m2 x2 — un par hote —, m3,
m4, m5, m6, m7, i1, I1 x2, m8, m9) passent desormais sans marque : **0 rouge marque restant,
0 rouge inattendu**, sur les trois phases et sur les tests PHP. Le total de tests a legerement
augmente depuis l'execution n° 1 (nouveaux tests ajoutes par les commits de correctif cites
ci-dessus, dont le test dedie a `ORDER_TOO_LARGE`, section 6). Les comptes de demonstration
utilises par la suite ne sont pas modifies par ce rejeu.

Duree d'une execution complete : environ 1 min 30 s, montage de la pile compris. Les deux
executions officielles n° 1 et n° 2 donnent le **meme statut pour chacun des 100 tests**
(comparaison des deux journaux ligne a ligne, durees exclues) : la suite ne depend ni de
l'ordre ni d'un etat laisse par une execution precedente. Elle a aussi ete jouee plusieurs
fois de suite sur une pile d'essai non remise a zero pendant sa mise au point, apres
correction de sa seule dependance a l'etat (un libelle non unique dans
`security-injection.spec.js`). `PasswordResetExpiryDbTest.php` passe PHPStan niveau 6
(configuration du projet).

**Pour rejouer :** `bash tests/e2e/run-security.sh`.

## 5. Tableau des controles

Reference : OWASP Top 10 2021 (A01 a A07) et, quand une exigence precise s'applique, OWASP
ASVS 4.0 (chapitre ou exigence).

| # | Controle | Reference OWASP | Test(s) | Resultat |
|---|---|---|---|---|
| 1 | En-tetes : `nosniff`, anti-encadrement (`X-Frame-Options` ou `frame-ancestors`), `Referrer-Policy`, CSP `script-src 'self'`, sur 9 types de reponse des deux hotes | A05 ; ASVS V14.4 | `security-headers` | prouve |
| 1 | Aucune version dans `Server` / `X-Powered-By` | A05 ; ASVS V14.3 | `security-headers` | prouve (en-tetes) — la sonde publique donnait la version de PHP, **ecart m5, corrige le 2026-09-29 (commit `08d7a96`), rejoue le 29/09, vert** |
| 1 | CSP de la borne complete (frame-ancestors, object-src, base-uri, form-action, styles) | A05 ; ASVS V14.4 | `security-headers` | prouve |
| 1 | CSP du back-office : `base-uri`, `form-action` | A05 ; ASVS V14.4 | `security-headers` | **ecart m3, corrige le 2026-09-29 (commit `08d7a96`), rejoue le 29/09, vert** |
| 1 | `Permissions-Policy` | A05 | `security-headers` | **ecart m2 (deux hotes), corrige le 2026-09-29 (commit `08d7a96`), rejoue le 29/09, vert** |
| 2 | Cookie : HttpOnly, SameSite=Strict ; Secure seulement en HTTPS (ADR-0010) | A07 ; ASVS V3.4 | `security-session` | prouve |
| 2 | Fixation : identifiant impose refuse, nouvel identifiant a la connexion, ancien inutilisable | A07 ; ASVS V3.2.1 | `security-session` | prouve |
| 2 | Deconnexion (HTML et JSON) : l'ancien cookie ne donne plus acces | A07 ; ASVS V3.3.1 | `security-session` | prouve |
| 2 | Pas de session sur l'hote borne | A05 | `security-session` | **ecart m1, corrige le 2026-09-29 (commit `ef7fd37`), rejoue le 29/09, vert** |
| 3 | Injection SQL : 13 charges (guillemet, OR 1=1, UNION, commentaire, point-virgule, SLEEP) dans les chemins, requetes et corps publics et d'administration ; pas de 500, pas de trace SQL, pas de delai, compteurs inchanges, libelles relus a l'identique | A03 ; ASVS V5.3.4 | `security-injection` | prouve |
| 4 | XSS stockee : categorie, 2 produits, ingredient, chevalet de commande ; borne (grilles, modale, panier, paiement), back-office (17 ecrans dont ecran cuisine et caisse) | A03 ; ASVS V5.3.3 | `security-xss` | prouve |
| 4 | XSS reflechie : jeton de reinitialisation, surlignage comptoir, page 404, formulaire en erreur | A03 ; ASVS V5.3.3 | `security-xss` | prouve |
| 5 | CSRF : jeton de la session A avec le cookie de la session B (formulaire et API), jeton hors en-tete, jeton d'avant connexion | A01 ; ASVS V4.2.2 | `security-csrf` | prouve |
| 5 | Connexion JSON sans jeton : protegee par le type de contenu impose (415 pour un formulaire) | A01 | `security-csrf` | prouve |
| 6 | Horizontal : comptoir / drive ne lisent ni ne modifient la commande de l'autre canal (API et HTML, etat relu) | A01 ; ASVS V4.2.1 | `security-access` | prouve |
| 6 | Vertical : cuisine n'ecrit que « prete » (13 ecritures refusees, API et HTML) ; responsable ne cree ni compte ni role | A01 ; ASVS V4.1 | `security-access` | prouve |
| 6 | Compte desactive : session fermee des la requete suivante ; role desactive : plus aucune permission sur une session deja ouverte (les pages SANS permission dediee -- tableau de bord, `/admin/me`, `/admin/profile/pin`, `/admin/privacy` -- restent accessibles, cf. `App\Health\RouteSecurity`) | A01 ; ASVS V4.1 | `security-access` | prouve |
| 6 | Compte change de role : droits de l'ancien role conserves jusqu'a la reconnexion | A01 | `security-access` | **ecart i1, corrige le 2026-09-29 (commit `ef7fd37`), rejoue le 29/09, vert** |
| 6 | 44 pages d'administration sans session : redirection `/login`, corps vide | A01 | `security-access` | prouve |
| 6 | Methodes non prevues : 405, ressource relue inchangee | A05 ; ASVS V14.5.1 | `security-access` | prouve |
| 6 | Methode `TRACE` | A05 ; ASVS V14.5.1 | `security-access` | **ecart m4, corrige le 2026-09-29 (commit `08d7a96`), rejoue le 29/09, vert** |
| 7 | Message identique compte existant / inconnu (HTML et JSON) | A07 ; ASVS V2.2.1 | `security-bruteforce` | prouve |
| 7 | Verrou par compte apres 5 echecs, depuis toute IP, indiscernable d'un echec ordinaire | A07 ; ASVS V2.2.1 | `security-bruteforce` | prouve |
| 7 | Verrou par IP apres 20 echecs (429 + Retry-After), les autres IP non penalisees | A07 ; ASVS V2.2.1 | `security-bruteforce` | prouve |
| 7 | PIN bloque apres 5 echecs, meme juste ensuite, commande non annulee | A07 | `security-bruteforce` | prouve |
| 7 | Mot de passe oublie : reponse identique ; lien forge refuse ; lien a usage unique ; expire apres sa duree ; jeton hache au repos | A07 ; ASVS V2.5 | `security-bruteforce`, `security-reset`, `PasswordResetExpiryDbTest` | prouve |
| 7 | Mot de passe oublie : limitation du nombre de demandes | A07 | `security-bruteforce` | **ecart m6, corrige le 2026-09-29 (commit `ef7fd37`), rejoue le 29/09, vert** |
| 7 | Sessions fermees apres reinitialisation | A07 ; ASVS V3.3 | `security-reset` | **ecart m7, corrige le 2026-09-29 (commit `ef7fd37`), rejoue le 29/09, vert** |
| 8 | Envoi d'image : PHP renomme en .jpg, signature PNG + PHP, SVG avec script, type mensonger, 6 Mo refuses ; polyglotte accepte comme image mais servi octet pour octet en `image/png` + `nosniff`, non interprete (le code PHP ressort tel quel), et refuse (403) sous une extension executable ; `../` et double extension ignores ; pas de listing | A04 / A05 ; ASVS V12 | `security-upload` | prouve |
| 9 | Affectation de masse : id, is_active, role_id, prix, stock, password_hash, pin_hash, statut, canal, total ignores (11 routes, relecture) | A01 ; ASVS V5.1.2 | `security-mass-assignment` | prouve |
| 10 | Pages d'erreur 400 / 403 / 404 / 405 des deux hotes : ni pile, ni chemin, ni SQL | A05 ; ASVS V7.4.1 | `security-info-leak` | prouve |
| 10 | Vraie 500 avec APP_DEBUG=false : message generique ; base arretee : 500 generique, connexion en echec ferme | A05 ; ASVS V7.4.1 | `security-info-leak`, `security-dbdown` | prouve |
| 10 | 39 chemins sensibles (.env, .git, composer, sources, captures, sauvegardes, phpinfo, server-status) sur les deux hotes : non servis ; listing desactive (10 dossiers) | A05 ; ASVS V14.3 | `security-info-leak` | prouve |
| 10 | `/api/health` : aucun secret | A05 | `security-info-leak` | prouve — version de PHP exposee, **ecart m5, corrige le 2026-09-29 (commit `08d7a96`), rejoue le 29/09, vert** |
| 11 | L'hote borne ne sert ni page ni API du back-office (15 chemins + 2 connexions) | A01 | `security-borne` | prouve |
| 11 | Aucun secret ni donnee personnelle dans les JS et JSON charges par 6 ecrans de la borne | A05 | `security-borne` | prouve |
| 11 | Stockage du navigateur apres une commande complete : ni e-mail, ni jeton, ni cookie | A05 | `security-borne` | prouve |
| 11 | CORS : origine etrangere, sosie, `null` sans aucun en-tete ; origine autorisee exacte, pas de `*`, pas d'Allow-Credentials, pas sur `/admin/*` | A05 ; ASVS V14.5.3 | `security-borne` | prouve |
| 11 | Suivi public : canal kiosk seul, numeros comptoir / drive repondus comme inconnus | A01 | `security-borne` | prouve |
| 12 | Cle d'idempotence : 36 acceptes, 37 refuses (422) ; prix et total du client ignores ; encaissement rejoue debite le stock une fois | A04 ; ASVS V11.1 | `security-order-integrity` | prouve |
| 12 | Quantite negative ou nulle refusee | A04 ; ASVS V5.1 | `security-order-integrity` | **ecart m8, corrige le 2026-09-29 (commit `fce3085`, fusionne par `86306ef`), rejoue le 29/09, vert** |
| 12 | Quantite enorme refusee ; stock non vidable par une commande anonyme | A04 ; ASVS V11.1 | `security-order-integrity` | **ecart I1, corrige le 2026-09-29 (commit `fce3085`, fusionne par `86306ef`), rejoue le 29/09, vert** |
| 12 | Corps mal forme (ligne non objet, cle en tableau) refuse en 422 | A04 ; ASVS V5.1 | `security-order-integrity` | **ecart m9, corrige le 2026-09-29 (commit `fce3085`, fusionne par `86306ef`), rejoue le 29/09, vert** |

## 6. Ecarts trouves

Classement : **critique** (compromission sans prerequis) — aucun trouve ; **important** ;
**mineur** ; **information**. A la date de cette execution (2026-09-29, 07:30-07:31), aucun
n'avait ete corrige. Les onze l'ont ete depuis, par trois commits merges le meme jour (voir la
mise a jour en tete de fiche), plus un quatrieme commit qui ajoute une borne complementaire ;
chaque entree corrigee garde son constat original (c'est l'interet de la fiche) et porte la
mention « corrige » avec le commit et le test qui le prouve, rejoue le 2026-09-29 (vert).
Les chemins et numeros de ligne cites dans les constats sont ceux du code au moment du
constat (commit `fddc26c`) ; le code a bouge depuis.

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

**Corrige le 2026-09-29 (commit `fce3085`, branche `fix/sec-order`, fusionnee par
`86306ef`)** : `OrderRepository::resolveQuantity()` (l.1230) borne desormais la quantite entre
1 et 20 (`INVALID_QUANTITY`, 422, `MAX_QUANTITY_PER_LINE` l.46) au lieu de `max(1, ...)`, et
`resolveAndTotal()` refuse au-dela de 50 lignes distinctes (`TOO_MANY_ITEMS`, 422,
`MAX_LINES_PER_ORDER` l.56, l.497) avant toute resolution catalogue. Test rejoue le 2026-09-29, vert ; les deux marques retirees. **Complement du meme jour (commit `33538c6`)** : une
borne sur le TOTAL d'articles de la commande (somme des quantites, `OrderRepository::
MAX_ITEMS_PER_ORDER` = 50, l.69, code `ORDER_TOO_LARGE`), distincte de la borne par ligne et
de la borne par nombre de lignes ci-dessus — sans elle, 50 lignes a 20 articles chacune (les
deux bornes ci-dessus respectees individuellement) restaient possibles dans UNE commande
anonyme (jusqu'a 1000 articles). Nouveau test e2e dedie, rejoue le 2026-09-29, vert.

**i1 — Changement de role d'un compte connecte non applique a sa session.**
`SessionGuard::check()` relit `is_active` en base a chaque requete
(`src/app/Auth/SessionGuard.php`) mais prend `role_id` dans la session, pose une fois a la
connexion (`AuthService::authenticate()`). Un responsable retrograde en equipier cuisine garde
l'acces aux statistiques jusqu'a sa deconnexion (au plus 10 h). La desactivation du compte ou
du role, elle, coupe l'acces aussitot (prouve). Test : `security-access.spec.js`.

**Corrige le 2026-09-29 (commit `ef7fd37`).** `SessionGuard::check()` relit desormais
`is_active`, `role_id` ET `session_epoch` en base, dans la MEME requete SQL, a chaque
requete authentifiee (`src/app/Auth/SessionGuard.php`, RG-T02) : un role retrograde
s'applique donc des la requete suivante, sans attendre la deconnexion. Test rejoue le 2026-09-29, vert.

### Mineurs

- **m1 — Session ouverte pour chaque appel anonyme.** Le front controller (`src/public/admin/index.php`) demarrait la
  session avant le dispatch, pour toute requete : chaque appel de la borne a `/api/*` (et la
  sonde `/api/health`, y compris en production) recoit un `Set-Cookie: WAKDO_SID` et cree un
  fichier de session. Pas de droit gagne ; stockage serveur qui grossit avec le trafic anonyme.
  **Corrige le 2026-09-29 (commit `ef7fd37`)** : une session n'est desormais ouverte que pour
  les routes qui en ont besoin (`App\Auth\SessionRoutePolicy::needsSession()`, cablee dans
  `src/public/admin/index.php` avant le dispatch) ; `/api/*` n'ouvre plus de session. Test rejoue le 2026-09-29, vert.
- **m2 — `Permissions-Policy` absente** sur les deux hotes (`docker/apache/httpd.conf`,
  `docker/apache/vhost.conf`). **Corrige le 2026-09-29 (commit `08d7a96`)** : en-tete pose une
  fois dans `httpd.conf` (herite par les deux vhosts), toutes les fonctionnalites
  capteur/media/paiement coupees. Test rejoue le 2026-09-29, vert pour les deux hotes (le test
  verifie la presence de l'en-tete, `security-headers.spec.js`, pas chacune de ses valeurs).
- **m3 — CSP du back-office sans `base-uri` ni `form-action`** (vhost admin, `docker/apache/vhost.conf`) ;
  ces deux directives ne retombent pas sur `default-src`. La borne les posait deja (meme
  fichier, vhost kiosk). **Corrige le 2026-09-29 (commit `08d7a96`)** : les deux
  directives ajoutees au vhost admin (`base-uri 'self'`, `form-action 'self'`). Test rejoue le 2026-09-29, vert.
- **m4 — `TRACE` accepte par Apache** (200, la requete est renvoyee en echo), faute de
  `TraceEnable Off` dans `docker/apache/httpd.conf`. Les navigateurs actuels interdisent
  `TRACE` depuis une page ; non verifie derriere Traefik. **Corrige le 2026-09-29 (commit
  `08d7a96`)** : `TraceEnable Off` ajoute, les deux hotes repondent desormais 405. Test rejoue le 2026-09-29, vert.
- **m5 — Version de PHP publiee par la sonde publique** :
  `src/app/Controllers/HealthController.php:48` (`"php_version": "8.3.22"`), lisible
  anonymement, aussi par l'hote borne. Observe en production le 2026-09-29. **Corrige le
  2026-09-29 (commit `08d7a96`)** : le champ `php_version` est retire de la reponse (6 cles
  desormais : `status`, `app_env`, `db`, `categories`, `version`, `deployed_at`) ; la page
  authentifiee `/admin/health` garde ce champ pour l'exploitant. Test rejoue le 2026-09-29, vert ; la lecture de production citee ci-dessus reste anterieure au correctif.
- **m6 — `/forgot_password` sans limitation** : 30 demandes de suite pour la meme adresse
  passent (`src/app/Controllers/PasswordResetController.php:48`). Avec un SMTP configure :
  inondation de la boite visee. **Corrige le 2026-09-29 (commit `ef7fd37`)** : throttle par
  adresse ET par IP (`App\Auth\PasswordResetThrottle`, table `password_reset_throttle`,
  migration `0020_session_invalidation.sql`), seuils par defaut 5 (adresse) / 15 (IP), reponse
  429 neutre au-dela. Test rejoue le 2026-09-29, vert. Le test navigateur couvre la limite par
  adresse ; la limite par IP est prouvee par `tests/Unit/Auth/PasswordResetThrottleTest.php`.
  Complements de l'apres-midi (`680820f`, revue adversariale) : la table n'etait purgee par
  aucun cron, elle l'est desormais (`docker/cron/scripts/purge-throttle.sh`, prouve par
  `tests/shell/purge-throttle.test.sh`) ; l'adresse y etait gardee en clair, elle est desormais
  stockee en empreinte SHA-256 (migration `0021`, `PasswordResetThrottleHashMigrationDbTest`).
- **m7 — Sessions conservees apres une reinitialisation du mot de passe** :
  `PasswordResetService::confirmReset()` change le
  hash sans fermer les sessions ouvertes du compte. **Corrige le 2026-09-29 (commit
  `ef7fd37`)** : la meme instruction `UPDATE` incremente desormais `user.session_epoch` ; toute
  session ouverte avant la reinitialisation est rejetee par `SessionGuard::check()` des la
  requete suivante (migration `0020_session_invalidation.sql`). Test rejoue le 2026-09-29, vert.
- **m8 — Quantite negative ou nulle acceptee et ramenee a 1**
  (`src/app/Order/OrderRepository.php:1095`, `max(1, ...)`) : une saisie invalide passe en
  silence (le total reste calcule par le serveur). **Corrige le 2026-09-29 (commit `fce3085`,
  fusionne par `86306ef`)** : `resolveQuantity()` (l.1230) refuse toute valeur hors 1-20
  ou non entiere (`INVALID_QUANTITY`, 422) au lieu de la ramener a 1 en silence. Test rejoue le 2026-09-29, vert.
- **m9 — Corps de commande mal forme mal traite** : une ligne qui n'est pas un objet provoque
  un `TypeError` (500) (`src/app/Order/OrderRepository.php:457`) ; une cle d'idempotence
  envoyee en tableau devient la chaine `"Array"` (`src/app/Order/OrderRepository.php:397`) :
  avec `APP_DEBUG=true` l'avertissement PHP, chemin du fichier compris, part dans la reponse ;
  sinon la commande est creee sous une cle partagee par tous ceux qui envoient un tableau.
  **Corrige le 2026-09-29 (commit `fce3085`, fusionne par `86306ef`)** : une ligne
  qui n'est pas un objet est refusee (`INVALID_ITEM_TYPE`, 422, l.515) et une `idempotency_key`
  qui n'est pas une chaine est refusee (`INVALID_IDEMPOTENCY_KEY`, 422, l.439), au lieu d'un
  `TypeError` ou d'un cast silencieux. Test rejoue le 2026-09-29, vert.

### Information (hors defaut, ou dependant du deploiement)

- **IP cliente lue dans `X-Forwarded-For`** (dernier element, `src/app/Core/Request.php:290`).
  C'est ce qui permet aux tests d'isoler leurs compteurs ; en production, la valeur fiable
  suppose que Traefik est le seul point d'entree et ajoute lui-meme l'en-tete — **non verifie,
  releve de Traefik ; le verrou par compte ne depend pas de l'IP** (seul le verrou par IP,
  RG-8/9, et le verrou par IP du throttle de reinitialisation, en dependent).
- **Hote inconnu** : le premier vhost (sonde `/healthz`, `docker/apache/vhost.conf`) sert la
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
- **Residuel assume sur I1** : les bornes de quantite/lignes (I1, m8) limitent ce qu'UNE
  commande anonyme consomme, pas le nombre de commandes successives depuis la meme source.
  Une limitation par adresse IP a ete ECARTEE : plusieurs bornes reelles d'un meme restaurant
  partagent la meme adresse (elle penaliserait tout le restaurant, pas l'attaquant), et la
  fiabilite de l'IP cliente derriere le proxy releve de Traefik (non verifie, voir le point
  X-Forwarded-For ci-dessous). En production reelle, l'API kiosk serait reservee au reseau du
  restaurant ou a des bornes identifiees (hors perimetre code de ce projet) ; en
  demonstration, la remise a zero des donnees (`docs/ops/demo-reset.md`) restaure le stock,
  mais c'est une commande lancee a la main : aucune planification n'est versionnee.
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

## 8. Contre-audit du 30/09 — huit correctifs supplementaires (A-2, D-1 a D-7)

Un contre-audit independant de la documentation securite (2026-09-30) a confronte chaque
promesse au code et trouve sept defauts de code (D-1 a D-7, hors documentation) plus une
restriction manquante deja en production (A-2). Chaque correctif a ete relu par un agent qui
n'avait pas ecrit le code (deux tours de revue adversariale), avec des demonstrations dans des
conteneurs jetables (image de l'application, ou paire Apache + PHP-FPM isolee montee sur le
code de cet arbre, `--network none`, aucun conteneur `wakdo-*` touche). Chiffres de tests non
repris ici (voir la note de tete de fiche) : ils seront mis a jour avec la mesure finale.

| # | Defaut | Preuve | Statut |
|---|---|---|---|
| A-2 | `POST /api/orders/{n}/pay` (public, anonyme) repondait pour une commande de N'IMPORTE QUEL canal, pas seulement la borne | `OrderRepository::pay()` restreint desormais la lecture au canal `kiosk` pour cette route ; une commande comptoir/drive rend `ORDER_NOT_FOUND`, MEME reponse qu'un numero inconnu, AVANT toute lecture de statut. Test navigateur dedie. | corrige, approuve en revue |
| D-1 | La re-verification du mot de passe sur `/admin/profile/pin` n'etait limitee par AUCUN compteur ni tracee ; le budget mis en place ensuite se partageait avec le PIN (une action PIN reussie d'un tiers le remettait a zero) | Compte desormais sur le MEME budget que la connexion (`App\Auth\AccountLockout`, gate-before-verify) ; echec trace `auth.reauth_failed` dans la MEME transaction que l'increment. Demonstration navigateur : 4 echecs, action PIN reussie avec l'email et le PIN d'un tiers, 5e echec, bon mot de passe refuse (verrou tenu). | corrige, approuve en 2e revue |
| D-2 | Une connexion reussie remettait a zero le compteur IP (`login_throttle`) : avec un couple d'identifiants valide connu, alterner echecs et succes empechait d'atteindre le plafond IP | Seul le compteur du compte est remis a zero au succes ; la ligne IP expire avec sa fenetre glissante. Preuve base reelle : 19 echecs conserves apres un succes. | corrige, approuve |
| D-3 | Le compteur par compte s'incrementait par une LECTURE PHP suivie d'une ECRITURE, hors transaction : des tentatives paralleles pouvaient lire la meme valeur et passer toutes la porte | Increment SQL atomique (`failed_login_attempts = failed_login_attempts + 1`), relecture SOUS le verrou de ligne pris par cet UPDATE. Test a deux connexions PDO : la connexion B ouvre sa transaction et lit une valeur perimee (0), la connexion A fait un echec reel et le valide, B execute ensuite l'increment atomique — le resultat observe (2) est coherent avec [CLAIM L2, manuel de reference MySQL/MariaDB, « Consistent Nonlocking Reads »] : un `UPDATE` lit la derniere valeur validee, pas l'instantane de la transaction qui l'execute. | corrige, approuve |
| D-4 | `user.update`/`user.create` ne verifiaient que l'existence du role vise (`activeRoleExists()`), pas ses permissions : un role personnalise dote de `user.update` pouvait s'affecter le role `admin` | Un acteur sans `role.manage` ne peut affecter un role, ni agir sur un compte dont le role deborde le sien, QUE si les permissions du role vise sont toutes incluses dans les siennes (verifie avant la demande de PIN, y compris pour un role vise desactive). Aucun role du jeu de demonstration n'est concerne (seul `admin` porte `user.*` au seed). Limite residuelle documentee (`docs/domaines/users.md`, `docs/domaines/rbac.md`, R8) : la portee des sources de commande visibles n'est pas comparee par cette garde. | corrige, approuve (limite documentee) |
| D-5 | Le verrou de session PHP restait tenu pendant l'envoi SMTP DIFFERE du courriel de reinitialisation : une 2e requete sur le meme cookie servait d'oracle de temps (le canal par le temps que ce lot devait fermer revenait, avec une requete de plus) | `DeferredActions::finishRequest()` ferme la session (`session_write_close()`) AVANT `fastcgi_finish_request()`, pas apres. Preuve en conteneur jetable : la requete B attend 0,00 s contre l'ancien ordre (2,31 a 2,50 s selon la mesure). Aucune ecriture de session apres la fermeture (jeton CSRF, flash, regeneration : tous poses avant `send()`). | corrige, approuve |
| D-6 | `default_route` (page d'accueil d'un role apres connexion) n'etait bornee qu'en LONGUEUR (120 caracteres), pas en forme : une valeur `https://...`/`//...` aurait pu rediriger tous les comptes du role hors du site | `RedirectPath::isLocal()` n'accepte qu'un chemin commencant par un seul `/`, sans schema. Message d'ecran clair (« Page d'accueil apres connexion invalide : choisissez une page de la liste. »). | corrige, approuve |
| D-7 | Le lien `GET /reset_password?token=<jeton brut>` etait journalise en clair par le format `combined` d'Apache (ligne de requete complete, query string comprise) | Format de journal dedie sans chaine de requete pour cette seule route (`combined_no_query`), et l'application pose `Referrer-Policy: no-referrer` sur les reponses de `/reset_password` (`PasswordResetController::renderConfirm()`) pour empecher le jeton de fuiter par l'en-tete `Referer` des ressources que la page charge. Preuve en conteneur jetable (paire Apache + PHP-FPM) : journal d'acces sans jeton ni `Referer` ; test navigateur Chromium sensible (avec l'ancienne politique, la feuille de style et le POST portaient bien `?token=...` en `Referer`). | corrige pour le jeton, approuve avec reserve |

### Reserve sur D-7 : regression de `Referrer-Policy` sur les reponses servies par Apache seul

Poser `no-referrer` cote application pour `/reset_password` a eu un effet de bord : avant ce
correctif, `Referrer-Policy: strict-origin-when-cross-origin` etait posee au niveau serveur
Apache pour les reponses des deux hotes. Depuis, sur l'hote admin, cet en-tete n'est plus pose
par Apache ; il ne l'est plus que par l'application (`App\Core\Response::send()`), qui ne
s'execute que pour une reponse PHP. Les fichiers vraiment statiques (`/assets/...`) et les
erreurs produites par Apache lui-meme (403 des chemins interdits, 502/503/504 quand PHP-FPM ne
repond pas) sur l'hote admin n'ont donc plus cet en-tete, a la date de cette redaction
(2026-09-30). Impact reel limite (un navigateur conforme applique deja
`strict-origin-when-cross-origin` par defaut en l'absence d'en-tete, et ces reponses ne
chargent aucune ressource), mais c'est une regression mesurable par un scanner d'en-tetes, et
`security-headers.spec.js` ne teste actuellement que des reponses PHP cote admin — la
regression y est invisible a cette suite. Reste a faire (config Apache du vhost admin, hors du
perimetre de cette redaction documentaire) : reposer l'en-tete par defaut au niveau du vhost
admin, CONDITIONNE a son absence (pour ne pas dupliquer la valeur `no-referrer` posee par PHP
sur `/reset_password`), et ajouter une ressource statique admin plus une erreur Apache aux
cibles de `security-headers.spec.js`.

**Methode de revue** : deux tours de relecture adversariale par un agent distinct de celui qui
a ecrit chaque correctif, rejeu de la suite PHPUnit ciblee sur une base jetable a chaque tour,
et une demonstration executee (pas seulement lue) pour chaque defaut a effet observable (verrou
de session, journal Apache, navigateur Chromium). Rien de cette section ne repose sur une
lecture de la production ou d'un secret.
