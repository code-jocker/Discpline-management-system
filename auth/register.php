<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/auth/AuthController.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/models/plexus.php';

$bootstrapMode = $authController->isBootstrapMode();
$canManageUsers = false;

if (!$bootstrapMode) {
    // Public registration stays available, but admin sessions get the management wording.
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
$registerSettings = new Settings(Database::connection());
$registerSchoolName = (string) $registerSettings->get('school_name', 'SBMS');
$registerLogo = $registerSettings->get('app_logo', 'assets/img/auth-icon.svg');
$registerSchoolYear = trim((string) $registerSettings->get('selected_school_year', ''));
$registerTerm = trim((string) $registerSettings->get('current_term', '1'));

$pageTitle = 'Create account — ' . $registerSchoolName;
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'auth-page';
$hideSidebar = true;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/auth.js', 'assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
    <div class="ob-shell ob">
        <section class="ob-brand">
            <span class="ob-orb ob-orb--one" aria-hidden="true"></span>
            <span class="ob-orb ob-orb--two" aria-hidden="true"></span>
            <span class="ob-orb ob-orb--three" aria-hidden="true"></span>

            <div>
                <div class="ob-logo">
                    <img src="<?= e(app_base_url()) ?>/<?= e($registerLogo) ?>" alt="<?= e($registerSchoolName) ?>">
                </div>
                <p class="ob-eyebrow"><?= $bootstrapMode ? 'Initial setup' : 'Admin workspace' ?></p>
                <h1 class="ob-title">JOIN US</h1>
                <p class="ob-subtitle"><?= e($registerSchoolName) ?></p>
                <p class="ob-lead">
                    <?= $bootstrapMode
                        ? 'Create the first trusted administrator account. This one-time bootstrap flow then hands registration control to the school administration.'
                        : 'Every new account inherits the same secure hashing, validation, audit logging, and role-based permissions as the rest of the platform.' ?>
                </p>

                <ul class="ob-features">
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v6c0 4.4 2.8 8.5 7 9 4.2-.5 7-4.6 7-9V6l-7-3Z"/><path d="M9.5 12.5 11 14l4-4.5"/></svg>
                        <span>Hashed credentials and role-based access</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"/><circle cx="10" cy="7" r="3"/><path d="M20 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span>One account per staff member, fully auditable</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-7"/></svg>
                        <span>Immediate access to the student behavior workspace</span>
                    </li>
                </ul>

                <div class="ob-meta">
                    <?php if ($registerSchoolYear !== ''): ?>
                        <span class="ob-chip">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                            <?= e($registerSchoolYear) ?>
                        </span>
                    <?php endif; ?>
                    <span class="ob-chip">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        Term <?= e($registerTerm) ?>
                    </span>
                </div>
            </div>

            <div class="ob-brand-foot">
                <span><?= $bootstrapMode ? 'One-time bootstrap' : 'Administrator-controlled' ?></span>
                <span class="ob-dot" aria-hidden="true"></span>
                <span>Audited institutional access</span>
            </div>
        </section>

        <section class="ob-form">
            <div class="ob-form-scroll">
                <div class="ob-form-head">
                    <div>
                        <p class="eyebrow"><?= $bootstrapMode ? 'Bootstrap' : 'Controlled registration' ?></p>
                        <h2><?= $bootstrapMode ? 'Initial admin setup' : 'Create account' ?></h2>
                        <p class="ob-hint"><?= $bootstrapMode ? 'This account becomes the first administrator.' : 'Fill in the staff member details below.' ?></p>
                    </div>
                    <button type="button" class="theme-toggle" onclick="window.toggleTheme()" title="Switch color theme" aria-label="Switch color theme">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="M4.9 4.9l1.4 1.4"/><path d="M17.7 17.7l1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M4.9 19.1l1.4-1.4"/><path d="M17.7 6.3l1.4-1.4"/></svg>
                    </button>
                </div>

                <form id="registerForm" class="auth-form" method="post" novalidate>
                    <?= csrf_input() ?>
                    <div class="form-group">
                        <label for="full_name">Full name</label>
                        <input type="text" id="full_name" name="full_name" maxlength="100" placeholder="e.g. Aline Uwase" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" maxlength="120" placeholder="name@school.edu" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" minlength="12" placeholder="At least 12 characters" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm password</label>
                        <input type="password" id="confirm_password" name="confirm_password" minlength="12" placeholder="Repeat the password" required>
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
            </div>
        </section>
    </div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
