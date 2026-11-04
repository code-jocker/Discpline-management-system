<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Student.php';
require_once dirname(__DIR__) . '/app/Services/ParentNotificationService.php';

require_auth();
$user = current_auth_user();

$db = Database::connection();
$userModel = new User($db);
$studentModel = new Student($db);
$parentService = new \App\Services\ParentNotificationService($db);

$canManageParents = $userModel->userHasPrivilegedRole((int) $user['id'])
    || strtolower((string) ($user['role_name'] ?? '')) === 'headteacher'
    || stripos((string) ($user['role_name'] ?? ''), 'discipline') !== false;

if (!$canManageParents) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    assert_csrf_token($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_contact') {
        $savedId = $parentService->saveParentContact([
            'id' => $_POST['contact_id'] ?? null,
            'student_id' => (int) ($_POST['student_id'] ?? 0),
            'parent_name' => trim((string) ($_POST['parent_name'] ?? '')),
            'relationship' => trim((string) ($_POST['relationship'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone_number' => trim((string) ($_POST['phone_number'] ?? '')),
            'whatsapp_number' => trim((string) ($_POST['whatsapp_number'] ?? '')),
            'contact_preference' => trim((string) ($_POST['contact_preference'] ?? 'email')),
            'is_primary' => !empty($_POST['is_primary']) ? 1 : 0,
        ]);

        if ($savedId !== null) {
            header('Location: ' . app_base_url() . '/parents/index.php?success=saved');
            exit;
        }

        $message = 'Please select a student and enter the parent/guardian name.';
        $messageType = 'error';
    }

    if ($action === 'delete_contact') {
        $contactId = isset($_POST['contact_id']) && ctype_digit((string) $_POST['contact_id'])
            ? (int) $_POST['contact_id']
            : 0;

        if ($contactId > 0 && $parentService->deleteParentContact($contactId)) {
            header('Location: ' . app_base_url() . '/parents/index.php?success=deleted');
            exit;
        }

        $message = 'Unable to delete that parent contact.';
        $messageType = 'error';
    }
}

$studentFilter = isset($_GET['student_id']) && ctype_digit((string) $_GET['student_id'])
    ? (int) $_GET['student_id']
    : null;
$contacts = $parentService->listParentContacts($studentFilter);
$students = $studentModel->listForSelection(null, 1000);
$editingContact = null;
if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editingContact = $parentService->findParentContactById((int) $_GET['edit']);
}

$totalContacts = count($contacts);
$whatsAppContacts = 0;
$studentsWithContacts = [];
foreach ($contacts as $contact) {
    if (!empty($contact['whatsapp_number'])) {
        $whatsAppContacts++;
    }
    $studentsWithContacts[(int) $contact['student_id']] = true;
}
$studentContactCount = count($studentsWithContacts);

$pageTitle = 'Parent Contacts - SBMS';
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
                    <h1 style="margin: 0; font-size: 2rem;">Parent Contacts</h1>
                    <p class="lead compact-lead" style="max-width: 820px;">
                        Maintain parent, guardian, phone, email, and WhatsApp details so the school can send letters and alerts directly from the system.
                    </p>
                </div>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <a class="btn-secondary" href="<?= e(app_base_url()) ?>/letters/index.php">Low-Marks Letters</a>
                    <a class="btn-secondary" href="<?= e(app_base_url()) ?>/parents/whatsapp-test.php">Test WhatsApp</a>
                </div>
            </header>

            <?php if (isset($_GET['success'])): ?>
                <?php
                $successLabel = match ((string) $_GET['success']) {
                    'saved' => 'Parent contact saved successfully.',
                    'deleted' => 'Parent contact deleted successfully.',
                    default => 'Changes saved successfully.',
                };
                ?>
                <div class="alert success" style="margin-bottom: 1rem;"><?= e($successLabel) ?></div>
            <?php elseif ($message !== ''): ?>
                <div class="alert <?= e($messageType) ?>" style="margin-bottom: 1rem;"><?= e($message) ?></div>
            <?php endif; ?>

            <section class="dashboard-stats" style="margin-bottom: 1.5rem;">
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Contacts</p>
                    <strong><?= (int) $totalContacts ?></strong>
                    <span>Parent/guardian records</span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">WhatsApp-ready</p>
                    <strong><?= (int) $whatsAppContacts ?></strong>
                    <span>Contacts with a WhatsApp number</span>
                </article>
                <article class="auth-panel stat-card">
                    <p class="eyebrow">Students covered</p>
                    <strong><?= (int) $studentContactCount ?></strong>
                    <span>Students with at least one contact</span>
                </article>
            </section>

            <section class="dashboard-grid" style="grid-template-columns: 0.95fr 1.25fr; align-items: start;">
                <article class="auth-panel dashboard-module-card">
                    <p class="eyebrow">Add or Edit</p>
                    <h2 style="margin-bottom: 0.5rem;"><?= $editingContact ? 'Edit Parent Contact' : 'New Parent Contact' ?></h2>
                    <p class="lead compact-lead">Keep this data accurate so letters can go to the right parent or guardian.</p>

                    <form method="post" style="margin-top: 1.25rem; display: grid; gap: 1rem;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="save_contact">
                        <input type="hidden" name="contact_id" value="<?= (int) ($editingContact['id'] ?? 0) ?>">

                        <div class="field">
                            <label for="student_id">Student</label>
                            <select id="student_id" name="student_id" required>
                                <option value="">Select student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= (int) $student['id'] ?>" <?= (int) ($editingContact['student_id'] ?? 0) === (int) $student['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $student['full_name']) ?> (<?= e((string) $student['student_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="parent_name">Parent / Guardian Name</label>
                            <input type="text" id="parent_name" name="parent_name" maxlength="150" required value="<?= e((string) ($editingContact['parent_name'] ?? '')) ?>">
                        </div>

                        <div class="field">
                            <label for="relationship">Relationship</label>
                            <input type="text" id="relationship" name="relationship" maxlength="30" value="<?= e((string) ($editingContact['relationship'] ?? '')) ?>" placeholder="mother, father, guardian">
                        </div>

                        <div class="field">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" maxlength="180" value="<?= e((string) ($editingContact['email'] ?? '')) ?>">
                        </div>

                        <div class="field">
                            <label for="phone_number">Phone Number</label>
                            <input type="text" id="phone_number" name="phone_number" maxlength="20" value="<?= e((string) ($editingContact['phone_number'] ?? '')) ?>" placeholder="+2507...">
                        </div>

                        <div class="field">
                            <label for="whatsapp_number">WhatsApp Number</label>
                            <input type="text" id="whatsapp_number" name="whatsapp_number" maxlength="20" value="<?= e((string) ($editingContact['whatsapp_number'] ?? '')) ?>" placeholder="+2507...">
                        </div>

                        <div class="field">
                            <label for="contact_preference">Preferred Channel</label>
                            <select id="contact_preference" name="contact_preference">
                                <option value="email" <?= (string) ($editingContact['contact_preference'] ?? 'email') === 'email' ? 'selected' : '' ?>>Email</option>
                                <option value="sms" <?= (string) ($editingContact['contact_preference'] ?? '') === 'sms' ? 'selected' : '' ?>>SMS</option>
                                <option value="whatsapp" <?= (string) ($editingContact['contact_preference'] ?? '') === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                            </select>
                        </div>

                        <label style="display: flex; align-items: center; gap: 0.6rem;">
                            <input type="checkbox" name="is_primary" value="1" <?= !empty($editingContact['is_primary']) ? 'checked' : '' ?>>
                            <span>Primary contact for this student</span>
                        </label>

                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                            <button type="submit" class="btn-primary" style="width: auto;">Save Contact</button>
                            <?php if ($editingContact): ?>
                                <a href="<?= e(app_base_url()) ?>/parents/index.php" class="btn-secondary" style="text-decoration: none; display: inline-block;">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </article>

                <article class="auth-panel dashboard-module-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
                        <div>
                            <p class="eyebrow">Directory</p>
                            <h2 style="margin: 0;">Saved Contacts</h2>
                        </div>
                        <form method="get" style="margin: 0; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                            <label style="display:flex; flex-direction:column; gap:0.25rem; font-size: 0.85rem; color: var(--of-muted);">
                                <span>Filter by student</span>
                                <select name="student_id" style="min-width: 220px;">
                                    <option value="">All students</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?= (int) $student['id'] ?>" <?= $studentFilter === (int) $student['id'] ? 'selected' : '' ?>>
                                            <?= e((string) $student['full_name']) ?> (<?= e((string) $student['student_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="btn-secondary" style="width: auto;">Filter</button>
                        </form>
                    </div>

                    <div style="overflow-x: auto; margin-top: 1.25rem;">
                        <table style="width: 100%; border-collapse: collapse; color: var(--of-ink);">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--of-line);">
                                    <th style="padding: 0.8rem; text-align:left;">Student</th>
                                    <th style="padding: 0.8rem; text-align:left;">Contact</th>
                                    <th style="padding: 0.8rem; text-align:left;">Channel</th>
                                    <th style="padding: 0.8rem; text-align:left;">Numbers</th>
                                    <th style="padding: 0.8rem; text-align:left;">Primary</th>
                                    <th style="padding: 0.8rem; text-align:left;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($contacts)): ?>
                                    <tr>
                                        <td colspan="6" style="padding: 1.2rem 0.8rem; color: var(--of-muted);">No parent contacts found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($contacts as $contact): ?>
                                        <tr style="border-bottom: 1px solid var(--of-line);">
                                            <td style="padding: 0.8rem;">
                                                <strong><?= e((string) ($contact['student_name'] ?? '')) ?></strong><br>
                                                <span style="font-size: 0.88rem; color: var(--of-muted);"><?= e((string) ($contact['student_code'] ?? '')) ?> · <?= e((string) ($contact['class_name'] ?? '')) ?></span>
                                            </td>
                                            <td style="padding: 0.8rem;">
                                                <strong><?= e((string) ($contact['parent_name'] ?? '')) ?></strong><br>
                                                <span style="font-size: 0.88rem; color: var(--of-muted);"><?= e((string) ($contact['relationship'] ?? '')) ?></span>
                                            </td>
                                            <td style="padding: 0.8rem; text-transform: capitalize;"><?= e((string) ($contact['contact_preference'] ?? 'email')) ?></td>
                                            <td style="padding: 0.8rem; font-size: 0.9rem;">
                                                <div>Email: <?= e((string) ($contact['email'] ?? '-')) ?></div>
                                                <div>Phone: <?= e((string) ($contact['phone_number'] ?? '-')) ?></div>
                                                <div>WhatsApp: <?= e((string) ($contact['whatsapp_number'] ?? '-')) ?></div>
                                            </td>
                                            <td style="padding: 0.8rem;">
                                                <?= !empty($contact['is_primary']) ? 'Yes' : 'No' ?>
                                            </td>
                                            <td style="padding: 0.8rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <a href="<?= e(app_base_url()) ?>/parents/index.php?edit=<?= (int) $contact['id'] ?>" class="btn-secondary" style="text-decoration: none; display: inline-block;">Edit</a>
                                                <form method="post" style="margin: 0;" onsubmit="return confirm('Delete this parent contact?');">
                                                    <?= csrf_input() ?>
                                                    <input type="hidden" name="action" value="delete_contact">
                                                    <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                                                    <button type="submit" class="btn-danger" style="border:none; padding:0.65rem 0.9rem; border-radius: 10px; background: #dc2626; color: #fff; cursor:pointer;">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
