# JOUL Rental Service

A full-featured, enterprise-grade vehicle and equipment rental platform built with PHP 8.x and MySQL. JOUL Rental combines a modern customer storefront with end-to-end fleet operations, role-based governance, escrow-backed financial accounting, and an automated background worker.

---

## Key Features

### 1. Customer Storefront & Booking Experience
- **Dynamic Vehicle Catalog**: Filter vehicles by category (City Car, Passenger Van, Bicycle, Scooter) with real-time availability badges, spec chips, and daily/hourly rates (`second1.php`).
- **Interactive Rental Cart & Sticky Drawer**: Select pickup and return schedules, calculate multi-day rental totals, and submit booking requests with instant concurrency checks.
- **Customer Booking Portal**: Track rental lifecycle milestones, review deposit escrow statuses, inspect vehicle details, and download booking receipts (`user_orders.php`).
- **Profile & Credential Management**: Update contact information, submit driver's license details, and manage national ID/passport numbers (`profile.php`).

### 2. Fleet & Maintenance Operations
- **Fleet Inventory Control**: Register, update, and manage vehicle attributes including plate numbers, mileage, condition ratings, fuel types, and daily rates (`Product.php`, `product_edit.php`).
- **Lifecycle & Maintenance Schedules**: Track scheduled maintenance, multi-point digital inspection logs, tire/brake checks, and turnaround buffer cleaning intervals (`maintenance.php`).
- **Turnaround Buffer Protection**: Mandatory 2-hour cleaning and safety inspection windows enforced between consecutive rentals.

### 3. Financial Integrity & Escrow Accounting
- **Double-Entry Financial Ledger**: Verified transaction ledger recording rental charges, damage deductions, fuel reconciliation, and late return fees (`finance.php`).
- **Security Deposit Escrow**: Segregated deposit escrow tracking to ensure customer deposits remain safe and are transparently refunded upon inspection.
- **Operational KPI Analytics**: Real-time breakdown of Net Rental Revenue, Cash Collected, Deposits Held in Escrow, and Outstanding Balances.

### 4. Role-Based Access Control (RBAC) & Security
- **Granular Permissions**: Server-side enforcement for `view_dashboard`, `manage_vehicles`, `manage_maintenance`, `manage_categories`, `manage_bookings`, `view_finance_reports`, and `manage_customers`.
- **CSRF & Session Hardening**: Cross-Site Request Forgery (CSRF) tokens across all state-mutating forms, Strict SameSite cookies, session regeneration on privilege elevation, and baseline HTTP security headers (`config.php`).
- **Audit Logging**: Comprehensive operational audit trail recording logins, status updates, maintenance dispatches, and financial ledger modifications.

### 5. Automated Background Worker & System Diagnostics
- **Scheduled Cron Worker**: CLI task runner to automatically expire stale pending reservations, dispatch upcoming pickup reminders, and purge expired tokens (`cron/worker.php`).
- **System Health Diagnostic Probe**: Multi-format health probe supporting CLI, JSON (for Kubernetes / Docker / load-balancer probes), and styled HTML status views (`healthcheck.php`).

---

## Technologies Used

- **Backend**: PHP (8.0+ supported, 8.5+ compatible)
- **Database**: MySQL / MariaDB (InnoDB engine, foreign key constraints)
- **Frontend**: HTML5, Vanilla CSS Design System, JavaScript (ES6)
- **Typography**: Google Fonts (Inter scale for modern readability)
- **Testing**: Native PHP CLI automated test harness (197 automated test cases)

---

## Getting Started

### Prerequisites

- PHP 8.0 or higher with `mysqli`, `json`, and `session` extensions
- MySQL 5.7+ or MariaDB 10.3+

### Installation & Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/gouvkimsea/rental-service.git
   cd rental-service
   ```

2. **Configure Environment:**
   - Copy the `.env.example` template:
     ```bash
     cp .env.example .env
     ```
   - Configure database credentials and application settings in `.env`:
     ```ini
     APP_ENV=development
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_USER=root
     DB_PASS=
     DB_NAME=rentalkh
     ```

3. **Database Initialization & Migrations:**
   - Create the MySQL database:
     ```bash
     mysql -u root -p -e "CREATE DATABASE rentalkh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
     ```
   - Import the base schema and sequential migrations:
     ```bash
     mysql -u root -p rentalkh < database.sql
     mysql -u root -p rentalkh < migration_v2.sql
     mysql -u root -p rentalkh < migration_v3.sql
     mysql -u root -p rentalkh < migration_v4.sql
     ```

4. **Launch Local Server:**
   ```bash
   php -S 127.0.0.1:8000
   ```
   Navigate to `http://127.0.0.1:8000/`.

---

## Background Tasks & Maintenance

Execute scheduled background maintenance tasks via CLI:

```bash
# Run all background routines (expire stale reservations, send reminders, maintenance purge)
php cron/worker.php --action=all

# Or run specific actions:
php cron/worker.php --action=expire_stale
php cron/worker.php --action=reminders
php cron/worker.php --action=cleanup
```

---

## System Health Diagnostic

Check application readiness and database latency:

```bash
# CLI format:
php healthcheck.php

# JSON format (for Docker/Kubernetes health probes):
curl -s http://127.0.0.1:8000/healthcheck.php?format=json
```

---

## Automated Test Harness

RentalKH features a comprehensive CLI test harness with 197 automated test assertions:

```bash
# Run all 5 test suites with unified summary report
php tests/run_all_tests.php
```

Or execute individual test suites:
1. **Rental Business Rules & Booking Engine**: `php tests/rental_business_tests.php`
2. **Operational Management & RBAC**: `php tests/operational_management_tests.php`
3. **Financial Operations & Ledger**: `php tests/financial_operations_tests.php`
4. **End-to-End Production Verification**: `php tests/production_e2e_tests.php`
5. **UI Rendering & View Integrations**: `php tests/render_test.php`

---

## Directory Structure

```
├── config.php              # Database connection, security headers & CSRF protection
├── rental_service.php      # Core business logic, booking engine & financial ledger
├── Service.php             # Customer storefront home page
├── second1.php             # Vehicle fleet catalog & booking drawer
├── user_orders.php         # Customer booking history & receipt tracking
├── profile.php             # Customer profile & document verification
├── login.php / signin.php  # Secure authentication suite
├── forgot_password.php     # Account recovery & reset flow
├── dashboard.php           # Staff operations overview dashboard
├── Product.php             # Fleet vehicle inventory table
├── product_edit.php        # Vehicle creation and editing form
├── orders.php              # Orders management & status transitions
├── order_detail.php        # Booking lifecycle & transaction ledger
├── maintenance.php         # Vehicle maintenance & inspection logs
├── finance.php             # Financial reports, cash ledger & escrow
├── categories.php          # Fleet category management
├── users.php               # User governance & staff role permissions
├── notifications.php       # Operational alerts & user notification center
├── healthcheck.php         # Readiness probe (CLI, JSON, HTML)
├── style.css               # Design system tokens, components & utilities
├── cron/
│   └── worker.php          # Scheduled background task runner
├── tests/                  # Automated test harness (197 test cases)
└── database.sql            # Base schema and seed data
```

---

## Production Deployment

For complete server configurations (Nginx reverse proxy, Apache virtual hosts, HTTPS/SSL certificates, systemd background worker timers, and backup routines), refer to [DEPLOYMENT.md](file:///d:/learn/reantalservise/DEPLOYMENT.md).
