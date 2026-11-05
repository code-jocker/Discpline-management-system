<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/StudentController.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/models/plexus.php';

require_auth();

$controller = new StudentController();

// Load initial data for filters and modals
$initialData       = $controller->indexData();
$categories        = $initialData['categories'] ?? [];
$classes            = $initialData['classes'] ?? [];
$teachers           = $initialData['teachers'] ?? [];
$streams            = $initialData['streams'] ?? [];
$dormitories        = $initialData['dormitories'] ?? [];
$schoolYears        = $initialData['school_years'] ?? [];
$authUser          = current_auth_user();
$canManageStudents = $controller->canManageStudents($authUser);
$canManageStructure = !empty($authUser) && isset($authUser['id']) && (new User(Database::connection()))->userHasPrivilegedRole((int) $authUser['id']);

// Identity printed on the student ID cards. Everything comes from settings so
// no school detail is hardcoded in the template.
$settingsModel   = new Settings(Database::connection());
$schoolIdentity  = [
    'name' => trim((string) $settingsModel->get('school_name', 'SBMS')),
    'address' => trim((string) $settingsModel->get('school_address', '')),
    'phone' => trim((string) $settingsModel->get('school_phone', '')),
    'logo' => app_base_url() . '/' . ltrim((string) $settingsModel->get('app_logo', 'assets/img/logo.svg'), '/'),
    'year' => trim((string) ($settingsModel->get('selected_school_year', '') ?: $settingsModel->get('active_academic_year', ''))),
];
?>
<?php
$pageTitle = 'SBMS Student Management';
$bodyClass = 'students-body sbms-plexus-body';
$mainClass = 'students-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/student-management.css', 'assets/css/student-id-card.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
        <?php
        $directoryStats = $initialData['stats'] ?? [];
        $maxMarks = max(1, (int) ($settingsModel->get('max_marks_per_term', 40) ?: 40));
        $categoryCount = [];
        foreach (($directoryStats['categories'] ?? []) as $categoryRow) {
            $categoryCount[(int) $categoryRow['id']] = (int) $categoryRow['total'];
        }
        $activeCount = (int) ($directoryStats['active'] ?? 0);
        $totalCount = (int) ($directoryStats['total'] ?? 0);
        $avgConduct = $directoryStats['avg_conduct'] ?? null;
        $highRiskCount = (int) ($directoryStats['high_risk'] ?? 0);
        $juniorName = $categories[0]['name'] ?? 'Junior';
        $seniorName = $categories[1]['name'] ?? 'Senior';
        $juniorCount = (int) ($categoryCount[(int) ($categories[0]['id'] ?? 0)] ?? 0);
        $seniorCount = (int) ($categoryCount[(int) ($categories[1]['id'] ?? 0)] ?? 0);
        ?>

        <header class="sd-page-head">
            <div class="sd-page-title">
                <p class="eyebrow">Student records</p>
                <h1>Student directory</h1>
                <p>Search, filter and manage every learner from one workspace.</p>
            </div>
            <div class="sd-page-actions">
                <?php if ($canManageStudents): ?>
                    <button type="button" class="sd-btn sd-btn--ghost" id="openUploadModal">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 20h16"/></svg>
                        Import students
                    </button>
                    <button type="button" class="sd-btn sd-btn--primary" id="openStudentModal">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                        Add student
                    </button>
                <?php endif; ?>
                <?php if ($canManageStructure): ?>
                    <button type="button" class="sd-btn sd-btn--danger" id="deleteAllStudentsBtn" title="Delete every student record">
                        Restricted
                    </button>
                <?php endif; ?>
            </div>
        </header>

        <section class="sd-stats" aria-label="Student statistics">
            <article class="sd-stat" data-stat="total">
                <span class="sd-stat-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"/><circle cx="10" cy="7" r="3"/><path d="M20 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <div>
                    <p class="sd-stat-label">Total students</p>
                    <p class="sd-stat-value" id="statTotalValue"><?= number_format($totalCount) ?></p>
                    <p class="sd-stat-sub" id="statTotalSub"><?= number_format($activeCount) ?> active &middot; avg conduct <?= $avgConduct !== null ? e(number_format((float) $avgConduct, 1)) . '/' . $maxMarks : '—' ?></p>
                </div>
            </article>
            <article class="sd-stat" data-stat="category" data-category-id="<?= e((string) ($categories[0]['id'] ?? '')) ?>">
                <span class="sd-stat-icon sd-stat-icon--indigo">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-7"/></svg>
                </span>
                <div>
                    <p class="sd-stat-label" id="statJuniorLabel"><?= e($juniorName) ?></p>
                    <p class="sd-stat-value" id="statJuniorValue"><?= number_format($juniorCount) ?></p>
                    <p class="sd-stat-sub">Active learners in this level</p>
                </div>
            </article>
            <article class="sd-stat" data-stat="category" data-category-id="<?= e((string) ($categories[1]['id'] ?? '')) ?>">
                <span class="sd-stat-icon sd-stat-icon--teal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20l7-7 4 4 7-9"/><path d="M14 8h7v7"/></svg>
                </span>
                <div>
                    <p class="sd-stat-label" id="statSeniorLabel"><?= e($seniorName) ?></p>
                    <p class="sd-stat-value" id="statSeniorValue"><?= number_format($seniorCount) ?></p>
                    <p class="sd-stat-sub">Active learners in this level</p>
                </div>
            </article>
            <article class="sd-stat sd-stat--alert">
                <span class="sd-stat-icon sd-stat-icon--gold">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v5"/><path d="M12 17h.01"/></svg>
                </span>
                <div>
                    <p class="sd-stat-label">High risk</p>
                    <p class="sd-stat-value" id="statRiskValue"><?= number_format($highRiskCount) ?></p>
                    <p class="sd-stat-sub">Needs pastoral follow-up</p>
                </div>
            </article>
        </section>

        <section class="sd-filter-card" aria-label="Student filters">
            <div class="sd-filter-head">
                <div>
                    <p class="sd-filter-eyebrow">Directory filters</p>
                    <h2>Find a student</h2>
                </div>
                <button type="button" class="sd-filter-clear" id="clearFiltersBtn">Clear all</button>
            </div>

            <div class="sd-filter-grid">
                <div class="sd-field sd-field--search">
                    <label for="searchFilter">Search</label>
                    <span class="sd-search-shell">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                        <input type="search" id="searchFilter" placeholder="Name, admission number or student code" autocomplete="off">
                    </span>
                </div>

                <div class="sd-field">
                    <label for="academicYearFilter">Academic year</label>
                    <select id="academicYearFilter">
                        <option value="">All years</option>
                        <?php foreach ($schoolYears as $year): ?>
                            <option value="<?= e($year) ?>" <?= $year === ($schoolIdentity['year'] ?? '') ? 'selected' : '' ?>><?= e($year) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sd-field">
                    <label for="categoryFilter">Level</label>
                    <select id="categoryFilter">
                        <option value="">All levels</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sd-field">
                    <label for="classFilter">Class</label>
                    <select id="classFilter">
                        <option value="">All classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= e((string) $class['id']) ?>" data-category-id="<?= e((string) $class['category_id']) ?>"><?= e($class['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sd-field">
                    <label for="streamFilter">Stream</label>
                    <select id="streamFilter">
                        <option value="">All streams</option>
                        <?php foreach ($streams as $stream): ?>
                            <option value="<?= e($stream) ?>"><?= e($stream) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sd-field">
                    <label for="dormitoryFilter">Dormitory</label>
                    <select id="dormitoryFilter">
                        <option value="">All dormitories</option>
                        <?php foreach ($dormitories as $dormitory): ?>
                            <option value="<?= e($dormitory) ?>"><?= e($dormitory) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sd-field">
                    <label for="statusFilter">Status</label>
                    <select id="statusFilter">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="transferred">Transferred</option>
                        <option value="graduated">Graduated</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>

                <div class="sd-field sd-field--refresh">
                    <button type="button" class="sd-btn sd-btn--light" id="refreshStudentsBtn">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>
                        Refresh
                    </button>
                </div>
            </div>
        </section>

        <nav class="tabs-nav">
            <button class="tab-btn active" data-target="tab-students">Students</button>
            <button class="tab-btn" data-target="tab-classes">Classes</button>
            <button class="tab-btn" data-target="tab-categories">Categories</button>
        </nav>

        <div id="tab-students" class="tab-content active">
            <section class="sd-table-card">
                <div class="sd-table-head">
                    <div>
                        <p class="eyebrow">Student records</p>
                        <h2>All students</h2>
                    </div>
                    <div class="sd-scope" role="group" aria-label="Filter by education level">
                        <button type="button" class="sd-scope-btn is-active" data-scope="">All</button>
                        <?php foreach ($categories as $index => $category): ?>
                            <button type="button" class="sd-scope-btn" data-scope="<?= e((string) $category['id']) ?>"><?= $index === 0 ? 'Junior' : ($index === 1 ? 'Senior' : e($category['name'])) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="tableFeedback" class="table-feedback" role="alert" aria-live="polite"></div>

                <div class="sd-table-wrap">
                    <table class="sd-table" id="studentTable">
                        <thead>
                            <tr>
                                <th scope="col" aria-sort="none"><button type="button" class="sd-sort" data-sort="name">Student <span aria-hidden="true"></span></button></th>
                                <th scope="col" aria-sort="none"><button type="button" class="sd-sort" data-sort="admission">Admission No. <span aria-hidden="true"></span></button></th>
                                <th scope="col" aria-sort="none"><button type="button" class="sd-sort" data-sort="class">Class <span aria-hidden="true"></span></button></th>
                                <th scope="col" aria-sort="none"><button type="button" class="sd-sort" data-sort="stream">Stream <span aria-hidden="true"></span></button></th>
                                <th scope="col" aria-sort="none"><button type="button" class="sd-sort" data-sort="dormitory">Dormitory <span aria-hidden="true"></span></button></th>
                                <th scope="col" aria-sort="none"><button type="button" class="sd-sort" data-sort="conduct">Conduct <span aria-hidden="true"></span></button></th>
                                <th scope="col" aria-sort="none"><button type="button" class="sd-sort" data-sort="risk">Risk <span aria-hidden="true"></span></button></th>
                                <th scope="col" aria-sort="none"><button type="button" class="sd-sort" data-sort="status">Status <span aria-hidden="true"></span></button></th>
                                <th scope="col" class="sd-actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="studentTableBody">
                            <tr>
                                <td colspan="9" class="sd-loading-cell">
                                    <span class="sd-spinner" aria-hidden="true"></span>
                                    Loading student records…
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="sd-table-foot">
                    <div id="tableMeta" class="table-meta">Loading records…</div>
                    <div class="sd-pagination" id="studentPagination"></div>
                    <label class="sd-page-size" for="pageSize">Rows
                        <select id="pageSize">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                        </select>
                    </label>
                </div>
            </section>
        </div>

        <div id="tab-classes" class="tab-content">
            <section class="dashboard-grid">
                <article class="table-card" style="grid-column: span 12;">
                    <div class="section-heading table-header">
                        <div>
                            <p class="eyebrow">Classes</p>
                            <h2>Directory</h2>
                        </div>
                        <?php if ($canManageStructure): ?>
                            <button type="button" class="ghost-btn danger" id="deleteAllClassesBtn" style="padding: 0.5rem 1rem; font-size: 0.85rem; color: #fb7185; border-color: rgba(251, 113, 133, 0.35);">Delete All Classes</button>
                            <button type="button" class="ghost-btn" id="openAssignTeacherModal" style="padding: 0.5rem 1rem; font-size: 0.85rem;">Assign Teacher</button>
                            <button type="button" class="primary-btn" id="openClassModal" style="padding: 0.5rem 1rem; font-size: 0.85rem;">Add Class</button>
                        <?php endif; ?>
                    </div>
                    <div id="classTableFeedback" class="table-feedback" role="alert" aria-live="polite"></div>
                    <div id="classAccordionContainer" style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                        <div class="table-placeholder">Loading classes...</div>
                    </div>
                </article>
            </section>
        </div>

        <div id="tab-categories" class="tab-content">
            <section class="dashboard-grid">
                <article class="table-card" style="grid-column: span 12;">
                    <div class="section-heading table-header">
                        <div>
                            <p class="eyebrow">Categories</p>
                            <h2>Directory</h2>
                        </div>
                        <?php if ($canManageStructure): ?>
                            <button type="button" class="primary-btn" id="openCategoryModal" style="padding: 0.5rem 1rem; font-size: 0.85rem;">Add Category</button>
                        <?php endif; ?>
                    </div>
                    <div id="categoryTableFeedback" class="table-feedback" role="alert" aria-live="polite"></div>
                    <div class="table-wrap">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th>Category Name</th>
                                    <?php if ($canManageStructure): ?>
                                        <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="categoryTableBody">
                                <tr>
                                    <td colspan="<?= $canManageStructure ? '2' : '1' ?>" class="table-placeholder">Loading categories...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        </div>


    </div>

    <div class="sheet-backdrop hidden" id="studentSheet" role="dialog" aria-modal="true" aria-labelledby="sheetName">
        <div class="sheet-panel sd-profile-panel">
            <div class="sheet-grip" aria-hidden="true"></div>
            <div class="sheet-header sd-profile-head">
                <div class="sd-profile-identity">
                    <div class="sd-avatar sd-avatar--lg" id="sheetAvatar" aria-hidden="true"></div>
                    <div>
                        <p class="eyebrow">Student profile</p>
                        <h2 id="sheetName">Student</h2>
                        <div class="sd-badges" id="sheetBadges"></div>
                    </div>
                </div>
                <button type="button" class="icon-btn" id="closeStudentSheet" aria-label="Close student details">&times;</button>
            </div>
            <div class="sheet-body sd-profile-body">
                <div id="sheetLoading" class="sd-profile-loading">
                    <span class="sd-spinner" aria-hidden="true"></span>
                    Loading profile…
                </div>
                <div class="sd-profile-grid" id="sheetContent" hidden>
                    <div class="sd-profile-main">
                        <section class="sd-panel">
                            <h3>Overview</h3>
                            <dl class="sheet-facts" id="sheetFacts"></dl>
                        </section>
                        <section class="sd-panel">
                            <h3>Parent &amp; guardian contacts</h3>
                            <div id="sheetContacts" class="sd-contact-list"></div>
                        </section>
                        <section class="sd-panel">
                            <h3>Academic &amp; conduct</h3>
                            <div id="sheetAcademic"></div>
                        </section>
                        <section class="sd-panel">
                            <h3>Discipline history</h3>
                            <div id="sheetDiscipline"></div>
                        </section>
                    </div>
                    <aside class="sd-profile-side">
                        <div class="sheet-card" id="sheetCard" hidden>
                            <iframe id="sheetCardFrame" title="Identity card preview" loading="lazy"></iframe>
                        </div>
                        <section class="sd-panel">
                            <h3>Rewards &amp; recognition</h3>
                            <div id="sheetRewards"></div>
                        </section>
                    </aside>
                </div>
            </div>
            <div class="sheet-actions sd-profile-actions">
                <div class="sd-lifecycle-actions" id="sheetLifecycleActions" hidden>
                    <button type="button" class="ghost-btn" data-lifecycle="promote">Promote</button>
                    <button type="button" class="ghost-btn" data-lifecycle="transfer">Transfer</button>
                    <button type="button" class="ghost-btn" data-lifecycle="graduate">Graduate</button>
                    <button type="button" class="ghost-btn" data-lifecycle="archive">Archive</button>
                    <button type="button" class="ghost-btn" data-lifecycle="reactivate">Reactivate</button>
                </div>
                <div class="sd-profile-primary-actions">
                    <a class="primary-btn" id="sheetPrintBtn" href="#" target="_blank" rel="noreferrer" hidden>Print card</a>
                    <button type="button" class="ghost-btn" id="sheetEditBtn" hidden>Edit</button>
                    <button type="button" class="ghost-btn danger" id="sheetDeleteBtn" hidden>Delete</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canManageStructure): ?>
        <div class="modal-backdrop hidden" id="lifecycleModal" role="dialog" aria-modal="true" aria-labelledby="lifecycleTitle">
            <div class="modal-panel sd-lifecycle-modal">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Lifecycle action</p>
                        <h2 id="lifecycleTitle">Update student status</h2>
                        <p class="sd-modal-sub" id="lifecycleStudentName"></p>
                    </div>
                    <button type="button" class="icon-btn" id="closeLifecycleModal" aria-label="Close lifecycle dialog">&times;</button>
                </div>

                <form id="lifecycleForm" class="student-form" method="post" novalidate>
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" id="lifecycleStudentId">
                    <input type="hidden" name="action" id="lifecycleAction">

                    <div id="lifecycleFields"></div>

                    <div id="lifecycleFeedback" class="form-feedback" role="alert" aria-live="polite"></div>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" id="cancelLifecycleModal">Cancel</button>
                        <button type="submit" class="primary-btn" id="lifecycleSubmitBtn">
                            <span class="btn-label">Apply action</span>
                            <span class="btn-loader" hidden></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canManageStudents): ?>
        <div class="modal-backdrop hidden" id="studentModal">
            <div class="modal-panel">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Student Form</p>
                        <h2 id="modalTitle">Add a student</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeStudentModal" aria-label="Close modal">&times;</button>
                </div>

                <form id="studentForm" class="student-form" method="post" enctype="multipart/form-data" novalidate autocomplete="off">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" id="studentId">

                    <div class="field">
                        <label for="fullName">Full name</label>
                        <input type="text" id="fullName" name="full_name" maxlength="120" required>
                    </div>

                    <div class="field">
                        <label for="studentCode">Student code</label>
                        <input type="text" id="studentCode" name="student_code" maxlength="30" placeholder="Auto-generated sequential code" value="" disabled>
                        <p style="font-size:0.85rem; color:var(--color-text-muted); margin-top:0.35rem;">Generated automatically and kept sequential within each class.</p>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="categoryId">Category</label>
                            <select id="categoryId" name="category_id" required>
                                <option value="">Select category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="classId">Class</label>
                            <select id="classId" name="class_id" required>
                                <option value="">Select class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= e((string) $class['id']) ?>" data-category-id="<?= e((string) $class['category_id']) ?>">
                                        <?= e($class['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="dateOfBirth">Date of birth</label>
                            <input type="date" id="dateOfBirth" name="date_of_birth" max="<?= e(date('Y-m-d')) ?>">
                        </div>

                        <div class="field">
                            <label for="parentContact">Parent contact</label>
                            <input type="text" id="parentContact" name="parent_contact" maxlength="20" required>
                        </div>
                    </div>

                    <div class="field">
                        <label for="address">Home address</label>
                        <input type="text" id="address" name="address" maxlength="255" placeholder="Street, city">
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="stream">Stream</label>
                            <input type="text" id="stream" name="stream" maxlength="40" placeholder="e.g. A">
                        </div>

                        <div class="field">
                            <label for="dormitory">Dormitory</label>
                            <input type="text" id="dormitory" name="dormitory" maxlength="60" placeholder="e.g. Kilimanjaro">
                        </div>
                    </div>

                    <div class="field">
                        <label for="schoolYear">Academic year</label>
                        <input type="text" id="schoolYear" name="school_year" list="schoolYearOptions" placeholder="e.g. <?= e((string) ($schoolIdentity['year'] ?: '2026-2027')) ?>" pattern="\d{4}-\d{4}">
                        <datalist id="schoolYearOptions">
                            <?php foreach ($schoolYears as $year): ?>
                                <option value="<?= e($year) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="field">
                        <label for="photoFile">Portrait</label>
                        <div class="sid-photo-row">
                            <img id="photoPreview" class="sid-photo-preview" alt="" hidden>
                            <div class="sid-photo-controls">
                                <input type="file" id="photoFile" name="photo_file" accept="image/*" capture="user">
                                <label class="sid-photo-remove" id="removePhotoWrap" hidden>
                                    <input type="checkbox" name="remove_photo" value="1" id="removePhoto">
                                    <span>Remove current portrait</span>
                                </label>
                                <p class="sid-photo-hint" id="photoCurrentHint">JPG, PNG, WEBP or GIF up to 10 MiB. Printed as-is on the card.</p>
                            </div>
                        </div>
                    </div>

                    <div id="formFeedback" class="form-feedback" role="alert" aria-live="polite"></div>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" id="cancelStudentModal">Cancel</button>
                        <button type="submit" class="primary-btn" id="studentSubmitButton">
                            <span class="btn-label">Save Student</span>
                            <span class="btn-loader" hidden></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-backdrop hidden" id="uploadModal">
            <div class="modal-panel">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Bulk Import</p>
                        <h2 id="uploadModalTitle">Upload Students</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeUploadModal" aria-label="Close modal">&times;</button>
                </div>

                <form id="uploadForm" class="student-form" method="post" enctype="multipart/form-data" novalidate>
                    <?= csrf_input() ?>

                    <div class="form-grid" style="margin-bottom: 1rem;">
                        <div class="field">
                            <label for="uploadCategoryId">Category</label>
                            <select id="uploadCategoryId" name="category_id" required>
                                <option value="">Select category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="uploadClassId">Class</label>
                            <select id="uploadClassId" name="class_id" required>
                                <option value="">Select class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= e((string) $class['id']) ?>" data-category-id="<?= e((string) $class['category_id']) ?>">
                                        <?= e($class['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="field" style="margin-bottom: 1rem;">
                        <p style="font-size: 0.9rem; color: var(--color-text-muted); margin-bottom: 0.5rem;">
                            Download the template, paste only student names from Excel, choose the target class and category, and upload it back here.
                        </p>
                        <button type="button" class="ghost-btn" style="display: inline-block; padding: 0.5rem 1rem;" onclick="window.location.href='<?= e(app_base_url()) ?>/students/template.php'">Download Template</button>
                    </div>

                    <div class="field">
                        <label for="csvFile">Excel or CSV File</label>
                        <input type="file" id="csvFile" name="csv_file" accept=".csv,.xlsx" style="display: block; width: 100%; padding: 0.5rem;">
                    </div>

                    <div class="field">
                        <label for="pasteData">Or paste from Excel</label>
                        <textarea id="pasteData" name="paste_data" rows="7" placeholder="Copy the names column from Excel and paste it here. One student name per row is enough." style="width: 100%; padding: 0.75rem; resize: vertical;"></textarea>
                        <p style="font-size: 0.85rem; color: var(--color-text-muted); margin: 0.35rem 0 0;">You can paste a single column of names directly from Excel.</p>
                    </div>

                    <div id="uploadFormFeedback" class="form-feedback" role="alert" aria-live="polite"></div>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" id="cancelUploadModal">Cancel</button>
                        <button type="submit" class="primary-btn" id="uploadSubmitButton">
                            <span class="btn-label">Upload</span>
                            <span class="btn-loader" hidden></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-backdrop hidden" id="classModal">
            <div class="modal-panel">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Class Form</p>
                        <h2 id="classModalTitle">Add a class</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeClassModal" aria-label="Close modal">&times;</button>
                </div>

                <form id="classForm" class="student-form" method="post" novalidate>
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" id="classIdInput">

                    <div class="field">
                        <label for="className">Class name</label>
                        <input type="text" id="className" name="name" maxlength="120" required>
                    </div>

                    <div class="field">
                        <label for="classCategoryId">Category</label>
                        <select id="classCategoryId" name="category_id" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="classTeacherId">Class teacher</label>
                        <select id="classTeacherId" name="class_teacher_user_id">
                            <option value="">-- None --</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= e((string) $teacher['id']) ?>"><?= e($teacher['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="classFormFeedback" class="form-feedback" role="alert" aria-live="polite"></div>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" id="cancelClassModal">Cancel</button>
                        <button type="submit" class="primary-btn" id="classSubmitButton">
                            <span class="btn-label">Save Class</span>
                            <span class="btn-loader" hidden></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-backdrop hidden" id="assignTeacherModal">
            <div class="modal-panel">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Assign Teacher</p>
                        <h2 id="assignTeacherModalTitle">Assign a teacher to a class</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeAssignTeacherModal" aria-label="Close modal">&times;</button>
                </div>

                <form id="assignTeacherForm" class="student-form" method="post" novalidate>
                    <?= csrf_input() ?>

                    <div class="field">
                        <label for="assignTeacherClassId">Class</label>
                        <select id="assignTeacherClassId" name="id" required>
                            <option value="">Select a class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= e((string) $class['id']) ?>"><?= e($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="assignTeacherId">Teacher</label>
                        <select id="assignTeacherId" name="class_teacher_user_id" required>
                            <option value="">Select a teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= e((string) $teacher['id']) ?>"><?= e($teacher['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="assignTeacherFeedback" class="form-feedback" role="alert" aria-live="polite"></div>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" id="cancelAssignTeacherModal">Cancel</button>
                        <button type="submit" class="primary-btn" id="assignTeacherSubmitButton">
                            <span class="btn-label">Assign Teacher</span>
                            <span class="btn-loader" hidden></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-backdrop hidden" id="reportModal">
            <div class="modal-panel">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Class Report</p>
                        <h2 id="reportModalTitle">Download Report</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeReportModal" aria-label="Close modal">&times;</button>
                </div>

                <form id="reportForm" class="student-form" method="get" action="<?= e(app_base_url()) ?>/reports/class_term.php" target="_blank" novalidate>
                    <input type="hidden" name="class_id" id="reportClassId">

                    <div class="field">
                        <label for="reportTerm">Select Term</label>
                        <select id="reportTerm" name="term" required>
                            <option value="1">Term 1</option>
                            <option value="2">Term 2</option>
                            <option value="3">Term 3</option>
                        </select>
                    </div>
                    
                    <div class="field">
                        <label for="reportFormat">Format</label>
                        <select id="reportFormat" name="format" required>
                            <option value="csv">CSV</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" id="cancelReportModal">Cancel</button>
                        <button type="submit" class="primary-btn" id="reportSubmitButton">
                            <span class="btn-label">Download Report</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-backdrop hidden" id="categoryModal">
            <div class="modal-panel">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Category Form</p>
                        <h2 id="categoryModalTitle">Add a category</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeCategoryModal" aria-label="Close modal">&times;</button>
                </div>

                <form id="categoryForm" class="student-form" method="post" novalidate>
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" id="categoryIdInput">

                    <div class="field">
                        <label for="categoryName">Category name</label>
                        <input type="text" id="categoryName" name="name" maxlength="120" required>
                    </div>

                    <div id="categoryFormFeedback" class="form-feedback" role="alert" aria-live="polite"></div>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" id="cancelCategoryModal">Cancel</button>
                        <button type="submit" class="primary-btn" id="categorySubmitButton">
                            <span class="btn-label">Save Category</span>
                            <span class="btn-loader" hidden></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script>
        window.SBMSStudentConfig = {
            baseUrl: '<?= e(app_base_url()) ?>',
            cardUrl: '<?= e(app_base_url()) ?>/students/card.php',
            fetchUrl: '<?= e(app_base_url()) ?>/students/fetch.php',
            createUrl: '<?= e(app_base_url()) ?>/students/create.php',
            updateUrl: '<?= e(app_base_url()) ?>/students/update.php',
            deleteUrl: '<?= e(app_base_url()) ?>/students/delete.php',
            importUrl: '<?= e(app_base_url()) ?>/students/import.php',
            detailUrl: '<?= e(app_base_url()) ?>/students/detail.php',
            lifecycleUrl: '<?= e(app_base_url()) ?>/students/lifecycle.php',
            currentYear: <?= json_encode((string) ($schoolIdentity['year'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            maxMarks: <?= (int) $maxMarks ?>,

            classFetchUrl: '<?= e(app_base_url()) ?>/classes/fetch.php',
            classCreateUrl: '<?= e(app_base_url()) ?>/classes/create.php',
            classUpdateUrl: '<?= e(app_base_url()) ?>/classes/update.php',
            classDeleteUrl: '<?= e(app_base_url()) ?>/classes/delete.php',
            classAssignTeacherUrl: '<?= e(app_base_url()) ?>/classes/assign_teacher.php',

            categoryFetchUrl: '<?= e(app_base_url()) ?>/categories/fetch.php',
            categoryCreateUrl: '<?= e(app_base_url()) ?>/categories/create.php',
            categoryUpdateUrl: '<?= e(app_base_url()) ?>/categories/update.php',
            categoryDeleteUrl: '<?= e(app_base_url()) ?>/categories/delete.php',
            canManage: <?= $canManageStudents ? 'true' : 'false' ?>,
            canManageStructure: <?= $canManageStructure ? 'true' : 'false' ?>,
            school: <?= json_encode($schoolIdentity, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            csrfToken: '<?= e(csrf_token()) ?>'
        };
    </script>
    <script src="<?= e(app_base_url()) ?>/assets/vendor/jquery-3.7.1.min.js"></script>
    <script src="<?= e(app_base_url()) ?>/assets/js/student-management.js?v=<?= time() ?>"></script>
</body>
</html>
