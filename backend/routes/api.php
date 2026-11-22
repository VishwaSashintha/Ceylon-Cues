<?php

declare(strict_types=1);

/**
 * Ceylon Cues — REST API Routes Definition
 * All endpoints registered under /api/v1/
 */

use CeylonCues\Controllers\Api\V1\Auth\AuthController;
use CeylonCues\Controllers\Api\V1\Auth\EmployeeController;
use CeylonCues\Controllers\Api\V1\Cart\CartController;
use CeylonCues\Controllers\Api\V1\Categories\CategoryController;
use CeylonCues\Controllers\Api\V1\Checkout\CheckoutController;
use CeylonCues\Controllers\Api\V1\Delivery\DeliveryController;
use CeylonCues\Controllers\Api\V1\Erp\DashboardController;
use CeylonCues\Controllers\Api\V1\Erp\ExpenseController;
use CeylonCues\Controllers\Api\V1\Inventory\InventoryController;
use CeylonCues\Controllers\Api\V1\Marketing\MarketingController;
use CeylonCues\Controllers\Api\V1\Notifications\NotificationController;
use CeylonCues\Controllers\Api\V1\Orders\OrderController;
use CeylonCues\Controllers\Api\V1\Payments\PaymentController;
use CeylonCues\Controllers\Api\V1\Pos\PosController;
use CeylonCues\Controllers\Api\V1\Products\ProductController;
use CeylonCues\Controllers\Api\V1\Reports\ReportController;
use CeylonCues\Controllers\Api\V1\Settings\SettingController;
use CeylonCues\Controllers\Api\V1\Support\SupportController;
use CeylonCues\Controllers\Api\V1\Wholesale\WholesaleController;
use CeylonCues\Middleware\AuthMiddleware;
use CeylonCues\Middleware\CorsMiddleware;
use CeylonCues\Middleware\JsonBodyMiddleware;
use CeylonCues\Middleware\RoleMiddleware;

/** @var \CeylonCues\Core\Router $router */

$router->group('/api/v1', function ($router) {

    // ── Public Authentication & Identity ─────────────────────────────────────
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/register', [AuthController::class, 'register']);
    $router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    $router->post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    $router->post('/auth/csrf-token', [AuthController::class, 'csrfToken']);

    // ── Public Storefront Catalog & Settings ─────────────────────────────────
    $router->get('/products', [ProductController::class, 'index']);
    $router->get('/products/{id}', [ProductController::class, 'show']);
    $router->get('/categories', [CategoryController::class, 'index']);
    $router->get('/categories/{id}', [CategoryController::class, 'show']);
    $router->get('/delivery/zones', [DeliveryController::class, 'zones']);
    $router->get('/delivery/calculate', [DeliveryController::class, 'calculate']);
    $router->get('/settings/public', [SettingController::class, 'getPublic']);
    $router->post('/wholesale/enquiry', [WholesaleController::class, 'submitEnquiry']);
    $router->post('/marketing/validate-coupon', [MarketingController::class, 'validateCoupon']);

    // ── Shopping Cart & Checkout (Guest or Registered) ───────────────────────
    $router->get('/cart', [CartController::class, 'show']);
    $router->post('/cart/items', [CartController::class, 'addItem']);
    $router->put('/cart/items/{id}', [CartController::class, 'updateItem']);
    $router->delete('/cart/items/{id}', [CartController::class, 'removeItem']);
    $router->delete('/cart', [CartController::class, 'clear']);

    $router->post('/checkout/calculate', [CheckoutController::class, 'calculate']);
    $router->post('/checkout/place-order', [CheckoutController::class, 'placeOrder']);
    $router->post('/payments/bank-transfer/submit', [PaymentController::class, 'submitBankTransfer']);

    // ── Authenticated User Routes (Customers & Employees) ───────────────────
    $router->group('', function ($router) {
        $router->get('/auth/me', [AuthController::class, 'me']);
        $router->put('/auth/profile', [AuthController::class, 'updateProfile']);
        $router->post('/auth/change-password', [AuthController::class, 'changePassword']);
        $router->post('/auth/logout', [AuthController::class, 'logout']);

        // Order history
        $router->get('/orders', [OrderController::class, 'index']);
        $router->get('/orders/{id}', [OrderController::class, 'show']);

        // Support Tickets
        $router->get('/support/tickets', [SupportController::class, 'index']);
        $router->get('/support/tickets/{id}', [SupportController::class, 'show']);
        $router->post('/support/tickets', [SupportController::class, 'store']);
        $router->post('/support/tickets/{id}/reply', [SupportController::class, 'reply']);
    }, [AuthMiddleware::class]);

    // ── Physical Retail Shop POS (Cashier / Manager / Owner) ────────────────
    $router->group('/pos', function ($router) {
        $router->post('/sessions/open', [PosController::class, 'openSession']);
        $router->post('/sessions/{id}/close', [PosController::class, 'closeSession']);
        $router->post('/sales', [PosController::class, 'processSale']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'shop_cashier']]]);

    // ── Central ERP Management (Staff & Owner) ──────────────────────────────
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

    // ERP Inventory, Warehouses & Stock Transfers
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
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager', 'inventory_staff']]]);

    // ERP Orders & Status Workflow
    $router->group('/orders', function ($router) {
        $router->post('', [OrderController::class, 'store']);
        $router->put('/{id}/status', [OrderController::class, 'updateStatus']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'shop_manager', 'warehouse_manager', 'order_staff']]]);

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

    // ERP Financial Reports
    $router->group('/reports', function ($router) {
        $router->get('/executive-metrics', [ReportController::class, 'metrics']);
        $router->get('/profit-and-loss', [ReportController::class, 'profitAndLoss']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner', 'finance_staff']]]);

    // ERP Notifications Queue & Testing
    $router->group('/notifications', function ($router) {
        $router->post('/process-queue', [NotificationController::class, 'process']);
        $router->post('/test', [NotificationController::class, 'testAlert']);
    }, [AuthMiddleware::class, [RoleMiddleware::class, ['owner']]]);

    // ── Owner-Only Administration ───────────────────────────────────────────
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
