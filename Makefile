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
	$(EXEC) php artisan migrate:fresh

# --------------------------------------------------------------------------
# Quality gates — `make check` must mirror the CI pipeline exactly
# --------------------------------------------------------------------------

.PHONY: check
check: lint static alerts-test test ## Run every gate CI runs (except mutation)

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
# Larastan boots the application and types console input from the signatures of
# the commands registered there. CI has no .env and boots as production, where
# the simulation commands are not registered; analysing under the local .env
# typed their input and hid six errors CI then reported. So analyse the way CI
# boots — with the demo-only settings the production boot refuses cleared.
static: ## Larastan (level max) + Deptrac (layers and module boundaries)
	$(EXEC) env APP_ENV=production APP_DEMO=false WEBHOOKS_TRUSTED_DESTINATION= vendor/bin/phpstan analyse --memory-limit=1G
	$(EXEC) vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml
	$(EXEC) vendor/bin/deptrac analyse --config-file=deptrac.modules.yaml

PROMETHEUS_IMAGE   := prom/prometheus:v3.15.0
ALERTMANAGER_IMAGE := prom/alertmanager:v0.34.1

.PHONY: alerts-test
# The images compose runs, so the rules are checked by the Prometheus that
# evaluates them. Needs Docker only, not the stack.
alerts-test: ## Alert rules: promtool check + promtool test rules, amtool check-config
	docker run --rm -v "$(CURDIR)/docker/prometheus:/etc/prometheus:ro" --entrypoint promtool $(PROMETHEUS_IMAGE) check config /etc/prometheus/prometheus.yml
	docker run --rm -v "$(CURDIR)/docker/prometheus:/etc/prometheus:ro" -w /etc/prometheus/rules --entrypoint promtool $(PROMETHEUS_IMAGE) test rules metered.test.yml
	docker run --rm -v "$(CURDIR)/docker/alertmanager:/etc/alertmanager:ro" --entrypoint amtool $(ALERTMANAGER_IMAGE) check-config /etc/alertmanager/alertmanager.yml

.PHONY: test
test: ## Full test suite with coverage thresholds
	$(EXEC) php artisan test --coverage --min=85

.PHONY: test-fast
# The coverage threshold is measured serially, as CI measures it: merged
# per-process coverage comes out a few tenths lower and would make the gate
# disagree with the pipeline. This target answers "does it still pass" only.
test-fast: ## Full test suite in parallel, without coverage (the loop between edits)
	$(EXEC) vendor/bin/pest --parallel

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
mutation: ## Mutation testing on the five Domain layers (score >= 85)
	$(EXEC) vendor/bin/pest --mutate --parallel --class='Metered\Shared\Domain,Metered\Usage\Domain,Metered\Billing\Domain,Metered\Invoicing\Domain,Metered\Webhooks\Domain' --min=85 --ignore-min-score-on-zero-mutations

.PHONY: mutation-module
# `make mutation` reports one score over all five domain layers, so a module
# that is weak on its own can pass behind the others. Milestone criteria name a
# module; this is how they are checked. Usage: make mutation-module MODULE=Billing
mutation-module: ## Mutation testing on one module's Domain layer (score >= 85)
	@test -n "$(MODULE)" || { echo "usage: make mutation-module MODULE=Billing"; exit 2; }
	$(EXEC) vendor/bin/pest --mutate --parallel --class='Metered\$(MODULE)\Domain' --min=85 --ignore-min-score-on-zero-mutations

.PHONY: security
security: ## Dependency and filesystem vulnerability scan
	$(EXEC) composer audit
	docker run --rm -v "$(PWD):/src" aquasec/trivy fs --exit-code 1 --severity HIGH,CRITICAL /src

# --------------------------------------------------------------------------
# Demo and load
# --------------------------------------------------------------------------

.PHONY: install
install: ## Install PHP dependencies into the working tree (the app mounts it over the image's)
	@test -f .env || cp .env.example .env
	$(DC) run --rm --no-deps --entrypoint composer app install --no-interaction --no-progress

# The showcase every visitor can look around before signing up (ADR-0016).
# Its read-only account is printed on the login page in demo mode; keep these
# in step with DEMO_SHOWCASE_EMAIL / DEMO_SHOWCASE_PASSWORD if you change them.
SHOWCASE          ?= Northwind Cloud
SHOWCASE_EMAIL    ?= demo@metered.test
SHOWCASE_PASSWORD ?= metered-demo
TRAFFIC           := metered-demo-traffic

.PHONY: demo
# APP_DEMO and tracing are read once, at boot, by long-running processes; when
# this switches either on for a stack that is already up, the stack is
# restarted to see it.
demo: ## One command for a reviewer: stack, showcase data, live traffic
	@test -f .env || cp .env.example .env
	@if ! grep -q '^APP_DEMO=true' .env || ! grep -q '^OTEL_SDK_DISABLED=false' .env; then \
		sed -i.bak -e 's/^APP_DEMO=.*/APP_DEMO=true/' -e 's/^OTEL_SDK_DISABLED=.*/OTEL_SDK_DISABLED=false/' .env && rm -f .env.bak; \
		$(DC) restart >/dev/null 2>&1 || true; \
	fi
	@test -f vendor/autoload.php || $(MAKE) install
	$(MAKE) up
	$(MAKE) demo-reset
	@echo
	@echo "Admin panel:  http://localhost:$${APP_PORT:-8080}/admin"
	@echo "              $(SHOWCASE_EMAIL) / $(SHOWCASE_PASSWORD) (read-only), or sign up for a tenant of your own"
	@echo "Grafana:      http://localhost:$${GRAFANA_PORT:-3000}  (live load; traces under Explore → Tempo)"
	@echo "Webhooks:     http://localhost:$${WEBHOOK_RECEIVER_PORT:-8089}"
	@echo "Horizon:      http://localhost:$${APP_PORT:-8080}/horizon"

.PHONY: demo-reset
# sim:seed prints one JSON object with the showcase's slug and key; the traffic
# generator runs as its own container so the next reset can stop it.
demo-reset: ## Delete every demo tenant, seed the showcase again, restart live traffic
	@docker rm -f $(TRAFFIC) >/dev/null 2>&1 || true
	$(EXEC) php artisan demo:reset --force
	@set -eu; \
	seeded=$$($(EXEC) php artisan sim:seed --profile=demo --demo --organization="$(SHOWCASE)" --json) \
		|| { echo "$$seeded" >&2; exit 1; }; \
	key=$$(echo "$$seeded" | sed -n 's/.*"key":"\([^"]*\)".*/\1/p'); \
	slug=$$(echo "$$seeded" | sed -n 's/.*"organization":"\([^"]*\)".*/\1/p'); \
	test -n "$$key" -a -n "$$slug" || { echo "sim:seed printed no key: $$seeded" >&2; exit 1; }; \
	$(EXEC) php artisan org:member "$$slug" $(SHOWCASE_EMAIL) --role=viewer --password=$(SHOWCASE_PASSWORD); \
	$(DC) run -d --rm --no-deps --name $(TRAFFIC) app php artisan sim:traffic --key="$$key" --rps=20 --duration=3600 >/dev/null 2>&1; \
	echo "Live traffic: 20 events/s for an hour (docker logs -f $(TRAFFIC))"

.PHONY: load
# --no-deps, because `compose run` otherwise reconciles the services this one
# depends on — and a load run is normally started from a shell that does not
# carry the raised API_KEY_RATE_LIMIT_PER_MINUTE the stack was brought up with.
# The app is then recreated at the default limit, in the middle of the run, so
# the number reported is the limiter's and the restart appears as connection
# errors. The stack under test is started by `make up`; this only sends traffic.
SCENARIO ?= ingest
load: ## Run a k6 profile against the local stack: SCENARIO=ingest (default) or mixed
	$(DC) --profile load run --rm --no-deps k6 run /scripts/$(SCENARIO).js

.PHONY: openapi
openapi: ## Regenerate the OpenAPI document
	@mkdir -p docs/api
	$(EXEC) php artisan scramble:export
