# Ceylon Cues

A complete business management platform for a billiards and pool accessories retailer in Sri Lanka. The system combines a customer-facing e-commerce storefront, a central ERP dashboard and a physical retail POS terminal into one connected application.

---

## Repository Structure

```
Ceylon Cues/
├── backend/                    PHP 8.2 REST API and business logic
│   ├── bin/                    CLI workers (notification worker)
│   ├── config/                 Application and database configuration
│   ├── database/               Migrations, seed data, install scripts
│   │   └── migrations/         Numbered SQL migration files
│   ├── docs/                   Internal API and architecture notes
│   ├── public/                 Apache document root
│   │   ├── index.php           Front controller
│   │   ├── .htaccess           URL rewriting rules
│   │   └── uploads/            Product image storage
│   ├── routes/                 API route definitions
│   ├── src/                    Application source code
│   │   ├── Controllers/        HTTP request handlers
│   │   ├── Middleware/         Auth, CORS, rate-limiting
│   │   ├── Models/             Database models
│   │   ├── Services/           Business logic services
│   │   └── Helpers/            Utility classes
│   ├── storage/logs/           Runtime logs
│   ├── tests/                  Automated test suite
│   ├── composer.json
│   └── .env.example            Environment variable template
│
├── frontend/                   Vanilla HTML, CSS and JavaScript
│   ├── website/                Customer e-commerce storefront
│   │   ├── index.html
│   │   └── storefront.js
│   ├── erp/                    Owner ERP and management dashboard
│   │   ├── index.html
│   │   └── erp.js
│   ├── pos/                    Physical retail POS terminal
│   │   ├── index.html
│   │   └── pos.js
│   └── shared/                 Shared assets used by all frontends
│       ├── css/style.css
│       ├── js/api.js           API client with auto-detected base URL
│       └── img/                Brand logos
│
├── index.html                  Root redirect to the storefront
├── .gitignore
└── README.md
```

---

## Technology Stack

| Layer | Technology |
|---|---|
| Backend language | PHP 8.2 |
| Database | MySQL 8 |
| Web server | Apache (XAMPP) |
| Frontend | Vanilla HTML5, CSS3, JavaScript (ES2020) |
| Currency | LKR (Sri Lankan Rupee) |
| Timezone | Asia/Colombo |

---

## Local Development Setup

### Prerequisites

- XAMPP with Apache and MySQL running
- PHP 8.2 on PATH (`C:\xampp\php\php.exe`)
- Composer installed globally

### 1. Create XAMPP junction

Create a Windows directory junction so Apache can serve the project:

```powershell
mklink /J "C:\xampp\htdocs\ceylon-cues" "C:\Users\Vishwa\Desktop\Ceylon Cues"
```

### 2. Configure environment

```powershell
Copy-Item backend\.env.example backend\.env
```

Edit `backend\.env` and set your database credentials and other secrets:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ceylon_cues
DB_USER=root
DB_PASS=

APP_ENV=local
APP_KEY=your-random-secret-key
JWT_SECRET=your-jwt-secret

TIMEZONE=Asia/Colombo
CURRENCY=LKR
```

### 3. Install PHP dependencies

```powershell
cd backend
composer install
cd ..
```

### 4. Set up the database

Run these three scripts in order:

```powershell
# Create all tables
php backend/database/install.php

# Apply any pending migrations
php backend/database/migrate.php

# Seed initial data (categories, products, owner account, settings)
php backend/database/seed.php

# Bootstrap application state
php backend/database/bootstrap.php
```

---

## Accessing the Application

Start Apache and MySQL in XAMPP, then open:

| Interface | URL |
|---|---|
| Customer Storefront | `http://localhost/ceylon-cues/frontend/website/index.html` |
| Owner ERP Dashboard | `http://localhost/ceylon-cues/frontend/erp/index.html` |
| Retail POS Terminal | `http://localhost/ceylon-cues/frontend/pos/index.html` |
| REST API root | `http://localhost/ceylon-cues/backend/public/api/v1/` |

The root URL `http://localhost/ceylon-cues/` automatically redirects to the storefront.

### Default owner credentials

After running the seed script, log in to the ERP dashboard with:

```
Email:    owner@ceyloncues.com
Password: Ceylon@2024
```

Use the same credentials to open a POS session from the POS terminal.

---

## API

The REST API is served from `backend/public/` via the Apache front controller.

Base URL: `/backend/public/api/v1/`

The shared API client (`frontend/shared/js/api.js`) auto-detects the correct base URL from the browser path, so no configuration is needed in the frontend files.

All API responses use JSON. Authentication uses JWT tokens stored in `localStorage`.

---

## Running Tests

```powershell
php backend/tests/run-tests.php
```

Expected result: **22/22 tests passing**.

---

## Background Worker

The notification worker processes queued WhatsApp and email notifications:

```powershell
php backend/bin/notification-worker.php
```

Run this in a separate terminal or configure it as a scheduled task.

---

## Notes

- `backend/vendor/` is excluded from version control. Always run `composer install` after cloning.
- `backend/.env` is excluded from version control. Copy `.env.example` and configure it.
- Product images are stored in `backend/public/uploads/` and served directly by Apache.
- The frontend requires no build step — it is plain HTML, CSS and JavaScript.
