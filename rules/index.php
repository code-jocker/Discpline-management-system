<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';

function rules_db_connection(): PDO
{
    $host = getenv('SBMS_DB_HOST') ?: '127.0.0.1';
    $port = getenv('SBMS_DB_PORT') ?: '3306';
    $database = getenv('SBMS_DB_NAME') ?: 'sbms';
    $username = getenv('SBMS_DB_USER') ?: 'root';
    $password = getenv('SBMS_DB_PASS') ?: '';
    $charset = getenv('SBMS_DB_CHARSET') ?: 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function rules_get_setting(PDO $db, string $key, mixed $default = null): mixed
{
    $statement = $db->prepare('SELECT `value` FROM settings WHERE `key_name` = :key LIMIT 1');
    $statement->execute(['key' => $key]);
    $value = $statement->fetchColumn();

    return $value !== false ? $value : $default;
}

function rules_update_settings(PDO $db, array $data): void
{
    $statement = $db->prepare(
        'INSERT INTO settings (`key_name`, `value`)
         VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    );

    foreach ($data as $key => $value) {
        $statement->execute([
            'key' => (string) $key,
            'value' => (string) $value,
        ]);
    }
}

$db = rules_db_connection();
$currentUserId = current_user_id();
$isAdmin = false;

if ($currentUserId !== null) {
    $statement = $db->prepare(
        'SELECT r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $currentUserId]);
    $currentUser = $statement->fetch();
    $isAdmin = $currentUser ? in_array(strtolower($currentUser['role_name'] ?? ''), ['headteacher', 'admin', 'it'], true) : false;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    if (($_POST['action'] ?? '') === 'upload_rules') {
        assert_csrf_token($_POST['csrf_token'] ?? null);

        if (isset($_FILES['rules_doc']) && $_FILES['rules_doc']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/pdf',
            ];

            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($fileInfo, $_FILES['rules_doc']['tmp_name']);
            finfo_close($fileInfo);

            if (in_array($mimeType, $allowedTypes, true)) {
                $extension = pathinfo($_FILES['rules_doc']['name'], PATHINFO_EXTENSION);
                if ($extension === '') {
                    if ($mimeType === 'application/pdf') {
                        $extension = 'pdf';
                    } elseif ($mimeType === 'application/msword') {
                        $extension = 'doc';
                    } else {
                        $extension = 'docx';
                    }
                }

                $filename = 'rules_' . time() . '.' . $extension;
                $uploadDir = dirname(__DIR__) . '/uploads/documents/';

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $destination = $uploadDir . $filename;

                if (move_uploaded_file($_FILES['rules_doc']['tmp_name'], $destination)) {
                    rules_update_settings($db, [
                        'rules_document_path' => 'uploads/documents/' . $filename,
                        'rules_document_name' => $_FILES['rules_doc']['name'],
                    ]);
                    $message = 'Rules & Regulations document uploaded successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to move uploaded file.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Invalid file type. Please upload a Word Document (.doc, .docx) or PDF.';
                $messageType = 'error';
            }
        } else {
            $message = 'Please select a valid file to upload.';
            $messageType = 'error';
        }
    }
}

$rulesPath = rules_get_setting($db, 'rules_document_path');
$rulesName = rules_get_setting($db, 'rules_document_name', 'Rules_and_Regulations.docx');
$rulesFilePath = $rulesPath ? dirname(__DIR__) . '/' . $rulesPath : null;
$rulesExtension = $rulesPath ? strtolower(pathinfo((string) $rulesPath, PATHINFO_EXTENSION)) : '';

$pageTitle = 'Rules & Regulations';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-content';
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
<style>
    #rules-docx-reader table {
        width: 100%;
        border-collapse: collapse;
        margin: 1.25rem 0 1.75rem;
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.98);
        color: #0f172a;
    }

    #rules-docx-reader tr:nth-child(even) {
        background: rgba(15, 23, 42, 0.03);
    }

    #rules-docx-reader td,
    #rules-docx-reader th {
        border: 1px solid rgba(148, 163, 184, 0.22);
        padding: 0.85rem 1rem;
        vertical-align: top;
        text-align: left;
    }

    #rules-docx-reader th,
    #rules-docx-reader tr:first-child td {
        background: linear-gradient(180deg, rgba(226, 232, 240, 0.95), rgba(241, 245, 249, 0.95));
        font-weight: 700;
    }

    #rules-docx-reader p {
        margin: 0 0 0.85rem;
    }

    #rules-docx-reader ul,
    #rules-docx-reader ol {
        margin: 0 0 1rem 1.25rem;
        padding-left: 1rem;
    }
</style>
<header style="margin-bottom: 24px;">
    <p class="eyebrow"><?= e(__('dashboard')) ?></p>
    <h1><?= e(__('rules_and_regulations')) ?></h1>
    <p class="lead compact-lead" style="max-width: 70ch;">
        Read the current school rules and regulations directly on this page. No download is required.
    </p>
</header>

<?php if ($message): ?>
    <div class="feedback <?= e($messageType) ?>" style="margin-bottom: 20px; padding: 15px; border-radius: 8px; background: <?= $messageType === 'success' ? 'rgba(52, 211, 153, 0.1)' : 'rgba(239, 68, 68, 0.1)' ?>; border: 1px solid <?= $messageType === 'success' ? 'var(--success)' : 'var(--danger)' ?>;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<div class="dashboard-grid" style="grid-template-columns: 1fr; max-width: 1000px;">
    <article class="auth-panel dashboard-module-card">
        <h2>Current Document</h2>
        <?php if ($rulesFilePath && file_exists($rulesFilePath)): ?>
            <p class="lead compact-lead" style="margin-bottom: 20px;">
                The current Rules & Regulations document is shown below in a browser reader.
            </p>
            <div style="background: var(--of-page); padding: 20px; border-radius: 12px; border: 1px solid var(--of-line); margin-bottom: 20px;">
                <h3 style="margin: 0 0 6px 0; font-size: 1rem;"><?= e($rulesName) ?></h3>
                <p style="margin: 0; font-size: 0.8rem; color: var(--muted);">Inline view</p>
            </div>

            <?php if ($rulesExtension === 'pdf'): ?>
                <iframe
                    src="<?= e(app_base_url()) ?>/<?= e($rulesPath) ?>"
                    title="<?= e($rulesName) ?>"
                    style="width: 100%; height: min(80vh, 900px); border: 1px solid var(--of-line); border-radius: 12px; background: #fff;"
                ></iframe>
            <?php elseif ($rulesExtension === 'docx'): ?>
                <div id="rules-docx-reader"
                     data-doc-url="<?= e(app_base_url()) ?>/<?= e($rulesPath) ?>"
                     style="max-height: min(80vh, 900px); overflow: auto; padding: 1.5rem; border: 1px solid var(--of-line); border-radius: 12px; background: var(--of-page); line-height: 1.75; color: var(--text);">
                    <p style="margin: 0; color: var(--muted);">Loading document...</p>
                </div>
            <?php else: ?>
                <div style="padding: 1.5rem; border: 1px solid var(--of-line); border-radius: 12px; background: var(--of-page); color: var(--muted);">
                    The uploaded file cannot be rendered inline by this server. Upload a PDF to enable browser viewing.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="lead compact-lead">No Rules & Regulations document has been uploaded yet.</p>
        <?php endif; ?>
    </article>

    <?php if ($isAdmin): ?>
        <article class="auth-panel dashboard-module-card" style="margin-top: 24px;">
            <h2>Upload New Document</h2>
            <p class="lead compact-lead" style="margin-bottom: 20px;">
                Upload a new document (.doc, .docx, or PDF). This will replace the currently available file.
            </p>

            <form method="POST" enctype="multipart/form-data" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_rules">

                <div class="form-group">
                    <label for="rules_doc">Select Document</label>
                    <input type="file" name="rules_doc" id="rules_doc" accept=".doc,.docx,.pdf" required style="background: rgba(0,0,0,0.2); padding: 10px;">
                </div>

                <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px;">Upload Document</button>
            </form>
        </article>
    <?php endif; ?>
</div>

<?php if ($rulesExtension === 'docx' && $rulesFilePath && file_exists($rulesFilePath)): ?>
    <script src="https://unpkg.com/mammoth/mammoth.browser.min.js"></script>
    <script>
        (async () => {
            const reader = document.getElementById('rules-docx-reader');
            if (!reader || typeof mammoth === 'undefined') {
                return;
            }

            try {
                const response = await fetch(reader.dataset.docUrl, { cache: 'no-store' });
                const arrayBuffer = await response.arrayBuffer();
                const result = await mammoth.convertToHtml({ arrayBuffer });
                reader.innerHTML = result.value || '<p style="margin: 0; color: var(--muted);">The document did not contain readable text.</p>';
            } catch (error) {
                reader.innerHTML = '<p style="margin: 0; color: var(--danger);">Unable to render the document in this browser.</p>';
                console.error(error);
            }
        })();
    </script>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
