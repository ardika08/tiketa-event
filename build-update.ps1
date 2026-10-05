# ============================================================
# Build paket update Nontix (jalankan di komputer lokal)
#   .\build-update.ps1
#
# Menghasilkan di folder ini:
#   - nontix-backend-update.tar.gz  (siap upload ke ~/apitix.diamcreative.com)
#   - nontix-frontend-dist.tar.gz   (siap upload ke ~/tiket.diamcreative.com)
# ============================================================

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

Write-Host "==> [1/4] Build frontend..." -ForegroundColor Cyan
Push-Location "$root\frontend"
npm run build
Pop-Location

Write-Host "==> [2/4] Siapkan staging backend (tanpa .env, vendor, cache)..." -ForegroundColor Cyan
$stage = "C:\Users\ARDIKA~1\AppData\Local\Temp\opencode\nontix-build"
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Path $stage -Force | Out-Null

robocopy "$root\backend" $stage /E /XD "$root\backend\vendor" "$root\backend\node_modules" "$root\backend\.git" "$root\backend\.idea" "$root\backend\bootstrap\cache" "$root\backend\storage\framework" "$root\backend\storage\logs" /XF .env database.sqlite .phpunit.result.cache | Out-Null

# Folder cache wajib ada (kosong + .gitignore saja)
New-Item -ItemType Directory -Path "$stage\bootstrap\cache" -Force | Out-Null
New-Item -ItemType Directory -Path "$stage\storage\framework\cache" -Force | Out-Null
New-Item -ItemType Directory -Path "$stage\storage\framework\sessions" -Force | Out-Null
New-Item -ItemType Directory -Path "$stage\storage\framework\views" -Force | Out-Null
New-Item -ItemType Directory -Path "$stage\storage\logs" -Force | Out-Null

# Salin skrip update frontend ke dist agar ikut dalam paket
Copy-Item "$root\frontend\update-frontend.sh" "$root\frontend\dist\update-frontend.sh" -Force

Write-Host "==> [3/4] Buat arsip backend + frontend..." -ForegroundColor Cyan
$backendZip = "$root\nontix-backend-update.tar.gz"
$frontendZip = "$root\nontix-frontend-dist.tar.gz"
if (Test-Path $backendZip) { Remove-Item $backendZip -Force }
if (Test-Path $frontendZip) { Remove-Item $frontendZip -Force }

tar -czf $backendZip -C $stage .
tar -czf $frontendZip -C "$root\frontend\dist" .

Write-Host "==> [4/4] Verifikasi..." -ForegroundColor Cyan
$bad = (tar -tzf $backendZip | Select-String "bootstrap/cache/packages\.php|bootstrap/cache/services\.php|bootstrap/cache/config\.php|storage/logs/laravel\.log|\.phpunit\.result|^\./\.env$" | Measure-Object).Count
if ($bad -gt 0) { Write-Host "!! PERINGATAN: ada file kotor di paket backend ($bad)" -ForegroundColor Red }
else { Write-Host "    Paket backend bersih (tanpa .env / cache / log)." -ForegroundColor Green }

Get-ChildItem $backendZip, $frontendZip | Select-Object Name, @{n='MB';e={[math]::Round($_.Length/1MB,2)}}

Write-Host ""
Write-Host "SELESAI. Langkah berikutnya:" -ForegroundColor Green
Write-Host "  Backend : upload nontix-backend-update.tar.gz ke ~/apitix.diamcreative.com lalu jalankan  bash update.sh"
Write-Host "  Frontend: upload nontix-frontend-dist.tar.gz ke ~/tiket.diamcreative.com lalu jalankan  bash update-frontend.sh"