<?php
// Reset admin password and create demo users
require __DIR__ . '/config/db.php';

try {
    $pdo = Database::connection();

    // Reset admin password
    $adminEmail = 'alainvainqueurkarangwa@gmail.com';
    $newPassword = password_hash('admin123!', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
    $stmt->execute([$newPassword, $adminEmail]);

    echo "Admin password reset to: admin123!\n";

    // Create demo users
    $demoUsers = [
        ['Jane Headteacher', 'headteacher@demo.com', 'demo123!', 2],
        ['Bob Teacher', 'teacher@demo.com', 'demo123!', 3],
        ['Alice Discipline', 'discipline@demo.com', 'demo123!', 4],
        ['Charlie IT', 'itadmin@demo.com', 'demo123!', 5],
    ];

    foreach ($demoUsers as $user) {
        // Check if exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$user[1]]);
        if ($stmt->fetchColumn() == 0) {
            $hashedPassword = password_hash($user[2], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$user[0], $user[1], $hashedPassword, $user[3]]);
            echo "Created: {$user[0]}\n";
        } else {
            echo "Already exists: {$user[0]}\n";
        }
    }

    echo "Done!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>