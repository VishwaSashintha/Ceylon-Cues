<?php

declare(strict_types=1);

namespace CeylonCues\Routes;

use CeylonCues\Controllers\Api\V1\Auth\AuthController;
use CeylonCues\Controllers\Api\V1\Auth\EmployeeController;
use CeylonCues\Controllers\Api\V1\Cart\CartController;
use CeylonCues\Controllers\Api\V1\Categories\CategoryController;
use CeylonCues\Controllers\Api\V1\Checkout\CheckoutController;
use CeylonCues\Controllers\Api\V1\Customers\CustomerController;
use CeylonCues\Controllers\Api\V1\Delivery\DeliveryController;
use CeylonCues\Controllers\Api\V1\Erp\DashboardController;
use CeylonCues\Controllers\Api\V1\Erp\ExpenseController;
use CeylonCues\Controllers\Api\V1\Inventory\InventoryController;
use CeylonCues\Controllers\Api\V1\Marketing\MarketingController;
use CeylonCues\Controllers\Api\V1\Notifications\NotificationController;
use CeylonCues\Controllers\Api\V1\Orders\OrderController;
use CeylonCues\Controllers\Api\V1\Orders\ReturnController;
use CeylonCues\Controllers\Api\V1\Payments\PaymentController;
use CeylonCues\Controllers\Api\V1\Pos\PosController;
use CeylonCues\Controllers\Api\V1\Products\ProductController;
use CeylonCues\Controllers\Api\V1\Purchasing\PurchaseOrderController;
use CeylonCues\Controllers\Api\V1\Reports\ReportController;
use CeylonCues\Controllers\Api\V1\Settings\SettingController;
use CeylonCues\Controllers\Api\V1\Suppliers\SupplierController;
use CeylonCues\Controllers\Api\V1\Support\SupportController;
use CeylonCues\Controllers\Api\V1\Wholesale\WholesaleController;
use CeylonCues\Middleware\AuthMiddleware;
use CeylonCues\Middleware\CorsMiddleware;
use CeylonCues\Middleware\JsonBodyMiddleware;
use CeylonCues\Middleware\RoleMiddleware;

/** @var \CeylonCues\Core\Router $router */

$router->group('/api/v1', function ($router) {

    // Public Authentication & Identity
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/register', [AuthController::class, 'register']);
    $router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    $router->post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    $router->post('/auth/csrf-token', [AuthController::class, 'csrfToken']);

    // Public Storefront Catalog & Settings
    $router->get('/products', [ProductController::class, 'index']);
    $router->get('/products/{id}', [ProductController::class, 'show']);
    $router->get('/categories', [CategoryController::class, 'index']);
    $router->get('/categories/{id}', [CategoryController::class, 'show']);
    $router->get('/delivery/zones', [DeliveryController::class, 'zones']);
    $router->get('/delivery/calculate', [DeliveryController::class, 'calculate']);
    $router->get('/settings/public', [SettingController::class, 'getPublic']);
    $router->post('/wholesale/enquiry', [WholesaleController::class, 'submitEnquiry']);
    $router->post('/marketing/validate-coupon', [MarketingController::class, 'validateCoupon']);

    // Shopping Cart & Checkout
    $router->get('/cart', [CartController::class, 'show']);
    $router->post('/cart/items', [CartController::class, 'addItem']);
    $router->put('/cart/items/{id}', [CartController::class, 'updateItem']);
    $router->delete('/cart/items/{id}', [CartController::class, 'removeItem']);
    $router->delete('/cart', [CartController::class, 'clear']);

    $router->post('/checkout/calculate', [CheckoutController::class, 'calculate']);
    $router->post('/checkout/place-order', [CheckoutController::class, 'placeOrder']);
    $router->post('/payments/bank-transfer/submit', [PaymentController::class, 'submitBankTransfer']);

    // Authenticated User Routes
    $router->group('', function ($router) {
        $router->get('/auth/me', [AuthController::class, 'me']);
        $router->put('/auth/profile', [AuthController::class, 'updateProfile']);
        $router->post('/auth/change-password', [AuthController::class, 'changePassword']);
        $router->post('/auth/logout', [AuthController::class, 'logout']);

        // Order history
        $router->get('/orders', [OrderController::class, 'index']);
        $router->get('/orders/{id}', [OrderController::class, 'show']);

        // Returns submission
        $router->post('/returns', [ReturnController::class, 'store']);

        // Support Tickets
        $router->get('/support/tickets', [SupportController::class, 'index']);
        $router->get('/support/tickets/{id}', [SupportController::class, 'show']);
        $router->post('/support/tickets', [SupportController::class, 'store']);
        $router->post('/support/tickets/{id}/reply', [SupportController::class, 'reply']);
    }, [AuthMiddleware::class]);

    // Physical Retail Shop POS
    $router->group('/pos', function ($router) {
        $router->get('/sessions', [PosController::class, 'listSessions']);
        $router->get('/sessions/{id}', [PosController::class, 'showSession']);
        $router->post('/sessions/open', [PosController::class, 'openSession']);
        $router->post('/sessions/{id}/close', [PosController::class, 'closeSession']);
        $router->get('/transactions', [PosController::class, 'listTransactions']);
        $router->get('/transactions/{id}', [PosController::class, 'showTransaction']);
        $router->post('/sales', [PosController::class, 'processSale']);
        $router->get('/daily-summary', [PosController::class, 'dailySummary']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'shop_cashier']]]);

    // Central ERP Dashboard & Expenses
    $router->group('/erp', function ($router) {
        $router->get('/dashboard', [DashboardController::class, 'index']);
        $router->get('/expenses', [ExpenseController::class, 'index']);
        $router->post('/expenses', [ExpenseController::class, 'store']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager', 'finance_staff']]]);

    // ERP Products & Categories CRUD
    $router->group('', function ($router) {
        $router->post('/products', [ProductController::class, 'store']);
        $router->put('/products/{id}', [ProductController::class, 'update']);
        $router->delete('/products/{id}', [ProductController::class, 'destroy']);
        $router->post('/products/{id}/images', [ProductController::class, 'uploadImage']);
        $router->delete('/products/images/{id}', [ProductController::class, 'destroyImage']);

        $router->post('/categories', [CategoryController::class, 'store']);
        $router->put('/categories/{id}', [CategoryController::class, 'update']);
        $router->delete('/categories/{id}', [CategoryController::class, 'destroy']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager']]]);

    // ERP Inventory, Warehouses, Transfers & Stock Counts
    $router->group('/inventory', function ($router) {
        $router->get('/locations', [InventoryController::class, 'locations']);
        $router->get('/stock', [InventoryController::class, 'stock']);
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

    // ERP Suppliers Management
    $router->group('/suppliers', function ($router) {
        $router->get('', [SupplierController::class, 'index']);
        $router->post('', [SupplierController::class, 'store']);
        $router->get('/{id}', [SupplierController::class, 'show']);
        $router->put('/{id}', [SupplierController::class, 'update']);
        $router->delete('/{id}', [SupplierController::class, 'destroy']);
        $router->get('/{id}/history', [SupplierController::class, 'purchaseHistory']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager']]]);

    // ERP Purchasing & Goods Receipts
    $router->group('/purchasing', function ($router) {
        $router->get('/orders', [PurchaseOrderController::class, 'index']);
        $router->post('/orders', [PurchaseOrderController::class, 'store']);
        $router->get('/orders/{id}', [PurchaseOrderController::class, 'show']);
        $router->put('/orders/{id}/status', [PurchaseOrderController::class, 'updateStatus']);
        $router->post('/orders/{id}/receive', [PurchaseOrderController::class, 'receiveGoods']);
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
        $router->put('/{id}/fulfillment', [DeliveryController::class, 'updateFulfillment']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager', 'order_staff']]]);

    // ERP Delivery Zones Management
    $router->group('/delivery/zones', function ($router) {
        $router->post('', [DeliveryController::class, 'createZone']);
        $router->put('/{id}', [DeliveryController::class, 'updateZone']);
        $router->delete('/{id}', [DeliveryController::class, 'destroyZone']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager']]]);

    // ERP Payments, Bank Transfer Verification & Refunds
    $router->group('/payments', function ($router) {
        $router->get('/bank-transfers/pending', [PaymentController::class, 'listPending']);
        $router->post('/bank-transfers/{id}/verify', [PaymentController::class, 'verifyBankTransfer']);
        $router->post('/refunds', [PaymentController::class, 'refund']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'finance_staff']]]);

    // ERP Marketing & Wholesale
    $router->group('', function ($router) {
        $router->get('/marketing/discounts', [MarketingController::class, 'index']);
        $router->post('/marketing/discounts', [MarketingController::class, 'store']);

        $router->get('/wholesale/enquiries', [WholesaleController::class, 'listEnquiries']);
        $router->get('/wholesale/pricing-tiers/{id}', [WholesaleController::class, 'tiers']);
        $router->post('/wholesale/pricing-tiers/{id}', [WholesaleController::class, 'setTier']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager']]]);

    // ERP Financial & Operational Reports
    $router->group('/reports', function ($router) {
        $router->get('/executive-metrics', [ReportController::class, 'metrics']);
        $router->get('/profit-and-loss', [ReportController::class, 'profitAndLoss']);
        $router->get('/sales', [ReportController::class, 'sales']);
        $router->get('/products', [ReportController::class, 'productSales']);
        $router->get('/inventory', [ReportController::class, 'inventory']);
        $router->get('/purchases', [ReportController::class, 'purchases']);
        $router->get('/customers', [ReportController::class, 'customers']);
        $router->get('/pos', [ReportController::class, 'pos']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'finance_staff']]]);

    // ERP Notifications Queue & Testing
    $router->group('/notifications', function ($router) {
        $router->post('/process-queue', [NotificationController::class, 'process']);
        $router->post('/test', [NotificationController::class, 'testAlert']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner']]]);

    // Owner-Only Administration
    $router->group('', function ($router) {
        // Employee Management
        $router->get('/employees', [EmployeeController::class, 'index']);
        $router->post('/employees', [EmployeeController::class, 'store']);
        $router->get('/employees/{id}', [EmployeeController::class, 'show']);
        $router->put('/employees/{id}', [EmployeeController::class, 'update']);
        $router->delete('/employees/{id}', [EmployeeController::class, 'destroy']);
        $router->put('/employees/permissions', [EmployeeController::class, 'updatePermissions']);

        // Business Settings & Database Backup
        $router->get('/settings', [SettingController::class, 'index']);
        $router->put('/settings', [SettingController::class, 'update']);
        $router->post('/settings/backup', [SettingController::class, 'createBackup']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner']]]);

}, [CorsMiddleware::class, JsonBodyMiddleware::class]);
