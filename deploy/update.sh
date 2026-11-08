#!/usr/bin/env bash
#
# Pulls the latest code from GitHub and restarts the bot. Run as root: loop-bot-update
#
set -euo pipefail

APP_DIR=/opt/loop-feedback-bot
APP_USER=loopbot
PHP=php8.4

cd "$APP_DIR"
as_app() { sudo -u "$APP_USER" -H "$@"; }

/usr/local/bin/loop-bot-backup
as_app git pull --ff-only
as_app "$PHP" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress
as_app "$PHP" artisan migrate --force --no-interaction
as_app "$PHP" artisan config:cache --no-interaction
as_app "$PHP" artisan view:cache --no-interaction
install -m 644 deploy/loop-bot.service /etc/systemd/system/loop-bot.service
install -m 755 deploy/backup.sh /usr/local/bin/loop-bot-backup
install -m 755 deploy/update.sh /usr/local/bin/loop-bot-update
systemctl daemon-reload
systemctl restart loop-bot
sleep 5
systemctl --no-pager --lines=5 status loop-bot
