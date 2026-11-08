#!/usr/bin/env bash
#
# Consistent copy of the bot's SQLite database, kept for 30 days. Runs daily from /etc/cron.d/loop-bot-backup.
#
set -euo pipefail

DB=/opt/loop-feedback-bot/database/database.sqlite
DIR=/var/backups/loop-bot

mkdir -p "$DIR"
chmod 700 "$DIR"
[ -f "$DB" ] || exit 0
sqlite3 "$DB" ".backup '$DIR/loop-$(date +%F-%H%M).sqlite'"
find "$DIR" -name 'loop-*.sqlite' -mtime +30 -delete
