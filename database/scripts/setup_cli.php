<?php
// CLI script to reset admin password and create demo users
require __DIR__ . '/config/db.php';

try {
    $pdo = Database::connection();

    // Create or reset admin password
    $adminEmail = 'alainvainqueurkarangwa@gmail.com';
    $newPassword = password_hash('admin123!', PASSWORD_DEFAULT);

    // Check if admin exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$adminEmail]);
    if ($stmt->fetchColumn() == 0) {
        // Create admin
        $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role_id, created_at) VALUES (?, ?, ?, ?, NOW())");
        $result = $stmt->execute(['Admin User', $adminEmail, $newPassword, 1]);
        echo "Admin user created: SUCCESS\n";
    } else {
        // Reset password
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $result = $stmt->execute([$newPassword, $adminEmail]);
        echo "Admin password reset: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
    }

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
            $result = $stmt->execute([$user[0], $user[1], $hashedPassword, $user[3]]);
            echo "Created: {$user[0]} - " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
        } else {
            echo "Already exists: {$user[0]}\n";
        }
    }

    echo "Done! You can now log in with:\n";
    echo "Admin: alainvainqueurkarangwa@gmail.com / admin123!\n";
    echo "Headteacher: headteacher@demo.com / demo123!\n";
    echo "Teacher: teacher@demo.com / demo123!\n";
    echo "Deputy Discipline: discipline@demo.com / demo123!\n";
    echo "IT: itadmin@demo.com / demo123!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>