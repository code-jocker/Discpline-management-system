<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/DisciplineController.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/models/SchoolYear.php';
require_once dirname(__DIR__) . '/models/plexus.php';

require_auth();

$controller = new DisciplineController();
$authUser = current_auth_user();
// Students are served through the paged search endpoint instead of being rendered in full.
$pageData = $controller->pageData($authUser, false);
$classes = $pageData['classes'] ?? [];
$canDeduct = !empty($pageData['can_deduct']);

$settingsModel = new Settings(Database::connection());
$currentTerm = SchoolYear::normalizeTerm($settingsModel->get('current_term', 1));
$schoolYear = (new SchoolYear(Database::connection()))->resolveSelected($settingsModel);
$schoolYearName = (string) ($schoolYear['name'] ?? SchoolYear::calculateCurrentAcademicYear());
$maxMarksPerTerm = max(1, min(40, (int) $settingsModel->get('max_marks_per_term', '40')));

$flags = [
    'baseUrl' => app_base_url(),
    'currentTerm' => $currentTerm,
    'maxMarks' => $maxMarksPerTerm,
    'classes' => array_map(static fn (array $class): array => [
        'id' => (int) $class['id'],
        'name' => (string) $class['name'],
    ], $classes),
];

?>
<?php
$pageTitle = 'SBMS Quick Log';
$bodyClass = 'discipline-body sbms-plexus-body quick-log-page';
$mainClass = 'discipline-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/discipline-engine.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
        <section class="discipline-hero">
            <div class="hero-copy">
                <p class="eyebrow">Discipline Engine</p>
                <h1>Record an action in seconds — from any device.</h1>
                <p class="hero-text">
                    The same discipline engine, organised into four short steps: choose the action, choose who it applies to,
                    add the details, then review. Marks, risk level, guardians, notifications and the audit trail all update automatically.
                </p>
                <div class="hero-links" style="margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <a class="btn-secondary dashboard-link inline-link" href="<?= e(app_base_url()) ?>/discipline/index.php">Open Full Discipline Engine</a>
                    <a class="btn-primary dashboard-link inline-link" href="<?= e(app_base_url()) ?>/students/index.php">Open Student Workspace</a>
                </div>
            </div>
            <div class="notification-card">
                <p class="eyebrow">Recommended order</p>
                <h2 style="margin-top: 0;">How to file a record</h2>
                <ol style="margin: 1rem 0 0; padding-left: 1.25rem; color: var(--of-muted, #64748b); display: grid; gap: 0.65rem;">
                    <li>Select the student and the incident category.</li>
                    <li>Record the date and a short factual description.</li>
                    <li>Attach evidence if it is available.</li>
                    <li>Submit — marks, risk level, and guardians are updated automatically.</li>
                    <li>Review the entry in the discipline log afterwards.</li>
                </ol>
            </div>
        </section>

        <section class="history-card" style="margin-top: 1.5rem;">
            <?php if (!$canDeduct): ?>
                <div class="feedback error" role="alert" style="margin-top: 1rem;">You do not have permission to use quick log.</div>
            <?php else: ?>
                <form id="quickLogForm" class="ql-card" enctype="multipart/form-data" novalidate autocomplete="off">
                    <?= csrf_input() ?>

                    <!-- Section A — action -->
                    <div class="ql-section">
                        <h2 class="ql-section-title"><span class="ql-step">1</span> Choose the action</h2>
                        <p class="ql-section-hint">One tap switches the whole form — no page reload.</p>

                        <div class="ql-actions" role="radiogroup" aria-label="Action type">
                            <label class="ql-action-card is-selected" data-action="deduct">
                                <input class="ql-action-radio" type="radio" name="action_type" value="deduct" checked>
                                <span class="ql-action-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 12h7"/></svg>
                                </span>
                                <span>
                                    <span class="ql-action-name">Deduct marks</span>
                                    <span class="ql-action-desc">Remove marks for a policy or conduct breach.</span>
                                </span>
                                <span class="ql-action-check" aria-hidden="true">&#10003;</span>
                            </label>

                            <label class="ql-action-card" data-action="add">
                                <input class="ql-action-radio" type="radio" name="action_type" value="add">
                                <span class="ql-action-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4h8v3.5a4 4 0 0 1-8 0V4z"/><path d="M8 5.5H5.5V7A2.5 2.5 0 0 0 8 9.5"/><path d="M16 5.5h2.5V7A2.5 2.5 0 0 1 16 9.5"/><path d="M12 12v4"/><path d="M10 14h4"/><path d="M9 20h6"/></svg>
                                </span>
                                <span>
                                    <span class="ql-action-name">Reward / Add</span>
                                    <span class="ql-action-desc">Give back or award marks for positive conduct.</span>
                                </span>
                                <span class="ql-action-check" aria-hidden="true">&#10003;</span>
                            </label>

                            <label class="ql-action-card" data-action="set">
                                <input class="ql-action-radio" type="radio" name="action_type" value="set">
                                <span class="ql-action-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 8h9"/><path d="M19 8h1"/><circle cx="16" cy="8" r="2.2"/><path d="M4 16h5"/><path d="M13 16h7"/><circle cx="11" cy="16" r="2.2"/></svg>
                                </span>
                                <span>
                                    <span class="ql-action-name">Set marks</span>
                                    <span class="ql-action-desc">Correct the balance to an exact value.</span>
                                </span>
                                <span class="ql-action-check" aria-hidden="true">&#10003;</span>
                            </label>
                        </div>
                    </div>

                    <!-- Section B — who -->
                    <div class="ql-section">
                        <h2 class="ql-section-title"><span class="ql-step">2</span> Who it applies to</h2>

                        <div class="ql-grid">
                            <div class="field ql-combo" id="quickLogClassCombo">
                                <label for="quickLogClassFilter">Class</label>
                                <input type="text" id="quickLogClassFilter" placeholder="Search classes..." role="combobox" aria-expanded="false" aria-autocomplete="list" data-class-id="">
                                <div class="ql-list" id="quickLogClassList" role="listbox"></div>
                                <p class="ql-combo-note">Filters the student search.</p>
                            </div>

                            <div class="field">
                                <label for="quickLogTerm">Term</label>
                                <select id="quickLogTerm" name="term" required>
                                    <?php foreach (SchoolYear::terms() as $termOption): ?>
                                        <option value="<?= (int) $termOption ?>" <?= $termOption === $currentTerm ? 'selected' : '' ?>>Term <?= (int) $termOption ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="ql-combo-note">Academic year <?= e($schoolYearName) ?></p>
                            </div>

                            <div class="field ql-combo ql-span-all" id="quickLogStudentCombo">
                                <label for="quickLogSearch">Search student by name, admission number or ID</label>
                                <input type="text" id="quickLogSearch" placeholder="Start typing at least two letters..." role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="quickLogStudentList" minlength="2">
                                <div class="ql-list" id="quickLogStudentList" role="listbox"></div>

                                <select id="quickLogStudentId" name="student_id" required hidden>
                                    <option value="">Select student</option>
                                </select>

                                <div class="ql-picked" id="quickLogPicked">
                                    <span class="ql-picked-avatar" id="quickLogPickedAvatar">--</span>
                                    <span class="ql-picked-body">
                                        <span class="ql-picked-name" id="quickLogPickedName"></span>
                                        <span class="ql-picked-meta" id="quickLogPickedMeta"></span>
                                        <span class="ql-signals" id="quickLogSignals"></span>
                                    </span>
                                    <button type="button" class="ql-clear-btn" id="quickLogClearStudent">Change</button>
                                </div>
                            </div>

                            <div class="ql-span-all">
                                <label class="ql-switch">
                                    <input type="checkbox" id="quickLogApplyClass">
                                    <span class="ql-switch-text">Apply to whole class</span>
                                </label>
                                <div class="ql-bulk" id="quickLogBulkPanel">
                                    <strong id="quickLogBulkCount">Checking the class roster...</strong>
                                    <div id="quickLogBulkDetail" style="margin-top:.3rem;"></div>
                                    <button type="button" class="ghost-btn" id="quickLogBulkConfirm" style="margin-top:.6rem;">Confirm bulk action</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section C — details -->
                    <div class="ql-section">
                        <h2 class="ql-section-title"><span class="ql-step">3</span> Action details</h2>

                        <div class="ql-grid">
                            <div class="field">
                                <label for="quickLogMarks" id="quickLogMarksLabel">Marks to deduct</label>
                                <input type="number" id="quickLogMarks" name="marks_to_deduct" min="1" max="<?= (int) $maxMarksPerTerm ?>" value="1" required inputmode="numeric" placeholder="1">
                                <p class="ql-combo-note" id="quickLogMarksHint">Balance after this action is shown in the review.</p>
                            </div>

                            <div class="field ql-combo" id="quickLogCategoryCombo">
                                <label for="quickLogCategory">Incident category</label>
                                <input type="text" id="quickLogCategory" name="incident_category" maxlength="100" placeholder="Search categories..." role="combobox" aria-expanded="false" aria-autocomplete="list" autocomplete="off">
                                <div class="ql-list" id="quickLogCategoryList" role="listbox"></div>
                                <p class="ql-combo-note">Picking a preset fills in the usual mark value. Free text is still accepted.</p>
                            </div>

                            <div class="field ql-span-all">
                                <label for="quickLogReason">Reason</label>
                                <textarea id="quickLogReason" name="reason" rows="3" minlength="5" maxlength="500" required placeholder="Short but clear reason..."></textarea>
                                <p class="ql-combo-note"><span id="quickLogReasonCount">0</span> / 500 characters</p>
                            </div>

                            <div class="field ql-span-all">
                                <label for="quickLogEvidence">Evidence file</label>
                                <input type="file" id="quickLogEvidence" name="evidence_file" accept="image/*,.pdf,.doc,.docx,.txt,.csv,.xls,.xlsx" hidden>
                                <div class="ql-evidence-actions">
                                    <label class="ql-file-btn" for="quickLogEvidence">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v3a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-3"/><path d="M12 3v12"/><path d="M7 8l5-5 5 5"/></svg>
                                        Choose file
                                    </label>
                                    <button type="button" class="ql-file-btn" id="quickLogCameraBtn">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/></svg>
                                        Take photo
                                    </button>
                                    <input type="file" id="quickLogCamera" accept="image/*" capture="environment" hidden>
                                </div>
                                <div class="ql-file-visual" id="quickLogFilePreview">
                                    <span class="ql-file-thumb" id="quickLogFileThumb">file</span>
                                    <span style="min-width:0;flex:1;">
                                        <span class="ql-file-name" id="quickLogFileName"></span>
                                        <span class="ql-file-size" id="quickLogFileSize"></span>
                                    </span>
                                    <button type="button" class="ql-clear-btn" id="quickLogRemoveFile">Remove</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section D — review -->
                    <div class="ql-section">
                        <h2 class="ql-section-title"><span class="ql-step">4</span> Review and submit</h2>

                        <div id="quickLogFeedback" class="ql-feedback" role="status" aria-live="polite"></div>

                        <dl class="ql-summary" id="quickLogSummary">
                            <div class="ql-summary-item">
                                <dt class="ql-summary-label">Action</dt>
                                <dd class="ql-summary-value" id="quickLogSumAction">Deduct marks</dd>
                            </div>
                            <div class="ql-summary-item">
                                <dt class="ql-summary-label">Target</dt>
                                <dd class="ql-summary-value" id="quickLogSumTarget">No student selected</dd>
                            </div>
                            <div class="ql-summary-item">
                                <dt class="ql-summary-label">Term</dt>
                                <dd class="ql-summary-value" id="quickLogSumTerm">Term <?= (int) $currentTerm ?></dd>
                            </div>
                            <div class="ql-summary-item">
                                <dt class="ql-summary-label">Marks change</dt>
                                <dd class="ql-summary-value" id="quickLogSumDelta" data-tone="danger">-1</dd>
                            </div>
                            <div class="ql-summary-item">
                                <dt class="ql-summary-label">Balance after</dt>
                                <dd class="ql-summary-value" id="quickLogSumBalance">--</dd>
                            </div>
                            <div class="ql-summary-item">
                                <dt class="ql-summary-label">Category</dt>
                                <dd class="ql-summary-value" id="quickLogSumCategory">Not set</dd>
                            </div>
                            <div class="ql-summary-item">
                                <dt class="ql-summary-label">Evidence</dt>
                                <dd class="ql-summary-value" id="quickLogSumEvidence">None attached</dd>
                            </div>
                        </dl>

                        <div class="ql-submit-row">
                            <button type="button" class="ghost-btn" id="quickLogResetBtn">Reset</button>
                            <button type="submit" class="ql-submit" id="quickLogSubmitBtn" data-action="deduct">
                                <span class="btn-label">Deduct marks</span>
                                <span class="btn-loader" hidden></span>
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="dashboard-grid" style="margin-top: 1.5rem;">
            <article class="auth-panel dashboard-module-card">
                <p class="eyebrow">Attendance signals</p>
                <h2>Late and absence tracking</h2>
                <p class="meta-text" style="margin:.35rem 0 .9rem;">
                    Categories carry an attendance signal, so the counters under the selected student are built from the discipline log itself —
                    no separate register to keep in sync.
                </p>
                <ul class="feature-list dashboard-list">
                    <li>Late arrival and absence categories feed the student's term signal count.</li>
                    <li>Reward categories raise marks and are recorded in the same ledger.</li>
                    <li>Parent portal for read-only access to reports and history.</li>
                    <li>Smart alerts, task automation, and retry logic.</li>
                </ul>
            </article>
        </section>

        <script type="application/json" id="quickLogConfig"><?= json_encode($flags, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
        <script>
        (function () {
            const form = document.getElementById('quickLogForm');
            if (!form) return;

            const cfg = JSON.parse(document.getElementById('quickLogConfig').textContent);
            const ENDPOINT = cfg.baseUrl + '/discipline/deduct.php';
            const STUDENT_SEARCH_URL = cfg.baseUrl + '/students/fetch.php';
            const CATEGORY_URL = cfg.baseUrl + '/discipline/incident_categories.php';
            const SIGNALS_URL = cfg.baseUrl + '/discipline/student_signals.php';
            const MAX_BYTES = 10 * 1024 * 1024;

            const el = {
                feedback: document.getElementById('quickLogFeedback'),
                classInput: document.getElementById('quickLogClassFilter'),
                classList: document.getElementById('quickLogClassList'),
                search: document.getElementById('quickLogSearch'),
                studentList: document.getElementById('quickLogStudentList'),
                studentSelect: document.getElementById('quickLogStudentId'),
                picked: document.getElementById('quickLogPicked'),
                pickedAvatar: document.getElementById('quickLogPickedAvatar'),
                pickedMeta: document.getElementById('quickLogPickedMeta'),
                pickedName: document.getElementById('quickLogPickedName'),
                clearStudent: document.getElementById('quickLogClearStudent'),
                signals: document.getElementById('quickLogSignals'),
                applyClass: document.getElementById('quickLogApplyClass'),
                bulkPanel: document.getElementById('quickLogBulkPanel'),
                bulkCount: document.getElementById('quickLogBulkCount'),
                bulkDetail: document.getElementById('quickLogBulkDetail'),
                bulkConfirm: document.getElementById('quickLogBulkConfirm'),
                term: document.getElementById('quickLogTerm'),
                marks: document.getElementById('quickLogMarks'),
                marksLabel: document.getElementById('quickLogMarksLabel'),
                reason: document.getElementById('quickLogReason'),
                reasonCount: document.getElementById('quickLogReasonCount'),
                category: document.getElementById('quickLogCategory'),
                categoryList: document.getElementById('quickLogCategoryList'),
                evidence: document.getElementById('quickLogEvidence'),
                camera: document.getElementById('quickLogCamera'),
                cameraBtn: document.getElementById('quickLogCameraBtn'),
                filePreview: document.getElementById('quickLogFilePreview'),
                fileThumb: document.getElementById('quickLogFileThumb'),
                fileName: document.getElementById('quickLogFileName'),
                fileSize: document.getElementById('quickLogFileSize'),
                removeFile: document.getElementById('quickLogRemoveFile'),
                submit: document.getElementById('quickLogSubmitBtn'),
                submitLabel: document.querySelector('#quickLogSubmitBtn .btn-label'),
                submitLoader: document.querySelector('#quickLogSubmitBtn .btn-loader'),
                reset: document.getElementById('quickLogResetBtn'),
                sumAction: document.getElementById('quickLogSumAction'),
                sumTarget: document.getElementById('quickLogSumTarget'),
                sumTerm: document.getElementById('quickLogSumTerm'),
                sumDelta: document.getElementById('quickLogSumDelta'),
                sumBalance: document.getElementById('quickLogSumBalance'),
                sumCategory: document.getElementById('quickLogSumCategory'),
                sumEvidence: document.getElementById('quickLogSumEvidence'),
            };

            const ACTIONS = {
                deduct: { label: 'Deduct marks', submit: 'Deduct marks' },
                add: { label: 'Reward / Add', submit: 'Apply reward' },
                set: { label: 'Set marks', submit: 'Set marks balance' },
            };

            const state = {
                action: 'deduct',
                student: null,
                snapshot: null,
                categories: [],
                categoriesLoaded: false,
                marksTouched: false,
                bulk: false,
                bulkConfirmed: false,
                bulkCount: null,
                submitting: false,
                objectUrl: null,
            };

            let studentRequest = null;
            let studentTimer = null;
            let signalsTimer = null;

            function setMessage(message, kind) {
                el.feedback.className = 'ql-feedback' + (message ? ' is-visible ' + (kind || 'success') : '');
                el.feedback.textContent = message || '';
            }

            function formatBytes(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }

            function clamp(value, min, max) {
                return Math.max(min, Math.min(max, value));
            }

            function currentAction() {
                const checked = form.querySelector('input[name="action_type"]:checked');
                return checked ? checked.value : 'deduct';
            }

            function marksValue() {
                const raw = parseInt(el.marks.value, 10);
                return Number.isNaN(raw) ? null : raw;
            }

            // ---------- generic searchable dropdown ----------
            function createCombo(input, list, getOptions, onPick) {
                let open = false;
                let activeIndex = -1;
                let items = [];

                function close() {
                    open = false;
                    list.classList.remove('is-open');
                    input.setAttribute('aria-expanded', 'false');
                    activeIndex = -1;
                }

                function paint() {
                    list.textContent = '';
                    items = getOptions();
                    if (!items.length) {
                        const empty = document.createElement('div');
                        empty.className = 'ql-empty';
                        empty.textContent = 'No matches';
                        list.appendChild(empty);
                    }
                    items.forEach(function (item, index) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'ql-option' + (index === activeIndex ? ' is-active' : '');
                        button.setAttribute('role', 'option');

                        const main = document.createElement('span');
                        main.className = 'ql-option-main';
                        main.textContent = item.main;
                        button.appendChild(main);

                        if (item.meta) {
                            const meta = document.createElement('span');
                            meta.className = 'ql-option-meta';
                            meta.textContent = item.meta;
                            button.appendChild(meta);
                        }

                        button.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                            onPick(item);
                            close();
                        });
                        list.appendChild(button);
                    });
                }

                function openList() {
                    paint();
                    open = true;
                    list.classList.add('is-open');
                    input.setAttribute('aria-expanded', 'true');
                }

                input.addEventListener('focus', openList);
                input.addEventListener('input', function () {
                    activeIndex = -1;
                    if (!open) openList();
                    else paint();
                });
                input.addEventListener('blur', function () {
                    window.setTimeout(close, 120);
                });
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (!open) { openList(); return; }
                        activeIndex = clamp(activeIndex + (event.key === 'ArrowDown' ? 1 : -1), 0, Math.max(items.length - 1, 0));
                        paint();
                        return;
                    }
                    if (event.key === 'Escape') { close(); return; }
                    if (event.key === 'Enter' && open && items.length) {
                        event.preventDefault();
                        onPick(items[activeIndex < 0 ? 0 : activeIndex]);
                        close();
                    }
                });

                return { close: close, refresh: function () { if (open) paint(); } };
            }

            // ---------- class combo ----------
            const classOptions = (cfg.classes || []).map(function (item) {
                return { main: String(item.name), id: String(item.id) };
            });

            const classCombo = createCombo(
                el.classInput,
                el.classList,
                function () {
                    const needle = el.classInput.value.trim().toLowerCase();
                    const all = [{ main: 'All classes', id: '' }];
                    return all.concat(classOptions.filter(function (item) {
                        return !needle || item.main.toLowerCase().indexOf(needle) !== -1;
                    }));
                },
                function (item) {
                    el.classInput.value = item.main;
                    el.classInput.dataset.classId = item.id;
                    onClassChanged();
                }
            );

            function selectedClassId() {
                return el.classInput.dataset.classId || '';
            }

            function onClassChanged() {
                classCombo.refresh();
                state.bulkConfirmed = false;
                if (state.bulk) refreshBulkCount();
                renderSummary();
            }

            // ---------- incident categories ----------
            function loadCategories() {
                if (state.categoriesLoaded) return Promise.resolve(state.categories);
                return fetch(CATEGORY_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('category load failed')); })
                    .then(function (payload) {
                        state.categories = (payload && payload.data && payload.data.items) || [];
                        state.categoriesLoaded = true;
                        categoryCombo.refresh();
                        return state.categories;
                    })
                    .catch(function () {
                        // The field still works as free text when the presets cannot load.
                        state.categoriesLoaded = false;
                        return [];
                    });
            }

            const categoryCombo = createCombo(
                el.category,
                el.categoryList,
                function () {
                    const needle = el.category.value.trim().toLowerCase();
                    return state.categories
                        .filter(function (item) { return item.action_type === state.action; })
                        .filter(function (item) { return !needle || item.name.toLowerCase().indexOf(needle) !== -1; })
                        .map(function (item) {
                            return {
                                main: item.name,
                                meta: (item.action_type === 'add' ? '+' : '-') + item.default_marks + ' marks' + (item.signal_type ? ' · ' + item.signal_type : ''),
                                item: item,
                            };
                        });
                },
                function (item) {
                    if (!item.item) return;
                    el.category.value = item.item.name;
                    if (!state.marksTouched) {
                        el.marks.value = clamp(parseInt(item.item.default_marks, 10) || 1, actionMin(), cfg.maxMarks);
                    }
                    renderSummary();
                }
            );

            el.category.addEventListener('focus', function () { loadCategories(); });

            // ---------- student autocomplete ----------
            function runStudentSearch() {
                const needle = el.search.value.trim();
                if (studentRequest) { studentRequest.abort(); studentRequest = null; }
                if (needle.length < 2) {
                    el.studentList.textContent = '';
                    el.studentList.classList.remove('is-open');
                    return;
                }

                const params = new URLSearchParams({ search: needle, limit: '25' });
                const classId = selectedClassId();
                if (classId) params.set('class_id', classId);

                studentRequest = new AbortController();
                fetch(STUDENT_SEARCH_URL + '?' + params.toString(), {
                    signal: studentRequest.signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('search failed')); })
                    .then(function (payload) {
                        const items = (payload && payload.data && payload.data.items) || [];
                        renderStudentResults(items);
                    })
                    .catch(function (error) {
                        if (error && error.name === 'AbortError') return;
                        el.studentList.textContent = '';
                        const empty = document.createElement('div');
                        empty.className = 'ql-empty';
                        empty.textContent = 'Search is unavailable right now.';
                        el.studentList.appendChild(empty);
                        el.studentList.classList.add('is-open');
                    });
            }

            function renderStudentResults(items) {
                el.studentList.textContent = '';
                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.className = 'ql-empty';
                    empty.textContent = 'No student matches that search.';
                    el.studentList.appendChild(empty);
                    el.studentList.classList.add('is-open');
                    return;
                }

                items.forEach(function (student) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'ql-option';
                    button.setAttribute('role', 'option');

                    const main = document.createElement('span');
                    main.className = 'ql-option-main';
                    main.textContent = student.full_name + ' (' + student.student_code + ')';
                    button.appendChild(main);

                    const meta = document.createElement('span');
                    meta.className = 'ql-option-meta';
                    meta.textContent = student.class_name || 'Unassigned class';
                    button.appendChild(meta);

                    button.addEventListener('mousedown', function (event) { event.preventDefault(); });
                    button.addEventListener('click', function () { pickStudent(student); });
                    el.studentList.appendChild(button);
                });
                el.studentList.classList.add('is-open');
            }

            el.search.addEventListener('input', function () {
                window.clearTimeout(studentTimer);
                studentTimer = window.setTimeout(runStudentSearch, 300);
            });
            el.search.addEventListener('focus', function () { runStudentSearch(); });
            el.search.addEventListener('blur', function () {
                window.setTimeout(function () { el.studentList.classList.remove('is-open'); }, 140);
            });
            el.search.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') el.studentList.classList.remove('is-open');
            });

            function pickStudent(student) {
                state.student = student;
                state.bulk = false;
                state.bulkConfirmed = false;
                el.applyClass.checked = false;
                el.bulkPanel.classList.remove('is-visible');
                el.search.disabled = false;

                el.studentSelect.hidden = false;
                let option = el.studentSelect.querySelector('option[value="' + student.id + '"]');
                if (!option) {
                    option = document.createElement('option');
                    option.value = String(student.id);
                    el.studentSelect.appendChild(option);
                }
                option.textContent = student.full_name + ' (' + (student.student_code || student.id) + ')';
                el.studentSelect.value = String(student.id);
                el.studentSelect.hidden = true;

                el.search.value = '';
                el.studentList.classList.remove('is-open');
                el.picked.classList.add('is-visible');
                el.pickedName.textContent = student.full_name;
                el.pickedAvatar.textContent = (student.full_name || '?').trim().charAt(0).toUpperCase();
                el.pickedMeta.textContent = (student.student_code || 'no code') + ' · ' + (student.class_name || 'Unassigned class');

                loadSnapshot();
                renderSummary();
            }

            function clearStudent() {
                state.student = null;
                state.snapshot = null;
                el.studentSelect.value = '';
                el.picked.classList.remove('is-visible');
                el.signals.textContent = '';
                renderSummary();
            }

            el.clearStudent.addEventListener('click', clearStudent);

            function loadSnapshot() {
                if (!state.student) { state.snapshot = null; el.signals.textContent = ''; return; }
                window.clearTimeout(signalsTimer);
                signalsTimer = window.setTimeout(function () {
                    const params = new URLSearchParams({ student_id: String(state.student.id), term: el.term.value });
                    fetch(SIGNALS_URL + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('snapshot failed')); })
                        .then(function (payload) {
                            if (!payload || payload.status !== 'success') return;
                            state.snapshot = payload.data;
                            renderSignals(payload.data);
                            renderSummary();
                        })
                        .catch(function () {
                            state.snapshot = null;
                            el.signals.textContent = '';
                            renderSummary();
                        });
                }, 120);
            }

            function renderSignals(snapshot) {
                el.signals.textContent = '';
                const signals = (snapshot && snapshot.signals) || {};
                const chips = [
                    { key: 'late', text: 'Late arrivals: ' + (signals.late || 0), cls: 'ql-chip ql-chip--late' },
                    { key: 'absence', text: 'Absences: ' + (signals.absence || 0), cls: 'ql-chip ql-chip--absence' },
                    { key: 'positive', text: 'Positive records: ' + (signals.positive || 0), cls: 'ql-chip ql-chip--positive' },
                ];
                if (typeof snapshot.current_marks === 'number') {
                    chips.unshift({ key: 'marks', text: 'Balance now: ' + snapshot.current_marks + '/' + cfg.maxMarks, cls: 'ql-chip' });
                }
                chips.forEach(function (chip) {
                    const span = document.createElement('span');
                    span.className = chip.cls;
                    span.textContent = chip.text;
                    el.signals.appendChild(span);
                });
            }

            // ---------- whole class ----------
            function refreshBulkCount() {
                const classId = selectedClassId();
                el.bulkConfirm.disabled = true;
                state.bulkConfirmed = false;
                state.bulkCount = null;

                if (!classId) {
                    el.bulkCount.textContent = 'Pick a class first.';
                    el.bulkDetail.textContent = 'A bulk action needs one class so the right students are covered.';
                    el.bulkPanel.classList.add('is-visible');
                    renderSummary();
                    return;
                }

                el.bulkPanel.classList.add('is-visible');
                el.bulkCount.textContent = 'Checking the class roster...';
                el.bulkDetail.textContent = '';

                fetch(STUDENT_SEARCH_URL + '?' + new URLSearchParams({ class_id: classId, limit: '1' }).toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('count failed')); })
                    .then(function (payload) {
                        const total = Number((payload && payload.data && payload.data.meta && payload.data.meta.total) || 0);
                        state.bulkCount = total;
                        const className = el.classInput.value || 'this class';
                        if (!total) {
                            el.bulkCount.textContent = 'No students in ' + className + '.';
                            el.bulkDetail.textContent = 'Choose another class or turn the bulk switch off.';
                        } else {
                            el.bulkCount.textContent = 'This will affect ' + total + ' student' + (total === 1 ? '' : 's') + ' in ' + className + '.';
                            el.bulkDetail.textContent = 'Every student in the class gets the same action for Term ' + el.term.value + ', in one transaction.';
                            el.bulkConfirm.disabled = false;
                        }
                        renderSummary();
                    })
                    .catch(function () {
                        el.bulkCount.textContent = 'Could not count this class.';
                        el.bulkDetail.textContent = 'Turn the bulk switch off to record a single student instead.';
                        renderSummary();
                    });
            }

            el.applyClass.addEventListener('change', function () {
                state.bulk = el.applyClass.checked;
                state.bulkConfirmed = false;
                el.search.disabled = state.bulk;
                if (state.bulk) {
                    if (state.student) clearStudent();
                    refreshBulkCount();
                } else {
                    el.bulkPanel.classList.remove('is-visible');
                }
                el.bulkConfirm.textContent = 'Confirm bulk action';
                renderSummary();
            });

            el.bulkConfirm.addEventListener('click', function () {
                state.bulkConfirmed = true;
                el.bulkConfirm.textContent = 'Bulk action confirmed';
                el.bulkConfirm.disabled = true;
                renderSummary();
            });

            // ---------- marks per action ----------
            function actionMin() {
                return state.action === 'set' ? 0 : 1;
            }

            function applyActionMode() {
                state.action = currentAction();

                document.querySelectorAll('.ql-action-card').forEach(function (card) {
                    card.classList.toggle('is-selected', card.dataset.action === state.action);
                });

                if (state.action === 'set') {
                    el.marksLabel.textContent = 'New marks balance';
                    el.marks.placeholder = '0';
                } else if (state.action === 'add') {
                    el.marksLabel.textContent = 'Marks to add';
                    el.marks.placeholder = '2';
                } else {
                    el.marksLabel.textContent = 'Marks to deduct';
                    el.marks.placeholder = '1';
                }

                el.marks.min = String(actionMin());
                el.marks.max = String(cfg.maxMarks);
                if (state.action !== 'set' && (el.marks.value === '' || parseInt(el.marks.value, 10) === 0)) {
                    el.marks.value = '1';
                }
                if (state.action === 'set' && el.marks.value === '') el.marks.value = '0';

                el.submit.dataset.action = state.action;
                el.submitLabel.textContent = ACTIONS[state.action].submit;
                el.sumAction.textContent = ACTIONS[state.action].label;

                categoryCombo.refresh();
                loadCategories().then(function () { categoryCombo.refresh(); });
                renderSummary();
            }

            form.querySelectorAll('input[name="action_type"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    state.marksTouched = false;
                    applyActionMode();
                });
            });

            el.marks.addEventListener('input', function () {
                state.marksTouched = true;
                renderSummary();
            });
            el.term.addEventListener('change', function () {
                loadSnapshot();
                if (state.bulk) refreshBulkCount();
                renderSummary();
            });
            el.reason.addEventListener('input', function () {
                el.reasonCount.textContent = String(el.reason.value.length);
            });
            el.category.addEventListener('input', function () { renderSummary(); });

            // ---------- evidence ----------
            function showFile(file) {
                if (state.objectUrl) { URL.revokeObjectURL(state.objectUrl); state.objectUrl = null; }

                if (!file) {
                    el.filePreview.classList.remove('is-visible');
                    el.sumEvidence.textContent = 'None attached';
                    return;
                }

                el.filePreview.classList.add('is-visible');
                el.fileName.textContent = file.name;
                el.fileSize.textContent = formatBytes(file.size) + (file.size > MAX_BYTES ? ' · over the 10 MB limit' : '');
                el.fileThumb.textContent = '';

                if (/^image\//.test(file.type)) {
                    state.objectUrl = URL.createObjectURL(file);
                    const image = document.createElement('img');
                    image.alt = '';
                    image.src = state.objectUrl;
                    el.fileThumb.appendChild(image);
                } else {
                    const ext = (file.name.split('.').pop() || 'file').slice(0, 4);
                    el.fileThumb.textContent = ext;
                }

                el.sumEvidence.textContent = file.name + ' · ' + formatBytes(file.size);
            }

            el.evidence.addEventListener('change', function () { showFile(el.evidence.files && el.evidence.files[0]); });

            el.cameraBtn.addEventListener('click', function () { el.camera.click(); });
            el.camera.addEventListener('change', function () {
                const file = el.camera.files && el.camera.files[0];
                if (!file) return;
                const transfer = new DataTransfer();
                transfer.items.add(file);
                el.evidence.files = transfer.files;
                showFile(file);
            });

            el.removeFile.addEventListener('click', function () {
                el.evidence.value = '';
                el.camera.value = '';
                showFile(null);
            });

            // ---------- summary ----------
            function projectedBalance() {
                const marks = marksValue();
                if (!Number.isInteger(marks)) return null;
                if (state.action === 'set') return clamp(marks, 0, cfg.maxMarks);
                const current = state.snapshot && typeof state.snapshot.current_marks === 'number'
                    ? state.snapshot.current_marks
                    : null;
                if (current === null) return null;
                if (state.action === 'add') return clamp(current + marks, 0, cfg.maxMarks);
                return clamp(current - marks, 0, cfg.maxMarks);
            }

            function renderSummary() {
                const marks = marksValue();

                if (state.bulk) {
                    const className = el.classInput.dataset.classId ? (el.classInput.value || 'the selected class') : 'no class selected';
                    el.sumTarget.textContent = 'Whole class · ' + className + (state.bulkCount === null ? '' : ' (' + state.bulkCount + ')');
                } else {
                    el.sumTarget.textContent = state.student ? state.student.full_name + ' (' + state.student.student_code + ')' : 'No student selected';
                }

                el.sumTerm.textContent = 'Term ' + el.term.value;

                if (marks === null) {
                    el.sumDelta.textContent = '--';
                    el.sumDelta.dataset.tone = '';
                } else if (state.action === 'deduct') {
                    el.sumDelta.textContent = '-' + marks;
                    el.sumDelta.dataset.tone = 'danger';
                } else if (state.action === 'add') {
                    el.sumDelta.textContent = '+' + marks;
                    el.sumDelta.dataset.tone = 'success';
                } else {
                    el.sumDelta.textContent = 'set to ' + marks;
                    el.sumDelta.dataset.tone = 'info';
                }

                if (state.bulk) {
                    el.sumBalance.textContent = 'Applies to the whole class';
                } else if (state.action === 'set') {
                    el.sumBalance.textContent = marks === null ? '--' : String(clamp(marks, 0, cfg.maxMarks));
                } else {
                    const balance = projectedBalance();
                    el.sumBalance.textContent = balance === null ? 'Shown after you pick a student' : String(balance);
                }

                el.sumCategory.textContent = el.category.value.trim() || 'Not set';
            }

            // ---------- validation ----------
            function validate() {
                if (state.bulk) {
                    if (!selectedClassId()) return 'Choose the class you want to apply this action to.';
                    if (!state.bulkConfirmed) return 'Confirm the bulk action before submitting.';
                } else if (!state.student) {
                    return 'Search for a student and select one.';
                }

                const marks = marksValue();
                if (!Number.isInteger(marks)) return 'Enter a whole number of marks.';
                if (marks < actionMin() || marks > cfg.maxMarks) return 'Marks must be between ' + actionMin() + ' and ' + cfg.maxMarks + '.';

                const reason = el.reason.value.trim();
                if (state.action !== 'set') {
                    if (reason.length < 5) return 'Give a reason of at least 5 characters.';
                    if (reason.length > 500) return 'Keep the reason under 500 characters.';
                }

                if (el.category.value.trim().length > 100) return 'Incident category must be 100 characters or less.';

                const file = el.evidence.files && el.evidence.files[0];
                if (file && file.size > MAX_BYTES) return 'Evidence files must be 10 MB or smaller.';

                return null;
            }

            function setSubmitting(isSubmitting) {
                state.submitting = isSubmitting;
                el.submit.disabled = isSubmitting;
                el.submitLoader.hidden = !isSubmitting;
                el.submitLabel.textContent = isSubmitting ? 'Saving...' : ACTIONS[state.action].submit;
            }

            // ---------- submit ----------
            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (state.submitting) return;

                const problem = validate();
                if (problem) {
                    setMessage(problem, 'error');
                    return;
                }

                setMessage('', 'success');
                setSubmitting(true);

                const body = new FormData(form);
                if (state.bulk) {
                    body.set('apply_to_class', '1');
                    body.set('class_id', selectedClassId());
                    body.delete('student_id');
                } else {
                    body.set('apply_to_class', '0');
                }

                try {
                    const response = await fetch(ENDPOINT, {
                        method: 'POST',
                        body: body,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });

                    const raw = await response.text();
                    let payload;
                    try {
                        payload = JSON.parse(raw);
                    } catch (parseError) {
                        throw new Error(response.status === 419
                            ? 'Your session expired. Sign in again and retry.'
                            : 'The server returned an unexpected response.');
                    }

                    if (!response.ok || payload.status !== 'success') {
                        throw new Error(payload.message || 'The action could not be recorded.');
                    }

                    const saved = payload.data || {};
                    const who = state.bulk
                        ? ((el.classInput.value || 'the class') + ' (' + (state.bulkCount === null ? 'the class' : state.bulkCount + ' students') + ')')
                        : (saved.student_name || state.student.full_name);
                    const balance = typeof saved.updated_marks === 'number' ? saved.updated_marks : null;
                    setMessage(payload.message + ' ' + who + (balance === null ? '' : ' is now at ' + balance + ' marks.') + ' Ledger, risk score and notifications were updated.', 'success');

                    if (state.bulk) {
                        state.bulkConfirmed = false;
                        el.bulkConfirm.textContent = 'Confirm bulk action';
                        refreshBulkCount();
                    } else {
                        el.marks.value = state.action === 'set' ? '0' : '1';
                        state.marksTouched = false;
                        el.reason.value = '';
                        el.reasonCount.textContent = '0';
                        el.category.value = '';
                        el.removeFile.click();
                        loadSnapshot();
                    }
                    renderSummary();
                } catch (error) {
                    setMessage(error.message || 'The action could not be recorded.', 'error');
                } finally {
                    setSubmitting(false);
                }
            });

            el.reset.addEventListener('click', function () {
                form.reset();
                state.student = null;
                state.snapshot = null;
                state.marksTouched = false;
                state.bulk = false;
                state.bulkConfirmed = false;
                state.bulkCount = null;
                el.applyClass.checked = false;
                el.applyClass.disabled = false;
                el.search.disabled = false;
                el.classInput.value = 'All classes';
                el.classInput.dataset.classId = '';
                el.studentSelect.value = '';
                el.picked.classList.remove('is-visible');
                el.signals.textContent = '';
                el.bulkPanel.classList.remove('is-visible');
                el.bulkConfirm.textContent = 'Confirm bulk action';
                el.term.value = String(cfg.currentTerm);
                el.reasonCount.textContent = '0';
                el.removeFile.click();
                form.querySelector('input[name="action_type"][value="deduct"]').checked = true;
                setMessage('', 'success');
                applyActionMode();
            });

            el.classInput.value = 'All classes';
            applyActionMode();
            renderSummary();
        })();
        </script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
