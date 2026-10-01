# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Runtime: the extensions and nothing to build them with. The upstream image
# ships a C toolchain and kernel headers for compiling extensions; once they
# are compiled, both are only attack surface and scanner findings, so they
# are removed in the same layer. Production is built on this stage.
# Debian's security updates are applied at build time, so a fix published
# before the upstream image is rebuilt still reaches this one.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS runtime

RUN apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        bcmath \
        intl \
        zip \
        pcntl \
        sockets \
        opcache \
    && apt-get purge -y --auto-remove \
        gcc \
        gcc-14 \
        dpkg-dev \
        libc6-dev \
        libc-dev-bin \
        libgcc-14-dev \
        linux-libc-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# ---------------------------------------------------------------------------
# Base: FrankenPHP with the extensions, the headers and Composer — for
# resolving dependencies and for development.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base

# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        bcmath \
        intl \
        zip \
        pcntl \
        sockets \
        opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# ---------------------------------------------------------------------------
# Vendor: dependencies only, so a code change does not re-resolve them.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

# ---------------------------------------------------------------------------
# Development: dev dependencies, coverage driver, writable source mount.
# ---------------------------------------------------------------------------
FROM base AS development

RUN install-php-extensions pcov

COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-metered.ini
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
# A worker is replaced after 10,000 requests, not 500: each replacement
# starts new metric series (ADR-0019).
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8080", "--max-requests=10000"]

# ---------------------------------------------------------------------------
# App: the source with production dependencies and an authoritative
# classmap, prepared where Composer exists.
# ---------------------------------------------------------------------------
FROM base AS app

COPY --from=vendor /app/vendor ./vendor
COPY . .

# The simulation module seeds data, generates traffic and kills processes on
# purpose. It has no business in an image that could reach real data.
#
# Config is NOT cached here. Caching it at build time freezes every env() call
# to the values present during the build — which is none of them — and the
# runtime environment would then be silently ignored. The entrypoint caches it
# on start instead, when the real environment exists.
RUN rm -rf src/Simulation tests docs .github \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------------------
# Production: no dev dependencies, no simulation module, no toolchain, non-root.
# ---------------------------------------------------------------------------
FROM runtime AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_DEMO=false

COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-metered.ini
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
COPY --from=app /app /app

RUN chmod +x /usr/local/bin/entrypoint

# Caddy keeps its autosaved config and its storage under XDG_CONFIG_HOME and
# XDG_DATA_HOME (/config and /data in the upstream image); the process user
# has to own them, or it logs an error for each on every start.
RUN useradd --uid 10001 --create-home --shell /usr/sbin/nologin metered \
    && chown -R 10001:10001 /app/storage /app/bootstrap/cache /config/caddy /data/caddy \
    && setcap -r /usr/local/bin/frankenphp || true

USER 10001

EXPOSE 8080

# Liveness: an orchestrator restarts on this, and must not restart a healthy
# process because a dependency is down. Readiness is /health/ready.
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/health/live') === false ? 1 : 0);"]

ENTRYPOINT ["entrypoint"]
# A worker is replaced after 10,000 requests, not 500: each replacement
# starts new metric series (ADR-0019).
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8080", "--max-requests=10000"]
