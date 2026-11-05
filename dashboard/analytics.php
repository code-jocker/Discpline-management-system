<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/plexus.php';

require_auth();

$db = Database::connection();
$settingsModel = new Settings($db);
$userModel = new User($db);
$authUser = current_auth_user();
$isAdmin = !empty($authUser) && isset($authUser['id']) && $userModel->userHasPrivilegedRole((int) $authUser['id']);
$classes = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$selectedClassId = (int) ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$term = (int) ($_GET['term'] ?? $settingsModel->get('current_term', 1));
if (!in_array($term, [1, 2, 3], true)) {
    $term = (int) $settingsModel->get('current_term', 1);
}

$pageTitle = 'SBMS Analytics';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
        <header class="dashboard-header" style="margin-bottom: 1.75rem; display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap;">
            <div>
                <p class="eyebrow" style="color: var(--brand-2);">Insights and trends</p>
                <h1 style="margin: 0; font-size: 2rem;">Analytics</h1>
                <p class="lead compact-lead" style="max-width: 760px;">
                    Follow class performance, incident categories, risk signals, and schoolwide trends from one screen.
                </p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a class="btn-secondary" href="<?= e(app_base_url()) ?>/dashboard/leaderboard.php">Leaderboard</a>
                <a class="btn-secondary" href="<?= e(app_base_url()) ?>/discipline/quick-log.php">Quick Log</a>
            </div>
        </header>

        <section class="dashboard-stats" style="margin-bottom: 1.5rem;">
            <article class="auth-panel stat-card">
                <p class="eyebrow">Average marks</p>
                <strong id="statAvgMarks">--</strong>
                <span>Out of 40 for the selected class</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Students</p>
                <strong id="statStudents">--</strong>
                <span>Included in the analysis</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Incidents</p>
                <strong id="statIncidents">--</strong>
                <span>Recorded in the selected term</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">High risk</p>
                <strong id="statHighRisk">--</strong>
                <span>Students needing intervention</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Top performer</p>
                <strong id="statTopPerformer">--</strong>
                <span>Highest marks in the class</span>
            </article>
        </section>

        <section class="dashboard-grid" style="grid-template-columns: 1.35fr 0.85fr; align-items: start;">
            <article class="auth-panel dashboard-module-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <p class="eyebrow">Filters</p>
                        <h2 style="margin-bottom: 0.25rem;">Class analytics dashboard</h2>
                        <p class="lead compact-lead">Switch class and term to compare performance, incidents, and risk signals.</p>
                    </div>
                    <button id="refreshAnalyticsBtn" type="button" class="primary-btn" style="min-width: 160px;">Refresh</button>
                </div>

                <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-top: 1.5rem;">
                    <div class="field">
                        <label for="classSelect">Class</label>
                        <select id="classSelect" class="form-control">
                            <option value="">Select Class...</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>" <?= (int) $class['id'] === $selectedClassId ? 'selected' : '' ?>>
                                    <?= e($class['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="termSelect">Term</label>
                        <select id="termSelect" class="form-control">
                            <?php for ($i = 1; $i <= 3; $i++): ?>
                                <option value="<?= $i ?>" <?= $i === $term ? 'selected' : '' ?>>Term <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div id="analyticsFeedback" class="feedback" role="alert" aria-live="polite" style="margin-top: 1rem;"></div>

                <div style="margin-top: 1.5rem; display: grid; gap: 1.5rem;">
                    <div class="section" style="padding: 1.25rem; margin: 0; background: var(--of-page);">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
                            <h3 style="margin: 0;">Top performers</h3>
                            <span id="classMeta" class="cell-muted">Loading...</span>
                        </div>
                        <div style="position: relative; height: 260px; margin-bottom: 1rem;">
                            <canvas id="performanceChart"></canvas>
                        </div>
                        <div class="table-wrap">
                            <table class="leaderboard-table" id="topPerformersTable">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Student</th>
                                        <th>Marks</th>
                                        <th>Points</th>
                                        <th>Incidents</th>
                                        <th>Risk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="6" class="text-center">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section" style="padding: 1.25rem; margin: 0; background: var(--of-page);">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
                            <h3 style="margin: 0;">Incident analysis</h3>
                            <span id="incidentMeta" class="cell-muted">Loading...</span>
                        </div>
                        <div style="position: relative; height: 260px; margin-bottom: 1rem;">
                            <canvas id="incidentChart"></canvas>
                        </div>
                        <div class="table-wrap">
                            <table class="leaderboard-table" id="incidentTable">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Count</th>
                                        <th>Total deducted</th>
                                        <th>Average</th>
                                        <th>Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="5" class="text-center">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </article>

            <aside class="auth-panel dashboard-module-card">
                <p class="eyebrow">School signals</p>
                <h2>Broad view</h2>
                <div style="position: relative; height: 220px; margin-top: 1rem;">
                    <canvas id="riskChart"></canvas>
                </div>

                <div style="margin-top: 1.5rem;">
                    <p class="eyebrow">School stats</p>
                    <div id="schoolStats" class="dashboard-stats" style="grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <article class="auth-panel stat-card" style="padding: 1rem;">
                            <p class="eyebrow">Students</p>
                            <strong id="schoolStudents">--</strong>
                            <span>Across the school</span>
                        </article>
                        <article class="auth-panel stat-card" style="padding: 1rem;">
                            <p class="eyebrow">Classes</p>
                            <strong id="schoolClasses">--</strong>
                            <span>Active classes</span>
                        </article>
                        <article class="auth-panel stat-card" style="padding: 1rem;">
                            <p class="eyebrow">Incidents</p>
                            <strong id="schoolIncidents">--</strong>
                            <span>This term</span>
                        </article>
                        <article class="auth-panel stat-card" style="padding: 1rem;">
                            <p class="eyebrow">High risk</p>
                            <strong id="schoolHighRisk">--</strong>
                            <span>Critical alerts</span>
                        </article>
                    </div>
                </div>

                <div style="margin-top: 1.5rem;">
                    <p class="eyebrow">Top offenders</p>
                    <div id="topOffendersList" style="display: grid; gap: 0.75rem;">
                        <div class="notification-item" style="padding: 1rem;">Loading school signals...</div>
                    </div>
                </div>
            </aside>
        </section>

        <script src="<?= e(app_base_url()) ?>/assets/vendor/chart.umd.min.js"></script>
        <script>
        (function () {
            const baseUrl = '<?= e(app_base_url()) ?>';
            const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const classSelect = document.getElementById('classSelect');
            const termSelect = document.getElementById('termSelect');
            const refreshBtn = document.getElementById('refreshAnalyticsBtn');
            const feedback = document.getElementById('analyticsFeedback');

            const statAvgMarks = document.getElementById('statAvgMarks');
            const statStudents = document.getElementById('statStudents');
            const statIncidents = document.getElementById('statIncidents');
            const statHighRisk = document.getElementById('statHighRisk');
            const statTopPerformer = document.getElementById('statTopPerformer');

            const classMeta = document.getElementById('classMeta');
            const incidentMeta = document.getElementById('incidentMeta');
            const topPerformersBody = document.querySelector('#topPerformersTable tbody');
            const incidentBody = document.querySelector('#incidentTable tbody');
            const topOffendersList = document.getElementById('topOffendersList');

            const schoolStudents = document.getElementById('schoolStudents');
            const schoolClasses = document.getElementById('schoolClasses');
            const schoolIncidents = document.getElementById('schoolIncidents');
            const schoolHighRisk = document.getElementById('schoolHighRisk');

            let performanceChart = null;
            let incidentChart = null;
            let riskChart = null;

            function escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value == null ? '' : String(value);
                return div.innerHTML;
            }

            function setFeedback(message, isError) {
                feedback.classList.remove('error', 'success');
                feedback.classList.add(isError ? 'error' : 'success');
                feedback.textContent = message || '';
            }

            function destroyChart(chart) {
                if (chart) {
                    chart.destroy();
                }
            }

            function buildBarChart(canvasId, labels, values, color) {
                const ctx = document.getElementById(canvasId);
                if (!ctx || !window.Chart) return null;
                return new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Marks',
                            data: values,
                            borderWidth: 0,
                            backgroundColor: color,
                            borderRadius: 10,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                suggestedMax: 40,
                                grid: { color: 'rgba(148, 163, 184, 0.12)' },
                            },
                            x: { grid: { display: false } },
                        },
                        plugins: {
                            legend: { display: false },
                        },
                    },
                });
            }

            function buildDoughnutChart(canvasId, labels, values, colors) {
                const ctx = document.getElementById(canvasId);
                if (!ctx || !window.Chart) return null;
                return new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels,
                        datasets: [{
                            data: values,
                            backgroundColor: colors,
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: '#64748b' },
                            },
                        },
                    },
                });
            }

            function renderClassMetrics(data) {
                const analytics = data.analytics || {};
                const students = analytics.students || [];
                const topPerformer = analytics.top_performers?.[0];

                statAvgMarks.textContent = Number(analytics.class_average_marks || 0).toFixed(1) + '/40';
                statStudents.textContent = analytics.total_students ?? '--';
                statIncidents.textContent = analytics.total_incidents ?? '--';
                statHighRisk.textContent = analytics.high_risk_students ?? '--';
                statTopPerformer.textContent = topPerformer ? topPerformer.full_name : '--';
                classMeta.textContent = analytics.class_name ? analytics.class_name + ' | Term ' + analytics.term : 'Class analytics';

                if (!students.length) {
                    topPerformersBody.innerHTML = '<tr><td colspan="6" class="text-center">No students found for this class.</td></tr>';
                    destroyChart(performanceChart);
                    performanceChart = null;
                    return;
                }

                const labels = students.slice(0, 5).map((student) => student.full_name);
                const values = students.slice(0, 5).map((student) => Number(student.current_marks || 0));
                destroyChart(performanceChart);
                performanceChart = buildBarChart('performanceChart', labels, values, '#38bdf8');

                topPerformersBody.innerHTML = students.slice(0, 10).map((student, index) => {
                    const risk = String(student.risk_level || 'Excellent');
                    const riskColor = /critical/i.test(risk) ? '#fb7185' : /high/i.test(risk) ? '#f59e0b' : /warning/i.test(risk) ? '#facc15' : '#22c55e';
                    return [
                        '<tr>',
                        '<td><strong>#' + (index + 1) + '</strong></td>',
                        '<td><strong>' + escapeHtml(student.full_name) + '</strong><br><small class="cell-muted">' + escapeHtml(student.student_code) + '</small></td>',
                        '<td>' + escapeHtml(student.current_marks) + '/40</td>',
                        '<td>' + escapeHtml(student.total_points || 0) + '</td>',
                        '<td>' + escapeHtml(student.incident_count || 0) + '</td>',
                        '<td><span style="display:inline-flex; align-items:center; padding:0.25rem 0.6rem; border-radius:999px; background:' + riskColor + '; color:#04101d; font-weight:700;">' + escapeHtml(risk) + '</span></td>',
                        '</tr>'
                    ].join('');
                }).join('');
            }

            function renderIncidentMetrics(data) {
                const incidents = data.incident_categories || [];
                if (!incidents.length) {
                    incidentBody.innerHTML = '<tr><td colspan="5" class="text-center">No incident data found for this selection.</td></tr>';
                    destroyChart(incidentChart);
                    incidentChart = null;
                    return;
                }

                const labels = incidents.map((row) => row.incident_category);
                const values = incidents.map((row) => Number(row.incident_count || 0));
                destroyChart(incidentChart);
                incidentChart = buildDoughnutChart('incidentChart', labels, values, ['#38bdf8', '#f59e0b', '#22c55e', '#fb7185', '#a78bfa', '#94a3b8']);

                const total = values.reduce((sum, value) => sum + value, 0) || 1;
                incidentBody.innerHTML = incidents.map((row) => {
                    const share = ((Number(row.incident_count || 0) / total) * 100).toFixed(1);
                    return [
                        '<tr>',
                        '<td><strong>' + escapeHtml(row.incident_category) + '</strong></td>',
                        '<td>' + escapeHtml(row.incident_count) + '</td>',
                        '<td>' + escapeHtml(row.total_deductions) + '</td>',
                        '<td>' + Number(row.average_deduction || 0).toFixed(1) + '</td>',
                        '<td>' + share + '%</td>',
                        '</tr>'
                    ].join('');
                }).join('');

                incidentMeta.textContent = total + ' incidents analyzed';
            }

            function renderSchoolSignals(data) {
                const summary = data.summary_stats || {};
                schoolStudents.textContent = summary.students ?? '--';
                schoolClasses.textContent = summary.classes ?? '--';
                schoolIncidents.textContent = summary.incidents ?? '--';
                schoolHighRisk.textContent = summary.high_risk ?? '--';

                const risk = data.risk_distribution || {};
                const labels = risk.labels || [];
                const values = risk.datasets?.[0]?.data || [];
                destroyChart(riskChart);
                riskChart = buildDoughnutChart('riskChart', labels, values, ['#fb7185', '#f59e0b', '#22c55e', '#38bdf8']);

                const offenders = data.top_offenders || [];
                const termLabel = data.selected_term ? 'Term ' + data.selected_term : 'Current term';
                if (!offenders.length) {
                    topOffendersList.innerHTML = '<div class="notification-item" style="padding: 1rem;">No offenders to display.</div>';
                    return;
                }

                topOffendersList.innerHTML = offenders.map((row, index) => {
                    const studentId = row.id || 0;
                    const deleteBtn = '';
                    return [
                        '<div class="notification-item" style="padding: 0.95rem 1rem; border-left: 4px solid ' + (index === 0 ? '#fb7185' : '#38bdf8') + '; display:flex; justify-content:space-between; align-items:flex-start; gap:0.75rem;">',
                        '<div style="flex:1">',
                        '<p style="margin:0; color:var(--of-ink); font-weight:700;">#' + (index + 1) + ' ' + escapeHtml(row.name || row.full_name || 'Student') + '</p>',
                        '<p style="margin:0.25rem 0 0; color:var(--of-muted); font-size:0.85rem;">' + escapeHtml(row.code || row.student_code || '') + '</p>',
                        '<p style="margin:0.45rem 0 0; color:var(--of-muted); font-size:0.85rem;">' + escapeHtml(termLabel) + ' | Impact: ' + escapeHtml(row.impact || row.total_deducted || 0) + ' | Incidents: ' + escapeHtml(row.incidents || 0) + '</p>',
                        '</div>',
                        '<div style="flex:0 0 auto">' + deleteBtn + '</div>',
                        '</div>'
                    ].join('');
                }).join('');

                // Delete buttons are handled on the main Behavior Signals dashboard; no client handler here.
            }

            async function loadAnalytics() {
                const classId = classSelect.value;
                const term = termSelect.value;

                if (!classId) {
                    setFeedback('Select a class to load analytics.', true);
                    return;
                }

                setFeedback('', false);
                topPerformersBody.innerHTML = '<tr><td colspan="6" class="text-center">Loading...</td></tr>';
                incidentBody.innerHTML = '<tr><td colspan="5" class="text-center">Loading...</td></tr>';
                topOffendersList.innerHTML = '<div class="notification-item" style="padding: 1rem;">Loading school signals...</div>';

                try {
                    const [analyticsResponse, incidentResponse, metricsResponse] = await Promise.all([
                        fetch(baseUrl + '/api/v1/index.php?route=analytics/dashboard&class_id=' + classId + '&term=' + term),
                        fetch(baseUrl + '/api/v1/index.php?route=analytics/incidents&class_id=' + classId + '&term=' + term),
                        fetch(baseUrl + '/dashboard/metrics.php?term=' + term),
                    ]);

                    const analyticsPayload = await analyticsResponse.json();
                    const incidentPayload = await incidentResponse.json();
                    const metricsPayload = await metricsResponse.json();

                    if (!analyticsResponse.ok) {
                        throw new Error(analyticsPayload.message || 'Unable to load class analytics.');
                    }
                    if (!incidentResponse.ok) {
                        throw new Error(incidentPayload.message || 'Unable to load incident analytics.');
                    }
                    if (!metricsResponse.ok) {
                        throw new Error(metricsPayload.message || 'Unable to load school signals.');
                    }

                    renderClassMetrics(analyticsPayload);
                    renderIncidentMetrics(incidentPayload);
                    renderSchoolSignals(metricsPayload.data || {});
                    setFeedback('Analytics loaded successfully.', false);
                } catch (error) {
                    setFeedback(error.message || 'Unable to load analytics.', true);
                    topPerformersBody.innerHTML = '<tr><td colspan="6" class="text-center">Unable to load data.</td></tr>';
                    incidentBody.innerHTML = '<tr><td colspan="5" class="text-center">Unable to load data.</td></tr>';
                    topOffendersList.innerHTML = '<div class="notification-item" style="padding: 1rem;">Unable to load school signals.</div>';
                }
            }

            classSelect.addEventListener('change', loadAnalytics);
            termSelect.addEventListener('change', loadAnalytics);
            refreshBtn.addEventListener('click', loadAnalytics);

            if (classSelect.value) {
                loadAnalytics();
            } else {
                setFeedback('Choose a class to begin.', true);
            }
        })();
        </script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
