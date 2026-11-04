<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/app/Services/LetterService.php';

require_auth();
$user = current_auth_user();

$db = Database::connection();
$userModel = new User($db);
$settingsModel = new Settings($db);

$hasLetterAccess = $userModel->userHasPrivilegedRole((int) $user['id'])
    || strtolower((string) ($user['role_name'] ?? '')) === 'headteacher'
    || stripos((string) ($user['role_name'] ?? ''), 'discipline') !== false;

if (!$hasLetterAccess) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

$letterService = new \App\Services\LetterService($db);
$letters = $letterService->getAll();
$currentTerm = (int) $settingsModel->get('current_term', 1);
$lowMarksThreshold = max(0, min(40, (int) $settingsModel->get('low_marks_threshold', 20)));
$syncStatus = strtolower((string) ($_GET['sync_status'] ?? ''));
$syncCount = isset($_GET['sync_count']) ? max(0, (int) $_GET['sync_count']) : 0;
$syncTerm = isset($_GET['sync_term']) ? max(1, min(3, (int) $_GET['sync_term'])) : $currentTerm;

$pageTitle = 'Letters - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
            <header class="dashboard-header" style="margin-bottom: 2rem;">
                <div>
                    <p class="eyebrow" style="color: var(--brand-2);">Reports</p>
                    <h1 style="margin: 0; font-size: 2rem;">Letters</h1>
                    <p class="lead compact-lead" style="margin-top: 0.5rem; max-width: 760px;">
                        Manage generated parent letters. Low-marks letters are generated automatically when a student falls below <?= (int) $lowMarksThreshold ?>/40 for the selected term.
                    </p>
                </div>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                    <form method="post" action="<?= e(app_base_url()) ?>/letters/sync.php" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <label style="display:flex; flex-direction:column; gap:0.35rem; color: var(--of-muted); font-size:0.85rem;">
                            <span>Term</span>
                            <select name="term" style="min-width: 120px;">
                                <?php for ($term = 1; $term <= 3; $term++): ?>
                                    <option value="<?= $term ?>" <?= $term === $syncTerm ? 'selected' : '' ?>>Term <?= $term ?></option>
                                <?php endfor; ?>
                            </select>
                        </label>
                        <button type="submit" class="btn-secondary" style="width: auto; text-decoration: none;">Sync Low-Marks Letters</button>
                    </form>
                    <a href="<?= e(app_base_url()) ?>/letters/create.php" class="btn-primary" style="text-decoration: none; display: inline-block;">Create Manual Letter</a>
                </div>
            </header>

            <?php if ($syncStatus === 'success'): ?>
                <div class="alert success" style="margin-bottom: 1rem;">
                    Synced <?= (int) $syncCount ?> low-marks letter(s) for Term <?= (int) $syncTerm ?>.
                </div>
            <?php elseif ($syncStatus === 'disabled'): ?>
                <div class="alert error" style="margin-bottom: 1rem;">
                    Automatic low-marks letters are currently disabled in system settings.
                </div>
            <?php endif; ?>

            <section class="dashboard-grid" style="grid-template-columns: 1fr;">
                <article class="auth-panel dashboard-module-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom: 1rem;">
                        <div>
                            <h2>Generated Parent Letters</h2>
                            <p class="lead compact-lead">Manage automatically generated and manually created parent invitation letters. Open a letter to print or save it as PDF using your browser.</p>
                        </div>
                    </div>
                    <div style="overflow-x:auto; margin-top: 1.5rem;">
                        <table style="width:100%; border-collapse: collapse; color: var(--of-ink);">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--of-line);">
                                    <th style="padding: 0.9rem 0.8rem; text-align:left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--of-muted);">Student</th>
                                    <th style="padding: 0.9rem 0.8rem; text-align:left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--of-muted);">Class</th>
                                    <th style="padding: 0.9rem 0.8rem; text-align:left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--of-muted);">Term</th>
                                    <th style="padding: 0.9rem 0.8rem; text-align:left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--of-muted);">Score</th>
                                    <th style="padding: 0.9rem 0.8rem; text-align:left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--of-muted);">Generated</th>
                                    <th style="padding: 0.9rem 0.8rem; text-align:left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--of-muted);">Source</th>
                                    <th style="padding: 0.9rem 0.8rem; text-align:left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--of-muted);">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($letters)): ?>
                                    <tr>
                                        <td colspan="7" style="padding: 1.5rem 0.8rem; color: var(--of-muted);">No letters have been generated yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($letters as $letter): ?>
                                        <tr style="border-bottom: 1px solid var(--of-line);">
                                            <td style="padding: 0.9rem 0.8rem;">
                                                <strong><?= e((string) ($letter['full_name'] ?? '')) ?></strong><br>
                                                <span style="color: var(--of-muted); font-size: 0.9rem;"><?= e((string) ($letter['student_code'] ?? '')) ?></span>
                                            </td>
                                            <td style="padding: 0.9rem 0.8rem;"><?= e((string) ($letter['class_name'] ?? '')) ?></td>
                                            <td style="padding: 0.9rem 0.8rem;">Term <?= (int) ($letter['term'] ?? 1) ?></td>
                                            <td style="padding: 0.9rem 0.8rem;"><?= (int) ($letter['score'] ?? 0) ?>/40</td>
                                            <td style="padding: 0.9rem 0.8rem;"><?= e((string) ($letter['generated_at'] ?? '')) ?></td>
                                            <td style="padding: 0.9rem 0.8rem; text-transform: capitalize;"><?= e((string) ($letter['source'] ?? 'auto')) ?></td>
                                            <td style="padding: 0.9rem 0.8rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                                                <a href="<?= e(app_base_url()) ?>/letters/view.php?id=<?= (int) $letter['id'] ?>" class="btn-primary" style="text-decoration: none; display: inline-block;">View</a>
                                                <a href="<?= e(app_base_url()) ?>/letters/edit.php?id=<?= (int) $letter['id'] ?>" class="btn-secondary" style="text-decoration: none; display: inline-block;">Edit</a>
                                                <form method="post" action="<?= e(app_base_url()) ?>/letters/delete.php" style="display:inline-block; margin:0;">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $letter['id'] ?>">
                                                    <button type="submit" class="btn-danger" style="border:none; padding:0.65rem 0.9rem; border-radius: 10px; background: #dc2626; color: #fff; cursor:pointer;">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
