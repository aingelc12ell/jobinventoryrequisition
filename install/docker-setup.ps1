#Requires -Version 7.0

<#
.SYNOPSIS
    Docker Desktop deployment for the Job & Inventory Request Management System.

.DESCRIPTION
    Provisions the entire application stack using Docker containers:
      - PHP 8.4-FPM (application)
      - Nginx 1.27 (web server)
      - MySQL 8.4 LTS (database)

    The script generates configuration, builds images, runs database
    migrations, installs Composer dependencies, creates an admin user
    with a random password, and seeds default system settings.

.PARAMETER DbName
    MySQL database name. Default: job_inventory_requests

.PARAMETER DbUser
    MySQL application user. Default: jir_app

.PARAMETER AdminEmail
    Admin account email address. Default: admin@jir.local

.PARAMETER AdminName
    Admin account display name. Default: System Administrator

.PARAMETER AppPort
    Host port mapped to Nginx. Default: 8080

.PARAMETER DbExternalPort
    Host port mapped to MySQL (for external tools). Default: 3306

.PARAMETER SkipBuild
    Skip container build/start (useful for re-running migrations only).

.EXAMPLE
    .\install\docker-setup.ps1

.EXAMPLE
    .\install\docker-setup.ps1 -AppPort 9000 -AdminEmail admin@company.com

.NOTES
    Requires Docker Desktop for Windows with WSL 2 backend.
    Run from the project root directory.
#>

[CmdletBinding()]
param(
    [string]$DbName          = 'job_inventory_requests',
    [string]$DbUser          = 'jir_app',
    [string]$AdminEmail      = 'admin@jir.local',
    [string]$AdminName       = 'System Administrator',
    [int]   $AppPort         = 8080,
    [int]   $DbExternalPort  = 3306,
    [string]$AppDebug = 'true',
    [switch]$SkipBuild
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ================================================================
# HELPERS
# ================================================================

function Write-Step  { param([string]$Msg) Write-Host "`n[STEP] $Msg" -ForegroundColor Cyan }
function Write-Ok    { param([string]$Msg) Write-Host "  + $Msg"     -ForegroundColor Green }
function Write-Warn  { param([string]$Msg) Write-Host "  ! $Msg"     -ForegroundColor Yellow }
function Write-Err   { param([string]$Msg) Write-Host "  x $Msg"     -ForegroundColor Red }
function Write-Info  { param([string]$Msg) Write-Host "  > $Msg"     -ForegroundColor White }

function New-SecurePassword {
    <#
    .SYNOPSIS
        Generate a cryptographically random password that satisfies the app policy.
    #>
    param([int]$Length = 20)

    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
    $lower   = 'abcdefghjkmnpqrstuvwxyz'
    $digits  = '23456789'
    # Avoid # $ ` " ' \ which break .env parsing across docker-compose and PHP dotenv
    $special = '!@%^&*-_=+'

    # Guarantee at least one character from each class
    $parts = [System.Collections.Generic.List[char]]::new()
    $parts.Add($upper[(Get-SecureRandom -Maximum $upper.Length)])
    $parts.Add($lower[(Get-SecureRandom -Maximum $lower.Length)])
    $parts.Add($digits[(Get-SecureRandom -Maximum $digits.Length)])
    $parts.Add($special[(Get-SecureRandom -Maximum $special.Length)])

    $all = $upper + $lower + $digits
    for ($i = $parts.Count; $i -lt $Length; $i++) {
        $parts.Add($all[(Get-SecureRandom -Maximum $all.Length)])
    }

    # Shuffle with Fisher-Yates
    for ($i = $parts.Count - 1; $i -gt 0; $i--) {
        $j = Get-SecureRandom -Maximum ($i + 1)
        $tmp = $parts[$i]; $parts[$i] = $parts[$j]; $parts[$j] = $tmp
    }

    return -join $parts
}

function New-HexSecret {
    param([int]$Bytes = 32)
    $buf = [byte[]]::new($Bytes)
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($buf)
    return ($buf | ForEach-Object { $_.ToString('x2') }) -join ''
}

function Invoke-Docker {
    <#
    .SYNOPSIS
        Run a docker compose command and throw on failure.
    #>
    param([string[]]$Arguments)

    $cmd = "docker compose -f `"$script:ComposeFile`" --env-file `"$script:EnvFile`" $($Arguments -join ' ')"
    Write-Verbose "Running: $cmd"
    $output = Invoke-Expression $cmd 2>&1

    if ($LASTEXITCODE -ne 0) {
        $output | ForEach-Object { Write-Err $_ }
        throw "Docker command failed (exit code $LASTEXITCODE): $cmd"
    }

    return $output
}

function Wait-ForMySQL {
    <#
    .SYNOPSIS
        Poll until the MySQL container passes its healthcheck.
    #>
    param([int]$TimeoutSeconds = 120)

    Write-Info "Waiting for MySQL to accept connections (timeout: ${TimeoutSeconds}s)..."

    $elapsed = 0
    $interval = 3

    while ($elapsed -lt $TimeoutSeconds) {
        $health = docker inspect --format '{{.State.Health.Status}}' jir-db 2>$null
        if ($health -eq 'healthy') {
            Write-Ok "MySQL is healthy"
            return
        }
        Start-Sleep -Seconds $interval
        $elapsed += $interval
        Write-Host "." -NoNewline
    }

    Write-Host ""
    throw "MySQL did not become healthy within ${TimeoutSeconds} seconds."
}

function Invoke-SqlFile {
    <#
    .SYNOPSIS
        Execute a SQL file inside the MySQL container after stripping
        CREATE DATABASE and USE statements.
    #>
    param(
        [string]$FilePath,
        [string]$Label
    )

    $sql = Get-Content -Path $FilePath -Raw -Encoding utf8

    # Strip CREATE DATABASE ... ; blocks (may span multiple lines)
    $sql = $sql -replace '(?si)CREATE\s+DATABASE\s+.*?;', ''

    # Strip USE <dbname>; lines
    $sql = $sql -replace '(?mi)^\s*USE\s+\S+\s*;\s*$', ''

    # Write cleaned SQL to a temp file
    $tempFile = Join-Path $script:ProjectRoot '.docker-migration.tmp.sql'
    $sql | Set-Content -Path $tempFile -NoNewline -Encoding utf8NoBOM

    try {
        # Copy into container
        docker cp $tempFile "jir-db:/tmp/migration.sql" 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Failed to copy migration to container" }

        # Execute
        $result = docker exec jir-db mysql `
            -u root "-p$script:MysqlRootPass" $script:DbName `
            -e "source /tmp/migration.sql" 2>&1

        if ($LASTEXITCODE -ne 0) {
            $result | ForEach-Object { Write-Err $_ }
            throw "Migration failed: $Label"
        }

        Write-Ok $Label
    }
    finally {
        Remove-Item -Path $tempFile -ErrorAction SilentlyContinue
    }
}

# ================================================================
# BANNER
# ================================================================

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "  Job & Inventory Request System — Docker Deployment" -ForegroundColor Cyan
Write-Host "  PHP 8.4-FPM  |  Nginx 1.27  |  MySQL 8.4 LTS" -ForegroundColor Cyan
Write-Host "================================================================" -ForegroundColor Cyan

# ================================================================
# 1. PRE-FLIGHT CHECKS
# ================================================================

Write-Step "Pre-flight checks..."

# PowerShell version
$psVer = $PSVersionTable.PSVersion
if ($psVer.Major -lt 7) {
    throw "PowerShell 7.0+ is required (current: $psVer). Install from https://aka.ms/powershell"
}
Write-Ok "PowerShell $psVer"

# Docker Desktop
try {
    $dockerVer = docker version --format '{{.Server.Version}}' 2>$null
    if ($LASTEXITCODE -ne 0) { throw }
    Write-Ok "Docker Engine $dockerVer"
}
catch {
    Write-Err "Docker Desktop is not running or not installed."
    Write-Err "Install from https://www.docker.com/products/docker-desktop/"
    Write-Err "Ensure WSL 2 backend is enabled in Docker Desktop settings."
    exit 1
}

# Docker Compose v2
try {
    $composeVer = docker compose version --short 2>$null
    if ($LASTEXITCODE -ne 0) { throw }
    Write-Ok "Docker Compose $composeVer"
}
catch {
    Write-Err "Docker Compose v2 is required (built into Docker Desktop)."
    exit 1
}

# ================================================================
# 2. RESOLVE PATHS
# ================================================================

Write-Step "Resolving project paths..."

$ScriptDir   = $PSScriptRoot
$ProjectRoot = Split-Path -Parent $ScriptDir

# Validate we're in the right repo
if (-not (Test-Path (Join-Path $ProjectRoot 'composer.json'))) {
    throw "Cannot find composer.json in $ProjectRoot — run this script from the project root or the install/ directory."
}

$ComposeFile = Join-Path $ScriptDir 'docker-compose.yml'
$EnvFile     = Join-Path $ProjectRoot '.env'

Write-Ok "Project root: $ProjectRoot"
Write-Ok "Compose file: $ComposeFile"

# ================================================================
# 3. GENERATE SECRETS
# ================================================================

Write-Step "Generating secure credentials..."

$AppSecret     = New-HexSecret -Bytes 32
$DbPass        = New-SecurePassword -Length 24
$MysqlRootPass = New-SecurePassword -Length 24
$AdminPass     = New-SecurePassword -Length 16

Write-Ok "APP_SECRET generated (64 hex chars)"
Write-Ok "Database passwords generated"
Write-Ok "Admin password generated"

# ================================================================
# 4. CREATE .env FILE
# ================================================================

Write-Step "Creating .env configuration..."

if ($AppPort -eq 80) {
    $AppUrl = 'http://localhost'
} else {
    $AppUrl = "http://localhost:$AppPort"
}

$envContent = @"
# ==========================================================
# JIR — Environment Configuration (Docker Deployment)
# Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
# ==========================================================

# Application
APP_NAME="Job & Inventory Request System"
APP_ENV=production
APP_DEBUG=$AppDebug
APP_URL=$AppUrl
APP_SECRET=$AppSecret

# Database (container service name = db)
DB_HOST=db
DB_PORT=3306
DB_NAME=$DbName
DB_USER=$DbUser
DB_PASS="$DbPass"
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_general_ci

# Database SSL (optional — for encrypted connections to MySQL)
DB_SSL_ENABLED=false
DB_SSL_CA=
DB_SSL_CERT=
DB_SSL_KEY=
DB_SSL_VERIFY=true

# Docker-specific (used by docker-compose.yml)
MYSQL_ROOT_PASSWORD="$MysqlRootPass"
APP_PORT=$AppPort
DB_EXTERNAL_PORT=$DbExternalPort

# Mail
MAIL_DRIVER=smtp
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Job & Inventory System"

# SMTP settings (when MAIL_DRIVER=smtp)
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587
MAIL_USER=
MAIL_PASS=
MAIL_ENCRYPTION=tls

# SendGrid settings (when MAIL_DRIVER=sendgrid)
SENDGRID_API_KEY=

# Session
SESSION_LIFETIME=1800
SESSION_NAME=jir_session
"@

$envContent | Set-Content -Path $EnvFile -Encoding utf8NoBOM
Write-Ok ".env created at $EnvFile"

# ================================================================
# 5. STORAGE DIRECTORIES
# ================================================================

Write-Step "Creating storage directories..."

$storageDirs = @(
    'storage/cache/twig',
    'storage/cache/rate_limit',
    'storage/logs',
    'storage/uploads'
)

foreach ($dir in $storageDirs) {
    $fullPath = Join-Path $ProjectRoot $dir
    if (-not (Test-Path $fullPath)) {
        New-Item -ItemType Directory -Path $fullPath -Force | Out-Null
    }
    Write-Ok $dir
}

# ================================================================
# 6. BUILD & START CONTAINERS
# ================================================================

if (-not $SkipBuild) {
    Write-Step "Building and starting Docker containers..."
    Write-Info "This may take a few minutes on first run..."

    Invoke-Docker @('build', '--no-cache', 'app')
    Write-Ok "PHP 8.4-FPM image built"

    Invoke-Docker @('up', '-d')
    Write-Ok "All containers started"

    # Show running containers
    $running = docker ps --filter "name=jir-" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" 2>$null
    Write-Host ""
    $running | ForEach-Object { Write-Info $_ }
    Write-Host ""
}
else {
    Write-Warn "Skipping build (--SkipBuild). Containers must already be running."
}

# ================================================================
# 7. WAIT FOR MYSQL
# ================================================================

Write-Step "Waiting for database..."
Wait-ForMySQL -TimeoutSeconds 120

# ================================================================
# 8. INSTALL COMPOSER DEPENDENCIES
# ================================================================

Write-Step "Installing PHP dependencies via Composer..."

$composerOutput = docker exec -w /var/www/html jir-app `
    composer install --no-dev --optimize-autoloader --no-interaction 2>&1

if ($LASTEXITCODE -ne 0) {
    $composerOutput | ForEach-Object { Write-Err $_ }
    throw "Composer install failed."
}
Write-Ok "Dependencies installed (production)"

# ================================================================
# 9. FIX STORAGE PERMISSIONS
# ================================================================

Write-Step "Setting storage permissions..."

docker exec jir-app chown -R www-data:www-data /var/www/html/storage 2>&1 | Out-Null
docker exec jir-app chmod -R 775 /var/www/html/storage 2>&1 | Out-Null
Write-Ok "storage/ owned by www-data with 775"

# ================================================================
# 10. RUN DATABASE MIGRATIONS
# ================================================================

Write-Step "Running database migrations..."

$schemaFile   = Join-Path $ProjectRoot 'database/schema.sql'
$migration002 = Join-Path $ProjectRoot 'database/migrations/002_requests.sql'
$migration003 = Join-Path $ProjectRoot 'database/migrations/003_inventory.sql'
$migration004 = Join-Path $ProjectRoot 'database/migrations/004_messages.sql'
$migration005 = Join-Path $ProjectRoot 'database/migrations/005_performance_indexes.sql'

Invoke-SqlFile -FilePath $schemaFile   -Label "Phase 1 - Users & Auth tables"
Invoke-SqlFile -FilePath $migration002 -Label "Phase 2 - Requests tables"
Invoke-SqlFile -FilePath $migration003 -Label "Phase 3 - Inventory tables"
Invoke-SqlFile -FilePath $migration004 -Label "Phase 4 - Messaging tables"
Invoke-SqlFile -FilePath $migration005 -Label "Phase 9 - Performance indexes"

# ================================================================
# 11. CREATE ADMIN USER
# ================================================================

Write-Step "Creating admin user..."

# Hash password with Argon2id inside the PHP container
$adminHash = docker exec jir-app php -r "echo password_hash('$AdminPass', PASSWORD_ARGON2ID);" 2>$null

if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($adminHash)) {
    throw "Failed to generate Argon2id password hash."
}

$adminSql = @"
INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at)
VALUES ('$AdminEmail', '$adminHash', '$AdminName', 'admin', 1, NOW())
AS new_row
ON DUPLICATE KEY UPDATE
    password_hash     = new_row.password_hash,
    role              = 'admin',
    is_active         = 1,
    email_verified_at = NOW();
"@

$result = docker exec jir-db mysql `
    -u root "-p$MysqlRootPass" $DbName `
    -e $adminSql 2>&1

if ($LASTEXITCODE -ne 0) {
    $result | ForEach-Object { Write-Err $_ }
    throw "Failed to create admin user."
}
Write-Ok "Admin user created ($AdminEmail)"

# ================================================================
# 12. SEED DEFAULT SETTINGS
# ================================================================

Write-Step "Seeding default system settings..."

$settingsSql = @"
INSERT INTO settings (setting_key, setting_value) VALUES
    ('site_name', 'Job & Inventory Request System'),
    ('items_per_page', '15'),
    ('max_upload_size_mb', '10'),
    ('default_request_priority', 'medium'),
    ('notification_email_enabled', '1'),
    ('maintenance_mode', '0'),
    ('maintenance_message', 'The system is currently undergoing scheduled maintenance. Please try again later.')
AS new_row
ON DUPLICATE KEY UPDATE setting_value = new_row.setting_value;
"@

$result = docker exec jir-db mysql `
    -u root "-p$MysqlRootPass" $DbName `
    -e $settingsSql 2>&1

if ($LASTEXITCODE -ne 0) {
    $result | ForEach-Object { Write-Err $_ }
    throw "Failed to seed settings."
}
Write-Ok "Default settings seeded"

# ================================================================
# 13. VERIFY
# ================================================================

Write-Step "Verifying deployment..."

# Quick smoke test: curl the app
try {
    $response = Invoke-WebRequest -Uri $AppUrl -UseBasicParsing -TimeoutSec 10 -ErrorAction SilentlyContinue
    if ($response.StatusCode -eq 200 -or $response.StatusCode -eq 302) {
        Write-Ok "Application responding (HTTP $($response.StatusCode))"
    }
    else {
        Write-Warn "Application returned HTTP $($response.StatusCode)"
    }
}
catch {
    Write-Warn "Could not reach $AppUrl (may need a moment to warm up)"
}

# ================================================================
# DONE — SUMMARY
# ================================================================

Write-Host ""
Write-Host "================================================================" -ForegroundColor Green
Write-Host "  Installation Complete!" -ForegroundColor Green
Write-Host "================================================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Containers" -ForegroundColor White
Write-Host "    jir-app   PHP 8.4-FPM" -ForegroundColor Gray
Write-Host "    jir-web   Nginx 1.27  -> port $AppPort" -ForegroundColor Gray
Write-Host "    jir-db    MySQL 8.4   -> port $DbExternalPort" -ForegroundColor Gray
Write-Host ""
Write-Host "  Database" -ForegroundColor White
Write-Host "    Host (from host):      localhost:$DbExternalPort" -ForegroundColor Gray
Write-Host "    Host (from container): db:3306" -ForegroundColor Gray
Write-Host "    Name:                  $DbName" -ForegroundColor Gray
Write-Host "    User:                  $DbUser" -ForegroundColor Gray
Write-Host "    Password:              " -ForegroundColor Gray -NoNewline
Write-Host "$DbPass" -ForegroundColor Cyan
Write-Host "    Root password:         " -ForegroundColor Gray -NoNewline
Write-Host "$MysqlRootPass" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Admin Account" -ForegroundColor White
Write-Host "    Email:    " -ForegroundColor Gray -NoNewline
Write-Host "$AdminEmail" -ForegroundColor Cyan
Write-Host "    Password: " -ForegroundColor Gray -NoNewline
Write-Host "$AdminPass" -ForegroundColor Cyan
Write-Host ""
Write-Host "  ** Save these credentials now -- they will not be shown again. **" -ForegroundColor Yellow
Write-Host ""
Write-Host "  Open: " -ForegroundColor White -NoNewline
Write-Host "$AppUrl" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Container management:" -ForegroundColor White
Write-Host "    docker compose -f install/docker-compose.yml --env-file .env up -d      # Start" -ForegroundColor Gray
Write-Host "    docker compose -f install/docker-compose.yml --env-file .env down        # Stop" -ForegroundColor Gray
Write-Host "    docker compose -f install/docker-compose.yml --env-file .env logs -f     # Logs" -ForegroundColor Gray
Write-Host "    docker exec -it jir-app bash                                             # Shell" -ForegroundColor Gray
Write-Host ""
Write-Host "  Post-install:" -ForegroundColor White
Write-Host "    * Edit .env to configure mail (SMTP or SendGrid)" -ForegroundColor Gray
Write-Host "    * Change admin password after first login" -ForegroundColor Gray
Write-Host "    * Run: docker compose -f install/docker-compose.yml --env-file .env restart app" -ForegroundColor Gray
Write-Host "      after .env changes" -ForegroundColor Gray
Write-Host ""
Write-Host "================================================================" -ForegroundColor Green
