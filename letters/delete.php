<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/app/Services/LetterService.php';

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

$id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
if ($id <= 0) {
    header('Location: ' . app_base_url() . '/letters/index.php');
    exit;
}

$letterService = new \App\Services\LetterService($db);
$deleted = $letterService->deleteById($id);
if (!$deleted) {
    header('Location: ' . app_base_url() . '/letters/view.php?id=' . $id);
    exit;
}

header('Location: ' . app_base_url() . '/letters/index.php');
exit;
