<?php
require __DIR__ . '/config/db.php';
$pdo = Database::connection();
$stmt = $pdo->query("DESCRIBE users");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo $col['Field'] . ' ' . $col['Type'] . "\n";
}
