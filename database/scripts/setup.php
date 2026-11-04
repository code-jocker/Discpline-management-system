<?php
// Web-accessible script to reset admin password and create demo users
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['run']) && $_GET['run'] === 'yes') {
    require __DIR__ . '/config/db.php';

    try {
        $pdo = Database::connection();

        // Reset admin password
        $adminEmail = 'alainvainqueurkarangwa@gmail.com';
        $newPassword = password_hash('admin123!', PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $result = $stmt->execute([$newPassword, $adminEmail]);
        echo "Admin password reset: " . ($result ? 'SUCCESS' : 'FAILED') . "<br>\n";

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
                echo "Created: {$user[0]} - " . ($result ? 'SUCCESS' : 'FAILED') . "<br>\n";
            } else {
                echo "Already exists: {$user[0]}<br>\n";
            }
        }

        echo "<br>Done! You can now log in with:<br>";
        echo "Admin: alainvainqueurkarangwa@gmail.com / admin123!<br>";
        echo "Headteacher: headteacher@demo.com / demo123!<br>";
        echo "Teacher: teacher@demo.com / demo123!<br>";
        echo "Deputy Discipline: discipline@demo.com / demo123!<br>";
        echo "IT: itadmin@demo.com / demo123!<br>";

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "<br>\n";
    }
} else {
    echo "<h1>Database Setup</h1>";
    echo "<p>Click the link below to reset admin password and create demo users:</p>";
    echo "<a href='?run=yes'>Run Setup</a>";
}
?>