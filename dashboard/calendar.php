<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/models/plexus.php';

require_auth();

$db = Database::connection();
$authUser = current_auth_user();
$userModel = new User($db);
$settingsModel = new Settings($db);

$isAdmin = !empty($authUser) && isset($authUser['id']) && $userModel->userHasPrivilegedRole((int) $authUser['id']);
$roleName = strtolower((string) ($authUser['role_name'] ?? ''));
$canManageCalendar = $isAdmin || in_array($roleName, ['headteacher', 'it'], true) || stripos($roleName, 'discipline') !== false || stripos($roleName, 'teacher') !== false;

$classes = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$students = $db->query(
    'SELECT s.id, s.full_name, s.student_code, s.class_id, c.name AS class_name
     FROM students s
     INNER JOIN classes c ON c.id = s.class_id
     ORDER BY c.name ASC, s.full_name ASC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$monthYear = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month_year'] ?? '')) === 1
    ? (string) $_GET['month_year']
    : date('Y-m');
$selectedClassId = (int) ($_GET['class_id'] ?? 0);

$selectedClassName = 'All classes';
foreach ($classes as $class) {
    if ((int) $class['id'] === $selectedClassId) {
        $selectedClassName = (string) $class['name'];
        break;
    }
}

$selectedMonth = new DateTimeImmutable($monthYear . '-01');
$pageTitle = 'SBMS Calendar';
$bodyClass = 'sbms-plexus-body dashboard-calendar-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
        <header class="dashboard-header" style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap;">
            <div>
                <p class="eyebrow" style="color: var(--brand-2);">Scheduling and follow-up</p>
                <h1 style="margin: 0; font-size: 2rem;">Calendar</h1>
                <p class="lead compact-lead" style="max-width: 820px;">
                    Plan follow-ups, parent meetings, counseling, detention, and other student support events in one shared view.
                </p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button type="button" class="btn-secondary" id="jumpTodayBtn">Today</button>
                <button type="button" class="btn-primary" id="openAddEventTopBtn">New Event</button>
            </div>
        </header>

        <section class="dashboard-stats" style="margin-bottom: 1.5rem;">
            <article class="auth-panel stat-card">
                <p class="eyebrow">Selected month</p>
                <strong id="statMonth"><?= e($selectedMonth->format('F Y')) ?></strong>
                <span>Current scheduling window</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Events this month</p>
                <strong id="statMonthEvents">--</strong>
                <span>Loaded from the calendar</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Upcoming</p>
                <strong id="statUpcoming">--</strong>
                <span>Next 30 days</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Selected class</p>
                <strong id="statClass"><?= e($selectedClassName) ?></strong>
                <span>Calendar filter</span>
            </article>
            <article class="auth-panel stat-card">
                <p class="eyebrow">Manage mode</p>
                <strong><?= $canManageCalendar ? 'Enabled' : 'Read only' ?></strong>
                <span><?= $canManageCalendar ? 'Create and delete events' : 'View events only' ?></span>
            </article>
        </section>

        <section class="dashboard-grid" style="grid-template-columns: 1.3fr 0.85fr; align-items: start;">
            <article class="auth-panel dashboard-module-card calendar-shell-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <p class="eyebrow">Month view</p>
                        <h2 id="monthLabel" style="margin-bottom: 0.25rem;"><?= e($selectedMonth->format('F Y')) ?></h2>
                        <p class="lead compact-lead">Click any day to review its events and add follow-ups where needed.</p>
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="ghost-btn" id="prevMonthBtn">Previous</button>
                        <button type="button" class="ghost-btn" id="nextMonthBtn">Next</button>
                        <button type="button" class="primary-btn" id="openAddEventBtn">Add Event</button>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-top: 1.25rem;">
                    <div class="field">
                        <label for="classFilter">Class filter</label>
                        <select id="classFilter" class="form-control">
                            <option value="0">All classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>" <?= (int) $class['id'] === $selectedClassId ? 'selected' : '' ?>>
                                    <?= e($class['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="monthPicker">Jump to month</label>
                        <input type="month" id="monthPicker" class="form-control" value="<?= e($monthYear) ?>">
                    </div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 0.65rem; margin-top: 1rem;">
                    <span class="badge" style="background: rgba(56, 189, 248, 0.18); color: #38bdf8;">Letter follow-up</span>
                    <span class="badge" style="background: rgba(245, 158, 11, 0.18); color: #f59e0b;">Parent meeting</span>
                    <span class="badge" style="background: rgba(34, 197, 94, 0.18); color: #22c55e;">Counseling</span>
                    <span class="badge" style="background: rgba(251, 113, 133, 0.18); color: #fb7185;">Detention</span>
                    <span class="badge" style="background: rgba(148, 163, 184, 0.18); color: #cbd5e1;">Custom</span>
                </div>

                <div id="calendarFeedback" class="feedback" role="alert" aria-live="polite" style="margin-top: 1rem;"></div>

                <div class="calendar-grid-shell" style="margin-top: 1rem;">
                    <div class="calendar-weekdays">
                        <div>Sun</div>
                        <div>Mon</div>
                        <div>Tue</div>
                        <div>Wed</div>
                        <div>Thu</div>
                        <div>Fri</div>
                        <div>Sat</div>
                    </div>
                    <div id="calendarGrid" class="calendar-grid"></div>
                </div>
            </article>

            <aside class="auth-panel dashboard-module-card">
                <p class="eyebrow">Upcoming agenda</p>
                <h2>Next 30 days</h2>
                <div id="upcomingList" class="notification-feed" style="margin-top: 1rem; max-height: 360px; overflow-y: auto;">
                    <div class="notification-item" style="padding: 1rem;">Loading upcoming events...</div>
                </div>

                <div style="margin-top: 1.5rem;">
                    <p class="eyebrow">Month summary</p>
                    <div id="typeSummary" style="display: grid; gap: 0.75rem;">
                        <div class="notification-item" style="padding: 1rem;">Loading summary...</div>
                    </div>
                </div>

                <div style="margin-top: 1.5rem;">
                    <p class="eyebrow">Quick actions</p>
                    <ul class="feature-list dashboard-list">
                        <li>Link events to a student, class, or incident.</li>
                        <li>Create follow-up meetings after discipline actions.</li>
                        <li>Keep the whole team aligned on due dates.</li>
                    </ul>
                </div>
            </aside>
        </section>

        <div class="modal-backdrop hidden" id="dayModal" style="position: fixed; inset: 0; z-index: 1000; background: rgba(2, 6, 23, 0.72); display: none; align-items: center; justify-content: center; padding: 1rem;">
            <div class="modal-panel" style="max-width: 760px; width: 100%; background: rgba(10, 18, 34, 0.98); color: #f8fafc; border: 1px solid rgba(148, 163, 184, 0.15); border-radius: 18px; box-shadow: 0 30px 80px rgba(0, 0, 0, 0.35);">
                <div class="modal-header" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(148, 163, 184, 0.15);">
                    <div>
                        <p class="eyebrow" style="margin-bottom: 0.25rem; color: #38bdf8;">Day details</p>
                        <h2 id="dayModalTitle" style="margin: 0;">Events</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeDayModalBtn" aria-label="Close modal">&times;</button>
                </div>

                <div id="dayModalFeedback" class="feedback" role="alert" aria-live="polite" style="margin-top: 1rem;"></div>

                <div id="dayEventList" style="display: grid; gap: 0.75rem; margin-top: 1rem;">
                    <div class="notification-item" style="padding: 1rem;">No events loaded.</div>
                </div>

                <div style="margin-top: 1.25rem; display: flex; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                    <button type="button" class="ghost-btn" id="openAddEventFromDayBtn">Add event</button>
                    <button type="button" class="btn-secondary" id="closeDayModalBtn2">Close</button>
                </div>
            </div>
        </div>

        <div class="modal-backdrop hidden" id="eventModal" style="position: fixed; inset: 0; z-index: 1100; background: rgba(2, 6, 23, 0.72); display: none; align-items: center; justify-content: center; padding: 1rem;">
            <div class="modal-panel" style="max-width: 860px; width: 100%; background: rgba(10, 18, 34, 0.98); color: #f8fafc; border: 1px solid rgba(148, 163, 184, 0.15); border-radius: 18px; box-shadow: 0 30px 80px rgba(0, 0, 0, 0.35);">
                <div class="modal-header" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(148, 163, 184, 0.15);">
                    <div>
                        <p class="eyebrow" style="margin-bottom: 0.25rem; color: #38bdf8;">Create event</p>
                        <h2 style="margin: 0;">Schedule a follow-up</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeEventModalBtn" aria-label="Close modal">&times;</button>
                </div>

                <form id="eventForm" class="form-grid" style="margin-top: 1rem; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;" novalidate>
                    <?= csrf_input() ?>
                    <div class="field">
                        <label for="eventType">Event type</label>
                        <select id="eventType" name="event_type" required>
                            <option value="letter_follow_up">Letter follow-up</option>
                            <option value="parent_meeting">Parent meeting</option>
                            <option value="counseling">Counseling</option>
                            <option value="detention">Detention</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="eventDate">Event date</label>
                        <input type="date" id="eventDate" name="event_date" required>
                    </div>

                    <div class="field full-width">
                        <label for="eventTitle">Event title</label>
                        <input type="text" id="eventTitle" name="event_title" maxlength="255" required placeholder="e.g. Parent meeting for John">
                    </div>

                    <div class="field full-width">
                        <label for="eventDescription">Description</label>
                        <textarea id="eventDescription" name="description" rows="3" maxlength="1000" placeholder="Optional details..."></textarea>
                    </div>

                    <div class="field">
                        <label for="eventTime">Time</label>
                        <input type="time" id="eventTime" name="event_time">
                    </div>

                    <div class="field">
                        <label for="eventLocation">Location</label>
                        <input type="text" id="eventLocation" name="location" maxlength="255" placeholder="Optional room or venue">
                    </div>

                    <div class="field">
                        <label for="eventClassId">Class</label>
                        <select id="eventClassId" name="class_id">
                            <option value="">All classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="eventStudentId">Student</label>
                        <select id="eventStudentId" name="student_id">
                            <option value="">No student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int) $student['id'] ?>" data-class-id="<?= (int) $student['class_id'] ?>">
                                    <?= e($student['full_name']) ?> (<?= e($student['student_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field full-width">
                        <label for="eventDeductionId">Related incident ID</label>
                        <input type="number" id="eventDeductionId" name="deduction_id" min="0" placeholder="Optional">
                    </div>

                    <div class="field full-width">
                        <label for="studentSearch">Student search</label>
                        <input type="text" id="studentSearch" placeholder="Filter students by name or code">
                    </div>

                    <div class="field full-width" style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                        <div class="cell-muted">Created by <?= e($authUser['full_name'] ?? 'User') ?></div>
                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                            <button type="button" class="ghost-btn" id="cancelEventBtn">Cancel</button>
                            <button type="submit" class="primary-btn" id="saveEventBtn">
                                <span class="btn-label">Save event</span>
                                <span class="btn-loader" hidden></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function () {
            const baseUrl = '<?= e(app_base_url()) ?>';
            const apiBase = baseUrl + '/api/v1/index.php?route=';
            const initialMonth = '<?= e($monthYear) ?>';
            const initialClassId = '<?= (int) $selectedClassId ?>';
            const authUserId = '<?= (int) ($authUser['id'] ?? 0) ?>';
            const canManageCalendar = <?= $canManageCalendar ? 'true' : 'false' ?>;

            const state = {
                month: new Date(initialMonth + '-01T00:00:00'),
                classId: initialClassId,
                monthEvents: {},
                upcomingEvents: [],
                selectedDate: null,
                dayEvents: [],
            };

            const calendarGrid = document.getElementById('calendarGrid');
            const monthLabel = document.getElementById('monthLabel');
            const monthPicker = document.getElementById('monthPicker');
            const classFilter = document.getElementById('classFilter');
            const statMonthEvents = document.getElementById('statMonthEvents');
            const statUpcoming = document.getElementById('statUpcoming');
            const statClass = document.getElementById('statClass');
            const calendarFeedback = document.getElementById('calendarFeedback');
            const upcomingList = document.getElementById('upcomingList');
            const typeSummary = document.getElementById('typeSummary');
            const dayModal = document.getElementById('dayModal');
            const dayModalTitle = document.getElementById('dayModalTitle');
            const dayEventList = document.getElementById('dayEventList');
            const dayModalFeedback = document.getElementById('dayModalFeedback');
            const eventModal = document.getElementById('eventModal');
            const eventForm = document.getElementById('eventForm');
            const saveEventBtn = document.getElementById('saveEventBtn');
            const saveEventLabel = saveEventBtn.querySelector('.btn-label');
            const saveEventLoader = saveEventBtn.querySelector('.btn-loader');
            const eventDateInput = document.getElementById('eventDate');
            const eventClassSelect = document.getElementById('eventClassId');
            const eventStudentSelect = document.getElementById('eventStudentId');
            const studentSearch = document.getElementById('studentSearch');
            const eventTitleInput = document.getElementById('eventTitle');
            const eventDescriptionInput = document.getElementById('eventDescription');
            const eventTypeSelect = document.getElementById('eventType');
            const eventTimeInput = document.getElementById('eventTime');
            const eventLocationInput = document.getElementById('eventLocation');
            const eventDeductionIdInput = document.getElementById('eventDeductionId');
            const openAddEventTopBtn = document.getElementById('openAddEventTopBtn');
            const openAddEventBtn = document.getElementById('openAddEventBtn');
            const openAddEventFromDayBtn = document.getElementById('openAddEventFromDayBtn');

            const typeMeta = {
                letter_follow_up: { label: 'Letter follow-up', color: '#f59e0b' },
                parent_meeting: { label: 'Parent meeting', color: '#38bdf8' },
                counseling: { label: 'Counseling', color: '#22c55e' },
                detention: { label: 'Detention', color: '#fb7185' },
                custom: { label: 'Custom', color: '#94a3b8' },
            };

            function escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value == null ? '' : String(value);
                return div.innerHTML;
            }

            function setFeedback(node, message, isError) {
                node.classList.remove('error', 'success');
                node.classList.add(isError ? 'error' : 'success');
                node.textContent = message || '';
            }

            function toMonthYear(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                return `${year}-${month}`;
            }

            function toDateString(date) {
                return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
            }

            function syncUrl() {
                const params = new URLSearchParams(window.location.search);
                params.set('month_year', toMonthYear(state.month));
                if (state.classId && state.classId !== '0') {
                    params.set('class_id', state.classId);
                } else {
                    params.delete('class_id');
                }
                const nextUrl = `${window.location.pathname}?${params.toString()}`;
                window.history.replaceState({}, '', nextUrl);
            }

            function setLoading(message) {
                setFeedback(calendarFeedback, message, false);
            }

            function renderCalendar() {
                const year = state.month.getFullYear();
                const month = state.month.getMonth();
                const firstDay = new Date(year, month, 1);
                const lastDay = new Date(year, month + 1, 0);
                const startOffset = firstDay.getDay();
                const totalCells = 42;

                monthLabel.textContent = state.month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
                monthPicker.value = toMonthYear(state.month);
                statClass.textContent = classFilter.selectedOptions[0]?.textContent || 'All classes';

                const todayKey = toDateString(new Date());
                const monthEvents = state.monthEvents || {};
                const allMonthEvents = Object.values(monthEvents).flat();
                statMonthEvents.textContent = allMonthEvents.length;

                const typeCounts = Object.keys(typeMeta).reduce((acc, key) => {
                    acc[key] = 0;
                    return acc;
                }, {});

                allMonthEvents.forEach((event) => {
                    const type = String(event.event_type || 'custom');
                    if (typeCounts[type] !== undefined) {
                        typeCounts[type] += 1;
                    }
                });

                const daysWithEvents = Object.keys(monthEvents).length;
                const uniqueStudents = new Set(allMonthEvents.map((event) => event.student_name || event.student_id).filter(Boolean)).size;

                typeSummary.innerHTML = [
                    ['letter_follow_up', 'Letter follow-up'],
                    ['parent_meeting', 'Parent meeting'],
                    ['counseling', 'Counseling'],
                    ['detention', 'Detention'],
                    ['custom', 'Custom'],
                ].map(([key, label]) => {
                    const count = typeCounts[key] || 0;
                    const width = Math.max(6, Math.min(100, count * 18));
                    return `
                        <div>
                            <div style="display:flex; justify-content:space-between; gap:0.75rem; margin-bottom:0.35rem;">
                                <span>${escapeHtml(label)}</span>
                                <strong>${count}</strong>
                            </div>
                            <div style="height:8px; border-radius:999px; background:rgba(148,163,184,0.18); overflow:hidden;">
                                <div style="width:${width}%; height:100%; background:${typeMeta[key].color}; border-radius:999px;"></div>
                            </div>
                        </div>
                    `;
                }).join('');

                upcomingList.innerHTML = state.upcomingEvents.length
                    ? state.upcomingEvents.map((event) => renderUpcomingItem(event)).join('')
                    : '<div class="notification-item" style="padding: 1rem;">No upcoming events.</div>';

                statUpcoming.textContent = state.upcomingEvents.length;

                const dayMap = monthEvents;
                const cells = [];

                for (let i = 0; i < startOffset; i++) {
                    const d = new Date(year, month, 1 - (startOffset - i));
                    cells.push(renderDayCell(d, true));
                }

                for (let day = 1; day <= lastDay.getDate(); day++) {
                    cells.push(renderDayCell(new Date(year, month, day), false));
                }

                const remaining = totalCells - cells.length;
                for (let i = 1; i <= remaining; i++) {
                    const d = new Date(year, month + 1, i);
                    cells.push(renderDayCell(d, true));
                }

                calendarGrid.innerHTML = cells.join('');
                document.querySelectorAll('[data-date-cell]').forEach((button) => {
                    button.addEventListener('click', () => {
                        openDayModal(button.dataset.date);
                    });
                });

                return { daysWithEvents, uniqueStudents, totalEvents: allMonthEvents.length };
            }

            function renderDayCell(date, muted) {
                const dateKey = toDateString(date);
                const isToday = dateKey === toDateString(new Date());
                const events = state.monthEvents[dateKey] || [];
                const visibleEvents = events.slice(0, 3);
                const moreCount = events.length - visibleEvents.length;

                return `
                    <button type="button" class="calendar-day ${muted ? 'other-month' : ''} ${isToday ? 'today' : ''}" data-date-cell data-date="${dateKey}">
                        <div class="day-number">${date.getDate()}</div>
                        <div class="day-events">
                            ${visibleEvents.map(renderMiniChip).join('')}
                            ${moreCount > 0 ? `<span class="event-badge" style="background: rgba(148,163,184,0.2); color: #e2e8f0;">+${moreCount} more</span>` : ''}
                        </div>
                    </button>
                `;
            }

            function renderMiniChip(event) {
                const meta = typeMeta[String(event.event_type || 'custom')] || typeMeta.custom;
                const time = event.event_time ? String(event.event_time).slice(0, 5) : 'All day';
                return `<span class="event-badge" style="background: ${meta.color}; color: #04101d;">${time} ${escapeHtml(meta.label)}</span>`;
            }

            function renderUpcomingItem(event) {
                const meta = typeMeta[String(event.event_type || 'custom')] || typeMeta.custom;
                const time = event.event_time ? String(event.event_time).slice(0, 5) : 'All day';
                return `
                    <div class="notification-item" style="padding: 0.9rem 1rem; border-left: 4px solid ${meta.color};">
                        <div style="display:flex; justify-content:space-between; gap:0.75rem; align-items:flex-start;">
                            <div>
                                <p style="margin:0; color:#f8fafc; font-weight:700;">${escapeHtml(event.event_title)}</p>
                                <p style="margin:0.2rem 0 0; color:#94a3b8; font-size:0.85rem;">${escapeHtml(event.event_date)}${event.class_name ? ' &bull; ' + escapeHtml(event.class_name) : ''}</p>
                            </div>
                            <strong style="color:${meta.color};">${time}</strong>
                        </div>
                        <p style="margin:0.55rem 0 0; color:#cbd5e1; font-size:0.85rem;">${escapeHtml(meta.label)}${event.student_name ? ' &bull; ' + escapeHtml(event.student_name) : ''}</p>
                    </div>
                `;
            }

            async function loadCalendar() {
                setLoading('Loading calendar...');

                try {
                    syncUrl();

                    const monthYear = toMonthYear(state.month);
                    const monthUrl = `${apiBase}calendar/month&year=${state.month.getFullYear()}&month=${String(state.month.getMonth() + 1).padStart(2, '0')}&class_id=${encodeURIComponent(state.classId || 0)}`;
                    const upcomingUrl = `${apiBase}calendar/upcoming&limit=8&class_id=${encodeURIComponent(state.classId || 0)}`;

                    const [monthResponse, upcomingResponse] = await Promise.all([
                        fetch(monthUrl),
                        fetch(upcomingUrl),
                    ]);

                    const monthPayload = await monthResponse.json();
                    const upcomingPayload = await upcomingResponse.json();

                    if (!monthResponse.ok) {
                        throw new Error(monthPayload.message || monthPayload.error || 'Unable to load month view.');
                    }

                    if (!upcomingResponse.ok) {
                        throw new Error(upcomingPayload.message || upcomingPayload.error || 'Unable to load upcoming events.');
                    }

                    state.monthEvents = monthPayload.calendar || {};
                    state.upcomingEvents = upcomingPayload.events || [];

                    const summary = renderCalendar();
                    document.getElementById('statMonthEvents').textContent = summary.totalEvents;
                    document.getElementById('statUpcoming').textContent = state.upcomingEvents.length;

                    setFeedback(calendarFeedback, summary.totalEvents > 0
                        ? `Loaded ${summary.totalEvents} event(s) across ${summary.daysWithEvents} day(s).`
                        : 'No events found for this month.', false);
                } catch (error) {
                    setFeedback(calendarFeedback, error.message || 'Unable to load calendar.', true);
                    calendarGrid.innerHTML = '<div class="notification-item" style="grid-column: 1 / -1; padding: 1rem;">Unable to load calendar data.</div>';
                    upcomingList.innerHTML = '<div class="notification-item" style="padding: 1rem;">Unable to load upcoming events.</div>';
                    typeSummary.innerHTML = '<div class="notification-item" style="padding: 1rem;">Unable to load summary.</div>';
                }
            }

            async function openDayModal(dateKey) {
                state.selectedDate = dateKey;
                const events = (state.monthEvents[dateKey] || []).slice();
                state.dayEvents = events;

                dayModalTitle.textContent = new Date(dateKey + 'T00:00:00').toLocaleDateString(undefined, {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                });

                if (!events.length) {
                    dayEventList.innerHTML = '<div class="notification-item" style="padding: 1rem;">No events scheduled for this day.</div>';
                } else {
                    dayEventList.innerHTML = events.map((event) => {
                        const meta = typeMeta[String(event.event_type || 'custom')] || typeMeta.custom;
                        return `
                            <div class="notification-item" style="padding: 1rem; border-left: 4px solid ${meta.color};">
                                <div style="display:flex; justify-content:space-between; gap:1rem; align-items:flex-start;">
                                    <div>
                                        <p style="margin:0; font-weight:700; color:#f8fafc;">${escapeHtml(event.event_title)}</p>
                                        <p style="margin:0.3rem 0 0; color:#94a3b8; font-size:0.85rem;">
                                            ${escapeHtml(meta.label)}
                                            ${event.event_time ? ' &bull; ' + escapeHtml(String(event.event_time).slice(0, 5)) : ''}
                                            ${event.class_name ? ' &bull; ' + escapeHtml(event.class_name) : ''}
                                            ${event.student_name ? ' &bull; ' + escapeHtml(event.student_name) : ''}
                                        </p>
                                        ${event.location ? `<p style="margin:0.35rem 0 0; color:#cbd5e1; font-size:0.85rem;">Location: ${escapeHtml(event.location)}</p>` : ''}
                                        ${event.description ? `<p style="margin:0.35rem 0 0; color:#cbd5e1; font-size:0.85rem;">${escapeHtml(event.description)}</p>` : ''}
                                    </div>
                                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; justify-content:flex-end;">
                                        ${canManageCalendar ? `<button type="button" class="ghost-btn" data-delete-event="${event.id}" style="padding:0.3rem 0.7rem; color:#fb7185;">Delete</button>` : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('');
                }

                document.querySelectorAll('[data-delete-event]').forEach((button) => {
                    button.addEventListener('click', () => {
                        deleteEvent(parseInt(button.dataset.deleteEvent, 10));
                    });
                });

                dayModal.style.display = 'flex';
            }

            function closeDayModal() {
                dayModal.style.display = 'none';
            }

            function openEventModal(dateKey) {
                const date = dateKey || state.selectedDate || toDateString(new Date());
                eventDateInput.value = date;
                eventClassSelect.value = state.classId && state.classId !== '0' ? state.classId : '';
                filterStudents();
                eventModal.style.display = 'flex';
            }

            function closeEventModal() {
                eventModal.style.display = 'none';
            }

            function filterStudents() {
                const classId = eventClassSelect.value || state.classId || '0';
                const query = studentSearch.value.trim().toLowerCase();
                const options = Array.from(eventStudentSelect.options);

                options.forEach((option) => {
                    if (!option.value) {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }

                    const matchesClass = classId === '0' || !classId || option.dataset.classId === classId;
                    const text = (option.textContent || '').toLowerCase();
                    const matchesQuery = !query || text.includes(query);
                    const visible = matchesClass && matchesQuery;
                    option.hidden = !visible;
                    option.disabled = !visible;
                });

                const selected = eventStudentSelect.selectedOptions[0];
                if (selected && selected.hidden) {
                    eventStudentSelect.value = '';
                }
            }

            function setSubmitting(isSubmitting) {
                saveEventBtn.disabled = isSubmitting;
                saveEventLoader.hidden = !isSubmitting;
                saveEventLabel.textContent = isSubmitting ? 'Saving...' : 'Save event';
            }

            async function deleteEvent(eventId) {
                if (!confirm('Delete this calendar event?')) {
                    return;
                }

                try {
                    const response = await fetch(`${apiBase}calendar/events`, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ event_id: eventId }),
                    });

                    const payload = await response.json();
                    if (!response.ok || payload.success === false || payload.error) {
                        throw new Error(payload.message || payload.error || 'Unable to delete event.');
                    }

                    setFeedback(dayModalFeedback, payload.message || 'Event deleted.', false);
                    await loadCalendar();
                    if (state.selectedDate) {
                        await openDayModal(state.selectedDate);
                    }
                } catch (error) {
                    setFeedback(dayModalFeedback, error.message || 'Unable to delete event.', true);
                }
            }

            async function submitEvent(event) {
                event.preventDefault();
                setSubmitting(true);
                setFeedback(calendarFeedback, '', false);

                try {
                    const payload = {
                        event_type: eventTypeSelect.value,
                        event_title: eventTitleInput.value.trim(),
                        description: eventDescriptionInput.value.trim(),
                        event_date: eventDateInput.value,
                        event_time: eventTimeInput.value || null,
                        location: eventLocationInput.value.trim() || null,
                        class_id: eventClassSelect.value ? parseInt(eventClassSelect.value, 10) : null,
                        student_id: eventStudentSelect.value ? parseInt(eventStudentSelect.value, 10) : null,
                        deduction_id: eventDeductionIdInput.value ? parseInt(eventDeductionIdInput.value, 10) : null,
                        created_by: parseInt(authUserId, 10),
                    };

                    const response = await fetch(`${apiBase}calendar/events`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(payload),
                    });

                    const result = await response.json();
                    if (!response.ok || result.success === false || result.error) {
                        throw new Error(result.message || result.error || 'Unable to create event.');
                    }

                    setFeedback(calendarFeedback, result.message || 'Event created successfully.', false);
                    closeEventModal();
                    await loadCalendar();
                    if (state.selectedDate) {
                        await openDayModal(state.selectedDate);
                    }
                } catch (error) {
                    setFeedback(calendarFeedback, error.message || 'Unable to create event.', true);
                } finally {
                    setSubmitting(false);
                }
            }

            document.getElementById('prevMonthBtn').addEventListener('click', () => {
                state.month = new Date(state.month.getFullYear(), state.month.getMonth() - 1, 1);
                loadCalendar();
            });

            document.getElementById('nextMonthBtn').addEventListener('click', () => {
                state.month = new Date(state.month.getFullYear(), state.month.getMonth() + 1, 1);
                loadCalendar();
            });

            document.getElementById('jumpTodayBtn').addEventListener('click', () => {
                state.month = new Date();
                state.month.setDate(1);
                loadCalendar();
            });

            monthPicker.addEventListener('change', () => {
                if (!monthPicker.value) return;
                state.month = new Date(monthPicker.value + '-01T00:00:00');
                loadCalendar();
            });

            classFilter.addEventListener('change', () => {
                state.classId = classFilter.value || '0';
                loadCalendar();
            });

            openAddEventTopBtn.addEventListener('click', () => openEventModal(state.selectedDate || toDateString(new Date())));
            openAddEventBtn.addEventListener('click', () => openEventModal(state.selectedDate || toDateString(new Date())));
            openAddEventFromDayBtn.addEventListener('click', () => {
                closeDayModal();
                openEventModal(state.selectedDate || toDateString(new Date()));
            });

            document.getElementById('closeDayModalBtn').addEventListener('click', closeDayModal);
            document.getElementById('closeDayModalBtn2').addEventListener('click', closeDayModal);
            document.getElementById('closeEventModalBtn').addEventListener('click', closeEventModal);
            document.getElementById('cancelEventBtn').addEventListener('click', closeEventModal);

            dayModal.addEventListener('click', (event) => {
                if (event.target === dayModal) {
                    closeDayModal();
                }
            });

            eventModal.addEventListener('click', (event) => {
                if (event.target === eventModal) {
                    closeEventModal();
                }
            });

            eventClassSelect.addEventListener('change', filterStudents);
            studentSearch.addEventListener('input', filterStudents);
            eventForm.addEventListener('submit', submitEvent);

            document.querySelectorAll('[data-date-cell]').forEach((button) => {
                button.addEventListener('click', () => openDayModal(button.dataset.date));
            });

            // Calendar day click delegation after first render.
            calendarGrid.addEventListener('click', (event) => {
                const target = event.target.closest('[data-date-cell]');
                if (!target) return;
                openDayModal(target.dataset.date);
            });

            loadCalendar();

            // Make the selected class searchable in the add dialog too.
            filterStudents();
        })();
        </script>

        <style>
            .calendar-shell-card {
                min-height: 100%;
            }

            .calendar-grid-shell {
                border: 1px solid rgba(148, 163, 184, 0.12);
                border-radius: 18px;
                overflow: hidden;
                background: rgba(255, 255, 255, 0.02);
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
            }

            .calendar-weekdays,
            .calendar-grid {
                display: grid;
                grid-template-columns: repeat(7, minmax(0, 1fr));
                gap: 0;
            }

            .calendar-weekdays div {
                padding: 0.8rem 0.7rem;
                text-align: center;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                font-size: 0.72rem;
                font-weight: 700;
                color: #94a3b8;
                background: rgba(15, 23, 42, 0.35);
                border-bottom: 1px solid rgba(148, 163, 184, 0.12);
            }

            .calendar-day {
                min-height: 118px;
                border: 0;
                padding: 0.75rem;
                background: rgba(255, 255, 255, 0.02);
                color: inherit;
                text-align: left;
                border-right: 1px solid rgba(148, 163, 184, 0.08);
                border-bottom: 1px solid rgba(148, 163, 184, 0.08);
                transition: transform 0.16s ease, background 0.16s ease, box-shadow 0.16s ease;
                cursor: pointer;
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }

            .calendar-day:hover {
                transform: translateY(-1px);
                background: rgba(56, 189, 248, 0.06);
                box-shadow: inset 0 0 0 1px rgba(56, 189, 248, 0.2);
            }

            .calendar-day.today {
                background: linear-gradient(180deg, rgba(56, 189, 248, 0.15), rgba(34, 197, 94, 0.08));
            }

            .calendar-day.other-month {
                opacity: 0.4;
                background: rgba(255, 255, 255, 0.01);
            }

            .day-number {
                font-size: 0.95rem;
                font-weight: 800;
                color: #f8fafc;
            }

            .day-events {
                display: grid;
                gap: 0.35rem;
            }

            .event-badge {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                padding: 0.25rem 0.45rem;
                border-radius: 999px;
                font-size: 0.72rem;
                font-weight: 700;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            @media (max-width: 900px) {
                .calendar-weekdays div,
                .calendar-day {
                    min-height: 92px;
                }
            }

            @media (max-width: 768px) {
                .calendar-weekdays div {
                    font-size: 0.62rem;
                    padding: 0.6rem 0.35rem;
                }

                .calendar-day {
                    padding: 0.55rem;
                }

                .day-number {
                    font-size: 0.82rem;
                }

                .event-badge {
                    font-size: 0.65rem;
                }

                .modal-panel {
                    max-height: 92vh;
                    overflow-y: auto;
                }

                #eventForm {
                    grid-template-columns: 1fr !important;
                }
            }
        </style>
        <style>
            body.dashboard-calendar-body {
                background:
                    radial-gradient(circle at top left, rgba(56, 189, 248, 0.16), transparent 26%),
                    radial-gradient(circle at bottom right, rgba(34, 197, 94, 0.14), transparent 24%),
                    linear-gradient(180deg, #050b16 0%, #0a1322 100%);
            }

            .dashboard-calendar-body .dashboard-shell .auth-panel,
            .dashboard-calendar-body .stat-card,
            .dashboard-calendar-body .calendar-shell-card,
            .dashboard-calendar-body .modal-panel {
                background: linear-gradient(180deg, rgba(10, 20, 36, 0.96), rgba(10, 20, 36, 0.88)) !important;
                border-color: rgba(255, 255, 255, 0.14) !important;
            }

            .dashboard-calendar-body .calendar-grid-shell {
                background: rgba(7, 17, 31, 0.8);
                border-color: rgba(255, 255, 255, 0.14);
            }

            .dashboard-calendar-body .calendar-weekdays div {
                background: rgba(255, 255, 255, 0.06);
                color: #e2e8f0;
            }

            .dashboard-calendar-body .calendar-day {
                background: rgba(255, 255, 255, 0.05);
                color: #f8fafc;
            }

            .dashboard-calendar-body .calendar-day.other-month {
                background: rgba(255, 255, 255, 0.02);
                opacity: 0.5;
            }

            .dashboard-calendar-body .calendar-day.today {
                background: linear-gradient(180deg, rgba(56, 189, 248, 0.2), rgba(34, 197, 94, 0.14));
            }

            .dashboard-calendar-body .day-number,
            .dashboard-calendar-body .lead,
            .dashboard-calendar-body .compact-lead,
            .dashboard-calendar-body .feature-list,
            .dashboard-calendar-body .feature-list li,
            .dashboard-calendar-body .stat-card span,
            .dashboard-calendar-body .stat-card strong,
            .dashboard-calendar-body .notification-item {
                color: #f8fafc;
            }

            .dashboard-calendar-body .notification-item {
                background: rgba(255, 255, 255, 0.06);
                border: 1px solid rgba(255, 255, 255, 0.12);
            }

            .dashboard-calendar-body #calendarFeedback,
            .dashboard-calendar-body #dayModalFeedback {
                min-height: 24px;
                padding: 0.85rem 1rem;
                border-radius: 14px;
                border: 1px solid rgba(255, 255, 255, 0.12);
                background: rgba(255, 255, 255, 0.06);
                color: #e2e8f0;
            }

            .dashboard-calendar-body .feedback.error {
                background: rgba(248, 113, 113, 0.16);
                border-color: rgba(248, 113, 113, 0.28);
                color: #fecaca;
            }

            .dashboard-calendar-body .feedback.success {
                background: rgba(34, 197, 94, 0.16);
                border-color: rgba(34, 197, 94, 0.28);
                color: #bbf7d0;
            }

            .dashboard-calendar-body .calendar-grid-shell,
            .dashboard-calendar-body .calendar-day,
            .dashboard-calendar-body .calendar-weekdays div,
            .dashboard-calendar-body .notification-item {
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
            }
        </style>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
