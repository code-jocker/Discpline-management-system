<?php
// Import auth_support.sql
require __DIR__ . '/../../config/db.php';

try {
    $pdo = Database::connection();

    $sql = file_get_contents(__DIR__ . '/../auth_support.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement) && !str_starts_with($statement, '--')) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "Auth support imported successfully.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>