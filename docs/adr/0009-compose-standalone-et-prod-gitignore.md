# ADR-0009 — docker-compose.yml standalone + docker-compose.prod.yml gitignore

- Statut : Accepte
- Date : 2026-06-17

## Contexte
Le `docker-compose.yml` versionne supposait un reverse proxy Traefik (reseau externe,
labels, aucun port hote) : `docker compose up` echouait pour quiconque sans Traefik
(jury, contributeur, tests E2E). Options envisagees : overlay `-f` (base + prod fusionnes
via `!reset`) ; un seul fichier parametre ; deux fichiers complets independants.

## Decision
Deux fichiers **complets et independants** (pas d'overlay) :
- **`docker-compose.yml`** (versionne) : standalone, `wakdo-web` publie
  `${HTTP_PORT:-8080}:80`, reseau interne seul, sans Traefik. `docker compose up` tourne
  partout, facon app open-source self-hostable.
- **`docker-compose.prod.yml`** : **gitignore**, propre a chaque hote derriere un proxy
  (meme stack + reseau externe + labels Traefik, sans port). `docker compose -f
  docker-compose.prod.yml up -d`.

Renommage `TRAEFIK_DOMAIN_*` -> `APP_HOST_*` (ce sont des `ServerName` de vhosts, pas du
Traefik). `.env.example` local-first.

## Consequences
- (+) `docker compose up` marche en local sans configuration ; le repo ne porte aucune
  hypothese d'infra.
- (+) Le critere Cr 7.c.4 tient avec un fichier que tout le monde peut lancer.
- (-) Duplication entre les deux fichiers (assumee : clarte > DRY pour l'infra).
- (-) Le serveur maintient son propre fichier prod (comme `.env`).
