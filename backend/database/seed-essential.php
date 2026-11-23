<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Essential System Seeder
 *
 * Seeds strictly required baseline system configuration and reference data:
 *   1. Business Settings
 *   2. Inventory Locations (Warehouse & Flagship Shop)
 *   3. Role Permissions Matrix (RBAC)
 *   4. Sri Lankan Delivery Zones
 *   5. Product Taxonomy / Categories
 *   6. Core Notification Templates (WhatsApp & Email)
 *
 * Safe to re-run in production; uses ON DUPLICATE KEY UPDATE / checks.
 *
 * Usage:
 *   php database/seed-essential.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CeylonCues\Core\Database;
use CeylonCues\Core\Env;
use CeylonCues\Core\Uuid;

Env::load(dirname(__DIR__) . '/.env');

$db = Database::getInstance()->getConnection();
echo "--- Seeding Ceylon Cues Essential System Settings ---\n";

// 1. Business Settings
echo "1. Seeding Business Settings...\n";
$settings = [
    ['setting_key' => 'business_name', 'setting_value' => 'Ceylon Cues', 'setting_type' => 'string', 'description' => 'Official Business Name', 'is_public' => 1],
    ['setting_key' => 'tagline', 'setting_value' => 'Precision. Performance. Passion.', 'setting_type' => 'string', 'description' => 'Business Tagline', 'is_public' => 1],
    ['setting_key' => 'currency_code', 'setting_value' => 'LKR', 'setting_type' => 'string', 'description' => 'Primary Currency Code', 'is_public' => 1],
    ['setting_key' => 'currency_symbol', 'setting_value' => 'Rs.', 'setting_type' => 'string', 'description' => 'Currency Symbol', 'is_public' => 1],
    ['setting_key' => 'timezone', 'setting_value' => 'Asia/Colombo', 'setting_type' => 'string', 'description' => 'Operating Timezone', 'is_public' => 1],
    ['setting_key' => 'tax_rate_percent', 'setting_value' => '0', 'setting_type' => 'decimal', 'description' => 'Value Added Tax Rate %', 'is_public' => 0],
    ['setting_key' => 'free_delivery_min_order', 'setting_value' => '15000.00', 'setting_type' => 'decimal', 'description' => 'Free Delivery Order Minimum (LKR)', 'is_public' => 1],
    ['setting_key' => 'bank_name', 'setting_value' => 'Commercial Bank of Ceylon', 'setting_type' => 'string', 'description' => 'Primary Bank Name', 'is_public' => 1],
    ['setting_key' => 'bank_account_name', 'setting_value' => 'Ceylon Cues (Pvt) Ltd', 'setting_type' => 'string', 'description' => 'Bank Account Name', 'is_public' => 1],
    ['setting_key' => 'bank_account_number', 'setting_value' => '8012345678', 'setting_type' => 'string', 'description' => 'Bank Account Number', 'is_public' => 1],
    ['setting_key' => 'bank_branch', 'setting_value' => 'Kollupitiya Branch', 'setting_type' => 'string', 'description' => 'Bank Branch Name', 'is_public' => 1],
    ['setting_key' => 'owner_whatsapp', 'setting_value' => '+94770000000', 'setting_type' => 'string', 'description' => 'Owner WhatsApp Notification Number', 'is_public' => 0],
    ['setting_key' => 'support_phone', 'setting_value' => '+94771234567', 'setting_type' => 'string', 'description' => 'Customer Care Hotline', 'is_public' => 1],
    ['setting_key' => 'support_email', 'setting_value' => 'support@ceyloncues.lk', 'setting_type' => 'string', 'description' => 'Customer Care Email', 'is_public' => 1],
    ['setting_key' => 'shop_address', 'setting_value' => 'No. 45, Galle Road, Colombo 03, Sri Lanka', 'setting_type' => 'string', 'description' => 'Physical Retail Store Address', 'is_public' => 1],
    ['setting_key' => 'stock_warning_threshold', 'setting_value' => '5', 'setting_type' => 'integer', 'description' => 'Global Low Stock Alert Threshold', 'is_public' => 0],
];

$stmt = $db->prepare("INSERT INTO business_settings (setting_key, setting_value, setting_type, description, is_public) 
                      VALUES (:setting_key, :setting_value, :setting_type, :description, :is_public)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
foreach ($settings as $s) {
    $stmt->execute($s);
}

// 2. Inventory Locations
echo "2. Seeding Inventory Locations...\n";
$locations = [
    [
        'uuid' => Uuid::v4(),
        'name' => 'Main Warehouse',
        'type' => 'warehouse',
        'address' => 'Ceylon Cues Central Logistics Hub, Kelaniya, Western Province, Sri Lanka',
        'is_active' => 1,
        'is_default' => 1
    ],
    [
        'uuid' => Uuid::v4(),
        'name' => 'Physical Retail Shop',
        'type' => 'shop',
        'address' => 'Ceylon Cues Flagship Store, No. 45, Galle Road, Colombo 03, Sri Lanka',
        'is_active' => 1,
        'is_default' => 0
    ],
];
$stmtLoc = $db->prepare("INSERT INTO inventory_locations (uuid, name, type, address, is_active, is_default)
                         VALUES (:uuid, :name, :type, :address, :is_active, :is_default)
                         ON DUPLICATE KEY UPDATE address = VALUES(address)");
foreach ($locations as $loc) {
    $stmtLoc->execute($loc);
}

// 3. Permissions Matrix
echo "3. Seeding Role Permissions...\n";
$modules = [
    'dashboard', 'products', 'categories', 'inventory', 'warehouse', 'shop', 
    'stock_transfers', 'stock_adjustments', 'stock_counts', 'suppliers', 
    'purchasing', 'orders', 'pos', 'wholesale', 'customers', 'payments', 
    'delivery', 'marketing', 'support', 'expenses', 'reports', 'settings', 
    'notifications', 'audit_logs', 'employees'
];

$roles = [
    'shop_manager' => ['dashboard', 'products', 'categories', 'inventory', 'shop', 'stock_transfers', 'orders', 'pos', 'customers', 'payments', 'delivery', 'support', 'reports'],
    'shop_cashier' => ['pos', 'orders', 'products', 'shop', 'customers'],
    'warehouse_manager' => ['dashboard', 'inventory', 'warehouse', 'stock_transfers', 'stock_adjustments', 'stock_counts', 'suppliers', 'purchasing', 'reports'],
    'inventory_staff' => ['inventory', 'warehouse', 'shop', 'stock_counts'],
    'order_staff' => ['orders', 'delivery', 'customers', 'inventory'],
    'customer_support' => ['support', 'orders', 'customers', 'wholesale'],
    'finance_staff' => ['payments', 'expenses', 'reports', 'purchasing', 'dashboard'],
];

$stmtPerm = $db->prepare("INSERT INTO permissions (role, module, can_view, can_create, can_edit, can_delete)
                         VALUES (:role, :module, :can_view, :can_create, :can_edit, :can_delete)
                         ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_create = VALUES(can_create), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)");

foreach ($roles as $role => $allowedModules) {
    foreach ($modules as $mod) {
        $allowed = in_array($mod, $allowedModules, true);
        $stmtPerm->execute([
            ':role' => $role,
            ':module' => $mod,
            ':can_view' => $allowed ? 1 : 0,
            ':can_create' => ($allowed && !in_array($role, ['shop_cashier', 'customer_support'])) ? 1 : ($mod === 'pos' ? 1 : 0),
            ':can_edit' => ($allowed && in_array($role, ['shop_manager', 'warehouse_manager', 'finance_staff'])) ? 1 : 0,
            ':can_delete' => ($allowed && in_array($role, ['shop_manager', 'warehouse_manager'])) ? 1 : 0,
        ]);
    }
}

// 4. Delivery Zones (Sri Lanka Districts)
echo "4. Seeding Delivery Zones...\n";
$deliveryZones = [
    [
        'name' => 'Colombo Metro & Suburbs',
        'districts' => json_encode(['Colombo', 'Gampaha']),
        'delivery_charge' => 350.00,
        'free_delivery_threshold' => 10000.00,
        'estimated_days' => 1,
        'sort_order' => 1
    ],
    [
        'name' => 'Western Province (Outer)',
        'districts' => json_encode(['Kalutara']),
        'delivery_charge' => 450.00,
        'free_delivery_threshold' => 12000.00,
        'estimated_days' => 2,
        'sort_order' => 2
    ],
    [
        'name' => 'Central & Southern Provinces',
        'districts' => json_encode(['Kandy', 'Matale', 'Nuwara Eliya', 'Galle', 'Matara', 'Hambantota']),
        'delivery_charge' => 550.00,
        'free_delivery_threshold' => 15000.00,
        'estimated_days' => 3,
        'sort_order' => 3
    ],
    [
        'name' => 'North Western & North Central',
        'districts' => json_encode(['Kurunegala', 'Puttalam', 'Anuradhapura', 'Polonnaruwa']),
        'delivery_charge' => 600.00,
        'free_delivery_threshold' => 18000.00,
        'estimated_days' => 3,
        'sort_order' => 4
    ],
    [
        'name' => 'Northern, Eastern, Uva & Sabaragamuwa',
        'districts' => json_encode(['Jaffna', 'Kilinochchi', 'Mannar', 'Vavuniya', 'Mullaitivu', 'Batticaloa', 'Ampara', 'Trincomalee', 'Badulla', 'Monaragala', 'Ratnapura', 'Kegalle']),
        'delivery_charge' => 700.00,
        'free_delivery_threshold' => 20000.00,
        'estimated_days' => 4,
        'sort_order' => 5
    ],
];

$stmtZone = $db->prepare("INSERT INTO delivery_zones (name, districts, delivery_charge, free_delivery_threshold, estimated_days, is_active, sort_order)
                          VALUES (:name, :districts, :delivery_charge, :free_delivery_threshold, :estimated_days, 1, :sort_order)
                          ON DUPLICATE KEY UPDATE delivery_charge = VALUES(delivery_charge)");
foreach ($deliveryZones as $zone) {
    $stmtZone->execute($zone);
}

// 5. Product Categories
echo "5. Seeding Product Categories...\n";
$categories = [
    ['name' => 'Cue Sticks', 'slug' => 'cue-sticks', 'description' => 'Professional, standard and house pool and snooker cues', 'sort_order' => 1],
    ['name' => 'Cue Cases', 'slug' => 'cue-cases', 'description' => 'Hard, soft, and leather cases for cues and shafts', 'sort_order' => 2],
    ['name' => 'Billiard & Pool Balls', 'slug' => 'billiard-balls', 'description' => 'Phenolic resin, Aramith, and tournament ball sets', 'sort_order' => 3],
    ['name' => 'Pool Tables', 'slug' => 'pool-tables', 'description' => 'Professional slate tables, commercial coin-op and home tables', 'sort_order' => 4],
    ['name' => 'Table Accessories', 'slug' => 'table-accessories', 'description' => 'Table cloth (felt), racks, ball trays, covers and brushes', 'sort_order' => 5],
    ['name' => 'Chalk & Tips', 'slug' => 'chalk-and-tips', 'description' => 'Premium chalk, layered leather tips, scuffers, and ferrules', 'sort_order' => 6],
    ['name' => 'Cue Maintenance', 'slug' => 'cue-maintenance', 'description' => 'Shaft conditioners, tip shapers, clamps and cleaning cloths', 'sort_order' => 7],
    ['name' => 'Training & Accessories', 'slug' => 'training-and-accessories', 'description' => 'Billiard gloves, cue extensions, training balls and aim trainers', 'sort_order' => 8],
];

$stmtCat = $db->prepare("INSERT INTO categories (uuid, name, slug, description, sort_order, is_active)
                         VALUES (:uuid, :name, :slug, :description, :sort_order, 1)
                         ON DUPLICATE KEY UPDATE name = VALUES(name)");
foreach ($categories as $cat) {
    $stmtCat->execute([
        ':uuid' => Uuid::v4(),
        ':name' => $cat['name'],
        ':slug' => $cat['slug'],
        ':description' => $cat['description'],
        ':sort_order' => $cat['sort_order'],
    ]);
}

// 6. Notification Templates
echo "6. Seeding Notification Templates...\n";
$templates = [
    [
        'name' => 'whatsapp_new_order_owner',
        'channel' => 'whatsapp',
        'event_type' => 'order.created',
        'template_body' => "🎱 NEW ORDER — CEYLON CUES\n\nOrder: {{order_ref}}\nCustomer: {{customer_name}}\nPhone: {{customer_phone}}\nItems: {{item_count}}\nSubtotal: LKR {{subtotal}}\nDelivery: LKR {{delivery_charge}}\nTotal: LKR {{total_amount}}\n\nPayment Method: {{payment_method}}\nPayment Status: {{payment_status}}\nDelivery Method: {{delivery_method}}\n\nPlease review this order in the ERP.",
        'variables' => json_encode(['order_ref', 'customer_name', 'customer_phone', 'item_count', 'subtotal', 'delivery_charge', 'total_amount', 'payment_method', 'payment_status', 'delivery_method']),
        'whatsapp_template_name' => 'ceylon_cues_new_order',
    ],
    [
        'name' => 'whatsapp_bank_transfer_submitted',
        'channel' => 'whatsapp',
        'event_type' => 'payment.bank_transfer_submitted',
        'template_body' => "💳 BANK TRANSFER EVIDENCE SUBMITTED — CEYLON CUES\n\nOrder: {{order_ref}}\nCustomer: {{customer_name}}\nAmount: LKR {{amount}}\nBank: {{bank_name}}\nTransfer Ref: {{transfer_ref}}\n\nPlease verify in ERP > Payments.",
        'variables' => json_encode(['order_ref', 'customer_name', 'amount', 'bank_name', 'transfer_ref']),
        'whatsapp_template_name' => 'ceylon_cues_bank_verification',
    ],
    [
        'name' => 'whatsapp_low_stock_alert',
        'channel' => 'whatsapp',
        'event_type' => 'inventory.low_stock',
        'template_body' => "⚠️ LOW STOCK ALERT — CEYLON CUES\n\nProduct: {{product_name}}\nSKU: {{sku}}\nLocation: {{location_name}}\nCurrent Stock: {{quantity}}\nReorder Level: {{reorder_level}}\n\nPlease review inventory replenishment.",
        'variables' => json_encode(['product_name', 'sku', 'location_name', 'quantity', 'reorder_level']),
        'whatsapp_template_name' => 'ceylon_cues_low_stock',
    ],
    [
        'name' => 'whatsapp_daily_summary',
        'channel' => 'whatsapp',
        'event_type' => 'report.daily_summary',
        'template_body' => "📊 DAILY BUSINESS SUMMARY — CEYLON CUES\n\nDate: {{summary_date}}\nOnline Orders: {{online_orders}} (LKR {{online_revenue}})\nShop POS Sales: {{pos_sales}} (LKR {{pos_revenue}})\nWholesale: {{wholesale_orders}} (LKR {{wholesale_revenue}})\n\nTotal Revenue: LKR {{total_revenue}}\nTotal Expenses: LKR {{total_expenses}}\nNet Daily Cash: LKR {{net_cash}}\nItems Sold: {{items_sold}}",
        'variables' => json_encode(['summary_date', 'online_orders', 'online_revenue', 'pos_sales', 'pos_revenue', 'wholesale_orders', 'wholesale_revenue', 'total_revenue', 'total_expenses', 'net_cash', 'items_sold']),
        'whatsapp_template_name' => 'ceylon_cues_daily_summary',
    ],
];

$stmtTmpl = $db->prepare("INSERT INTO notification_templates (name, channel, event_type, template_body, variables, whatsapp_template_name, is_active)
                          VALUES (:name, :channel, :event_type, :template_body, :variables, :whatsapp_template_name, 1)
                          ON DUPLICATE KEY UPDATE template_body = VALUES(template_body)");
foreach ($templates as $t) {
    $stmtTmpl->execute($t);
}

echo "--- Essential System Seeding Completed Successfully! ---\n";
