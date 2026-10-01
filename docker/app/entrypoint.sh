#!/usr/bin/env bash
set -euo pipefail

# Wait for the services the application cannot start without. Docker healthchecks
# cover the common case; this covers the one where the container is started
# directly, and it fails loudly rather than looping forever.
wait_for() {
    local host="$1" port="$2" name="$3" attempts=30

    until php -r "exit(@fsockopen('${host}', ${port}) ? 0 : 1);" 2>/dev/null; do
        attempts=$((attempts - 1))
        if [ "${attempts}" -le 0 ]; then
            echo "entrypoint: ${name} at ${host}:${port} never became reachable" >&2
            exit 1
        fi
        sleep 1
    done
}

if [ "${SKIP_DEPENDENCY_WAIT:-false}" != "true" ]; then
    wait_for "${DB_DIRECT_HOST:-postgres}" "${DB_DIRECT_PORT:-5432}" "PostgreSQL"
    wait_for "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" "Redis"
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    # Cached here rather than at build time: caching config during the build
    # freezes every env() call to the values present then, and the runtime
    # environment would be ignored for the life of the image.
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
elif [ "${GENERATES_APP_KEY:-false}" = "true" ]; then
    # One writer. Every service shares the mounted .env and starts at the same
    # moment, so if each of them seeded it, their writes would interleave into
    # a key no cipher accepts. `make install` normally writes it before any
    # container starts; this covers a bare `docker compose up`.
    if [ ! -f /app/.env ] && [ -f /app/.env.example ]; then
        cp /app/.env.example /app/.env
    fi

    if ! grep -q '^APP_KEY=base64:' /app/.env 2>/dev/null; then
        php artisan key:generate --force --no-interaction
    fi
else
    # Everyone else waits for the key the writer produces, and fails loudly
    # rather than booting without one.
    attempts=60

    until grep -q '^APP_KEY=base64:' /app/.env 2>/dev/null; do
        attempts=$((attempts - 1))
        if [ "${attempts}" -le 0 ]; then
            echo "entrypoint: /app/.env never got an APP_KEY; run \`make install\` or start the app service" >&2
            exit 1
        fi
        sleep 1
    done
fi

exec "$@"
