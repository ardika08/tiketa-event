#!/usr/bin/env bash
#
# ============================================================
# Setup SERVER pertama kali (dijalankan sekali di server cPanel)
# ============================================================
#
# Langkah:
#   git clone https://github.com/ardika08/tiketa-event.git ~/nontix-repo
#   cd ~/nontix-repo
#   bash setup-server.sh
# ============================================================
set -e

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
BE_SRC="$REPO_DIR/backend"
BE_DST="$HOME/apitix.diamcreative.com"
FE_SRC="$REPO_DIR/frontend/dist"
FE_DST="$HOME/tiket.diamcreative.com"

echo "==> [1/5] Amankan .env lama"
if [ -f "$BE_DST/.env" ]; then
  cp "$BE_DST/.env" /tmp/nontix-env-backup
  echo "    .env -> /tmp/nontix-env-backup"
else
  echo "    !! .env tidak ditemukan di $BE_DST"
  echo "       Buat dari template: cp $BE_SRC/.env.production.example $BE_DST/.env"
fi

echo "==> [2/5] Sinkronkan backend dari repo"
mkdir -p "$BE_DST"
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete \
    --exclude '.env' --exclude '.env.backup' \
    --exclude 'vendor/' \
    --exclude 'public/storage' \
    --exclude 'storage/app/' --exclude 'storage/logs/' --exclude 'storage/framework/' \
    --exclude 'bootstrap/cache/' \
    "$BE_SRC/" "$BE_DST/"
else
  tar -C "$BE_SRC" \
    --exclude=.env --exclude=.env.backup \
    --exclude=vendor --exclude=public/storage \
    --exclude=storage/app --exclude=storage/logs \
    --exclude=storage/framework --exclude=bootstrap/cache \
    -cf - . | tar -C "$BE_DST" -xf -
fi
[ -f /tmp/nontix-env-backup ] && cp /tmp/nontix-env-backup "$BE_DST/.env"

echo "==> [3/5] Composer install"
cd "$BE_DST"
rm -f bootstrap/cache/*.php
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-interaction
else
  php /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
fi

find app bootstrap config database public resources routes storage tests -type d -exec chmod 755 {} \; 2>/dev/null || true
find app bootstrap config database public resources routes storage tests -type f -exec chmod 644 {} \; 2>/dev/null || true
chmod -R u+rwX storage bootstrap

echo "==> [4/5] Migrasi & cache"
php artisan migrate --force
php artisan storage:link 2>/dev/null || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> [5/5] Frontend ke $FE_DST"
if [ -d "$FE_SRC" ]; then
  mkdir -p "$FE_DST"
  rm -rf "$FE_DST/assets" "$FE_DST/index.html"
  cp -R "$FE_SRC"/. "$FE_DST"/
  chmod -R 755 "$FE_DST"
fi

echo ""
echo "==> SETUP SELESAI. Update berikutnya cukup: bash deploy.sh"