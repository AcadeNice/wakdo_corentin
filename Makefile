#
# Wakdo - Makefile d'orchestration locale
#
# Conventions :
#   - Une cible = une action unitaire. Les cibles composites sont commentees.
#   - Chaque cible est documentee par un `## description` pour auto-help.
#   - Echec sur erreur (set -e implicite via bash recipes + pipefail).
#
# Documentation :
#   make help
#

SHELL := /usr/bin/env bash
.SHELLFLAGS := -eu -o pipefail -c

# === Configuration ===

# Chargement du .env s'il existe (variables Make + export pour docker compose)
ifneq (,$(wildcard .env))
include .env
export
endif

# Prefixe du projet compose (utilise pour nommer les containers)
PROJECT := wakdo

# Nom du fichier compose (override possible : make up COMPOSE_FILE=docker-compose.prod.yml)
COMPOSE_FILE := docker-compose.yml
COMPOSE := docker compose -f $(COMPOSE_FILE) -p $(PROJECT)

# Services docker-compose
SERVICE_WEB  := wakdo-web
SERVICE_APP  := wakdo-app
SERVICE_DB   := wakdo-db
SERVICE_CRON := wakdo-cron

# === Meta ===

.DEFAULT_GOAL := help
.PHONY: help
help: ## Liste toutes les cibles disponibles avec leur description
	@echo "Wakdo - cibles Make disponibles :"
	@echo ""
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[1m%-22s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST) | sort
	@echo ""

# === Orchestration principale ===

.PHONY: init
init: ## Build et demarre toute la stack en une commande (Cr RNCP 7.c.4)
	@test -f .env || { echo "ERREUR: .env manquant. Executer : cp .env.example .env"; exit 1; }
	@$(MAKE) --no-print-directory check-env
	@echo "[init] Verification du reseau docker '$(REVERSE_PROXY_NETWORK)'..."
	@docker network inspect $(REVERSE_PROXY_NETWORK) >/dev/null 2>&1 || { \
		echo "ERREUR: reseau docker '$(REVERSE_PROXY_NETWORK)' introuvable."; \
		echo "  - Si un Traefik est installe sur l'hote, verifier le nom de son reseau ;"; \
		echo "  - Adapter REVERSE_PROXY_NETWORK dans .env en consequence ;"; \
		echo "  - Sinon creer le reseau manuellement :"; \
		echo "      docker network create $(REVERSE_PROXY_NETWORK)"; \
		exit 1; }
	@echo "[init] Build des images..."
	@$(COMPOSE) build
	@echo "[init] Demarrage des services..."
	@$(COMPOSE) up -d
	@echo "[init] Attente de la base de donnees..."
	@$(MAKE) --no-print-directory wait-db
	@echo "[init] Execution des migrations..."
	@$(MAKE) --no-print-directory migrate
	@echo "[init] Stack operationnelle."
	@$(COMPOSE) ps

.PHONY: up
up: ## Demarre les services sans rebuild
	@$(COMPOSE) up -d

.PHONY: down
down: ## Arrete et supprime les containers (volumes preserves)
	@$(COMPOSE) down

.PHONY: stop
stop: ## Arrete les services sans les supprimer
	@$(COMPOSE) stop

.PHONY: restart
restart: ## Redemarre tous les services
	@$(COMPOSE) restart

.PHONY: build
build: ## Build les images (utilise le cache)
	@$(COMPOSE) build

.PHONY: rebuild
rebuild: ## Rebuild complet sans cache puis restart
	@$(COMPOSE) build --no-cache
	@$(COMPOSE) up -d

# === Observabilite ===

.PHONY: ps
ps: ## Affiche le statut des services
	@$(COMPOSE) ps

.PHONY: logs
logs: ## Suit les logs de tous les services (Ctrl+C pour sortir)
	@$(COMPOSE) logs -f --tail=100

.PHONY: logs-app
logs-app: ## Suit les logs du service applicatif PHP-FPM
	@$(COMPOSE) logs -f --tail=100 $(SERVICE_APP)

.PHONY: logs-web
logs-web: ## Suit les logs du service web Apache
	@$(COMPOSE) logs -f --tail=100 $(SERVICE_WEB)

.PHONY: logs-db
logs-db: ## Suit les logs de la base de donnees
	@$(COMPOSE) logs -f --tail=100 $(SERVICE_DB)

# === Acces shell ===

.PHONY: shell-app
shell-app: ## Ouvre un shell dans le container applicatif
	@$(COMPOSE) exec $(SERVICE_APP) sh

.PHONY: shell-db
shell-db: ## Ouvre le client mariadb dans le container de base de donnees
	@$(COMPOSE) exec $(SERVICE_DB) mariadb -u root -p"$${DB_ROOT_PASSWORD}"

.PHONY: shell-cron
shell-cron: ## Ouvre un shell dans le container cron
	@$(COMPOSE) exec $(SERVICE_CRON) sh

# === Verification env ===

.PHONY: check-env
check-env: ## Verifie que les variables critiques Wakdo sont definies dans .env
	@missing=""; \
	for var in DB_PASSWORD DB_ROOT_PASSWORD REVERSE_PROXY_NETWORK TRAEFIK_DOMAIN_KIOSK TRAEFIK_DOMAIN_ADMIN APP_URL_KIOSK APP_URL_ADMIN CORS_ALLOWED_ORIGIN; do \
		if [ -z "$${!var:-}" ]; then missing="$$missing $$var"; fi; \
	done; \
	if [ -n "$$missing" ]; then \
		echo "ERREUR: variables manquantes dans .env :$$missing"; \
		echo "Conseil : si vous aviez un .env pre-existant (tooling externe),"; \
		echo "  merger les variables manquantes depuis .env.example au lieu"; \
		echo "  d'ecraser le fichier."; \
		exit 1; \
	fi

# === Base de donnees ===

.PHONY: wait-db
wait-db: ## Attend que la base de donnees accepte les connexions (timeout 60s)
	@echo "[wait-db] En attente de MariaDB..."
	@timeout 60 bash -c 'until $(COMPOSE) exec -T $(SERVICE_DB) healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do sleep 2; done' \
		|| { echo "ERREUR: MariaDB ne repond pas apres 60s"; $(COMPOSE) logs --tail=50 $(SERVICE_DB); exit 1; }
	@echo "[wait-db] OK"

.PHONY: migrate
migrate: ## Applique les migrations SQL en attente [a venir]
	@echo "[migrate] Pas encore implemente. Les migrations seront dans db/migrations/."

.PHONY: seed
seed: ## Charge les donnees de demo [a venir]
	@echo "[seed] Pas encore implemente. Les seeds seront dans db/seeds/."

.PHONY: backup
backup: ## Genere un dump SQL horodate dans ./backups/ [a venir]
	@echo "[backup] Pas encore implemente. Voir scripts/backup-db.sh a venir."

# === Tests ===

.PHONY: test
test: ## Lance la suite complete de tests PHPUnit [a venir]
	@echo "[test] Pas encore implemente. PHPUnit via .phar sera configure en P2."

.PHONY: test-unit
test-unit: ## Lance uniquement les tests unitaires [a venir]
	@echo "[test-unit] Pas encore implemente."

.PHONY: test-integration
test-integration: ## Lance uniquement les tests d'integration [a venir]
	@echo "[test-integration] Pas encore implemente."

# === Qualite code ===

.PHONY: lint
lint: ## Lance php -l sur tous les fichiers src/ [a venir]
	@echo "[lint] Pas encore implemente. PHP syntax check via php -l + outil de style en P2."

# === Nettoyage ===

.PHONY: clean
clean: ## Stop + suppression containers + volumes (DESTRUCTIF, demande confirmation)
	@read -p "Supprimer containers ET volumes (les donnees seront perdues) ? [y/N] " ans; \
	if [ "$$ans" = "y" ] || [ "$$ans" = "Y" ]; then \
		$(COMPOSE) down -v; \
		echo "[clean] Stack et volumes supprimes."; \
	else \
		echo "[clean] Annule."; \
	fi

.PHONY: clean-force
clean-force: ## Version non interactive de clean (pour CI uniquement)
	@$(COMPOSE) down -v

# === Hooks Git ===

.PHONY: install-hooks
install-hooks: ## Installe les hooks git depuis .githooks/ [a venir]
	@echo "[install-hooks] Pas encore implemente. Voir scripts/install-hooks.sh a venir."
