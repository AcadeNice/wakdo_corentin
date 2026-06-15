# P3 securite — throttle du PIN d'action sensible (RG-T22), design multi-agents + verification adversariale

**Date** : 2026-06-15 (suite de la session CRUD Produits #17)
**Branche** : working tree sur `dev` (chunk non commite ; base `dev` = `2756fb4`)
**PR** : ouverte vers `dev` apres revue de l'implementation (auto-merge sur CI verte)
**Duree estimee** : session longue (finalisation + merge Produits, puis design + build + docs Merise du throttle)

---

## Ce qui a ete fait

Deux temps dans la session.

### 1. Finalisation et merge du CRUD Produits (PR #17)

Le CRUD produits (cas riche : `price_cents`, `vat_rate {55,100}`, `category_id`, suppression FK-safe)
a ete termine, revu (6 findings : 1 HIGH, 1 LOW, 4 MEDIUM de couverture), corrige, puis merge sur `dev`
en auto-merge sur CI verte (squash, `dev` = `2756fb4`). La revue avait remonte un finding **HIGH** : le PIN
d'action sensible (`PinVerifier`) verifie le PIN avec parite de timing mais **sans limitation de
tentatives**. Mitigation shippee dans #17 : chaque echec ecrit une ligne `audit_log` `pin.failed`
(detectable). Le throttle complet a ete arbitre comme chunk dedie — ce qui suit.

### 2. Throttle du PIN (RG-T22) — conception puis construction

**Conception via un panel multi-agents** (3 lentilles independantes : Ockham / efficacite-menace /
anti-DoS) -> synthese -> passe adversariale. Le panel a tranche la **dimension** du compteur et a integre
deux correctifs d'emblee. Verdict de l'adversaire : la conception tient (`holds = true`).

Artefacts produits (tous dans le working tree, **non commites**) :

- `db/migrations/0002_pin_throttle.sql` — nouvelle table (entite 22), cle sur `actor_user_id`
  (UNIQUE, FK -> `user` ON DELETE CASCADE), separee des compteurs de connexion. **Appliquee a la base
  dev** via `bash db/migrate.sh`.
- `src/app/Auth/ThrottlePolicy.php` — dimension `'pin'` ajoutee a `fromConfig` (bornes propres
  `PIN_THROTTLE_*` : base 30s, plafond 300s).
- `src/app/Auth/PinThrottle.php` (nouveau) — `isLocked` / `recordFailure` (upsert atomique + backoff,
  une transaction) / `reset`.
- `src/app/Auth/PinVerifier.php` — methode additive `payTimingDecoy` (parite de timing du chemin
  verrouille).
- `src/app/Controllers/ProductController.php` — cablage dans `update` (branche prix/TVA) et `destroy` :
  gate avant verification, `recordFailure` sur PIN faux, `reset` apres l'effet reussi.
- Config : `.env.example` + `docker-compose.yml` (`PIN_THROTTLE_THRESHOLD/BASE/MAX/WINDOW`).
- Docs Merise portees de 21 a 22 entites : RG-T22 dans `mlt.md`, entite 22 `pin_throttle` dans
  `mcd.md` / `mld.md` / `dictionary.md`, couverture MCT 22/22 dans `mct.md`.
- Tests : +16 (dimension `pin` de `ThrottlePolicy` ; `PinThrottleTest` ; cas de controleur ; leurre de
  timing ; integration `PinThrottleDbTest`). **188 tests / 525 assertions verts, PHPStan L6 propre.**

---

## Pourquoi — decisions et alternatives

### Decision 1 — Compter les echecs par utilisateur AGISSANT (et non par email cible ni par IP)

- **Decision** : la dimension du throttle est l'identite de session authentifiee qui realise l'action
  (`$guard->userId`), stockee dans une table dediee `pin_throttle` cle sur `actor_user_id`.
- **Alternatives considerees** :
  - *par email cible* : contournable par rotation des emails (le modele "identifiant equipier + PIN"
    verifie un email arbitraire) ;
  - *par IP* : sur un poste a session partagee, tous les equipiers sortent par la meme IP ; un verrou IP
    priverait de re-autorisation l'ensemble des equipiers honnetes du comptoir ;
  - *hybride cible + IP avec delai `usleep`* : ajoute une colonne de portee, ~6 cles de config, un `usleep`
    qui retient un worker PHP-FPM, et une surface de blocage d'un collegue ;
  - *globale* : un seul attaquant degraderait l'autorisation sensible de tout le magasin.
- **Raison du choix** : la cle "acteur" est la seule non-contournable (changer d'acteur impose une
  reconnexion, elle-meme throttlee et auditee cote login) ET sans collateral sur un poste partage
  (verrouiller l'attaquant n'affecte aucun autre `user_id`). Elle dissout la tension rotation/collateral
  qui force les autres pistes a un delai par IP. Rasoir d'Ockham (#37) : une table, un collaborateur, deux
  points d'appel, `PinVerifier` inchange.

### Decision 2 — Table dediee, separee des compteurs de connexion

- **Decision** : compteurs `pin_throttle` physiquement distincts de `user.failed_login_attempts` /
  `user.lockout_until` / `login_throttle`.
- **Alternative** : reutiliser les colonnes de login existantes.
- **Raison** : un echec de PIN n'incremente aucun compteur de login ; sinon, marteler le PIN d'une victime
  verrouillerait sa connexion (escalade de deni de service vers une surface plus sensible). Un test de
  regression verifie l'absence d'ecriture vers `user`/`login_throttle` sur le chemin d'echec.

### Decision 3 — Backoff plus permissif que le login

- **Decision** : base 30s, plafond 300s (le login est a 60s / 900s).
- **Raison** : RG-T13 cadre le PIN comme un controle de dissuasion (risque residuel Faible) ; un faux
  positif bloque un manager en plein rush. Le backoff reste degressif, pas un verrou definitif.

### Decision 4 — Correctifs adversariaux integres a la conception (pas en second passage)

- **Anti-flood de l'audit** : sous verrou actif, aucune nouvelle ligne `pin.failed` (les echecs ayant
  arme le verrou sont deja audites) — sinon le chemin verrouille, moins couteux, gonflerait le journal
  append-only et noierait l'alerte de volume.
- **Parite de timing** : `payTimingDecoy` paie le cout argon2id sur le chemin verrouille, pour que la
  latence ne distingue pas "verrouille" de "mauvais PIN".

### Methodo — pourquoi un panel + une passe adversariale

Challenge Before Confirm (mantra IA-16) sur un finding de severite HIGH avec migration de schema (peu
reversible) : faire produire trois conceptions independantes, les arbitrer, puis tenter de casser la
retenue. La passe adversariale a confirme que les quatre attaques visees (rotation d'email, falsification
de `X-Forwarded-For`, contamination du compteur de login, collateral de borne partagee) echouent par
construction, et a remonte les deux correctifs ci-dessus.

---

## Comment — points techniques cles

- **Upsert atomique, miroir de la dimension IP d'`AuthService`** : `INSERT ... ON DUPLICATE KEY UPDATE
  failed_attempts = IF(window_started_at < :cutoff, 1, failed_attempts + 1) ...`. L'increment est calcule
  cote SQL sous le verrou de ligne pris sur la cle UNIQUE, ce qui serialise les POST concurrents (anti
  lost-update). Placeholders nommes distincts car `PDO::ATTR_EMULATE_PREPARES = false` interdit de lier un
  meme nom deux fois (`src/app/Auth/PinThrottle.php`).
- **Gate-before-verify** : `isLocked($actorId)` est evalue AVANT `resolveActingUser`. Un acteur verrouille
  recoit le meme 422 generique "Email ou PIN invalide" (anti-enumeration) ; meme un PIN correct est bloque
  tant que le verrou court.
- **Le piege du `reset`** : a un succes, deux identites sont en portee — l'acteur de session
  (`$guard->userId`, celui qui a ete incremente) et l'equipier resolu par le PIN (`$actor['id']`, ecrit
  dans `audit_log`). Le `reset` cible l'acteur de **session** ; le confondre laisserait le compteur de
  l'agissant sans purge. Un test l'asserte explicitement (`ProductControllerTest`).
- **FK ON DELETE CASCADE** (contrairement a `login_throttle`, sans FK) : la cle est un utilisateur
  back-office authentifie, donc supprimer/anonymiser le compte retire proprement sa ligne de throttle
  (etat ephemere, par opposition a `audit_log` qui est permanent et en SET NULL).

---

## Criteres RNCP couverts

- **Bloc 2 - Cr 3.a / 3.b** : extension du modele Merise (dictionnaire/MCD/MLD) — entite 22 `pin_throttle`,
  FK et cardinalite (assoc R9), coherence 22/22 verifiee dans les quatre docs.
- **Bloc 2 - Cr 4.e (securite)** : requetes preparees (anti-injection), reponse generique
  (anti-enumeration), separation dure des compteurs (anti escalade de DoS), gate avant verification.
- **Bloc 2 - Cr 4.c (POO / namespaces)** : `PinThrottle` (classe dediee), reutilisation de `ThrottlePolicy`
  (math pure), cablage via les controleurs heritant d'`AdminController`.
- **Bloc 2 - Cr 4.g (preparation livraison)** : 188 tests PHPUnit verts, PHPStan niveau 6 propre, test
  d'integration contre une vraie MariaDB.
- **Bloc 2 - Cr 3.d (RGPD)** : FK ON DELETE CASCADE (l'etat de throttle suit l'anonymisation du compte) et
  purge cron documentee (minimisation / limitation de conservation).
- **Bloc 5 - Cr 7.b.3 (cron) / Cr 7.d.2 (tests avant deploiement)** : predicat de purge `pin_throttle`
  aligne sur `login_throttle` ; le chunk passera la CI (PHPUnit + PHPStan + secret-scan) avant merge.

---

## Questions anticipees du jury

- **Q** : "Pourquoi compter les echecs de PIN sur l'utilisateur agissant plutot que sur l'IP, comme pour le login ?"
  **R** : Sur une borne a session partagee, tous les equipiers sortent par la meme IP ; un verrou par IP
  les priverait tous de re-autorisation. La cle "acteur" verrouille seulement l'individu qui multiplie les
  echecs, sans toucher ses collegues, et reste non-contournable (changer d'acteur impose une reconnexion,
  deja throttlee cote login).

- **Q** : "Un attaquant qui martele le PIN d'un collegue peut-il bloquer sa connexion ?"
  **R** : Non. Les compteurs du PIN vivent dans une table separee (`pin_throttle`), distincte de
  `user.failed_login_attempts` et de `login_throttle`. Un echec de PIN n'ecrit aucun compteur de login ;
  un test de regression le verifie.

- **Q** : "Pourquoi un backoff degressif et pas un verrou definitif ?"
  **R** : Le PIN est un controle de dissuasion a risque residuel Faible ; un verrou dur bloquerait un
  manager sur quelques fautes de frappe en plein service. Le backoff ralentit la force brute (de quelques
  essais a une poignee par fenetre) tout en s'auto-resorbant.

- **Q** : "Comment avez-vous valide cette conception de securite ?"
  **R** : Trois conceptions independantes ont ete produites puis arbitrees, et une passe adversariale a
  tente de casser la retenue (rotation d'email, falsification d'en-tete proxy, contamination du login,
  collateral de borne). Les quatre echouent par construction ; la passe a aussi remonte deux correctifs
  (anti-flood de l'audit, parite de timing) integres avant la fin.

- **Q** : "Pourquoi ajouter une 22e table plutot que des colonnes sur `user` ?"
  **R** : Des colonnes sur `user` devraient porter sur l'utilisateur cible (contournable par rotation) ou
  ajouter une 4e dimension de verrou sur la table de comptes. Une table dediee, cle sur l'acteur, garde
  `user` epuree et garantit la separation des compteurs par construction.

---

## Points d'amelioration conscients

- **Couverture CI de l'increment SQL** : les tests unitaires stubbent le compteur relu apres l'upsert
  (`FakeDatabase.pinThrottleAttempts` fixe), donc la semantique reelle de l'increment + fenetre glissante
  n'est prouvee que par `PinThrottleDbTest` (integration), auto-skippee sans MariaDB. C'est la posture
  STANDARD du projet (CI sans Composer ni base : `AuthServiceDbTest`, `PinVerifierDbTest`... skippent de
  meme) ; verifiee en local avec `WAKDO_DB_TESTS=1`. A garder en tete si la CI gagne un service DB.
- **Cron de purge non encore etendu** : le predicat de purge `pin_throttle` est documente (`mlt.md` 13.5)
  mais le job cron lui-meme (`docker/cron`) n'a pas ete edite. Sans impact fonctionnel (la table tient une
  ligne par utilisateur back-office) ; a brancher avec le job `login_throttle` existant.
- **Dimension par IP volontairement absente** : choix documente (collateral de borne partagee). A
  reconsiderer seulement si un abus par IP est observe en pratique.
- **Detection** : l'alerte sur le volume de `pin.failed` est le vrai controle detectif ; elle reste a
  outiller cote supervision (hors code applicatif). Un PIN de plus de 4 chiffres pour les roles sensibles
  est recommande.

---

## Etat a la reprise

- Chunk throttle PIN complet (source + tests + migration + docs Merise + `.env.example` + compose + ce
  journal), vert (188 tests, PHPStan L6), revue adversariale de l'implementation passee (`holds = true`),
  commite et pousse cette session avec PR vers `dev` (auto-merge sur CI verte). Migration `0002` deja
  appliquee a la base dev.
- **Prochaine action** : suite P3 : Menus (+ slots), Ingredients/stock, Users + matrice RBAC, Stats.
  Differe : etendre le cron de purge a `pin_throttle` ; alerte de volume `pin.failed` (supervision).

---

## Liens vers artefacts

- CRUD Produits merge : commit `49ab77b` -> `dev` `2756fb4` (PR #17, squash).
- Throttle PIN (non commite) : `src/app/Auth/PinThrottle.php`, `src/app/Auth/ThrottlePolicy.php`,
  `src/app/Auth/PinVerifier.php`, `src/app/Controllers/ProductController.php`,
  `db/migrations/0002_pin_throttle.sql`.
- Tests : `tests/Unit/Auth/PinThrottleTest.php`, `tests/Unit/Auth/ThrottlePolicyTest.php`,
  `tests/Unit/Admin/ProductControllerTest.php`, `tests/Integration/PinThrottleDbTest.php`,
  `tests/Support/FakeDatabase.php`.
- Docs Merise (RG-T22, entite 22) : `docs/merise/{mlt,mcd,mld,dictionary,mct}.md`.
- Config : `.env.example`, `docker-compose.yml` (`PIN_THROTTLE_*`).
- Resume roulant : `docs/SESSION_RESUME.md` (entree Produits #17 = suite 4).
