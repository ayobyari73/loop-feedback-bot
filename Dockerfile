# Container image for NSIN (or any Docker platform). Serves the bot in webhook mode on port 8080
# with FrankenPHP; see docker/start.sh for what happens at start-up.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_sqlite pdo_pgsql intl zip bcmath pcntl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /data \
    && chmod +x docker/start.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/loop.sqlite \
    PORT=8080

EXPOSE 8080

CMD ["docker/start.sh"]
