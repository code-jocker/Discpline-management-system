<?php
require_once __DIR__ . '/config/db.php';

try {
    $db = Database::connection();
    
    // Check if column exists
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $db->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL");
        echo "Successfully added profile_picture column to users table.\n";
    } else {
        echo "profile_picture column already exists.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
