# Requires Docker Desktop with Linux containers. Runs only on localhost.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$envFile = Join-Path $dir '.env.local'
$composeFile = Join-Path $dir 'compose.yaml'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Install and start Docker Desktop before running Aqari Pro.'
}
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Desktop is not running (or cannot access the Docker Engine).'
}
& docker compose version *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Compose v2 is required.'
}

if (-not (Test-Path -LiteralPath $envFile)) {
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $appBytes = New-Object byte[] 32
        $dbBytes = New-Object byte[] 32
        $rng.GetBytes($appBytes)
        $rng.GetBytes($dbBytes)
        $appKey = 'base64:' + [Convert]::ToBase64String($appBytes)
        $dbPassword = -join ($dbBytes | ForEach-Object { $_.ToString('x2') })
        $config = "AQARI_APP_KEY=$appKey" + [Environment]::NewLine +
                  "AQARI_DB_PASSWORD=$dbPassword" + [Environment]::NewLine +
                  "AQARI_LOCAL_PORT=8080" + [Environment]::NewLine
        [System.IO.File]::WriteAllText(
            $envFile, $config, [System.Text.Encoding]::ASCII)
        Write-Host 'Generated private backend/deploy/local/.env.local'
    } finally {
        $rng.Dispose()
    }
}

$port = 8080
foreach ($line in [System.IO.File]::ReadAllLines($envFile)) {
    if ($line -match '^AQARI_LOCAL_PORT=(\d+)$') {
        $port = [int]$Matches[1]
    }
}
if ($port -lt 1024 -or $port -gt 65535) {
    throw 'AQARI_LOCAL_PORT must be between 1024 and 65535.'
}

$argsBase = @('compose', '--env-file', $envFile, '-f', $composeFile)
& docker @argsBase config --quiet
if ($LASTEXITCODE -ne 0) { throw 'Invalid local Compose configuration.' }
& docker @argsBase up --detach --build --wait db migrate app
if ($LASTEXITCODE -ne 0) { throw 'Local Aqari Pro containers failed to start.' }

$health = 'http://localhost:' + $port + '/up'
try {
    Invoke-WebRequest -Uri $health -UseBasicParsing -TimeoutSec 15 | Out-Null
} catch {
    throw "Backend did not respond to $health. Inspect: docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml logs app db"
}
Write-Host ''
Write-Host ("Aqari Pro is running: http://localhost:{0}/staging/" -f $port)
Write-Host 'Database data persists in a private Docker volume.'
Write-Host ''
Write-Host 'Create the first owner from the repository root:'
Write-Host 'docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml exec app php artisan aqari:provision-owner you@example.com --name="Owner Name"'
Write-Host ''
Write-Host 'For ClamAV signature updates before file upload tests:'
Write-Host 'docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml --profile maintenance run --rm clamav-update'
Write-Host ''
Write-Host 'To stop WITHOUT deleting data:'
Write-Host 'docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml down'
