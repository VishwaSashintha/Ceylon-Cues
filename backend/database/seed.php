<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Comprehensive Database Seeder
 *
 * Usage:
 *   php database/seed.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CeylonCues\Core\Database;
use CeylonCues\Core\Env;
use CeylonCues\Core\Uuid;

Env::load(dirname(__DIR__) . '/.env');

$db = Database::getInstance()->getConnection();
echo "--- Seeding Ceylon Cues Database ---\n";

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

$locMap = [];
$res = $db->query("SELECT id, name FROM inventory_locations")->fetchAll();
foreach ($res as $r) {
    $locMap[$r['name']] = (int)$r['id'];
}
$warehouseId = $locMap['Main Warehouse'];
$shopId = $locMap['Physical Retail Shop'];

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
                          VALUES (:name, :districts, :delivery_charge, :free_delivery_threshold, :estimated_days, 1, :sort_order)");
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
$catMap = [];
foreach ($categories as $cat) {
    $uuid = Uuid::v4();
    $stmtCat->execute([
        ':uuid' => $uuid,
        ':name' => $cat['name'],
        ':slug' => $cat['slug'],
        ':description' => $cat['description'],
        ':sort_order' => $cat['sort_order'],
    ]);
}
$resCats = $db->query("SELECT id, slug FROM categories")->fetchAll();
foreach ($resCats as $c) {
    $catMap[$c['slug']] = (int)$c['id'];
}

// 6. Suppliers
echo "6. Seeding Suppliers...\n";
$suppliers = [
    [
        'uuid' => Uuid::v4(),
        'company_name' => 'Predator Cues International Dist.',
        'contact_person' => 'Marcus Vance',
        'email' => 'marcus@predatordist.com',
        'phone' => '+18005550199',
        'city' => 'Jacksonville',
        'country' => 'United States',
        'notes' => 'Authorized distributor for carbon fiber cues and precision accessories'
    ],
    [
        'uuid' => Uuid::v4(),
        'company_name' => 'Kamui Brand Japan (South Asia Hub)',
        'contact_person' => 'Kenji Tanaka',
        'email' => 'kenji@kamuibrand.jp',
        'phone' => '+81665432100',
        'city' => 'Hiroshima',
        'country' => 'Japan',
        'notes' => 'Direct supplier for 0.98 Roku chalk, clear tips, and cue care'
    ],
    [
        'uuid' => Uuid::v4(),
        'company_name' => 'Saluc S.A. (Aramith Belgium)',
        'contact_person' => 'Laurent Dupont',
        'email' => 'laurent@saluc.com',
        'phone' => '+3269778211',
        'city' => 'Callenelle',
        'country' => 'Belgium',
        'notes' => 'World leader in phenolic billiard and snooker ball sets'
    ],
];
$stmtSup = $db->prepare("INSERT INTO suppliers (uuid, company_name, contact_person, email, phone, city, country, notes, is_active)
                         VALUES (:uuid, :company_name, :contact_person, :email, :phone, :city, :country, :notes, 1)");
foreach ($suppliers as $sup) {
    $stmtSup->execute($sup);
}
$supMap = [];
$resSups = $db->query("SELECT id, company_name FROM suppliers")->fetchAll();
foreach ($resSups as $s) {
    $supMap[$s['company_name']] = (int)$s['id'];
}
$predatorSupId = $resSups[0]['id'];
$kamuiSupId = $resSups[1]['id'];
$aramithSupId = $resSups[2]['id'];

// 7. Realistic Sample Products (Clearly prefixed with SAMPLE_ to prevent confusion)
echo "7. Seeding Realistic Sample Products...\n";
$products = [
    [
        'sku' => 'SAMPLE_CC-CUE-001',
        'barcode' => '793573000001',
        'name' => 'Ceylon Pro Maple Pool Cue (19oz)',
        'slug' => 'sample-ceylon-pro-maple-pool-cue-19oz',
        'category_id' => $catMap['cue-sticks'],
        'supplier_id' => $predatorSupId,
        'description' => 'High-grade Canadian Hard Rock Maple cue with Irish linen wrap, stainless steel Uni-Loc joint, and 13mm Tiger Everest tip.',
        'specifications' => json_encode(['Length' => '58 inches', 'Weight' => '19oz', 'Joint' => 'Uni-Loc Quick Release', 'Tip' => '13mm Everest', 'Wrap' => 'Irish Linen']),
        'quality_tier' => 'standard',
        'cost_price' => 28000.00,
        'retail_price' => 45000.00,
        'wholesale_price' => 36000.00,
        'weight_kg' => 0.540,
        'warehouse_qty' => 30,
        'shop_qty' => 10,
        'online_alloc' => 8,
    ],
    [
        'sku' => 'SAMPLE_CC-CUE-002',
        'barcode' => '793573000002',
        'name' => 'Ceylon Revo Carbon Composite Cue (20oz)',
        'slug' => 'sample-ceylon-revo-carbon-composite-cue-20oz',
        'category_id' => $catMap['cue-sticks'],
        'supplier_id' => $predatorSupId,
        'description' => 'Ultra-low deflection aerospace carbon fiber composite shaft with Kamui Black tip. The ultimate weapon for tournament precision.',
        'specifications' => json_encode(['Shaft' => 'Aerospace Carbon Fiber', 'Weight' => '20oz', 'Tip' => 'Kamui Black Clear Soft 12.4mm', 'Ferrule' => 'Titanium Vault Plate']),
        'quality_tier' => 'premium',
        'cost_price' => 82000.00,
        'retail_price' => 135000.00,
        'wholesale_price' => 110000.00,
        'weight_kg' => 0.560,
        'warehouse_qty' => 15,
        'shop_qty' => 4,
        'online_alloc' => 3,
    ],
    [
        'sku' => 'SAMPLE_CC-CUE-003',
        'barcode' => '793573000003',
        'name' => 'Ceylon Club 1-Piece Ramin House Cue 57"',
        'slug' => 'sample-ceylon-club-1-piece-ramin-house-cue-57',
        'category_id' => $catMap['cue-sticks'],
        'supplier_id' => $predatorSupId,
        'description' => 'Sturdy commercial-grade one-piece pool hall cue. Perfect for billiard clubs, hotels, and entertainment lounges.',
        'specifications' => json_encode(['Wood' => 'Kiln Dried Ramin', 'Length' => '57 inches', 'Tip' => 'Screw-on 13mm Tip', 'Construction' => 'One-Piece Solid']),
        'quality_tier' => 'budget',
        'cost_price' => 4500.00,
        'retail_price' => 8500.00,
        'wholesale_price' => 6200.00,
        'weight_kg' => 0.510,
        'warehouse_qty' => 100,
        'shop_qty' => 25,
        'online_alloc' => 20,
    ],
    [
        'sku' => 'SAMPLE_CC-CASE-001',
        'barcode' => '793573000004',
        'name' => 'Ceylon Leather Hard Cue Case (2 Butts x 2 Shafts)',
        'slug' => 'sample-ceylon-leather-hard-cue-case-2x2',
        'category_id' => $catMap['cue-cases'],
        'supplier_id' => $predatorSupId,
        'description' => 'Reinforced tubular interior with moisture barrier, dual zipper utility pockets, and padded shoulder strap.',
        'specifications' => json_encode(['Capacity' => '2 Butts, 2 Shafts', 'Exterior' => 'Full-Grain Weatherproof Leatherette', 'Protection' => 'Molded PVC Tubes']),
        'quality_tier' => 'standard',
        'cost_price' => 11500.00,
        'retail_price' => 19500.00,
        'wholesale_price' => 15000.00,
        'weight_kg' => 1.400,
        'warehouse_qty' => 20,
        'shop_qty' => 6,
        'online_alloc' => 5,
    ],
    [
        'sku' => 'SAMPLE_CC-BALL-001',
        'barcode' => '793573000005',
        'name' => 'Super Aramith Pro TV Tournament Ball Set (2 1/4")',
        'slug' => 'sample-super-aramith-pro-tv-tournament-ball-set',
        'category_id' => $catMap['billiard-balls'],
        'supplier_id' => $aramithSupId,
        'description' => 'World tournament standard pure phenolic resin pool ball set. Vitrified high-density surface prevents burn marks on table cloth.',
        'specifications' => json_encode(['Material' => 'Pure Phenolic Resin', 'Size' => '2 1/4 inch (57.2mm)', 'Standard' => 'WPA Tournament Approved']),
        'quality_tier' => 'premium',
        'cost_price' => 55000.00,
        'retail_price' => 85000.00,
        'wholesale_price' => 72000.00,
        'weight_kg' => 3.200,
        'warehouse_qty' => 12,
        'shop_qty' => 3,
        'online_alloc' => 2,
    ],
    [
        'sku' => 'SAMPLE_CC-CHK-001',
        'barcode' => '793573000006',
        'name' => 'Kamui Roku 0.98 Hexagonal Chalk (2-Piece Pack)',
        'slug' => 'sample-kamui-roku-098-hexagonal-chalk-2pc',
        'category_id' => $catMap['chalk-and-tips'],
        'supplier_id' => $kamuiSupId,
        'description' => 'Revolutionary ultra-fine Japanese chalk engineered to eliminate miscues and maintain maximum grip on English spins.',
        'specifications' => json_encode(['Shape' => 'Hexagonal', 'Quantity' => '2 pieces', 'Made in' => 'Japan', 'Grip Factor' => 'Max 0.98']),
        'quality_tier' => 'premium',
        'cost_price' => 5400.00,
        'retail_price' => 8900.00,
        'wholesale_price' => 7000.00,
        'weight_kg' => 0.080,
        'warehouse_qty' => 50,
        'shop_qty' => 20,
        'online_alloc' => 15,
    ],
    [
        'sku' => 'SAMPLE_CC-TIP-001',
        'barcode' => '793573000007',
        'name' => 'Kamui Clear Black Layered Tip 14mm (Medium)',
        'slug' => 'sample-kamui-clear-black-layered-tip-14mm-m',
        'category_id' => $catMap['chalk-and-tips'],
        'supplier_id' => $kamuiSupId,
        'description' => '10-layer vegetable-tanned pigskin tip with clear backing layer to preserve consistency through hundreds of racks.',
        'specifications' => json_encode(['Layers' => '10 Layers Pigskin', 'Hardness' => 'Medium (M)', 'Diameter' => '14mm', 'Backing' => 'Clear Shield']),
        'quality_tier' => 'premium',
        'cost_price' => 3800.00,
        'retail_price' => 6500.00,
        'wholesale_price' => 5000.00,
        'weight_kg' => 0.010,
        'warehouse_qty' => 80,
        'shop_qty' => 30,
        'online_alloc' => 25,
    ],
    [
        'sku' => 'SAMPLE_CC-TAB-001',
        'barcode' => '793573000008',
        'name' => 'Ceylon Sovereign 9ft Championship Slate Pool Table',
        'slug' => 'sample-ceylon-sovereign-9ft-championship-slate-pool-table',
        'category_id' => $catMap['pool-tables'],
        'supplier_id' => $predatorSupId,
        'description' => 'Commercial grade 3-piece diamond-honed 1-inch Brazilian slate, solid hardwood sub-frame, genuine K66 rubber cushions.',
        'specifications' => json_encode(['Playfield' => '1-inch 3-piece Brazilian Slate', 'Cushions' => 'Tournament K66 profile', 'Finish' => 'Matte Emerald & Rich Walnut']),
        'quality_tier' => 'premium',
        'cost_price' => 620000.00,
        'retail_price' => 950000.00,
        'wholesale_price' => 820000.00,
        'weight_kg' => 450.000,
        'warehouse_qty' => 3,
        'shop_qty' => 1,
        'online_alloc' => 1,
    ],
    [
        'sku' => 'SAMPLE_CC-ACC-001',
        'barcode' => '793573000009',
        'name' => 'Solid Oak 8-Ball Triangle Rack (Tournament Spec)',
        'slug' => 'sample-solid-oak-8-ball-triangle-rack',
        'category_id' => $catMap['table-accessories'],
        'supplier_id' => $predatorSupId,
        'description' => 'Precision-mitered corners with reinforced brass splines for a tight, gap-free rack every single break.',
        'specifications' => json_encode(['Material' => 'Solid White Oak', 'Compatibility' => 'Standard 2 1/4 inch balls', 'Finish' => 'Satin Walnut']),
        'quality_tier' => 'standard',
        'cost_price' => 2400.00,
        'retail_price' => 4500.00,
        'wholesale_price' => 3400.00,
        'weight_kg' => 0.400,
        'warehouse_qty' => 40,
        'shop_qty' => 15,
        'online_alloc' => 10,
    ],
    [
        'sku' => 'SAMPLE_CC-MNT-001',
        'barcode' => '793573000010',
        'name' => 'Ceylon Silk-Touch Microfiber Shaft Slicker & Cleaner',
        'slug' => 'sample-ceylon-silk-touch-microfiber-shaft-slicker',
        'category_id' => $catMap['cue-maintenance'],
        'supplier_id' => $kamuiSupId,
        'description' => 'Dual-textured microfiber polishing cloth with non-abrasive micro-bead interior. Restores silky smooth glide in seconds.',
        'specifications' => json_encode(['Texture' => 'Dual Action Microfiber', 'Washable' => 'Yes', 'Color' => 'Emerald Green with Gold Embroidery']),
        'quality_tier' => 'standard',
        'cost_price' => 950.00,
        'retail_price' => 1950.00,
        'wholesale_price' => 1400.00,
        'weight_kg' => 0.050,
        'warehouse_qty' => 120,
        'shop_qty' => 40,
        'online_alloc' => 30,
    ],
];

$stmtProd = $db->prepare("INSERT INTO products (uuid, sku, barcode, name, slug, category_id, supplier_id, description, specifications, quality_tier, cost_price, retail_price, wholesale_price, weight_kg, is_active, is_published, is_featured, min_wholesale_qty)
                         VALUES (:uuid, :sku, :barcode, :name, :slug, :category_id, :supplier_id, :description, :specifications, :quality_tier, :cost_price, :retail_price, :wholesale_price, :weight_kg, 1, 1, 1, 3)
                         ON DUPLICATE KEY UPDATE retail_price = VALUES(retail_price)");

$stmtStock = $db->prepare("INSERT INTO inventory_stock (product_id, location_id, quantity, reserved_quantity, min_stock_level, reorder_level)
                          VALUES (:product_id, :location_id, :quantity, 0, 5, 10)
                          ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");

$stmtOnline = $db->prepare("INSERT INTO online_stock_allocations (product_id, allocated_quantity, min_online_quantity, is_visible_online, source_location_id)
                           VALUES (:product_id, :allocated_quantity, 1, 1, :source_location_id)
                           ON DUPLICATE KEY UPDATE allocated_quantity = VALUES(allocated_quantity)");

$stmtMovement = $db->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, quantity_before, quantity_after, reference_type, unit_cost, notes)
                             VALUES (:product_id, :location_id, 'receive', :quantity, 0, :quantity_after, 'initial_seed', :unit_cost, 'Initial inventory seed')");

foreach ($products as $p) {
    $uuid = Uuid::v4();
    $stmtProd->execute([
        ':uuid' => $uuid,
        ':sku' => $p['sku'],
        ':barcode' => $p['barcode'],
        ':name' => $p['name'],
        ':slug' => $p['slug'],
        ':category_id' => $p['category_id'],
        ':supplier_id' => $p['supplier_id'],
        ':description' => $p['description'],
        ':specifications' => $p['specifications'],
        ':quality_tier' => $p['quality_tier'],
        ':cost_price' => $p['cost_price'],
        ':retail_price' => $p['retail_price'],
        ':wholesale_price' => $p['wholesale_price'],
        ':weight_kg' => $p['weight_kg'],
    ]);
    
    // Get inserted product id
    $prodId = (int)$db->query("SELECT id FROM products WHERE sku = '{$p['sku']}'")->fetchColumn();
    
    // Warehouse stock
    $stmtStock->execute([
        ':product_id' => $prodId,
        ':location_id' => $warehouseId,
        ':quantity' => $p['warehouse_qty'],
    ]);
    $stmtMovement->execute([
        ':product_id' => $prodId,
        ':location_id' => $warehouseId,
        ':quantity' => $p['warehouse_qty'],
        ':quantity_after' => $p['warehouse_qty'],
        ':unit_cost' => $p['cost_price'],
    ]);

    // Shop stock
    $stmtStock->execute([
        ':product_id' => $prodId,
        ':location_id' => $shopId,
        ':quantity' => $p['shop_qty'],
    ]);
    $stmtMovement->execute([
        ':product_id' => $prodId,
        ':location_id' => $shopId,
        ':quantity' => $p['shop_qty'],
        ':quantity_after' => $p['shop_qty'],
        ':unit_cost' => $p['cost_price'],
    ]);

    // Online stock allocation
    $stmtOnline->execute([
        ':product_id' => $prodId,
        ':allocated_quantity' => $p['online_alloc'],
        ':source_location_id' => $warehouseId,
    ]);
}

// 8. Notification Templates
echo "8. Seeding Notification Templates...\n";
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

// 9. Promotional Discounts
echo "9. Seeding Promotional Coupons...\n";
// Create a system user for created_by or leave null/first user
$systemUserId = 1; // Will match owner when bootstrapped
$stmtDisc = $db->prepare("INSERT INTO discounts (uuid, code, name, type, value, applies_to, min_order_amount, max_discount_amount, max_uses, is_first_order_only, start_date, end_date, is_active, created_by)
                          VALUES (:uuid, :code, :name, :type, :value, 'order', :min_order, :max_discount, 500, :first_order, NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), 1, :created_by)
                          ON DUPLICATE KEY UPDATE value = VALUES(value)");

$userCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($userCount > 0) {
    $firstUserId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    $stmtDisc->execute([
        ':uuid' => Uuid::v4(),
        ':code' => 'WELCOME10',
        ':name' => 'Welcome 10% Discount',
        ':type' => 'percentage',
        ':value' => 10.00,
        ':min_order' => 5000.00,
        ':max_discount' => 5000.00,
        ':first_order' => 1,
        ':created_by' => $firstUserId,
    ]);
    $stmtDisc->execute([
        ':uuid' => Uuid::v4(),
        ':code' => 'CEYLONFREE',
        ':name' => 'Free Delivery on Orders Over 8,000 LKR',
        ':type' => 'free_delivery',
        ':value' => 0.00,
        ':min_order' => 8000.00,
        ':max_discount' => null,
        ':first_order' => 0,
        ':created_by' => $firstUserId,
    ]);
}

echo "--- Seeding Complete Successfully! ---\n";
