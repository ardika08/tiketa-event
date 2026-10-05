# ============================================================
# Push update Nontix ke GitHub (jalankan di komputer lokal)
#
#   .\push-update.ps1 "pesan perubahan"
#
# Otomatis: build frontend -> commit -> push ke GitHub.
# Setelah itu di server cukup jalankan:  bash deploy.sh
# ============================================================

param(
  [string]$Message = "Update $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

Write-Host "==> [1/4] Build frontend..." -ForegroundColor Cyan
Push-Location "$root\frontend"
npm run build
Pop-Location

Write-Host "==> [2/4] Salin skrip update frontend ke dist..." -ForegroundColor Cyan
Copy-Item "$root\frontend\update-frontend.sh" "$root\frontend\dist\update-frontend.sh" -Force

Write-Host "==> [3/4] Commit perubahan..." -ForegroundColor Cyan
Set-Location $root
git add -A
git commit -m $Message 2>&1 | Select-Object -Last 2

Write-Host "==> [4/4] Push ke GitHub..." -ForegroundColor Cyan
git push 2>&1 | Select-Object -Last 2

Write-Host ""
Write-Host "SELESAI. Di server jalankan:" -ForegroundColor Green
Write-Host "  cd ~/nontix-repo && bash deploy.sh"