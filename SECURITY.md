# Politique de securite - Wakdo

Wakdo est un projet de fin de formation (RNCP 37805) construit en
**security-by-design** : la menace est modelisee avant le code. Ce document
resume la posture, le signalement de vulnerabilites et les garde-fous CI.

## Modele de menace

Le modele STRIDE complet, le registre des risques et la classification des
donnees (4 niveaux) vivent dans `docs/PROJECT_CONTEXT.md` section 19, et le flux
d'une action sensible durci par PIN (annulation de commande) dans
`docs/uml/security-sequence.md`.

## Mesures en place (resume)

| Domaine | Mesure |
|---|---|
| Mots de passe | `password_hash` argon2id (cout configurable, defauts OWASP) |
| Actions sensibles | PIN equipier hashe argon2id (`pin_hash`) |
| Brute-force | double throttle sur la connexion : compteur par compte (`user.failed_login_attempts`/`lockout_until`) + par IP (`login_throttle`), backoff degressif. La re-verification du mot de passe sur `/admin/profile/pin` compte sur le MEME budget que le compte (corrige le 2026-09-30) : une action PIN reussie avec l'identifiant d'un tiers ne remet plus ce compteur a zero, et une connexion reussie ne remet plus a zero le compteur IP. Voir « Limites connues » ci-dessous. |
| Sessions | cookies `HttpOnly` + `SameSite=Strict` inconditionnels, `Secure` des que la requete arrive en HTTPS (`X-Forwarded-Proto`, sinon `HTTPS`, sinon le port 443 — ADR-0010 ; pose en production), regeneration d'ID a la connexion (anti-fixation), idle 4h / absolu 10h |
| Injection | PDO prepared statements exclusivement |
| Upload | implemente et teste (`App\Core\ImageUploader`) : type reel detecte cote serveur (finfo + getimagesize, pas l'extension ni le type annonce par le navigateur), nom de fichier regenere, taille/formats regles par l'environnement, stockage dans `public/uploads` (voisin des racines web, non execute directement) — correction du 2026-09-24, cette ligne annoncait a tort l'upload comme non implemente |
| En-tetes / PHP | `expose_php=Off`, `allow_url_fopen/include=Off`, `cgi.fix_pathinfo=0`, fonctions d'execution systeme desactivees |
| RGPD | retention limitee : journal d'audit ~12 mois et compteurs de tentatives (connexion/PIN/reinitialisation) 24h, purges planifiees (`docker/cron/scripts/purge-audit-log.sh`, `purge-throttle.sh`) ; conservation des commandes NON bornee a ce jour (`ORDER_RETENTION_DAYS` declare dans `.env.example`, pas encore cable a une purge, voir ADR-0014) ; droit de consultation, modification, effacement par anonymisation (ADR-0007) |
| Secrets | `.env` gitignore, tenu hors de `.git/config` (credential helper lisant `.env`), secret-scan gitleaks en CI |

Les seuils operationnels (couts argon2, lockout, throttle, retention) sont
documentes dans `.env.example`.

## Limites connues

Ce que le code et les revues adverses etablissent, sans en dire plus :

- **Verrou par compte partage entre connexion et re-verification du mot de passe**
  (depuis le correctif du 30/09) : sur un poste partage dont la session reste ouverte,
  un collegue qui accumule des echecs sur `/admin/profile/pin` peut faire verrouiller la
  CONNEXION du titulaire de la session. Ce n'est pas une regression : le meme resultat
  etait deja possible depuis `/login`, avec la seule adresse du titulaire (connue sur un
  poste de restaurant partage) ; le nombre total de mots de passe essayables contre ce
  compte est identique par les deux voies, et chaque essai laisse une ligne `audit_log`.
- **Compteur IP partage derriere un NAT de restaurant** : le verrou par IP (`login_throttle`,
  20 echecs / 15 min) compte par adresse IP source, pas par poste. Plusieurs equipiers
  d'un meme restaurant, derriere le meme routeur, partagent en pratique une seule IP :
  des echecs repetes sur UN poste peuvent ralentir la connexion des autres. `IP_THROTTLE_MAX_ATTEMPTS`
  est une variable d'environnement, ajustable par site.
- **Fiabilite de l'IP cliente non verifiee ici** : `Request::clientIp()` lit `X-Forwarded-For`
  (dernier maillon), ce qui suppose que le reverse proxy (Traefik, hors perimetre de ce
  depot) est le seul point d'entree et pose lui-meme cet en-tete correctement. Non relu
  cote infrastructure par cet audit.
- **SMTP** : si `SMTP_HOST`/`SMTP_USER`/`SMTP_PASSWORD` ne sont pas definis, l'envoi du lien
  de reinitialisation de mot de passe n'est PAS silencieux : `App\Auth\LogMailer` ecrit le
  lien complet, jeton compris, dans le journal PHP (`error_log`) — c'est ce que les tests
  navigateur exploitent pour rejouer le lien sans serveur mail. La presence effective d'un
  relais SMTP en production (secret `.env`) n'est pas verifiee par cet audit ; sans lui, ce
  journal PHP est le canal de facto de diffusion du jeton et doit etre protege en
  consequence.
- **Canal par le temps sur la demande de reinitialisation** : pour une adresse connue, l'envoi
  du courriel se fait dans la requete ; pour une adresse inconnue, seul un leurre de calcul
  s'execute. La difference de duree n'a pas ete mesuree de maniere reproductible depuis ce
  depot ; le verrou de session PHP tenu pendant l'envoi est ferme avant l'envoi differe
  depuis le 2026-09-30 (`DeferredActions::finishRequest()`), ce qui evite qu'une deuxieme
  requete serve d'oracle de temps sur la meme session.

## Garde-fous CI (Forgejo Actions)

Chaque PR vers `dev` ou `main` declenche `.forgejo/workflows/ci.yml`, cinq travaux :

- **secret-scan** (gitleaks) : empeche un secret d'entrer dans l'historique
- **php-lint** : `php -l` sur tous les fichiers PHP
- **static-tests** : PHPStan + PHPUnit
- **js-tests** : tests de la borne (node:test + jsdom)
- **shell-tests** : fonctions pures du filet instantane/remise a zero de la demo

La protection des branches `main` et `dev` exige quatre de ces cinq travaux
(`secret-scan`, `php-lint`, `static-tests`, `js-tests`) ; `shell-tests` n'est pas
exige. La strategie de merge est **PR + auto-merge sur CI verte** (travail solo) :
la PR est obligatoire (trace de gouvernance), le merge se declenche automatiquement
une fois les checks requis au vert. Voir `scripts/forgejo-pr-automerge.sh` et
`scripts/forgejo-branch-protection.sh`.

## Signaler une vulnerabilite

Projet pedagogique non destine a la production publique. Pour signaler un
probleme de securite : ouvrir une issue sur le depot Forgejo
(`https://git.acadenice.com/AcadeNice/corentin_wakdo`) ou contacter l'auteur.
Merci de ne pas divulguer publiquement un detail exploitable avant correction.

## Perimetre

Couvert : authentification, autorisation (RBAC), gestion de session, validation
d'entree, integrite des donnees de commande, hygiene des secrets.
Hors perimetre : paiement reel (remplace par numero de commande), durcissement
OS de l'hote, securite physique de la borne.
