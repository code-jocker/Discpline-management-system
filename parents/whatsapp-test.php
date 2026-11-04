<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/app/Services/ParentNotificationService.php';

require_auth();
$user = current_auth_user();

$db = Database::connection();
$userModel = new User($db);
$settingsModel = new Settings($db);
$parentService = new \App\Services\ParentNotificationService($db);

$canManageParents = $userModel->userHasPrivilegedRole((int) $user['id'])
    || strtolower((string) ($user['role_name'] ?? '')) === 'headteacher'
    || stripos((string) ($user['role_name'] ?? ''), 'discipline') !== false;

if (!$canManageParents) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

function sbms_mask_value(string $value, int $visible = 4): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Not set';
    }

    $length = strlen($value);
    if ($length <= ($visible * 2)) {
        return str_repeat('*', max(4, $length));
    }

    return substr($value, 0, $visible) . str_repeat('*', max(4, $length - ($visible * 2))) . substr($value, -$visible);
}

function sbms_html_status(bool $ok): string
{
    return $ok ? 'Ready' : 'Needs attention';
}

$storedTwilioAccountSid = trim((string) $settingsModel->get('twilio_account_sid', ''));
$storedTwilioAuthToken = trim((string) $settingsModel->get('twilio_auth_token', ''));
$storedTwilioPhoneNumber = trim((string) $settingsModel->get('twilio_phone_number', ''));
$storedTwilioWhatsAppNumber = trim((string) $settingsModel->get('twilio_whatsapp_number', ''));

$twilioAccountSid = trim((string) (getenv('TWILIO_ACCOUNT_SID') ?: $storedTwilioAccountSid));
$twilioAuthToken = trim((string) (getenv('TWILIO_AUTH_TOKEN') ?: $storedTwilioAuthToken));
$twilioPhoneNumber = trim((string) (getenv('TWILIO_PHONE_NUMBER') ?: $storedTwilioPhoneNumber));
$twilioWhatsAppNumber = trim((string) (getenv('TWILIO_WHATSAPP_NUMBER') ?: $storedTwilioWhatsAppNumber));

$allContacts = $parentService->listParentContacts();
$whatsappContacts = [];
$smsContacts = [];
foreach ($allContacts as $contact) {
    if (trim((string) ($contact['whatsapp_number'] ?? '')) !== '') {
        $whatsappContacts[] = $contact;
    }
    if (trim((string) ($contact['phone_number'] ?? '')) !== '') {
        $smsContacts[] = $contact;
    }
}

$defaultWhatsAppMessage = 'Hello from SBMS. This is a test WhatsApp message sent on ' . date('Y-m-d H:i') . '.';
$defaultSmsMessage = 'Hello from SBMS. This is a test SMS message sent on ' . date('Y-m-d H:i') . '.';
$selectedContactId = !empty($whatsappContacts) ? (int) $whatsappContacts[0]['id'] : 0;
$selectedSmsContactId = !empty($smsContacts) ? (int) $smsContacts[0]['id'] : 0;
$whatsappMessage = $defaultWhatsAppMessage;
$smsMessage = $defaultSmsMessage;
$sendResult = null;
$message = '';
$messageType = '';

$checks = [
    [
        'label' => 'Account SID',
        'value' => sbms_mask_value($twilioAccountSid),
        'ok' => $twilioAccountSid !== '',
    ],
    [
        'label' => 'Auth Token',
        'value' => sbms_mask_value($twilioAuthToken),
        'ok' => $twilioAuthToken !== '',
    ],
    [
        'label' => 'SMS Number',
        'value' => $twilioPhoneNumber !== '' ? $twilioPhoneNumber : 'Not set',
        'ok' => $twilioPhoneNumber !== '',
    ],
    [
        'label' => 'WhatsApp Number',
        'value' => $twilioWhatsAppNumber !== '' ? $twilioWhatsAppNumber : 'Not set',
        'ok' => $twilioWhatsAppNumber !== '',
    ],
];

$allConfigured = true;
$missingChecks = [];
foreach ($checks as $check) {
    if (!$check['ok']) {
        $allConfigured = false;
        $missingChecks[] = $check['label'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['send_test_whatsapp', 'send_first_whatsapp', 'send_test_sms'], true)) {
        assert_csrf_token($_POST['csrf_token'] ?? null);
    }

    if ($action === 'send_test_whatsapp') {
        $selectedContactId = isset($_POST['contact_id']) && ctype_digit((string) $_POST['contact_id'])
            ? (int) $_POST['contact_id']
            : 0;
        $whatsappMessage = trim((string) ($_POST['message'] ?? ''));

        if ($selectedContactId <= 0) {
            $sendResult = [
                'success' => false,
                'message' => 'Please select a parent contact with a WhatsApp number.',
            ];
        } elseif (!$allConfigured) {
            $sendResult = [
                'success' => false,
                'message' => 'Complete the Twilio setup before sending a test message.',
            ];
        } else {
            $sendResult = $parentService->sendWhatsAppTestMessageToContact($selectedContactId, $whatsappMessage);
        }

        $sendResult['channel'] = 'whatsapp';
        $sendResult['channel_label'] = 'WhatsApp';
    } elseif ($action === 'send_first_whatsapp') {
        $whatsappMessage = trim((string) ($_POST['message'] ?? ''));

        if (!$allConfigured) {
            $sendResult = [
                'success' => false,
                'message' => 'Complete the Twilio setup before sending a test message.',
            ];
        } else {
            $sendResult = $parentService->sendWhatsAppTestMessageToFirstContact($whatsappMessage);
        }

        $sendResult['channel'] = 'whatsapp';
        $sendResult['channel_label'] = 'WhatsApp';
    } elseif ($action === 'send_test_sms') {
        $selectedSmsContactId = isset($_POST['contact_id']) && ctype_digit((string) $_POST['contact_id'])
            ? (int) $_POST['contact_id']
            : 0;
        $smsMessage = trim((string) ($_POST['message'] ?? ''));

        if ($selectedSmsContactId <= 0) {
            $sendResult = [
                'success' => false,
                'message' => 'Please select a parent contact with a phone number.',
            ];
        } elseif (!$allConfigured) {
            $sendResult = [
                'success' => false,
                'message' => 'Complete the Twilio setup before sending a test message.',
            ];
        } else {
            $sendResult = $parentService->sendSmsTestMessageToContact($selectedSmsContactId, $smsMessage);
        }

        $sendResult['channel'] = 'sms';
        $sendResult['channel_label'] = 'SMS';
    }

    if ($sendResult !== null) {
        $message = $sendResult['message'] ?? 'Notification test completed.';
        $messageType = !empty($sendResult['success']) ? 'success' : 'error';
    }
}

$apiResult = [
    'ok' => false,
    'http_code' => null,
    'message' => 'Twilio API check not run yet.',
    'account_name' => null,
    'response' => null,
];

if ($allConfigured && function_exists('curl_init')) {
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($twilioAccountSid) . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $twilioAccountSid . ':' . $twilioAuthToken);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $apiResult['http_code'] = $httpCode;
    $apiResult['response'] = $response;

    if ($response !== false && $httpCode === 200) {
        $payload = json_decode((string) $response, true);
        $apiResult['ok'] = true;
        $apiResult['message'] = 'Twilio API connection succeeded.';
        $apiResult['account_name'] = is_array($payload) && isset($payload['friendly_name']) ? (string) $payload['friendly_name'] : null;
    } else {
        $apiResult['message'] = $error !== '' ? $error : 'Twilio API connection failed.';
    }
} elseif (!$allConfigured) {
    $apiResult['message'] = 'Missing required settings: ' . implode(', ', $missingChecks) . '.';
} elseif ($allConfigured && !function_exists('curl_init')) {
    $apiResult['message'] = 'cURL is not available on this server.';
}

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
                    <h1 style="margin: 0; font-size: 2rem;">WhatsApp Test</h1>
                    <p class="lead compact-lead" style="max-width: 820px;">
                        Verify that Twilio WhatsApp settings are configured correctly before you send parent alerts.
                    </p>
                </div>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <a class="btn-secondary" href="<?= e(app_base_url()) ?>/parents/index.php">Back to Parent Contacts</a>
                    <a class="btn-secondary" href="<?= e(app_base_url()) ?>/parents/whatsapp-test.php">Run Again</a>
                </div>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert <?= e($messageType) ?>" style="margin-bottom: 1rem;">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <?php if (!$allConfigured): ?>
                <div style="margin-bottom: 1rem; padding: 1rem 1.1rem; border-radius: 14px; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.28); color: #fecaca;">
                    <strong style="display: block; margin-bottom: 0.35rem;">Twilio is not configured yet.</strong>
                    <span>Open <a href="<?= e(app_base_url()) ?>/settings/index.php#messaging-settings" style="color: #fca5a5; text-decoration: underline;">System Settings</a> and fill in the Account SID, Auth Token, SMS Number, and WhatsApp Number.</span>
                </div>
            <?php endif; ?>

            <section class="dashboard-stats" style="margin-bottom: 1.5rem;">
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Configuration</p>
                    <strong><?= e(sbms_html_status($allConfigured)) ?></strong>
                    <span>Required WhatsApp settings</span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">API Check</p>
                    <strong><?= e(sbms_html_status($apiResult['ok'])) ?></strong>
                    <span>Twilio account connectivity</span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Sender</p>
                    <strong><?= e($twilioWhatsAppNumber !== '' ? $twilioWhatsAppNumber : 'Not set') ?></strong>
                    <span>WhatsApp from number</span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Contacts</p>
                    <strong><?= (int) count($whatsappContacts) ?></strong>
                    <span>Parent contacts ready for WhatsApp</span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">SMS Contacts</p>
                    <strong><?= (int) count($smsContacts) ?></strong>
                    <span>Parent contacts ready for SMS</span>
                </article>
            </section>

            <section class="dashboard-grid" style="grid-template-columns: 0.95fr 1.05fr; align-items: start;">
                <article class="auth-panel dashboard-module-card">
                    <p class="eyebrow">Settings</p>
                    <h2 style="margin-bottom: 0.75rem;">Current Twilio Values</h2>
                    <div style="display: grid; gap: 0.85rem; color: #e2e8f0;">
                        <?php foreach ($checks as $check): ?>
                            <div style="padding: 0.8rem 0.95rem; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; background: rgba(255,255,255,0.03);">
                                <strong style="display: block; margin-bottom: 0.25rem;"><?= e($check['label']) ?></strong>
                                <span style="color: <?= $check['ok'] ? '#34d399' : '#f87171' ?>; font-weight: 600;"><?= $check['ok'] ? 'Configured' : 'Missing' ?></span>
                                <div style="margin-top: 0.35rem; color: #94a3b8; word-break: break-word;"><?= e($check['value']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="auth-panel dashboard-module-card">
                    <p class="eyebrow">Send Test</p>
                    <h2 style="margin-bottom: 0.75rem;">Send a real message</h2>
                    <p class="lead compact-lead" style="margin-bottom: 1rem;">Test WhatsApp, SMS, or send straight to the first WhatsApp-ready contact.</p>

                    <div style="display: grid; gap: 1.25rem; margin-bottom: 1.25rem;">
                        <div style="padding: 1rem; border-radius: 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <p class="eyebrow" style="margin-top: 0;">WhatsApp Test</p>
                            <?php if (!empty($whatsappContacts)): ?>
                                <form method="post" style="display: grid; gap: 1rem;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="send_test_whatsapp">

                                    <div class="field">
                                        <label for="contact_id">Parent Contact</label>
                                        <select id="contact_id" name="contact_id" required>
                                            <option value="">Select contact</option>
                                            <?php foreach ($whatsappContacts as $contact): ?>
                                                <option value="<?= (int) $contact['id'] ?>" <?= $selectedContactId === (int) $contact['id'] ? 'selected' : '' ?>>
                                                    <?= e((string) ($contact['student_name'] ?? 'Unknown student')) ?> - <?= e((string) ($contact['parent_name'] ?? 'Unknown parent')) ?> (<?= e((string) ($contact['whatsapp_number'] ?? '')) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="field">
                                        <label for="whatsapp_message">Message</label>
                                        <textarea id="whatsapp_message" name="message" rows="4" maxlength="1000" required><?= e($whatsappMessage !== '' ? $whatsappMessage : $defaultWhatsAppMessage) ?></textarea>
                                    </div>

                                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                        <button type="submit" class="btn-primary" style="width: auto;">Send Test WhatsApp</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div style="padding: 0.9rem; border-radius: 12px; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.22); color: #fde68a;">
                                    No parent contacts with WhatsApp numbers are available yet.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div style="padding: 1rem; border-radius: 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <p class="eyebrow" style="margin-top: 0;">Quick WhatsApp</p>
                            <p class="lead compact-lead" style="margin-bottom: 0.9rem;">Send immediately to the first WhatsApp-ready contact in the directory.</p>
                            <?php if (!empty($whatsappContacts)): ?>
                                <form method="post" style="margin: 0; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="send_first_whatsapp">
                                    <input type="hidden" name="message" value="<?= e($defaultWhatsAppMessage) ?>">
                                    <button type="submit" class="btn-secondary" style="width: auto;">Send to First WhatsApp-Ready Contact</button>
                                </form>
                            <?php else: ?>
                                <div style="color: #94a3b8;">No WhatsApp-ready contacts found.</div>
                            <?php endif; ?>
                        </div>

                        <div style="padding: 1rem; border-radius: 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <p class="eyebrow" style="margin-top: 0;">SMS Test</p>
                            <?php if (!empty($smsContacts)): ?>
                                <form method="post" style="display: grid; gap: 1rem;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="send_test_sms">

                                    <div class="field">
                                        <label for="sms_contact_id">Parent Contact</label>
                                        <select id="sms_contact_id" name="contact_id" required>
                                            <option value="">Select contact</option>
                                            <?php foreach ($smsContacts as $contact): ?>
                                                <option value="<?= (int) $contact['id'] ?>" <?= $selectedSmsContactId === (int) $contact['id'] ? 'selected' : '' ?>>
                                                    <?= e((string) ($contact['student_name'] ?? 'Unknown student')) ?> - <?= e((string) ($contact['parent_name'] ?? 'Unknown parent')) ?> (<?= e((string) ($contact['phone_number'] ?? '')) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="field">
                                        <label for="sms_message">Message</label>
                                        <textarea id="sms_message" name="message" rows="4" maxlength="1000" required><?= e($smsMessage !== '' ? $smsMessage : $defaultSmsMessage) ?></textarea>
                                    </div>

                                    <button type="submit" class="btn-primary" style="width: auto;">Send Test SMS</button>
                                </form>
                            <?php else: ?>
                                <div style="padding: 0.9rem; border-radius: 12px; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.22); color: #fde68a;">
                                    No parent contacts with phone numbers are available yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p class="eyebrow">Result</p>
                    <h2 style="margin-bottom: 0.75rem;">Connectivity Check</h2>
                    <div style="padding: 1rem; border-radius: 14px; border: 1px solid rgba(255,255,255,0.08); background: <?= $apiResult['ok'] ? 'rgba(16,185,129,0.08)' : 'rgba(239,68,68,0.08)' ?>;">
                        <strong style="display: block; margin-bottom: 0.5rem; color: <?= $apiResult['ok'] ? '#86efac' : '#fca5a5' ?>;">
                            <?= e($apiResult['ok'] ? 'WhatsApp setup looks good.' : 'WhatsApp setup needs attention.') ?>
                        </strong>
                        <p style="margin: 0 0 0.75rem 0; color: #e2e8f0;"><?= e($apiResult['message']) ?></p>
                        <?php if (!empty($apiResult['account_name'])): ?>
                            <p style="margin: 0; color: #cbd5e1;">Account name: <?= e((string) $apiResult['account_name']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($apiResult['http_code'])): ?>
                            <p style="margin: 0.4rem 0 0 0; color: #cbd5e1;">HTTP status: <?= (int) $apiResult['http_code'] ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if (!$allConfigured): ?>
                        <div style="margin-top: 1rem; padding: 1rem; border-radius: 14px; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.22); color: #fde68a;">
                            <strong style="display: block; margin-bottom: 0.45rem;">Finish setup first</strong>
                            <p style="margin: 0; color: #e2e8f0;">
                                The API request will run once the missing Twilio values are saved in System Settings.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 1rem; padding: 1rem; border-radius: 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); color: #cbd5e1;">
                        <strong style="display: block; margin-bottom: 0.5rem; color: #e2e8f0;">What this page checks</strong>
                        <ul style="margin: 0; padding-left: 1.2rem; display: grid; gap: 0.35rem;">
                            <li>Twilio account SID and auth token</li>
                            <li>SMS and WhatsApp sender numbers</li>
                            <li>Basic API connectivity to Twilio</li>
                        </ul>
                    </div>

                    <?php if ($sendResult !== null): ?>
                        <div style="margin-top: 1rem; padding: 1rem; border-radius: 14px; background: <?= !empty($sendResult['success']) ? 'rgba(16,185,129,0.08)' : 'rgba(239,68,68,0.08)' ?>; border: 1px solid rgba(255,255,255,0.08);">
                            <strong style="display: block; margin-bottom: 0.45rem; color: <?= !empty($sendResult['success']) ? '#86efac' : '#fca5a5' ?>;">
                                <?= e(!empty($sendResult['success']) ? 'Test message sent.' : 'Test message failed.') ?>
                            </strong>
                            <p style="margin: 0 0 0.45rem 0; color: #e2e8f0;"><?= e((string) ($sendResult['message'] ?? '')) ?></p>
                            <?php if (!empty($sendResult['channel_label'])): ?>
                                <p style="margin: 0; color: #cbd5e1;">Channel: <?= e((string) $sendResult['channel_label']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($sendResult['contact']['parent_name'])): ?>
                                <p style="margin: 0; color: #cbd5e1;">Recipient: <?= e((string) $sendResult['contact']['parent_name']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($sendResult['to'])): ?>
                                <p style="margin: 0.35rem 0 0 0; color: #cbd5e1;">To: <?= e((string) $sendResult['to']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($sendResult['http_code'])): ?>
                                <p style="margin: 0.35rem 0 0 0; color: #cbd5e1;">HTTP status: <?= (int) $sendResult['http_code'] ?></p>
                            <?php endif; ?>
                            <?php if (empty($sendResult['success']) && !empty($sendResult['response'])): ?>
                                <pre style="margin: 0.75rem 0 0 0; white-space: pre-wrap; word-break: break-word; color: #f8fafc; background: rgba(15, 23, 42, 0.55); padding: 0.8rem; border-radius: 10px; overflow-x: auto;"><?= e(substr((string) $sendResult['response'], 0, 600)) ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
