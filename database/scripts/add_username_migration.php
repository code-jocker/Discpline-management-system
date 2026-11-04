<?php
require __DIR__ . '/config/db.php';

try {
    $pdo = Database::connection();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'username'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(100) UNIQUE DEFAULT NULL AFTER full_name");
        echo "Added 'username' column.\n";
        
        // Backfill existing users
        $stmt = $pdo->query("SELECT id, email FROM users");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $updateStmt = $pdo->prepare("UPDATE users SET username = :username WHERE id = :id");
        foreach ($users as $user) {
            $username = explode('@', $user['email'])[0];
            // Ensure uniqueness
            $baseUsername = $username;
            $counter = 1;
            while (true) {
                try {
                    $updateStmt->execute(['username' => $username, 'id' => $user['id']]);
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) { // Integrity constraint violation: 1062 Duplicate entry
                        $username = $baseUsername . $counter;
                        $counter++;
                    } else {
                        throw $e;
                    }
                }
            }
        }
        echo "Backfilled 'username' for existing users.\n";
    } else {
        echo "Column 'username' already exists.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
