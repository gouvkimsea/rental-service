# RentalKH Production Deployment & Operations Guide

This guide covers deployment, environment configuration, database lifecycle, security, background workers, and disaster recovery procedures for the RentalKH production system.

---

## 1. Environment Configuration

RentalKH loads environment variables via `.env` in the application root, or directly from the OS/container environment.

### Production Environment Template (`.env`)
```ini
# Application Configuration
APP_ENV=production
APP_DEBUG=false
APP_URL=https://rental.yourdomain.com

# Primary Database Connection
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=rentalkh
DB_USER=rentalkh_app
DB_PASS=A_Very_Strong_Database_Password_Here

# Optional Failover Database
DB_FALLBACK_HOST=
DB_FALLBACK_USER=
DB_FALLBACK_PASS=
DB_FALLBACK_NAME=

# Security & Session Configuration
SESSION_LIFETIME_SECONDS=86400
SESSION_SECURE_COOKIE=true

# Operational Policies
RESERVATION_EXPIRY_HOURS=24
MIN_RENTAL_DAYS=1
LATE_FEE_PER_HOUR=10.00
LATE_GRACE_PERIOD_MINUTES=30
FUEL_FEE_PER_QUARTER=15.00
CANCELLATION_CUTOFF_HOURS=24
CANCELLATION_FEE_FIXED=10.00
REQUIRE_DOCUMENTS_FOR_HANDOVER=1
REQUIRE_DEPOSIT_FOR_HANDOVER=1
```

> **Security Rule:** Never commit the `.env` file containing production credentials to version control. Always verify that `.gitignore` contains `.env`.

---

## 2. Web Server Configuration

### Apache (`.htaccess` / VirtualHost)
Ensure `mod_rewrite`, `mod_headers`, and `mod_ssl` are enabled:
```apache
<VirtualHost *:443>
    ServerName rental.yourdomain.com
    DocumentRoot /var/www/rentalkh

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/rental.yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/rental.yourdomain.com/privkey.pem

    # Security Headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

    # Prevent access to dotfiles and SQL dumps
    <FilesMatch "^\.|\.sql$|\.env">
        Require all denied
    </FilesMatch>

    <Directory /var/www/rentalkh>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/rentalkh_error.log
    CustomLog ${APACHE_LOG_DIR}/rentalkh_access.log combined
</VirtualHost>
```

### Nginx + PHP-FPM
```nginx
server {
    listen 80;
    server_name rental.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name rental.yourdomain.com;
    root /var/www/rentalkh;
    index index.php Service.php;

    ssl_certificate /etc/letsencrypt/live/rental.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/rental.yourdomain.com/privkey.pem;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Deny access to sensitive files
    location ~* (\.env|\.git|\.sql|\.log|\.md)$ {
        deny all;
        return 404;
    }

    # Uploads directory execution protection
    location ^~ /image/ {
        # Only allow static file serving, execute no scripts
        location ~ \.php$ {
            deny all;
        }
    }

    location / {
        try_files $uri $uri/ /Service.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## 3. Directory Permissions

Set restrictive ownership and permissions so PHP runs securely:
```bash
# Set application ownership (e.g. www-data)
chown -R www-data:www-data /var/www/rentalkh

# Directories: 755
find /var/www/rentalkh -type d -exec chmod 755 {} \;

# Files: 644
find /var/www/rentalkh -type f -exec chmod 644 {} \;

# Uploads directory requires write permissions for image uploads
chmod -R 775 /var/www/rentalkh/image

# Protect .env
chmod 600 /var/www/rentalkh/.env
```

---

## 4. Database Setup & Migration Sequence

### Initial Setup
```bash
mysql -u root -p -e "CREATE DATABASE rentalkh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'rentalkh_app'@'localhost' IDENTIFIED BY 'StrongPassword!';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON rentalkh.* TO 'rentalkh_app'@'localhost'; FLUSH PRIVILEGES;"
```

### Complete Migration Sequence
Always run migrations in sequential order:
```bash
# 1. Base Schema (Tables: users, categories, products, orders, order_items)
mysql -u rentalkh_app -p rentalkh < database.sql

# 2. Migration v2 (Availability, Hourly Pricing, Turnaround Buffer, Expired Bookings)
mysql -u rentalkh_app -p rentalkh < migration_v2.sql

# 3. Migration v3 (Financial Ledger, Escrow Deposits, Lifecycle Handover/Return, Damage Tickets)
mysql -u rentalkh_app -p rentalkh < migration_v3.sql

# 4. Migration v4 (RBAC Roles, Safety Inspection Gates, Notification Hub, System Audit Trail)
mysql -u rentalkh_app -p rentalkh < migration_v4.sql
```

### Rollback Strategy
All migrations preserve existing columns and use `ADD COLUMN IF NOT EXISTS` or `ALTER TABLE`. If a migration rollback is required:
1. Restore from the pre-migration snapshot (see Section 5).
2. Or execute reversal SQL statements targeting specific migration features.

---

## 5. Backup & Disaster Recovery Procedures

### Automated Daily Backup Script (`/usr/local/bin/backup_rentalkh.sh`)
```bash
#!/bin/bash
set -e

BACKUP_DIR="/var/backups/rentalkh"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
DB_NAME="rentalkh"
DB_USER="rentalkh_app"
DB_PASS="StrongPassword!"

mkdir -p "$BACKUP_DIR"

# Dump database with single-transaction (no table locks)
mysqldump --single-transaction --quick --routines --triggers \
    -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$BACKUP_DIR/rentalkh_$TIMESTAMP.sql.gz"

# Backup user image uploads
tar -czf "$BACKUP_DIR/uploads_$TIMESTAMP.tar.gz" -C /var/www/rentalkh image

# Retain backups for 30 days
find "$BACKUP_DIR" -type f -mtime +30 -delete

echo "[$(date)] Backup completed: rentalkh_$TIMESTAMP.sql.gz"
```
Make executable and schedule in cron:
```bash
chmod +x /usr/local/bin/backup_rentalkh.sh
# Run every night at 2:00 AM
echo "0 2 * * * /usr/local/bin/backup_rentalkh.sh >> /var/log/rentalkh_backup.log 2>&1" | crontab -
```

### Database Restore Procedure
```bash
# 1. Decompress dump
gunzip < /var/backups/rentalkh/rentalkh_YYYYMMDD_HHMMSS.sql.gz > /tmp/restore.sql

# 2. Restore into MySQL
mysql -u rentalkh_app -p rentalkh < /tmp/restore.sql

# 3. Clean up temporary file
rm /tmp/restore.sql
```

---

## 6. Scheduled Background Workers & Cron Jobs

RentalKH features automated background engines for stale booking expiration, pickup reminders, return due alerts, and overdue rental detection.

### Linux Crontab Configuration
Add to `/etc/cron.d/rentalkh` or run `crontab -e`:
```cron
# 1. Expire stale pending reservations every 15 minutes
*/15 * * * * php /var/www/rentalkh/cron/worker.php --action=expire_stale >> /var/log/rentalkh_cron.log 2>&1

# 2. Trigger automated reminder scanner (pickups, returns, overdue) every hour
0 * * * * php /var/www/rentalkh/cron/worker.php --action=reminders >> /var/log/rentalkh_cron.log 2>&1
```

---

## 7. Logging & Production Monitoring

RentalKH maintains three distinct, dedicated logging channels:
1. **Application Error Log (`error_log`):**
   - Captures unhandled exceptions, database connection errors, and critical server failures.
   - Configure in `php.ini`: `error_log = /var/log/php/rentalkh_error.log`.
2. **Financial Audit Trail (`financial_audit_logs` table):**
   - Immutable record of every payment verification, refund, deposit deduction, damage fee assessment, and balance adjustment.
3. **Operational Security Audit Trail (`system_audit_logs` table):**
   - Records logins, role authorizations, permission-denied events, vehicle status transitions, maintenance schedules, and security incidents.
   - Automatically sanitizes and masks sensitive data (`password=***`, `token=***`, `cvv=***`).

### Production Health Check Probe (`healthcheck.php`)
RentalKH provides a diagnostic probe for container orchestration (Kubernetes, Docker), uptime monitors, and load balancers:
- **HTTP Endpoint**: `GET /healthcheck.php?format=json` (returns HTTP 200 with structured JSON status, HTTP 503 if degraded/down)
- **CLI Diagnostic**: `php healthcheck.php`
- **Monitored Dimensions**: PHP runtime version, required extensions (`mysqli`, `json`, `session`), MySQL connectivity & query latency, and upload storage write permissions.

---

## 8. Recovery from Failed Requests & Transaction Deadlocks

- **Idempotency Keys:** Every customer and staff action accepts or generates an `idempotency_key` (notifications) and unique `payment_reference` (payments). In the event of a browser refresh, network retry, or timeout, duplicate charges and alerts are safely blocked.
- **Transaction Rollback:** All multi-table mutations in `RentalService` (`createBooking`, `recordHandover`, `recordReturn`, `recordPayment`, `refundDeposit`, `deductDeposit`, `completeRental`, `cancelBooking`) are wrapped in `begin_transaction()` and catch `Throwable`. Any database or constraint error initiates an immediate `$conn->rollback()`, leaving zero partial or corrupt rows.
