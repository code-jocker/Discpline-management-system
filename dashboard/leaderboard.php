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
$roleName = strtolower((string) ($authUser['role_name'] ?? ''));
$canBuildLeaderboard = $isAdmin || in_array($roleName, ['headteacher', 'it'], true) || stripos($roleName, 'discipline') !== false;

$classes = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$selectedClassId = (int) ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$monthYear = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month_year'] ?? '')) === 1
    ? (string) $_GET['month_year']
    : date('Y-m');
$currentTerm = (int) $settingsModel->get('current_term', 1);

$pageTitle = 'SBMS Leaderboard';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
        <header class="dashboard-header" style="margin-bottom: 1.75rem; display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap;">
            <div>
                <p class="eyebrow" style="color: var(--brand-2);">Rewards and rankings</p>
                <h1 style="margin: 0; font-size: 2rem;">Leaderboard</h1>
                <p class="lead compact-lead" style="max-width: 760px;">
                    Track monthly standings, celebrate top performers, and build cached rankings for class competitions.
                </p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a class="btn-secondary" href="<?= e(app_base_url()) ?>/discipline/quick-log.php">Quick Log</a>
                <a class="btn-secondary" href="<?= e(app_base_url()) ?>/dashboard/analytics.php">Analytics</a>
            </div>
        </header>

        <section class="dashboard-stats" style="margin-bottom: 1.5rem;">
            <article class="auth-panel stat-card">
                <p class="eyebrow">Month</p>
                <strong id="stat-month"><?= e(date('F Y', strtotime($monthYear . '-01'))) ?></strong>
                <span>Selected ranking window</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Top points</p>
                <strong id="stat-top-points">--</strong>
                <span>Highest reward total</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Average marks</p>
                <strong id="stat-average-marks">--</strong>
                <span>Across the selected class</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Incidents</p>
                <strong id="stat-incidents">--</strong>
                <span>Recorded this month</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Students</p>
                <strong id="stat-students">--</strong>
                <span>Visible in ranking</span>
            </article>
        </section>

        <section class="dashboard-grid" style="grid-template-columns: 1.4fr 0.9fr; align-items: start;">
            <article class="auth-panel dashboard-module-card" style="min-height: 100%;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <p class="eyebrow">Ranking controls</p>
                        <h2 style="margin-bottom: 0.25rem;">Class monthly leaderboard</h2>
                        <p class="lead compact-lead">Use the cache when available, or live-calculate a ranking if no build has been run yet.</p>
                    </div>
                    <?php if ($canBuildLeaderboard): ?>
                        <button id="buildLeaderboardBtn" class="primary-btn" type="button" style="min-width: 180px;">Build leaderboard</button>
                    <?php endif; ?>
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
                        <label for="monthYear">Month</label>
                        <input type="month" id="monthYear" value="<?= e($monthYear) ?>" class="form-control">
                    </div>
                </div>

                <div id="leaderboardFeedback" class="feedback" role="alert" aria-live="polite" style="margin-top: 1rem;"></div>

                <div style="margin-top: 1.25rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <button type="button" class="ghost-btn" id="refreshLeaderboardBtn">Refresh</button>
                    <a class="ghost-btn" href="<?= e(app_base_url()) ?>/reports/index.php" style="text-decoration: none; display: inline-flex; align-items: center;">Open reports</a>
                </div>

                <div class="section" style="margin-top: 1.5rem; padding: 0; background: transparent; box-shadow: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
                        <h3 style="margin: 0;">Monthly ranking</h3>
                        <span id="leaderboardMeta" class="cell-muted">Loading...</span>
                    </div>

                    <div class="table-wrap">
                        <table class="leaderboard-table" id="leaderboardTable">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Student</th>
                                    <th>Points</th>
                                    <th>Marks</th>
                                    <th>Incidents</th>
                                    <th>Momentum</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="text-center">Select a class to load the leaderboard.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </article>

            <aside class="auth-panel dashboard-module-card">
                <p class="eyebrow">Top 3</p>
                <h2>Podium</h2>
                <div id="podiumList" style="display: grid; gap: 0.75rem; margin-top: 1rem;">
                    <div class="notification-item" style="padding: 1rem;">No ranking loaded yet.</div>
                </div>

                <div style="margin-top: 1.5rem;">
                    <p class="eyebrow">How it works</p>
                    <ul class="feature-list dashboard-list">
                        <li>Points reward positive behavior and improvement.</li>
                        <li>Marks average keeps the ranking fair.</li>
                        <li>Incident counts help the team see context.</li>
                        <li>Monthly builds cache results for faster loading.</li>
                    </ul>
                </div>
            </aside>
        </section>

        <script>
        (function () {
            const baseUrl = '<?= e(app_base_url()) ?>';
            const leaderboardBase = baseUrl + '/api/v1/index.php?route=classes/';
            const buildUrl = baseUrl + '/api/v1/index.php?route=leaderboards/build';
            const classSelect = document.getElementById('classSelect');
            const monthYear = document.getElementById('monthYear');
            const tableBody = document.querySelector('#leaderboardTable tbody');
            const leaderboardMeta = document.getElementById('leaderboardMeta');
            const feedback = document.getElementById('leaderboardFeedback');
            const podiumList = document.getElementById('podiumList');
            const statTopPoints = document.getElementById('stat-top-points');
            const statAverageMarks = document.getElementById('stat-average-marks');
            const statIncidents = document.getElementById('stat-incidents');
            const statStudents = document.getElementById('stat-students');
            const refreshBtn = document.getElementById('refreshLeaderboardBtn');
            const buildBtn = document.getElementById('buildLeaderboardBtn');

            function setFeedback(message, isError) {
                if (!feedback) return;
                feedback.classList.remove('error', 'success');
                feedback.classList.add(isError ? 'error' : 'success');
                feedback.textContent = message || '';
            }

            function escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value == null ? '' : String(value);
                return div.innerHTML;
            }

            function renderEmpty(message) {
                tableBody.innerHTML = '<tr><td colspan="6" class="text-center">' + escapeHtml(message) + '</td></tr>';
                podiumList.innerHTML = '<div class="notification-item" style="padding: 1rem;">' + escapeHtml(message) + '</div>';
                leaderboardMeta.textContent = 'No data loaded';
                statTopPoints.textContent = '--';
                statAverageMarks.textContent = '--';
                statIncidents.textContent = '--';
                statStudents.textContent = '--';
            }

            function renderPodium(rows) {
                if (!rows.length) {
                    podiumList.innerHTML = '<div class="notification-item" style="padding: 1rem;">No students to rank yet.</div>';
                    return;
                }

                const colors = ['#f59e0b', '#94a3b8', '#c08457'];
                podiumList.innerHTML = rows.slice(0, 3).map((row, index) => {
                    const accent = colors[index] || '#38bdf8';
                    return [
                        '<div class="notification-item" style="border-left: 4px solid ' + accent + '; padding: 0.9rem 1rem;">',
                        '<div style="display:flex; justify-content:space-between; gap:0.75rem; align-items:flex-start;">',
                        '<div>',
                        '<p style="margin:0; color:var(--of-ink); font-weight:700;">#' + escapeHtml(row.rank) + ' ' + escapeHtml(row.full_name) + '</p>',
                        '<p style="margin:0.2rem 0 0; color:var(--of-muted); font-size:0.85rem;">' + escapeHtml(row.student_code) + '</p>',
                        '</div>',
                        '<strong style="color:' + accent + ';">' + escapeHtml(row.points) + ' pts</strong>',
                        '</div>',
                        '<p style="margin:0.6rem 0 0; color:var(--of-muted); font-size:0.82rem;">Marks: ' + escapeHtml(row.marks_avg) + '/40 | Incidents: ' + escapeHtml(row.incidents_count) + '</p>',
                        '</div>'
                    ].join('');
                }).join('');
            }

            function renderLeaderboard(rows) {
                if (!rows.length) {
                    renderEmpty('No ranking data found for this class and month.');
                    return;
                }

                const topPoints = rows[0] ? rows[0].points : '--';
                const avgMarks = rows.length
                    ? (rows.reduce((sum, row) => sum + Number(row.marks_avg || 0), 0) / rows.length).toFixed(1)
                    : '--';
                const incidents = rows.reduce((sum, row) => sum + Number(row.incidents_count || 0), 0);

                statTopPoints.textContent = topPoints + ' pts';
                statAverageMarks.textContent = avgMarks + '/40';
                statIncidents.textContent = incidents;
                statStudents.textContent = rows.length;

                leaderboardMeta.textContent = rows.length + ' students ranked';
                tableBody.innerHTML = rows.map((row, index) => {
                    const rank = Number(row.rank || index + 1);
                    const shade = rank === 1 ? '#f59e0b' : rank === 2 ? '#94a3b8' : rank === 3 ? '#c08457' : '#38bdf8';
                    const width = Math.max(10, Math.min(100, Number(row.marks_avg || 0) * 2.5));
                    return [
                        '<tr>',
                        '<td><span style="display:inline-flex; width:32px; height:32px; border-radius:999px; align-items:center; justify-content:center; background:' + shade + '; color:#04101d; font-weight:800;">' + rank + '</span></td>',
                        '<td><strong>' + escapeHtml(row.full_name) + '</strong><br><small class="cell-muted">' + escapeHtml(row.student_code) + '</small></td>',
                        '<td><strong>' + escapeHtml(row.points) + '</strong> pts</td>',
                        '<td>' + escapeHtml(row.marks_avg) + '/40</td>',
                        '<td>' + escapeHtml(row.incidents_count) + '</td>',
                        '<td><div style="display:grid; gap:0.35rem;"><span class="cell-muted">' + (rank <= 3 ? 'Podium' : 'Progress') + '</span><div style="height:8px; border-radius:999px; background:rgba(148,163,184,0.18); overflow:hidden;"><div style="width:' + width + '%; height:100%; background:linear-gradient(90deg, #38bdf8, #22c55e); border-radius:999px;"></div></div></div></td>',
                        '</tr>'
                    ].join('');
                }).join('');

                renderPodium(rows);
            }

            async function loadLeaderboard() {
                const classId = classSelect.value;
                const month = monthYear.value;

                if (!classId) {
                    renderEmpty('Select a class to load the leaderboard.');
                    return;
                }

                tableBody.innerHTML = '<tr><td colspan="6" class="text-center">Loading leaderboard...</td></tr>';
                leaderboardMeta.textContent = 'Loading...';
                setFeedback('', false);

                try {
                    const response = await fetch(leaderboardBase + classId + '/leaderboard&month_year=' + encodeURIComponent(month));
                    const payload = await response.json();
                    if (!response.ok || !payload.leaderboard) {
                        throw new Error(payload.message || 'Unable to load leaderboard data.');
                    }

                    const rows = payload.leaderboard || [];
                    renderLeaderboard(rows);
                    setFeedback(rows.length ? 'Leaderboard loaded successfully.' : 'No leaderboard rows found yet. Build it to cache rankings.', false);
                    if (payload.month_year) {
                        document.getElementById('stat-month').textContent = new Date(payload.month_year + '-01').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
                    }
                } catch (error) {
                    renderEmpty(error.message || 'Unable to load leaderboard data.');
                    setFeedback(error.message || 'Unable to load leaderboard data.', true);
                }
            }

            async function buildLeaderboard() {
                const classId = classSelect.value;
                const month = monthYear.value;

                if (!classId) {
                    setFeedback('Please choose a class first.', true);
                    return;
                }

                if (!buildBtn) return;
                const originalText = buildBtn.textContent;
                buildBtn.disabled = true;
                buildBtn.textContent = 'Building...';
                setFeedback('', false);

                try {
                    const response = await fetch(buildUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            class_id: parseInt(classId, 10),
                            month_year: month,
                        }),
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        throw new Error(payload.message || 'Unable to build leaderboard.');
                    }

                    setFeedback(payload.message || 'Leaderboard built successfully.', false);
                    await loadLeaderboard();
                } catch (error) {
                    setFeedback(error.message || 'Unable to build leaderboard.', true);
                } finally {
                    buildBtn.disabled = false;
                    buildBtn.textContent = originalText;
                }
            }

            classSelect.addEventListener('change', loadLeaderboard);
            monthYear.addEventListener('change', loadLeaderboard);
            refreshBtn.addEventListener('click', loadLeaderboard);
            if (buildBtn) {
                buildBtn.addEventListener('click', buildLeaderboard);
            }

            if (classSelect.value) {
                loadLeaderboard();
            } else {
                renderEmpty('Choose a class to begin.');
            }
        })();
        </script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
