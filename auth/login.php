<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/auth/AuthController.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/models/plexus.php';

start_secure_session();
$authController = new AuthController();

if (is_authenticated()) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $storageDir = dirname(__DIR__) . '/storage/ratelimits';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0775, true);
    }
    $key = sha1(($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') . '|' . date('YmdHi'));
    $path = $storageDir . '/' . $key . '.limit';
    $current = file_exists($path) ? (int) file_get_contents($path) : 0;

    if ($current >= 30) {
        json_response(['status' => 'error', 'message' => 'Too many login attempts. Please try again later.'], 429);
        exit;
    }
    file_put_contents($path, (string) ($current + 1), LOCK_EX);

    assert_csrf_token($_POST['csrf_token'] ?? null);
    $response = $authController->login($_POST);
    json_response($response, http_response_code() ?: 200);
}
?>
<?php
$pageTitle = 'Sign in — ' . (string) (new Settings(Database::connection()))->get('school_name', 'SBMS');
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'auth-page';
$hideSidebar = true;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/auth.js', 'assets/js/theme.js'];
$settingsModel = new Settings(Database::connection());
$loginLogo = $settingsModel->get('app_logo', 'assets/img/auth-icon.svg');
$loginSchoolName = (string) $settingsModel->get('school_name', 'SBMS');
$loginSchoolAddress = (string) $settingsModel->get('school_address', '');
$loginSchoolEmail = (string) $settingsModel->get('school_email', '');
$loginSchoolPhone = (string) $settingsModel->get('school_phone', '');
$loginSchoolYear = trim((string) $settingsModel->get('selected_school_year', ''));
$loginTerm = trim((string) $settingsModel->get('current_term', '1'));
require_once dirname(__DIR__) . '/includes/header.php';
?>
    <div class="ob-shell ob">
        <section class="ob-brand">
            <span class="ob-orb ob-orb--one" aria-hidden="true"></span>
            <span class="ob-orb ob-orb--two" aria-hidden="true"></span>
            <span class="ob-orb ob-orb--three" aria-hidden="true"></span>

            <div>
                <div class="ob-logo">
                    <img src="<?= e(app_base_url()) ?>/<?= e($loginLogo) ?>" alt="<?= e($loginSchoolName) ?>">
                </div>
                <p class="ob-eyebrow">Student Behavior Management System</p>
                <h1 class="ob-title">WELCOME</h1>
                <p class="ob-subtitle"><?= e($loginSchoolName) ?></p>
                <p class="ob-lead">
                    A secure institutional workspace for recording behavior incidents, monitoring student
                    conduct, and supporting pastoral care with clear, auditable records.
                </p>

                <ul class="ob-features">
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v6c0 4.4 2.8 8.5 7 9 4.2-.5 7-4.6 7-9V6l-7-3Z"/><path d="M9.5 12.5 11 14l4-4.5"/></svg>
                        <span>Role-based access for teachers, leadership, and administrators</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-7"/></svg>
                        <span>Fast behavior logging with term-by-term reporting</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-4.4-7-10a7 7 0 0 1 14 0c0 5.6-7 10-7 10Z"/><circle cx="12" cy="11" r="2.5"/></svg>
                        <span>Risk insights that support early intervention</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>
                        <span>A complete audit trail for every recorded action</span>
                    </li>
                </ul>

                <div class="ob-meta">
                    <?php if ($loginSchoolYear !== ''): ?>
                        <span class="ob-chip">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                            <?= e($loginSchoolYear) ?>
                        </span>
                    <?php endif; ?>
                    <span class="ob-chip">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        Term <?= e($loginTerm) ?>
                    </span>
                    <?php if ($loginSchoolEmail !== ''): ?>
                        <span class="ob-chip">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="M4 7l8 6 8-6"/></svg>
                            <?= e($loginSchoolEmail) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ob-brand-foot">
                <?php if ($loginSchoolAddress !== ''): ?>
                    <span><?= e($loginSchoolAddress) ?></span>
                    <span class="ob-dot" aria-hidden="true"></span>
                <?php endif; ?>
                <?php if ($loginSchoolPhone !== ''): ?>
                    <span><?= e($loginSchoolPhone) ?></span>
                    <span class="ob-dot" aria-hidden="true"></span>
                <?php endif; ?>
                <span>Audited institutional access</span>
            </div>
        </section>

        <section class="ob-form">
            <div class="ob-form-scroll">
                <div class="ob-form-head">
                    <div>
                        <p class="eyebrow">Secure sign in</p>
                        <h2>Welcome back</h2>
                        <p class="ob-hint">Use the account issued by your school administrator.</p>
                    </div>
                    <button type="button" class="theme-toggle" onclick="window.toggleTheme()" title="Switch color theme" aria-label="Switch color theme">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="M4.9 4.9l1.4 1.4"/><path d="M17.7 17.7l1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M4.9 19.1l1.4-1.4"/><path d="M17.7 6.3l1.4-1.4"/></svg>
                    </button>
                </div>

                <form id="loginForm" class="auth-form" method="post" novalidate>
                    <?= csrf_input() ?>
                    <div class="form-group">
                        <label for="email">Email or username</label>
                        <input type="text" id="email" name="email" autocomplete="username" placeholder="name@school.edu" required>
                    </div>

                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: baseline;">
                            <label for="password">Password</label>
                            <a class="ob-link" href="<?= e(app_base_url()) ?>/auth/forgot-password.php">Forgot password?</a>
                        </div>
                        <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                    </div>

                    <label class="checkbox-row">
                        <input type="checkbox" name="remember_me" value="1">
                        <span>Keep me signed in on this device for 30 days</span>
                    </label>

                    <div id="loginFeedback" class="feedback" role="alert" aria-live="polite"></div>

                    <button type="submit" id="loginButton" class="btn-primary">
                        <span class="btn-label">Sign in to portal</span>
                        <span class="btn-loader" hidden></span>
                    </button>
                </form>

                <details class="mobile-access" id="mobileAccess">
                    <summary>Open this portal on your phone</summary>
                    <div class="qr-body">
                        <div class="qr-frame">
                            <div id="mobileQrCode" style="width: 200px; height: 200px;"></div>
                        </div>
                        <p>Scan the code with your phone camera to open the sign-in page.</p>
                        <p id="qrUrlText"></p>
                        <p><a href="#" id="openQrUrl">Open the link manually</a></p>
                    </div>
                </details>

                <p class="helper-text">
                    Accounts are issued by the school administrator.
                    <?php if ($loginSchoolEmail !== ''): ?>
                        For access, contact <a href="mailto:<?= e($loginSchoolEmail) ?>"><?= e($loginSchoolEmail) ?></a>.
                    <?php else: ?>
                        Contact your administrator for access.
                    <?php endif; ?>
                    <br>
                    <span style="font-size: 0.74rem;">First-time deployment? <a href="<?= e(app_base_url()) ?>/auth/register.php">Create the initial administrator</a>.</span>
                </p>
            </div>

            <script src="<?= e(app_base_url()) ?>/assets/vendor/qrcode.min.js"></script>
            <script>
                (function () {
                    var container = document.getElementById('mobileQrCode');
                    var link = document.getElementById('openQrUrl');
                    var urlText = document.getElementById('qrUrlText');
                    var details = document.getElementById('mobileAccess');
                    if (!container || !link) return;

                    var origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
                    var appBaseUrl = <?php echo json_encode(app_base_url(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    var baseForQr = appBaseUrl && /^https?:\/\//i.test(appBaseUrl)
                        ? appBaseUrl
                        : (origin + (appBaseUrl || ''));
                    var targetUrl = baseForQr.replace(/\/$/, '') + '/auth/login.php';
                    link.href = targetUrl;
                    if (urlText) {
                        urlText.textContent = targetUrl;
                    }

                    var qrBuilt = false;
                    function buildQr() {
                        if (qrBuilt) return;
                        qrBuilt = true;
                        if (window.QRCode) {
                            new QRCode(container, {
                                text: targetUrl,
                                width: 200,
                                height: 200,
                                colorDark: '#1e2a78',
                                colorLight: '#ffffff',
                                correctLevel: QRCode.CorrectLevel.H
                            });
                        } else {
                            container.innerHTML = '<p style="margin: 0; padding-top: 78px; font-size: 0.8rem; color: #64748b;">QR code unavailable</p>';
                        }
                    }

                    if (details) {
                        details.addEventListener('toggle', function () {
                            if (details.open) {
                                buildQr();
                            }
                        });
                        if (details.open) {
                            buildQr();
                        }
                    } else {
                        buildQr();
                    }
                })();
            </script>
        </section>
    </div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
