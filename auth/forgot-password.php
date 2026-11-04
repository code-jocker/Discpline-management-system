<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/auth/AuthController.php';
require_once dirname(__DIR__) . '/models/plexus.php';

start_secure_session();
$authController = new AuthController();
$feedbackMessage = '';
$feedbackType = '';
$feedbackLink = '';
$emailValue = '';

if (is_authenticated()) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    assert_csrf_token($_POST['csrf_token'] ?? null);
    $emailValue = trim((string) ($_POST['email'] ?? ''));
    $response = $authController->forgotPassword($_POST);

    if (is_ajax_request()) {
        json_response($response, http_response_code() ?: 200);
    }

    $feedbackType = $response['status'] === 'success' ? 'success' : 'error';
    $feedbackMessage = (string) ($response['message'] ?? '');
    $feedbackLink = (string) ($response['reset_url'] ?? '');
}
?>
<?php
$pageTitle = 'SBMS Forgot Password';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'auth-shell';
$hideSidebar = true;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/auth.js', 'assets/js/theme.js'];
$settingsModel = new Settings(Database::connection());
$loginLogo = $settingsModel->get('app_logo', 'assets/img/auth-icon.svg');
require_once dirname(__DIR__) . '/includes/header.php';
?>
    <button type="button" onclick="window.toggleTheme()" style="position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: var(--text); padding: 8px 16px; border-radius: 20px; cursor: pointer; z-index: 100; font-family: inherit; font-size: 0.9rem; font-weight: 600; backdrop-filter: blur(10px);">🌓 Toggle Theme</button>

    <main class="auth-shell">
        <section class="auth-panel auth-brand-panel">
            <img src="<?= e(app_base_url()) ?>/<?= e($loginLogo) ?>" alt="SBMS Login Logo" style="width: 84px; height: 84px; margin-bottom: 20px; object-fit: contain;">
            <p class="eyebrow">Account Recovery</p>
            <h1>Reset access for a registered user.</h1>
            <p class="lead">
                Enter the email address associated with the account. If it exists in the system, recovery instructions will be prepared.
            </p>
        </section>

        <section class="auth-panel auth-form-panel">
            <div class="card-header">
                <img src="<?= e(app_base_url()) ?>/<?= e($loginLogo) ?>" alt="SBMS Login Logo" class="logo">
                <div>
                    <p class="eyebrow">Forgot password</p>
                    <h2>Request reset instructions</h2>
                </div>
            </div>

            <form id="forgotPasswordForm" class="auth-form" method="post" novalidate>
                <?= csrf_input() ?>
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" autocomplete="email" value="<?= e($emailValue) ?>" required>
                </div>

                <?php if ($feedbackMessage !== ''): ?>
                    <div class="feedback <?= e($feedbackType) ?>" role="alert" aria-live="polite">
                        <?= e($feedbackMessage) ?>
                        <?php if ($feedbackLink !== ''): ?>
                            <div style="margin-top: 10px;">
                                <a href="<?= e(app_base_url() . $feedbackLink) ?>" style="color: var(--brand-2); font-weight: 700; text-decoration: underline;">Open reset link</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div id="forgotPasswordFeedback" class="feedback" role="alert" aria-live="polite"></div>

                <button type="submit" id="forgotPasswordButton" class="btn-primary" data-label="Send Reset Instructions">
                    <span class="btn-label">Send Reset Instructions</span>
                    <span class="btn-loader" hidden></span>
                </button>
            </form>

            <p class="helper-text">
                <a href="<?= e(app_base_url()) ?>/auth/login.php">Back to sign in</a>
            </p>
        </section>
    </main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>