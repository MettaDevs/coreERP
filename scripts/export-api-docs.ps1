$repoRoot = Split-Path -Parent $PSScriptRoot
$core = Join-Path $repoRoot 'apps\core'
$moduleContracts = Join-Path $core 'contracts\modules'

Push-Location $core
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
