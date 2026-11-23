<?php

declare(strict_types=1);

namespace CeylonCues\Routes;

use CeylonCues\Controllers\Api\V1\Auth\AuthController;
use CeylonCues\Controllers\Api\V1\Auth\EmployeeController;
use CeylonCues\Controllers\Api\V1\Cart\CartController;
use CeylonCues\Controllers\Api\V1\Categories\CategoryController;
use CeylonCues\Controllers\Api\V1\Checkout\CheckoutController;
use CeylonCues\Controllers\Api\V1\Cms\BannerController;
use CeylonCues\Controllers\Api\V1\Customers\CustomerController;
use CeylonCues\Controllers\Api\V1\Delivery\DeliveryController;
use CeylonCues\Controllers\Api\V1\Erp\DashboardController;
use CeylonCues\Controllers\Api\V1\Erp\ExpenseController;
use CeylonCues\Controllers\Api\V1\Inventory\InventoryController;
use CeylonCues\Controllers\Api\V1\Marketing\MarketingController;
use CeylonCues\Controllers\Api\V1\Notifications\NotificationController;
use CeylonCues\Controllers\Api\V1\Orders\OrderController;
use CeylonCues\Controllers\Api\V1\Orders\ReturnController;
use CeylonCues\Controllers\Api\V1\Payments\CodController;
use CeylonCues\Controllers\Api\V1\Payments\PaymentController;
use CeylonCues\Controllers\Api\V1\Pos\PosController;
use CeylonCues\Controllers\Api\V1\Products\ProductController;
use CeylonCues\Controllers\Api\V1\Products\ReviewController;
use CeylonCues\Controllers\Api\V1\Products\WishlistController;
use CeylonCues\Controllers\Api\V1\Purchasing\PurchaseOrderController;
use CeylonCues\Controllers\Api\V1\Reports\ReportController;
use CeylonCues\Controllers\Api\V1\Settings\CmsController;
use CeylonCues\Controllers\Api\V1\Settings\SettingController;
use CeylonCues\Controllers\Api\V1\Suppliers\SupplierController;
use CeylonCues\Controllers\Api\V1\Support\SupportController;
use CeylonCues\Controllers\Api\V1\Wholesale\WholesaleController;
use CeylonCues\Middleware\AuthMiddleware;
use CeylonCues\Middleware\CorsMiddleware;
use CeylonCues\Middleware\JsonBodyMiddleware;
use CeylonCues\Middleware\RoleMiddleware;
use CeylonCues\Middleware\SecurityHeadersMiddleware;

/** @var \CeylonCues\Core\Router $router */

$router->group('/api/v1', function ($router) {

    // Public Authentication & Identity
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/2fa/verify', [AuthController::class, 'verify2faLogin']);
    $router->post('/auth/register', [AuthController::class, 'register']);
    $router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    $router->post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    $router->post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    $router->post('/auth/csrf-token', [AuthController::class, 'csrfToken']);

    // Public Storefront Catalog, Content & Settings
    $router->get('/health', [SettingController::class, 'health']);
    $router->get('/products', [ProductController::class, 'index']);
    $router->get('/products/{id}', [ProductController::class, 'show']);
    $router->get('/products/{id}/reviews', [ReviewController::class, 'listByProduct']);
    $router->post('/products/{id}/notify-stock', [WishlistController::class, 'notifyStock']);
    $router->get('/categories', [CategoryController::class, 'index']);
    $router->get('/categories/{id}', [CategoryController::class, 'show']);
    $router->get('/delivery/zones', [DeliveryController::class, 'zones']);
    $router->get('/delivery/calculate', [DeliveryController::class, 'calculate']);
    $router->post('/delivery/calculate', [DeliveryController::class, 'calculate']);
    $router->get('/settings/public', [SettingController::class, 'getPublic']);
    $router->post('/wholesale/enquiry', [WholesaleController::class, 'submitEnquiry']);
    $router->post('/marketing/validate-coupon', [MarketingController::class, 'validateCoupon']);
    $router->post('/marketing/discounts/validate', [MarketingController::class, 'validateCoupon']);

    // Public Homepage Banners
    $router->get('/banners', [BannerController::class, 'listPublic']);

    // Public CMS Pages & Order Tracking
    $router->get('/cms/pages', [CmsController::class, 'index']);
    $router->get('/cms/pages/{slug}', [CmsController::class, 'show']);
    $router->get('/orders/track', [OrderController::class, 'publicTrack']);

    // Shopping Cart & Checkout
    $router->get('/cart', [CartController::class, 'show']);
    $router->post('/cart/items', [CartController::class, 'addItem']);
    $router->put('/cart/items/{id}', [CartController::class, 'updateItem']);
    $router->delete('/cart/items/{id}', [CartController::class, 'removeItem']);
    $router->delete('/cart', [CartController::class, 'clear']);

    $router->post('/checkout/calculate', [CheckoutController::class, 'calculate']);
    $router->post('/checkout/place-order', [CheckoutController::class, 'placeOrder']);
    $router->post('/payments/bank-transfer/submit', [PaymentController::class, 'submitBankTransfer']);

    // Authenticated User Routes (Customers & Staff)
    $router->group('', function ($router) {
        $router->get('/auth/me', [AuthController::class, 'me']);
        $router->put('/auth/profile', [AuthController::class, 'updateProfile']);
        $router->post('/auth/change-password', [AuthController::class, 'changePassword']);
        $router->post('/auth/logout', [AuthController::class, 'logout']);
        $router->post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        $router->post('/auth/2fa/setup', [AuthController::class, 'setup2fa']);
        $router->post('/auth/2fa/enable', [AuthController::class, 'enable2fa']);
        $router->post('/auth/2fa/disable', [AuthController::class, 'disable2fa']);
        $router->post('/auth/resend-verification', [AuthController::class, 'resendVerification']);

        // Customer Wishlist
        $router->get('/wishlist', [WishlistController::class, 'index']);
        $router->post('/wishlist', [WishlistController::class, 'add']);
        $router->delete('/wishlist/{id}', [WishlistController::class, 'remove']);

        // Customer Reviews submission
        $router->post('/products/{id}/reviews', [ReviewController::class, 'submit']);

        // Order history, tax invoice and invoice download
        $router->get('/orders', [OrderController::class, 'index']);
        $router->get('/orders/{id}', [OrderController::class, 'show']);
        $router->get('/orders/{id}/tax-invoice', [OrderController::class, 'taxInvoice']);
        $router->get('/orders/{id}/invoice', [OrderController::class, 'downloadInvoice']);

        // Private bank transfer slip proof download
        $router->get('/payments/bank-transfers/{id}/proof', [PaymentController::class, 'getProof']);

        // Customer PDPA data export & erasure
        $router->get('/customers/{id}/export-data', [CustomerController::class, 'exportData']);
        $router->post('/customers/{id}/erase-data', [CustomerController::class, 'eraseData']);

        // Customer Wholesale statement
        $router->get('/wholesale/customers/{id}/statement', [WholesaleController::class, 'statement']);

        // Returns submission
        $router->post('/returns', [ReturnController::class, 'store']);

        // Authenticated Checkout — OTP must be issued with a valid customer session
        $router->post('/checkout/request-otp', [CheckoutController::class, 'requestOtp']);

        // Support Tickets
        $router->get('/support/tickets', [SupportController::class, 'index']);
        $router->get('/support/tickets/{id}', [SupportController::class, 'show']);
        $router->post('/support/tickets', [SupportController::class, 'store']);
        $router->post('/support/tickets/{id}/reply', [SupportController::class, 'reply']);
    }, [AuthMiddleware::class]);

    // Physical Retail Shop POS — Terminal Status & Fast PIN Login
    $router->get('/pos/status', [PosController::class, 'status']);
    $router->post('/pos/verify-pin', [PosController::class, 'verifyPin']);

    // Physical Retail Shop POS — Operational Sessions & Sales
    $router->group('/pos', function ($router) {
        $router->get('/sessions', [PosController::class, 'listSessions']);
        $router->get('/sessions/{id}', [PosController::class, 'showSession']);
        $router->post('/sessions/open', [PosController::class, 'openSession']);
        $router->post('/sessions/{id}/close', [PosController::class, 'closeSession']);
        $router->get('/sessions/{id}/float', [PosController::class, 'showFloat']);
        $router->post('/sessions/{id}/cash-movement', [PosController::class, 'cashMovement']);
        // Hold & resume
        $router->post('/sessions/{id}/hold', [PosController::class, 'holdSale']);
        $router->get('/sessions/{id}/held-sales', [PosController::class, 'listHeldSales']);
        $router->post('/sessions/{id}/resume/{held_id}', [PosController::class, 'resumeHeldSale']);
        $router->get('/transactions', [PosController::class, 'listTransactions']);
        $router->get('/transactions/{id}', [PosController::class, 'showTransaction']);
        $router->get('/transactions/{id}/receipt', [PosController::class, 'receipt']);
        $router->post('/transactions/{id}/void', [PosController::class, 'voidSale']);
        $router->post('/sales', [PosController::class, 'processSale']);
        $router->post('/exchange', [PosController::class, 'exchange']);
        $router->post('/sync-offline', [PosController::class, 'syncOffline']);
        $router->get('/daily-summary', [PosController::class, 'dailySummary']);
        // Terminal registration
        $router->post('/terminals/register', [PosController::class, 'registerTerminal']);
        $router->get('/terminals', [PosController::class, 'listTerminals']);
        // Barcode labels
        $router->get('/barcode/{id}', [PosController::class, 'barcodeLabel']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'shop_cashier']]]);

    // Central ERP Dashboard & Expenses
    $router->group('/erp', function ($router) {
        $router->get('/dashboard', [DashboardController::class, 'index']);
        $router->get('/expenses', [ExpenseController::class, 'index']);
        $router->post('/expenses', [ExpenseController::class, 'store']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager', 'finance_staff']]]);

    // Expense alias routes — ERP frontend calls /expenses directly (without /erp/ prefix)
    $router->group('', function ($router) {
        $router->get('/expenses', [ExpenseController::class, 'index']);
        $router->post('/expenses', [ExpenseController::class, 'store']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'finance_staff']]]);

    // ERP Products & Categories CRUD + CSV Import/Export
    $router->group('', function ($router) {
        $router->post('/products', [ProductController::class, 'store']);
        $router->put('/products/{id}', [ProductController::class, 'update']);
        $router->delete('/products/{id}', [ProductController::class, 'destroy']);
        $router->post('/products/import', [ProductController::class, 'importCsv']);
        $router->get('/products/export', [ProductController::class, 'exportCsv']);
        $router->post('/products/{id}/images', [ProductController::class, 'uploadImage']);
        $router->delete('/products/images/{id}', [ProductController::class, 'destroyImage']);

        $router->post('/categories', [CategoryController::class, 'store']);
        $router->put('/categories/{id}', [CategoryController::class, 'update']);
        $router->delete('/categories/{id}', [CategoryController::class, 'destroy']);

        // ERP Product Review Moderation
        $router->get('/erp/reviews', [ReviewController::class, 'moderationList']);
        $router->patch('/erp/reviews/{id}/approve', [ReviewController::class, 'approve']);
        $router->patch('/erp/reviews/{id}/reject', [ReviewController::class, 'reject']);
        $router->delete('/erp/reviews/{id}', [ReviewController::class, 'destroy']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager']]]);

    // ERP Inventory, Warehouses, Transfers & Stock Counts
    $router->group('/inventory', function ($router) {
        $router->get('/locations', [InventoryController::class, 'locations']);
        $router->get('/stock', [InventoryController::class, 'stock']);
        $router->get('/adjustments', [InventoryController::class, 'listAdjustments']);
        $router->post('/adjustments', [InventoryController::class, 'adjust']);
        $router->get('/transfers', [InventoryController::class, 'listTransfers']);
        $router->post('/transfers', [InventoryController::class, 'createTransfer']);
        $router->post('/transfers/{id}/complete', [InventoryController::class, 'completeTransfer']);
        $router->get('/allocations/{id}', [InventoryController::class, 'showAllocation']);
        $router->put('/allocations/{id}', [InventoryController::class, 'updateAllocation']);
        $router->get('/movements', [InventoryController::class, 'movements']);

        // Physical Stock Counts
        $router->get('/counts', [InventoryController::class, 'counts']);
        $router->post('/counts', [InventoryController::class, 'createCount']);
        $router->get('/counts/{id}', [InventoryController::class, 'showCount']);
        $router->post('/counts/{id}/items', [InventoryController::class, 'recordCountItem']);
        $router->post('/counts/{id}/reconcile', [InventoryController::class, 'reconcileCount']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager', 'inventory_staff']]]);

    // ERP Suppliers Management & Ledger
    $router->group('/suppliers', function ($router) {
        $router->get('', [SupplierController::class, 'index']);
        $router->post('', [SupplierController::class, 'store']);
        $router->get('/{id}', [SupplierController::class, 'show']);
        $router->put('/{id}', [SupplierController::class, 'update']);
        $router->delete('/{id}', [SupplierController::class, 'destroy']);
        $router->get('/{id}/history', [SupplierController::class, 'purchaseHistory']);
        $router->get('/{id}/payments', [SupplierController::class, 'listPayments']);
        $router->post('/{id}/payments', [SupplierController::class, 'recordPayment']);
        $router->get('/{id}/returns', [SupplierController::class, 'listReturns']);
        $router->post('/{id}/returns', [SupplierController::class, 'recordReturn']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager']]]);

    // ERP Purchasing & Goods Receipts
    $router->group('/purchasing', function ($router) {
        $router->get('/orders', [PurchaseOrderController::class, 'index']);
        $router->post('/orders', [PurchaseOrderController::class, 'store']);
        $router->get('/orders/{id}', [PurchaseOrderController::class, 'show']);
        $router->put('/orders/{id}/status', [PurchaseOrderController::class, 'updateStatus']);
        $router->post('/orders/{id}/receive', [PurchaseOrderController::class, 'receiveGoods']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager']]]);

    $router->group('/purchase-orders', function ($router) {
        $router->get('', [PurchaseOrderController::class, 'index']);
        $router->post('', [PurchaseOrderController::class, 'store']);
        $router->get('/{id}', [PurchaseOrderController::class, 'show']);
        $router->put('/{id}/status', [PurchaseOrderController::class, 'updateStatus']);
        $router->patch('/{id}/status', [PurchaseOrderController::class, 'updateStatus']);
        $router->post('/{id}/receive', [PurchaseOrderController::class, 'receiveGoods']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager']]]);

    // ERP Customer Management
    $router->group('/customers', function ($router) {
        $router->get('', [CustomerController::class, 'index']);
        $router->get('/{id}', [CustomerController::class, 'show']);
        $router->put('/{id}', [CustomerController::class, 'update']);
        $router->get('/{id}/addresses', [CustomerController::class, 'addresses']);
        $router->post('/{id}/addresses', [CustomerController::class, 'addAddress']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'customer_support']]]);

    // ERP Returns & Refunds Management
    $router->group('/returns', function ($router) {
        $router->get('', [ReturnController::class, 'index']);
        $router->get('/{id}', [ReturnController::class, 'show']);
        $router->post('/{id}/approve', [ReturnController::class, 'approve']);
        $router->post('/{id}/reject', [ReturnController::class, 'reject']);
        $router->post('/{id}/receive', [ReturnController::class, 'receive']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'customer_support', 'warehouse_manager']]]);

    // ERP Orders, Status & Fulfillment
    $router->group('/orders', function ($router) {
        $router->post('', [OrderController::class, 'store']);
        $router->put('/{id}/status', [OrderController::class, 'updateStatus']);
        $router->patch('/{id}/status', [OrderController::class, 'updateStatus']);
        $router->put('/{id}/fulfillment', [DeliveryController::class, 'updateFulfillment']);
        $router->post('/{id}/fulfill', [DeliveryController::class, 'updateFulfillment']);
        $router->post('/{id}/fulfillment', [DeliveryController::class, 'updateFulfillment']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager', 'order_staff']]]);

    // ERP Delivery Zones Management
    $router->group('/delivery/zones', function ($router) {
        $router->post('', [DeliveryController::class, 'createZone']);
        $router->put('/{id}', [DeliveryController::class, 'updateZone']);
        $router->delete('/{id}', [DeliveryController::class, 'destroyZone']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager']]]);

    // ERP Payments, Bank Transfer Verification, Refunds & COD Remittances
    $router->group('/payments', function ($router) {
        $router->get('/bank-transfers/pending', [PaymentController::class, 'listPending']);
        $router->post('/bank-transfers/{id}/verify', [PaymentController::class, 'verifyBankTransfer']);
        $router->post('/refunds', [PaymentController::class, 'refund']);
        $router->get('/cod-remittances', [CodController::class, 'index']);
        $router->post('/cod-remittances', [CodController::class, 'store']);
        $router->get('/cod-remittances/{id}', [CodController::class, 'show']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'finance_staff']]]);

    // ERP Marketing & Wholesale
    $router->group('', function ($router) {
        $router->get('/marketing/discounts', [MarketingController::class, 'index']);
        $router->post('/marketing/discounts', [MarketingController::class, 'store']);
        $router->delete('/marketing/discounts/{id}', [MarketingController::class, 'destroy']);

        $router->get('/wholesale/enquiries', [WholesaleController::class, 'listEnquiries']);
        $router->post('/wholesale/enquiries/{id}/approve', [WholesaleController::class, 'approve']);
        $router->get('/wholesale/pricing-tiers/{id}', [WholesaleController::class, 'tiers']);
        $router->post('/wholesale/pricing-tiers/{id}', [WholesaleController::class, 'setTier']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager']]]);

    // ERP Financial & Operational Reports + CSV Export
    $router->group('/reports', function ($router) {
        $router->get('/executive-metrics', [ReportController::class, 'metrics']);
        $router->get('/executive', [ReportController::class, 'metrics']);
        $router->get('/profit-and-loss', [ReportController::class, 'profitAndLoss']);
        $router->get('/sales', [ReportController::class, 'sales']);
        $router->get('/sales/export', [ReportController::class, 'exportSalesCsv']);
        $router->get('/products', [ReportController::class, 'productSales']);
        $router->get('/inventory', [ReportController::class, 'inventory']);
        $router->get('/inventory/export', [ReportController::class, 'exportInventoryCsv']);
        $router->get('/purchases', [ReportController::class, 'purchases']);
        $router->get('/customers', [ReportController::class, 'customers']);
        $router->get('/pos', [ReportController::class, 'pos']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'finance_staff']]]);

    // ERP Notifications Queue & Testing
    $router->group('/notifications', function ($router) {
        $router->get('/queue', [NotificationController::class, 'listQueue']);
        $router->post('/process-queue', [NotificationController::class, 'process']);
        $router->post('/process', [NotificationController::class, 'process']);
        $router->post('/test', [NotificationController::class, 'testAlert']);
        $router->post('/test-whatsapp', [NotificationController::class, 'testAlert']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner']]]);

    // Owner-Only Administration & CMS
    $router->group('', function ($router) {
        // Employee Management
        $router->get('/employees', [EmployeeController::class, 'index']);
        $router->post('/employees', [EmployeeController::class, 'store']);
        $router->get('/employees/{id}', [EmployeeController::class, 'show']);
        $router->put('/employees/{id}', [EmployeeController::class, 'update']);
        $router->delete('/employees/{id}', [EmployeeController::class, 'destroy']);
        $router->put('/employees/permissions', [EmployeeController::class, 'updatePermissions']);

        // Audit Logs
        $router->get('/audit-logs', [EmployeeController::class, 'auditLogs']);

        // Business Settings & Database Backup
        $router->get('/settings', [SettingController::class, 'index']);
        $router->put('/settings', [SettingController::class, 'update']);
        $router->post('/settings/backup', [SettingController::class, 'createBackup']);
        $router->post('/maintenance/backup', [SettingController::class, 'createBackup']);

        // CMS Content Management
        $router->post('/cms/pages', [CmsController::class, 'store']);
        $router->put('/cms/pages/{id}', [CmsController::class, 'update']);
        $router->delete('/cms/pages/{id}', [CmsController::class, 'destroy']);

        // Banner Management (ERP)
        $router->get('/banners', [BannerController::class, 'listAll']);
        $router->post('/banners', [BannerController::class, 'create']);
        $router->put('/banners/{id}', [BannerController::class, 'update']);
        $router->delete('/banners/{id}', [BannerController::class, 'delete']);
        $router->patch('/banners/{id}/toggle', [BannerController::class, 'toggle']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner']]]);

}, [SecurityHeadersMiddleware::class, CorsMiddleware::class, JsonBodyMiddleware::class]);
