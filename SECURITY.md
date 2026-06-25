# Politique de securite - Wakdo

Wakdo est un projet de fin de formation (RNCP 37805) construit en
**security-by-design** : la menace est modelisee avant le code. Ce document
resume la posture, le signalement de vulnerabilites et les garde-fous CI.

## Modele de menace

Le modele STRIDE complet, le registre des risques et la classification des
donnees (4 niveaux) vivent dans `docs/PROJECT_CONTEXT.md` section 19, et le flux
d'authentification durci dans `docs/uml/security-sequence.md`.

## Mesures en place (resume)

| Domaine | Mesure |
|---|---|
| Mots de passe | `password_hash` argon2id (cout configurable, defauts OWASP) |
| Actions sensibles | PIN equipier hashe argon2id (`pin_hash`) |
| Brute-force | double throttle : compteur par compte (`user`) + par IP (`login_throttle`), backoff degressif |
| Sessions | cookies `HttpOnly` + `Secure` + `SameSite=Strict`, regeneration d'ID a la connexion (anti-fixation), idle 4h / absolu 10h |
| Injection | PDO prepared statements exclusivement |
| Upload | non implemente (aucun flux d'upload livre) ; prevu : validation MIME + taille, stockage hors webroot |
| En-tetes / PHP | `expose_php=Off`, `allow_url_fopen/include=Off`, `cgi.fix_pathinfo=0`, fonctions d'execution systeme desactivees |
| RGPD | retention limitee (audit ~12 mois, throttle 24h, commandes ~3 ans), droit consultation/modif/suppression |
| Secrets | `.env` gitignore, tenu hors de `.git/config` (credential helper lisant `.env`), secret-scan gitleaks en CI |

Les seuils operationnels (couts argon2, lockout, throttle, retention) sont
documentes dans `.env.example`.

## Garde-fous CI (Forgejo Actions)

Chaque PR vers `dev` ou `main` declenche `.forgejo/workflows/ci.yml` :

- **secret-scan** (gitleaks) : empeche un secret d'entrer dans l'historique
- **php-lint** : `php -l` sur tous les fichiers PHP
- **static-tests** : PHPStan + PHPUnit (s'activent quand le code PHP arrive en P2)

La strategie de merge est **PR + auto-merge sur CI verte** (travail solo) : la
PR est obligatoire (trace de gouvernance), le merge se declenche automatiquement
une fois les checks au vert. Voir `scripts/forgejo-pr-automerge.sh` et
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
