#!/usr/bin/env bash
#
# Skrip update backend Nontix (cPanel).
#
# Cara pakai:
#   1. Upload nontix-backend-update.tar.gz ke folder ini
#   2. Jalankan:  bash update.sh
#
set -e

cd "$(dirname "$0")"

PKG=$(ls -t nontix-backend-update.tar.gz 2>/dev/null | head -1)
if [ -z "$PKG" ]; then
  echo "!! Paket nontix-backend-update.tar.gz tidak ditemukan di folder ini."
  echo "   Upload paketnya dulu, lalu jalankan ulang: bash update.sh"
  exit 1
fi

echo "==> [1/8] Backup .env"
if [ -f .env ]; then
  cp .env .env.backup
  echo "    .env -> .env.backup"
fi

echo "==> [2/8] Extract $PKG"
tar -xzf "$PKG"
rm -f "$PKG"

echo "==> [3/8] Bersihkan cache bootstrap"
rm -f bootstrap/cache/*.php

echo "==> [4/8] Perbaiki permission"
find app bootstrap config database public resources routes storage tests -type d -exec chmod 755 {} \; 2>/dev/null || true
find app bootstrap config database public resources routes storage tests -type f -exec chmod 644 {} \; 2>/dev/null || true
chmod -R u+rwX storage bootstrap 2>/dev/null || true

echo "==> [5/8] Composer autoload"
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-interaction
else
  php /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null \
    || echo "    (composer dilewati - pastikan vendor/ sudah ada)"
fi

echo "==> [6/8] Migrasi database & storage link"
php artisan migrate --force
php artisan storage:link 2>/dev/null || true

echo "==> [7/8] Cache ulang"
php artisan optimize:clear
php artisan config:cache

echo "==> [8/8] Cek health"
APP_DOMAIN=$(grep -E '^APP_URL=' .env | cut -d= -f2-)
curl -s -o /dev/null -w "    up=%{http_code}\n" "$APP_DOMAIN/up" || true

echo ""
echo "==> UPDATE SELESAI."
echo "    .env aman (backup: .env.backup)"