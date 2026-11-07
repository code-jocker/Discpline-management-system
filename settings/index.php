<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/models/ClassModel.php';
require_once dirname(__DIR__) . '/models/SchoolYear.php';

require_auth();
$user = current_auth_user();

$db = \Database::connection();
$userModel = new \User($db);
$settingsModel = new \Settings($db);
$classModel = new \ClassModel($db);
$schoolYearModel = new \SchoolYear($db);
\SchoolYear::ensureTable($db);

$is_admin = $userModel->userHasPrivilegedRole((int) $user['id']);
if (!$is_admin) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_logo') {
        if (isset($_FILES['app_logo']) && $_FILES['app_logo']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp'];
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($fileInfo, $_FILES['app_logo']['tmp_name']);
            finfo_close($fileInfo);

            if (in_array($mimeType, $allowedTypes)) {
                $extension = pathinfo($_FILES['app_logo']['name'], PATHINFO_EXTENSION);
                if (empty($extension) && $mimeType === 'image/svg+xml') {
                    $extension = 'svg';
                }
                $filename = 'logo_' . time() . '.' . $extension;
                $uploadDir = dirname(__DIR__) . '/uploads/';
                
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $destination = $uploadDir . $filename;

                if (move_uploaded_file($_FILES['app_logo']['tmp_name'], $destination)) {
                    $settingsModel->update(['app_logo' => 'uploads/' . $filename]);
                    $message = 'Logo updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to move uploaded file.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Invalid file type. Only JPG, PNG, WEBP, and SVG are allowed.';
                $messageType = 'error';
            }
        } else {
            $message = 'No file uploaded or an error occurred during upload.';
            $messageType = 'error';
        }
    } elseif ($action === 'update_favicon') {
        if (isset($_FILES['app_favicon']) && $_FILES['app_favicon']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($fileInfo, $_FILES['app_favicon']['tmp_name']);
            finfo_close($fileInfo);

            if (in_array($mimeType, $allowedTypes)) {
                $extension = pathinfo($_FILES['app_favicon']['name'], PATHINFO_EXTENSION);
                if (empty($extension) && $mimeType === 'image/svg+xml') {
                    $extension = 'svg';
                }
                $filename = 'favicon_' . time() . '.' . $extension;
                $uploadDir = dirname(__DIR__) . '/uploads/';
                
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $destination = $uploadDir . $filename;

                if (move_uploaded_file($_FILES['app_favicon']['tmp_name'], $destination)) {
                    $settingsModel->update(['app_favicon' => 'uploads/' . $filename]);
                    $message = 'Favicon updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to move uploaded file.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Invalid file type. Only ICO, PNG, JPG, and SVG are allowed.';
                $messageType = 'error';
            }
        } else {
            $message = 'No file uploaded or an error occurred during upload.';
            $messageType = 'error';
        }
    } elseif ($action === 'update_general') {
        $allowedFields = ['school_name', 'academic_year', 'current_term', 'contact_email'];
        $updates = [];
        foreach ($allowedFields as $field) {
            if (isset($_POST[$field])) {
                $updates[$field] = trim((string)$_POST[$field]);
            }
        }

        if (isset($_POST['low_marks_threshold']) && $_POST['low_marks_threshold'] !== '') {
            $threshold = max(0, min(40, (int) $_POST['low_marks_threshold']));
            $updates['low_marks_threshold'] = (string) $threshold;
        }

        $isGeneralSettingsSubmission = isset($_POST['school_name'])
            || isset($_POST['academic_year'])
            || isset($_POST['current_term'])
            || isset($_POST['contact_email'])
            || isset($_POST['low_marks_threshold']);

        if ($isGeneralSettingsSubmission) {
            $updates['low_marks_letter_enabled'] = !empty($_POST['low_marks_letter_enabled']) ? '1' : '0';
        }

        if (!empty($_POST['class_default_marks']) && is_array($_POST['class_default_marks'])) {
            foreach ($_POST['class_default_marks'] as $classId => $value) {
                $classId = (int) $classId;
                if ($classId <= 0) {
                    continue;
                }
                $mark = max(0, min(40, (int) $value));
                $updates['class_default_marks_' . $classId] = (string) $mark;

                $stmt = $db->prepare(
                    'UPDATE student_marks sm
                     JOIN students s ON s.id = sm.student_id
                     SET sm.marks = :marks
                     WHERE s.class_id = :class_id'
                );
                $stmt->execute([
                    'marks' => $mark,
                    'class_id' => $classId,
                ]);
            }
        }

            if (!empty($updates)) {
                // Preserve previous academic year and set active year flag when academic_year changes
                $currentAcademicYear = $settingsModel->get('academic_year', '');
                if (!empty($updates['academic_year']) && $updates['academic_year'] !== $currentAcademicYear) {
                    // store previous year for reference
                    $updates['previous_academic_year'] = (string) $currentAcademicYear;
                    // mark the newly provided academic year as the active year when requested
                    if (!empty($_POST['activate_academic_year'])) {
                        $updates['active_academic_year'] = (string) $updates['academic_year'];
                    }

                    // if requested, mark previous as deactivated
                    $deactivated = false;
                    if (!empty($_POST['deactivate_previous_year']) && $currentAcademicYear !== '') {
                        $updates['deactivated_academic_year'] = (string) $currentAcademicYear;
                        $deactivated = true;
                    }

                    // write an audit log entry for the academic year change
                    $logDir = dirname(__DIR__) . '/logs';
                    if (!is_dir($logDir)) {
                        mkdir($logDir, 0755, true);
                    }
                    $logFile = $logDir . '/academic_year_changes.log';
                    $timestamp = (new DateTime())->format('Y-m-d H:i:s');
                    $adminId = (int) ($user['id'] ?? 0);
                    $adminEmail = e($user['email'] ?? ($user['username'] ?? ''));
                    $prev = $currentAcademicYear === '' ? 'none' : $currentAcademicYear;
                    $new = $updates['academic_year'];
                    $entry = sprintf("%s - admin_id=%d admin=%s changed_academic_year from=%s to=%s deactivated_previous=%s\n",
                        $timestamp,
                        $adminId,
                        $adminEmail,
                        $prev,
                        $new,
                        $deactivated ? '1' : '0'
                    );
                    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
                }

                $settingsModel->update($updates);
                $message = 'General settings updated successfully!';
                $messageType = 'success';
            } else {
                $message = 'No general settings to update.';
                $messageType = 'error';
            }
    } elseif ($action === 'update_messaging') {
        $updates = [];
        foreach (['whatsapp_worker_url', 'whatsapp_worker_token', 'whatsapp_country_code_default'] as $field) {
            if (array_key_exists($field, $_POST)) {
                $updates[$field] = trim((string) $_POST[$field]);
            }
        }
        $updates['whatsapp_web_enabled'] = !empty($_POST['whatsapp_web_enabled']) ? '1' : '0';
        if (!empty($updates)) {
            $settingsModel->update($updates);
            $message = 'WhatsApp settings updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'No WhatsApp settings to update.';
            $messageType = 'error';
        }
    } elseif ($action === 'manage_school_year') {
        $op = $_POST['op'] ?? '';

        if ($op === 'create' && !empty($_POST['name'])) {
            $name = trim((string) $_POST['name']);
            $created = $schoolYearModel->create($name);
            $message = 'Created school year: ' . ($created['name'] ?? $name);
            $messageType = 'success';
        } elseif ($op === 'activate' && !empty($_POST['id'])) {
            $id = (int) $_POST['id'];
            $schoolYearModel->activate($id);
            $year = $schoolYearModel->byId($id);
            if ($year) {
                $settingsModel->update(['selected_school_year' => $year['name'], 'academic_year' => $year['name']]);
            }
            $message = 'Activated selected school year.';
            $messageType = 'success';
        } elseif ($op === 'archive' && !empty($_POST['id'])) {
            $schoolYearModel->archive((int) $_POST['id']);
            $message = 'Archived school year.';
            $messageType = 'success';
        } elseif ($op === 'reopen' && !empty($_POST['id'])) {
            $schoolYearModel->reopen((int) $_POST['id']);
            $message = 'Reopened school year.';
            $messageType = 'success';
        } elseif ($op === 'lock' && !empty($_POST['id'])) {
            $schoolYearModel->lock((int) $_POST['id']);
            $message = 'Locked school year.';
            $messageType = 'success';
        } elseif ($op === 'unlock' && !empty($_POST['id'])) {
            $schoolYearModel->unlock((int) $_POST['id']);
            $message = 'Unlocked school year.';
            $messageType = 'success';
        } elseif ($op === 'select' && !empty($_POST['name'])) {
            $name = trim((string) $_POST['name']);
            $schoolYearModel->setSelectedName($settingsModel, $name);
            $message = 'Selected school year: ' . $name;
            $messageType = 'success';
        } else {
            $message = 'Invalid school year operation.';
            $messageType = 'error';
        }
    }
}

$allSettings = $settingsModel->getAll();
$classes = $classModel->all();
$schoolYears = $schoolYearModel->all();

$currentLogo = $settingsModel->get('app_logo', 'assets/img/logo.svg');
$waEnabled = in_array(strtolower(trim((string) $settingsModel->get('whatsapp_web_enabled', '1'))), ['1','true','yes','on'], true);
$waWorkerUrl = trim((string) $settingsModel->get('whatsapp_worker_url', getenv('WHATSAPP_WORKER_URL') ?: ''));
$waWorkerToken = trim((string) $settingsModel->get('whatsapp_worker_token', ''));
$waCountryCode = trim((string) $settingsModel->get('whatsapp_country_code_default', '250'));
$waReady = $waEnabled && $waWorkerUrl !== '';
?>
<?php
$pageTitle = 'System Settings - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css', 'assets/css/settings.css'];
$viteFooterAssets = ['assets/js/theme.js', 'assets/js/settings-schoolyear.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
    <div class="settings-shell">
            <header id="system-settings" class="dashboard-header" style="margin-bottom: 2.5rem;">
                <p class="eyebrow" style="color: var(--brand-2);">Administrative</p>
                <h1 style="margin: 0; font-size: 2rem;">System Settings</h1>
            </header>

            <?php if ($message): ?>
                <div class="alert <?= e($messageType) ?>">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <div class="settings-grid">
                <!-- General Settings Card -->
                <div class="settings-card settings-card--primary" style="margin-bottom: 0;">
                    <span class="settings-card-tag">System settings</span>
                    <h2>General Information</h2>
                    <p style="color: var(--of-muted); margin-bottom: 1.5rem;">Configure basic school and term information.</p>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_general">
                        
                        <div class="form-group">
                            <label for="school_name">School Name</label>
                            <input type="text" id="school_name" name="school_name" value="<?= e($allSettings['school_name'] ?? '') ?>" placeholder="e.g. Springfield High">
                        </div>
                        
                        <div class="form-group">
                            <label for="academic_year">Academic Year</label>
                            <input type="text" id="academic_year" name="academic_year" value="<?= e($allSettings['academic_year'] ?? '') ?>" placeholder="e.g. 2026-2027">
                        </div>

                        <div class="form-group" style="display:flex; gap:0.75rem; align-items:center;">
                            <label style="display:flex; align-items:center; gap:0.5rem; margin:0;">
                                <input type="checkbox" id="activate_academic_year" name="activate_academic_year" value="1">
                                <span style="color: var(--of-ink);">Activate this academic year on save</span>
                            </label>
                            <label style="display:flex; align-items:center; gap:0.5rem; margin:0;">
                                <input type="checkbox" id="deactivate_previous_year" name="deactivate_previous_year" value="1">
                                <span style="color: var(--of-ink);">Deactivate previous academic year</span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label for="current_term">Current Term</label>
                            <select id="current_term" name="current_term">
                                <option value="1" <?= ($allSettings['current_term'] ?? '1') === '1' ? 'selected' : '' ?>>Term 1</option>
                                <option value="2" <?= ($allSettings['current_term'] ?? '') === '2' ? 'selected' : '' ?>>Term 2</option>
                                <option value="3" <?= ($allSettings['current_term'] ?? '') === '3' ? 'selected' : '' ?>>Term 3</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_email">Support / Contact Email</label>
                            <input type="email" id="contact_email" name="contact_email" value="<?= e($allSettings['contact_email'] ?? '') ?>" placeholder="admin@school.edu">
                        </div>

                        <div class="form-group">
                            <label for="low_marks_threshold">Low Marks Threshold (out of 40)</label>
                            <input type="number" id="low_marks_threshold" name="low_marks_threshold" min="0" max="40" value="<?= e($allSettings['low_marks_threshold'] ?? '20') ?>">
                        </div>

                        <label style="display:flex; align-items:center; gap:0.6rem; margin-bottom: 1rem;">
                            <input type="checkbox" name="low_marks_letter_enabled" value="1" <?= ($allSettings['low_marks_letter_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span style="color: var(--of-ink);">Auto-generate low-marks letters</span>
                        </label>

                        <button type="submit" class="btn-primary">Save General Settings</button>
                    </form>
                </div>

                <div class="settings-card settings-card--secondary" id="set-marks" style="margin-bottom: 0;">
                    <span class="settings-card-tag settings-card-tag--accent">Class baseline</span>
                    <h2>SET marks</h2>
                    <p style="color: var(--of-muted); margin-bottom: 1.5rem;">Set the baseline marks used for each class. These values are applied when new term records are created and can be updated here.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_general">
                        <div class="default-marks-grid">
                            <?php foreach ($classes as $class): ?>
                                <div class="default-marks-row">
                                    <label for="class_default_marks_<?= e((string) $class['id']) ?>"><?= e($class['name']) ?></label>
                                    <input type="number" id="class_default_marks_<?= e((string) $class['id']) ?>" name="class_default_marks[<?= e((string) $class['id']) ?>]" min="0" max="40" value="<?= e($allSettings['class_default_marks_' . (string) $class['id']] ?? '40') ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn-primary" style="margin-top: 1.5rem;">Save SET marks</button>
                    </form>
                </div>

                <div class="settings-card" id="academic-years-card" style="margin-bottom: 0;">
                    <h2>Academic Years</h2>
                    <p style="color: var(--of-muted); margin-bottom: 1rem;">Create, activate, archive, and lock academic years (three-term cycles).</p>

                    <form method="POST" style="display:flex; gap:0.5rem; align-items:center; margin-bottom: 1rem;">
                        <input type="hidden" name="action" value="manage_school_year">
                        <input type="hidden" name="op" value="create">
                        <input type="text" name="name" placeholder="e.g. 2026-2027" style="flex:1;" required>
                        <button type="submit" class="btn-primary">Create</button>
                    </form>

                    <div style="display: grid; gap: 0.5rem;">
                        <?php foreach ($schoolYears as $sy): ?>
                            <div style="display:flex; gap:0.5rem; align-items:center; justify-content:space-between; padding:0.5rem; background: rgba(255,255,255,0.02); border-radius:6px;">
                                <div>
                                    <strong><?= e($sy['name']) ?></strong>
                                    <div style="color: var(--of-muted); font-size:0.9rem;"><?= $sy['start_year'] ?> – <?= $sy['end_year'] ?> &middot; <?= $sy['is_active'] ? 'Active' : ($sy['is_archived'] ? 'Archived' : 'Inactive') ?><?= $sy['is_locked'] ? ' · Locked' : '' ?></div>
                                </div>
                                <div style="display:flex; gap:0.4rem;">
                                    <?php if (!$sy['is_active']): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="manage_school_year">
                                            <input type="hidden" name="op" value="activate">
                                            <input type="hidden" name="id" value="<?= e((string) $sy['id']) ?>">
                                            <button type="submit" class="btn-secondary">Activate</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($sy['is_archived']): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="manage_school_year">
                                            <input type="hidden" name="op" value="reopen">
                                            <input type="hidden" name="id" value="<?= e((string) $sy['id']) ?>">
                                            <button type="submit" class="btn-secondary">Reopen</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="manage_school_year">
                                            <input type="hidden" name="op" value="archive">
                                            <input type="hidden" name="id" value="<?= e((string) $sy['id']) ?>">
                                            <button type="submit" class="btn-secondary">Archive</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($sy['is_locked']): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="manage_school_year">
                                            <input type="hidden" name="op" value="unlock">
                                            <input type="hidden" name="id" value="<?= e((string) $sy['id']) ?>">
                                            <button type="submit" class="btn-secondary">Unlock</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="manage_school_year">
                                            <input type="hidden" name="op" value="lock">
                                            <input type="hidden" name="id" value="<?= e((string) $sy['id']) ?>">
                                            <button type="submit" class="btn-secondary">Lock</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="manage_school_year">
                                        <input type="hidden" name="op" value="select">
                                        <input type="hidden" name="name" value="<?= e($sy['name']) ?>">
                                        <button type="submit" class="btn-primary">Select</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Logo Settings Card -->
                <div id="messaging-settings" class="settings-card" style="margin-bottom: 0;">
                    <h2>Application Logo</h2>
                    <p style="color: var(--of-muted); margin-bottom: 1.5rem;">Update the logo displayed in the sidebar and auth pages.</p>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <p style="color: var(--of-ink); font-size: 0.85rem; margin-bottom: 0.5rem;">Current Logo:</p>
                        <img src="<?= e(app_base_url()) ?>/<?= e($currentLogo) ?>" alt="Current Logo" class="current-logo-preview">
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_logo">
                        <div class="form-group">
                            <label for="app_logo">Upload New Logo (JPG, PNG, WEBP, SVG)</label>
                            <input type="file" id="app_logo" name="app_logo" accept=".jpg,.jpeg,.png,.svg,.webp" required>
                        </div>
                        <button type="submit" class="btn-primary">Save Logo</button>
                    </form>
                </div>

                <!-- Favicon Settings Card -->
                <div class="settings-card" style="margin-bottom: 0;">
                    <h2>Application Favicon</h2>
                    <p style="color: var(--of-muted); margin-bottom: 1.5rem;">Update the icon displayed in the browser tab.</p>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <p style="color: var(--of-ink); font-size: 0.85rem; margin-bottom: 0.5rem;">Current Favicon:</p>
                        <?php $currentFavicon = $settingsModel->get('app_favicon', ''); ?>
                        <?php if ($currentFavicon): ?>
                            <img src="<?= e(app_base_url()) ?>/<?= e($currentFavicon) ?>" alt="Current Favicon" style="width: 32px; height: 32px; border-radius: 4px; object-fit: contain;">
                        <?php else: ?>
                            <div style="width: 32px; height: 32px; border-radius: 6px; background: #38bdf8; display: flex; align-items: center; justify-content: center; color: #07111f; font-weight: bold; font-family: sans-serif; font-size: 20px;">S</div>
                        <?php endif; ?>
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_favicon">
                        <div class="form-group">
                            <label for="app_favicon">Upload New Favicon (ICO, PNG, JPG, SVG)</label>
                            <input type="file" id="app_favicon" name="app_favicon" accept=".ico,.png,.jpg,.jpeg,.svg" required>
                        </div>
                        <button type="submit" class="btn-primary">Save Favicon</button>
                    </form>
                </div>

                <div class="settings-card" style="margin-bottom: 0;">
                    <h2>WhatsApp Web</h2>
                    <p style="color: var(--of-muted); margin-bottom: 1.5rem;">Parent WhatsApp messages are queued in MySQL and sent by the Node <code>whatsapp-web.js</code> worker (no Twilio). Keep the worker running and the school phone linked.</p>

                    <div style="display: grid; gap: 0.85rem; margin-bottom: 1.25rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="margin-bottom: 0.35rem;">Status</label>
                            <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 0.8rem; border-radius: 999px; font-weight: 700; <?= $waReady ? 'background: rgba(16,185,129,0.12); color: #10b981; border: 1px solid rgba(16,185,129,0.25);' : 'background: rgba(245,158,11,0.12); color: #f59e0b; border: 1px solid rgba(245,158,11,0.25);' ?>">
                                <span aria-hidden="true"><?= $waReady ? '✅' : '⚠️' ?></span>
                                <?= $waReady ? 'Configured' : ($waEnabled ? 'Worker URL needed' : 'Disabled') ?>
                            </div>
                        </div>
                        <div style="display: grid; gap: 0.5rem; color: var(--of-muted); font-size: 0.92rem;">
                            <div><strong>Enabled:</strong> <?= $waEnabled ? 'Yes' : 'No' ?></div>
                            <div><strong>Worker URL:</strong> <?= $waWorkerUrl !== '' ? e($waWorkerUrl) : 'Not set' ?></div>
                            <div><strong>Default country code:</strong> +<?= e($waCountryCode ?: '250') ?></div>
                        </div>
                    </div>

                    <form method="POST" style="display: grid; gap: 1rem; margin-bottom: 1.25rem;">
                        <input type="hidden" name="action" value="update_messaging">
                        <label style="display:flex; align-items:center; gap:0.6rem;">
                            <input type="checkbox" name="whatsapp_web_enabled" value="1" <?= $waEnabled ? 'checked' : '' ?>>
                            <span style="color: var(--of-ink);">Enable WhatsApp Web queue</span>
                        </label>
                        <div class="form-group">
                            <label for="whatsapp_worker_url">Worker health URL</label>
                            <input type="text" id="whatsapp_worker_url" name="whatsapp_worker_url" value="<?= e($waWorkerUrl) ?>" placeholder="http://localhost:3100">
                        </div>
                        <div class="form-group">
                            <label for="whatsapp_worker_token">Worker token (optional)</label>
                            <input type="password" id="whatsapp_worker_token" name="whatsapp_worker_token" value="" placeholder="<?= $waWorkerToken !== '' ? 'Leave blank to keep current token' : 'Optional bearer token' ?>">
                        </div>
                        <div class="form-group">
                            <label for="whatsapp_country_code_default">Default country code</label>
                            <input type="text" id="whatsapp_country_code_default" name="whatsapp_country_code_default" value="<?= e($waCountryCode) ?>" placeholder="250">
                        </div>
                        <button type="submit" class="btn-primary">Save WhatsApp Settings</button>
                    </form>

                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                        <a href="<?= e(app_base_url()) ?>/parents/whatsapp-queue.php" class="btn-secondary" style="text-decoration: none; display: inline-block;">WhatsApp Queue</a>
                        <a href="<?= e(app_base_url()) ?>/parents/whatsapp-test.php" class="btn-secondary" style="text-decoration: none; display: inline-block;">Test WhatsApp</a>
                    </div>
                </div>
            </div>
        </div>
        <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
