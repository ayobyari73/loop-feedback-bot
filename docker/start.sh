#!/bin/sh
#
# Container start-up: prepare the database, register the Telegram webhook, then serve HTTP.
#
# Required environment: APP_URL (public https address), TELEGRAM_BOT_TOKEN, TELEGRAM_OWNER_ID,
# TELEGRAM_WEBHOOK_SECRET, and TELEGRAM_API_BASE (the Cloudflare bridge) when Telegram is filtered.
# Keep /data on a persistent disk: the SQLite database lives there.
#
set -e

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

# The bot encrypts nothing it keeps, so a key generated per start is fine when none is configured.
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php artisan key:generate --show --no-interaction)"
    export APP_KEY
fi

php artisan config:cache --no-interaction
php artisan view:cache --no-interaction
php artisan migrate --force --no-interaction

if [ -n "$TELEGRAM_WEBHOOK_SECRET" ]; then
    php artisan app:webhook set --no-interaction || echo "Webhook not registered yet (check TELEGRAM_API_BASE and APP_URL); the server starts anyway."
fi

exec frankenphp php-server --root public --listen ":${PORT:-8080}"
