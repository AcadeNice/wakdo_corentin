# ADR-0008 — Du Makefile a `docker compose up` (service wakdo-migrate)

- Statut : Accepte
- Date : 2026-06-17

## Contexte
Le critere Cr 7.c.4 demande de lancer la stack complete en une commande. C'etait
`make init`. Mais le Makefile portait surtout des cibles mortes/trompeuses
(`test`/`lint` annoncaient "pas implemente" alors que les tests tournent) ; sa seule
cible porteuse, `init`, existait parce que `docker compose up` seul n'applique pas les
migrations. Le critere parle d'un **resultat**, pas de `make`.

## Decision
Migration + seed deplaces **dans la stack** : un service one-shot **`wakdo-migrate`**
(image mariadb, `db/migrate-container.sh` par le reseau) applique
`db/migrations/*.sql` (suivi `schema_migrations`) puis `db/seeds/*.sql` (suivi
`seeds_applied`), idempotents. `wakdo-app`/`wakdo-web` `depends_on:
service_completed_successfully`. **Makefile supprime.** `docker compose up` devient
l'unique commande.

## Consequences
- (+) Commande universelle, sans dependance a l'outil `make` sur l'hote.
- (+) Comportement = doc (l'ancien `make init` ne seedait meme pas).
- (-) Migrations/seed evalues a chaque `up` (cout negligeable, suivi -> re-run sans effet).
- (-) Base **deja seedee** avant le suivi : back-filler `seeds_applied` avant le 1er up.
- `db/migrate.sh` (hote) conserve pour l'usage manuel. Detail : journal 2026-06-17.
