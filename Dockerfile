# Container image for NSIN (or any Docker platform). Serves the bot in webhook mode on port 8080
# with FrankenPHP; see docker/start.sh for what happens at start-up.
#
# Stage 1 downloads the PHP packages with Composer's own image (it has git and unzip).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --ignore-platform-reqs

# Stage 2 is the runtime. The base image already has every extension the bot needs (SQLite, mbstring,
# curl, openssl...). Add more at build time only if wanted, e.g. PostgreSQL:
#   --build-arg PHP_EXTRA_EXTENSIONS="pdo_pgsql"
FROM dunglas/frankenphp:1-php8.4
ARG PHP_EXTRA_EXTENSIONS=""
RUN if [ -n "$PHP_EXTRA_EXTENSIONS" ]; then install-php-extensions $PHP_EXTRA_EXTENSIONS; fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /data \
    && sed -i 's/\r$//' docker/start.sh \
    && chmod +x docker/start.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/loop.sqlite \
    PORT=8080

EXPOSE 8080

# The base image checks Caddy's admin port, which `frankenphp php-server` leaves switched off;
# check the app's own health route instead.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 CMD curl -fsS http://localhost:8080/up || exit 1

CMD ["docker/start.sh"]
