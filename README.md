# Job & Inventory Request Management System

A responsive web application for managing job requests and inventory workflows across three user roles. Built with **Slim PHP 4**, **MySQL 8.4**, and **Twig 3**, following a modular Controller &rarr; Service &rarr; Repository architecture with event-driven extensibility.

---

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Docker Desktop (Windows)](#docker-desktop-windows)
  - [Automated (WSL / Debian)](#automated-wsl--debian)
  - [XAMPP / WAMP (Windows)](#xampp--wamp-windows)
  - [IIS (Windows Server / Windows 10+)](#iis-windows-server--windows-10)
  - [Manual Setup](#manual-setup)
- [Configuration](#configuration)
- [Usage](#usage)
- [Coding Standards](#coding-standards)
- [Security](#security)
- [CLI Tools](#cli-tools)
- [License](#license)

---

## Features

### Role-Based Access

| Role | Capabilities |
|---|---|
| **Personnel** | Submit job/inventory requests, track status, upload attachments, message staff |
| **Staff** | Review/approve/reject requests, manage inventory catalog, stock adjustments, CSV export |
| **Admin** | User management, system settings, audit logs, maintenance mode, all staff capabilities |

### Request Management
- Job and inventory request types with configurable priority levels
- Full lifecycle: Draft &rarr; Submitted &rarr; In Review &rarr; Approved/Rejected &rarr; Completed
- File attachments (PDF, Office, images) with MIME validation and size limits
- Request-linked line items for inventory requests
- Status history audit trail with comments
- Staff assignment and workload tracking

### Inventory Tracking
- Catalog management with SKU, category, location, and reorder levels
- Stock adjustments (in/out/adjustment) with transaction history
- Low-stock alerts surfaced on staff and admin dashboards
- Inventory search API for AJAX autocomplete

### Communication
- Threaded conversations between users with request linking
- Role-based messaging restrictions (personnel can only message staff/admin)
- Unread message badge with 30-second AJAX polling
- Email notifications with per-conversation deduplication (1-hour cooldown)
- Daily digest emails for unread messages older than 24 hours

### Dashboards & Reporting
- Personnel: request summary cards, status doughnut chart, recent activity
- Staff: priority/type charts, workload distribution, inventory alerts, average turnaround
- Admin: 30-day volume trends, user/role breakdown, system-wide analytics
- Chart.js visualizations (bar, doughnut, line charts)
- CSV export of filtered request data

### Administration
- User CRUD with role assignment, activation/deactivation
- Self-demotion safeguard (admin cannot change own role)
- Password re-entry required for role changes
- Filterable audit log viewer with old/new value diffs
- System settings: site name, pagination, upload limits, email toggle, maintenance mode

### Security
- Argon2id password hashing with policy enforcement (8+ chars, mixed case, digit, common-password blocklist)
- Two-factor authentication via email OTP (6-digit, 15-minute TTL)
- HMAC-SHA256 signed tokens for email verification and password reset
- CSRF protection (persistent token mode)
- Session hardening (HTTPOnly, SameSite=Strict, idle timeout, regeneration on auth)
- Rate limiting on login (5/min), registration (5/min), and API endpoints (120/min)
- Security headers: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS
- Maintenance mode with admin bypass
- Custom error pages (403, 404, 500) with no stack traces in production

### UI / UX
- Bootstrap 5 responsive layout with mobile-first approach
- Touch-friendly targets (44px minimum tap size)
- Accessibility: skip links, ARIA roles/labels, focus indicators, keyboard navigation
- Toast notification system
- Inline form validation with password strength indicator
- Auto-save drafts to localStorage (forms with `data-autosave`)
- Unsaved changes warning (`beforeunload`)
- File upload preview with size display
- Print-optimized styles (hides navigation, buttons, pagination)

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.4 |
| Framework | Slim 4 (PSR-7 / PSR-15) |
| DI Container | PHP-DI 7 with autowiring |
| Templates | Twig 3 (auto-escaping, template inheritance) |
| Database | MySQL 8.4 LTS (PDO, prepared statements, no ORM) |
| Frontend | Bootstrap 5.3, Font Awesome, Chart.js (CDN) |
| Email | PHPMailer 6 (SMTP) or SendGrid v3 HTTP API |
| Logging | Monolog 3 (PSR-3) |
| Web Server | Nginx + PHP-FPM 8.4, Apache 2.4 (XAMPP/WAMP), or IIS 10 with PHP CGI/FastCGI |
| Testing | PHPUnit 10 |

---

## Architecture

```
HTTP Request
  |
  v
public/index.php (bootstrap)
  |
  v
Middleware Stack  (LIFO — last registered = outermost = runs first)
  ErrorHandler -> Routing -> MethodOverride -> SecurityHeaders -> IdleTimeout
  -> MaintenanceMode -> CSRF Guard -> PreRender (Twig globals) -> TwigView
  |
  v
Router (config/routes.php)
  |
  v
Controller (thin — delegates to service)
  |
  v
Service (business logic, validation, events)
  |
  v
Repository (PDO prepared statements)
  |
  v
MySQL 8.4
```

### Key Principles

| Principle | Implementation |
|---|---|
| Separation of concerns | Controllers never touch SQL; services never touch HTTP |
| Event-driven | Services emit domain events (`request.submitted`, `inventory.low_stock`, `message.new`) handled by listeners |
| Dependency injection | All dependencies wired through PHP-DI; no `new` in controllers |
| Security by default | Auto-escaping in Twig, prepared statements in repositories, CSRF on all forms |

---

## Requirements

- PHP >= 8.1 (8.4 recommended)
- MySQL >= 8.0 (8.4 LTS recommended)
- Composer 2.x
- Nginx + PHP-FPM, Apache 2.4 with `mod_rewrite` (XAMPP / WAMP), or IIS 10+ with URL Rewrite
- PHP extensions: `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `intl`, `gd`, `opcache`

---

## Installation

### Docker Desktop (Windows)

The fastest path on Windows. Requires [Docker Desktop](https://www.docker.com/products/docker-desktop/) with the WSL 2 backend and [PowerShell 7.5+](https://aka.ms/powershell).

```powershell
.\install\docker-setup.ps1
```

This single command:
1. Builds a PHP 8.4-FPM image with all required extensions
2. Starts Nginx, PHP-FPM, and MySQL 8.4 containers
3. Installs Composer dependencies
4. Runs all database migrations
5. Creates an admin user with a random password
6. Seeds default system settings

Customizable parameters:

```powershell
.\install\docker-setup.ps1 -AppPort 9000 -AdminEmail admin@company.com -DbExternalPort 33060
```

| Parameter | Default | Description |
|---|---|---|
| `-DbName` | `job_inventory_requests` | MySQL database name |
| `-DbUser` | `jir_app` | MySQL application user |
| `-AdminEmail` | `admin@jir.local` | Admin account email |
| `-AdminName` | `System Administrator` | Admin display name |
| `-AppPort` | `8080` | Host port for the web server |
| `-DbExternalPort` | `3306` | Host port for MySQL (external access) |
| `-SkipBuild` | *off* | Skip container build (re-run migrations only) |

After installation, manage containers with:

```powershell
# Start / Stop / Logs
docker compose -f install/docker-compose.yml --env-file .env up -d
docker compose -f install/docker-compose.yml --env-file .env down
docker compose -f install/docker-compose.yml --env-file .env logs -f

# Shell into the app container
docker exec -it jir-app bash

# Rebuild after Dockerfile changes
docker compose -f install/docker-compose.yml --env-file .env up -d --build app
```

### Automated (WSL / Debian)

The install script handles everything on a fresh Debian-based system: PHP 8.4-FPM, Nginx, Oracle MySQL 8.4, Composer, database setup, and admin user creation.

```bash
chmod +x install/setup.sh
./install/setup.sh
```

The script accepts these environment variables for customization:

| Variable | Default | Description |
|---|---|---|
| `JIR_DB_NAME` | `job_inventory_requests` | Database name |
| `JIR_DB_USER` | `jir_app` | Database user |
| `JIR_DB_PASS` | *(random 24-char)* | Database password |
| `JIR_MYSQL_ROOT_PASS` | *(empty — sudo auth)* | MySQL root password |
| `JIR_APP_PORT` | `80` | Nginx listen port |
| `JIR_APP_DOMAIN` | `_` (any) | Server name |
| `JIR_ADMIN_EMAIL` | `admin@example.com` | Admin account email |
| `JIR_ADMIN_NAME` | `System Administrator` | Admin display name |

Example with custom values:

```bash
JIR_APP_PORT=8080 JIR_ADMIN_EMAIL=admin@company.com ./install/setup.sh
```

Credentials (database + admin) are printed once at the end. Save them immediately.

### Setting up WSL 

For Windows users, the easiest way to run the automated installation is using the **Windows Subsystem for Linux (WSL)** with a **Debian** or **Ubuntu** distribution.

1. **Enable WSL and install a distribution**:
   Open PowerShell as Administrator and run:
   ```powershell
   wsl --install -d Debian
   ```
   *Restart your computer if prompted.*

2. **Set up your user**:
   Follow the on-screen instructions in the new terminal window to create a Linux username and password.

3. **Clone the repository inside WSL**:
   It is highly recommended to clone the project directly into the Linux file system (e.g., `/home/username/projects/`) rather than accessing the Windows drive (`/mnt/c/`) for significantly better performance and avoiding permission issues.
   ```bash
   sudo apt update -qq
   sudo apt upgrade -y -qq
   sudo apt install git -y
   cd ~
   mkdir projects && cd projects
   git clone https://github.com/aingelc12ell/jobinventoryrequisition.git JobInventoryRequests
   cd JobInventoryRequests
   ```

4. **Run the installer**:
   Follow the [Automated (WSL / Debian)](#automated-wsl--debian) instructions above.

### XAMPP / WAMP (Windows)

Deploy on a local Windows machine using [XAMPP](https://www.apachefriends.org/) or [WAMP](https://www.wampserver.com/) as the Apache + MySQL + PHP stack.

#### Prerequisites

| Component | Minimum | Recommended | Notes |
|---|---|---|---|
| XAMPP | 8.4.x | Latest 8.4 | Ships with Apache, MariaDB, PHP |
| **or** WAMP | 3.3.x+ | Latest | Allows switching PHP versions |
| PHP | 8.1 | 8.4 | Must match the XAMPP/WAMP PHP version |
| MySQL | 8.0 | 8.4 | XAMPP ships MariaDB by default; see note below |
| Composer | 2.x | Latest | [getcomposer.org](https://getcomposer.org/) |

> **XAMPP ships MariaDB, not MySQL.** The application is designed for MySQL 8.0+ and uses MySQL-specific features (`utf8mb4_general_ci` collation, `JSON` columns, `FIELD()` function). MariaDB 10.6+ generally works, but for full compatibility replace the XAMPP MariaDB service with [MySQL Community Server 8.4](https://dev.mysql.com/downloads/mysql/) or use WAMP which supports MySQL natively.

#### Step 1 — Install XAMPP or WAMP

Download and install from the official site. During installation make sure the following components are selected:

- **Apache** (web server)
- **MySQL** (database — or MariaDB on XAMPP)
- **PHP** (8.4 if available; 8.1+ minimum)

Default installation paths:

| Stack | Install Path | Document Root | PHP Executable |
|---|---|---|---|
| XAMPP | `C:\xampp` | `C:\xampp\htdocs` | `C:\xampp\php\php.exe` |
| WAMP | `C:\wamp64` | `C:\wamp64\www` | `C:\wamp64\bin\php\php8.x.x\php.exe` |

#### Step 2 — Enable Required PHP Extensions

Open `php.ini` (XAMPP: `C:\xampp\php\php.ini`; WAMP: click tray icon &rarr; PHP &rarr; php.ini) and ensure these extensions are enabled (remove the leading `;` if commented out):

```ini
extension=curl
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=zip
```

Save the file and restart Apache via the XAMPP Control Panel or WAMP tray menu.

To verify, open a terminal and run:

```cmd
php -m | findstr /i "pdo_mysql curl gd intl mbstring openssl zip"
```

All seven should appear in the output.

#### Step 3 — Install Composer

If Composer is not installed, download and run the [Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe) installer. It auto-detects your PHP path. Verify:

```cmd
composer --version
```

#### Step 4 — Clone the Project

Clone the repository into the XAMPP/WAMP document root or a custom directory:

**Option A — Into the default document root:**

```cmd
cd C:\xampp\htdocs
git clone https://github.com/aingelc12ell/jobinventoryrequisition.git jir
cd jir
```

**Option B — Any directory (recommended):**

```cmd
cd C:\Projects
git clone https://github.com/aingelc12ell/jobinventoryrequisition.git JobInventoryRequests
cd JobInventoryRequests
```

#### Step 5 — Install PHP Dependencies

```cmd
composer install --no-dev --optimize-autoloader
```

#### Step 6 — Configure the Environment

```cmd
copy .env.example .env
```

Open `.env` in a text editor and set:

```ini
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_SECRET=<paste-a-random-64-character-hex-string>

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=job_inventory_requests
DB_USER=root
DB_PASS=
```

Generate `APP_SECRET` with PHP:

```cmd
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

> **Note:** XAMPP's default MySQL root password is empty. WAMP defaults to `root` with no password. Set `DB_PASS` accordingly.

#### Step 7 — Create the Database

Open **phpMyAdmin** at `http://localhost/phpmyadmin` (started automatically by XAMPP/WAMP), or use the MySQL CLI:

```cmd
:: XAMPP
C:\xampp\mysql\bin\mysql.exe -u root

:: WAMP
C:\wamp64\bin\mysql\mysql8.x.x\bin\mysql.exe -u root
```

Execute the schema and all migrations in order:

```sql
SOURCE C:/Projects/JobInventoryRequests/database/schema.sql;
USE job_inventory_requests;
SOURCE C:/Projects/JobInventoryRequests/database/migrations/002_requests.sql;
SOURCE C:/Projects/JobInventoryRequests/database/migrations/003_inventory.sql;
SOURCE C:/Projects/JobInventoryRequests/database/migrations/004_messages.sql;
SOURCE C:/Projects/JobInventoryRequests/database/migrations/005_performance_indexes.sql;
```

> **Path separators:** Use forward slashes (`/`) in the `SOURCE` command even on Windows.

Alternatively, paste each `.sql` file's contents into the phpMyAdmin SQL tab and execute.

#### Step 8 — Create Storage Directories

```cmd
mkdir storage\cache storage\logs storage\uploads storage\cache\rate_limit
```

#### Step 9 — Create an Admin User

```cmd
php -r "echo password_hash('YourSecurePassword1!', PASSWORD_ARGON2ID);"
```

Copy the hash output, then run in MySQL:

```sql
INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at)
VALUES ('admin@jir.local', '<paste-hash-here>', 'Administrator', 'admin', 1, NOW());
```

Or use a single command:

```cmd
php -r "$h = password_hash('YourSecurePassword1!', PASSWORD_ARGON2ID); echo \"INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at) VALUES ('admin@jir.local', '$h', 'Administrator', 'admin', 1, NOW());\";" | C:\xampp\mysql\bin\mysql.exe -u root job_inventory_requests
```

#### Step 10 — Configure Apache Virtual Host

Using a VirtualHost ensures Apache serves the `public/` directory as the document root and prevents access to source files, configuration, and `.env`.

**XAMPP:** Edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf`

**WAMP:** Right-click tray icon &rarr; Apache &rarr; httpd-vhosts.conf

Add the following (adjust `DocumentRoot` and `Directory` paths to match your setup):

```apache
<VirtualHost *:8080>
    ServerName jir.local
    DocumentRoot "C:/Projects/JobInventoryRequests/public"

    <Directory "C:/Projects/JobInventoryRequests/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Deny access to the project root (source files, .env, config, etc.)
    <Directory "C:/Projects/JobInventoryRequests">
        Require all denied
    </Directory>

    # Log files (optional)
    ErrorLog "logs/jir-error.log"
    CustomLog "logs/jir-access.log" common
</VirtualHost>
```

> **If using the htdocs directory instead**, point `DocumentRoot` to `C:/xampp/htdocs/jir/public` and the deny directory to `C:/xampp/htdocs/jir`.

**Enable the port.** If using a port other than 80 or 443, add `Listen 8080` to the main `httpd.conf`:

**XAMPP:** `C:\xampp\apache\conf\httpd.conf`
**WAMP:** Right-click tray icon &rarr; Apache &rarr; httpd.conf

```apache
Listen 8080
```

**Enable `mod_rewrite`.** Ensure this line is *not* commented out in `httpd.conf`:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

**Enable `mod_headers`** (for security headers middleware):

```apache
LoadModule headers_module modules/mod_headers.so
```

**Optional — Local DNS.** To use `http://jir.local:8080` instead of `http://localhost:8080`, add to `C:\Windows\System32\drivers\etc\hosts` (run Notepad as Administrator):

```
127.0.0.1    jir.local
```

Restart Apache from the XAMPP Control Panel or WAMP tray menu.

#### Step 11 — Verify the Installation

Open your browser and navigate to:

```
http://localhost:8080
```

You should see the JIR login page. Log in with the admin credentials created in Step 9.

**Troubleshooting:**

| Symptom | Cause | Fix |
|---|---|---|
| **404 Not Found** on all routes except `/` | `mod_rewrite` is disabled or `AllowOverride` is not `All` | Enable `mod_rewrite` in `httpd.conf` and set `AllowOverride All` in the VirtualHost |
| **403 Forbidden** | Apache lacks permission to the project directory | Check `Require all granted` is set on the `public/` `<Directory>` block |
| **500 Internal Server Error** | PHP error — check the log | Open `storage/logs/app.log` or the Apache error log; common causes: missing extension, `.env` misconfigured |
| **"Class not found"** errors | Composer dependencies not installed | Run `composer install --no-dev --optimize-autoloader` |
| **Database connection refused** | MySQL not running or wrong credentials | Start MySQL from XAMPP/WAMP panel; verify `DB_*` values in `.env` |
| **`utf8mb4_general_ci` collation error** | MariaDB < 10.10 or MySQL < 8.0 | Upgrade to MySQL 8.0+, or replace collation with `utf8mb4_general_ci` in `schema.sql` (not recommended) |
| Page loads but **assets/styles missing** | `DocumentRoot` not pointing to `public/` | Ensure VirtualHost `DocumentRoot` is the `public/` subdirectory, not the project root |

#### Seed Sample Data (Optional)

```cmd
php cli/seed.php --clean --job=25 --inventory=25
```

#### Running with PHP Built-in Server (Quick Start)

If you prefer to skip the Apache VirtualHost setup entirely during development:

```cmd
cd C:\Projects\JobInventoryRequests
php -S localhost:8080 -t public
```

This starts a single-threaded development server. **Do not use this in production.** You still need MySQL running (via XAMPP/WAMP panel).

---

### IIS (Windows Server / Windows 10+)

Deploy on **Internet Information Services (IIS)** with PHP running via FastCGI. This is the recommended approach for Windows Server production environments and also works on Windows 10/11 Pro, Enterprise, and Education editions.

#### Prerequisites

| Component | Minimum | Recommended | Where to Get It |
|---|---|---|---|
| Windows | 10 Pro / Server 2016 | Server 2022 / Windows 11 Pro | IIS is not available on Home editions |
| IIS | 10.0 | 10.0 | Built-in Windows feature |
| PHP | 8.1 (NTS x64) | 8.4 (NTS x64) | [windows.php.net](https://windows.php.net/download/) |
| MySQL | 8.0 | 8.4 LTS | [dev.mysql.com](https://dev.mysql.com/downloads/installer/) |
| Composer | 2.x | Latest | [getcomposer.org](https://getcomposer.org/) |
| URL Rewrite | 2.1 | 2.1 | [IIS URL Rewrite Module](https://www.iis.net/downloads/microsoft/url-rewrite) |

> **PHP Thread Safety:** IIS uses FastCGI (one process per request), so download the **Non-Thread Safe (NTS)** build of PHP. The TS (Thread Safe) build is for Apache `mod_php` only.

#### Step 1 — Enable IIS and Required Features

Open **PowerShell as Administrator** and run:

```powershell
# Core IIS + CGI (required for PHP FastCGI)
Enable-WindowsOptionalFeature -Online -FeatureName IIS-WebServerRole, IIS-WebServer, IIS-CommonHttpFeatures, IIS-StaticContent, IIS-DefaultDocument, IIS-DirectoryBrowsing, IIS-HttpErrors, IIS-HealthAndDiagnostics, IIS-HttpLogging, IIS-Security, IIS-RequestFiltering, IIS-Performance, IIS-HttpCompressionStatic, IIS-CGI -All
```

On **Windows Server**, use Server Manager instead:

1. Open **Server Manager** &rarr; **Add Roles and Features**
2. Select **Web Server (IIS)** &rarr; **Application Development** &rarr; check **CGI**
3. Complete the wizard and restart if prompted

Verify IIS is running by opening `http://localhost` in a browser — you should see the default IIS welcome page.

#### Step 2 — Install PHP for IIS

1. Download **PHP 8.4 NTS x64** (zip) from [windows.php.net/download/](https://windows.php.net/download/)

2. Extract to `C:\php` (or your preferred path)

3. Copy the configuration template:

   ```cmd
   copy C:\php\php.ini-production C:\php\php.ini
   ```

4. Edit `C:\php\php.ini` and apply these settings:

   ```ini
   ; Set the extension directory
   extension_dir = "C:\php\ext"

   ; Required extensions — uncomment these lines:
   extension=curl
   extension=gd
   extension=intl
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   extension=zip

   ; FastCGI settings for IIS
   cgi.force_redirect = 0
   cgi.fix_pathinfo = 1
   fastcgi.impersonate = 1

   ; Recommended production settings
   expose_php = Off
   error_log = "C:\php\php_errors.log"
   upload_max_filesize = 10M
   post_max_size = 12M
   max_execution_time = 60
   memory_limit = 256M
   date.timezone = "UTC"

   ; Session configuration
   session.save_path = "C:\php\sessions"
   session.cookie_httponly = 1
   session.use_strict_mode = 1
   session.use_only_cookies = 1

   ; OPcache (strongly recommended for production)
   zend_extension=opcache
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=10000
   opcache.validate_timestamps=0
   ```

5. Create the session save directory:

   ```cmd
   mkdir C:\php\sessions
   ```

6. Add PHP to the system PATH:

   ```powershell
   [Environment]::SetEnvironmentVariable("Path", "$env:Path;C:\php", "Machine")
   ```

   Close and reopen your terminal, then verify:

   ```cmd
   php -v
   php -m | findstr /i "pdo_mysql curl gd intl mbstring openssl zip"
   ```

#### Step 3 — Install the IIS URL Rewrite Module

Download and install from: https://www.iis.net/downloads/microsoft/url-rewrite

This module is required for Slim's front-controller routing. Without it, only the root URL (`/`) will work; all other routes will return IIS 404 errors.

After installation, restart IIS:

```cmd
iisreset
```

#### Step 4 — Register PHP as a FastCGI Handler in IIS

Open **IIS Manager** (`inetmgr`) &rarr; select the **server node** (top level) &rarr; **Handler Mappings** &rarr; **Add Module Mapping** (right panel):

| Field | Value |
|---|---|
| Request path | `*.php` |
| Module | `FastCgiModule` |
| Executable | `C:\php\php-cgi.exe` |
| Name | `PHP_via_FastCGI` |

Click **OK**, then **Yes** when asked to create a FastCGI application.

**Or via command line (run as Administrator):**

```cmd
%windir%\system32\inetsrv\appcmd.exe set config /section:system.webServer/fastCgi /+[fullPath='C:\php\php-cgi.exe']

%windir%\system32\inetsrv\appcmd.exe set config /section:system.webServer/handlers /+[name='PHP_via_FastCGI',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='C:\php\php-cgi.exe',resourceType='Either']
```

#### Step 5 — Install MySQL

Download and install [MySQL Community Server 8.4](https://dev.mysql.com/downloads/installer/) using the MySQL Installer. During setup:

- Choose **Server only** (or Full if you want Workbench)
- Set a root password and note it
- Keep the default port `3306`
- Install as a **Windows Service** (auto-start)

Verify from the command line:

```cmd
"C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p -e "SELECT VERSION();"
```

#### Step 6 — Install Composer

Download and run [Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe). It auto-detects `C:\php\php.exe`. Verify:

```cmd
composer --version
```

#### Step 7 — Clone the Project and Install Dependencies

```cmd
cd C:\inetpub
git clone https://github.com/aingelc12ell/jobinventoryrequisition.git jir
cd jir
composer install --no-dev --optimize-autoloader
```

> **Why `C:\inetpub`?** It is the standard IIS content directory with correct NTFS permissions. You can use any directory, but `C:\inetpub` avoids permission issues.

#### Step 8 — Configure the Environment

```cmd
copy .env.example .env
notepad .env
```

Set the following values:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost
APP_SECRET=<paste-a-random-64-character-hex-string>

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=job_inventory_requests
DB_USER=root
DB_PASS=<your-mysql-root-password>
```

Generate `APP_SECRET`:

```cmd
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

#### Step 9 — Create the Database and Run Migrations

```cmd
"C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p
```

In the MySQL prompt:

```sql
SOURCE C:/inetpub/jir/database/schema.sql;
USE job_inventory_requests;
SOURCE C:/inetpub/jir/database/migrations/002_requests.sql;
SOURCE C:/inetpub/jir/database/migrations/003_inventory.sql;
SOURCE C:/inetpub/jir/database/migrations/004_messages.sql;
SOURCE C:/inetpub/jir/database/migrations/005_performance_indexes.sql;
```

#### Step 10 — Create Storage Directories and Set Permissions

```cmd
mkdir C:\inetpub\jir\storage\cache
mkdir C:\inetpub\jir\storage\logs
mkdir C:\inetpub\jir\storage\uploads
mkdir C:\inetpub\jir\storage\cache\rate_limit
```

Grant the IIS application pool identity write access:

```cmd
icacls "C:\inetpub\jir\storage" /grant "IIS_IUSRS:(OI)(CI)M" /T
icacls "C:\inetpub\jir\storage" /grant "IUSR:(OI)(CI)M" /T
```

#### Step 11 — Create an Admin User

```cmd
php -r "$h = password_hash('YourSecurePassword1!', PASSWORD_ARGON2ID); echo \"INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at) VALUES ('admin@jir.local', '$h', 'Administrator', 'admin', 1, NOW());\";" | "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p job_inventory_requests
```

Or generate the hash and paste into MySQL Workbench / CLI manually:

```cmd
php -r "echo password_hash('YourSecurePassword1!', PASSWORD_ARGON2ID) . PHP_EOL;"
```

#### Step 12 — Create the IIS Website

**Option A — IIS Manager (GUI):**

1. Open **IIS Manager** (`inetmgr`)
2. Right-click **Sites** &rarr; **Add Website**

   | Field | Value |
   |---|---|
   | Site name | `JIR` |
   | Physical path | `C:\inetpub\jir\public` |
   | Binding: Type | `http` |
   | Binding: Port | `80` (or `8080` to avoid conflicts) |
   | Binding: Host name | *(leave blank for localhost, or enter `jir.local`)* |

3. Click **OK**

4. If using port 80, **stop** the Default Web Site to avoid conflicts:
   Right-click **Default Web Site** &rarr; **Stop**

**Option B — Command line (Administrator):**

```cmd
:: Create a new application pool
%windir%\system32\inetsrv\appcmd.exe add apppool /name:"JIR" /managedRuntimeVersion:"" /managedPipelineMode:Integrated

:: Create the website
%windir%\system32\inetsrv\appcmd.exe add site /name:"JIR" /physicalPath:"C:\inetpub\jir\public" /bindings:http/*:80: /applicationPool:"JIR"
```

> **Important:** The physical path must point to the `public/` subdirectory, **not** the project root. This prevents IIS from serving `.env`, source code, and configuration files.

#### Step 13 — Verify the URL Rewrite Rules

The project includes a `public/web.config` file with the IIS URL Rewrite rules pre-configured. Verify it is in place:

```cmd
type C:\inetpub\jir\public\web.config
```

The `web.config` handles:
- Rewriting all non-file/non-directory requests to `index.php` (Slim routing)
- Disabling directory browsing
- Blocking access to dotfiles (`.env`, `.git`, `.htaccess`)
- Setting `index.php` as the default document
- Passing through custom HTTP error responses from the application

If the file is missing, copy it from the repository or create it manually.

#### Step 14 — Verify the Installation

Restart the site and open your browser:

```cmd
iisreset
```

Navigate to `http://localhost` (or `http://localhost:8080` if using a custom port). You should see the JIR login page.

**Troubleshooting:**

| Symptom | Cause | Fix |
|---|---|---|
| **HTTP 404.0** on all routes except `/` | URL Rewrite module not installed | Install from [iis.net/downloads/microsoft/url-rewrite](https://www.iis.net/downloads/microsoft/url-rewrite) and run `iisreset` |
| **HTTP 403.14** Forbidden | Physical path points to project root instead of `public/` | Update the site's physical path to `C:\inetpub\jir\public` |
| **HTTP 500.0** with "FastCGI process exited unexpectedly" | PHP misconfigured or missing extensions | Check `C:\php\php_errors.log`; verify `cgi.force_redirect = 0` and `fastcgi.impersonate = 1` in `php.ini` |
| **HTTP 500.19** "Cannot read configuration file" | `web.config` syntax error or IIS lacks permission | Validate XML syntax; grant `IIS_IUSRS` read access to `C:\inetpub\jir\public` |
| **HTTP 502.3** Bad Gateway / timeout | PHP FastCGI handler not registered | Re-register via IIS Manager &rarr; Handler Mappings (see Step 4) |
| **"Class not found"** errors | Composer dependencies missing | Run `composer install --no-dev --optimize-autoloader` in `C:\inetpub\jir` |
| **Database connection refused** | MySQL not running or wrong credentials | Verify MySQL service is running; check `DB_*` values in `.env` |
| **Blank page / no output** | `display_errors` off and app error | Set `APP_DEBUG=true` in `.env` temporarily; check `storage/logs/app.log` |
| **Session errors** | Session save path missing or not writable | Create `C:\php\sessions` and grant `IIS_IUSRS` write access |

#### Seed Sample Data (Optional)

```cmd
php C:\inetpub\jir\cli\seed.php --clean --job=25 --inventory=25
```

#### HTTPS with a Self-Signed Certificate (Optional)

For production, use a proper certificate from a CA. For development/testing:

```powershell
# Create a self-signed certificate (PowerShell as Administrator)
New-SelfSignedCertificate -DnsName "jir.local","localhost" -CertStoreLocation "cert:\LocalMachine\My" -FriendlyName "JIR Dev Certificate" -NotAfter (Get-Date).AddYears(3)
```

Then in IIS Manager:

1. Select the **JIR** site &rarr; **Bindings** &rarr; **Add**
2. Type: `https`, Port: `443`, SSL certificate: select **JIR Dev Certificate**
3. Update `.env`: `APP_URL=https://localhost`

#### Scheduled Tasks (Daily Digest)

Use **Windows Task Scheduler** to run the daily digest email script:

```cmd
schtasks /create /tn "JIR Daily Digest" /tr "C:\php\php.exe C:\inetpub\jir\cli\digest.php" /sc daily /st 08:00 /ru SYSTEM
```

Or via PowerShell:

```powershell
$action = New-ScheduledTaskAction -Execute "C:\php\php.exe" -Argument "C:\inetpub\jir\cli\digest.php"
$trigger = New-ScheduledTaskTrigger -Daily -At 8:00AM
Register-ScheduledTask -TaskName "JIR Daily Digest" -Action $action -Trigger $trigger -RunLevel Highest -User "SYSTEM"
```

---

### Manual Setup

**1. Clone and install dependencies**

```bash
git clone https://github.com/aingelc12ell/jobinventoryrequisition.git JobInventoryRequests
cd JobInventoryRequests
composer install --no-dev --optimize-autoloader
```

**2. Configure environment**

```bash
cp .env.example .env
```

Edit `.env` and set all values. Generate a random `APP_SECRET`:

```bash
openssl rand -hex 32
```

**3. Create the database and run migrations**

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p job_inventory_requests < database/migrations/002_requests.sql
mysql -u root -p job_inventory_requests < database/migrations/003_inventory.sql
mysql -u root -p job_inventory_requests < database/migrations/004_messages.sql
```

**4. Create storage directories**

```bash
mkdir -p storage/{cache,logs,uploads,cache/rate_limit}
# Skip if on Windows mount (/mnt/*) in WSL
chmod -R 775 storage 2>/dev/null || true
```

**5. Create an admin user**

```bash
php -r "
  \$hash = password_hash('YourSecurePassword1!', PASSWORD_ARGON2ID);
  echo \"INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at)
        VALUES ('admin@example.com', '\$hash', 'Administrator', 'admin', 1, NOW());\" . PHP_EOL;
" | mysql -u root -p job_inventory_requests
```

**6. Start the development server**

```bash
composer start
# or
php -S localhost:8080 -t public
```

Open `http://localhost:8080` and log in with the admin credentials.

---

## Configuration

All configuration is driven by the `.env` file:

```ini
# Application
APP_NAME="Job & Inventory Request System"
APP_ENV=production          # production | development
APP_DEBUG=false              # true shows stack traces — never in production
APP_URL=http://localhost
APP_SECRET=<64-char-hex>     # Used for HMAC token signing

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=job_inventory_requests
DB_USER=jir_app
DB_PASS=<password>
DB_CHARSET=utf8mb4                       # Connection character set
DB_COLLATION=utf8mb4_general_ci          # Collation (utf8mb4_unicode_ci for MySQL < 8.0)

# Database SSL (optional — encrypted connection to MySQL)
DB_SSL_ENABLED=false
DB_SSL_CA=/path/to/ca.pem
DB_SSL_CERT=/path/to/client-cert.pem
DB_SSL_KEY=/path/to/client-key.pem
DB_SSL_VERIFY=true

# Mail
MAIL_DRIVER=smtp             # smtp | sendgrid
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Job & Inventory System"

# SMTP settings (when MAIL_DRIVER=smtp)
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USER=<smtp-user>
MAIL_PASS=<smtp-password>
MAIL_ENCRYPTION=tls

# SendGrid settings (when MAIL_DRIVER=sendgrid)
SENDGRID_API_KEY=<your-sendgrid-api-key>

# Session
SESSION_LIFETIME=1800        # Seconds (30 minutes)
SESSION_NAME=jir_session
```

### Mail Drivers

The system supports two mail transports, selected via `MAIL_DRIVER`:

| Driver | Config Required | Notes |
|---|---|---|
| `smtp` (default) | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_ENCRYPTION` | Uses PHPMailer. Works with any SMTP provider (Mailtrap, Gmail, SES, etc.) |
| `sendgrid` | `SENDGRID_API_KEY` | Uses SendGrid v3 HTTP API via native curl. No extra Composer packages needed. |

Both drivers use `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` for the sender identity.

### Database Charset & Collation

The connection character set and collation are configurable via `DB_CHARSET` and `DB_COLLATION`. These values are applied at two levels:

1. **DSN** — `charset=<DB_CHARSET>` in the PDO connection string tells the MySQL client library which character set to use for the wire protocol.
2. **`SET NAMES`** — an init command runs `SET NAMES '<DB_CHARSET>' COLLATE '<DB_COLLATION>'` on every new connection, ensuring the server session matches.

| Variable | Default | Description |
|---|---|---|
| `DB_CHARSET` | `utf8mb4` | MySQL connection character set. `utf8mb4` supports the full Unicode range including emojis (4-byte). |
| `DB_COLLATION` | `utf8mb4_general_ci` | Collation for string comparisons and sorting. The `0900` collations are MySQL 8.0+ only. |

**Common collation choices:**

| Collation | MySQL Version | Notes |
|---|---|---|
| `utf8mb4_general_ci` | 8.0+ | Default for MySQL 8.0+. Accent-insensitive, case-insensitive. Based on Unicode 9.0 CLDR. |
| `utf8mb4_0900_as_cs` | 8.0+ | Accent-sensitive, case-sensitive variant. |
| `utf8mb4_unicode_ci` | 5.7+ | Use this if running MySQL 5.7 or MariaDB. Based on Unicode 4.0 UCA. |
| `utf8mb4_general_ci` | 5.5+ | Fastest but least accurate sorting. Legacy — not recommended for new projects. |

> **Important:** The collation set here applies to the **connection session** only. Table and column collations are defined in `database/schema.sql` and the migration files. If you change these values, ensure your schema DDL matches to avoid implicit conversion overhead.

### Database SSL / TLS

Enable encrypted connections between the application and MySQL by setting `DB_SSL_ENABLED=true`. This is strongly recommended when the database server is on a different host, across a network boundary, or hosted by a cloud provider (AWS RDS, Azure Database for MySQL, Google Cloud SQL, PlanetScale, etc.).

| Variable | Required | Default | Description |
|---|---|---|---|
| `DB_SSL_ENABLED` | No | `false` | Master switch — set `true` to activate SSL options |
| `DB_SSL_CA` | Yes (when enabled) | *(empty)* | Absolute path to the CA certificate bundle (`.pem`). Required for the client to trust the server's certificate. |
| `DB_SSL_CERT` | No | *(empty)* | Path to the client certificate — only needed for **mutual TLS** (mTLS) where the server also authenticates the client |
| `DB_SSL_KEY` | No | *(empty)* | Path to the client private key — required alongside `DB_SSL_CERT` for mTLS |
| `DB_SSL_VERIFY` | No | `true` | Verify the server's certificate against the CA. Set `false` only for self-signed certs in development. |

**One-way SSL (server authentication only — most common):**

```ini
DB_SSL_ENABLED=true
DB_SSL_CA=/etc/ssl/certs/mysql-ca.pem
DB_SSL_VERIFY=true
```

**Mutual TLS (both server and client authenticated):**

```ini
DB_SSL_ENABLED=true
DB_SSL_CA=/etc/ssl/certs/mysql-ca.pem
DB_SSL_CERT=/etc/ssl/certs/mysql-client-cert.pem
DB_SSL_KEY=/etc/ssl/private/mysql-client-key.pem
DB_SSL_VERIFY=true
```

**Self-signed certificate in development:**

```ini
DB_SSL_ENABLED=true
DB_SSL_CA=/path/to/self-signed-ca.pem
DB_SSL_VERIFY=false
```

> **Cloud provider notes:**
> - **AWS RDS**: Download the [combined CA bundle](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.SSL.html) and set `DB_SSL_CA` to its path. One-way SSL is sufficient.
> - **Azure Database for MySQL**: Download the [DigiCert Global Root G2](https://learn.microsoft.com/en-us/azure/mysql/single-server/how-to-configure-ssl) CA. SSL is enforced by default on Azure.
> - **Google Cloud SQL**: Use the [Cloud SQL Auth Proxy](https://cloud.google.com/sql/docs/mysql/connect-auth-proxy) for automatic SSL, or download instance CA/client certs from the console and place them in the `ssl/gcloud` directory.
> - **PlanetScale**: SSL is always on. Set `DB_SSL_CA` to the system CA bundle (e.g., `/etc/ssl/certs/ca-certificates.crt` on Debian/Ubuntu).

**Verify the connection is encrypted** after configuration:

```sql
-- From the MySQL CLI or any query tool:
SHOW STATUS LIKE 'Ssl_cipher';
-- Should return a non-empty cipher name (e.g., TLS_AES_256_GCM_SHA384)
```

Or via PHP:

```php
php -r "
    \$pdo = new PDO('mysql:host=127.0.0.1;dbname=job_inventory_requests', 'user', 'pass', [
        PDO::MYSQL_ATTR_SSL_CA => '/path/to/ca.pem',
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ]);
    \$row = \$pdo->query(\"SHOW STATUS LIKE 'Ssl_cipher'\")->fetch();
    echo \$row['Value'] ? 'SSL active: ' . \$row['Value'] : 'NOT encrypted';
"
```

Additional settings are managed through the admin UI at `/admin/settings`:
site name, items per page, max upload size, default priority, email notifications, maintenance mode.

---

## Usage

### Default Workflow

1. **Personnel** creates a request (job or inventory) and submits it
2. **Staff** reviews the request queue, assigns themselves, changes status
3. For inventory requests, staff deducts stock from the catalog on approval
4. Both parties communicate via threaded messages linked to the request
5. **Admin** monitors activity via dashboards, manages users and settings

### Service Management

**Docker deployment:**

```powershell
docker compose -f install/docker-compose.yml --env-file .env restart   # Restart all
docker compose -f install/docker-compose.yml --env-file .env restart app  # Restart PHP-FPM only
docker compose -f install/docker-compose.yml --env-file .env down      # Stop all
docker compose -f install/docker-compose.yml --env-file .env up -d     # Start all
```

**WSL/Debian deployment:**

```bash
sudo service nginx restart
sudo service php8.4-fpm restart
sudo service mysql restart
```

**XAMPP/WAMP deployment:**

Services are managed through the XAMPP Control Panel or WAMP system-tray menu. To restart from the command line:

```cmd
:: XAMPP — Stop and start Apache + MySQL
C:\xampp\xampp_stop.exe && C:\xampp\xampp_start.exe

:: Or individually via Windows services (if installed as services)
net stop Apache2.4 && net start Apache2.4
net stop mysql && net start mysql
```

**IIS deployment:**

```cmd
:: Restart IIS (all sites)
iisreset

:: Or restart only the JIR site
%windir%\system32\inetsrv\appcmd.exe stop site "JIR"
%windir%\system32\inetsrv\appcmd.exe start site "JIR"

:: Restart the application pool (recycles PHP FastCGI workers)
%windir%\system32\inetsrv\appcmd.exe recycle apppool "JIR"

:: Restart MySQL
net stop mysql84 && net start mysql84
```

---

## Coding Standards

### PHP

- **Strict types**: Every PHP file begins with `declare(strict_types=1);`
- **PSR-4 autoloading**: `App\` namespace maps to `src/`
- **PSR-12 code style**: PER Coding Style (braces, spacing, naming)
- **Final controllers**: Controller classes are `final` to prevent inheritance
- **Constructor promotion**: All injected dependencies use promoted readonly properties
- **Type declarations**: All method parameters and return types are explicitly typed
- **No magic**: No `__get`, `__call`, or service locator patterns outside the DI container

### Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Classes | PascalCase | `RequestService`, `UserRepository` |
| Methods | camelCase | `findById()`, `countByStatus()` |
| Variables | camelCase | `$statusCounts`, `$targetUser` |
| DB columns | snake_case | `created_at`, `full_name` |
| Routes | kebab-case | `/forgot-password`, `/audit-logs` |
| Templates | kebab-case | `reset-password.twig`, `activity-feed.twig` |
| Config keys | snake_case | `session_lifetime`, `app_secret` |

### Architecture Rules

- **Controllers** receive HTTP requests, call a service, and return an HTTP response. No business logic, no direct SQL.
- **Services** contain all business logic, validation, and event dispatch. They receive repositories and other services via constructor injection.
- **Repositories** execute SQL via PDO prepared statements. One repository per database entity. No business rules.
- **Middleware** handles cross-cutting concerns (auth, CSRF, rate limiting, headers). Stateless and composable.
- **Events** decouple side effects (emails, notifications) from core logic. Listeners never throw exceptions that affect the main flow.

### SQL

- All queries use PDO prepared statements with bound parameters (no string concatenation)
- All tables explicitly declare `ENGINE=InnoDB`
- MySQL 8.4 syntax: `ON DUPLICATE KEY UPDATE` uses the `AS new_row` alias form (not the deprecated `VALUES()` function)
- All `GROUP BY` queries are `ONLY_FULL_GROUP_BY` compliant

### Frontend

- Bootstrap 5.3 via CDN with Font Awesome icons
- Chart.js via CDN for dashboard visualizations
- Vanilla JavaScript (no build tools or bundler required)
- All interactive elements have minimum 44px tap targets
- ARIA attributes on all interactive components

### Templates

- Twig 3 with `autoescape: true` (default)
- `|raw` filter only for pre-escaped content (CSRF fields, trusted HTML)
- Template inheritance: all pages extend `layouts/app.twig`
- Reusable components in `templates/components/`
- Flash messages support both keyed (`{type: [messages]}`) and flat (`[{type, message}]`) formats

---

## Security

### Authentication
- Argon2id password hashing via `password_hash()` / `password_verify()`
- Password policy: minimum 8 characters, mixed case, at least one digit, blocklist of 100 common passwords
- Session regeneration on login and 2FA verification
- Idle timeout: sessions destroyed after 30 minutes of inactivity
- Session cookies: `HTTPOnly`, `Secure` (HTTPS), `SameSite=Strict`, `use_strict_mode`

### Authorization
- Role-based access control enforced at the middleware level (not just UI)
- Three roles: `personnel`, `staff`, `admin` (stored as ENUM)
- Route groups protected by `RoleMiddleware` with role whitelists

### Input Protection
- CSRF: Slim-CSRF with persistent token mode on all POST/PUT/DELETE forms
- XSS: Twig auto-escaping, `InputSanitizer` for server-side cleaning
- SQL Injection: All repositories use PDO prepared statements exclusively
- File uploads: MIME type whitelist, size limits, sanitized filenames

### Rate Limiting
- Login: 5 attempts per minute per IP
- Registration: 5 attempts per minute per IP
- API endpoints: 120 requests per minute per IP
- Returns 429 with `Retry-After` and `X-RateLimit-*` headers

### HTTP Headers
- `Content-Security-Policy` (allows Bootstrap/FontAwesome/Chart.js CDN)
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy` (restricts camera, microphone, geolocation)
- `Strict-Transport-Security` (production only)

### Signed Tokens
- Email verification and password reset links use HMAC-SHA256 tokens
- Tokens are stored hashed in the database with expiry timestamps
- One-time use: marked as consumed after verification

---

## CLI Tools

### Sample Data Generator

Populates the database with realistic test data: users, inventory catalogue,
requests at various workflow stages, status history trails, conversations,
messages, inventory transactions, and audit log entries.

**WSL / native deployment:**

```bash
# Default: 25 job + 25 inventory requests, 5 users per role
php cli/seed.php

# Custom counts
php cli/seed.php --job=40 --inventory=30 --users=10

# Wipe all data and re-seed from scratch
php cli/seed.php --clean --job=50 --inventory=50

# Custom password for all generated users
php cli/seed.php --password='MyTestPass1!'
```

**Docker deployment** — CLI scripts must run inside the `jir-app` container because `DB_HOST=db` only resolves within the Docker network:

```powershell
# Default seed
docker exec -it jir-app php cli/seed.php

# Custom counts with clean
docker exec -it jir-app php cli/seed.php --clean --job=30 --inventory=20

# Custom password
docker exec -it jir-app php cli/seed.php --clean --password='MyTestPass1!'
```

> **Note:** Running `php cli/seed.php` directly on the Windows/WSL host when using Docker will fail with a DNS resolution error (`Cannot resolve database host 'db'`). The script detects this and prints the correct `docker exec` command.

| Option | Default | Description |
|---|---|---|
| `--job=N` | 25 | Number of job requests |
| `--inventory=N` | 25 | Number of inventory requests |
| `--users=N` | 5 | Users to create per role |
| `--password=S` | `Password1!` | Password for all generated users |
| `--clean` | off | Truncate all tables before seeding |

All generated users use deterministic emails: `admin.1@jir.test`, `staff.1@jir.test`, `personnel.1@jir.test`, etc.

Requests are distributed across all statuses (draft, submitted, in_review,
approved, rejected, completed, cancelled) with realistic status history
trails, inventory stock adjustments, and threaded conversations.

**What gets generated:**

| Data | Details |
|---|---|
| Users | 2 admins + N staff + N personnel (default N=5) |
| Inventory catalog | 30 items across 5 categories with opening stock |
| Requests | Configurable job + inventory count with full status trails |
| Status history | Complete audit trail per request with comments |
| Inventory transactions | Stock-out entries for approved/completed inventory requests |
| Conversations | ~55% of non-draft requests get threaded messages (2-6 each) |
| Audit logs | Entries for user registration, request creation, and all status changes |

### Daily Digest

Sends summary emails to users with unread messages older than 24 hours.

**WSL / native deployment:**

```bash
php cli/digest.php
```

The install script sets up a cron job to run this daily at 08:00:

```
0 8 * * * cd /path/to/project && /usr/bin/php cli/digest.php >> storage/logs/digest.log 2>&1
```

**Docker deployment:**

```powershell
docker exec -it jir-app php cli/digest.php
```

For scheduled execution in Docker, use the host's task scheduler to run the container command:

```powershell
# Windows Task Scheduler (or cron inside WSL)
docker exec jir-app php cli/digest.php >> storage/logs/digest.log 2>&1
```

---

## License

This project is proprietary software. All rights reserved.
