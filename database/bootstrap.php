<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Secure Owner Bootstrap Script
 *
 * MUST ONLY be run from the CLI.
 * Strictly enforces single-owner architecture.
 * Refuses if an owner already exists.
 * Never uses hardcoded passwords.
 *
 * Usage:
 *   php database/bootstrap.php
 *   php database/bootstrap.php --email=owner@ceyloncues.lk --first_name=Vishwa --last_name=Owner --phone=+94770000000 --password=Secret123!
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: This script can only be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use CeylonCues\Core\Database;
use CeylonCues\Core\Env;
use CeylonCues\Core\Uuid;

Env::load(dirname(__DIR__) . '/.env');

$db = Database::getInstance()->getConnection();

echo "========================================================\n";
echo "       CEYLON CUES — SUPER ADMINISTRATOR BOOTSTRAP       \n";
echo "            Single Business Owner Provisioning          \n";
echo "========================================================\n\n";

// 1. Verify single owner requirement: Check if owner already exists
$stmt = $db->query("SELECT id, email, first_name, last_name, created_at FROM users WHERE is_owner = 1 OR role = 'owner' LIMIT 1");
$existingOwner = $stmt->fetch();

if ($existingOwner) {
    echo "[SECURITY ERROR] A Super Administrator / Business Owner already exists!\n";
    echo "Owner Email: " . $existingOwner['email'] . "\n";
    echo "Registered At: " . $existingOwner['created_at'] . "\n\n";
    echo "For security, only ONE business owner is permitted in Ceylon Cues.\n";
    echo "Bootstrap operation aborted.\n";
    exit(1);
}

// 2. Parse CLI options or prompt interactively
$options = getopt('', ['email:', 'first_name:', 'last_name:', 'phone:', 'password:']);

function promptInput(string $prompt, bool $isSecret = false): string {
    echo $prompt;
    if ($isSecret && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $input = trim((string)fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $input = trim((string)fgets(STDIN));
    }
    return $input;
}

$email = $options['email'] ?? promptInput("Enter Owner Email Address: ");
$firstName = $options['first_name'] ?? promptInput("Enter Owner First Name: ");
$lastName = $options['last_name'] ?? promptInput("Enter Owner Last Name: ");
$phone = $options['phone'] ?? promptInput("Enter Owner Phone (e.g. +94771234567): ");

if (isset($options['password'])) {
    $password = $options['password'];
} else {
    $password = promptInput("Enter Secure Password (min 8 chars): ", true);
    $confirmPassword = promptInput("Confirm Secure Password: ", true);
    if ($password !== $confirmPassword) {
        echo "[ERROR] Passwords do not match. Bootstrap cancelled.\n";
        exit(1);
    }
}

// 3. Validation
$errors = [];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format.";
}
if (empty($firstName)) {
    $errors[] = "First name is required.";
}
if (empty($lastName)) {
    $errors[] = "Last name is required.";
}
if (empty($phone)) {
    $errors[] = "Phone number is required.";
}
if (strlen($password) < 8) {
    $errors[] = "Password must be at least 8 characters long.";
}

if (!empty($errors)) {
    echo "\n[VALIDATION FAILED]:\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

// 4. Hash password with PASSWORD_ARGON2ID
$passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 1,
]);

$uuid = Uuid::v4();

// 5. Insert Owner Account
$insertStmt = $db->prepare("
    INSERT INTO users (
        uuid, email, phone, password_hash, first_name, last_name, 
        role, is_active, is_owner, email_verified_at, created_at, updated_at
    ) VALUES (
        :uuid, :email, :phone, :password_hash, :first_name, :last_name, 
        'owner', 1, 1, NOW(), NOW(), NOW()
    )
");

$success = $insertStmt->execute([
    ':uuid'          => $uuid,
    ':email'         => strtolower(trim($email)),
    ':phone'         => trim($phone),
    ':password_hash' => $passwordHash,
    ':first_name'    => trim($firstName),
    ':last_name'     => trim($lastName),
]);

if ($success) {
    $ownerId = (int)$db->lastInsertId();
    
    // Update owner_whatsapp business setting
    $db->prepare("UPDATE business_settings SET setting_value = :phone WHERE setting_key = 'owner_whatsapp'")
       ->execute([':phone' => trim($phone)]);

    // Update promotional discounts created_by if they were seeded
    $db->prepare("UPDATE discounts SET created_by = :owner_id WHERE created_by = 1")
       ->execute([':owner_id' => $ownerId]);

    // Record audit log
    $db->prepare("
        INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address)
        VALUES (:user_id, 'owner_bootstrap', 'users', :entity_id, :new_values, 'CLI')
    ")->execute([
        ':user_id'   => $ownerId,
        ':entity_id' => $ownerId,
        ':new_values' => json_encode(['email' => $email, 'role' => 'owner', 'bootstrap' => 'initial']),
    ]);

    echo "\n========================================================\n";
    echo "  SUCCESS: SUPER ADMINISTRATOR ACCOUNT CREATED!        \n";
    echo "========================================================\n";
    echo "Owner ID:       {$ownerId}\n";
    echo "Owner UUID:     {$uuid}\n";
    echo "Owner Name:     {$firstName} {$lastName}\n";
    echo "Owner Email:    {$email}\n";
    echo "Owner Role:     owner (Super Administrator)\n";
    echo "Full Access:    Granted to ALL ERP Modules\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "[ERROR] Failed to insert owner record.\n";
    exit(1);
}
