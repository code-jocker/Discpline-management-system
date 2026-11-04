<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Student.php';
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

$letterService = new \App\Services\LetterService($db);
$studentModel = new Student($db);
$students = $studentModel->listForSelection();

$id = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
if ($id <= 0) {
    header('Location: ' . app_base_url() . '/letters/index.php');
    exit;
}

$letter = $letterService->getById($id);
if ($letter === null) {
    header('Location: ' . app_base_url() . '/letters/index.php');
    exit;
}

$errors = [];
$studentId = (int) ($letter['student_id'] ?? 0);
$term = (int) ($letter['term'] ?? 1);
$score = (int) ($letter['score'] ?? 0);
$threshold = (int) ($letter['threshold'] ?? 20);
$subject = (string) ($letter['subject'] ?? 'Parent Invitation Letter');
$body = (string) ($letter['body'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    assert_csrf_token($_POST['csrf_token'] ?? null);

    $studentId = isset($_POST['student_id']) ? max(0, (int) $_POST['student_id']) : 0;
    $term = isset($_POST['term']) ? max(1, min(3, (int) $_POST['term'])) : 1;
    $score = isset($_POST['score']) ? max(0, min(40, (int) $_POST['score'])) : 0;
    $threshold = isset($_POST['threshold']) ? max(0, min(40, (int) $_POST['threshold'])) : 20;
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));

    if ($studentId <= 0) {
        $errors[] = 'Please select a student.';
    }

    if ($subject === '') {
        $errors[] = 'Please provide a subject.';
    }

    if ($body === '') {
        $errors[] = 'Please provide the letter body.';
    }

    if (empty($errors)) {
        $savedId = $letterService->saveLetter(
            $id,
            $studentId,
            $term,
            $score,
            $threshold,
            $subject,
            $body
        );

        if ($savedId === null) {
            $errors[] = 'Unable to save the letter. Please check the input and try again.';
        } else {
            header('Location: ' . app_base_url() . '/letters/view.php?id=' . $savedId);
            exit;
        }
    }
}

$pageTitle = 'Edit Letter - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
            <header class="dashboard-header" style="margin-bottom: 2rem;">
                <p class="eyebrow" style="color: var(--brand-2);">Letters</p>
                <h1 style="margin: 0; font-size: 2rem;">Edit Parent Invitation Letter</h1>
            </header>

            <section class="dashboard-grid" style="grid-template-columns: 1fr;">
                <article class="auth-panel dashboard-module-card">
                    <p class="lead compact-lead">Edit the stored letter and update the saved record.</p>

                    <?php if (!empty($errors)): ?>
                        <div style="margin-bottom: 1rem; padding: 1rem; border: 1px solid #ef4444; border-radius: 12px; background: rgba(254, 205, 211, 0.15); color: #b91c1c;">
                            <strong>Please fix the following issues:</strong>
                            <ul style="margin: 0.75rem 0 0 1.25rem; padding: 0; list-style: disc;">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= e($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(app_base_url()) ?>/letters/edit.php?id=<?= (int) $id ?>" style="display:grid; gap:1.25rem;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                        <label style="display:block;">
                            <span style="display:block; margin-bottom:0.5rem; font-weight:600;">Student</span>
                            <select name="student_id" style="width:100%; padding:0.85rem; border-radius: 12px; border: 1px solid var(--of-line-strong, #cbd5e1); background: var(--of-surface, #ffffff); color: var(--of-ink, #0f172a);">
                                <option value="">Select a student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= (int) $student['id'] ?>" <?= $studentId === (int) $student['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $student['full_name']) ?> — <?= e((string) $student['student_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
                            <label style="display:block;">
                                <span style="display:block; margin-bottom:0.5rem; font-weight:600;">Term</span>
                                <input type="number" name="term" min="1" max="3" value="<?= (int) $term ?>" style="width:100%; padding:0.85rem; border-radius: 12px; border: 1px solid var(--of-line-strong, #cbd5e1); background: var(--of-surface, #ffffff); color: var(--of-ink, #0f172a);">
                            </label>
                            <label style="display:block;">
                                <span style="display:block; margin-bottom:0.5rem; font-weight:600;">Score</span>
                                <input type="number" name="score" min="0" max="40" value="<?= (int) $score ?>" style="width:100%; padding:0.85rem; border-radius: 12px; border: 1px solid var(--of-line-strong, #cbd5e1); background: var(--of-surface, #ffffff); color: var(--of-ink, #0f172a);">
                            </label>
                            <label style="display:block;">
                                <span style="display:block; margin-bottom:0.5rem; font-weight:600;">Threshold</span>
                                <input type="number" name="threshold" min="0" max="40" value="<?= (int) $threshold ?>" style="width:100%; padding:0.85rem; border-radius: 12px; border: 1px solid var(--of-line-strong, #cbd5e1); background: var(--of-surface, #ffffff); color: var(--of-ink, #0f172a);">
                            </label>
                        </div>

                        <label style="display:block;">
                            <span style="display:block; margin-bottom:0.5rem; font-weight:600;">Subject</span>
                            <input type="text" name="subject" value="<?= e($subject) ?>" style="width:100%; padding:0.85rem; border-radius: 12px; border: 1px solid var(--of-line-strong, #cbd5e1); background: var(--of-surface, #ffffff); color: var(--of-ink, #0f172a);">
                        </label>

                        <label style="display:block;">
                            <span style="display:block; margin-bottom:0.5rem; font-weight:600;">Letter body</span>
                            <textarea name="body" rows="12" style="width:100%; min-height: 320px; padding:0.85rem; border-radius: 12px; border: 1px solid var(--of-line-strong, #cbd5e1); background: var(--of-surface, #ffffff); color: var(--of-ink, #0f172a); font-family: inherit;"><?= e($body) ?></textarea>
                        </label>

                        <div style="display:flex; justify-content:flex-end; gap:0.75rem; flex-wrap:wrap;">
                            <a href="<?= e(app_base_url()) ?>/letters/view.php?id=<?= (int) $id ?>" class="btn-secondary" style="text-decoration: none; display: inline-block;">Cancel</a>
                            <button type="submit" class="btn-primary" style="display: inline-block;">Save Changes</button>
                        </div>
                    </form>
                </article>
            </section>
<?php require_once dirname(__DIR__) . '/includes/footer.php';
