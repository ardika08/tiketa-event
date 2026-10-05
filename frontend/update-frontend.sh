#!/usr/bin/env bash
#
# Skrip update frontend Nontix (React SPA).
#
# Cara pakai:
#   1. Upload nontix-frontend-dist.tar.gz ke folder domain frontend
#   2. Jalankan:  bash update-frontend.sh
#
set -e

cd "$(dirname "$0")"

PKG=$(ls -t nontix-frontend-dist.tar.gz 2>/dev/null | head -1)
if [ -z "$PKG" ]; then
  echo "!! Paket nontix-frontend-dist.tar.gz tidak ditemukan di folder ini."
  exit 1
fi

echo "==> [1/3] Hapus build lama"
rm -rf assets index.html

echo "==> [2/3] Extract $PKG"
tar -xzf "$PKG"
rm -f "$PKG"

echo "==> [3/3] Verifikasi"
ls -la index.html assets/ | head -8

# Hapus skrip ini agar folder tetap bersih (akan ikut lagi di paket berikutnya).
rm -f update-frontend.sh

echo ""
echo "==> UPDATE FRONTEND SELESAI. Hard refresh browser: Ctrl+Shift+R"