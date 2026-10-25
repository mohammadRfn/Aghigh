ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5

# ---------- base: PHP extensions, non-root user ----------
FROM ${FRANKENPHP_IMAGE} AS base

# apt over an unstable proxy/VPN: HTTPS, no pipelining, many retries.
RUN printf 'Acquire::Retries "10";\nAcquire::http::Pipeline-Depth "0";\nAcquire::http::Timeout "60";\nAcquire::https::Timeout "60";\n' > /etc/apt/apt.conf.d/80-network \
 && if dpkg -s ca-certificates >/dev/null 2>&1; then \
      for f in /etc/apt/sources.list /etc/apt/sources.list.d/*; do \
        [ ! -f "$f" ] || sed -i 's#http://#https://#g' "$f"; \
      done; \
    fi

# Build dependencies get their own retried step: packages that were downloaded
# survive a dropped connection, and the extension installer then finds them present.
RUN set -eu; \
    for i in 1 2 3 4 5 6 7 8; do \
      if apt-get update && apt-get install -y --no-install-recommends \
           cmake libicu-dev libpq-dev libzip-dev zlib1g-dev libssl-dev unzip; then break; fi; \
      [ "$i" -lt 8 ] || exit 1; \
      echo "apt attempt $i failed, retrying in 5s"; sleep 5; \
    done; \
    rm -rf /var/lib/apt/lists/*

RUN set -eu; \
    for i in 1 2 3 4 5; do \
      if install-php-extensions @composer pdo_pgsql redis pcntl intl bcmath zip opcache; then break; fi; \
      [ "$i" -lt 5 ] || exit 1; \
      echo "install-php-extensions attempt $i failed, retrying in 10s"; sleep 10; \
    done

RUN php -r 'foreach (["pdo_pgsql","redis","pcntl","posix","intl","bcmath","zip"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension: $e\n"); exit(1); } }'

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
