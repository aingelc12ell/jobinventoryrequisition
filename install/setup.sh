#!/usr/bin/env bash
# ============================================================
# Job & Inventory Request Management System — WSL/Debian Setup
# ============================================================
# Deploys the full application on a fresh Debian-based WSL
# instance with Nginx + PHP-FPM 8.4.
# Run as a regular user with sudo privileges.
#
# Usage:
#   chmod +x install/setup.sh
#   ./install/setup.sh
#
# What this script does:
#   1. Adds the Sury PHP repository and installs PHP 8.4-FPM
#   2. Installs Nginx and configures the virtual host
#   3. Installs Oracle MySQL 8.4 LTS and Composer
#   4. Creates the MySQL database and runs all migrations
#   5. Installs PHP dependencies via Composer
#   6. Generates a .env file with secure random secrets
#   7. Creates storage directories with proper permissions
#   8. Ensures parent directories are traversable by web server
#   9. Creates an admin user with a random password
#  10. Sets up a daily digest cron job
#  11. Starts Nginx + PHP-FPM, verifies, and prints credentials
# ============================================================

set -euo pipefail

# ── Colors ──────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No color

print_step()  { echo -e "\n${CYAN}${BOLD}[STEP]${NC} $1"; }
print_ok()    { echo -e "  ${GREEN}✓${NC} $1"; }
print_warn()  { echo -e "  ${YELLOW}!${NC} $1"; }
print_err()   { echo -e "  ${RED}✗${NC} $1"; }
print_info()  { echo -e "  ${BOLD}→${NC} $1"; }

# ── Helper: ensure 'other' has execute (traverse) on every
#    parent directory from $1 up to / so www-data can reach
#    the project root. Only adds o+x — no read access. ──────
ensure_parent_traversal() {
    local dir="$1"
    while [[ "$dir" != "/" ]]; do
        dir="$(dirname "$dir")"
        # Extract the 'other' permission digit (last char of octal mode)
        local other_perm
        other_perm=$(stat -c '%a' "$dir" 2>/dev/null | grep -o '.$')
        # If the execute bit is not set (digit is even), add o+x
        if (( (other_perm % 2) == 0 )); then
            sudo chmod o+x "$dir"
            print_ok "Added traverse permission (o+x): $dir"
        fi
    done
}

# ── Pre-flight checks ──────────────────────────────────────
if [[ $EUID -eq 0 ]]; then
    print_err "Do not run this script as root. Run as a regular user with sudo privileges."
    exit 1
fi

# Resolve project root (parent of install/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
print_info "Project directory: $PROJECT_DIR"

# ── Filesystem compatibility check ─────────────────────────
# Detect if project resides UNDER a Windows-mounted drive.
# mount outputs lines like: C:\ on /mnt/c type drvfs (rw,...)
# We check whether PROJECT_DIR starts with any drvfs/9p mount point.
IS_DRVFS=false
while IFS= read -r mount_line; do
    mount_point=$(echo "$mount_line" | awk '{print $3}')
    mount_type=$(echo "$mount_line" | awk '{print $5}')
    if [[ ("$mount_type" == "drvfs" || "$mount_type" == "9p") && "$PROJECT_DIR/" == "$mount_point/"* ]]; then
        IS_DRVFS=true
        break
    fi
done < <(mount 2>/dev/null)

if [[ "$IS_DRVFS" == "true" ]]; then
    print_warn "Project detected on Windows-mounted drive (drvfs/9p)."
    print_info "The web server will run as your user ($(whoami)) instead of www-data"
    print_info "to work around drvfs permission limitations."
    print_info "Recommendation: Move project to Linux filesystem (~/projects) for"
    print_info "better security and I/O performance."
fi

# ============================================================
# 0. SYSTEM PACKAGES
# ============================================================
print_step "Updating system packages..."
sudo apt update -qq
sudo apt upgrade -y -qq
sudo apt install -y openssl wget curl
print_ok "System updated"

# ── Configurable defaults ──────────────────────────────────
DB_NAME="${JIR_DB_NAME:-job_inventory_requests}"
DB_USER="${JIR_DB_USER:-jir_app}"
DB_PASS="${JIR_DB_PASS:-$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 24)}"
MYSQL_ROOT_PASS="${JIR_MYSQL_ROOT_PASS:-$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 24)}"
APP_PORT="${JIR_APP_PORT:-80}"
APP_DOMAIN="${JIR_APP_DOMAIN:-_}"
ADMIN_EMAIL="${JIR_ADMIN_EMAIL:-admin@jir.com}"
ADMIN_NAME="${JIR_ADMIN_NAME:-System Administrator}"

PHP_VER="8.4"
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
NGINX_SITE="jir"

# Determine the user identity that Nginx/PHP-FPM workers will run as.
# On native Linux filesystems this is www-data (standard).
# On drvfs/9p mounts this must be the current user because drvfs
# maps all files to the WSL user and www-data has no access.
CURRENT_USER="$(whoami)"
if [[ "$IS_DRVFS" == "true" ]]; then
    WEB_USER="$CURRENT_USER"
    WEB_GROUP="$CURRENT_USER"
else
    WEB_USER="www-data"
    WEB_GROUP="www-data"
fi

# ============================================================
# 1. PHP REPOSITORY & PACKAGES
# ============================================================

print_step "Adding Sury PHP repository for PHP ${PHP_VER}..."
sudo apt install -y -qq ca-certificates apt-transport-https lsb-release curl gnupg2
if [[ ! -f /usr/share/keyrings/sury-php.gpg ]]; then
    curl -fsSL https://packages.sury.org/php/apt.gpg | sudo gpg --dearmor -o /usr/share/keyrings/sury-php.gpg
    echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
        | sudo tee /etc/apt/sources.list.d/sury-php.list > /dev/null
    sudo apt update -qq
fi
print_ok "Sury repository configured"

print_step "Installing PHP ${PHP_VER}-FPM and required extensions..."
sudo apt install -y -qq \
    php${PHP_VER}-cli \
    php${PHP_VER}-fpm \
    php${PHP_VER}-mysql \
    php${PHP_VER}-mbstring \
    php${PHP_VER}-xml \
    php${PHP_VER}-curl \
    php${PHP_VER}-zip \
    php${PHP_VER}-intl \
    php${PHP_VER}-gd \
    php${PHP_VER}-opcache \
    unzip git cron
print_ok "PHP $(php -v | head -1 | awk '{print $2}') installed"

# ── Nginx ──────────────────────────────────────────────────
print_step "Installing Nginx..."
sudo apt install -y -qq nginx
print_ok "Nginx installed"

# ── Oracle MySQL 8.4 LTS ───────────────────────────────────
print_step "Installing Oracle MySQL 8.4 LTS..."

if ! dpkg -l mysql-server 2>/dev/null | grep -q "^ii"; then
    # Download and install the Oracle MySQL APT config package
    MYSQL_APT_DEB="mysql-apt-config_0.8.36-1_all.deb"
    if [[ ! -f "/tmp/${MYSQL_APT_DEB}" ]]; then
        curl -fsSL "https://dev.mysql.com/get/${MYSQL_APT_DEB}" -o "/tmp/${MYSQL_APT_DEB}"
        print_ok "MySQL APT config package downloaded"
    fi

    # Pre-seed selections: MySQL 8.4 LTS, no prompts
    sudo debconf-set-selections <<< "mysql-apt-config mysql-apt-config/select-server select mysql-8.4-lts"
    sudo debconf-set-selections <<< "mysql-apt-config mysql-apt-config/select-tools select Enabled"
    sudo DEBIAN_FRONTEND=noninteractive dpkg -i "/tmp/${MYSQL_APT_DEB}"
    sudo apt update -qq
    print_ok "Oracle MySQL 8.4 LTS repository added"

    # Pre-seed root password if provided to avoid interactive prompt
    if [[ -n "$MYSQL_ROOT_PASS" ]]; then
        sudo debconf-set-selections <<< "mysql-community-server mysql-community-server/root-pass password $MYSQL_ROOT_PASS"
        sudo debconf-set-selections <<< "mysql-community-server mysql-community-server/re-root-pass password $MYSQL_ROOT_PASS"
    fi
    sudo debconf-set-selections <<< "mysql-community-server mysql-community-server/default-auth-override select Use Strong Password Encryption (RECOMMENDED)"

    sudo DEBIAN_FRONTEND=noninteractive apt install -y -qq mysql-server mysql-client
    print_ok "Oracle MySQL $(mysql --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1) installed"
    rm -f "/tmp/${MYSQL_APT_DEB}"
else
    print_ok "MySQL already installed: $(mysql --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)"
fi

# Start MySQL if not running
if ! sudo service mysql status > /dev/null 2>&1; then
    sudo service mysql start
    print_ok "MySQL service started"
else
    print_ok "MySQL service already running"
fi

# ── Composer ───────────────────────────────────────────────
print_step "Installing Composer..."
if ! command -v composer &> /dev/null; then
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
        print_err "Composer installer checksum verification failed!"
        rm -f composer-setup.php
        exit 1
    fi
    sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f composer-setup.php
    print_ok "Composer $(composer --version 2>/dev/null | awk '{print $3}') installed"
else
    print_ok "Composer already installed: $(composer --version 2>/dev/null | awk '{print $3}')"
fi

# ============================================================
# 2. DATABASE SETUP
# ============================================================
print_step "Configuring MySQL database..."

# Oracle MySQL uses root password auth (not socket) — determine connection method
if [[ -n "$MYSQL_ROOT_PASS" ]]; then
    export MYSQL_PWD="${MYSQL_ROOT_PASS}"
    MYSQL_CMD="mysql -u root"
else
    # Try socket auth first, fall back to passwordless root
    if sudo mysql -e "SELECT 1" > /dev/null 2>&1; then
        MYSQL_CMD="sudo mysql"
    else
        MYSQL_CMD="mysql -u root"
    fi
fi

$MYSQL_CMD <<EOSQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOSQL
unset MYSQL_PWD
print_ok "Database '${DB_NAME}' created"
print_ok "Database user '${DB_USER}' created"

print_step "Running database migrations..."

# Use MYSQL_PWD to avoid command-line password warning
export MYSQL_PWD="$DB_PASS"

# Remove multi-line CREATE DATABASE and USE statements from schema.sql
sed '/CREATE DATABASE IF NOT EXISTS/,/;/d; /USE /d' "$PROJECT_DIR/database/schema.sql" \
    | mysql -u "$DB_USER" "$DB_NAME"
print_ok "Phase 1 — Users & Auth tables"

sed '/USE /d' "$PROJECT_DIR/database/migrations/002_requests.sql" \
    | mysql -u "$DB_USER" "$DB_NAME"
print_ok "Phase 2 — Requests tables"

sed '/USE /d' "$PROJECT_DIR/database/migrations/003_inventory.sql" \
    | mysql -u "$DB_USER" "$DB_NAME"
print_ok "Phase 3 — Inventory tables"

mysql -u "$DB_USER" "$DB_NAME" < "$PROJECT_DIR/database/migrations/004_messages.sql"
print_ok "Phase 4 — Messaging tables"

sed '/USE /d' "$PROJECT_DIR/database/migrations/005_performance_indexes.sql" \
    | mysql -u "$DB_USER" "$DB_NAME"
print_ok "Phase 9 — Performance indexes"

# ============================================================
# 3. PHP DEPENDENCIES
# ============================================================
print_step "Installing PHP dependencies..."
cd "$PROJECT_DIR"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
print_ok "Composer dependencies installed (production)"

# ============================================================
# 4. ENVIRONMENT CONFIGURATION
# ============================================================
print_step "Generating .env configuration..."

APP_SECRET="$(openssl rand -hex 32)"

# Determine APP_URL based on port
if [[ "$APP_PORT" == "80" ]]; then
    APP_URL="http://localhost"
else
    APP_URL="http://localhost:${APP_PORT}"
fi

cat > "$PROJECT_DIR/.env" <<ENVFILE
# Application
APP_NAME="Job & Inventory Request System"
APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL}
APP_SECRET=${APP_SECRET}

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

# Database SSL (for Google Cloud SQL)
DB_SSL_ENABLED=false
DB_SSL_CA=
DB_SSL_CERT=
DB_SSL_KEY=
DB_SSL_VERIFY=true

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
ENVFILE

print_ok ".env created"

# ============================================================
# 5. STORAGE DIRECTORIES & PERMISSIONS
# ============================================================
print_step "Creating storage directories and setting permissions..."

mkdir -p "$PROJECT_DIR/storage/cache/twig"
mkdir -p "$PROJECT_DIR/storage/cache/rate_limit"
mkdir -p "$PROJECT_DIR/storage/logs"
mkdir -p "$PROJECT_DIR/storage/uploads"
print_ok "storage/cache/twig/"
print_ok "storage/cache/rate_limit/"
print_ok "storage/logs/"
print_ok "storage/uploads/"

if [[ "$IS_DRVFS" == "false" ]]; then
    # ── Native Linux filesystem permissions ─────────────────
    # Project: owner=user, group=www-data, mode=750 (rwxr-x---)
    # Storage: mode=770 (rwxrwx---) so www-data can write logs/cache
    # .env:    mode=640 (rw-r-----) readable by www-data, not world
    sudo chown -R "$CURRENT_USER":"$WEB_GROUP" "$PROJECT_DIR"
    sudo chmod -R 750 "$PROJECT_DIR"
    sudo chmod -R 770 "$PROJECT_DIR/storage"
    sudo chown "$CURRENT_USER":"$WEB_GROUP" "$PROJECT_DIR/.env"
    sudo chmod 640 "$PROJECT_DIR/.env"
    print_ok "File permissions secured (750 project, 770 storage, 640 .env)"

    # ── Parent directory traversal ──────────────────────────
    # www-data must have execute (traverse) permission on every
    # directory from / down to PROJECT_DIR. Without this, Nginx
    # and PHP-FPM return 404 / "Primary script unknown".
    # We only add o+x (no read) — this is safe and minimal.
    print_info "Checking parent directory traversal for $WEB_USER..."
    ensure_parent_traversal "$PROJECT_DIR"
    print_ok "Parent directories traversable by $WEB_USER"
else
    # ── drvfs/9p mount (Windows drive) ──────────────────────
    # Linux permissions are not effective on drvfs mounts.
    # Instead we run Nginx/PHP-FPM workers as the current user
    # (who owns all files on the mount). See sections 6 & 7.
    print_warn "Skipping chmod/chown (ineffective on drvfs)"
    print_info "Web server will run as '$CURRENT_USER' to access drvfs files"
fi

# ============================================================
# 6. NGINX CONFIGURATION
# ============================================================
print_step "Configuring Nginx..."

# ── 6a. Main nginx.conf: set worker user ────────────────────
NGINX_MAIN_CONF="/etc/nginx/nginx.conf"

if [[ -f "$NGINX_MAIN_CONF" ]]; then
    # Read the current user directive
    CURRENT_NGINX_USER=$(grep -oP '^\s*user\s+\K\S+' "$NGINX_MAIN_CONF" | tr -d ';' || echo "")

    if [[ "$CURRENT_NGINX_USER" != "$WEB_USER" ]]; then
        sudo sed -i "s|^\s*user\s\+.*;|user ${WEB_USER};|" "$NGINX_MAIN_CONF"
        print_ok "Nginx worker user set to '${WEB_USER}' in nginx.conf"
    else
        print_ok "Nginx worker user already '${WEB_USER}'"
    fi
fi

# ── 6b. Virtual host ────────────────────────────────────────
sudo tee "/etc/nginx/sites-available/${NGINX_SITE}" > /dev/null <<NGINX
server {
    listen ${APP_PORT} default_server;
    listen [::]:${APP_PORT} default_server;

    server_name ${APP_DOMAIN};
    root ${PROJECT_DIR}/public;
    index index.php;

    charset utf-8;
    client_max_body_size 12M;

    # ── Logging ─────────────────────────────────────────────
    access_log /var/log/nginx/${NGINX_SITE}_access.log;
    error_log  /var/log/nginx/${NGINX_SITE}_error.log;

    # ── Front controller ────────────────────────────────────
    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    # ── PHP-FPM ─────────────────────────────────────────────
    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   \$realpath_root;

        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
        fastcgi_connect_timeout 60s;
        fastcgi_send_timeout 60s;
        fastcgi_read_timeout 60s;
    }

    # ── Static assets ───────────────────────────────────────
    location ~* \.(css|js|ico|gif|jpe?g|png|svg|woff2?|ttf|eot)\$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # ── Deny dotfiles ───────────────────────────────────────
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # ── Block access to sensitive paths ─────────────────────
    location ~ ^/(src|config|database|storage|cli|vendor|install|tests|templates)/ {
        deny all;
        return 404;
    }
}
NGINX
print_ok "Virtual host written to /etc/nginx/sites-available/${NGINX_SITE}"

# Enable site and disable default
sudo ln -sf "/etc/nginx/sites-available/${NGINX_SITE}" "/etc/nginx/sites-enabled/${NGINX_SITE}"
sudo rm -f /etc/nginx/sites-enabled/default
print_ok "Site enabled, default site disabled"

# Validate Nginx config
if sudo nginx -t 2>&1 | grep -q "successful"; then
    print_ok "Nginx configuration test passed"
else
    print_err "Nginx configuration test failed:"
    sudo nginx -t
    exit 1
fi

# ============================================================
# 7. PHP-FPM TUNING
# ============================================================
print_step "Tuning PHP-FPM ${PHP_VER} pool..."

FPM_POOL="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
FPM_INI="/etc/php/${PHP_VER}/fpm/php.ini"

# Pool: set worker identity + socket ownership
if [[ -f "$FPM_POOL" ]]; then
    # ── Worker process user/group ───────────────────────────
    # On native Linux FS: www-data (standard web user)
    # On drvfs: current user (owns all files on the mount)
    sudo sed -i "s|^\s*user\s*=.*|user = ${WEB_USER}|"   "$FPM_POOL"
    sudo sed -i "s|^\s*group\s*=.*|group = ${WEB_GROUP}|" "$FPM_POOL"
    print_ok "FPM pool worker: ${WEB_USER}:${WEB_GROUP}"

    # ── Socket path + ownership ─────────────────────────────
    # Socket owner must match the Nginx worker user so Nginx
    # can connect. The FPM master (root) creates the socket.
    sudo sed -i "s|^\s*listen\s*=.*|listen = ${FPM_SOCK}|"              "$FPM_POOL"
    sudo sed -i "s|^;*\s*listen\.owner.*|listen.owner = ${WEB_USER}|"   "$FPM_POOL"
    sudo sed -i "s|^;*\s*listen\.group.*|listen.group = ${WEB_GROUP}|"   "$FPM_POOL"
    sudo sed -i "s|^;*\s*listen\.mode.*|listen.mode = 0660|"            "$FPM_POOL"
    print_ok "FPM socket: ${FPM_SOCK} (owner ${WEB_USER}:${WEB_GROUP})"
fi

# php.ini production hardening
if [[ -f "$FPM_INI" ]]; then
    sudo sed -i "s|^upload_max_filesize.*|upload_max_filesize = 10M|"              "$FPM_INI"
    sudo sed -i "s|^post_max_size.*|post_max_size = 12M|"                          "$FPM_INI"
    sudo sed -i "s|^memory_limit.*|memory_limit = 128M|"                           "$FPM_INI"
    sudo sed -i "s|^max_execution_time.*|max_execution_time = 30|"                 "$FPM_INI"
    sudo sed -i "s|^expose_php.*|expose_php = Off|"                                "$FPM_INI"
    sudo sed -i "s|^;*session\.cookie_httponly.*|session.cookie_httponly = 1|"      "$FPM_INI"
    sudo sed -i "s|^;*session\.cookie_samesite.*|session.cookie_samesite = Strict|" "$FPM_INI"
    sudo sed -i "s|^;*session\.use_strict_mode.*|session.use_strict_mode = 1|"     "$FPM_INI"
    print_ok "php.ini hardened (uploads 10M, expose_php Off, secure sessions)"
fi

# ============================================================
# 8. CREATE ADMIN USER
# ============================================================
print_step "Creating admin user..."

# Generate a strong random password (16 chars: upper, lower, digits, special)
ADMIN_PASS="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9!@#$%&*' | head -c 14)"
# Ensure the password meets the policy (uppercase + lowercase + digit + special)
ADMIN_PASS="${ADMIN_PASS}A1!"

# Hash password with Argon2id via PHP
ADMIN_HASH="$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_ARGON2ID);")"

# MYSQL_PWD should already be set from earlier (Phase 1-4) or we set it again
export MYSQL_PWD="$DB_PASS"

mysql -u "$DB_USER" "$DB_NAME" <<EOSQL
INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at)
VALUES (
    '${ADMIN_EMAIL}',
    '${ADMIN_HASH}',
    '${ADMIN_NAME}',
    'admin',
    1,
    NOW()
) AS new_row
ON DUPLICATE KEY UPDATE
    password_hash = new_row.password_hash,
    role = 'admin',
    is_active = 1,
    email_verified_at = NOW();
EOSQL
print_ok "Admin user created"

# ============================================================
# 9. CRON JOB — Daily Digest
# ============================================================
print_step "Setting up daily digest cron job..."

CRON_CMD="0 8 * * * cd $PROJECT_DIR && /usr/bin/php cli/digest.php >> storage/logs/digest.log 2>&1"

# Get existing crontab, excluding our script if it's already there
# If crontab is empty, we just echo the new command
(crontab -l 2>/dev/null | grep -v "cli/digest.php" || true; echo "$CRON_CMD") | crontab -
sudo service cron start 2>/dev/null || true
print_ok "Daily digest scheduled at 08:00"

# ============================================================
# 10. SEED DEFAULT SETTINGS
# ============================================================
print_step "Seeding default system settings..."

export MYSQL_PWD="$DB_PASS"

mysql -u "$DB_USER" "$DB_NAME" <<EOSQL
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
EOSQL
unset MYSQL_PWD
print_ok "Default settings seeded"

# ============================================================
# 11. START & VERIFY SERVICES
# ============================================================
print_step "Starting services..."

# ── PHP-FPM ────────────────────────────────────────────────
sudo service php${PHP_VER}-fpm restart
sleep 1

if [[ -S "$FPM_SOCK" ]]; then
    print_ok "PHP-FPM ${PHP_VER} started (socket: ${FPM_SOCK})"
else
    print_err "PHP-FPM socket not found at ${FPM_SOCK}"
    print_info "Checking PHP-FPM status..."
    sudo service php${PHP_VER}-fpm status || true
    print_info "Checking PHP-FPM error log..."
    sudo tail -5 /var/log/php${PHP_VER}-fpm.log 2>/dev/null || true
    print_err "Fix the PHP-FPM issue above, then run: sudo service php${PHP_VER}-fpm restart"
    exit 1
fi

# ── Nginx ──────────────────────────────────────────────────
sudo service nginx restart
sleep 1

if sudo service nginx status > /dev/null 2>&1; then
    print_ok "Nginx started on port ${APP_PORT}"
else
    print_err "Nginx failed to start."
    print_info "Checking error log..."
    sudo tail -10 /var/log/nginx/error.log 2>/dev/null || true
    exit 1
fi

# ── Smoke test ─────────────────────────────────────────────
print_step "Running smoke test..."

SMOKE_URL="${APP_URL}/login"
SMOKE_STATUS=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$SMOKE_URL" 2>/dev/null || echo "000")

if [[ "$SMOKE_STATUS" == "200" || "$SMOKE_STATUS" == "302" ]]; then
    print_ok "Smoke test passed — HTTP ${SMOKE_STATUS} from ${SMOKE_URL}"
elif [[ "$SMOKE_STATUS" == "000" ]]; then
    print_warn "Smoke test: could not connect to ${SMOKE_URL}"
    print_info "This may be normal if WSL networking needs a moment."
    print_info "Try opening ${APP_URL} in your browser."
elif [[ "$SMOKE_STATUS" == "404" ]]; then
    print_err "Smoke test: HTTP 404 from ${SMOKE_URL}"
    print_info "Diagnosing..."

    # Check if Nginx can read the document root
    if ! sudo -u "$WEB_USER" test -r "${PROJECT_DIR}/public/index.php" 2>/dev/null; then
        print_err "  → ${WEB_USER} cannot read ${PROJECT_DIR}/public/index.php"
        print_info "  Fix: ensure parent directories have o+x and project has correct ownership"
    fi

    # Check if PHP-FPM can resolve the script
    if sudo -u "$WEB_USER" test -x "${PROJECT_DIR}/public" 2>/dev/null; then
        print_ok "  → ${WEB_USER} can traverse ${PROJECT_DIR}/public"
    else
        print_err "  → ${WEB_USER} cannot traverse ${PROJECT_DIR}/public"
    fi

    # Check Nginx error log for the actual cause
    print_info "  Last Nginx errors:"
    sudo tail -5 "/var/log/nginx/${NGINX_SITE}_error.log" 2>/dev/null || \
        sudo tail -5 /var/log/nginx/error.log 2>/dev/null || true

    print_info ""
    print_info "  Common fixes:"
    print_info "    1. sudo chmod o+x /home/${CURRENT_USER}"
    print_info "    2. sudo chown -R ${CURRENT_USER}:${WEB_GROUP} ${PROJECT_DIR}"
    print_info "    3. sudo chmod -R 750 ${PROJECT_DIR}"
    print_info "    4. sudo service php${PHP_VER}-fpm restart && sudo service nginx restart"
elif [[ "$SMOKE_STATUS" == "500" ]]; then
    print_warn "Smoke test: HTTP 500 — application error"
    print_info "Check the application log: tail -20 ${PROJECT_DIR}/storage/logs/app.log"
    print_info "Check PHP-FPM log: sudo tail -20 /var/log/php${PHP_VER}-fpm.log"
else
    print_warn "Smoke test: HTTP ${SMOKE_STATUS} from ${SMOKE_URL} (unexpected)"
fi

# ============================================================
# DONE — Print summary
# ============================================================
echo ""
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}${BOLD}  Installation Complete!${NC}"
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "  ${BOLD}Web Server${NC}"
echo -e "    Nginx → PHP-FPM ${PHP_VER} (Unix socket)"
echo -e "    Worker user: ${WEB_USER}"
echo -e "    Config: /etc/nginx/sites-available/${NGINX_SITE}"
echo -e "    Logs:   /var/log/nginx/${NGINX_SITE}_*.log"
echo ""
echo -e "  ${BOLD}Database (Oracle MySQL 8.4 LTS)${NC}"
echo -e "    Host:     127.0.0.1:3306"
echo -e "    Name:     ${DB_NAME}"
echo -e "    User:     ${DB_USER}"
echo -e "    Password: ${DB_PASS}"
echo -e "    Root:     ${MYSQL_ROOT_PASS}"
echo ""
echo -e "  ${BOLD}Admin Account${NC}"
echo -e "    Email:    ${CYAN}${ADMIN_EMAIL}${NC}"
echo -e "    Password: ${CYAN}${ADMIN_PASS}${NC}"
echo ""
echo -e "  ${YELLOW}⚠  Save these credentials now — they will not be shown again.${NC}"
echo ""
echo -e "  ${BOLD}Open:${NC} ${APP_URL}"
echo ""
echo -e "  ${BOLD}Service management:${NC}"
echo -e "    sudo service nginx restart"
echo -e "    sudo service php${PHP_VER}-fpm restart"
echo -e "    sudo service mysql restart"
echo ""
echo -e "  ${BOLD}Post-install:${NC}"
echo -e "    • Edit ${BOLD}.env${NC} to configure SMTP mail settings"
echo -e "    • Change admin password after first login"
echo -e "    • Set ${BOLD}JIR_APP_DOMAIN${NC} to your actual domain for production"
echo ""
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════════════${NC}"
