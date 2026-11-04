<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/app/Services/LetterService.php';
require_once dirname(__DIR__) . '/app/Services/ParentNotificationService.php';

require_auth();
assert_csrf_token($_POST['csrf_token'] ?? null);

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

$settings = new Settings($db);
$term = isset($_POST['term']) ? max(1, min(3, (int) $_POST['term'])) : (int) $settings->get('current_term', 1);
$threshold = max(0, min(40, (int) $settings->get('low_marks_threshold', 20)));

if ((string) $settings->get('low_marks_letter_enabled', '1') !== '1') {
    header('Location: ' . app_base_url() . '/letters/index.php?sync_status=disabled&sync_term=' . $term);
    exit;
}

$letterService = new \App\Services\LetterService($db);
$notificationService = new \App\Services\ParentNotificationService($db);

$result = $letterService->syncLowMarksLettersForTerm($term, $threshold);
$notified = 0;

foreach (($result['letters'] ?? []) as $letter) {
    if (!empty($letter['created']) || !empty($letter['updated'])) {
        $sent = $notificationService->notifyParentsOfLetter(
            (int) ($letter['student_id'] ?? 0),
            (string) ($letter['subject'] ?? 'Parent Invitation Letter'),
            (string) ($letter['body'] ?? '')
        );

        if ($sent) {
            $notified++;
        }
    }
}

$query = http_build_query([
    'sync_status' => 'success',
    'sync_count' => (int) ($result['count'] ?? 0),
    'sync_term' => $term,
    'notified' => $notified,
]);

header('Location: ' . app_base_url() . '/letters/index.php?' . $query);
exit;
