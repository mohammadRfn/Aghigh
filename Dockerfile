# syntax=docker/dockerfile:1
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5

# ---------- base: PHP extensions, non-root user ----------
FROM ${FRANKENPHP_IMAGE} AS base

RUN install-php-extensions @composer pdo_pgsql redis pcntl intl bcmath zip opcache

ARG UID=1000
ARG GID=1000
RUN groupadd --gid ${GID} app \
 && useradd --uid ${UID} --gid ${GID} --create-home --shell /bin/bash app \
 && mkdir -p /app /tmp/frankenphp/config /tmp/frankenphp/data /tmp/composer \
 && chown -R app:app /app /tmp/frankenphp /tmp/composer

ENV COMPOSER_HOME=/tmp/composer \
    XDG_CONFIG_HOME=/tmp/frankenphp/config \
    XDG_DATA_HOME=/tmp/frankenphp/data

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/zz-aghigh.ini $PHP_INI_DIR/conf.d/zz-aghigh.ini

WORKDIR /app
USER app

# ---------- dev: source is bind-mounted by compose ----------
FROM base AS dev

# ---------- prod: source and dependencies baked into the image ----------
FROM base AS prod
COPY --chown=app:app . /app
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
 && php artisan package:discover --ansi \
 && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php
