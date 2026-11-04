<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/controllers/StudentController.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/app/Support/StudentCard.php';

require_auth();

use App\Repositories\StudentRepository;
use App\Support\StudentCard;

$controller = new StudentController();
$authUser   = current_auth_user();

// The card prints a home address and a parent contact, so it is limited to the
// same role that may open the student directory form.
if (!$controller->canManageStudents($authUser)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Not allowed</title>'
        . '<div style="font:16px/1.6 system-ui;padding:3rem;max-width:34rem;margin:0 auto;">'
        . '<h1 style="font-size:1.2rem;">Not allowed</h1>'
        . '<p>Your role cannot print student identity cards.</p></div>';
    exit;
}

$settings  = new Settings(Database::connection());
$school = [
    'name'    => trim((string) $settings->get('school_name', 'SBMS')),
    'address' => trim((string) $settings->get('school_address', '')),
    'phone'   => trim((string) $settings->get('school_phone', '')),
    'email'   => trim((string) $settings->get('school_email', '')),
    'logo'    => trim((string) $settings->get('app_logo', 'assets/img/logo.svg')),
    'year'    => trim((string) ($settings->get('selected_school_year', '') ?: $settings->get('active_academic_year', ''))),
];

$repository = new StudentRepository(Database::connection());
$students   = [];

$singleId = isset($_GET['student_id']) && ctype_digit((string) $_GET['student_id'])
    ? (int) $_GET['student_id']
    : 0;

if ($singleId > 0) {
    $found = (new Student(Database::connection()))->find($singleId);
    if ($found) {
        $students = [$found];
    }
} elseif (isset($_GET['class_id']) && ctype_digit((string) $_GET['class_id'])) {
    $students = $repository->paginate(['class_id' => (int) $_GET['class_id']], 200, 0);
} elseif (!empty($_GET['ids'])) {
    $ids = array_values(array_filter(array_map(
        static fn ($value): int => (int) trim((string) $value),
        explode(',', (string) $_GET['ids'])
    ), static fn (int $id): bool => $id > 0));

    $model = new Student(Database::connection());
    foreach (array_slice($ids, 0, 120) as $id) {
        $found = $model->find($id);
        if ($found) {
            $students[] = $found;
        }
    }
}

$isEmbed  = isset($_GET['embed']);
$isSingle = count($students) === 1;
$bodyClass = 'card-page' . ($isEmbed ? ' card-embed' : '') . ($isSingle ? ' card-single' : ' card-sheet');
$pageTitle = $isSingle ? 'Student card' : 'Student cards';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_base_url()) ?>/assets/css/student-card-print.css?v=<?= (int) (@filemtime(dirname(__DIR__) . '/assets/css/student-card-print.css') ?: 1) ?>">
    <?php if (!$isEmbed): ?>
        <style>@media print { @page { size: <?= $isSingle ? '85.6mm 54mm; margin: 0' : 'A4 landscape; margin: 8mm' ?>; } }</style>
    <?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
<?php if (!$students): ?>
    <div class="card-empty">No student matched this request. Open the directory and pick a learner first.</div>
<?php else: ?>
    <?php if (!$isEmbed): ?>
        <div class="card-toolbar">
            <h1><?= $isSingle ? htmlspecialchars('Card · ' . (string) $students[0]['full_name']) : htmlspecialchars(count($students) . ' cards') ?></h1>
            <div class="card-toolbar-actions">
                <button type="button" class="card-button" id="cardPrintBtn">Print / Save as PDF</button>
                <a class="card-button is-secondary" href="<?= htmlspecialchars(app_base_url()) ?>/students/index.php">Back to directory</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="card-stage">
        <?php foreach ($students as $student): ?>
            <div class="card-frame"><?= StudentCard::render($student, $school) ?></div>
        <?php endforeach; ?>
    </div>

    <?php if (!$isEmbed): ?>
        <script>
            document.getElementById('cardPrintBtn').addEventListener('click', function () {
                window.print();
            });

            // ?print=1 opens the print dialog straight away, which is what the
            // directory uses when a user taps "Print card" and expects the sheet.
            <?php if (isset($_GET['print'])): ?>
                window.addEventListener('load', function () { window.print(); });
            <?php endif; ?>
        </script>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
