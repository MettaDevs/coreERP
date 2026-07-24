$repoRoot = Split-Path -Parent $PSScriptRoot
$controlPlane = Join-Path $repoRoot 'apps\control-plane'
$moduleContracts = Join-Path $controlPlane 'contracts\modules'

Push-Location $controlPlane
try {
    php artisan scramble:export --path=contracts\openapi.json
}
finally {
    Pop-Location
}

New-Item -ItemType Directory -Force -Path $moduleContracts | Out-Null
Get-ChildItem -Path (Join-Path $repoRoot 'modules') -Recurse -Filter openapi.yaml |
    Where-Object { $_.Directory.Name -eq 'contracts' } |
    ForEach-Object { Copy-Item $_.FullName (Join-Path $moduleContracts ($_.Directory.Parent.Name + '.yaml')) -Force }
