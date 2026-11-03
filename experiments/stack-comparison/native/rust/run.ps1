param(
    [Parameter(Mandatory = $true)][string]$RuntimeEnv,
    [int]$Port = 18643,
    [string]$Database = 'erp_compare_rust',
    [string]$Image = 'coreerp-native-rust:local',
    [switch]$Build
)
$ErrorActionPreference = 'Stop'
$runtimePath = (Resolve-Path -LiteralPath $RuntimeEnv).Path
if ($Build) {
    docker build -t $Image $PSScriptRoot
    if ($LASTEXITCODE -ne 0) { throw 'Rust image build failed.' }
}
docker run --rm --name coreerp-native-rust --cpus 2 --memory 1g --env-file $runtimePath `
    -e DB_HOST=host.docker.internal -e DB_PORT=18640 -e DB_USERNAME=compare_admin `
    -e "DB_DATABASE=$Database" -e "PORT=$Port" -e BIND_HOST=0.0.0.0 `
    -p "127.0.0.1:${Port}:${Port}" $Image
if ($LASTEXITCODE -ne 0) { throw 'Rust service exited with an error.' }
