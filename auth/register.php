<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/auth/AuthController.php';
require_once dirname(__DIR__) . '/models/plexus.php';

$bootstrapMode = $authController->isBootstrapMode();
$canManageUsers = false;

if (!$bootstrapMode) {
    // If not in bootstrap mode, we check if the current user is an admin.
    // We allow public registration, but we keep this flag to show different UI texts.
    $currentUser = current_auth_user(); 
    $userModel = new User(Database::connection());
    $canManageUsers = !empty($currentUser['id']) && $userModel->hasPermission((int) $currentUser['id'], 'manage_users');
}

$roleOptions = $authController->roles();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    assert_csrf_token($_POST['csrf_token'] ?? null);
    $response = $authController->register($_POST, $bootstrapMode || $canManageUsers);
    json_response($response, http_response_code() ?: 200);
}
?>
<?php
$pageTitle = 'SBMS User Registration';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'auth-shell auth-shell-register';
$hideSidebar = true;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/auth.js', 'assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
    <button type="button" onclick="window.toggleTheme()" style="position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: var(--text); padding: 8px 16px; border-radius: 20px; cursor: pointer; z-index: 100; font-family: inherit; font-size: 0.9rem; font-weight: 600; backdrop-filter: blur(10px);">🌓 Toggle Theme</button>
    <main class="auth-shell auth-shell-register">
        <section class="auth-panel auth-brand-panel">
            <img src="<?= e(app_base_url()) ?>/assets/img/logo.svg" alt="SBMS Logo" style="width: 84px; height: 84px; margin-bottom: 20px;">
            <p class="eyebrow"><?= $bootstrapMode ? 'Initial Setup' : 'Admin Workspace' ?></p>
            <h1><?= $bootstrapMode ? 'Create the first administrator account.' : 'Provision secure user access.' ?></h1>
            <p class="lead">
                <?= $bootstrapMode
                    ? 'This one-time bootstrap flow seeds the first trusted account, then registration becomes admin-controlled.'
                    : 'Every new account inherits the same secure hashing, validation, and audit logging standards as login.' ?>
            </p>
        </section>

        <section class="auth-panel auth-form-panel">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <p class="eyebrow"><?= $bootstrapMode ? 'Bootstrap' : 'Controlled Registration' ?></p>
                    <h2><?= $bootstrapMode ? 'Initial admin setup' : 'Create a user account' ?></h2>
                </div>
                <a href="<?= e(app_base_url()) ?>/auth/login.php" class="ghost-btn" style="font-size: 0.85rem; padding: 8px 16px;">Sign In</a>
            </div>

            <form id="registerForm" class="auth-form" method="post" novalidate>
                <?= csrf_input() ?>
                <div class="form-group">
                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" maxlength="120" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" minlength="12" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" id="confirm_password" name="confirm_password" minlength="12" required>
                </div>

                <?php if ($bootstrapMode): ?>
                    <input type="hidden" name="role_id" value="<?= e((string) ($authController->roles()[0]['id'] ?? '')) ?>">
                <?php else: ?>
                    <div class="form-group">
                        <label for="role_id">Role</label>
                        <select id="role_id" name="role_id" required>
                            <option value="">Select role</option>
                            <?php foreach ($roleOptions as $role): ?>
                                <option value="<?= e((string) $role['id']) ?>"><?= e($role['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div id="registerFeedback" class="feedback" role="alert" aria-live="polite"></div>

                <button type="submit" id="registerButton" class="btn-primary">
                    <span class="btn-label"><?= $bootstrapMode ? 'Create Administrator' : 'Create User' ?></span>
                    <span class="btn-loader" hidden></span>
                </button>
            </form>

            <p class="helper-text">
                <?= $bootstrapMode
                    ? 'After the first administrator is created, this page requires an authenticated admin session. <a href="' . e(app_base_url()) . '/auth/login.php">Sign in here</a>.'
                    : 'Return to the <a href="' . e(app_base_url()) . '/index.php">protected home screen</a> or <a href="' . e(app_base_url()) . '/auth/login.php">sign in</a> with another account.' ?>
            </p>
        </section>
    </main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
