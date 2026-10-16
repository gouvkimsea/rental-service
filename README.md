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

### Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/gouvkimsea/rental-service.git
   ```

2. **Import the Database:**
   - Create a MySQL database named `rentalkh` (or your preferred name).
   - Import the schema from `database.sql`:
     ```bash
     mysql -u root -p rentalkh < database.sql
     ```

3. **Configure Database Connection:**
   - Review or modify `config.php` to match your local database settings (defaults to `localhost`, user `root`, empty password, and database `rentalkh`).

4. **Run the Application:**
   - Using the PHP built-in server:
     ```bash
     php -S localhost:8000
     ```
   - Or place the project in your web server root (e.g., `C:/xampp/htdocs/reantalservise`).

5. Open your browser and navigate to `http://localhost:8000/`.
