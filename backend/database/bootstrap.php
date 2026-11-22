<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Secure Owner Bootstrap Script
 *
 * Enforces single-owner architecture.
 * Generates a high-entropy random password if none is provided via CLI.
 * Never stores or displays default passwords in code or documentation.
 *
 * Usage:
 *   php database/bootstrap.php
 *   php database/bootstrap.php --email=owner@ceyloncues.lk --first_name=Owner --last_name=Admin --password=CustomStrongPass123!
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use CeylonCues\Core\Database;
use CeylonCues\Core\Env;
use CeylonCues\Core\Uuid;

Env::load(dirname(__DIR__) . '/.env');

$db = Database::getInstance()->getConnection();

echo "========================================================\n";
echo "       CEYLON CUES — SUPER ADMINISTRATOR PROVISIONING    \n";
echo "========================================================\n\n";

$options = getopt('', ['email:', 'first_name:', 'last_name:', 'phone:', 'password:', 'force']);

$stmt = $db->query("SELECT id, email, first_name, last_name, created_at FROM users WHERE is_owner = 1 OR role = 'owner' LIMIT 1");
$existingOwner = $stmt->fetch();

$isUpdate = false;
if ($existingOwner) {
    if (!isset($options['force'])) {
        echo "[SECURITY NOTICE] Super Administrator account exists: {$existingOwner['email']}\n";
        echo "To re-generate owner credentials, re-run with: php database/bootstrap.php --force\n";
        exit(0);
    }
    $isUpdate = true;
    echo "[INFO] Updating existing owner credentials...\n";
}

$email = $options['email'] ?? ($existingOwner['email'] ?? 'owner@ceyloncues.lk');
$firstName = $options['first_name'] ?? ($existingOwner['first_name'] ?? 'Super');
$lastName = $options['last_name'] ?? ($existingOwner['last_name'] ?? 'Administrator');
$phone = $options['phone'] ?? '+94770000000';

$passwordGenerated = false;
if (!empty($options['password'])) {
    $password = $options['password'];
} else {
    // Generate secure 18-character password: 8 bytes hex + uppercase + special chars
    $raw = bin2hex(random_bytes(8));
    $password = ucfirst($raw) . '!9xZ';
    $passwordGenerated = true;
}

if (strlen($password) < 12) {
    echo "[ERROR] Password must be at least 12 characters long.\n";
    exit(1);
}

$passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 1,
]);

if ($isUpdate) {
    $stmt = $db->prepare("
        UPDATE users 
        SET email = :email, password_hash = :hash, first_name = :fname, last_name = :lname, phone = :phone, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':email' => $email,
        ':hash'  => $passwordHash,
        ':fname' => $firstName,
        ':lname' => $lastName,
        ':phone' => $phone,
        ':id'    => $existingOwner['id'],
    ]);
    $ownerId = (int)$existingOwner['id'];
} else {
    $uuid = Uuid::v4();
    $stmt = $db->prepare("
        INSERT INTO users (
            uuid, email, phone, password_hash, first_name, last_name, role, is_active, is_owner, email_verified_at
        ) VALUES (
            :uuid, :email, :phone, :hash, :fname, :lname, 'owner', 1, 1, NOW()
        )
    ");
    $stmt->execute([
        ':uuid'  => $uuid,
        ':email' => $email,
        ':phone' => $phone,
        ':hash'  => $passwordHash,
        ':fname' => $firstName,
        ':lname' => $lastName,
    ]);
    $ownerId = (int)$db->lastInsertId();
}

echo "✓ Super Administrator provisioned successfully (User ID: {$ownerId}).\n\n";

if ($passwordGenerated) {
    echo "┌──────────────────────────────────────────────────────────────┐\n";
    echo "│ [ATTENTION] GENERATED ONE-TIME SUPER ADMINISTRATOR PASSWORD: │\n";
    echo "├──────────────────────────────────────────────────────────────┤\n";
    echo "│ Email:    " . str_pad($email, 50) . " │\n";
    echo "│ Password: " . str_pad($password, 50) . " │\n";
    echo "├──────────────────────────────────────────────────────────────┤\n";
    echo "│ Store this password in your password manager immediately.     │\n";
    echo "│ It is NOT stored in plain text and will NOT be shown again.  │\n";
    echo "└──────────────────────────────────────────────────────────────┘\n\n";
}
