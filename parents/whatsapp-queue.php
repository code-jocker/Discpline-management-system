<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/app/Services/WhatsAppQueueService.php';

require_auth();

$authUser = current_auth_user();
$db = Database::connection();
$userModel = new User($db);

$canManage = !empty($authUser)
    && ($userModel->userHasPrivilegedRole((int) $authUser['id'])
        || strtolower((string) ($authUser['role_name'] ?? '')) === 'headteacher'
        || stripos((string) ($authUser['role_name'] ?? ''), 'discipline') !== false);

if (!$canManage) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

$queueService = new \App\Services\WhatsAppQueueService($db);
$allowedStatuses = ['pending', 'processing', 'sent', 'failed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    assert_csrf_token($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['queue_action'] ?? '');
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $filter = (string) ($_POST['status_filter'] ?? '');
        $returnStatus = in_array($filter, $allowedStatuses, true) ? '&status=' . $filter : '';

        $ok = false;
        if ($queueId > 0 && $action === 'retry') {
            $ok = $queueService->retry($queueId);
        } elseif ($queueId > 0 && $action === 'cancel') {
            $ok = $queueService->cancel($queueId);
        } elseif ($action === 'retry_failed') {
            $n = $queueService->retryAllFailed();
            header('Location: ' . app_base_url() . '/parents/whatsapp-queue.php?success=' . rawurlencode($n . ' failed job(s) queued for retry.') . $returnStatus);
            exit;
        }

    $message = $ok
        ? ($action === 'retry' ? 'Job returned to the queue.' : 'Job cancelled.')
        : 'That job could not be updated. It may have already moved on.';

    header('Location: ' . app_base_url() . '/parents/whatsapp-queue.php?'
        . ($ok ? 'success=' : 'error=') . rawurlencode($message) . $returnStatus);
    exit;
}

$statusFilter = (string) ($_GET['status'] ?? '');
$statusFilter = in_array($statusFilter, $allowedStatuses, true) ? $statusFilter : '';
$counts = $queueService->statusCounts();
$jobs = $queueService->recent(60, $statusFilter !== '' ? $statusFilter : null);
$health = $queueService->workerHealth();

$optInContacts = 0;
try {
    $stmt = $db->query(
        "SELECT COUNT(*) FROM parent_contacts
          WHERE contact_preference = 'whatsapp'
            AND whatsapp_number IS NOT NULL AND whatsapp_number <> ''"
    );
    $optInContacts = (int) $stmt->fetchColumn();
} catch (Throwable $exception) {
    $optInContacts = 0;
}

$flashSuccess = trim((string) ($_GET['success'] ?? ''));
$flashError = trim((string) ($_GET['error'] ?? ''));
$totalJobs = array_sum($counts);

$pageTitle = 'SBMS WhatsApp Queue';
$bodyClass = 'sbms-plexus-body whatsapp-queue-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';

$statusTone = static function (string $status): string {
    return match ($status) {
        'sent' => 'queue-badge-sent',
        'processing' => 'queue-badge-processing',
        'failed' => 'queue-badge-failed',
        default => 'queue-badge-pending',
    };
};
?>
<style>
    .queue-hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .queue-hero h1 { margin: 0; font-size: 2rem; }
    .queue-worker { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.85rem; border-radius: 999px; font-size: 0.85rem; font-weight: 600; border: 1px solid rgba(148, 163, 184, 0.35); background: rgba(15, 23, 42, 0.35); color: var(--text, #f8fafc); }
    .queue-dot { width: 9px; height: 9px; border-radius: 50%; background: #f59e0b; box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.18); }
    .queue-dot.is-ready { background: #22c55e; box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.18); }
    .queue-dot.is-down { background: #ef4444; box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.18); }
    .queue-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .queue-card { padding: 1.1rem 1.25rem; border-radius: 14px; border: 1px solid var(--panel-border, rgba(148, 163, 184, 0.25)); background: var(--panel, rgba(15, 23, 42, 0.55)); }
    .queue-card p { margin: 0 0 0.4rem; font-size: 0.78rem; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.7; }
    .queue-card strong { font-size: 1.9rem; line-height: 1; }
    .queue-card span { display: block; margin-top: 0.45rem; font-size: 0.8rem; opacity: 0.7; }
    .queue-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .queue-tabs { display: flex; gap: 0.4rem; flex-wrap: wrap; }
    .queue-tab { padding: 0.45rem 0.9rem; border-radius: 999px; border: 1px solid rgba(148, 163, 184, 0.35); color: var(--text, #e2e8f0); text-decoration: none; font-size: 0.82rem; }
    .queue-tab.is-active { background: var(--brand, #f5a33f); border-color: transparent; color: #0b1026; font-weight: 700; }
    .queue-table-wrap { overflow-x: auto; border: 1px solid var(--panel-border, rgba(148, 163, 184, 0.25)); border-radius: 14px; }
    .queue-table { width: 100%; border-collapse: collapse; min-width: 860px; font-size: 0.855rem; }
    .queue-table th, .queue-table td { padding: 0.75rem 0.9rem; text-align: left; border-bottom: 1px solid rgba(148, 163, 184, 0.16); vertical-align: top; }
    .queue-table th { font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.65; }
    .queue-table tbody tr:last-child td { border-bottom: 0; }
    .queue-badge { display: inline-flex; align-items: center; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; text-transform: capitalize; }
    .queue-badge-pending { background: rgba(245, 158, 11, 0.18); color: #fbbf24; }
    .queue-badge-processing { background: rgba(56, 189, 248, 0.18); color: #38bdf8; }
    .queue-badge-sent { background: rgba(34, 197, 94, 0.18); color: #4ade80; }
    .queue-badge-failed { background: rgba(239, 68, 68, 0.18); color: #f87171; }
    .queue-msg { max-width: 320px; opacity: 0.85; }
    .queue-error { color: #fca5a5; font-size: 0.78rem; max-width: 280px; word-break: break-word; }
    .queue-actions { display: flex; gap: 0.35rem; flex-wrap: wrap; }
    .queue-btn { border: 1px solid rgba(148, 163, 184, 0.4); background: transparent; color: inherit; border-radius: 8px; padding: 0.35rem 0.7rem; font-size: 0.78rem; cursor: pointer; min-height: 36px; }
    .queue-btn:hover { border-color: var(--brand, #f5a33f); }
    .queue-empty { padding: 2rem 1rem; text-align: center; opacity: 0.7; }
    .queue-hint { margin-top: 1rem; padding: 1rem 1.1rem; border-radius: 12px; border: 1px dashed rgba(148, 163, 184, 0.35); font-size: 0.85rem; line-height: 1.6; }
    .queue-hint code { background: rgba(15, 23, 42, 0.55); padding: 0.1rem 0.4rem; border-radius: 5px; }
    .queue-flash { margin-bottom: 1rem; padding: 0.8rem 1rem; border-radius: 10px; font-size: 0.9rem; }
    .queue-flash.is-success { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.4); color: #86efac; }
    .queue-flash.is-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; }
    @media (max-width: 767px) {
        .queue-hero h1 { font-size: 1.55rem; }
        .queue-card strong { font-size: 1.5rem; }
    }
</style>

<header class="queue-hero">
    <div>
        <p class="eyebrow" style="color: var(--brand-2, #f5a33f); margin: 0 0 0.35rem;">Parent communication</p>
        <h1>WhatsApp queue</h1>
        <p class="lead compact-lead" style="max-width: 760px; margin: 0.5rem 0 0;">
            Every incident or achievement alert waits here until the school phone comes online, then the
            whatsapp-web.js worker sends it and records the outcome.
        </p>
    </div>
    <div class="queue-worker">
        <span class="queue-dot <?= $health['ready'] ? 'is-ready' : ($health['reachable'] ? '' : 'is-down') ?>"></span>
        <?php if ($health['reachable'] && $health['ready']): ?>
            Worker online
        <?php elseif ($health['reachable']): ?>
            Worker reachable, WhatsApp not linked
        <?php else: ?>
            Worker offline
        <?php endif; ?>
    </div>
</header>

<?php if ($flashSuccess !== ''): ?>
    <div class="queue-flash is-success" role="status"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== ''): ?>
    <div class="queue-flash is-error" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<section class="queue-grid" aria-label="Queue totals">
    <article class="queue-card">
        <p>Pending</p>
        <strong><?= (int) $counts['pending'] ?></strong>
        <span>Waiting for the worker</span>
    </article>
    <article class="queue-card">
        <p>Processing</p>
        <strong><?= (int) $counts['processing'] ?></strong>
        <span>Being sent right now</span>
    </article>
    <article class="queue-card">
        <p>Sent</p>
        <strong><?= (int) $counts['sent'] ?></strong>
        <span>Delivered to WhatsApp</span>
    </article>
    <article class="queue-card">
        <p>Failed</p>
        <strong><?= (int) $counts['failed'] ?></strong>
        <span>Retry or check the number</span>
    </article>
    <article class="queue-card">
        <p>Opted in</p>
        <strong><?= $optInContacts ?></strong>
        <span>Parents using WhatsApp</span>
    </article>
</section>

<div class="queue-toolbar">
        <nav class="queue-tabs" aria-label="Filter queue by status">
            <a class="queue-tab <?= $statusFilter === '' ? 'is-active' : '' ?>" href="<?= e(app_base_url()) ?>/parents/whatsapp-queue.php">All (<?= $totalJobs ?>)</a>
            <?php foreach ($allowedStatuses as $status): ?>
                <a class="queue-tab <?= $statusFilter === $status ? 'is-active' : '' ?>" href="<?= e(app_base_url()) ?>/parents/whatsapp-queue.php?status=<?= e($status) ?>">
                    <?= e(ucfirst($status)) ?> (<?= (int) ($counts[$status] ?? 0) ?>)
                </a>
            <?php endforeach; ?>
        </nav>
        <div style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center;">
            <a class="queue-btn" href="<?= e(app_base_url()) ?>/parents/whatsapp-test.php" style="text-decoration:none; display:inline-flex; align-items:center;">Test WhatsApp</a>
            <form method="post" style="margin:0;">
                <?= csrf_input() ?>
                <input type="hidden" name="queue_action" value="retry_failed">
                <input type="hidden" name="status_filter" value="<?= e($statusFilter) ?>">
                <button type="submit" class="queue-btn">Retry all failed</button>
            </form>
            <a class="queue-btn" href="<?= e(app_base_url()) ?>/parents/whatsapp-queue.php<?= $statusFilter !== '' ? '?status=' . e($statusFilter) : '' ?>" style="text-decoration:none; display:inline-flex; align-items:center;">Refresh</a>
        </div>
</div>

<?php if ($jobs === []): ?>
    <div class="queue-table-wrap">
        <p class="queue-empty">No jobs in this view yet. Queue an alert by recording a discipline action for a student whose parent chose WhatsApp.</p>
    </div>
<?php else: ?>
    <div class="queue-table-wrap">
        <table class="queue-table">
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Student</th>
                    <th scope="col">Parent phone</th>
                    <th scope="col">Message</th>
                    <th scope="col">Status</th>
                    <th scope="col">Attempts</th>
                    <th scope="col">Created</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= (int) $job['id'] ?></td>
                            <td>
                                <strong><?= e((string) ($job['full_name'] ?? '')) ?></strong>
                                <?php if (!empty($job['student_code'])): ?>
                                    <div style="opacity: 0.65; font-size: 0.78rem;">ID <?= e((string) $job['student_code']) ?></div>
                                <?php endif; ?>
                                <?php if (empty($job['full_name'])): ?>
                                    <span style="opacity: 0.6;">Student removed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($job['parent_phone'] ?? $job['recipient_phone'] ?? '')) ?></td>
                        <td>
                            <div class="queue-msg"><?= e((string) $job['message']) ?></div>
                            <?php if (!empty($job['last_error'])): ?>
                                <div class="queue-error"><?= e((string) $job['last_error']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="queue-badge <?= e($statusTone((string) $job['status'])) ?>"><?= e((string) $job['status']) ?></span></td>
                        <td><?= (int) ($job['attempts'] ?? 0) ?> / <?= (int) ($job['max_attempts'] ?? 5) ?></td>
                        <td>
                            <?= e((string) ($job['created_at'] ?? '')) ?>
                            <?php if (!empty($job['sent_at'])): ?>
                                <div style="opacity: 0.65; font-size: 0.78rem;">sent <?= e((string) $job['sent_at']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="queue-actions">
                                <?php if ((string) $job['status'] === 'failed'): ?>
                                    <form method="post" action="<?= e(app_base_url()) ?>/parents/whatsapp-queue.php" style="margin: 0;">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="queue_action" value="retry">
                                        <input type="hidden" name="queue_id" value="<?= (int) $job['id'] ?>">
                                        <input type="hidden" name="status_filter" value="<?= e($statusFilter) ?>">
                                        <button type="submit" class="queue-btn">Retry</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((string) $job['status'] === 'pending'): ?>
                                    <form method="post" action="<?= e(app_base_url()) ?>/parents/whatsapp-queue.php" style="margin: 0;">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="queue_action" value="cancel">
                                        <input type="hidden" name="queue_id" value="<?= (int) $job['id'] ?>">
                                        <input type="hidden" name="status_filter" value="<?= e($statusFilter) ?>">
                                        <button type="submit" class="queue-btn">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<section class="queue-hint">
    <strong>How delivery works.</strong>
    PHP never talks to WhatsApp directly. A discipline action inserts a job here, then the Node service claims it:
    <code>cd whatsapp-service</code>, <code>npm install</code>, <code>npm start</code>. Scan the QR code once with the school phone.
    <?php if (!$health['reachable']): ?>
        <br><span style="color: #fca5a5;">The worker is not reachable at the configured URL<?= !empty($health['error']) ? ' — ' . e((string) $health['error']) : '' ?>.</span>
    <?php elseif (!$health['ready']): ?>
        <br><span style="color: #fbbf24;">The worker is running but WhatsApp is not linked yet; open its console and scan the QR code.</span>
    <?php endif; ?>
    Jobs stay queued while the phone is offline, so nothing is lost.
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
