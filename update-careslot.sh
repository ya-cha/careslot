#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

# Install on the server as /update-careslot.sh, owned by root (0750).
export PATH=/opt/careslot/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export HOME=/root
readonly app=/var/www/careslot
readonly repository="$app/repository.git"
readonly php=/opt/careslot/bin/php
readonly branch=main
readonly domain=careslot.ya-cha.de
maintenance_started=0

if [[ $EUID -ne 0 ]]; then
    echo 'Bitte auf dem Server als root ausführen.' >&2
    exit 1
fi
if [[ $# -gt 1 || ( $# -eq 1 && $1 != --force ) ]]; then
    echo 'Aufruf: /update-careslot.sh [--force]' >&2
    exit 1
fi

exec 9>/run/lock/update-careslot.lock
flock -n 9 || { echo 'Ein Deployment läuft bereits.' >&2; exit 1; }

as_app() {
    runuser -u careslot -- env HOME=/home/careslot PATH="$PATH" "$@"
}

on_error() {
    local status=$?
    echo "Deployment fehlgeschlagen (Status $status)." >&2
    if [[ $maintenance_started == 1 ]]; then
        if [[ -f "$app/current/artisan" ]]; then
            as_app "$php" "$app/current/artisan" down --retry=60 --no-interaction || true
        fi
        systemctl stop careslot-queue.service careslot-scheduler.timer || true
        systemctl start php85-php-fpm || true
        echo 'Wartungsmodus bleibt aktiv. Datenbanksicherung unter /var/backups/careslot prüfen; keine automatischen Rückwärtsmigrationen.' >&2
    fi
    exit "$status"
}
trap on_error ERR

as_app git --git-dir="$repository" fetch --prune origin "+refs/heads/$branch:refs/heads/$branch"
commit=$(as_app git --git-dir="$repository" rev-parse "refs/heads/$branch")
if [[ ${1:-} != --force && -f "$app/current/.deployed-commit" ]] && [[ $(cat "$app/current/.deployed-commit") == "$commit" ]]; then
    echo "Bereits aktuell: $commit"
    exit 0
fi

stamp=$(date -u +%Y%m%dT%H%M%SZ)
release="$app/releases/${stamp}-${commit:0:12}"
install -d -o careslot -g careslot -m 755 "$release"
as_app git --git-dir="$repository" archive "$commit" | as_app tar -xf - -C "$release"
as_app ln -s "$app/shared/.env" "$release/.env"
as_app mv "$release/storage" "$release/storage-template"
as_app ln -s "$app/shared/storage" "$release/storage"
as_app rm -rf "$release/storage-template"
as_app chmod 755 "$release" "$release/public"
cd "$release"
as_app composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
as_app composer check-platform-reqs --no-dev
as_app npm ci --no-audit --no-fund
as_app npm run build
test -s public/build/manifest.json
as_app chmod -R o+rX "$release/public"

# Stop all writers before making the database backup and applying migrations.
maintenance_started=1
systemctl stop careslot-scheduler.timer
systemctl stop careslot-scheduler.service careslot-queue.service
if [[ -f "$app/current/artisan" ]]; then
    as_app "$php" "$app/current/artisan" down --retry=60 --no-interaction
fi
systemctl stop php85-php-fpm
install -d -m 700 "/var/backups/careslot/$stamp"
runuser -u postgres -- pg_dump --format=custom careslot > "/var/backups/careslot/$stamp/database.dump"
cp "$app/shared/.env" "/var/backups/careslot/$stamp/.env"
tar -czf "/var/backups/careslot/$stamp/storage.tar.gz" -C "$app/shared" storage
as_app "$php" artisan migrate --force --no-interaction
as_app "$php" artisan optimize --no-interaction
as_app "$php" artisan queue:restart --no-interaction
printf '%s\n' "$commit" > "$release/.deployed-commit"
chown careslot:careslot "$release/.deployed-commit"
ln -s "$release" "$app/current.next"
mv -Tf "$app/current.next" "$app/current"
systemctl start php85-php-fpm
as_app "$php" artisan up --no-interaction
systemctl start careslot-queue.service careslot-scheduler.timer
curl --fail --silent --show-error --retry 3 --retry-delay 2 --resolve "$domain:443:127.0.0.1" "https://$domain/up" > /dev/null
maintenance_started=0
echo "Deployment erfolgreich: $commit"
echo "Sicherung: /var/backups/careslot/$stamp"
