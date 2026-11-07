<?php
declare(strict_types=1);

// Prevent browser caching so dashboard numbers are always fresh on back navigation
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/auth/check.php';
require_once __DIR__ . '/models/plexus.php';
require_once __DIR__ . '/auth/csrf.php';
require_once __DIR__ . '/models/Settings.php';
require_once __DIR__ . '/models/Notification.php';
require_once __DIR__ . '/controllers/StudentController.php';

require_auth();
$user = current_auth_user();

// Handle case where user is not found in database
if ($user === null) {
    session_destroy();
    header('Location: ' . app_base_url() . '/auth/login.php?error=session_expired');
    exit;
}

$db = Database::connection();
$settingsModel = new Settings($db);
$userModel = new User($db);
$notificationModel = new Notification($db);
$schoolYearModel = new SchoolYear($db);

$currentTerm = SchoolYear::normalizeTerm($settingsModel->get('current_term', 1));
$selectedSchoolYear = trim((string) $settingsModel->get('selected_school_year', SchoolYear::calculateCurrentAcademicYear()));
$is_admin = $userModel->userHasPrivilegedRole((int) $user['id']);
$notificationScope = $notificationModel->describeVisibilityScope((int) $user['id']);

$unreadNotifications = $is_admin 
    ? $notificationModel->formatGrouped($notificationModel->fetchUnread((int)$user['id'], 5))
    : [];
$unreadCount = $is_admin ? $notificationModel->countUnread((int)$user['id']) : 0;

// Handle term update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_term') {
    assert_csrf_token($_POST['csrf_token'] ?? null);
    if ($is_admin) {
        $newTerm = SchoolYear::normalizeTerm($_POST['current_term'] ?? $currentTerm);
        if (in_array($newTerm, SchoolYear::terms(), true)) {
            $settingsModel->update(['current_term' => (string) $newTerm]);
            if (is_ajax_request()) {
                json_response(['status' => 'success', 'message' => 'Term updated successfully.']);
            } else {
                header('Location: ' . app_base_url() . '/index.php?success=term_updated');
                exit;
            }
        }
    }
    if (is_ajax_request()) { // If not admin or invalid term, still respond to AJAX
        json_response(['status' => 'error', 'message' => 'Failed to update term or unauthorized.'], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_school_year') {
    assert_csrf_token($_POST['csrf_token'] ?? null);
    if ($is_admin) {
        $newSchoolYear = trim((string) ($_POST['school_year'] ?? ''));
        if ($newSchoolYear !== '') {
            $settingsModel->update(['selected_school_year' => $newSchoolYear]);
            $schoolYear = $schoolYearModel->byName($newSchoolYear) ?: $schoolYearModel->create($newSchoolYear);
            if (isset($schoolYear['id'])) {
                $schoolYearModel->activate((int) $schoolYear['id']);
            }
            if (is_ajax_request()) {
                json_response(['status' => 'success', 'message' => 'School year updated successfully.']);
            } else {
                header('Location: ' . app_base_url() . '/index.php?success=school_year_updated');
                exit;
            }
        }
    }
    if (is_ajax_request()) {
        json_response(['status' => 'error', 'message' => 'Failed to update school year or unauthorized.'], 400);
    }
}

$is_discipline_deputy = strtolower($user['role_name'] ?? '') === 'deputy headteacher discipline' || $is_admin;
$is_headteacher_or_admin = in_array(strtolower($user['role_name'] ?? ''), ['headteacher', 'admin', 'it'], true);

// Handle blacklist and expulsion POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_blacklist') {
        assert_csrf_token($_POST['csrf_token'] ?? null);
        if ($is_discipline_deputy) {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if ($studentId > 0) {
                $statement = $db->prepare('INSERT IGNORE INTO blacklist (student_id, added_by) VALUES (:student_id, :added_by)');
                $statement->execute(['student_id' => $studentId, 'added_by' => $user['id']]);
                
                // Audit log
                $auditDesc = "Added student ID {$studentId} to blacklist.";
                $statementAudit = $db->prepare('INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, "add_blacklist", :description, :ip)');
                $statementAudit->execute(['user_id' => $user['id'], 'description' => $auditDesc, 'ip' => client_ip_address()]);
                
                if (is_ajax_request()) {
                    json_response(['status' => 'success', 'message' => 'Student added to blacklist.']);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?success=blacklisted');
                    exit;
                }
            }
        } else {
            if (is_ajax_request()) {
                json_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            } else {
                header('Location: ' . app_base_url() . '/index.php?error=unauthorized');
                exit;
            }
        }
    }

    if ($action === 'remove_blacklist') {
        assert_csrf_token($_POST['csrf_token'] ?? null);
        if ($is_discipline_deputy) {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if ($studentId > 0) {
                $statement = $db->prepare('DELETE FROM blacklist WHERE student_id = :student_id');
                $statement->execute(['student_id' => $studentId]);
                
                // Audit log
                $auditDesc = "Removed student ID {$studentId} from blacklist.";
                $statementAudit = $db->prepare('INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, "remove_blacklist", :description, :ip)');
                $statementAudit->execute(['user_id' => $user['id'], 'description' => $auditDesc, 'ip' => client_ip_address()]);
                
                if (is_ajax_request()) {
                    json_response(['status' => 'success', 'message' => 'Student removed from blacklist.']);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?success=unblacklisted');
                    exit;
                }
            }
        } else {
            if (is_ajax_request()) {
                json_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            } else {
                header('Location: ' . app_base_url() . '/index.php?error=unauthorized');
                exit;
            }
        }
    }

    if ($action === 'add_expulsion') {
        assert_csrf_token($_POST['csrf_token'] ?? null);
        if ($is_headteacher_or_admin) {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($studentId > 0) {
                $statement = $db->prepare('INSERT IGNORE INTO expulsions (student_id, expelled_by, reason) VALUES (:student_id, :expelled_by, :reason)');
                $statement->execute(['student_id' => $studentId, 'expelled_by' => $user['id'], 'reason' => $reason]);
                
                // Audit log
                $auditDesc = "Expelled student ID {$studentId}. Reason: {$reason}";
                $statementAudit = $db->prepare('INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, "add_expulsion", :description, :ip)');
                $statementAudit->execute(['user_id' => $user['id'], 'description' => $auditDesc, 'ip' => client_ip_address()]);
                
                if (is_ajax_request()) {
                    json_response(['status' => 'success', 'message' => 'Student expelled successfully.']);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?success=expelled');
                    exit;
                }
            }
        } else {
            if (is_ajax_request()) {
                json_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            } else {
                header('Location: ' . app_base_url() . '/index.php?error=unauthorized');
                exit;
            }
        }
    }

    if ($action === 'clear_high_risk_student') {
        assert_csrf_token($_POST['csrf_token'] ?? null);
        if ($is_admin) {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if ($studentId > 0 && Database::tableExists('student_risk_profiles')) {
                $statement = $db->prepare('DELETE FROM student_risk_profiles WHERE student_id = :student_id');
                $statement->execute(['student_id' => $studentId]);

                if (is_ajax_request()) {
                    json_response([
                        'status' => 'success',
                        'message' => 'Student removed from the high-risk list.',
                    ], 200);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?success=high_risk_student_removed');
                    exit;
                }
            } else {
                if (is_ajax_request()) {
                    json_response(['status' => 'error', 'message' => 'No student selected or risk data is unavailable.'], 422);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?error=high_risk_student_not_removed');
                    exit;
                }
            }
        } else {
            if (is_ajax_request()) {
                json_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            } else {
                header('Location: ' . app_base_url() . '/index.php?error=unauthorized');
                exit;
            }
        }
    }

    if ($action === 'clear_all_high_risk_students') {
        assert_csrf_token($_POST['csrf_token'] ?? null);
        if ($is_admin) {
            $rawStudentIds = $_POST['student_ids'] ?? [];
            $studentIds = [];
            if (is_array($rawStudentIds)) {
                foreach ($rawStudentIds as $rawStudentId) {
                    if (ctype_digit((string) $rawStudentId)) {
                        $studentIds[] = (int) $rawStudentId;
                    }
                }
            }

            if ($studentIds === []) {
                if (is_ajax_request()) {
                    json_response(['status' => 'error', 'message' => 'No students selected.'], 422);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?error=no_students_selected');
                    exit;
                }
            }

            if (Database::tableExists('student_risk_profiles')) {
                $removedCount = 0;
                foreach ($studentIds as $studentId) {
                    $statement = $db->prepare('DELETE FROM student_risk_profiles WHERE student_id = :student_id');
                    $statement->execute(['student_id' => $studentId]);
                    if ($statement->rowCount() > 0) {
                        $removedCount++;
                    }
                }

                if (is_ajax_request()) {
                    json_response([
                        'status' => 'success',
                        'message' => sprintf('Removed %d student(s) from the high-risk list.', $removedCount),
                    ], 200);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?success=' . urlencode(sprintf('removed_%d_high_risk_students', $removedCount)));
                    exit;
                }
            } else {
                if (is_ajax_request()) {
                    json_response(['status' => 'error', 'message' => 'Risk tracking data is unavailable.'], 422);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?error=high_risk_data_unavailable');
                    exit;
                }
            }
        } else {
            if (is_ajax_request()) {
                json_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            } else {
                header('Location: ' . app_base_url() . '/index.php?error=unauthorized');
                exit;
            }
        }
    }

    if ($action === 'remove_expulsion') {
        assert_csrf_token($_POST['csrf_token'] ?? null);
        if ($is_headteacher_or_admin) {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if ($studentId > 0) {
                $statement = $db->prepare('DELETE FROM expulsions WHERE student_id = :student_id');
                $statement->execute(['student_id' => $studentId]);
                
                // Audit log
                $auditDesc = "Reinstated student ID {$studentId} (removed from expulsions).";
                $statementAudit = $db->prepare('INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, "remove_expulsion", :description, :ip)');
                $statementAudit->execute(['user_id' => $user['id'], 'description' => $auditDesc, 'ip' => client_ip_address()]);
                
                if (is_ajax_request()) {
                    json_response(['status' => 'success', 'message' => 'Student reinstated successfully.']);
                } else {
                    header('Location: ' . app_base_url() . '/index.php?success=reinstated');
                    exit;
                }
            }
        } else {
            if (is_ajax_request()) {
                json_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            } else {
                header('Location: ' . app_base_url() . '/index.php?error=unauthorized');
                exit;
            }
        }
    }
}

/**
 * Cache wrapper for dashboard statistics (60 second TTL)
 */
function get_dashboard_stats_cache(int $term): array
{
    static $cache = null;
    static $cacheTime = 0;
    $cacheLifetime = 60;

    if ($cache !== null && (time() - $cacheTime) < $cacheLifetime) {
        return $cache;
    }

    $cache = get_dashboard_stats($term);
    $cacheTime = time();
    return $cache;
}

/**
 * Get dashboard statistics using optimized single query with UNION ALL
 * Uses allowlist approach to prevent SQL injection
 */
function get_dashboard_stats(int $term): array
{
    $stats = [
        'students' => null,
        'classes' => null,
        'incidents' => null,
        'roles' => null,
        'high_risk' => null,
    ];

    try {
        $pdo = Database::connection();

        $stats['students'] = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
        $stats['classes'] = (int) $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn();
        $stats['roles'] = Database::tableExists('categories')
            ? (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn()
            : (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();

        if (Database::tableExists('deductions_history')) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM deductions_history WHERE term = :term');
            $statement->execute(['term' => $term]);
            $stats['incidents'] = (int) $statement->fetchColumn();
        } else {
            $stats['incidents'] = 0;
        }

        if (Database::tableExists('student_risk') && Database::tableExists('risk_levels') && Database::columnExists('student_risk', 'risk_level_id')) {
            $riskLabelColumn = 'level_name';
            if (Database::columnExists('risk_levels', 'name')) {
                $riskLabelColumn = 'name';
            } elseif (Database::columnExists('risk_levels', 'risk_level')) {
                $riskLabelColumn = 'risk_level';
            }

            if (Database::columnExists('risk_levels', $riskLabelColumn)) {
                $statement = $pdo->prepare(
                    sprintf(
                        'SELECT COUNT(sr.id) AS cnt
                         FROM risk_levels rl
                         LEFT JOIN student_risk sr ON sr.risk_level_id = rl.id
                         WHERE LOWER(rl.%s) = LOWER(:critical)',
                        $riskLabelColumn
                    )
                );
                $statement->execute(['critical' => 'critical']);
                $stats['high_risk'] = (int) $statement->fetchColumn();
            } else {
                $stats['high_risk'] = 0;
            }
        } elseif (Database::tableExists('student_risk_profiles')) {
            $stats['high_risk'] = (int) $pdo->query("SELECT COUNT(*) FROM student_risk_profiles WHERE risk_level IN ('Critical', 'High Risk')")->fetchColumn();
        } else {
            $stats['high_risk'] = 0;
        }
    } catch (Throwable $exception) {
        Database::logError('Dashboard stats query failed', ['exception' => $exception->getMessage()]);
    }

    return $stats;
}

$stats = get_dashboard_stats_cache($currentTerm);

$activeHighRiskStudents = [];
if (Database::tableExists('student_risk_profiles')) {
    $pdo = Database::connection();
    $stmt = $pdo->query("SELECT sr.student_id, sr.risk_level, sr.total_marks_deducted, s.full_name, c.name AS class_name
                        FROM student_risk_profiles sr
                        JOIN students s ON sr.student_id = s.id
                        JOIN classes c ON s.class_id = c.id
                        WHERE sr.risk_level IN ('Critical', 'High Risk')
                        ORDER BY sr.total_marks_deducted DESC
                        LIMIT 5");
    if ($stmt) {
        $activeHighRiskStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$blacklistedStudents = [];
if (Database::tableExists('blacklist')) {
    $pdo = Database::connection();
    $stmt = $pdo->query("SELECT b.student_id, s.full_name, c.name AS class_name, b.added_at
                        FROM blacklist b
                        JOIN students s ON b.student_id = s.id
                        JOIN classes c ON s.class_id = c.id
                        ORDER BY b.added_at DESC");
    if ($stmt) {
        $blacklistedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$expelledStudents = [];
if (Database::tableExists('expulsions')) {
    $pdo = Database::connection();
    $stmt = $pdo->query("SELECT ex.student_id, s.full_name, c.name AS class_name, ex.reason, ex.expelled_at
                        FROM expulsions ex
                        JOIN students s ON ex.student_id = s.id
                        JOIN classes c ON s.class_id = c.id
                        ORDER BY ex.expelled_at DESC");
    if ($stmt) {
        $expelledStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$studentsForBlacklist = [];
if (Database::tableExists('students')) {
    $pdo = Database::connection();
    $stmt = $pdo->query("SELECT s.id, s.full_name, s.student_code, c.name AS class_name 
                        FROM students s 
                        JOIN classes c ON s.class_id = c.id
                        WHERE s.id NOT IN (SELECT student_id FROM blacklist)
                          AND s.id NOT IN (SELECT student_id FROM expulsions)
                        ORDER BY s.full_name ASC");
    if ($stmt) {
        $studentsForBlacklist = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$studentsForExpulsion = [];
if (Database::tableExists('students')) {
    $pdo = Database::connection();
    $stmt = $pdo->query("SELECT s.id, s.full_name, s.student_code, c.name AS class_name 
                        FROM students s 
                        JOIN classes c ON s.class_id = c.id
                        WHERE s.id NOT IN (SELECT student_id FROM expulsions)
                        ORDER BY s.full_name ASC");
    if ($stmt) {
        $studentsForExpulsion = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<?php
$pageTitle = 'SBMS Home';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/dashboard.js', 'assets/js/theme.js'];
require_once __DIR__ . '/includes/header.php';
?>
            <header class="dashboard-header">
                <div class="dashboard-heading">
                    <p class="eyebrow"><?= e(__('control_center')) ?></p>
                    <h1><?= e(__('dashboard_overview')) ?></h1>
                    <p class="dashboard-subtitle">Institutional conduct overview for the current academic period</p>
                    <div class="dashboard-context">
                        <span class="context-chip accent">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M9 20v-6h6v6"/></svg>
                            <?= e($settingsModel->get('school_name', 'SBMS')) ?>
                        </span>
                        <span class="context-chip">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                            <?= e($selectedSchoolYear) ?>
                        </span>
                        <span class="context-chip">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            Term <?= (int) $currentTerm ?>
                        </span>
                    </div>
                </div>
                <div class="dashboard-header-actions">
                    <label class="dashboard-search" aria-label="Search students, classes, reports">
                        <span aria-hidden="true">⌕</span>
                        <input type="search" placeholder="Search students, classes, reports..." data-dashboard-search>
                    </label>
                    <a class="dashboard-icon-btn" href="<?= e(app_base_url()) ?>/notifications/index.php" aria-label="Open notifications" title="Notifications">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
                        <span class="dashboard-badge"><?= (int) $unreadCount ?></span>
                    </a>
                    <a class="dashboard-icon-btn" href="<?= e(app_base_url()) ?>/parents/index.php" aria-label="Open parent communications" title="Messages">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="M4 7l8 6 8-6"/></svg>
                    </a>
                    <a class="dashboard-quick-action" href="<?= e(app_base_url()) ?>/discipline/quick-log.php">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                        Quick log
                    </a>
                    <a class="dashboard-quick-action secondary" href="<?= e(app_base_url()) ?>/students/index.php">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"/><circle cx="10" cy="7" r="3"/><path d="M20 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Students
                    </a>
                </div>
            </header>

        <section class="dashboard-stats" aria-label="Institution summary">
            <article class="auth-panel stat-card stat-card-students">
                <div class="stat-card-head">
                    <span class="stat-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"/><circle cx="10" cy="7" r="3"/><path d="M20 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                    <p class="eyebrow"><?= e(__('students')) ?></p>
                </div>
                <strong id="stat-students"><?= e($stats['students'] !== null ? (string) $stats['students'] : '--') ?></strong>
                <span><?= e(__('registered_learners')) ?></span>
            </article>
            <article class="auth-panel stat-card stat-card-classes">
                <div class="stat-card-head">
                    <span class="stat-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
                    <p class="eyebrow"><?= e(__('classes')) ?></p>
                </div>
                <strong id="stat-classes"><?= e($stats['classes'] !== null ? (string) $stats['classes'] : '--') ?></strong>
                <span><?= e(__('class_records')) ?></span>
            </article>
            <article class="auth-panel stat-card stat-card-incidents">
                <div class="stat-card-head">
                    <span class="stat-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>
                    <p class="eyebrow"><?= e(__('incidents')) ?></p>
                </div>
                <strong id="stat-incidents"><?= e($stats['incidents'] !== null ? (string) $stats['incidents'] : '--') ?></strong>
                <span><?= e(__('behavioral_incidents')) ?></span>
            </article>
            <article class="auth-panel stat-card stat-card-risk">
                <div class="stat-card-head">
                    <span class="stat-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v6c0 4.4 2.8 8.5 7 9 4.2-.5 7-4.6 7-9V6l-7-3Z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>
                    <p class="eyebrow"><?= e(__('high_risk')) ?></p>
                </div>
                <strong id="stat-high-risk"><?= e($stats['high_risk'] !== null ? (string) $stats['high_risk'] : '--') ?></strong>
                <span><?= e(__('students_requiring_intervention')) ?></span>
            </article>
            <article class="auth-panel stat-card stat-card-rules">
                <div class="stat-card-head">
                    <span class="stat-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5"/><path d="M8.5 12h7"/><path d="M8.5 16h5"/></svg></span>
                    <p class="eyebrow">Rules</p>
                </div>
                <strong id="stat-roles"><?= e($stats['roles'] !== null ? (string) $stats['roles'] : '--') ?></strong>
                <span>Rules in the SBMS model</span>
            </article>
        </section>

        <section class="dashboard-grid">
            <?php if ($is_admin): ?>
                <article class="auth-panel dashboard-module-card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <p class="eyebrow"><?= e(__('system_alerts')) ?></p>
                            <h2><?= e(__('notifications')) ?></h2>
                            <p class="lead compact-lead" style="margin-top: 0.35rem; max-width: 520px;">
                                <span class="sidebar-scope-tag scope-<?= e($notificationScope['tone']) ?>" style="margin-left: 0; margin-right: 0.5rem; vertical-align: middle;">
                                    <span aria-hidden="true" style="margin-right: 0.25rem;"><?= e($notificationScope['icon']) ?></span>
                                    <?= e($notificationScope['short_label']) ?>
                                </span>
                                <strong><?= e($notificationScope['label']) ?>:</strong> <?= e($notificationScope['description']) ?>
                            </p>
                        </div>
                        <button id="markAllReadBtn" class="ghost-btn" style="font-size: 0.75rem; padding: 4px 8px; <?= empty($unreadNotifications) ? 'display: none;' : '' ?>">
                            <?= e(__('mark_all_as_read')) ?>
                        </button>
                    </div>
                    <p class="lead compact-lead"><?= e(__('stay_updated')) ?></p>
                    <div id="dashboardNotificationList" class="notification-feed" style="margin-top: 15px; max-height: 280px; overflow-y: auto;">
                        <?php if (empty($unreadNotifications)): ?>
                            <p class="placeholder-item" style="color: #64748b; font-size: 0.9rem;"><?= e(__('no_new_alerts')) ?></p>
                        <?php else: ?>
                            <?php foreach ($unreadNotifications as $label => $items): ?>
                                <p class="group-label" style="font-size: 0.7rem; color: var(--brand-2); text-transform: uppercase; letter-spacing: 0.05em; margin: 10px 0 5px; font-weight: 600;"><?= e($label) ?></p>
                                <?php foreach ($items as $notif): ?>
                                    <?php
                                    $priorityColor = match($notif['priority'] ?? 'low') {
                                        'high' => '#fb7185',
                                        'medium' => '#f59e0b',
                                        default => '#38bdf8'
                                    };
                                    ?>
                                    <div class="notification-item" style="padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; border-left: 3px solid <?= $priorityColor ?>; padding-left: 10px; margin-bottom: 5px;">
                                        <div style="flex: 1;">
                                            <p style="margin: 0; font-size: 0.9rem; color: #f8fafc;"><?= e($notif['message']) ?></p>
                                            <span style="font-size: 0.7rem; color: #94a3b8;"><?= e(date('H:i', strtotime((string)$notif['timestamp']))) ?></span>
                                        </div>
                                        <button class="mark-read-btn" data-id="<?= (int)$notif['id'] ?>" title="Mark as read" 
                                            style="background: transparent; border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; border-radius: 4px; padding: 2px 6px; cursor: pointer; font-size: 0.75rem;">✓</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.05);">
                    <a href="<?= e(app_base_url()) ?>/notifications/index.php" style="color: var(--brand-2); font-size: 0.8rem; text-decoration: none;"><?= e(__('view_all_notifications')) ?> &rarr;</a>
                    </div>
                </article>
            <?php endif; ?>

            <article class="auth-panel dashboard-module-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">
                    <div>
                        <p class="eyebrow"><?= e(__('attention_required')) ?></p>
                        <h2><?= e(__('active_high_risk_students')) ?></h2>
                    </div>
                    <?php if ($is_admin && !empty($activeHighRiskStudents)): ?>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Remove all students shown in this high-risk list?')">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="clear_all_high_risk_students">
                            <?php foreach ($activeHighRiskStudents as $hrStudent): ?>
                                <input type="hidden" name="student_ids[]" value="<?= (int) $hrStudent['student_id'] ?>">
                            <?php endforeach; ?>
                            <button type="submit" style="background: transparent; border: 1px solid rgba(56, 189, 248, 0.3); color: #38bdf8; border-radius: 4px; padding: 4px 10px; cursor: pointer; font-size: 0.75rem;">
                                Remove All from Risk
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="notification-feed" style="margin-top: 15px; max-height: 280px; overflow-y: auto;">
                    <?php if (empty($activeHighRiskStudents)): ?>
                        <p class="placeholder-item" style="color: #64748b; font-size: 0.9rem;"><?= e(__('no_active_high_risk')) ?></p>
                    <?php else: ?>
                        <?php foreach ($activeHighRiskStudents as $hrStudent): ?>
                            <?php
                            $riskColor = match(strtolower($hrStudent['risk_level'])) {
                                'critical' => '#fb7185',
                                'high' => '#f59e0b',
                                default => '#38bdf8'
                            };
                            ?>
                            <div class="notification-item" style="padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; border-left: 3px solid <?= $riskColor ?>; padding-left: 10px; margin-bottom: 5px;">
                                <div style="flex: 1;">
                                    <p style="margin: 0; font-size: 0.9rem; color: #f8fafc;">
                                        <strong><?= e($hrStudent['full_name']) ?></strong> (<?= e($hrStudent['class_name']) ?>)
                                    </p>
                                    <span style="font-size: 0.75rem; color: #94a3b8;">Level: <span style="color: <?= $riskColor ?>"><?= e($hrStudent['risk_level']) ?></span> | Deducted: <?= (int)$hrStudent['total_marks_deducted'] ?></span>
                                </div>
                                <?php if ($is_admin): ?>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Remove this student from the high-risk list?')">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="clear_high_risk_student">
                                        <input type="hidden" name="student_id" value="<?= (int) $hrStudent['student_id'] ?>">
                                        <button type="submit" style="background: transparent; border: 1px solid rgba(56, 189, 248, 0.3); color: #38bdf8; border-radius: 4px; padding: 2px 6px; cursor: pointer; font-size: 0.75rem;">
                                            Remove
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.05);">
                    <a href="<?= e(app_base_url()) ?>/reports/index.php" style="color: var(--brand-2); font-size: 0.8rem; text-decoration: none;"><?= e(__('generate_full_report')) ?> &rarr;</a>
                </div>
            </article>

            <article class="auth-panel dashboard-module-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                    <div>
                        <p class="eyebrow" style="color: #ef4444;"><?= e(__('attention_required')) ?></p>
                        <h2><?= e(__('blacklist')) ?></h2>
                    </div>
                </div>
                <p class="lead compact-lead"><?= e(__('students_in_blacklist')) ?></p>

                <?php if ($is_discipline_deputy): ?>
                    <form method="POST" style="display: flex; gap: 8px; margin-top: 15px; margin-bottom: 15px; width: 100%;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="add_blacklist">
                        <select name="student_id" required style="flex: 1; min-width: 150px; font-size: 0.8rem; padding: 6px; border-radius: 4px; background: rgba(0, 0, 0, 0.3); color: #fff; border: 1px solid rgba(255, 255, 255, 0.1); outline: none;">
                            <option value=""><?= e(__('search_students')) ?></option>
                            <?php foreach ($studentsForBlacklist as $s): ?>
                                <option value="<?= e((string) $s['id']) ?>"><?= e($s['full_name']) ?> (<?= e($s['class_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-primary" style="padding: 4px 10px; font-size: 0.75rem; border-radius: 4px; white-space: nowrap; background: var(--brand-2, #38bdf8); color: #000; border: none; cursor: pointer; font-weight: bold;">
                            <?= e(__('add_to_blacklist')) ?>
                        </button>
                    </form>
                <?php endif; ?>

                <div class="notification-feed" style="max-height: 250px; overflow-y: auto; margin-top: 10px;">
                    <?php if (empty($blacklistedStudents)): ?>
                        <p class="placeholder-item" style="color: #64748b; font-size: 0.9rem;"><?= e(__('no_blacklisted_students')) ?></p>
                    <?php else: ?>
                        <?php foreach ($blacklistedStudents as $bStudent): ?>
                            <div class="notification-item" style="padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; gap: 10px; border-left: 3px solid #ef4444; padding-left: 10px; margin-bottom: 5px;">
                                <div style="flex: 1;">
                                    <p style="margin: 0; font-size: 0.9rem; color: #f8fafc;">
                                        <strong><?= e($bStudent['full_name']) ?></strong> (<?= e($bStudent['class_name']) ?>)
                                    </p>
                                    <span style="font-size: 0.7rem; color: #94a3b8;">
                                        Added: <?= e(date('d M Y', strtotime((string)$bStudent['added_at']))) ?>
                                    </span>
                                </div>
                                <?php if ($is_discipline_deputy): ?>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('<?= e(__('remove_from_blacklist')) ?>?')">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="remove_blacklist">
                                        <input type="hidden" name="student_id" value="<?= (int) $bStudent['student_id'] ?>">
                                        <button type="submit" style="background: transparent; border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; border-radius: 4px; padding: 2px 6px; cursor: pointer; font-size: 0.75rem;">
                                            <?= e(__('remove_from_blacklist')) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>

            <article class="auth-panel dashboard-module-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                    <div>
                        <p class="eyebrow" style="color: #fb7185;"><?= e(__('attention_required')) ?></p>
                        <h2><?= e(__('expelled_students')) ?></h2>
                    </div>
                </div>
                <p class="lead compact-lead"><?= e(__('expelled_desc')) ?></p>

                <?php if ($is_headteacher_or_admin): ?>
                    <form method="POST" style="display: flex; flex-direction: column; gap: 8px; margin-top: 15px; margin-bottom: 15px; width: 100%;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="add_expulsion">
                        <div style="display: flex; gap: 8px; width: 100%;">
                            <select name="student_id" required style="flex: 1; min-width: 150px; font-size: 0.8rem; padding: 6px; border-radius: 4px; background: rgba(0, 0, 0, 0.3); color: #fff; border: 1px solid rgba(255, 255, 255, 0.1); outline: none;">
                                <option value=""><?= e(__('search_students')) ?></option>
                                <?php foreach ($studentsForExpulsion as $s): ?>
                                    <option value="<?= e((string) $s['id']) ?>"><?= e($s['full_name']) ?> (<?= e($s['class_name']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="reason" placeholder="<?= e(__('reason')) ?>..." required style="flex: 1.5; font-size: 0.8rem; padding: 6px; border-radius: 4px; background: rgba(0, 0, 0, 0.3); color: #fff; border: 1px solid rgba(255, 255, 255, 0.1); outline: none;">
                            <button type="submit" class="btn-primary" style="padding: 4px 10px; font-size: 0.75rem; border-radius: 4px; white-space: nowrap; background: #fb7185; color: #000; border: none; cursor: pointer; font-weight: bold;">
                                <?= e(__('expel')) ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="notification-feed" style="max-height: 250px; overflow-y: auto; margin-top: 10px;">
                    <?php if (empty($expelledStudents)): ?>
                        <p class="placeholder-item" style="color: #64748b; font-size: 0.9rem;"><?= e(__('no_expelled_students')) ?></p>
                    <?php else: ?>
                        <?php foreach ($expelledStudents as $eStudent): ?>
                            <div class="notification-item" style="padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; gap: 10px; border-left: 3px solid #fb7185; padding-left: 10px; margin-bottom: 5px;">
                                <div style="flex: 1;">
                                    <p style="margin: 0; font-size: 0.9rem; color: #f8fafc;">
                                        <strong><?= e($eStudent['full_name']) ?></strong> (<?= e($eStudent['class_name']) ?>)
                                    </p>
                                    <p style="margin: 2px 0 0; font-size: 0.75rem; color: #cbd5e1;">
                                        <?= e(__('reason')) ?>: <?= e($eStudent['reason']) ?>
                                    </p>
                                    <span style="font-size: 0.7rem; color: #94a3b8;">
                                        Expelled: <?= e(date('d M Y', strtotime((string)$eStudent['expelled_at']))) ?>
                                    </span>
                                </div>
                                <?php if ($is_headteacher_or_admin): ?>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('<?= e(__('reinstate')) ?>?')">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="remove_expulsion">
                                        <input type="hidden" name="student_id" value="<?= (int) $eStudent['student_id'] ?>">
                                        <button type="submit" style="background: transparent; border: 1px solid rgba(251, 113, 133, 0.3); color: #fb7185; border-radius: 4px; padding: 2px 6px; cursor: pointer; font-size: 0.75rem;">
                                            <?= e(__('reinstate')) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>

            <article class="auth-panel dashboard-module-card dashboard-notes-card">
                <p class="eyebrow">Workflow</p>
                <h2>Discipline workflow</h2>
                <ul class="feature-list dashboard-list">
                    <li>Log an incident from the classroom in seconds.</li>
                    <li>Deductions update conduct scores automatically.</li>
                    <li>Guardians are notified through the communication module.</li>
                    <li>Leadership reviews risk levels and term reports.</li>
                </ul>
            </article>

            <article class="auth-panel dashboard-module-card">
                <p class="eyebrow"><?= e(__('student_operations')) ?></p>
                <h2><?= e(__('student_class_management')) ?></h2>
                <p class="lead compact-lead">
                    <?= e(__('student_desc')) ?>
                </p>
                <a class="btn-primary dashboard-link inline-link" href="<?= e(app_base_url()) ?>/students/index.php"><?= e(__('open_student_workspace')) ?></a>
            </article>

            <article class="auth-panel dashboard-module-card">
                <p class="eyebrow">Recommended workflow</p>
                <h2>Teacher quick log</h2>
                <p class="lead compact-lead">
                    Fast, mobile-friendly discipline entry with automatic audit logging and risk scoring.
                </p>
                <a class="btn-primary dashboard-link inline-link" href="<?= e(app_base_url()) ?>/discipline/quick-log.php">Open quick log</a>
            </article>

            <article class="auth-panel dashboard-module-card">
                <p class="eyebrow"><?= e(__('behavior_control')) ?></p>
                <h2><?= e(__('discipline_engine')) ?></h2>
                <p class="lead compact-lead">
                    <?= e(__('discipline_desc')) ?>
                </p>
                <a class="btn-primary dashboard-link inline-link" href="<?= e(app_base_url()) ?>/discipline/index.php"><?= e(__('open_discipline_workspace')) ?></a>
            </article>
        </section>

        <section class="dashboard-analytics">
            <article class="auth-panel analytics-card risk-distribution-card">
                <div class="analytics-header">
                    <div>
                        <p class="eyebrow"><?= e(__('risk_analytics')) ?></p>
                        <h2><?= e(__('risk_distribution')) ?></h2> 
                    </div>
                    <div>
                        <button type="button" class="ghost-btn" id="refreshAnalyticsButton"><?= e(__('refresh')) ?></button>
                        <span class="btn-loader analytics-spinner" hidden></span>
                    </div>
                </div>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="riskDistributionChart"></canvas>
                </div>
            </article>

            <article class="auth-panel analytics-card class-comparison-card">
                <div class="analytics-header">
                    <div>
                        <p class="eyebrow"><?= e(__('class_analytics')) ?></p>
                        <h2><?= e(__('avg_marks_by_class')) ?></h2>
                    </div>
                    <div>
                        <span class="btn-loader analytics-spinner" hidden></span>
                    </div>
                </div>
                <div class="class-chart-shell">
                    <canvas id="classComparisonChart"></canvas>
                    <p id="classChartEmpty" class="chart-empty" hidden>No class data available.</p>
                </div>
            </article>

            <article class="auth-panel analytics-card top-offenders-card">
                <div class="analytics-header">
                    <div>
                        <p class="eyebrow"><?= e(__('behavior_signals')) ?></p>
                        <h2><?= e(__('top_offenders')) ?></h2>
                    </div>
                    <div class="analytics-header-action">
                        <a class="analytics-report-link" href="<?= e(app_base_url()) ?>/reports/index.php">View full report &rarr;</a>
                        <span class="btn-loader analytics-spinner" hidden></span>
                    </div>
                </div>
                <div id="dashboardFeedback" class="feedback" role="alert" aria-live="polite"></div>
                <ul id="topOffendersList" class="notification-feed behavior-signals-list">
                    <li class="behavior-empty-state">
                        <span class="behavior-empty-icon" aria-hidden="true">&#9673;</span>
                        <strong>No offenders to display.</strong>
                        <span>Great news! Keep up the good work.</span>
                    </li>
                </ul>
            </article>
        </section>
        </main>
    <script>
        window.SBMSDashboardConfig = {
            metricsUrl: '<?= e(app_base_url()) ?>/dashboard/metrics.php',
            notificationsUrl: '<?= e(app_base_url()) ?>/dashboard/notifications.php',
            markReadUrl: '<?= e(app_base_url()) ?>/dashboard/mark_notification_read.php',
            markAllReadUrl: '<?= e(app_base_url()) ?>/dashboard/mark_all_notifications_read.php',
            initialUnreadCount: <?= (int)$unreadCount ?>,
            canExpel: <?= !empty($is_headteacher_or_admin) ? 'true' : 'false' ?>
        };
    </script>
    <audio id="notificationSound" src="<?= e(app_base_url()) ?>/assets/audio/notification.mp3" preload="auto"></audio>
<?php
$viteFooterAssets = ['assets/js/vendor/chart.js', 'assets/js/dashboard.js', 'assets/js/theme.js'];
require_once __DIR__ . '/includes/footer.php';
?>
