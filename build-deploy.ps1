# ============================================================
# Membuat paket deploy Nontix (backend + frontend).
# Jalankan dari root project:  powershell -File build-deploy.ps1
# Hasil:
#   deploy/nontix-backend.zip
#   deploy/nontix-backend.tar.gz
#   deploy/nontix-frontend-dist.zip
#   deploy/nontix-frontend-dist.tar.gz
# ============================================================

$ErrorActionPreference = 'Stop'

$root = $PSScriptRoot
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'
$deployDir = Join-Path $root 'deploy'
$staging = Join-Path $env:TEMP ('nontix-deploy-' + [Guid]::NewGuid().ToString('N'))
$backendStage = Join-Path $staging 'backend'
$frontendStage = Join-Path $staging 'frontend'

Add-Type -AssemblyName System.IO.Compression.FileSystem

function Copy-Tree($source, $destination, [string[]]$excludeDirs) {
    New-Item -ItemType Directory -Force -Path $destination | Out-Null
    $robocopyArgs = @($source, $destination, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP')
    if ($excludeDirs.Count -gt 0) {
        $robocopyArgs += '/XD'
        $robocopyArgs += $excludeDirs
    }
    & robocopy @robocopyArgs | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy gagal ($LASTEXITCODE) untuk: $source" }
}

Write-Host '==> [1/4] Menyiapkan staging backend...'
# vendor ikut disertakan (hosting mungkin tanpa composer), node_modules & storage runtime dikecualikan.
Copy-Tree $backend $backendStage @(
    (Join-Path $backend 'node_modules'),
    (Join-Path $backend 'storage\logs'),
    (Join-Path $backend 'storage\framework\cache\data'),
    (Join-Path $backend 'storage\framework\sessions'),
    (Join-Path $backend 'storage\framework\testing'),
    (Join-Path $backend 'storage\framework\views'),
    (Join-Path $backend 'bootstrap\cache')
)

# File runtime lokal tidak boleh ikut ter-deploy.
$backendExcludeFiles = @('.env', '.phpunit.result.cache')
foreach ($file in $backendExcludeFiles) {
    $path = Join-Path $backendStage $file
    if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force }
}

# Pastikan folder runtime ada namun kosong (kecuali .gitignore).
New-Item -ItemType Directory -Force -Path (Join-Path $backendStage 'storage\logs') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $backendStage 'storage\framework\cache\data') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $backendStage 'storage\framework\sessions') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $backendStage 'storage\framework\testing') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $backendStage 'storage\framework\views') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $backendStage 'bootstrap\cache') | Out-Null
foreach ($keep in @('storage\logs\.gitignore', 'storage\framework\.gitignore', 'storage\framework\cache\.gitignore', 'storage\framework\cache\data\.gitignore', 'storage\framework\sessions\.gitignore', 'storage\framework\testing\.gitignore', 'storage\framework\views\.gitignore', 'bootstrap\cache\.gitignore')) {
    $src = Join-Path $backend $keep
    $dst = Join-Path $backendStage $keep
    if (Test-Path -LiteralPath $src) {
        New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent) | Out-Null
        Copy-Item -LiteralPath $src -Destination $dst -Force
    }
}

Write-Host '==> [2/4] Menyiapkan staging frontend...'
Copy-Tree (Join-Path $frontend 'dist') $frontendStage @()

Write-Host '==> [3/4] Membuat arsip...'
New-Item -ItemType Directory -Force -Path $deployDir | Out-Null

$backendZip = Join-Path $deployDir 'nontix-backend.zip'
$frontendZip = Join-Path $deployDir 'nontix-frontend-dist.zip'
if (Test-Path -LiteralPath $backendZip) { Remove-Item -LiteralPath $backendZip -Force }
if (Test-Path -LiteralPath $frontendZip) { Remove-Item -LiteralPath $frontendZip -Force }
[System.IO.Compression.ZipFile]::CreateFromDirectory($backendStage, $backendZip, [System.IO.Compression.CompressionLevel]::Optimal, $false)
[System.IO.Compression.ZipFile]::CreateFromDirectory($frontendStage, $frontendZip, [System.IO.Compression.CompressionLevel]::Optimal, $false)

# tar.gz via tar bawaan Windows 10+ / Git Bash.
$tar = Get-Command tar -ErrorAction SilentlyContinue
if ($tar) {
    & tar -czf (Join-Path $deployDir 'nontix-backend.tar.gz') -C $backendStage .
    & tar -czf (Join-Path $deployDir 'nontix-frontend-dist.tar.gz') -C $frontendStage .
} else {
    Write-Host '    tar tidak tersedia — hanya .zip yang dibuat.'
}

Write-Host '==> [4/4] Membersihkan staging...'
Remove-Item -LiteralPath $staging -Recurse -Force

Write-Host ''
Write-Host 'SELESAI. Paket deploy:'
Get-ChildItem $deployDir | Select-Object Name, @{N = 'SizeMB'; E = { [Math]::Round($_.Length / 1MB, 2) } }, LastWriteTime | Format-Table -AutoSize