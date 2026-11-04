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
$classes           = $initialData['classes'] ?? [];
$teachers          = $initialData['teachers'] ?? [];
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
        <section class="hero-card hero-compact">
            <div class="hero-copy">
                <p class="eyebrow">SBMS Module</p>
                <h1>Student directory</h1>
                <p class="hero-text">Search learners, edit records and print identity cards.</p>
            </div>
            <div class="hero-actions">
                <?php if ($canManageStudents): ?>
                    <button type="button" class="ghost-btn" id="openUploadModal">Import</button>
                    <button type="button" class="primary-btn" id="openStudentModal">Add Student</button>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($canManageStudents): ?>
            <div class="directory-danger">
                <button type="button" class="link-danger" id="deleteAllStudentsBtn">Delete all students</button>
            </div>
        <?php endif; ?>

        <section class="filter-bar" aria-label="Student filters">
            <div class="field filter-search">
                <label for="searchFilter">Search</label>
                <input type="search" id="searchFilter" placeholder="Name, code or ID" autocomplete="off">
            </div>
            <div class="field">
                <label for="classFilter">Class</label>
                <select id="classFilter">
                    <option value="">All classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= e((string) $class['id']) ?>"><?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="categoryFilter">Level</label>
                <select id="categoryFilter">
                    <option value="">All levels</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field filter-spacer" aria-hidden="true"></div>
            <div class="field filter-actions">
                <button type="button" class="ghost-btn" id="expandAllBtn">Show all</button>
                <button type="button" class="ghost-btn" id="collapseAllBtn">Hide all</button>
                <button type="button" class="ghost-btn" id="refreshStudentsBtn">Refresh</button>
            </div>
        </section>

        <nav class="tabs-nav">
            <button class="tab-btn active" data-target="tab-students">Students</button>
            <button class="tab-btn" data-target="tab-classes">Classes</button>
            <button class="tab-btn" data-target="tab-categories">Categories</button>
        </nav>

        <div id="tab-students" class="tab-content active">
            <section class="dashboard-grid">
            
            <article class="table-card">
                <div class="section-heading table-header">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--brand-2, #38bdf8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <div>
                            <p class="eyebrow">Students</p>
                            <h2>Directory</h2>
                        </div>
                    </div>
                    <div id="tableMeta" class="table-meta">Loading records...</div>
                </div>

                <div id="tableFeedback" class="table-feedback" role="alert" aria-live="polite"></div>

                <div id="studentAccordionContainer" style="display: flex; flex-direction: column; gap: 1rem;">
                    <div class="table-placeholder">Loading students...</div>
                </div>
            </article>

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
        <div class="sheet-panel">
            <div class="sheet-grip" aria-hidden="true"></div>
            <div class="sheet-header">
                <div>
                    <p class="eyebrow">Student</p>
                    <h2 id="sheetName">Student</h2>
                </div>
                <button type="button" class="icon-btn" id="closeStudentSheet" aria-label="Close student details">&times;</button>
            </div>
            <div class="sheet-body">
                <div class="sheet-card" id="sheetCard" hidden>
                    <iframe id="sheetCardFrame" title="Identity card preview" loading="lazy"></iframe>
                </div>
                <dl class="sheet-facts" id="sheetFacts"></dl>
            </div>
            <div class="sheet-actions">
                <a class="primary-btn" id="sheetPrintBtn" href="#" target="_blank" rel="noreferrer" hidden>Print card</a>
                <button type="button" class="ghost-btn" id="sheetEditBtn" hidden>Edit</button>
                <button type="button" class="ghost-btn danger" id="sheetDeleteBtn" hidden>Delete</button>
            </div>
        </div>
    </div>

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
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= e(app_base_url()) ?>/assets/js/student-management.js?v=<?= time() ?>"></script>
</body>
</html>
