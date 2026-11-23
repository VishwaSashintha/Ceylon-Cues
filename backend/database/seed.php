<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Master Database Seeder
 *
 * Runs essential configuration first, followed by demonstration seed data.
 *
 * Usage:
 *   php database/seed.php               (Seeds both essential + demo)
 *   php database/seed-essential.php     (Seeds only essential settings)
 *   php database/seed-demo.php          (Seeds only demo sample data)
 */

require_once __DIR__ . '/seed-essential.php';
require_once __DIR__ . '/seed-demo.php';

echo "\n========================================================\n";
echo " Ceylon Cues Database Seeding Finished Successfully!\n";
echo "========================================================\n";
