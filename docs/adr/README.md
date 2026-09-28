# Registre des decisions d'architecture (ADR)

Une fiche courte par decision structurante : **contexte**, **decision**, **consequences**.
Format inspire des Architecture Decision Records (M. Nygard). Les ADR sont immuables :
une decision revisee donne une nouvelle fiche qui *supersede* l'ancienne (statut mis a jour).

**Auteur : BYAN** (formalisation ; arbitrage et validation par l'auteur).

| # | Decision | Statut |
|---|---|---|
| [0001](0001-php-from-scratch-sans-composer.md) | PHP from scratch, sans framework ni Composer | Accepte |
| [0002](0002-back-office-mvc-rendu-serveur.md) | Back-office en MVC rendu serveur (pas de SPA) | Accepte, complete le 2026-09-25 par ADR-0017 |
| [0003](0003-stock-pourcentage-dispo-calculee.md) | Stock en pourcentage + disponibilite produit calculee (RG-T21) | Accepte |
| [0004](0004-pin-action-sensible-audit.md) | PIN d'action sensible (equipier) + audit dans la meme transaction | Accepte |
| [0005](0005-throttle-pin-separe-du-login.md) | Throttle du PIN separe des compteurs de connexion (RG-T22) | Accepte |
| [0006](0006-http-409-conflit-422-validation.md) | HTTP 409 (conflit) vs 422 (validation) | Accepte |
| [0007](0007-rgpd-anonymisation-tombstone.md) | Effacement RGPD par anonymisation (tombstone), pas DELETE | Accepte |
| [0008](0008-makefile-vers-compose-migrate.md) | Du Makefile a `docker compose up` (service wakdo-migrate) | Accepte |
| [0009](0009-compose-standalone-et-prod-gitignore.md) | docker-compose.yml standalone + docker-compose.prod.yml gitignore | Accepte |
| [0010](0010-cookie-secure-conditionnel-https.md) | Cookie de session Secure conditionnel au HTTPS | Accepte |
| [0011](0011-pos-tactile-tuiles-comptoir-drive.md) | POS tactile a tuiles pour la saisie comptoir/drive | Accepte |
| [0012](0012-page-stock-tableau-de-bord.md) | Page Stock en tableau de bord (alertes + reapprovisionnement en avant) | Accepte |
| [0013](0013-vue-produits-groupee-par-categorie.md) | Vue back-office du catalogue groupee par categorie (en plus de la liste plate) | Accepte |
| [0014](0014-expiration-commandes-pending.md) | Expiration des commandes restees en attente de paiement (cron 02h00) | Accepte, amende le 2026-07-31 par ADR-0016 |
| [0015](0015-allergenes-calcules-par-produit.md) | Allergenes calcules par produit, avec etat de revue explicite | Accepte, complete le 2026-09-27 par ADR-0018 |
| [0016](0016-modification-commande-avant-paiement.md) | Modifier une commande avant paiement, et le verrou qui va avec | Accepte |
| [0017](0017-api-admin-json.md) | API d'administration JSON, en complement du MVC rendu serveur | Accepte |
| [0018](0018-familles-ingredients-filtre-recette.md) | Familles d'ingredients et filtre souple du constructeur de recette | Accepte |
| [0019](0019-page-sante-api-carte-vivante.md) | Page « Santé de l'API » : une carte des routes qui ne peut pas diverger du code | Accepte, complete le 2026-09-28 |
| [0020](0020-responsable-annule-commande.md) | Le responsable peut annuler une commande | Accepte |

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
