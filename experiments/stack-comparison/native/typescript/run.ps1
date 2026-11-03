param(
    [Parameter(Mandatory = $true)]
    [string]$EnvPath,
    [ValidateSet('hono', 'elysia')]
    [string]$Adapter = 'hono',
    [ValidatePattern('^(?:erp_compare_ts(?:_[a-z0-9]+)?|erp_compare_final_(?:hono|elysia))$')]
    [string]$Database = 'erp_compare_ts',
    [int]$Port = 0,
    [string]$TraceFile = ''
)

$ErrorActionPreference = 'Stop'
$env:COMPARE_ENV = (Resolve-Path -LiteralPath $EnvPath).Path
$env:DB_DATABASE = $Database
if ($Port -gt 0) {
    $env:PORT = [string]$Port
} else {
    Remove-Item Env:PORT -ErrorAction SilentlyContinue
}
if ($TraceFile -ne '') {
    $env:TRACE_FILE = [System.IO.Path]::GetFullPath($TraceFile)
} else {
    Remove-Item Env:TRACE_FILE -ErrorAction SilentlyContinue
}

Push-Location -LiteralPath $PSScriptRoot
try {
    & bun run "start:$Adapter"
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
