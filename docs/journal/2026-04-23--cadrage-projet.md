# Cadrage projet et bootstrap Git

**Date** : 2026-04-23
**Branche** : `main` (commit initial), puis `dev` cree
**PR** : commit direct (bootstrap initial avant mise en place des protections)
**Duree estimee** : ~4h

---

## Ce qui a ete fait

1. **Lecture integrale du referentiel RNCP 37805** (20 pages) pour les Blocs 1, 2 et 5 (option DevOps). Extraction manuelle de chaque critere et sous-critere avec leur libelle officiel.
2. **Redaction du document `docs/PROJECT_CONTEXT.md`** (17 sections, ~560 lignes) servant de source de verite unique pour le projet. Il contient notamment :
   - Le scope metier (Wakdo = borne de commande pastiche McDonald's)
   - La stack technique lockee
   - L'architecture 2 FQDN
   - Le mapping critere RNCP -> feature livree
   - Le planning heures detaille (260h budgetees)
   - Les risques et mitigations
   - Les 10 regles invariantes
3. **Mise en place de la configuration Claude Code / BYAN** (`.claude/CLAUDE.md` + `.claude/rules/*.md`) qui documente la methodologie appliquee au projet (Merise Agile, fact-check scientifique, ELO trust, conventions commits).
4. **Securite SSH** :
   - Un Personal Access Token GitHub a ete accidentellement expose dans un chat. Il a ete immediatement revoque.
   - Generation d'une cle SSH ED25519 dediee (`~/.ssh/git_wakdo`, passphrase-protected).
   - Configuration d'un alias SSH `github-wakdo` dans `~/.ssh/config`.
5. **Initialisation Git** :
   - `git init -b main`
   - Configuration locale (`user.name`, `user.email`)
   - Creation du `.gitignore` avec une strategie ciblee (voir ci-dessous)
   - Premier commit `6f87314` (renomme ensuite en `c044d9b`) : 9 fichiers, 1209 lignes
   - Creation de la branche `dev` depuis `main`
   - Remote `origin` pointant sur `git@github-wakdo:AcadeNice/wakdo_corentin.git`
   - Push initial de `main` et `dev`
6. **Configuration des branch protections GitHub** (rulesets) sur `main` et `dev` :
   - PR requise avant merge
   - Force push bloque (sauf bypass admin explicite)
   - Suppressions restreintes
   - Resolution des conversations requise
   - Bypass `Repository admin` configure pour ne pas me bloquer moi-meme

---

## Pourquoi — decisions et alternatives

### 1. Strategie B (codebase unifie) vs Strategie A (rendus isoles)

- **Decision** : un seul codebase qui heberge le front Bloc 1, le back Bloc 2 et la stack DevOps Bloc 5.
- **Alternative consideree** : deux codebases totalement isoles, un par bloc, pour garantir la note bloc par bloc.
- **Raison du choix** : le critere Cr 7.c.4 du Bloc 5 exige qu'une seule commande (`make init`) lance la stack complete. Avoir deux codebases separes serait contradictoire avec cette exigence, et multiplierait les points de divergence entre front et back pendant le developpement. La strategie B demande un peu plus de rigueur sur l'isolation (le front doit pouvoir fonctionner en fallback JSON si l'API est down), mais elle est plus elegante et plus defendable.

### 2. Pas de framework PHP (ni Laravel ni Symfony)

- **Decision** : PHP 8.3 "from scratch" avec autoloader manuel PSR-4, PDO natif, pas de Composer.
- **Alternative consideree** : Symfony ou Laravel pour aller plus vite.
- **Raison du choix** : le sujet du Bloc 2 impose explicitement un developpement "from scratch" pour demontrer la maitrise des fondamentaux (heritage, namespaces, MVC). Utiliser un framework reviendrait a cacher ces fondamentaux derriere des abstractions, et serait mal vu par le jury qui veut **justement** tester ces competences brutes.

### 3. PHPUnit via `.phar` autonome (sans Composer)

- **Decision** : telecharger le binaire PHPUnit en `.phar` et l'executer directement.
- **Alternative consideree** : installer PHPUnit via Composer avec un autoloader auto-genere.
- **Raison du choix** : coherence avec la decision precedente. Le `.phar` est auto-contenu, n'introduit pas de `vendor/` dans le repo, et reste defendable. Le critere Cr 4.g.2 demande "des tests unitaires fonctionnels" — il ne specifie pas l'outil d'installation. Source : docs officielles PHPUnit section "Installing PHPUnit as a PHAR".

### 4. Alpine pour tous les conteneurs

- **Decision** : `php:8.3-fpm-alpine`, `httpd:alpine`, `mariadb:11` (officielle).
- **Alternative consideree** : `debian-slim` qui est plus standard mais plus lourd.
- **Raison du choix** : experience d'admin sys de l'auteur, images legeres (~80 MB vs ~300 MB), surface d'attaque reduite. Alpine utilise `musl libc` au lieu de `glibc`, ce qui peut poser des soucis pour certaines extensions PHP compilees — a surveiller pendant le dev.

### 5. Traefik comme reverse proxy (reutilise, non reconstruit)

- **Decision** : exposer la stack a un Traefik deja existant sur le serveur via un reseau Docker externe `admin_proxy`.
- **Alternative consideree** : inclure Traefik dans le `docker-compose.yml` du projet.
- **Raison du choix** : le Traefik existant gere deja les certificats Let's Encrypt pour d'autres projets du serveur. Inclure un second Traefik creerait un conflit sur les ports 80/443. Utiliser le reseau `admin_proxy` en mode `external: true` est la convention etablie.

### 6. Transparence methodologie IA — Option C

- **Decision** : committer la methodologie BYAN/Claude (`.claude/CLAUDE.md` + `.claude/rules/`) dans le repo, mais **pas** le moteur (`_byan/` gitignore).
- **Alternative consideree** : tout masquer (silencieux) ou tout committer (lourd et pollue).
- **Raison du choix** : le centre Acadenice valide explicitement l'usage d'IA assistee. Masquer la methodologie serait malhonnete face au jury. Committer le moteur entier polluerait le repo avec des milliers de fichiers non pertinents. La solution mediane documente la demarche et reste factuelle.

### 7. Aucun Co-Authored-By AI sur les commits

- **Decision** : les commits ne portent pas de tag `Co-Authored-By: Claude...`.
- **Alternative consideree** : tagger tous les commits AI-assistes, ou seulement les majeurs.
- **Raison du choix** : le projet peut utiliser plusieurs outils IA differents au cours de son cycle de vie. Un tag fige sur un modele specifique serait trompeur. De plus, des commits ecrits intentionnellement sans assistance (ex : dictionnaire de donnees reflechi seul) porteraient faussement le tag par defaut. La transparence de la methodologie vit dans le README et les `.claude/rules/`, pas dans les metadonnees git.

---

## Comment — points techniques cles

### Strategie du `.gitignore`

Trois zones distinctes :

```gitignore
# Moteur BYAN — masque (pas pertinent jury)
_byan/
_byan-output/

# Claude Code — methodologie visible, reste masque
.claude/*
!.claude/CLAUDE.md
!.claude/rules/

# Secrets et artefacts
.env
vendor/
/logs/
/backups/
```

Le pattern `.claude/*` + `!.claude/CLAUDE.md` + `!.claude/rules/` utilise la negation d'ignore git pour re-inclure selectivement deux chemins. Cette syntaxe fonctionne tant que le chemin parent n'est pas lui-meme ignore en tant que dossier complet (ce qui serait `.claude/` sans slash). Voir docs git-scm.com section "gitignore - Specifying files".

### Alias SSH GitHub dedie

Un `~/.ssh/config` avec :

```
Host github-wakdo
    HostName github.com
    User git
    IdentityFile ~/.ssh/git_wakdo
    IdentitiesOnly yes
```

L'interet est double :
1. Permet de pointer vers un compte GitHub specifique sur une machine qui en a plusieurs
2. L'URL du remote devient `git@github-wakdo:AcadeNice/wakdo_corentin.git` (notez le `github-wakdo` au lieu de `github.com`), ce qui rend explicite quelle cle utiliser

`IdentitiesOnly yes` empeche SSH de tenter toutes les cles chargees dans l'agent et de se faire banner par GitHub apres plusieurs echecs.

### Branch protections via rulesets

Configure via l'UI GitHub (Settings -> Rules -> Rulesets). Les 4 rules activees :

- `Restrict deletions` : les branches `main` et `dev` ne peuvent etre supprimees
- `Block force pushes` : pas de reecriture d'historique pushed
- `Require a pull request before merging` : force le passage par une PR
- `Require conversation resolution before merging` : toutes les discussions de review doivent etre closes

`Repository admin` est dans la liste bypass : permet a l'auteur de debloquer des situations exceptionnelles (comme le force-push initial pour retirer le Co-Authored-By) sans avoir a desactiver le ruleset.

---

## Criteres RNCP couverts

- **Bloc 2 - Cr 4.f.2** : Maitrise de l'outil collaboratif — Git et GitHub utilises, repo public accessible au jury, branches `main` et `dev` protegees, flow `feat/*` -> `dev` -> `main` impose, Conventional Commits definis dans `docs/PROJECT_CONTEXT.md` section 9 (a faire appliquer par hook en Task #3).

> Note : le libelle officiel du Cr 4.f.1 (*« mobilise et transmet son savoir, participe activement a la collaboration »*) et du Cr 4.f.4 (*« rendre compte de sa participation individuelle au travail collectif »*) designent des soft skills evaluees a l'oral, et non la mise en place de Git — qui releve du Cr 4.f.2. Cette correction a ete appliquee apres lecture integrale du referentiel (source : `docs/_ref/rncp-37805-referentiel.pdf` page 11-12).
- **Bloc 5 - Cr 7.a.1** : Analyse de l'infra cible documentee (section 5 du PC).
- **Bloc 5 - Cr 7.d.1** : Architecture serveur decrite (reseau `admin_proxy` + reseau interne + 4 services).

---

## Questions anticipees du jury

- **Q** : "Pourquoi un PROJECT_CONTEXT de 560 lignes avant d'avoir ecrit une ligne de code ?"
  **R** : Parce que c'est un projet solo de 240h sur 20 semaines. Sans plan ecrit, chaque decision differee devient une source de dette (indecisions re-ouvertes, scope qui drift). Le PC me sert de garde-fou et de support d'argumentation devant le jury.

- **Q** : "Vous avez utilise une IA pour rediger ce document, est-ce conforme ?"
  **R** : Oui, le centre Acadenice autorise l'usage d'IA assistee. Je le declare ouvertement dans le README (a venir) et la methodologie est commitee dans `.claude/`. Les decisions sont **mes** decisions, meme si la redaction est IA-assistee. Je peux defendre chaque choix oralement.

- **Q** : "Pourquoi deux FQDN au lieu d'un seul avec des routes differentes ?"
  **R** : Separation de securite et de concerns. Le front public et le back-office ont des profils d'exposition opposes : le premier est large, le second est administrateur. Deux FQDN permettent des politiques TLS, CORS et firewall distinctes. C'est plus proche de la realite production.

- **Q** : "Vous avez fait fuiter un PAT GitHub dans un chat. Comment evitez-vous que ca se reproduise ?"
  **R** : Revocation immediate, migration sur SSH (cle + alias dedie, passphrase obligatoire). Les tokens n'existent plus dans mon workflow pour ce projet. Un hook pre-commit de detection de secrets est prevu en Task #3 pour ajouter une defense en profondeur.

- **Q** : "Pourquoi n'avez-vous pas utilise de `Co-Authored-By` pour tracer l'usage IA ?"
  **R** : Parce qu'au fil du projet j'utiliserai potentiellement plusieurs modeles et plusieurs outils IA. Figer un tag sur un modele specifique serait trompeur. De plus, des commits ecrits sans assistance porteraient le tag par defaut — c'est encore plus trompeur. Je documente la methodologie globale dans le README, ce qui est plus honnete qu'une metadonnee de commit.

---

## Points d'amelioration conscients

- **Le `docs/PROJECT_CONTEXT.md` est long.** Si un jury le lit lineairement il risque de decrocher. Je prevois de le garder comme document interne (reference) et de produire un `README.md` synthetique pour le rendu final qui pointe vers les sections utiles.

- **Aucun test automatise encore.** Normal a ce stade (phase P0 selon le planning), mais a surveiller : des que l'autoloader et le Router existent, les tests unitaires doivent suivre **immediatement** et pas en fin de projet.

- **Pas de CI GitHub Actions.** Prevue en Task #5 (apres l'arborescence). Un push sur `dev` ne verifie rien aujourd'hui, donc les protections de branche sont partiellement theoriques.

- **Pas de secret scanner.** Un `git-secrets` ou equivalent dans le pre-commit hook aurait pu detecter la fuite du PAT. A ajouter en Task #3 quand on installe les hooks.

---

## Liens vers artefacts

- Commit initial : `c044d9b` (avant amend : `6f87314`, retire pour nettoyer le Co-Authored-By)
- Fichiers cles : `docs/PROJECT_CONTEXT.md`, `.claude/CLAUDE.md`, `.claude/rules/*.md`, `.gitignore`
- Documentation associee : voir directement `docs/PROJECT_CONTEXT.md` section 10 pour le recap des decisions verrouilles
