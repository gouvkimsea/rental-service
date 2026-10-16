# JOUL Rental Service

A web-based vehicle and equipment rental management platform built with PHP and MySQL.

## Features

- **Customer Storefront**: Browse rental vehicles/items by category, view details, and place rental orders.
- **Admin Dashboard**:
  - Overview metrics and business analytics.
  - Product catalog management (add, edit, delete, view items).
  - Category management.
  - Order review, tracking, and approval workflow.
  - User and account management.
- **Authentication**: Secure login, signup, session control, role-based access (Customer / Administrator), and password management.
- **Database**: Complete MySQL schema and sample seed data included (`database.sql`).

## Technologies Used

- **Backend**: PHP (8.x)
- **Database**: MySQL / MariaDB
- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap 5
- **Fonts & Icons**: Google Fonts (Odibee Sans, Saira Semi Condensed)

## Getting Started

### Prerequisites

- PHP 8.x
- MySQL or MariaDB (e.g., via XAMPP)

### Installation & Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/gouvkimsea/rental-service.git
   cd rental-service
   ```

2. **Configure Environment:**
   - Copy the template `.env.example` to `.env`:
     ```bash
     cp .env.example .env
     ```
   - Update database credentials and operational settings in `.env`.

3. **Import Database & Run Migrations:**
   - Create a MySQL database named `rentalkh`:
     ```bash
     mysql -u root -p -e "CREATE DATABASE rentalkh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
     ```
   - Apply base schema and sequential migrations:
     ```bash
     mysql -u root -p rentalkh < database.sql
     mysql -u root -p rentalkh < migration_v2.sql
     mysql -u root -p rentalkh < migration_v3.sql
     mysql -u root -p rentalkh < migration_v4.sql
     ```

4. **Run the Application Locally:**
   ```bash
   php -S 127.0.0.1:8000
   ```
   Navigate to `http://127.0.0.1:8000/`.

---

## Automated Test Suites

RentalKH includes an automated test harness covering business rules, pricing, operational RBAC, financial ledger, and end-to-end production scenarios:

```bash
# Run all automated test suites at once with summary report
php tests/run_all_tests.php

# Or run individual test suites:
# 1. Complete End-to-End Production Verification (15 Scenarios)
php tests/production_e2e_tests.php

# 2. Operational Management & Role-Based Access Control (RBAC)
php tests/operational_management_tests.php

# 3. Financial Integrity, Ledger & Escrow Accounting
php tests/financial_operations_tests.php

# 4. Rental Business Rules & Concurrency Engine
php tests/rental_business_tests.php
```

---

## Production Deployment & Operations

For complete web server configuration (Nginx, Apache, SSL), directory permissions, cron workers, and backup/restore procedures, consult [DEPLOYMENT.md](file:///d:/learn/reantalservise/DEPLOYMENT.md).

