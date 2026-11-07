<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/app/Services/ParentNotificationService.php';
require_once dirname(__DIR__) . '/app/Services/WhatsAppQueueService.php';

require_auth();
$user = current_auth_user();

$db = Database::connection();
$userModel = new User($db);
$parentService = new \App\Services\ParentNotificationService($db);
$queueService = new \App\Services\WhatsAppQueueService($db);

$canManageParents = $userModel->userHasPrivilegedRole((int) $user['id'])
    || strtolower((string) ($user['role_name'] ?? '')) === 'headteacher'
    || stripos((string) ($user['role_name'] ?? ''), 'discipline') !== false;

if (!$canManageParents) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

$allContacts = $parentService->listParentContacts();
$whatsappContacts = [];
foreach ($allContacts as $contact) {
    $num = trim((string) ($contact['whatsapp_number'] ?? $contact['phone_number'] ?? ''));
    if ($num !== '') {
        $whatsappContacts[] = $contact;
    }
}

$defaultMessage = 'Hello from SBMS. This is a test WhatsApp message queued on ' . date('Y-m-d H:i') . '.';
$selectedContactId = !empty($whatsappContacts) ? (int) $whatsappContacts[0]['id'] : 0;
$messageText = $defaultMessage;
$sendResult = null;
$flash = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    assert_csrf_token($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $messageText = trim((string) ($_POST['message'] ?? '')) ?: $defaultMessage;

    if ($action === 'send_test_whatsapp') {
        $selectedContactId = isset($_POST['contact_id']) && ctype_digit((string) $_POST['contact_id']) ? (int) $_POST['contact_id'] : 0;
        if ($selectedContactId <= 0) {
            $sendResult = ['success' => false, 'message' => 'Please select a parent contact.'];
        } else {
            $sendResult = $parentService->sendWhatsAppTestMessageToContact($selectedContactId, $messageText);
        }
    } elseif ($action === 'send_first_whatsapp') {
        $sendResult = $parentService->sendWhatsAppTestMessageToFirstContact($messageText);
    }

    if ($sendResult !== null) {
        $flash = (string) ($sendResult['message'] ?? 'Done.');
        $flashType = !empty($sendResult['success']) ? 'success' : 'error';
    }
}

$health = $queueService->workerHealth();
$queueAvailable = $queueService->isAvailable();
$queueEnabled = $queueService->isEnabled();
$counts = $queueAvailable ? $queueService->statusCounts() : ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];

$pageTitle = 'WhatsApp Test - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
            <header class="dashboard-header" style="margin-bottom: 1.75rem; display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <div>
                    <p class="eyebrow" style="color: var(--brand-2);">Communication</p>
                    <h1 style="margin: 0; font-size: 2rem;">Test WhatsApp</h1>
                    <p class="lead compact-lead" style="max-width: 820px;">
                        Queue a test message through the WhatsApp Web worker (<code>whatsapp-web.js</code>). Messages are delivered from the linked school phone.
                    </p>
                </div>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <a class="btn-secondary" href="<?= e(app_base_url()) ?>/parents/whatsapp-queue.php">WhatsApp Queue</a>
                    <a class="btn-secondary" href="<?= e(app_base_url()) ?>/parents/index.php">Parent Contacts</a>
                </div>
            </header>

            <?php if ($flash !== ''): ?>
                <div class="alert <?= e($flashType) ?>" style="margin-bottom: 1rem;"><?= e($flash) ?></div>
            <?php endif; ?>

            <?php if (!$queueAvailable): ?>
                <div style="margin-bottom: 1rem; padding: 1rem 1.1rem; border-radius: 14px; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.28); color: #fecaca;">
                    <strong style="display:block; margin-bottom:.35rem;">Queue table not found.</strong>
                    Apply <code>database/migration_whatsapp_queue.sql</code> (or ensure <code>notification_queue</code> exists), then reload.
                </div>
            <?php endif; ?>
            <?php if ($queueAvailable && !$queueEnabled): ?>
                <div style="margin-bottom: 1rem; padding: 1rem 1.1rem; border-radius: 14px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.28); color: #fde68a;">
                    WhatsApp Web is disabled in settings (<code>whatsapp_web_enabled</code>). Enable it to send queued messages.
                </div>
            <?php endif; ?>

            <section class="dashboard-stats" style="margin-bottom: 1.5rem;">
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Worker</p>
                    <strong><?= e($health['reachable'] ? ($health['ready'] ? 'Ready' : 'Linked?') : 'Offline') ?></strong>
                    <span><?= e($health['error'] ? (string) $health['error'] : ($health['reachable'] ? 'Health endpoint reachable' : 'Set WHATSAPP_WORKER_URL')) ?></span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Queue</p>
                    <strong><?= $queueAvailable ? 'Available' : 'Missing' ?></strong>
                    <span>notification_queue table</span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Pending</p>
                    <strong><?= (int) $counts['pending'] ?></strong>
                    <span>Waiting for worker</span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Contacts</p>
                    <strong><?= count($whatsappContacts) ?></strong>
                    <span>With WhatsApp/phone number</span>
                </article>
            </section>

            <section class="dashboard-grid" style="grid-template-columns: 1fr 1fr; align-items: start;">
                <article class="auth-panel dashboard-module-card">
                    <p class="eyebrow">Send test</p>
                    <h2 style="margin-bottom: 0.75rem;">Queue a test message</h2>
                    <?php if (!empty($whatsappContacts)): ?>
                        <form method="post" style="display: grid; gap: 1rem;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="send_test_whatsapp">
                            <div class="field">
                                <label for="contact_id">Parent Contact</label>
                                <select id="contact_id" name="contact_id" required>
                                    <?php foreach ($whatsappContacts as $contact): ?>
                                        <?php $num = (string) ($contact['whatsapp_number'] ?: $contact['phone_number'] ?: ''); ?>
                                        <option value="<?= (int) $contact['id'] ?>" <?= $selectedContactId === (int) $contact['id'] ? 'selected' : '' ?>>
                                            <?= e((string) ($contact['student_name'] ?? 'Unknown')) ?> - <?= e((string) ($contact['parent_name'] ?? 'Parent')) ?> (<?= e($num) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="message">Message</label>
                                <textarea id="message" name="message" rows="4" maxlength="1000" required><?= e($messageText) ?></textarea>
                            </div>
                            <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
                                <button type="submit" class="btn-primary" style="width:auto;" <?= !$queueAvailable ? 'disabled' : '' ?>>Queue test WhatsApp</button>
                            </div>
                        </form>
                        <form method="post" style="margin-top:1rem;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="send_first_whatsapp">
                            <input type="hidden" name="message" value="<?= e($defaultMessage) ?>">
                            <button type="submit" class="btn-secondary" style="width:auto;" <?= !$queueAvailable ? 'disabled' : '' ?>>Queue to first contact</button>
                        </form>
                    <?php else: ?>
                        <div style="padding: 0.9rem; border-radius: 12px; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.22); color: #fde68a;">
                            No parent contacts with WhatsApp/phone numbers found. <a href="<?= e(app_base_url()) ?>/parents/index.php">Add a contact</a>.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="auth-panel dashboard-module-card">
                    <p class="eyebrow">Worker setup</p>
                    <h2 style="margin-bottom: 0.75rem;">How to run the worker</h2>
                    <div style="display:grid; gap:.75rem; color:#e2e8f0; font-size:.92rem;">
                        <p style="margin:0; color:#cbd5e1;">SBMS only enqueues messages. The Node worker sends them via WhatsApp Web.</p>
                        <pre style="margin:0; padding:.85rem; border-radius:10px; background:rgba(15,23,42,.55); white-space:pre-wrap; word-break:break-word;">cd whatsapp-worker
cp .env.example .env   # set DB_* (same as SBMS). For Aiven: DB_SSL=true
npm install
npm start
# scan QR once with the school phone (Linked Devices)</pre>
                        <p style="margin:0; color:#cbd5e1;">Set <code>WHATSAPP_WORKER_URL</code> (e.g. <code>http://localhost:3100</code>) so this page can show worker health. See <code>whatsapp-worker/README.md</code>.</p>
                        <?php if ($health['reachable'] && !empty($health['data'])): ?>
                            <details style="margin-top:.25rem;">
                                <summary style="cursor:pointer; color:#94a3b8;">Worker health payload</summary>
                                <pre style="margin:.5rem 0 0; padding:.75rem; border-radius:10px; background:rgba(15,23,42,.55); overflow:auto; max-height:220px; font-size:.8rem;"><?= e(json_encode($health['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                </article>
            </section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>