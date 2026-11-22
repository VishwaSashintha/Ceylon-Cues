# Ceylon Cues — REST API Reference

Complete API specification for the **Ceylon Cues** backend platform.

- **Base URL (Local XAMPP):** `http://localhost/ceylon-cues/backend/public/api/v1`
- **Operating Currency:** `LKR` (Sri Lankan Rupee)
- **Timezone:** `Asia/Colombo` (UTC+5:30)
- **Content-Type:** `application/json`

---

## Response Structure

All responses follow a consistent JSON envelope:

### Success Response
```json
{
  "success": true,
  "data": {},
  "message": "Operation completed successfully",
  "timestamp": "2026-11-23 02:50:00",
  "execution_time_ms": 2.45
}
```

### Paginated Response
```json
{
  "success": true,
  "data": [],
  "pagination": {
    "total": 100,
    "per_page": 20,
    "current_page": 1,
    "last_page": 5,
    "from": 1,
    "to": 20
  },
  "message": "Items retrieved",
  "timestamp": "2026-11-23 02:50:00",
  "execution_time_ms": 3.12
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error description message",
  "code": 400,
  "timestamp": "2026-11-23 02:50:00"
}
```

---

## Authentication & Authorization

Protected endpoints require an `Authorization` header containing the session token:

```http
Authorization: Bearer <token>
```
*(or via custom header: `X-Session-Token: <token>`)*

### Roles & Access Levels
| Role | Capabilities |
|---|---|
| `owner` | Sole super administrator. Full unrestricted access to all modules, settings, backups, finances, and employee management. |
| `shop_manager` | Access to POS, shop inventory, orders, customer service, stock transfers, and daily shop summaries. |
| `shop_cashier` | Access to open/close POS register sessions and process walk-in customer sales. |
| `warehouse_manager` | Access to central warehouse stock, purchase orders, goods receipt (GRN), and physical transfers. |
| `inventory_staff` | Access to record stock adjustments and physical stock counts. |
| `finance_staff` | Access to payment verification, expenses, profit & loss, and financial analytics. |
| `order_staff` | Access to process, pack, and update online order status and courier tracking numbers. |
| `customer_support` | Access to support tickets, customer inquiries, and return requests. |
| `customer` | Access to own profile, address book, personal order history, and submit return requests. |

---

## 1. Authentication & Identity

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `POST` | `/auth/login` | None | Public | Log in with email and password. Returns user object and 64-character token. |
| `POST` | `/auth/register` | None | Public | Register customer account. |
| `POST` | `/auth/forgot-password` | None | Public | Request password reset token. |
| `POST` | `/auth/reset-password` | None | Public | Reset password using valid token. |
| `POST` | `/auth/csrf-token` | None | Public | Obtain CSRF token. |
| `GET` | `/auth/me` | Bearer | Any | Retrieve authenticated profile. |
| `PUT` | `/auth/profile` | Bearer | Any | Update profile name, phone. |
| `POST` | `/auth/change-password` | Bearer | Any | Change password with old/new validation. |
| `POST` | `/auth/logout` | Bearer | Any | Invalidate current session token. |

---

## 2. Products & Catalog

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/products` | None | Public | Browse products. Filters: `category_id`, `brand`, `quality_tier` (budget, standard, premium), `search`, `min_price`, `max_price`, `sort`, `page`, `per_page`. |
| `GET` | `/products/{id}` | None | Public | Retrieve product details by ID or slug with variants, images, and allocated online stock. |
| `POST` | `/products` | Bearer | `owner`, `shop_manager`, `warehouse_manager` | Create product. Fields: `name`, `sku`, `category_id`, `brand`, `cost_price`, `retail_price`, `wholesale_price`, `quality_tier`, `description`. |
| `PUT` | `/products/{id}` | Bearer | `owner`, `shop_manager`, `warehouse_manager` | Update product information and prices. |
| `DELETE` | `/products/{id}` | Bearer | `owner` | Safe soft-delete/archive product. |
| `POST` | `/products/{id}/images` | Bearer | `owner`, `shop_manager` | Upload product image (`multipart/form-data`). |
| `DELETE` | `/products/images/{id}` | Bearer | `owner`, `shop_manager` | Delete product image and remove file from disk. |
| `GET` | `/categories` | None | Public | List categories with parent-child hierarchy. |
| `GET` | `/categories/{id}` | None | Public | Get single category. |
| `POST` | `/categories` | Bearer | `owner`, `shop_manager` | Create category. |
| `PUT` | `/categories/{id}` | Bearer | `owner`, `shop_manager` | Update category. |
| `DELETE` | `/categories/{id}` | Bearer | `owner` | Delete category. |

---

## 3. Shopping Cart & Checkout

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/cart` | Optional | Guest/Customer | Retrieve active cart with item calculations. |
| `POST` | `/cart/items` | Optional | Guest/Customer | Add item to cart. Validates available online stock. |
| `PUT` | `/cart/items/{id}` | Optional | Guest/Customer | Update cart item quantity. |
| `DELETE` | `/cart/items/{id}` | Optional | Guest/Customer | Remove item from cart. |
| `DELETE` | `/cart` | Optional | Guest/Customer | Clear all items from cart. |
| `POST` | `/checkout/calculate` | Optional | Guest/Customer | Calculate subtotal, delivery charges, discounts, and total. |
| `POST` | `/checkout/place-order` | Optional | Guest/Customer | Place online order. Reserves warehouse stock and deducts online allocation. |
| `POST` | `/payments/bank-transfer/submit` | Optional | Guest/Customer | Submit bank deposit slip metadata and transaction reference. |

---

## 4. Retail POS Terminal

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/pos/sessions` | Bearer | `owner`, `shop_manager`, `shop_cashier` | List POS register sessions with cashier name, location, and open/close status. |
| `GET` | `/pos/sessions/{id}` | Bearer | `owner`, `shop_manager`, `shop_cashier` | Get details and transaction total for a POS session. |
| `POST` | `/pos/sessions/open` | Bearer | `owner`, `shop_manager`, `shop_cashier` | Open a cashier shift. Body: `location_id`, `opening_balance`, `notes`. |
| `POST` | `/pos/sessions/{id}/close` | Bearer | `owner`, `shop_manager`, `shop_cashier` | Close cashier shift. Calculates expected cash balance vs actual closing balance. |
| `POST` | `/pos/sales` | Bearer | `owner`, `shop_manager`, `shop_cashier` | Process POS sale. Immediately deducts stock from physical shop location. Body: `session_id`, `items`, `payment_method` (cash, card, bank_transfer), `cash_received`, `discount_amount`. |
| `GET` | `/pos/transactions` | Bearer | `owner`, `shop_manager`, `shop_cashier` | List POS transaction receipts with pagination and search. |
| `GET` | `/pos/transactions/{id}` | Bearer | `owner`, `shop_manager`, `shop_cashier` | Get detailed POS receipt with itemized line items. |
| `GET` | `/pos/daily-summary` | Bearer | `owner`, `shop_manager` | Daily sales summary grouped by payment method (cash, card, transfer). |

---

## 5. Central Inventory & Warehouse Management

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/inventory/locations` | Bearer | Staff | List physical locations (`Main Warehouse`, `Retail Shop`). |
| `GET` | `/inventory/stock` | Bearer | Staff | Query stock balances per location. Param: `location_id`, `low_stock_only`, `search`. |
| `POST` | `/inventory/adjustments` | Bearer | `owner`, `warehouse_manager`, `inventory_staff` | Manual stock adjustment. Body: `product_id`, `location_id`, `quantity_change`, `reason`, `notes`. Logs movement. |
| `GET` | `/inventory/transfers` | Bearer | Staff | List inter-location stock transfers. |
| `POST` | `/inventory/transfers` | Bearer | `owner`, `warehouse_manager`, `shop_manager` | Create transfer from Warehouse to Shop. Reserves stock at origin. |
| `POST` | `/inventory/transfers/{id}/complete` | Bearer | `owner`, `warehouse_manager`, `shop_manager` | Complete transfer upon physical receipt. Deducts origin and adds to destination. |
| `GET` | `/inventory/allocations/{productId}` | Bearer | Staff | View allocated website quantity for a product. |
| `PUT` | `/inventory/allocations/{productId}` | Bearer | `owner`, `warehouse_manager` | Set website stock allocation. Protects warehouse reserves. |
| `GET` | `/inventory/movements` | Bearer | Staff | Audit ledger of all stock movements. Filter: `product_id`, `location_id`, `movement_type`. |
| `GET` | `/inventory/counts` | Bearer | `owner`, `warehouse_manager`, `inventory_staff` | List physical stock counts. |
| `POST` | `/inventory/counts` | Bearer | `owner`, `warehouse_manager`, `inventory_staff` | Start physical stock count for a location. |
| `GET` | `/inventory/counts/{id}` | Bearer | `owner`, `warehouse_manager`, `inventory_staff` | View count sheet with recorded system vs physical variances. |
| `POST` | `/inventory/counts/{id}/items` | Bearer | `owner`, `warehouse_manager`, `inventory_staff` | Record physical counted quantity for a product. |
| `POST` | `/inventory/counts/{id}/reconcile` | Bearer | `owner`, `warehouse_manager` | Reconcile count: automatically adjusts inventory balances and logs count corrections. |

---

## 6. Suppliers & Purchasing Management

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/suppliers` | Bearer | `owner`, `shop_manager`, `warehouse_manager` | List suppliers with search and pagination. |
| `POST` | `/suppliers` | Bearer | `owner`, `warehouse_manager` | Register new supplier. Fields: `company_name`, `contact_person`, `email`, `phone`, `address`, `city`, `country`. |
| `GET` | `/suppliers/{id}` | Bearer | `owner`, `shop_manager`, `warehouse_manager` | Get supplier details. |
| `PUT` | `/suppliers/{id}` | Bearer | `owner`, `warehouse_manager` | Update supplier details. |
| `DELETE` | `/suppliers/{id}` | Bearer | `owner` | Deactivate/remove supplier. |
| `GET` | `/suppliers/{id}/history` | Bearer | `owner`, `warehouse_manager` | View past purchase orders placed with supplier. |
| `GET` | `/purchasing/orders` | Bearer | `owner`, `warehouse_manager` | List purchase orders with status filter (`draft`, `submitted`, `confirmed`, `partial_received`, `received`, `cancelled`). |
| `POST` | `/purchasing/orders` | Bearer | `owner`, `warehouse_manager` | Create purchase order. Body: `supplier_id`, `expected_date`, `items` (`product_id`, `quantity`, `unit_cost`). |
| `GET` | `/purchasing/orders/{id}` | Bearer | `owner`, `warehouse_manager` | View purchase order with itemized list and receipts. |
| `PUT` | `/purchasing/orders/{id}/status` | Bearer | `owner`, `warehouse_manager` | Update PO status (e.g., submit, confirm, cancel). |
| `POST` | `/purchasing/orders/{id}/receive` | Bearer | `owner`, `warehouse_manager` | Receive goods into Warehouse (GRN). Increases physical warehouse stock, logs stock movements, updates PO received status. |

---

## 7. Customer Management (ERP)

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/customers` | Bearer | `owner`, `shop_manager`, `customer_support` | List registered customers with total orders and lifetime spend metrics. |
| `GET` | `/customers/{id}` | Bearer | `owner`, `shop_manager`, `customer_support` | Get customer profile, saved delivery addresses, order history, and notes. |
| `PUT` | `/customers/{id}` | Bearer | `owner`, `shop_manager` | Update customer status (active/inactive), phone, or notes. |
| `GET` | `/customers/{id}/addresses` | Bearer | `owner`, `customer_support` | Get saved delivery addresses for a customer. |
| `POST` | `/customers/{id}/addresses` | Bearer | `owner`, `customer_support` | Add new address to customer address book. |

---

## 8. Returns & Refunds Management

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `POST` | `/returns` | Bearer | Customer / Staff | Submit return request against an order. Body: `order_id`, `items` (`order_item_id`, `quantity`, `condition_notes`), `reason`. |
| `GET` | `/returns` | Bearer | `owner`, `shop_manager`, `customer_support` | List return requests. Filter by status (`requested`, `approved`, `received`, `rejected`). |
| `GET` | `/returns/{id}` | Bearer | `owner`, `shop_manager`, `customer_support` | View return request with items and reason. |
| `POST` | `/returns/{id}/approve` | Bearer | `owner`, `shop_manager` | Approve return request. |
| `POST` | `/returns/{id}/reject` | Bearer | `owner`, `shop_manager` | Reject return request with `rejection_reason`. |
| `POST` | `/returns/{id}/receive` | Bearer | `owner`, `warehouse_manager` | Receive returned items. Only approved returns can be restocked. Restores inventory stock and logs `return` movement. |

---

## 9. Orders & Delivery Management

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/orders` | Bearer | Customer (own) / Staff (all) | List orders. Staff can filter by `channel`, `status`, `payment_status`. |
| `GET` | `/orders/{id}` | Bearer | Customer (own) / Staff (all) | Get order details with items, addresses, payment status, tracking number. |
| `PUT` | `/orders/{id}/status` | Bearer | `owner`, `order_staff` | Update order workflow status (`pending`, `confirmed`, `processing`, `dispatched`, `delivered`, `cancelled`). Cancelling automatically releases reserved stock. |
| `PUT` | `/orders/{id}/fulfillment` | Bearer | `owner`, `order_staff` | Update courier dispatch tracking: `carrier_name`, `tracking_number`. |
| `GET` | `/delivery/zones` | None | Public | List active delivery zones, districts, rates, and free delivery thresholds. |
| `POST` | `/delivery/zones` | Bearer | `owner` | Create delivery zone. |
| `PUT` | `/delivery/zones/{id}` | Bearer | `owner` | Update delivery zone rate, threshold, estimated days. |
| `DELETE` | `/delivery/zones/{id}` | Bearer | `owner` | Deactivate delivery zone. |

---

## 10. Financial Reports & Analytics

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/reports/executive-metrics` | Bearer | `owner`, `finance_staff` | High-level metrics: revenue by channel, inventory valuation (cost vs retail), low-stock count, pending transfers. |
| `GET` | `/reports/profit-and-loss` | Bearer | `owner`, `finance_staff` | P&L statement: Gross revenue, COGS, gross margin, operating expenses breakdown, net profit. |
| `GET` | `/reports/sales` | Bearer | `owner`, `finance_staff` | Sales report with daily breakdown, channel breakdown, discounts, average order value. |
| `GET` | `/reports/products` | Bearer | `owner`, `finance_staff` | Top-selling products by quantity and revenue. |
| `GET` | `/reports/inventory` | Bearer | `owner`, `finance_staff` | Inventory valuation per location, low-stock items, out-of-stock items. |
| `GET` | `/reports/purchases` | Bearer | `owner`, `finance_staff` | Supplier spending and purchase order totals. |
| `GET` | `/reports/customers` | Bearer | `owner`, `finance_staff` | Customer account metrics and top spenders. |
| `GET` | `/reports/pos` | Bearer | `owner`, `finance_staff` | Cashier shift sales and POS transaction summaries. |

---

## 11. Notifications & Worker

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `POST` | `/notifications/process-queue` | Bearer | `owner` | Manually trigger processing of pending notification queue items. |
| `POST` | `/notifications/test` | Bearer | `owner` | Dispatch test WhatsApp/email alert to verify gateway settings. |

### Background CLI Worker
Run the CLI notification worker for automated queue dispatch:
```powershell
php backend/bin/notification-worker.php
```

---

## 12. Owner Administration

| Method | Endpoint | Auth | Role | Description |
|---|---|---|---|---|
| `GET` | `/employees` | Bearer | `owner` | List employee accounts. |
| `POST` | `/employees` | Bearer | `owner` | Create employee account with assigned role. |
| `GET` | `/employees/{id}` | Bearer | `owner` | View employee details. |
| `PUT` | `/employees/{id}` | Bearer | `owner` | Update employee details or deactivate account. |
| `DELETE` | `/employees/{id}` | Bearer | `owner` | Deactivate/remove employee account. |
| `PUT` | `/employees/permissions` | Bearer | `owner` | Update granular role permission matrix. |
| `GET` | `/settings` | Bearer | `owner` | View all business, currency, tax, notification settings. |
| `PUT` | `/settings` | Bearer | `owner` | Update business settings. |
| `POST` | `/settings/backup` | Bearer | `owner` | Generate full MySQL database backup SQL file stored in `backend/storage/backups/`. |
