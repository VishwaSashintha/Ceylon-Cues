# Ceylon Cues — Complete Backend System

Production-ready backend architecture for **Ceylon Cues**, a premier billiards, pool cue, and cue-sports accessories enterprise based in Sri Lanka.

The backend operates as an independent, secure REST API engine powering central ERP operations, multi-location inventory (Main Warehouse and Retail Shop), online website stock allocation, retail shop POS terminals, purchase orders, customer service, payments, and business analytics.

---

## 1. Project Overview

Ceylon Cues is a privately owned enterprise. The owner serves as the sole Super Administrator.

This backend provides a unified, centralized data model and API surface connecting client applications:
1. **Customer-Facing E-Commerce Storefront**
2. **Central ERP Business Management Dashboard**
3. **Physical Retail Shop Point of Sale (POS) Terminal**

All operations share one central MySQL 8 database, strict relational integrity, atomic database transactions (`FOR UPDATE`), and consistent financial precision in Sri Lankan Rupees (`LKR`).

---

## 2. Backend Architecture

```
                       ┌────────────────────────────────────────────────┐
                       │               Client Applications              │
                       │   Storefront  │  ERP Dashboard  │  Retail POS  │
                       └───────────────────────┬────────────────────────┘
                                               │ HTTP / REST (JSON)
                                               ▼
                       ┌────────────────────────────────────────────────┐
                       │       Apache Front Controller (public/)        │
                       │       index.php  +  .htaccess URL Rewriting    │
                       └───────────────────────┬────────────────────────┘
                                               │
                                               ▼
                       ┌────────────────────────────────────────────────┐
                       │               Core Middleware                  │
                       │  SecurityHeaders │ CorsMiddleware (Whitelisted)│
                       │  JsonBodyMiddleware  │ RateLimitMiddleware     │
                       │  AuthMiddleware      │ RoleMiddleware          │
                       └───────────────────────┬────────────────────────┘
                                               │
                                               ▼
                       ┌────────────────────────────────────────────────┐
                       │           REST API Controllers (v1)            │
                       │  Auth / 2FA │ Products    │ Categories │ Cart  │
                       │  Checkout   │ Orders      │ Pos        │ Erp   │
                       │  Inventory  │ Purchasing  │ Suppliers  │ COD   │
                       │  Customers  │ Delivery    │ Marketing  │ CMS   │
                       │  Reports    │ Settings    │ Support    │ Pay   │
                       └───────────────────────┬────────────────────────┘
                                               │
                                               ▼
                       ┌────────────────────────────────────────────────┐
                       │              Business Services                 │
                       │  Auth / TotpService │  OrderService            │
                       │  InventoryService   │  StockAllocationService  │
                       │  PosService         │  PurchaseOrderService    │
                       │  SupplierService    │  CustomerService (PDPA)  │
                       │  ReturnService      │  PaymentService          │
                       │  ReportService      │  BackupService (AES-256) │
                       └───────────────────────┬────────────────────────┘
                                               │
                                               ▼
                       ┌────────────────────────────────────────────────┐
                       │           MySQL 8 Database (PDO Engine)        │
                       │ 75 Relational Tables, Transactions & Indexes   │
                       └────────────────────────────────────────────────┘
```

---

## 3. Technology Stack

| Layer | Technology | Details |
|---|---|---|
| **Language** | PHP 8.2+ | Strict typing (`declare(strict_types=1)`), typed properties, match expressions |
| **Database** | MySQL 8.0+ / MariaDB 10.4+ | InnoDB, Foreign Key Constraints, Row-level Locking (`FOR UPDATE`), 75 tables |
| **Database Abstraction** | PDO | Prepared statements with parameterized queries, SQLi immunity |
| **Security Headers** | Custom Middleware | HSTS, CSP, X-Frame-Options (`SAMEORIGIN`), X-Content-Type-Options (`nosniff`) |
| **CORS** | Whitelist-based | Configurable domain whitelist with credentials and `Vary: Origin` |
| **Password Hashing** | Argon2id | Memory cost 65536, time cost 4, threads 1 |
| **Two-Factor Auth (2FA)** | RFC 6238 TOTP | Pure-PHP implementation, 30s step, clock-skew tolerance |
| **Backup Encryption** | AES-256-CBC | Authenticated symmetrical encryption with IV prefixing |
| **Testing** | PHPUnit 10.5 & Custom Runner | 25 unit/feature tests + 29 end-to-end integration scenario tests |
| **Currency** | `LKR` (Sri Lankan Rupee) | Standardized `DECIMAL(12,2)` / `DECIMAL(14,2)` arithmetic |
| **Timezone** | `Asia/Colombo` (UTC+5:30) | Consistent across PHP runtime, migrations, and database queries |

---

## 4. Repository Structure

The repository is organized into exactly two top-level application directories:

```text
Ceylon-Cues/
├── .github/                        GitHub Actions CI Workflows
│   └── workflows/tests.yml         Automated test pipeline for PHP 8.2 & MySQL
│
├── backend/                        PHP 8.2+ Application Source Code
│   ├── bin/                        CLI Tools & Schedulers
│   │   ├── cron.php                Central cron engine (sales summaries, expiry, backups)
│   │   ├── notification-worker.php Background queue worker for notifications
│   │   └── restore-backup.php      CLI restore script for AES-256 encrypted backups
│   ├── config/                     Application Configuration
│   │   ├── app.php                 General environment & timezone settings
│   │   └── database.php            PDO connection configuration
│   ├── database/                   Database Migrations, Seeds & Scripts
│   │   ├── migrations/             Schema migration scripts (001 to 100)
│   │   ├── bootstrap.php           Secure owner bootstrapper (random password, Argon2id)
│   │   ├── install.php             Fresh database creation & migration runner
│   │   ├── migrate.php             Migration runner
│   │   └── seed.php                Seed data (categories, initial products, settings)
│   ├── docs/                       Architecture & API Documentation
│   │   ├── API.md                  Complete Markdown API documentation
│   │   └── openapi.json            OpenAPI 3.0 specification
│   ├── public/                     Web server document root
│   │   ├── uploads/                Public catalog assets (PHP execution denied via .htaccess)
│   │   ├── sitemap.php             Dynamic XML sitemap generator
│   │   ├── .htaccess               URL rewrite rules routing to index.php
│   │   └── index.php               Single entry front controller
│   ├── routes/                     API Route definitions
│   │   └── api.php                 All v1 API routes (Auth, POS, ERP, Storefront, CMS)
│   ├── src/                        Core classes, Controllers, Middleware, Services
│   │   ├── Controllers/Api/V1/     Controllers for all modules
│   │   ├── Core/                   Application, Database, Env, Request, Response, Router, Validator
│   │   ├── Middleware/             SecurityHeaders, Cors, JsonBody, Auth, Role, RateLimit
│   │   └── Services/               Domain business logic services
│   ├── storage/                    Private storage (web-inaccessible)
│   │   ├── backups/                AES-256 encrypted database dumps (.sql.enc)
│   │   ├── logs/                   Application execution logs
│   │   └── uploads/payments/       Private bank-transfer deposit slips (served via auth endpoint)
│   ├── tests/                      Automated test suites
│   │   ├── Unit/                   PHPUnit unit tests (TOTP, Hashing, Idempotency, Backups)
│   │   ├── Feature/                PHPUnit feature tests (Auth, Orders, POS, Wishlists)
│   │   └── run-tests.php           29 Master business scenario integration tests
│   ├── phpunit.xml                 PHPUnit configuration
│   ├── composer.json               Composer dependencies
│   ├── composer.lock
│   └── .env.example                Template environment configuration
│
├── frontend/                       Frontend Application Directory (Reserved for Frontend Phase)
│   └── .gitkeep                    Empty directory tracked by Git
│
├── .gitignore                      Git ignore rules
└── README.md                       Project documentation
```

---

## 5. Requirements

- **PHP**: 8.2 or higher (with `pdo_mysql`, `curl`, `mbstring`, `json`, `openssl` extensions enabled).
- **MySQL / MariaDB**: 8.0+ (InnoDB storage engine support).
- **Apache**: Web server with `mod_rewrite` enabled.
- **Composer**: Dependency manager.

---

## 6. Installation & Setup

### 1. Environment Configuration

Copy the template configuration file:

```bash
cp backend/.env.example backend/.env
```

Configure your local database credentials and app key in `backend/.env`:

```env
APP_NAME="Ceylon Cues"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/ceylon-cues/backend/public
APP_KEY=generate-a-secure-32-byte-hex-key
APP_TIMEZONE=Asia/Colombo

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ceylon_cues
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4

ALLOWED_ORIGINS=http://localhost:3000,http://localhost:8080,https://ceyloncues.lk
WEBHOOK_SECRET=your-secure-webhook-secret
```

### 2. Install Dependencies

```bash
cd backend
composer install
```

### 3. Database Initialization

Run the database installer to create the database schema and execute all migrations:

```bash
php database/install.php
```

Populate initial categories, products, delivery zones, and templates:

```bash
php database/seed.php
```

Bootstrap the initial owner account:

```bash
php database/bootstrap.php
```

> **Security Note:** `bootstrap.php` generates a cryptographically secure random password, hashes it using Argon2id, and prints it once to your console. It enforces a strict single-owner policy and never hardcodes credentials.

---

## 7. Web Server Configuration

Point your virtual host document root directly to `backend/public/`:

```apache
<VirtualHost *:80>
    ServerName ceyloncues.local
    DocumentRoot "/path/to/Ceylon-Cues/backend/public"
    <Directory "/path/to/Ceylon-Cues/backend/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

---

## 8. Automated Testing

### PHPUnit Test Suite

Run the full suite of unit and feature tests:

```bash
cd backend
vendor/bin/phpunit --no-coverage
```

### 29 Master Business Scenarios Test Suite

Execute the comprehensive end-to-end integration test runner:

```bash
php backend/tests/run-tests.php
```

All 29 business scenarios verify:
1. Product lifecycle and SKU assignment
2. Main warehouse stock additions
3. Warehouse-to-Shop stock transfers
4. Virtual online stock allocations
5. Storefront checkout and stock reservation
6. Oversell prevention and concurrency locks
7. POS sales execution and cash change calculation
8. POS shop inventory deduction
9. Warehouse stock invariance
10. Overselling denial
11. Private bank-transfer proof upload and storage
12. Manual bank-transfer payment verification
13. District delivery calculation & free shipping
14. Percentage and fixed discount codes
15. Order cancellations and stock restoration
16. Return requests and financial refund processing
17. Customer support ticketing thread
18. WhatsApp notification queueing
19. Notification duplicate prevention and worker execution
20. Role-Based Access Control (RBAC) enforcement
21. AES-256 encrypted database backups
22. SQL injection, Argon2id, and IDOR defenses
23. Supplier management & purchase history
24. Purchase orders and Goods Receipts (GRN)
25. Customer address book and lifetime spend tracking
26. Return lifecycle and warehouse restock
27. Physical stock count sheets & reconciliation
28. Financial reporting (P&L, sales, inventory valuation)
29. POS session register audit & daily sales calculations

---

## 9. Scheduled Jobs & Background Workers

### Central Cron Engine (`backend/bin/cron.php`)

Runs recurring maintenance tasks:
- **Reservation Expiry**: Cancels unpaid orders older than expiration and restores inventory.
- **Campaign Lifecycle**: Activates scheduled campaigns and concludes ended ones.
- **Coupon Expiry**: Deactivates expired discount codes.
- **Daily Sales Summary**: Aggregates daily orders and revenue.
- **Low-Stock Alerts**: Detects items below reorder points and queues alerts.
- **Database Backup**: Creates AES-256 encrypted SQL dumps and purges backups older than 30 days.
- **Audit Log Cleanup**: Prunes audit entries older than 90 days.

Configure on your server's crontab:

```bash
0 * * * * php /path/to/Ceylon-Cues/backend/bin/cron.php >> /var/log/ceylon-cron.log 2>&1
```

### Notification Queue Worker (`backend/bin/notification-worker.php`)

Dispatches asynchronous WhatsApp, SMS, and Email notifications from `notification_queue`:

```bash
php backend/bin/notification-worker.php --limit=50
```

### Encrypted Database Backup & Restore

Create an encrypted backup:
```bash
php -r "require 'backend/vendor/autoload.php'; (new CeylonCues\Services\Settings\BackupService())->createBackup();"
```

Restore an encrypted backup:
```bash
php backend/bin/restore-backup.php <filename.sql.enc>
```

---

## 10. API Documentation

Detailed documentation of all endpoints, request schemas, authentication requirements, and response structures:
- **Markdown Reference:** `backend/docs/API.md`
- **OpenAPI 3.0 Specification:** `backend/docs/openapi.json`
- **XML Sitemap:** `GET /sitemap.php`
