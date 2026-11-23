<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Demo & Sample Data Seeder
 *
 * Seeds realistic demonstration records clearly labelled with [DEMO] / SAMPLE_:
 *   1. Demo Suppliers
 *   2. Demo Products, Variants, Inventory Stock & Initial Allocations
 *   3. Demo Promotional Coupons & Discounts
 *
 * Usage:
 *   php database/seed-demo.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CeylonCues\Core\Database;
use CeylonCues\Core\Env;
use CeylonCues\Core\Uuid;

Env::load(dirname(__DIR__) . '/.env');

$db = Database::getInstance()->getConnection();
echo "--- Seeding Ceylon Cues Demonstration Data ---\n";

// Ensure essential settings exist first
$whId = (int)$db->query("SELECT id FROM inventory_locations WHERE name = 'Main Warehouse'")->fetchColumn();
$shId = (int)$db->query("SELECT id FROM inventory_locations WHERE name = 'Physical Retail Shop'")->fetchColumn();

if (!$whId || !$shId) {
    echo "Essential locations missing. Running seed-essential.php first...\n";
    require_once __DIR__ . '/seed-essential.php';
    $whId = (int)$db->query("SELECT id FROM inventory_locations WHERE name = 'Main Warehouse'")->fetchColumn();
    $shId = (int)$db->query("SELECT id FROM inventory_locations WHERE name = 'Physical Retail Shop'")->fetchColumn();
}

$catMap = [];
$resCats = $db->query("SELECT id, slug FROM categories")->fetchAll();
foreach ($resCats as $c) {
    $catMap[$c['slug']] = (int)$c['id'];
}

// 1. Demo Suppliers
echo "1. Seeding [DEMO] Suppliers...\n";
$suppliers = [
    [
        'uuid' => Uuid::v4(),
        'company_name' => '[DEMO] Predator Cues International Dist.',
        'contact_person' => 'Marcus Vance',
        'email' => 'marcus@predatordist.com',
        'phone' => '+18005550199',
        'city' => 'Jacksonville',
        'country' => 'United States',
        'notes' => '[DEMO] Authorized distributor for carbon fiber cues and precision accessories'
    ],
    [
        'uuid' => Uuid::v4(),
        'company_name' => '[DEMO] Kamui Brand Japan (South Asia Hub)',
        'contact_person' => 'Kenji Tanaka',
        'email' => 'kenji@kamuibrand.jp',
        'phone' => '+81665432100',
        'city' => 'Hiroshima',
        'country' => 'Japan',
        'notes' => '[DEMO] Direct supplier for 0.98 Roku chalk, clear tips, and cue care'
    ],
    [
        'uuid' => Uuid::v4(),
        'company_name' => '[DEMO] Saluc S.A. (Aramith Belgium)',
        'contact_person' => 'Laurent Dupont',
        'email' => 'laurent@saluc.com',
        'phone' => '+3269778211',
        'city' => 'Callenelle',
        'country' => 'Belgium',
        'notes' => '[DEMO] World leader in phenolic billiard and snooker ball sets'
    ],
];

$stmtSup = $db->prepare("INSERT INTO suppliers (uuid, company_name, contact_person, email, phone, city, country, notes, is_active)
                         VALUES (:uuid, :company_name, :contact_person, :email, :phone, :city, :country, :notes, 1)
                         ON DUPLICATE KEY UPDATE contact_person = VALUES(contact_person)");
foreach ($suppliers as $sup) {
    $stmtSup->execute($sup);
}

$predatorSupId = (int)$db->query("SELECT id FROM suppliers WHERE company_name LIKE '%Predator%' LIMIT 1")->fetchColumn();
$kamuiSupId    = (int)$db->query("SELECT id FROM suppliers WHERE company_name LIKE '%Kamui%' LIMIT 1")->fetchColumn();
$aramithSupId  = (int)$db->query("SELECT id FROM suppliers WHERE company_name LIKE '%Saluc%' LIMIT 1")->fetchColumn();

// 2. Demo Products
echo "2. Seeding [DEMO] Sample Products & Inventory...\n";
$products = [
    [
        'sku' => 'DEMO_CC-CUE-001',
        'barcode' => '793573000001',
        'name' => '[DEMO] Ceylon Pro Maple Pool Cue (19oz)',
        'slug' => 'demo-ceylon-pro-maple-pool-cue-19oz',
        'category_id' => $catMap['cue-sticks'] ?? 1,
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
        'sku' => 'DEMO_CC-CUE-002',
        'barcode' => '793573000002',
        'name' => '[DEMO] Ceylon Revo Carbon Composite Cue (20oz)',
        'slug' => 'demo-ceylon-revo-carbon-composite-cue-20oz',
        'category_id' => $catMap['cue-sticks'] ?? 1,
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
        'sku' => 'DEMO_CC-BALL-001',
        'barcode' => '793573000005',
        'name' => '[DEMO] Super Aramith Pro TV Tournament Ball Set (2 1/4")',
        'slug' => 'demo-super-aramith-pro-tv-tournament-ball-set',
        'category_id' => $catMap['billiard-balls'] ?? 3,
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
        'sku' => 'DEMO_CC-CHK-001',
        'barcode' => '793573000006',
        'name' => '[DEMO] Kamui Roku 0.98 Hexagonal Chalk (2-Piece Pack)',
        'slug' => 'demo-kamui-roku-098-hexagonal-chalk-2pc',
        'category_id' => $catMap['chalk-and-tips'] ?? 6,
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

    $prodId = (int)$db->query("SELECT id FROM products WHERE barcode = '{$p['barcode']}'")->fetchColumn();
    if ($prodId === 0) {
        $prodId = (int)$db->lastInsertId();
    }

    $stmtStock->execute([
        ':product_id' => $prodId,
        ':location_id' => $whId,
        ':quantity' => $p['warehouse_qty'],
    ]);

    $stmtStock->execute([
        ':product_id' => $prodId,
        ':location_id' => $shId,
        ':quantity' => $p['shop_qty'],
    ]);

    $stmtOnline->execute([
        ':product_id' => $prodId,
        ':allocated_quantity' => $p['online_alloc'],
        ':source_location_id' => $whId,
    ]);
}

// 3. Demo Discounts
echo "3. Seeding [DEMO] Coupons...\n";
$firstUserId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn() ?: 1;
$stmtDisc = $db->prepare("INSERT INTO discounts (uuid, code, name, type, value, applies_to, min_order_amount, max_discount_amount, max_uses, is_first_order_only, start_date, end_date, is_active, created_by)
                          VALUES (:uuid, :code, :name, :type, :value, 'order', :min_order, :max_discount, 500, :first_order, NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), 1, :created_by)
                          ON DUPLICATE KEY UPDATE value = VALUES(value)");

$stmtDisc->execute([
    ':uuid' => Uuid::v4(),
    ':code' => 'DEMO10',
    ':name' => '[DEMO] 10% Off Welcome Promotion',
    ':type' => 'percentage',
    ':value' => 10.00,
    ':min_order' => 5000.00,
    ':max_discount' => 5000.00,
    ':first_order' => 0,
    ':created_by' => $firstUserId,
]);

echo "--- Demo Data Seeding Completed Successfully! ---\n";
