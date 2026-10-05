#!/usr/bin/env bash
#
# Skrip deploy Nontix backend di cPanel (dijalankan via Terminal).
# Pemakaian:  bash deploy.sh
#
set -e

cd "$(dirname "$0")"

echo "==> [1/7] Install dependency (production)"
composer install --no-dev --optimize-autoloader --no-interaction

if ! grep -qE "^APP_KEY=base64:" .env 2>/dev/null; then
  echo "==> [2/7] Generate APP_KEY"
  php artisan key:generate --force
else
  echo "==> [2/7] APP_KEY sudah ada, dilewati"
fi

echo "==> [3/7] Bersihkan cache lama"
php artisan optimize:clear || true

echo "==> [4/7] Migrasi database"
php artisan migrate --force

echo "==> [5/7] Storage link"
php artisan storage:link || true

echo "==> [6/7] Cache config, route, view"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> [7/7] Selesai. Jangan lupa set cron scheduler + queue."
php artisan about --only=environment 2>/dev/null || true
