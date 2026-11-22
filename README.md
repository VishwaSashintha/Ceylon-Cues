# Ceylon Cues

## Overview

Ceylon Cues is a business management and commerce platform designed for a billiards and pool equipment retailer in Sri Lanka. The system unifies customer-facing online retail, central ERP business management, multi-location inventory tracking, and physical retail shop point-of-sale (POS) operations into a single platform powered by a central PHP REST API and MySQL database.

## Main Features

- Customer-Facing Storefront: Product catalog with filtering by category and quality tier, debounced search, product specifications, shopping cart management, coupon application, district-based delivery calculation, order placement, and customer account management with order tracking and support tickets.
- Central ERP Dashboard: Executive business dashboard with sales summaries, revenue by channel, inventory valuation, recent orders, and operational alerts.
- Multi-Location Inventory: Dual-location tracking across Main Warehouse and Physical Retail Shop, internal stock transfers with approval workflow, stock adjustments, and immutable movement audit logs.
- Online Stock Allocation: Independent allocation of physical inventory for online sales to prevent overselling.
- Retail Point of Sale (POS): Cashier register session management with opening float and closing cash reconciliation, barcode/SKU scanning, live shop inventory enforcement, cash tender with change calculation, card payment reference recording, and printable thermal receipts.
- Order Management: Multi-channel order processing (online and POS), status transitions (pending, confirmed, processing, shipped, delivered, cancelled), and cancellation with automatic reservation release.
- Payment Management: Support for cash, bank transfers with customer deposit slip submission and owner verification, sandbox card/BNPL gateways, and order refunds.
- Wholesale Inquiries: Dedicated inquiry submission and management for billiard clubs, hotels, and bulk buyers.
- Customer Support: In-platform ticketing system with threaded messaging between customers and staff.
- Marketing and Discounts: Percentage, fixed amount, and free delivery promotional codes with usage limits, minimum order thresholds, and date validity.
- Employee Management and RBAC: Role-based access control with predefined roles (owner, shop manager, shop cashier, warehouse manager, inventory staff, order staff, finance staff, customer support) and granular permissions.
- Business Administration: Automated full database backup generation and notification queue processing.

## Technology Stack

- Backend: PHP 8.2+ (native PSR-4 modular architecture)
- Frontend: HTML5, CSS3 (custom responsive design tokens), JavaScript (ES6+ modular client)
- Database: MySQL 8.0 / MariaDB 10.4+ (InnoDB, utf8mb4)
- Web Server: Apache with mod_rewrite (XAMPP environment)
- Security: Argon2id password hashing, SHA-256 session tokens, parameterized PDO queries, rate limiting, and CSRF protection
- Package Management: Composer

## Project Structure

- bin/: CLI utility scripts, including background notification worker.
- config/: Application and database connection configuration.
- database/: Schema migrations (60 migrations), seeds, and installation scripts.
- docs/: API documentation, including OpenAPI 3.0 specification.
- public/: Web root containing the front controller, storefront, ERP, POS interfaces, and asset directories.
- public/assets/: Shared CSS design system and JavaScript controllers (api.js, storefront.js, erp.js, pos.js).
- routes/: REST API route definitions.
- src/: Core application classes, HTTP middleware, controllers, and business service modules.
- storage/: Temporary runtime storage, backups, and log files.
- tests/: Automated scenario test runner covering 22 integration workflows.

## Installation and Setup

### Prerequisites

- PHP 8.2 or later with pdo_mysql, curl, mbstring, and openssl extensions enabled.
- MySQL 8.0 or MariaDB 10.4 or later.
- Composer.
- Apache web server (or local XAMPP installation).

### Step 1: Clone the Repository

```bash
git clone https://github.com/VishwaSashintha/Ceylon-Cues.git
cd Ceylon-Cues
```

### Step 2: Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### Step 3: Configure Environment

Copy the example environment configuration file:

```bash
cp .env.example .env
```

Edit the .env file with your local database connection parameters:

```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ceylon_cues
DB_USERNAME=root
DB_PASSWORD=
APP_URL=http://localhost/ceylon-cues
```

### Step 4: Run Migrations and Seed Data

Create the database and execute migrations:

```bash
php database/install.php
php database/seed.php
```

### Step 5: Bootstrap Owner Account

Create the initial Super Administrator / Owner identity:

```bash
php database/bootstrap.php
```

## Configuration

- Web Server: When deploying under an Apache subfolder (such as XAMPP htdocs), ensure mod_rewrite and mod_headers are enabled. The included public/.htaccess file handles route rewriting and passes the Authorization header to PHP.
- WhatsApp Business API: Optional cloud notification integration. Configure WHATSAPP_PHONE_NUMBER_ID and WHATSAPP_ACCESS_TOKEN in .env when activating live WhatsApp messaging.
- Payment Gateways: Online card and BNPL providers require merchant account credentials. By default, payment processing operates in sandbox mode for development. Real payment processing requires active provider credentials.

## Running the Application

### Using XAMPP

1. Place or link the project directory inside the XAMPP htdocs folder:
   ```cmd
   mklink /J "C:\xampp\htdocs\ceylon-cues" "c:\path\to\Ceylon Cues"
   ```
2. Start the Apache and MySQL services from the XAMPP Control Panel.
3. Access the interfaces in a web browser:
   - Customer Storefront: http://localhost/ceylon-cues/public/index.html
   - Central ERP: http://localhost/ceylon-cues/public/erp.html
   - Retail Shop POS: http://localhost/ceylon-cues/public/pos.html
   - REST API Base: http://localhost/ceylon-cues/public/api/v1/

### Using PHP Built-in Server (Alternative)

```bash
php -S localhost:8000 -t public
```

### Running Tests

Execute the automated test suite verifying 22 business scenarios:

```bash
php tests/run-tests.php
```

## Notes

- Currency: All monetary amounts are handled in Sri Lankan Rupees (LKR) with standard two-decimal precision.
- Timezone: Default application timezone is configured to Asia/Colombo (+05:30).
- Payment Gateway Notice: External payment gateways (e.g., PayHere, KOKO, Mintpay) require verified merchant credentials and webhook endpoints for production activation. In local environments, bank transfer with manual verification is fully functional for live order processing.
