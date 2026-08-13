# deploy.ps1 — Satu perintah untuk build + deploy ke Docker
# Cara pakai: .\deploy.ps1
# Atau dari mana saja: powershell -File "c:\PKL\coreERP\apps\control-plane\deploy.ps1"

$ErrorActionPreference = "Stop"
$projectDir = $PSScriptRoot

Write-Host "`n[1/4] Build Vite..." -ForegroundColor Cyan
$env:PATH = "$projectDir;$env:PATH"
Set-Location $projectDir
npx vite build
if ($LASTEXITCODE -ne 0) { Write-Host "Build gagal!" -ForegroundColor Red; exit 1 }

Write-Host "`n[2/4] Hapus build lama di Docker..." -ForegroundColor Cyan
docker exec erp-core-app-1 rm -rf /var/www/html/public/build

Write-Host "`n[3/4] Copy build & source files ke Docker..." -ForegroundColor Cyan
docker cp "$projectDir\routes" erp-core-app-1:/var/www/html/
docker cp "$projectDir\app" erp-core-app-1:/var/www/html/
docker cp "$projectDir\bootstrap" erp-core-app-1:/var/www/html/
docker cp "$projectDir\database\migrations" erp-core-app-1:/var/www/html/database/
docker cp "$projectDir\resources" erp-core-app-1:/var/www/html/
docker cp "$projectDir\public\images" erp-core-app-1:/var/www/html/public/
docker cp "$projectDir\public\build" erp-core-app-1:/var/www/html/public/

Write-Host "`n[4/4] Jalankan migrasi & bersihkan cache Laravel..." -ForegroundColor Cyan
docker exec erp-core-app-1 php artisan migrate --force
docker exec erp-core-app-1 php artisan storage:link --force
docker exec erp-core-app-1 php artisan route:clear
docker exec erp-core-app-1 php artisan view:clear
docker exec erp-core-app-1 php artisan cache:clear
docker exec erp-core-app-1 php artisan config:clear

Write-Host "`n✅ Selesai! Tekan Ctrl+F5 di browser untuk melihat perubahan.`n" -ForegroundColor Green
