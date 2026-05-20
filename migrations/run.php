<?php
// Simple migration runner for MetroMart
// Run: php migrations/run.php

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';

$dir = __DIR__;
$files = glob($dir . '/*.sql');
if (!$files) {
    echo "No migration files found in migrations/.\n";
    exit(0);
}

sort($files, SORT_STRING);

$db = db();

// Ensure migrations table
$db->exec("CREATE TABLE IF NOT EXISTS migrations_applied (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;");

$stmt = $db->prepare("SELECT filename FROM migrations_applied WHERE filename = ? LIMIT 1");
$ins  = $db->prepare("INSERT INTO migrations_applied (filename) VALUES (?)");

foreach ($files as $file) {
    $name = basename($file);
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        echo "Skipping already applied: {$name}\n";
        continue;
    }

    echo "Applying: {$name}... ";
    $sql = file_get_contents($file);
    try {
        $db->beginTransaction();

        // Simple split on semicolon for multiple statements
        $parts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
        foreach ($parts as $p) {
            if ($p === '') continue;
            $db->exec($p);
        }

        $ins->execute([$name]);
        $db->commit();
        echo "OK\n";
    } catch (Exception $e) {
        $db->rollBack();
        echo "FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "All migrations processed.\n";
