#!/usr/bin/env bash
#
# ============================================================
# Deploy Nontix dari GitHub (dijalankan di server cPanel)
# ============================================================
#
# Setup awal (sekali saja):
#   git clone https://github.com/ardika08/tiketa-event.git ~/nontix-repo
#   cd ~/nontix-repo
#   bash setup-server.sh
#
# Update berikutnya:
#   cd ~/nontix-repo
#   bash deploy.sh
# ============================================================
set -e

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
BE_DIR="$HOME/apitix.diamcreative.com"
FE_DIR="$HOME/tiket.diamcreative.com"

echo "==> [1/6] Tarik perubahan dari GitHub"
cd "$REPO_DIR"
git pull --ff-only

echo "==> [2/6] Sinkronkan backend ke $BE_DIR"
mkdir -p "$BE_DIR"

sync_tree() {
  local src="$1" dst="$2"
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
      --exclude '.env' --exclude '.env.backup' \
      --exclude 'vendor/' \
      --exclude 'public/storage' \
      --exclude 'storage/app/' --exclude 'storage/logs/' --exclude 'storage/framework/' \
      --exclude 'bootstrap/cache/' \
      "$src/" "$dst/"
  else
    tar -C "$src" \
      --exclude=.env --exclude=.env.backup \
      --exclude=vendor --exclude=public/storage \
      --exclude=storage/app --exclude=storage/logs \
      --exclude=storage/framework --exclude=bootstrap/cache \
      -cf - . | tar -C "$dst" -xf -
  fi
}

sync_tree "$REPO_DIR/backend" "$BE_DIR"

echo "==> [3/6] Composer install & permission"
cd "$BE_DIR"
rm -f bootstrap/cache/*.php
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-interaction
else
  php /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null \
    || echo "    (composer dilewati - vendor/ sudah ada)"
fi

find app bootstrap config database public resources routes storage tests -type d -exec chmod 755 {} \; 2>/dev/null || true
find app bootstrap config database public resources routes storage tests -type f -exec chmod 644 {} \; 2>/dev/null || true
chmod -R u+rwX storage bootstrap 2>/dev/null || true

echo "==> [4/6] Migrasi & cache backend"
php artisan migrate --force
php artisan storage:link 2>/dev/null || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> [5/6] Sinkronkan frontend ke $FE_DIR"
if [ -d "$REPO_DIR/frontend/dist" ]; then
  mkdir -p "$FE_DIR"
  rm -rf "$FE_DIR/assets" "$FE_DIR/index.html"
  cp -R "$REPO_DIR/frontend/dist"/. "$FE_DIR"/
  chmod -R 755 "$FE_DIR"
else
  echo "    (frontend/dist tidak ada, dilewati)"
fi

echo "==> [6/6] Health check"
APP_DOMAIN=$(grep -E '^APP_URL=' "$BE_DIR/.env" 2>/dev/null | cut -d= -f2-)
[ -z "$APP_DOMAIN" ] && APP_DOMAIN="https://apitix.diamcreative.com"
curl -s -o /dev/null -w "    backend /up = %{http_code}\n" "$APP_DOMAIN/up" || true

echo ""
echo "==> DEPLOY SELESAI. Hard refresh browser: Ctrl+Shift+R"