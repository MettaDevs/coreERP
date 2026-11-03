param(
    [Parameter(Mandatory = $true)][string]$EnvironmentFile,
    [string]$Database = 'erp_compare_go',
    [string]$DatabaseHost = '127.0.0.1',
    [string]$DatabasePort = '18640',
    [string]$DatabaseUser = 'compare_admin',
    [string]$ListenAddress = '127.0.0.1:18642'
)

$ErrorActionPreference = 'Stop'
$configurationPath = (Resolve-Path -LiteralPath $EnvironmentFile).Path
foreach ($line in Get-Content -LiteralPath $configurationPath) {
    if ($line -match '^(DB_PASSWORD|APP_KEY|CACHE_PREFIX|SESSION_COOKIE|SESSION_LIFETIME)=(.*)$') {
        [Environment]::SetEnvironmentVariable($Matches[1], $Matches[2], 'Process')
    }
}
$env:DB_DATABASE = $Database
$env:DB_HOST = $DatabaseHost
$env:DB_PORT = $DatabasePort
$env:DB_USERNAME = $DatabaseUser
$env:LISTEN_ADDR = $ListenAddress
$binaryPath = Join-Path ([System.IO.Path]::GetTempPath()) ('coreerp-native-go-' + [guid]::NewGuid().ToString('N') + '.exe')
Push-Location -LiteralPath $PSScriptRoot
try {
    & go build -trimpath -o $binaryPath .
    if ($LASTEXITCODE -ne 0) { throw 'Go build failed.' }
    & $binaryPath
} finally {
    Pop-Location
    if (Test-Path -LiteralPath $binaryPath) { Remove-Item -LiteralPath $binaryPath }
}
