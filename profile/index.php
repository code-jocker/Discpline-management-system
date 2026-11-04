<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Settings.php';

require_auth();
$userSession = current_auth_user();

$db = Database::connection();
$userModel = new User($db);

// Fetch fresh user data
$user = $userModel->findById((int) $userSession['id']);
if (!$user) {
    header('Location: ' . app_base_url() . '/auth/logout.php');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($fullName) || empty($username) || empty($email)) {
            $message = 'Full Name, Username, and Email are required.';
            $messageType = 'error';
        } else {
            // Check for duplicate username or email
            $existingUserByUsername = $userModel->findByEmailOrUsername($username);
            $existingUserByEmail = $userModel->findByEmailOrUsername($email);

            if ($existingUserByUsername && $existingUserByUsername['id'] !== $user['id']) {
                $message = 'This username is already taken.';
                $messageType = 'error';
            } elseif ($existingUserByEmail && $existingUserByEmail['id'] !== $user['id']) {
                $message = 'This email is already taken.';
                $messageType = 'error';
            } else {
                if ($userModel->updateProfile((int) $user['id'], $fullName, $username, $email)) {
                    $message = 'Profile updated successfully!';
                    $messageType = 'success';
                    
                    // Profile picture upload
                    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
                        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimeType = finfo_file($fileInfo, $_FILES['profile_picture']['tmp_name']);
                        finfo_close($fileInfo);

                        if (in_array($mimeType, $allowedTypes)) {
                            $extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                            $filename = 'profile_' . $user['id'] . '_' . time() . '.' . $extension;
                            $uploadDir = dirname(__DIR__) . '/uploads/';
                            
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            
                            $destination = $uploadDir . $filename;

                            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
                                $userModel->updateProfilePicture((int)$user['id'], 'uploads/' . $filename);
                            } else {
                                $message = 'Profile updated, but failed to upload picture.';
                                $messageType = 'error';
                            }
                        } else {
                            $message = 'Profile updated, but invalid picture type. Only JPG, PNG, WEBP are allowed.';
                            $messageType = 'error';
                        }
                    }

                    // Update session data if needed
                    $_SESSION['user']['full_name'] = $fullName;
                    $_SESSION['user']['username'] = $username;
                    $_SESSION['user']['email'] = $email;
                    
                    // Refresh current $user object
                    $user = $userModel->findById((int) $user['id']);
                } else {
                    $message = 'Failed to update profile. Please try again.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'update_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $message = 'All password fields are required.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'New password must be at least 8 characters long.';
            $messageType = 'error';
        } else {
            // Verify current password
            $fullUserData = $userModel->findByEmailOrUsername($user['email']);
            if (password_verify($currentPassword, $fullUserData['password_hash'])) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $userModel->updatePasswordHash((int) $user['id'], $hashedPassword);
                $message = 'Password updated successfully!';
                $messageType = 'success';
            } else {
                $message = 'Incorrect current password.';
                $messageType = 'error';
            }
        }
    }
}
?>
<?php
$pageTitle = 'My Profile - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
            <header class="dashboard-header" style="margin-bottom: 2.5rem;">
                <p class="eyebrow" style="color: var(--brand-2);">User Account</p>
                <h1 style="margin: 0; font-size: 2rem;">My Profile</h1>
            </header>

            <?php if ($message): ?>
                <div class="alert <?= e($messageType) ?>">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <!-- Personal Information Card -->
                <div class="settings-card" style="margin-bottom: 0;">
                    <h2>Personal Information</h2>
                    <p style="color: var(--of-muted); margin-bottom: 1.5rem;">Update your account details.</p>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div style="text-align: center; margin-bottom: 1.5rem;">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <img src="<?= e(app_base_url()) ?>/<?= e($user['profile_picture']) ?>" alt="Profile Picture" class="profile-picture-preview">
                            <?php else: ?>
                                <div class="profile-picture-preview" style="background: rgba(255,255,255,0.1); font-size: 2rem; font-weight: bold; color: var(--brand-2);">
                                    <?= e(strtoupper(substr($user['full_name'], 0, 1))) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="profile_picture">Profile Picture (JPG, PNG, WEBP)</label>
                            <input type="file" id="profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                        
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" value="<?= e($user['full_name'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" value="<?= e($user['username'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?= e($user['email'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" value="<?= e($user['role_name'] ?? 'Unknown') ?>" disabled title="Contact an administrator to change your role.">
                        </div>
                        
                        <button type="submit" class="btn-primary">Save Changes</button>
                    </form>
                </div>

                <!-- Security Card -->
                <div class="settings-card" style="margin-bottom: 0;">
                    <h2>Security</h2>
                    <p style="color: var(--of-muted); margin-bottom: 1.5rem;">Change your account password.</p>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="update_password">
                        
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required minlength="8">
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                        </div>
                        
                        <button type="submit" class="btn-primary">Update Password</button>
                    </form>
                </div>
            </div>
        <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
