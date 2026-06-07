# Metered — developer entrypoints.
# Every gate that CI runs must be runnable here with the same result.

SHELL := /bin/bash
DC    := docker compose
EXEC  := $(DC) exec -T app

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z0-9_.-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s\033[0m %s\n", $$1, $$2}'

# --------------------------------------------------------------------------
# Environment
# --------------------------------------------------------------------------

.PHONY: up
up: ## Build and start the full stack (app, db, redis, workers, observability)
	$(DC) up -d --build
	$(MAKE) migrate

.PHONY: down
down: ## Stop the stack, keep volumes
	$(DC) down

.PHONY: destroy
destroy: ## Stop the stack and delete volumes (irreversible)
	$(DC) down -v

.PHONY: logs
logs: ## Tail logs of all services
	$(DC) logs -f --tail=100

.PHONY: shell
shell: ## Open a shell inside the app container
	$(DC) exec app bash

.PHONY: migrate
migrate: ## Run database migrations
	$(EXEC) php artisan migrate --force

.PHONY: fresh
fresh: ## Drop everything and re-migrate (local only)
	$(EXEC) php artisan migrate:fresh --seed

# --------------------------------------------------------------------------
# Quality gates — `make check` must mirror the CI pipeline exactly
# --------------------------------------------------------------------------

.PHONY: check
check: lint static test ## Run every gate CI runs (except mutation)

.PHONY: lint
lint: ## composer validate + Pint (check mode) + Rector (dry run)
	$(EXEC) composer validate --strict
	$(EXEC) vendor/bin/pint --test
	$(EXEC) vendor/bin/rector process --dry-run

.PHONY: fix
fix: ## Apply Pint and Rector fixes
	$(EXEC) vendor/bin/pint
	$(EXEC) vendor/bin/rector process

.PHONY: static
static: ## Larastan (level max) + Deptrac (layers and module boundaries)
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=1G
	$(EXEC) vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml
	$(EXEC) vendor/bin/deptrac analyse --config-file=deptrac.modules.yaml

.PHONY: test
test: ## Full test suite with coverage thresholds
	$(EXEC) php artisan test --coverage --min=85

.PHONY: test-unit
test-unit: ## Domain unit tests only (fast, no containers needed)
	$(EXEC) vendor/bin/pest --testsuite=Unit

.PHONY: test-integration
test-integration: ## Tests that need real PostgreSQL and Redis
	$(EXEC) vendor/bin/pest --testsuite=Integration

.PHONY: test-concurrency
test-concurrency: ## Concurrency tests (real parallel connections, spatie/fork)
	$(EXEC) vendor/bin/pest --testsuite=Concurrency

.PHONY: test-arch
test-arch: ## Architecture tests (Pest Arch)
	$(EXEC) vendor/bin/pest --testsuite=Architecture

.PHONY: mutation
mutation: ## Mutation testing on the four Domain layers (score >= 85)
	$(EXEC) vendor/bin/pest --mutate --class='Metered\Shared\Domain,Metered\Usage\Domain,Metered\Billing\Domain,Metered\Invoicing\Domain' --min=85 --ignore-min-score-on-zero-mutations

.PHONY: security
security: ## Dependency and filesystem vulnerability scan
	$(EXEC) composer audit
	docker run --rm -v "$(PWD):/src" aquasec/trivy fs --exit-code 1 --severity HIGH,CRITICAL /src

# --------------------------------------------------------------------------
# Demo and load
# --------------------------------------------------------------------------

.PHONY: demo
demo: ## One command for a reviewer: stack + seeded data + live traffic + open admin
	$(DC) --profile demo up -d --build
	$(MAKE) migrate
	$(EXEC) php artisan sim:seed --profile=demo
	$(EXEC) php artisan sim:traffic --rps=50 --duration=60 &
	@echo "Admin panel:  http://localhost:8080/admin  (sign up to get your own demo tenant)"
	@echo "Grafana:      http://localhost:3000"
	@echo "Horizon:      http://localhost:8080/horizon"

.PHONY: demo-reset
demo-reset: ## Wipe demo tenants and reseed
	$(EXEC) php artisan demo:reset --force
	$(EXEC) php artisan sim:seed --profile=demo

.PHONY: load
load: ## Run the k6 load profile against the local stack
	$(DC) --profile load run --rm k6 run /scripts/ingest.js

.PHONY: openapi
openapi: ## Regenerate the OpenAPI document
	$(EXEC) php artisan scramble:export --path=docs/api/openapi.json
