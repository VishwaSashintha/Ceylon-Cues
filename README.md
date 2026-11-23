# Ceylon Cues

**Precision. Performance. Passion.**

Ceylon Cues is a Sri Lankan billiards and pool equipment retail and wholesale business. This repository contains the complete application stack: a customer-facing online store, an owner-operated business management interface (ERP), and a physical retail point-of-sale terminal (POS).

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Technology Stack](#technology-stack)
3. [System Architecture](#system-architecture)
4. [Frontend Applications](#frontend-applications)
5. [Business Model](#business-model)
6. [Customer Order Verification](#customer-order-verification)
7. [POS Activation](#pos-activation)
8. [XAMPP Local Setup](#xampp-local-setup)
9. [Local URLs](#local-urls)
10. [Database Setup](#database-setup)
11. [Owner Account Setup](#owner-account-setup)
12. [Production Deployment Architecture](#production-deployment-architecture)
13. [External Services](#external-services)
14. [Security Architecture](#security-architecture)
15. [API Reference](#api-reference)
16. [Testing](#testing)
17. [License and Copyright](#license-and-copyright)

---

## Project Overview

Ceylon Cues is a one-owner retail and wholesale business. The owner manages all operations personally: products, inventory, pricing, orders, customers, suppliers, purchases, expenses, and reports.

The system provides:

- A customer-facing online store for retail and wholesale order placement.
- An owner-only ERP for complete business management.
- An optional POS terminal for physical retail shop operations, disabled until the shop opens.

This is not a multi-user SaaS platform. There is one owner account with full administrative access.

---

## Technology Stack

| Component  | Technology                                    |
|------------|-----------------------------------------------|
| Backend    | PHP 8.2, PSR-4 autoloading, PDO, custom Router |
| Database   | MySQL 8 via XAMPP (port 3307)                 |
| Frontend   | Vanilla HTML5, CSS3, ES6+ (no build tools)    |
| Local host | Apache via XAMPP                              |
| Currency   | LKR (Sri Lankan Rupee)                        |

---

## System Architecture

```
Ceylon-Cues/
|
+-- backend/              PHP 8.2 REST API (PSR-4, PDO, custom Router)
|   +-- public/           Apache document root (index.php entry point)
|   +-- routes/           api.php route definitions
|   +-- src/
|   |   +-- Controllers/  API controllers (Auth, Checkout, POS, ERP, ...)
|   |   +-- Services/     Business logic (Auth, OTP, Orders, POS, ...)
|   |   +-- Middleware/   Auth, CORS, Security headers, Role checks
|   |   +-- Core/         Router, Database, Request, Response, Validator
|   +-- database/
|   |   +-- migrations/   Numbered migration files (001 to 102)
|   |   +-- seeds/        Sample product and settings data
|   +-- storage/
|   |   +-- logs/         OTP log (dev mode), error logs
|   |   +-- uploads/      Payment proof files
|   +-- tests/            PHPUnit tests and E2E integration test
|
+-- frontend/
|   +-- website/          Customer-facing online store (HTML/CSS/JS)
|   +-- erp/              Owner business management interface (SPA)
|   +-- pos/              Physical retail POS terminal (HTML/CSS/JS)
|   +-- shared/           Shared utilities and common styles
|
+-- README.md
+-- .gitignore
```

**Data flow:**

```
Browser (website / erp / pos)
       |
       | HTTP REST (JSON)
       v
Apache  -->  backend/public/index.php
                    |
              Router + Middleware
                    |
            Controller --> Service --> PDO --> MySQL
```

---

## Frontend Applications

### Website (Customer Store)

**Local URL:** http://localhost/ceylon-cues/frontend/website/

The customer-facing online store. Customers can browse products, search and filter, add items to cart, register an account, place orders, track orders, and submit wholesale enquiries.

Features:
- Product catalogue with categories, search, and quality classification (Budget, Standard, Premium)
- Shopping cart and checkout with district-based delivery fee calculation
- Customer registration and login
- OTP-verified order placement for authenticated customers
- Bank transfer payment with slip/reference submission
- Cash on delivery option
- Order history and tracking
- Wholesale enquiry form
- About, contact, and policy pages

### ERP (Owner Interface)

**Local URL:** http://localhost/ceylon-cues/frontend/erp/

The owner-only business management interface. Protected by owner credentials. Provides complete visibility and control over all business operations.

Features:
- Dashboard with key metrics and revenue summary
- Product and category management (add, edit, delete, image upload)
- Inventory management (stock levels, adjustments, transfers, counts)
- Order management (view, update status, fulfillment, tracking assignment)
- Customer management and address records
- Supplier management and purchase orders
- Bank transfer verification
- Discount and coupon management
- Financial reports (profit/loss, sales, inventory, purchases)
- Expense tracking
- Notification queue management
- Business settings including POS activation control
- CMS pages and banners

### POS (Point-of-Sale Terminal)

**Local URL:** http://localhost/ceylon-cues/frontend/pos/

The physical retail shop POS application. Inactive by default until the owner enables it from ERP Business Settings.

Features (when active):
- PIN-based terminal login (no password required at counter)
- Shift open and close with opening/closing balance
- In-store product search and sale processing
- Cash, card, and bank transfer payment methods
- Change calculation
- Receipt printing
- Sale voiding with manager override PIN
- Daily shift summary

---

## Business Model

Ceylon Cues is operated by a single owner. There are no employees, cashiers, managers, or other staff accounts. The owner personally manages every aspect of the business.

**Owner responsibilities:**
- All ERP functions
- All order processing and fulfillment
- All inventory and stock management
- All financial operations
- All customer communications

**Separation of access:**
- The ERP is exclusively for the owner. It is protected by the owner login credentials.
- The website is for customers. Customer accounts are entirely separate from owner authentication.
- The POS uses a PIN-based login appropriate for a physical counter environment.

Do not add employee accounts, staff roles, or multi-user RBAC unless the business grows and the owner explicitly requests them.

---

## Customer Order Verification

Authenticated customers are required to verify their identity with a one-time password (OTP) when placing bank transfer orders.

**OTP flow:**

1. Customer adds items to cart and proceeds to checkout.
2. Customer selects bank transfer as payment method.
3. Customer clicks "Request Verification Code."
4. The backend issues a 6-digit OTP via email (or in development, writes it to `backend/storage/logs/otp.log` and returns it in the API response as `dev_otp`).
5. Customer enters the OTP in the checkout form.
6. The backend verifies the OTP and places the order.

OTP properties:
- 6 digits, valid for 10 minutes
- Maximum 5 verification attempts
- Bound to the specific order payload (items, delivery method, payment method)
- Cannot be reused after a successful order

In development (XAMPP, `APP_ENV=local`): the OTP is returned in the API response as `dev_otp` for testing purposes. Remove or disable this in production by setting `APP_ENV=production` in `.env`.

---

## POS Activation

The POS terminal is disabled by default because the physical retail shop may not be open.

**To activate the POS:**

1. Log in to the ERP at http://localhost/ceylon-cues/frontend/erp/
2. Navigate to Settings.
3. Under "Physical Shop and POS Counter", set "POS Terminal Status" to "Active / In Operation."
4. Save settings.

**To deactivate the POS:**

Reverse the same setting. The POS terminal will display an inactive notice until reactivated.

When inactive, all POS operational endpoints return HTTP 503 to prevent accidental use.

---

## XAMPP Local Setup

### Requirements

- XAMPP with Apache and MySQL
- PHP 8.2 or higher
- MySQL configured on **port 3307** (not the default 3306)
- Composer (for backend dependencies)

### Step 1: Configure MySQL Port

Ensure XAMPP MySQL is configured to run on port 3307. Edit `C:\xampp\mysql\bin\my.ini` and set:

```
[mysqld]
port = 3307
```

### Step 2: Clone the Repository

```
git clone https://github.com/VishwaSashintha/Ceylon-Cues.git "C:\xampp\htdocs\ceylon-cues"
```

### Step 3: Configure the Backend Environment

Copy the sample environment file and edit it:

```
cd "C:\xampp\htdocs\ceylon-cues\backend"
copy .env.example .env
```

Edit `backend/.env`:

```
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/ceylon-cues

DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=ceylon_cues
DB_USERNAME=root
DB_PASSWORD=

SESSION_LIFETIME=120
RATE_LIMIT_LOGIN_MAX=5
RATE_LIMIT_LOGIN_WINDOW=900
```

### Step 4: Install PHP Dependencies

```
cd "C:\xampp\htdocs\ceylon-cues\backend"
composer install
```

### Step 5: Start XAMPP Services

Start Apache and MySQL from the XAMPP Control Panel. Ensure MySQL runs on port 3307.

---

## Database Setup

### Create the Database

Connect to MySQL and create the database:

```sql
CREATE DATABASE ceylon_cues CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Run Migrations

```
cd "C:\xampp\htdocs\ceylon-cues"
C:\xampp\php\php.exe backend\database\migrate.php
```

### Run Seeds (Sample Data)

```
C:\xampp\php\php.exe backend\database\seed.php
```

This creates sample products, categories, delivery zones, and default business settings including the owner account.

---

## Owner Account Setup

The owner account is created automatically by the database seed.

**Default owner credentials:**

```
Email:    owner@ceyloncues.lk
Password: Password123!
POS PIN:  1234
```

Change these credentials immediately after initial setup.

**To change the owner password:**

Log in to the ERP and use the Account Settings page, or use the forgot-password flow.

**Security note:** The owner account has `is_owner = 1` and `role = owner` in the database. It has unrestricted access to all ERP and API functions.

---

## Production Deployment Architecture

The recommended production deployment uses separate subdomains for each application.

```
ceyloncues.lk          -->  Website (customer store)
erp.ceyloncues.lk      -->  ERP (owner interface)
pos.ceyloncues.lk      -->  POS terminal
api.ceyloncues.lk      -->  Backend REST API
```

**Recommended server stack:**

- Ubuntu 22.04 LTS
- Nginx with PHP-FPM (PHP 8.2)
- MySQL 8.0
- Let's Encrypt TLS (HTTPS required for all applications)

**Production checklist:**

- Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- Configure a real SMTP provider for OTP email delivery
- Remove `dev_otp` from API responses (handled automatically when `APP_ENV=production`)
- Set strong database credentials (not empty root password)
- Restrict `backend/storage/` from direct web access
- Configure firewall to block direct MySQL access from outside
- Set up automated daily database backups
- Configure HTTPS on all subdomains before going live

---

## External Services

The following external services are referenced but require configuration:

| Service            | Purpose                               | Configuration          |
|--------------------|---------------------------------------|------------------------|
| SMTP Provider      | OTP email delivery to customers       | `MAILER_DSN` in `.env` |
| WhatsApp API       | Order notifications to owner          | `WHATSAPP_*` in `.env` |
| Payment Gateways   | Future online card payment processing | To be integrated       |

In development (XAMPP), all these services degrade gracefully:
- OTP emails are written to `backend/storage/logs/otp.log`
- WhatsApp notifications are queued but not sent
- Bank transfer is the only active payment method

---

## Security Architecture

- All passwords hashed with `PASSWORD_ARGON2ID` (bcrypt fallback for OTP codes)
- Session tokens: 64-character random hex, stored as SHA-256 hash in the database
- Bearer token authentication via `Authorization: Bearer <token>` header
- Role-based access middleware (`AuthMiddleware`, `RoleMiddleware`)
- CORS headers restricted to configured allowed origins
- Security response headers on all API responses (`X-Content-Type-Options`, `X-Frame-Options`, `Referral-Policy`)
- OTP codes bound to specific order payloads via SHA-256 hash comparison
- Server-side price recalculation on all orders (client-submitted prices are ignored)
- Login rate limiting (5 attempts per 15-minute window)
- Direct access to `backend/.env` and `backend/src/` blocked by `.htaccess` (returns HTTP 403)
- POS endpoints return HTTP 503 when POS is not activated
- Cross-customer IDOR prevention: customers can only access their own orders

---

## API Reference

**Base URL (local):** `http://localhost/ceylon-cues/api/v1`

**Authentication:** `Authorization: Bearer <token>`

**Key public endpoints:**

| Method | Path                              | Description                          |
|--------|-----------------------------------|--------------------------------------|
| GET    | /health                           | API health check                     |
| GET    | /settings/public                  | Public business settings             |
| GET    | /products                         | Product catalogue (paginated)        |
| GET    | /products/{id}                    | Single product details               |
| GET    | /categories                       | Product categories                   |
| POST   | /delivery/calculate               | Delivery fee for district            |
| POST   | /auth/register                    | Customer registration                |
| POST   | /auth/login                       | Login (customers and owner)          |
| POST   | /checkout/place-order             | Place a new order                    |
| POST   | /payments/bank-transfer/submit    | Submit bank transfer reference       |
| GET    | /orders/track                     | Public order tracking by reference   |
| GET    | /pos/status                       | POS terminal active status (public)  |
| POST   | /pos/verify-pin                   | POS terminal PIN login               |

**Key authenticated endpoints:**

| Method | Path                              | Access        | Description                     |
|--------|-----------------------------------|---------------|---------------------------------|
| POST   | /checkout/request-otp             | Customer      | Request order verification OTP  |
| GET    | /orders                           | Customer/Owner| Order list                      |
| GET    | /orders/{id}                      | Customer/Owner| Order details                   |
| PUT    | /orders/{id}/fulfillment          | Owner         | Assign courier tracking         |
| PUT    | /settings                         | Owner         | Update business settings        |
| GET    | /reports/executive-metrics        | Owner         | Business performance report     |
| POST   | /pos/sessions/open                | POS/Owner     | Open POS shift                  |
| POST   | /pos/sales                        | POS/Owner     | Process in-store sale           |

Full API documentation is available from the ERP interface under Settings.

---

## Testing

### PHPUnit Tests

```
cd "C:\xampp\htdocs\ceylon-cues"
C:\xampp\php\php.exe backend\vendor\bin\phpunit -c backend\phpunit.xml --no-coverage
```

Expected result: 26 tests, 59 assertions, all passing.

### End-to-End Integration Tests

```
C:\xampp\php\php.exe backend\tests\e2e_full_verification.php
```

Expected result: ALL 23 END-TO-END INTEGRATION TESTS PASSED.

The E2E test suite covers:
1. API health check
2. Public settings
3. Product catalogue browsing
4. Delivery fee calculation
5. Customer registration
6. Customer authentication
7. OTP issuance for order placement
8. Order placement with OTP verification
9. Bank transfer evidence submission
10. Owner super administrator login
11. Owner ERP order visibility
12. Owner bank transfer approval
13. Courier tracking number assignment
14. POS inactive status guard
15. POS 503 guard when inactive
16. POS activation via business settings
17. POS fast PIN terminal login
18. POS shift open
19. POS in-store sale
20. POS shift close
21. IDOR prevention (Customer B cannot access Customer A order)
22. IDOR prevention (Customer B cannot access Customer A tax invoice)
23. RBAC enforcement (Customer denied owner reports)

---

## License and Copyright

Copyright 2024 Ceylon Cues. All rights reserved.

This software is proprietary. Unauthorised copying, distribution, or modification is prohibited.
