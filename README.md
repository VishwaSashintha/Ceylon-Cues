# Ceylon Cues — Complete Backend System

Production-ready backend architecture for **Ceylon Cues**, a premium billiards, pool cue, and cue-sports accessories enterprise based in Sri Lanka. 

The backend operates as an independent, secure REST API engine powering central ERP operations, multi-location inventory (Main Warehouse and Retail Shop), online website stock allocation, retail shop POS terminals, purchase orders, customer service, payments, and business analytics.

---

## 1. Project Overview

Ceylon Cues is a privately owned single-enterprise business. The owner serves as the sole Super Administrator.

This backend provides a unified, centralized data model and API surface designed to connect future client applications:
1. **Customer-Facing E-Commerce Storefront**
2. **Central ERP Business Management Dashboard**
3. **Physical Retail Shop Point of Sale (POS) Terminal**

All operations share one central MySQL 8 database, strict relational integrity, atomic database transactions, and consistent financial precision.

---

## 2. Backend Architecture

```
                       ┌────────────────────────────────────────────────┐
                       │               Future Clients                   │
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
                       │  CorsMiddleware  │  JsonBodyMiddleware         │
                       │  AuthMiddleware  │  RoleMiddleware             │
                       │  RateLimitMiddleware │ CsrfMiddleware         │
                       └───────────────────────┬────────────────────────┘
                                               │
                                               ▼
                       ┌────────────────────────────────────────────────┐
                       │           REST API Controllers (v1)            │
                       │  Auth      │ Products   │ Categories │ Cart    │
                       │  Checkout  │ Orders     │ Pos        │ Erp     │
                       │  Inventory │ Purchasing │ Suppliers  │ Returns │
                       │  Customers │ Delivery   │ Marketing  │ Reports │
                       │  Settings  │ Support    │ Payments   │ Notif   │
                       └───────────────────────┬────────────────────────┘
                                               │
                                               ▼
                       ┌────────────────────────────────────────────────┐
                       │              Business Services                 │
                       │  OrderService      │  InventoryService         │
                       │  StockTransfer     │  StockAllocationService   │
                       │  PosService        │  PurchaseOrderService     │
                       │  SupplierService   │  CustomerService          │
                       │  ReturnService     │  PaymentService           │
                       │  ReportService     │  WhatsAppService          │
                       └───────────────────────┬────────────────────────┘
                                               │
                                               ▼
                       ┌────────────────────────────────────────────────┐
                       │           MySQL 8 Database (PDO Engine)        │
                       │   61 Relational Tables, Transactions & Foreign Keys│
                       └────────────────────────────────────────────────┘
```

---

## 3. Technology Stack

| Layer | Technology | Details |
|---|---|---|
| **Language** | PHP 8.2+ | Native strict typing, typed properties, match expressions |
| **Database** | MySQL 8.0+ | InnoDB, Foreign Key Constraints, Transactions (`FOR UPDATE`), 61 tables |
| **Database Abstraction** | PDO | Prepared statements with parameterized queries, SQLi immunity |
| **Web Server** | Apache (XAMPP) | URL rewriting via `public/.htaccess` |
| **Password Hashing** | Argon2id / Bcrypt | Sensitive data protected with secure salt generation |
| **Authentication** | Cryptographic Session Tokens | High-entropy 64-character tokens, stored hashed, rate-limited |
| **Currency** | `LKR` (Sri Lankan Rupee) | Standardized `DECIMAL(12,2)` / `DECIMAL(14,2)` arithmetic |
| **Timezone** | `Asia/Colombo` (UTC+5:30) | Consistent across PHP runtime, migrations, and database queries |

---

## 4. Repository Structure

The repository is organized into exactly two top-level application directories:

```text
Ceylon-Cues/
├── backend/                        PHP 8.2+ Application Source Code
│   ├── bin/                        CLI Workers
│   │   └── notification-worker.php Background queue worker for notifications
│   ├── config/                     Application Configuration
│   │   ├── app.php                 General environment & timezone settings
│   │   └── database.php            PDO connection configuration
│   ├── database/                   Database Migrations, Seeds & Scripts
│   │   ├── migrations/             61 Migration scripts (001 to 099)
│   │   ├── bootstrap.php           Initializes owner account & settings
│   │   ├── install.php             Fresh database creation & migration runner
│   │   ├── migrate.php             Migration runner
│   │   └── seed.php                Seed data (categories, initial products, settings)
│   ├── docs/                       Architecture & API Documentation
│   │   ├── API.md                  Complete Markdown API documentation
│   │   └── openapi.json            OpenAPI 3.0 specification
│   ├── public/                     Web server document root
│   │   ├── uploads/                Persistent storage for images and proofs
│   │   ├── .htaccess               URL rewrite rules routing to index.php
│   │   └── index.php               Single entry front controller
│   ├── routes/                     API Route definitions
│   │   └── api.php                 All v1 API routes
│   ├── src/                        Core classes, Controllers, Middleware, Services
│   │   ├── Controllers/Api/V1/     Controllers for all modules
│   │   ├── Core/                   Application, Database, Env, Request, Response, Router, Validator
│   │   ├── Middleware/             Auth, CORS, CSRF, Role, RateLimit
│   │   └── Services/               Domain business logic services
│   ├── storage/                    Logs and database backups (git-ignored)
│   │   ├── backups/                Automated SQL database dumps
│   │   └── logs/                   Application execution logs
│   ├── tests/                      Automated scenario test suite
│   │   └── run-tests.php           29 Comprehensive end-to-end business test scenarios
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
- **XAMPP**: Recommended local development bundle on Windows.

---

## 6. XAMPP Installation & Directory Link

To serve the project directly from XAMPP without moving it from your workspace:

1. Open PowerShell or Command Prompt as **Administrator**.
2. Create a Windows Directory Junction from XAMPP's `htdocs` to your project folder:

```powershell
mklink /J "C:\xampp\htdocs\ceylon-cues" "c:\Users\Vishwa\Desktop\Ceylon Cues"
```

3. Ensure Apache and MySQL are running in the XAMPP Control Panel.

---

## 7. Database Setup

1. Copy the environment configuration template:

```powershell
Copy-Item backend\.env.example backend\.env
```

2. Configure database credentials in `backend\.env` (see Section 8).

3. Run the installer to create the database schema and execute all 61 migration scripts:

```powershell
C:\xampp\php\php.exe backend\database\install.php
```

4. Populate initial categories, products, delivery zones, notification templates, and settings:

```powershell
C:\xampp\php\php.exe backend\database\seed.php
```

5. Bootstrap the initial owner account and system settings:

```powershell
C:\xampp\php\php.exe backend\database\bootstrap.php
```

---

## 8. Environment Configuration

Edit `backend\.env`:

```env
APP_NAME="Ceylon Cues"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/ceylon-cues/backend/public
APP_TIMEZONE=Asia/Colombo

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ceylon_cues
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

JWT_SECRET=your-secure-random-jwt-secret-string-here
APP_KEY=your-secure-app-key-here

WHATSAPP_PROVIDER=official
WHATSAPP_API_URL=https://graph.facebook.com/v19.0/YOUR_PHONE_NUMBER_ID/messages
WHATSAPP_ACCESS_TOKEN=your_token_here
WHATSAPP_OWNER_NUMBER=+94770000000

MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=
MAIL_PASSWORD=
```

---

## 9. Owner Account Setup

Running `backend/database/bootstrap.php` initializes the sole Super Administrator account:

- **Email:** `owner@ceyloncues.lk`
- **Password:** `CeylonCuesOwner2026!`
- **Role:** `owner` (`is_owner = 1`)

The owner possesses a permanent RBAC bypass, allowing unrestricted access across all ERP, inventory, financial, and administrative operations.

---

## 10. How to Start the Backend

1. Start **Apache** and **MySQL** in XAMPP.
2. The REST API is accessible at:
   ```text
   http://localhost/ceylon-cues/backend/public/api/v1
   ```
3. Test connectivity with a public endpoint:
   ```text
   GET http://localhost/ceylon-cues/backend/public/api/v1/products
   GET http://localhost/ceylon-cues/backend/public/api/v1/categories
   GET http://localhost/ceylon-cues/backend/public/api/v1/settings/public
   ```

---

## 11. API Documentation Location

Detailed documentation of all endpoints, parameters, permissions, request schemas, and responses is provided in:

- **Markdown Reference:** [`backend/docs/API.md`](file:///c:/Users/Vishwa/Desktop/Ceylon%20Cues/backend/docs/API.md)
- **OpenAPI 3.0 Specification:** [`backend/docs/openapi.json`](file:///c:/Users/Vishwa/Desktop/Ceylon%20Cues/backend/docs/openapi.json)

---

## 12. Authentication & Permissions (RBAC)

The system uses a token-based authentication mechanism. Login via `POST /api/v1/auth/login` returns a 64-character bearer token.

Pass this token in all subsequent requests:
```http
Authorization: Bearer <token>
```

### Supported Roles
- `owner`: Sole Super Administrator.
- `shop_manager`: Manage shop operations, POS, inventory, staff, and customer orders.
- `shop_cashier`: Register sales, open/close POS register sessions.
- `warehouse_manager`: Manage warehouse stock, receive purchase orders (GRN), and process transfers.
- `inventory_staff`: Record manual adjustments and stock counts.
- `order_staff`: Update order status, packaging, and courier tracking details.
- `finance_staff`: Manage expenses, verify bank transfers, view profit & loss, and audit finances.
- `customer_support`: Reply to tickets, view customer inquiries, handle return requests.
- `customer`: Storefront customer account.

---

## 13. Inventory Architecture & Stock Isolation

The inventory system manages physical stock across distinct locations:

1. **Main Warehouse (`type: warehouse`)**: Primary physical repository where goods receipts (GRNs) are accepted from suppliers.
2. **Physical Retail Shop (`type: shop`)**: Physical store location where walk-in POS sales occur.
3. **Website Stock Allocation (`online_stock_allocations`)**: Virtual allocation explicitly designated for online sales.

### Oversell Protection & Allocation Rule
- If the Main Warehouse holds 10 physical units, the owner can allocate only 3 units to the website.
- The storefront API (`GET /products/{id}`) reports a maximum availability of 3 units.
- Online orders immediately reserve stock in the warehouse and decrement the online allocation inside an atomic database transaction (`SELECT ... FOR UPDATE`).
- POS sales deduct directly from the shop's stock, leaving warehouse and website allocations unaffected.
- Order cancellations automatically release reserved warehouse stock and restore online allocations.

---

## 14. Payment Architecture

The backend supports multiple payment methods with strict verification rules:

| Payment Method | Implementation Status | Verification Policy |
|---|---|---|
| **Cash** | Fully Functional | Immediate confirmation at POS terminal. Calculated against change given. |
| **Card (POS)** | Fully Functional | Recorded at POS register with physical terminal reference code. |
| **Bank Transfer** | Fully Functional | Customer submits deposit slip reference & metadata. Stored as `pending`. Requires manual review and verification via `POST /api/v1/payments/bank-transfers/{id}/verify`. |
| **Cash on Delivery (COD)** | Fully Functional | Handled via order lifecycle upon delivery confirmation. |
| **KOKO (BNPL)** | Ready for Credentials | Adapter configured (`KokoAdapter.php`). External credentials required for production live gateway webhook. |
| **Mintpay (BNPL)** | Ready for Credentials | Adapter configured (`MintpayAdapter.php`). External credentials required for production live gateway webhook. |
| **Visa / Mastercard Online** | Ready for Credentials | Adapter configured (`VisaMastercardAdapter.php`). External credentials required for production live gateway webhook. |

> **Security Rule:** The backend never simulates successful real payment gateway transactions. Payments are only confirmed through verified callbacks or authorized administrative review.

---

## 15. Testing Instructions

The backend includes a comprehensive automated test runner verifying all 29 master business scenarios directly against the MySQL database.

Execute the test suite:

```powershell
C:\xampp\php\php.exe backend\tests\run-tests.php
```

### Verified Test Scenarios
1. `Scenario 1`: Create product through ERP.
2. `Scenario 2`: Add actual stock to warehouse.
3. `Scenario 3`: Transfer stock to physical shop.
4. `Scenario 4`: Configure website stock allocation.
5. `Scenario 5`: Place online order.
6. `Scenario 6`: Confirm correct stock reservation & online allocation decrement.
7. `Scenario 7`: Complete shop POS sale.
8. `Scenario 8`: Verify correct shop quantity deduction.
9. `Scenario 9`: Verify warehouse stock invariance.
10. `Scenario 10`: Prevent inventory overselling under concurrency.
11. `Scenario 11`: Submit bank transfer deposit slip.
12. `Scenario 12`: Manually verify bank transfer payment.
13. `Scenario 13`: Calculate district delivery charges & free shipping thresholds.
14. `Scenario 14`: Apply percentage & fixed discount coupons.
15. `Scenario 15`: Cancel order and release reserved stock.
16. `Scenario 16`: Process return and financial refund.
17. `Scenario 17`: Create customer support ticket & reply thread.
18. `Scenario 18`: Queue WhatsApp business event notification.
19. `Scenario 19`: Prevent notification duplicates & run worker dispatch.
20. `Scenario 20`: Enforce Role-Based Access Control (RBAC).
21. `Scenario 21`: Generate full database backup SQL file.
22. `Scenario 22`: Security audit against SQLi, Argon2id, and IDOR attacks.
23. `Scenario 23`: Supplier profile management & purchase history.
24. `Scenario 24`: Purchase order creation & Goods Receipt (GRN) into warehouse.
25. `Scenario 25`: Customer management, address book & lifetime spend metrics.
26. `Scenario 26`: Return request lifecycle, approval & inventory restock.
27. `Scenario 27`: Physical stock count & variance reconciliation.
28. `Scenario 28`: Reporting & financial intelligence suite (Sales, Products, Inventory, P&L).
29. `Scenario 29`: POS session audit & daily sales summary calculations.

**Result: 29/29 PASSED (100% Success Rate).**

---

## 16. Background Worker & Notification Setup

The notification system uses an asynchronous queue table (`notification_queue`) to ensure zero latency during user requests.

To run the background worker daemon:

```powershell
C:\xampp\php\php.exe backend\bin\notification-worker.php
```

### Production Setup
In a production Linux/Windows environment:
- Set up a cron job or Windows Scheduled Task to run the worker every 1 to 5 minutes:
  ```bash
  * * * * * /usr/bin/php /var/www/ceylon-cues/backend/bin/notification-worker.php --limit=50 >> /var/log/ceylon-cues-worker.log 2>&1
  ```
- Or run it under a process manager like **Supervisor** or **systemd** as a permanent daemon.

---

## 17. Production Deployment Considerations

1. **Environment Security**:
   - Set `APP_ENV=production` and `APP_DEBUG=false` in `backend/.env`.
   - Ensure `backend/.env` is never exposed or committed to source control.
2. **Web Server Root**:
   - Point your web server document root directly to `backend/public/`. The parent directories must not be publicly accessible via HTTP.
3. **HTTPS / TLS**:
   - Enforce HTTPS and HSTS headers on production domains.
4. **CORS Configuration**:
   - Update `CorsMiddleware.php` to restrict allowed origins to your production storefront and ERP domains instead of `*`.
5. **Storage Permissions**:
   - Ensure `backend/storage/` and `backend/public/uploads/` are writable by the web server user (`www-data` or `apache`).
6. **Payment Credentials**:
   - Add production merchant credentials in `backend/.env` for KOKO, Mintpay, and your online card gateway.

---

## Backend Completion Summary

| Module | Status | Notes |
|---|---|---|
| Authentication & RBAC | Complete | Multi-role permissions, Argon2id, sessions, brute-force limits |
| Products & Categories | Complete | Quality tiers, categories, SKU, image uploads, soft deletes |
| Inventory & Locations | Complete | Warehouse, Shop, online allocation, transfers, adjustments |
| Stock Counts & Reconciliation | Complete | Physical count sheet, variance detection, automatic ledger updates |
| Suppliers & Purchasing | Complete | Supplier profiles, purchase orders, Goods Receipt (GRN) |
| Customer Management | Complete | Address book, profile, metrics, lifetime spend calculation |
| Orders & Fulfillment | Complete | Order lifecycle, reserved stock tracking, courier fulfillment |
| Retail POS Terminal | Complete | Shift sessions, receipt generation, cash/card tracking, daily summaries |
| Payments & Bank Transfers | Complete | Deposit slip submission, manual verification, refund records |
| Returns & Refunds | Complete | Request submission, approval, rejected reasons, stock restoration |
| Delivery & Zones | Complete | District lookup, zone rates, free delivery thresholds |
| Marketing & Coupons | Complete | Percentage and fixed discounts, expiration dates, usage limits |
| Support Tickets | Complete | Multi-message ticket thread, category routing |
| Notifications & WhatsApp | Complete | Database queue, retry logic, background CLI worker |
| Financial Reports & Analytics | Complete | P&L, sales reports, product sales, inventory valuation, POS summaries |
| System Settings & Backups | Complete | Dynamic business configuration, full automated SQL database dumps |
