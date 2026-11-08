#!/usr/bin/env bash
#
# One-time setup of the Loop feedback bot on a fresh Ubuntu 22.04 / 24.04 server. Run as root:
#
#   curl -fsSL https://raw.githubusercontent.com/ayobyari73/loop-feedback-bot/main/deploy/install.sh | bash
#
# Secrets are never typed into this script: put them in /root/loop-bot.env first, one KEY=value per line
# (TELEGRAM_BOT_TOKEN, TELEGRAM_OWNER_ID, optionally TELEGRAM_PROXY). Safe to run again; it skips what is done.
#
set -euo pipefail

REPO="${LOOP_REPO:-https://github.com/ayobyari73/loop-feedback-bot.git}"
APP_DIR=/opt/loop-feedback-bot
APP_USER=loopbot
PHP=php8.4
SECRETS=/root/loop-bot.env

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 1; }
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || { echo "This script supports Ubuntu only (found: ${ID:-unknown})."; exit 1; }

step "System packages and PHP 8.4"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq software-properties-common ca-certificates curl git unzip sqlite3
if ! command -v "$PHP" >/dev/null; then
    LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php
    apt-get update -q
fi
apt-get install -yq "$PHP"-cli "$PHP"-sqlite3 "$PHP"-curl "$PHP"-mbstring "$PHP"-xml "$PHP"-zip "$PHP"-intl "$PHP"-bcmath

step "Composer"
if [ ! -x /usr/local/bin/composer ]; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "$PHP" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

step "App user and code"
id -u "$APP_USER" >/dev/null 2>&1 || useradd --system --create-home --home-dir "/home/$APP_USER" --shell /usr/sbin/nologin "$APP_USER"
if [ ! -d "$APP_DIR/.git" ]; then
    git clone --depth 1 "$REPO" "$APP_DIR"
fi
chown -R "$APP_USER:$APP_USER" "$APP_DIR"
cd "$APP_DIR"
as_app() { sudo -u "$APP_USER" -H "$@"; }
as_app "$PHP" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress

step "Configuration (.env)"
set_env() {
    local key=$1 value=$2
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value//|/\|}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}
if [ ! -f .env ]; then
    as_app cp .env.example .env
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env LOG_CHANNEL daily
    set_env LOG_LEVEL warning
    as_app "$PHP" artisan key:generate --force --no-interaction
fi
if [ -f "$SECRETS" ]; then
    while IFS='=' read -r key value; do
        case "$key" in
            TELEGRAM_BOT_TOKEN|TELEGRAM_OWNER_ID|TELEGRAM_PROXY|LOOP_SEASON_START) set_env "$key" "$value" ;;
        esac
    done < <(grep -E '^[A-Z_]+=' "$SECRETS")
    shred -u "$SECRETS" 2>/dev/null || rm -f "$SECRETS"
    echo "Secrets applied and $SECRETS removed."
fi
chown "$APP_USER:$APP_USER" .env
chmod 600 .env

step "Database"
as_app touch database/database.sqlite
as_app "$PHP" artisan migrate --force --no-interaction
as_app "$PHP" artisan config:cache --no-interaction
as_app "$PHP" artisan view:cache --no-interaction

step "Service and daily backup"
install -m 644 deploy/loop-bot.service /etc/systemd/system/loop-bot.service
install -m 755 deploy/backup.sh /usr/local/bin/loop-bot-backup
install -m 755 deploy/update.sh /usr/local/bin/loop-bot-update
printf '30 3 * * * root /usr/local/bin/loop-bot-backup\n' > /etc/cron.d/loop-bot-backup
systemctl daemon-reload

if grep -qE '^TELEGRAM_BOT_TOKEN=.+' .env && grep -qE '^TELEGRAM_OWNER_ID=[0-9]+' .env; then
    systemctl enable --now loop-bot
    systemctl restart loop-bot
    sleep 5
    systemctl --no-pager --lines=5 status loop-bot || true
    step "Done. The bot is running."
else
    systemctl enable loop-bot
    step "Installed, but TELEGRAM_BOT_TOKEN / TELEGRAM_OWNER_ID are missing in $APP_DIR/.env."
    echo "Add them, then run: systemctl restart loop-bot"
fi
