<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/app/Services/LetterService.php';

require_auth();
$user = current_auth_user();

$db = Database::connection();
$userModel = new User($db);

$hasLetterAccess = $userModel->userHasPrivilegedRole((int) $user['id'])
    || strtolower((string) ($user['role_name'] ?? '')) === 'headteacher'
    || stripos((string) ($user['role_name'] ?? ''), 'discipline') !== false;

if (!$hasLetterAccess) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

$id = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
if ($id <= 0) {
    header('Location: ' . app_base_url() . '/letters/index.php');
    exit;
}

$letterService = new \App\Services\LetterService($db);
$letter = $letterService->getById($id);
if ($letter === null) {
    header('Location: ' . app_base_url() . '/letters/index.php');
    exit;
}

$pageTitle = 'Letter Details - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
<style>
@page {
    size: A4;
    margin: 8mm;
}

@media print {
    body {
        background: #fff !important;
        padding: 0;
    }

    .sidebar,
    .sidebar-footer,
    .dashboard-header,
    .btn-primary,
    .btn-secondary,
    .btn-danger,
    .dashboard-shell > .dashboard-header,
    .dashboard-shell .auth-panel > div:first-child,
    .dashboard-shell .auth-panel form {
        display: none !important;
    }

    .dashboard-shell {
        padding: 0 !important;
        max-width: none !important;
    }

    .dashboard-grid {
        display: block !important;
        grid-template-columns: none !important;
        padding: 0 !important;
    }

    .auth-panel {
        background: #fff !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }

    .letter-print-shell {
        padding: 0 !important;
        border: none !important;
        background: #fff !important;
        line-height: 1.45 !important;
        font-size: 11pt !important;
        white-space: normal !important;
        page-break-inside: avoid;
    }
}
</style>
            <header class="dashboard-header" style="margin-bottom: 1.75rem;">
                <p class="eyebrow" style="color: var(--brand-2);">Letters</p>
                <h1 style="margin: 0; font-size: 2rem;">Parent Invitation Letter</h1>
            </header>

            <section class="dashboard-grid" style="grid-template-columns: 1fr;">
                <article class="auth-panel dashboard-module-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom: 1rem;">
                        <div>
                            <h2 style="margin: 0;"><?= e((string) ($letter['full_name'] ?? '')) ?></h2>
                            <p style="margin: 0.35rem 0 0; color: var(--of-muted);"><?= e((string) ($letter['student_code'] ?? '')) ?> · Term <?= (int) ($letter['term'] ?? 1) ?> · Score <?= (int) ($letter['score'] ?? 0) ?>/40 · Source: <?= e((string) ($letter['source'] ?? 'auto')) ?></p>
                        </div>
                        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
                            <button type="button" class="btn-secondary" onclick="window.print()" style="text-decoration:none; display:inline-block;">Print / Save as PDF</button>
                            <a href="<?= e(app_base_url()) ?>/letters/edit.php?id=<?= (int) $letter['id'] ?>" class="btn-secondary" style="text-decoration:none; display:inline-block;">Edit</a>
                            <form method="post" action="<?= e(app_base_url()) ?>/letters/delete.php" style="display:inline-block; margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $letter['id'] ?>">
                                <button type="submit" class="btn-danger" style="border:none; padding:0.65rem 0.9rem; border-radius: 10px; background: #dc2626; color: #fff; cursor:pointer;">Delete</button>
                            </form>
                            <a href="<?= e(app_base_url()) ?>/letters/index.php" class="btn-primary" style="text-decoration:none; display:inline-block;">Back to Letters</a>
                        </div>
                    </div>

                    <div class="letter-print-shell" style="padding: 1.5rem; border: 1px solid var(--of-line); border-radius: 16px; background: #ffffff; color: var(--of-ink); line-height: 1.8; white-space: pre-wrap;">
                        <?= nl2br(e((string) ($letter['body'] ?? ''))) ?>
                    </div>
                </article>
            </section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
