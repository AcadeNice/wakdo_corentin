# Registre des decisions d'architecture (ADR)

Une fiche courte par decision structurante : **contexte**, **decision**, **consequences**.
Format inspire des Architecture Decision Records (M. Nygard). Les ADR sont immuables :
une decision revisee donne une nouvelle fiche qui *supersede* l'ancienne (statut mis a jour).

**Auteur : BYAN** (formalisation ; arbitrage et validation par l'auteur).

| # | Decision | Statut |
|---|---|---|
| [0001](0001-php-from-scratch-sans-composer.md) | PHP from scratch, sans framework ni Composer | Accepte |
| [0002](0002-back-office-mvc-rendu-serveur.md) | Back-office en MVC rendu serveur (pas de SPA) | Accepte |
| [0003](0003-stock-pourcentage-dispo-calculee.md) | Stock en pourcentage + disponibilite produit calculee (RG-T21) | Accepte |
| [0004](0004-pin-action-sensible-audit.md) | PIN d'action sensible (equipier) + audit dans la meme transaction | Accepte |
| [0005](0005-throttle-pin-separe-du-login.md) | Throttle du PIN separe des compteurs de connexion (RG-T22) | Accepte |
| [0006](0006-http-409-conflit-422-validation.md) | HTTP 409 (conflit) vs 422 (validation) | Accepte |
| [0007](0007-rgpd-anonymisation-tombstone.md) | Effacement RGPD par anonymisation (tombstone), pas DELETE | Accepte |
| [0008](0008-makefile-vers-compose-migrate.md) | Du Makefile a `docker compose up` (service wakdo-migrate) | Accepte |
| [0009](0009-compose-standalone-et-prod-gitignore.md) | docker-compose.yml standalone + docker-compose.prod.yml gitignore | Accepte |

## Modele de fiche

```
# ADR-NNNN — Titre

- Statut : Propose | Accepte | Supersede par ADR-XXXX
- Date : AAAA-MM-JJ

## Contexte
Le probleme, les contraintes, les options envisagees.

## Decision
Le choix retenu, en une ou deux phrases nettes.

## Consequences
Ce que ca implique (positif et negatif), et les regles/fichiers concernes.
```
