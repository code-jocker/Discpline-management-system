<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';

require_auth();
$user = current_auth_user();

if (empty($user) || empty($user['id'])) {
    header('Location: ' . app_base_url() . '/auth/login.php');
    exit;
}

$db = Database::connection();
$userModel = new User($db);

$roleName = strtolower((string) ($user['role_name'] ?? ''));
$hasReportAccess = $userModel->userHasPrivilegedRole((int) $user['id'])
    || $roleName === 'headteacher'
    || stripos($roleName, 'discipline') !== false;

$is_admin = $userModel->userHasPrivilegedRole((int) $user['id']);
$is_discipline = stripos($roleName, 'discipline') !== false
    || $roleName === 'headteacher'
    || $is_admin;

if (!$hasReportAccess) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}
?>
<?php
$pageTitle = 'Reports - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
            <header class="dashboard-header" style="margin-bottom: 2.5rem;">
                <p class="eyebrow" style="color: var(--brand-2);">Overview</p>
                <h1 style="margin: 0; font-size: 2rem;">System Reports</h1>
            </header>

            <section class="dashboard-grid" style="grid-template-columns: 1fr;">
                <article class="auth-panel dashboard-module-card">
                    <h2>Reports Dashboard</h2>
                    <p class="lead compact-lead">Download consolidated term and yearly discipline reports.</p>
                    
                    <?php if ($is_admin || $is_discipline): ?>
                    <div style="margin-top: 2rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                        <!-- Term Report -->
                        <div style="padding: 1.5rem; border: 1px solid var(--of-line); border-radius: 12px; background: var(--of-page);">
                            <h3 style="margin-bottom: 1rem; color: var(--brand-2);">Term Report</h3>
                            <p style="margin-bottom: 1.5rem; color: var(--of-muted); font-size: 0.9rem;">Download a consolidated CSV report for all students in a specific term.</p>
                            <form method="get" action="<?= e(app_base_url()) ?>/reports/term.php" target="_blank" style="display: flex; flex-wrap: wrap; gap: 0.85rem; align-items: center;">
                                <select name="term" required style="min-width: 120px; padding: 0.7rem 0.85rem; border-radius: 12px; background: var(--of-surface); color: var(--of-ink); border: 1px solid var(--of-line-strong);">
                                    <option value="1">Term 1</option>
                                    <option value="2">Term 2</option>
                                    <option value="3">Term 3</option>
                                </select>
                                <select name="class_id" style="min-width: 180px; padding: 0.7rem 0.85rem; border-radius: 12px; background: var(--of-surface); color: var(--of-ink); border: 1px solid var(--of-line-strong);">
                                    <option value="">All classes</option>
                                    <?php foreach ($db->query('SELECT id, name FROM classes ORDER BY name ASC') as $class): ?>
                                        <option value="<?= e((string) $class['id']) ?>"><?= e($class['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="format" style="min-width: 120px; padding: 0.7rem 0.85rem; border-radius: 12px; background: var(--of-surface); color: var(--of-ink); border: 1px solid var(--of-line-strong);">
                                    <option value="csv">CSV</option>
                                    <option value="pdf">PDF</option>
                                </select>
                                <button type="submit" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.1rem; border-radius: 12px; background: linear-gradient(135deg, var(--brand-1), #16a34a); color: #fff; border: none; cursor: pointer; font-weight: 700; box-shadow: 0 12px 24px rgba(34, 197, 94, 0.18);">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <path d="M7 10l5 5 5-5"></path>
                                        <path d="M12 15V3"></path>
                                    </svg>
                                    Download Term Report
                                </button>
                            </form>
                        </div>

                        <!-- Yearly Report -->
                        <div style="padding: 1.5rem; border: 1px solid var(--of-line); border-radius: 12px; background: var(--of-page);">
                            <h3 style="margin-bottom: 1rem; color: var(--brand-2);">Yearly Report</h3>
                            <p style="margin-bottom: 1.5rem; color: var(--of-muted); font-size: 0.9rem;">Download a consolidated CSV report summarizing the entire academic year.</p>
                            <form method="get" action="<?= e(app_base_url()) ?>/reports/yearly.php" target="_blank" style="display: flex; flex-wrap: wrap; gap: 0.85rem; align-items: center;">
                                <select name="class_id" style="min-width: 220px; padding: 0.7rem 0.85rem; border-radius: 12px; background: var(--of-surface); color: var(--of-ink); border: 1px solid var(--of-line-strong); cursor: pointer;">
                                    <option value="">All classes</option>
                                    <?php foreach ($db->query('SELECT id, name FROM classes ORDER BY name ASC') as $class): ?>
                                        <option value="<?= e((string) $class['id']) ?>"><?= e($class['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="format" style="min-width: 120px; padding: 0.7rem 0.85rem; border-radius: 12px; background: var(--of-surface); color: var(--of-ink); border: 1px solid var(--of-line-strong); cursor: pointer;">
                                    <option value="csv">CSV</option>
                                    <option value="pdf">PDF</option>
                                </select>
                                <button type="submit" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.1rem; border-radius: 12px; background: linear-gradient(135deg, var(--brand-1), #16a34a); color: #fff; border: none; cursor: pointer; font-weight: 700; box-shadow: 0 12px 24px rgba(34, 197, 94, 0.18);">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <path d="M7 10l5 5 5-5"></path>
                                        <path d="M12 15V3"></path>
                                    </svg>
                                    Download Yearly Report
                                </button>
                            </form>
                            <p style="margin-top: 0.75rem; color: var(--of-muted); font-size: 0.85rem;">Choose a class from the dropdown, or leave it on “All classes” to include everything.</p>
                        </div>

                        <!-- High Risk Report -->
                        <div style="padding: 1.5rem; border: 1px solid var(--of-line); border-radius: 12px; background: var(--of-page);">
                            <h3 style="margin-bottom: 1rem; color: #fb7185;">High Risk Report</h3>
                            <p style="margin-bottom: 1.5rem; color: var(--of-muted); font-size: 0.9rem;">Download a consolidated report of all currently active high-risk students.</p>
                            <form method="get" action="<?= e(app_base_url()) ?>/reports/high_risk.php" target="_blank" style="display: flex; flex-wrap: wrap; gap: 0.85rem; align-items: center;">
                                <select name="format" style="min-width: 120px; padding: 0.7rem 0.85rem; border-radius: 12px; background: var(--of-surface); color: var(--of-ink); border: 1px solid var(--of-line-strong);">
                                    <option value="csv">CSV</option>
                                    <option value="pdf">PDF</option>
                                </select>
                                <button type="submit" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.1rem; border-radius: 12px; background: linear-gradient(135deg, #fb7185, #e11d48); color: #fff; border: none; cursor: pointer; font-weight: 700; box-shadow: 0 12px 24px rgba(251, 113, 133, 0.18);">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <path d="M7 10l5 5 5-5"></path>
                                        <path d="M12 15V3"></path>
                                    </svg>
                                    Download High Risk Report
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 2rem; padding: 1.5rem; border: 1px solid var(--of-line); border-radius: 12px; background: var(--of-page);">
                        <h3 style="margin-bottom: 1rem; color: var(--brand-2);">Class Reports</h3>
                        <p style="color: var(--of-muted); font-size: 0.9rem;">Class reports can be downloaded directly from the Student Management module under the "Classes" tab by clicking the "Report" button next to any class.</p>
                        <button type="button" class="primary-btn" style="margin-top: 1rem;" onclick="window.location.href='<?= e(app_base_url()) ?>/students/index.php'">Open Student Management</button>
                    </div>
                </article>
            </section>
        <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
