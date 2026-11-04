<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/DisciplineController.php'; // Correct path
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/Settings.php';

require_auth();

$controller = new \DisciplineController();
$authUser = current_auth_user();
$pageData = $controller->pageData($authUser);
$students = $pageData['students'] ?? [];
$classes = $pageData['classes'] ?? [];
$canDeduct = !empty($pageData['can_deduct']);
$settingsModel = new Settings(Database::connection());
$currentTerm = (int) $settingsModel->get('current_term', 1);
?>
<?php
$pageTitle = 'SBMS Discipline Engine';
$bodyClass = 'discipline-body sbms-plexus-body';
$mainClass = 'discipline-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/discipline-engine.css'];
$viteFooterAssets = ['assets/js/theme.js', 'assets/js/discipline-engine.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
            <section class="discipline-hero">
            <div class="hero-copy">
                <p class="eyebrow">Core Engine</p>
                <h1>Discipline actions with transactional safety.</h1>
                <p class="hero-text">
                    Every deduction is validated, locked, logged, and broadcast to the right stakeholders without
                    leaving partial updates behind.
                </p>
                <div class="hero-links" style="margin-top: 2rem; display:flex; gap:1rem; flex-wrap:wrap;">
                    <a class="btn-primary dashboard-link inline-link" href="<?= e(app_base_url()) ?>/discipline/quick-log.php">Open Quick Log</a>
                    <a class="btn-secondary dashboard-link inline-link" href="<?= e(app_base_url()) ?>/letters/index.php">View Parent Letters</a>
                    <span class="cell-muted" style="align-self:center;">Generated invitation letters are created automatically when serious discipline incidents trigger threshold alerts.</span>
                </div>
            </div>
            <div class="notification-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                    <div>
                        <p class="eyebrow">Live Notifications</p>
                        <h2 style="margin: 0;">Unread alerts</h2>
                    </div>
                    <div style="display: flex; gap: 5px;">
                        <button type="button" class="ghost-btn" id="notifShowAllBtn" style="font-size: 0.75rem; padding: 2px 6px;">Show All</button>
                        <button type="button" class="ghost-btn" id="notifHideAllBtn" style="font-size: 0.75rem; padding: 2px 6px;">Hide All</button>
                        <button type="button" class="ghost-btn" id="notifDeleteAllBtn" style="font-size: 0.75rem; padding: 2px 6px; color: #fb7185;">Delete All</button>
                    </div>
                </div>
                <div id="notificationList" class="notification-list">
                    <p class="placeholder-item">Loading alerts...</p>
                </div>
            </div>
        </section>

        <section class="history-card">
            <div class="section-top" style="align-items: center;">
                <div>
                    <?php if ($canDeduct): ?>
                        <button type="button" class="primary-btn" id="openDeductionModal" style="margin-bottom: 10px; font-size: 0.9rem; padding: 0.5rem 1rem;">Discipline Action</button>
                    <?php endif; ?>
                    <p class="eyebrow">History</p>
                    <h2>Deduction timeline</h2>
                </div>
                <div id="historyMeta" class="meta-text">Loading records...</div>
            </div>

            <?php if ($canDeduct): ?>
            <div id="deductionFormContainer" class="hidden" style="background: var(--card, rgba(255,255,255,0.92)); color: var(--ink, #102135); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--line, rgba(16, 33, 53, 0.12)); margin-bottom: 1.5rem; box-shadow: var(--shadow);">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line, rgba(16, 33, 53, 0.12)); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                    <h3 style="margin: 0; font-size: 1.25rem;">Discipline Action</h3>
                    <button type="button" class="icon-btn" id="cancelDeductionModal" aria-label="Close form">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div id="deductionFeedback" class="feedback" role="alert" aria-live="polite"></div>
                <form id="deductionForm" class="form-grid" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="field full-width">
                        <label>Action Type</label>
                        <div style="display: flex; gap: 15px; margin-top: 5px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 5px;"><input type="radio" name="action_type" value="deduct" checked> Deduct Marks</label>
                            <label style="display: flex; align-items: center; gap: 5px;"><input type="radio" name="action_type" value="add"> Reward / Add Marks</label>
                            <label style="display: flex; align-items: center; gap: 5px;"><input type="radio" name="action_type" value="set"> Set Marks</label>
                        </div>
                    </div>
                    
                    <div class="field">
                        <label for="deductClassFilterModal">Class Filter</label>
                        <select id="deductClassFilterModal" name="class_id" style="background: var(--card, rgba(255,255,255,0.92)); color: var(--ink, #102135); border: 1px solid var(--line, rgba(16, 33, 53, 0.12)); border-radius: 4px; padding: 0.5rem; outline: none; width: 100%;">
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= e((string) $class['id']) ?>"><?= e($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field full-width">
                        <label for="applyToClassCheckbox" style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600;">
                            <input type="checkbox" id="applyToClassCheckbox" name="apply_to_class" value="1">
                            Apply to whole class
                        </label>
                    </div>

                    <div class="field">
                        <label for="deductStudentSearch">Search Student</label>
                        <input type="text" id="deductStudentSearch" placeholder="Type to search student name..." style="background: var(--card, rgba(255,255,255,0.92)); color: var(--ink, #102135); border: 1px solid var(--line, rgba(16, 33, 53, 0.12)); border-radius: 4px; padding: 0.5rem; outline: none; width: 100%;">
                    </div>

                    <div class="field full-width">
                        <label for="deductStudentId">Student</label>
                        <select id="deductStudentId" name="student_id" required>
                            <option value="">-- Select Student --</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= e((string) $student['id']) ?>" data-class-id="<?= e((string) $student['class_id']) ?>"><?= e($student['full_name']) ?> (<?= e($student['student_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="field">
                        <label for="deductTerm">Term</label>
                        <select id="deductTerm" name="term" required>
                            <option value="<?= e(strval($currentTerm)) ?>">Term <?= e(strval($currentTerm)) ?></option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="deductMarks">Marks to Deduct</label>
                        <input type="number" id="deductMarks" name="marks_to_deduct" min="1" max="40" required>
                    </div>

                    <div class="field full-width">
                        <label for="deductReason">Reason</label>
                        <textarea id="deductReason" name="reason" rows="3" minlength="5" maxlength="500" required placeholder="Provide a detailed reason..."></textarea>
                    </div>

                    <div class="field">
                        <label for="incidentCategory">Incident Category</label>
                        <input type="text" id="incidentCategory" name="incident_category" maxlength="100" placeholder="e.g. Behavior, Attendance, Safety, Academic" style="background: var(--card, rgba(255,255,255,0.92)); color: var(--ink, #102135); border: 1px solid var(--line, rgba(16, 33, 53, 0.12)); border-radius: 4px; padding: 0.5rem; outline: none; width: 100%;">
                    </div>

                    <div class="field full-width">
                        <label for="evidenceFile">Evidence (optional)</label>
                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <button type="button" id="openCameraBtn" class="ghost-btn" style="display: inline-flex; align-items: center; gap: 0.4rem;">📷 Take Photo</button>
                            <input type="file" id="evidenceFile" name="evidence_file" accept="image/*,.pdf,.doc,.docx,.txt,.csv,.xls,.xlsx" capture="environment" style="position: absolute; left: -9999px; top: -9999px;">
                            <span id="evidenceFileLabel" class="cell-muted">No file chosen</span>
                        </div>
                        <p style="font-size: 0.85rem; color: var(--color-text-muted); margin: 0.35rem 0 0;">You can take a photo or upload an image or document from your device.</p>
                    </div>
                
                    <footer class="modal-footer" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end;">
                        <button type="submit" class="primary-btn" id="deductionSubmitButton">
                            <span class="btn-label">Apply Deduction</span>
                            <svg class="btn-loader spin" hidden width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l3.08 2.69"/></svg>
                        </button>
                    </footer>
                </form>
            </div>
            <?php endif; ?>

            <div class="filter-grid">
                <div class="field">
                    <label for="historyStudentFilter">Student</label>
                    <select id="historyStudentFilter">
                        <option value="">All students</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= e((string) $student['id']) ?>" data-class-id="<?= e((string) $student['class_id']) ?>">
                                <?= e($student['full_name']) ?> (<?= e($student['student_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="historyClassFilter">Class</label>
                    <select id="historyClassFilter">
                        <option value="">All classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= e((string) $class['id']) ?>"><?= e($class['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="historyTermFilter">Term</label>
                    <select id="historyTermFilter">
                        <option value="<?= e(strval($currentTerm)) ?>" selected>Term <?= e(strval($currentTerm)) ?></option>
                    </select>
                </div>

                <div class="field">
                    <label for="historySortFilter">Sort</label>
                    <select id="historySortFilter">
                        <option value="latest">Latest first</option>
                        <option value="most_deducted">Most deducted first</option>
                    </select>
                </div>
            </div>

            <div id="historyFeedback" class="feedback" role="alert" aria-live="polite"></div>

            <div class="accordion-controls" style="display: flex; gap: 10px; margin-bottom: 1rem; align-items: center;">
                <button type="button" class="ghost-btn" id="historyExpandAllBtn" style="display: flex; align-items: center; gap: 6px; padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                    Show All
                </button>
                <button type="button" class="ghost-btn" id="historyCollapseAllBtn" style="display: flex; align-items: center; gap: 6px; padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 14h6v6M20 10h-6V4M14 10l7-7M10 14l-7 7"/></svg>
                    Hide All
                </button>
                <button type="button" class="ghost-btn" id="refreshHistoryBtn" style="display: flex; align-items: center; gap: 6px; padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l3.08 2.69"/></svg>
                    Refresh
                </button>
                <?php if (!empty($authUser) && (in_array(strtolower((string) ($authUser['role_name'] ?? '')), ['admin', 'headteacher'], true) || stripos((string) ($authUser['role_name'] ?? ''), 'discipline') !== false)): ?>
                    <button type="button" class="ghost-btn" id="historyDeleteAllBtn" style="display: flex; align-items: center; gap: 6px; padding: 0.4rem 0.8rem; font-size: 0.85rem; color: #fb7185;">
                        Delete All
                    </button>
                <?php endif; ?>
            </div>

            <div id="historyContentContainer">
                <div class="table-wrap">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Term</th>
                                <th>Marks Deducted</th>
                                <th>Category</th>
                                <th>Reason</th>
                                <th>Letter</th>
                                <th>Evidence</th>
                                <th>Performed By</th>
                                <th>Time</th>
                                <?php if (!empty($authUser) && (in_array(strtolower((string) ($authUser['role_name'] ?? '')), ['admin', 'headteacher'], true) || stripos((string) ($authUser['role_name'] ?? ''), 'discipline') !== false)): ?>
                                    <th>Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr><td colspan="<?= !empty($authUser) && (in_array(strtolower((string) ($authUser['role_name'] ?? '')), ['admin', 'headteacher'], true) || stripos((string) ($authUser['role_name'] ?? ''), 'discipline') !== false) ? '11' : '10' ?>" class="table-placeholder">Loading history...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar">
                    <button type="button" class="ghost-btn" id="historyPrev">Previous</button>
                    <span id="historyPageIndicator">Page 1</span>
                    <button type="button" class="ghost-btn" id="historyNext">Next</button>
                </div>
            </div>
        </section>

        <script>
            window.SBMSDisciplineConfig = {
                historyUrl: '<?= e(app_base_url()) ?>/discipline/history.php',
                notificationsUrl: '<?= e(app_base_url()) ?>/discipline/notifications.php',
                notificationActionUrl: '<?= e(app_base_url()) ?>/discipline/notification_action.php',
                deductUrl: '<?= e(app_base_url()) ?>/discipline/deduct.php',
                letterViewUrl: '<?= e(app_base_url()) ?>/letters/view.php?id=',
                csrfToken: '<?= e(csrf_token()) ?>',
                canDeleteHistory: <?= (!empty($authUser) && (in_array(strtolower((string) ($authUser['role_name'] ?? '')), ['admin', 'headteacher'], true) || stripos((string) ($authUser['role_name'] ?? ''), 'discipline') !== false)) ? 'true' : 'false' ?>,
                historyDeleteUrl: '<?= e(app_base_url()) ?>/discipline/history.php',
                currentTerm: <?= e(strval($currentTerm)) ?>
            };
        </script>

        <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
