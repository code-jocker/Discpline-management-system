<?php
require 'c:/xampp/htdocs/sbms/config/db.php';
$db = Database::connection();

// 1 -> Ordinary level
// 2 -> Advanced level
// 3 -> TVET

$db->exec("UPDATE categories SET name = 'Ordinary level' WHERE id = 1");
$db->exec("UPDATE categories SET name = 'Advanced level' WHERE id = 2");

$exists = $db->query("SELECT id FROM categories WHERE name = 'TVET'")->fetchColumn();
if (!$exists) {
    $db->exec("INSERT INTO categories (name) VALUES ('TVET')");
}

echo "Categories updated.\n";
