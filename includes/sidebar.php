<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Notification.php';
require_once dirname(__DIR__) . '/config/db.php';

$sbms_sidebar_user = current_auth_user();
if (!$sbms_sidebar_user) {
    return; // Should not happen if require_auth() is used properly
}

$sbms_sidebar_db = Database::connection();
$sbms_sidebar_settingsModel = new Settings($sbms_sidebar_db);
$sbms_sidebar_userModel = new User($sbms_sidebar_db);
$sbms_sidebar_notificationModel = new Notification($sbms_sidebar_db);

$sbms_sidebar_currentTerm = SchoolYear::normalizeTerm($sbms_sidebar_settingsModel->get('current_term', 1));
$sbms_sidebar_selectedSchoolYear = trim((string) $sbms_sidebar_settingsModel->get('selected_school_year', SchoolYear::calculateCurrentAcademicYear()));
$sbms_sidebar_schoolYears = (new SchoolYear(Database::connection()))->all();
$sbms_sidebar_userId = isset($sbms_sidebar_user['id']) ? (int) $sbms_sidebar_user['id'] : 0;
$sbms_sidebar_is_admin = $sbms_sidebar_userId > 0 && $sbms_sidebar_userModel->userHasPrivilegedRole($sbms_sidebar_userId);
$sbms_sidebar_roleName = strtolower((string) ($sbms_sidebar_user['role_name'] ?? ''));
$sbms_sidebar_can_view_reports = $sbms_sidebar_is_admin
    || $sbms_sidebar_roleName === 'headteacher'
    || stripos($sbms_sidebar_roleName, 'discipline') !== false;
$sbms_sidebar_unreadCount = $sbms_sidebar_userId > 0 ? $sbms_sidebar_notificationModel->countUnread($sbms_sidebar_userId) : 0;
$sbms_sidebar_notificationScope = $sbms_sidebar_userId > 0
    ? $sbms_sidebar_notificationModel->describeVisibilityScope($sbms_sidebar_userId)
    : ['short_label' => 'General', 'label' => 'General notifications only', 'description' => '', 'class_names' => [], 'tone' => 'general', 'icon' => '🔔'];
$sbms_sidebar_requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$sbms_sidebar_basePath = rtrim((string) parse_url(app_base_url(), PHP_URL_PATH), '/');
if ($sbms_sidebar_basePath !== '' && str_starts_with($sbms_sidebar_requestPath, $sbms_sidebar_basePath)) {
    $sbms_sidebar_requestPath = substr($sbms_sidebar_requestPath, strlen($sbms_sidebar_basePath)) ?: '/';
}
$sbms_sidebar_currentPath = rtrim(app_base_url(), '/') . $sbms_sidebar_requestPath;
$sbms_sidebar_appLogo = $sbms_sidebar_settingsModel->get('app_logo', 'assets/img/logo.svg');
$sbms_sidebar_full_user = $sbms_sidebar_userModel->findById((int) $sbms_sidebar_user['id']);
$sbms_sidebar_profile_pic = $sbms_sidebar_full_user['profile_picture'] ?? null;
?>
<style>
.sidebar-link {
    color: var(--sidebar-muted, #94a3b8);
    text-decoration: none;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    font-size: 0.9rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    transition: all 0.2s ease-in-out;
    background: transparent;
    border: 1px solid transparent;
}
.sidebar-link-inner {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
    flex: 1;
}
.sidebar-link-icon {
    width: 1.1rem;
    height: 1.1rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: currentColor;
    opacity: 0.95;
}
.sidebar-link-icon svg {
    width: 100%;
    height: 100%;
    stroke: currentColor;
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.sidebar-link-text {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sidebar-link.submenu-toggle {
    background: transparent !important;
    color: var(--sidebar-text, #f8fafc) !important;
}
.sidebar-link:hover {
    color: var(--sidebar-text, #f8fafc);
    background: var(--sidebar-hover-bg, rgba(255, 255, 255, 0.05));
    transform: translateX(4px);
}
.sidebar-link.active {
    color: var(--sidebar-active-text, #ffffff);
    background: linear-gradient(135deg, var(--brand, #22c55e), var(--brand-2, #38bdf8));
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(56, 189, 248, 0.2);
}
.sidebar-submenu .sidebar-link {
    padding: 0.5rem 1rem;
    font-size: 0.85rem;
    border-radius: 6px;
}
.sidebar-submenu .sidebar-link:hover {
    transform: translateX(2px);
}
.sidebar {
    width: 260px;
    background: linear-gradient(180deg, rgba(10, 20, 36, 0.96), rgba(10, 20, 36, 0.88));
    backdrop-filter: blur(12px);
    border-right: 1px solid var(--sidebar-border, rgba(255,255,255,0.05));
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    position: sticky;
    top: 0;
    height: 100vh;
    z-index: 100;
    overflow-y: auto;
    overflow-x: hidden;
    transition: background 0.3s ease, border-color 0.3s ease;
}

[data-theme="light"] .sidebar {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(240, 244, 248, 0.92));
}

.sidebar-brand h2 {
    color: transparent;
}

#digital-clock {
    background: var(--sidebar-hover-bg, rgba(255, 255, 255, 0.08)) !important;
    color: var(--sidebar-text, #f8fafc) !important;
    border-color: var(--brand-2, #38bdf8) !important;
}

.sidebar-user > img,
.sidebar-user > div {
    background: var(--sidebar-hover-bg, rgba(255, 255, 255, 0.08)) !important;
    border-color: var(--brand-2, #38bdf8) !important;
}

.sidebar-user h3 {
    color: var(--sidebar-text, #f8fafc) !important;
}

.sidebar-user p {
    color: var(--sidebar-muted, #94a3b8) !important;
}

.sidebar-nav > .eyebrow,
.sidebar-group .eyebrow,
.sidebar-term-switcher .eyebrow {
    color: var(--brand-2, #38bdf8) !important;
}

.sidebar-submenu {
    background: rgba(255, 255, 255, 0.02);
    border-left: 1px solid var(--sidebar-border, rgba(255,255,255,0.05));
}

[data-theme="light"] .sidebar-submenu {
    background: rgba(9, 32, 50, 0.03);
}

.sidebar-footer select,
.sidebar-term-switcher select {
    background: var(--sidebar-bg, #1e293b) !important;
    color: var(--sidebar-text, #f8fafc) !important;
    border-color: var(--sidebar-border, rgba(255,255,255,0.1)) !important;
}

.sidebar-footer button[onclick*="toggleTheme"],
.sidebar-footer a.btn-secondary {
    background: var(--sidebar-hover-bg, rgba(255,255,255,0.08)) !important;
    border-color: var(--sidebar-border, rgba(255,255,255,0.1)) !important;
    color: var(--sidebar-text, #f8fafc) !important;
}

#sidebarNotifBadge {
    background: #ef4444 !important;
    color: #ffffff !important;
}

.sidebar-scope-tag {
    display: inline-flex;
    align-items: center;
    margin-left: 0.5rem;
    padding: 0.15rem 0.45rem;
    border-radius: 999px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--brand-2, #38bdf8);
    background: rgba(56, 189, 248, 0.12);
    border: 1px solid rgba(56, 189, 248, 0.2);
}

.sidebar-scope-tag.scope-all {
    color: #10b981;
    background: rgba(16, 185, 129, 0.12);
    border-color: rgba(16, 185, 129, 0.25);
}

.sidebar-scope-tag.scope-class {
    color: #f59e0b;
    background: rgba(245, 158, 11, 0.14);
    border-color: rgba(245, 158, 11, 0.28);
}

.sidebar-scope-tag.scope-general {
    color: #38bdf8;
    background: rgba(56, 189, 248, 0.12);
    border-color: rgba(56, 189, 248, 0.2);
}

/* Custom Scrollbar for Sidebar */
.sidebar::-webkit-scrollbar {
    width: 6px;
}
.sidebar::-webkit-scrollbar-track {
    background: transparent;
}
.sidebar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
}
.sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.2);
}

.sidebar-user h3 {
    color: var(--text, #f8fafc);
}

.sidebar-user p {
    color: var(--muted, #94a3b8);
}

@media (max-width: 900px) {
    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
        border-right: none;
        border-bottom: 1px solid var(--sidebar-border, rgba(255,255,255,0.05));
    }
}
</style>
<nav class="sidebar" id="sbms-sidebar" aria-label="Main navigation">
    <button type="button" class="sidebar-close" data-sidebar-close aria-label="Close navigation">&times;</button>
    <div class="sidebar-brand" style="margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.85rem; min-width: 0;">
            <img src="<?= e(app_base_url()) ?>/<?= e($sbms_sidebar_appLogo) ?>" alt="Logo" style="width: 32px; height: 32px; flex-shrink: 0;">
            <div style="min-width: 0;">
                <h2 style="margin: 0; font-size: 1.2rem; background: linear-gradient(135deg, var(--brand, #22c55e), var(--brand-2, #38bdf8)); -webkit-background-clip: text; color: transparent; line-height: 1.15;">SBMS</h2>
                <p class="sidebar-brand-school"><?= e($sbms_sidebar_settingsModel->get('school_name', 'School Management')) ?></p>
            </div>
        </div>
        
        <!-- Digital Clock Widget -->
        <div id="digital-clock" style="font-family: monospace; font-size: 0.95rem; font-weight: 700; color: var(--text, #f8fafc); background: rgba(255,255,255,0.06); padding: 6px 10px; border-radius: 8px; border: 1px solid var(--brand-2, #38bdf8); box-shadow: 0 4px 12px rgba(56, 189, 248, 0.1); letter-spacing: 0.05em;">
            00:00:00
        </div>
    </div>
    
    <script>
    function updateDigitalClock() {
        const now = new Date();
        let h = now.getHours();
        let m = now.getMinutes();
        let s = now.getSeconds();
        const ampm = h >= 12 ? 'PM' : 'AM';
        
        h = h % 12;
        h = h ? h : 12; // the hour '0' should be '12'
        
        // Pad with zero
        h = h < 10 ? '0' + h : h;
        m = m < 10 ? '0' + m : m;
        s = s < 10 ? '0' + s : s;
        
        const clockEl = document.getElementById('digital-clock');
        if (clockEl) {
            clockEl.textContent = `${h}:${m}:${s} ${ampm}`;
        }
    }
    setInterval(updateDigitalClock, 1000);
    if (document.readyState === 'loading') {
        window.addEventListener('DOMContentLoaded', updateDigitalClock);
    } else {
        updateDigitalClock();
    }
    </script>

    <div class="sidebar-user" style="padding-bottom: 1rem; border-bottom: 1px solid var(--sidebar-border, rgba(255,255,255,0.05));">
        <p class="eyebrow" style="margin-bottom: 0.5rem; font-size: 0.7rem; opacity: 0.6; color: var(--brand-2, #38bdf8);"><?= e(__('signed_in')) ?></p>
        <?php if ($sbms_sidebar_profile_pic): ?>
            <img src="<?= e(app_base_url()) ?>/<?= e($sbms_sidebar_profile_pic) ?>" alt="Profile" style="width: 48px; height: 48px; border-radius: 50%; object-fit: contain; margin-bottom: 10px; border: 2px solid var(--brand-2, #38bdf8); background: rgba(255,255,255,0.06);">
        <?php else: ?>
            <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(255,255,255,0.1); margin-bottom: 10px; border: 2px solid var(--brand-2, #38bdf8); display: flex; align-items: center; justify-content: center; font-weight: bold; color: var(--brand-2, #38bdf8);">
                <?= e(strtoupper(substr($sbms_sidebar_user['full_name'] ?? 'U', 0, 1))) ?>
            </div>
        <?php endif; ?>
        <h3 style="margin: 0; font-size: 1rem; color: var(--text, #f8fafc);"><?= e($sbms_sidebar_user['full_name'] ?? 'User') ?></h3>
        <p style="margin: 0; font-size: 0.8rem; color: var(--muted, #94a3b8);"><?= e($sbms_sidebar_user['role_name'] ?? 'Unknown') ?></p>
        <a href="<?= e(app_base_url()) ?>/profile/index.php" style="display: inline-block; margin-top: 0.75rem; font-size: 0.8rem; color: var(--brand-2, #38bdf8); text-decoration: none;"><?= e(__('my_profile')) ?> &rarr;</a>
    </div>

    <div class="sidebar-nav" style="display: flex; flex-direction: column; gap: 0.5rem; flex: 1;">
        <p class="eyebrow" style="font-size: 0.7rem; opacity: 0.4; margin-bottom: 0.5rem;"><?= e(__('menu')) ?></p>
        <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/index.php">
            <span class="sidebar-link-inner">
                <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M9 20v-6h6v6"/></svg></span>
                <span class="sidebar-link-text"><?= e(__('dashboard')) ?></span>
            </span>
        </a>
        <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/rules/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/rules/index.php">
            <span class="sidebar-link-inner">
                <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5"/><path d="M8 12h8"/><path d="M8 16h8"/></svg></span>
                <span class="sidebar-link-text"><?= e(__('rules_and_regulations')) ?></span>
            </span>
        </a>
        <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/students/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/students/index.php">
            <span class="sidebar-link-inner">
                <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"/><circle cx="10" cy="7" r="3"/><path d="M20 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                <span class="sidebar-link-text"><?= e(__('student_module')) ?></span>
            </span>
        </a>
        <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/discipline/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/discipline/index.php">
            <span class="sidebar-link-inner">
                <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v6c0 4.4 2.8 8.5 7 9 4.2-.5 7-4.6 7-9V6l-7-3Z"/><path d="M9.5 12.5 11 14l3.5-4"/></svg></span>
                <span class="sidebar-link-text"><?= e(__('discipline_engine')) ?></span>
            </span>
        </a>
        <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/discipline/quick-log.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/discipline/quick-log.php">
            <span class="sidebar-link-inner">
                <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 14h8"/><path d="M8 18h5"/></svg></span>
                <span class="sidebar-link-text">Quick Log</span>
            </span>
        </a>
        <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/notifications.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/notifications.php">
            <span class="sidebar-link-inner">
                <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></span>
                <span class="sidebar-link-text">Notification Settings</span>
            </span>
        </a>
        
        <?php if ($sbms_sidebar_can_view_reports): ?>
            <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/reports/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/reports/index.php">
                <span class="sidebar-link-inner">
                    <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19h16"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-7"/></svg></span>
                    <span class="sidebar-link-text"><?= e(__('reports')) ?></span>
                </span>
            </a>
            <div class="sidebar-group" style="margin-top: 0.5rem;">
                <p class="eyebrow" style="font-size: 0.7rem; opacity: 0.4; margin: 0 0 0.5rem 0;">Communication</p>
                <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                    <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/letters/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/letters/index.php">
                        <span class="sidebar-link-inner">
                            <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="M4 7l8 6 8-6"/></svg></span>
                            <span class="sidebar-link-text"><?= e(__('letters')) ?></span>
                        </span>
                    </a>
                    <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/parents/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/parents/index.php">
                        <span class="sidebar-link-inner">
                            <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 19v-1a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v1"/><circle cx="11" cy="7" r="3"/><path d="M22 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                            <span class="sidebar-link-text">Parent Contacts</span>
                        </span>
                    </a>
                    <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/parents/whatsapp-test.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/parents/whatsapp-test.php">
                        <span class="sidebar-link-inner">
                            <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.3-5.4A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 10.5c.2-.5.8-.8 1.3-.9.3-.1.7 0 1 .2l.6.5c.3.2.4.6.2 1-.2.5-.8.9-1.3 1.1-.5.2-1 .5-1.3 1-.3.5-.2 1.1.1 1.5l.6.9c.3.4.9.5 1.4.3.5-.2 1.1-.6 1.8-1.1"/></svg></span>
                            <span class="sidebar-link-text">Test WhatsApp</span>
                        </span>
                    </a>
                </div>
            </div>
            <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/dashboard/analytics.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/dashboard/analytics.php">
                <span class="sidebar-link-inner">
                    <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 18V6"/><path d="M10 18V10"/><path d="M16 18V4"/><path d="M22 18v-8"/></svg></span>
                    <span class="sidebar-link-text">Analytics</span>
                </span>
            </a>
            <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/dashboard/calendar.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/dashboard/calendar.php">
                <span class="sidebar-link-inner">
                    <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></span>
                    <span class="sidebar-link-text">Calendar</span>
                </span>
            </a>
            <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/dashboard/leaderboard.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/dashboard/leaderboard.php">
                <span class="sidebar-link-inner">
                    <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 21h10"/><path d="M8 21V7l4-4 4 4v14"/><path d="M8 11h8"/><path d="M12 7v4"/></svg></span>
                    <span class="sidebar-link-text">Leaderboard</span>
                </span>
            </a>
            <a class="sidebar-link" href="<?= e(app_base_url()) ?>/settings/index.php#set-marks">
                <span class="sidebar-link-inner">
                    <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg></span>
                    <span class="sidebar-link-text"><?= e(__('set_marks')) ?></span>
                </span>
            </a>
        <?php endif; ?>
        
        <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/notifications/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/notifications/index.php">
            <span class="sidebar-link-inner">
                <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></span>
                <span class="sidebar-link-text"><?= e(__('notifications')) ?></span>
            </span>
            <span class="sidebar-scope-tag scope-<?= e($sbms_sidebar_notificationScope['tone']) ?>" title="<?= e($sbms_sidebar_notificationScope['description']) ?>">
                <span aria-hidden="true" style="margin-right: 0.25rem;"><?= e($sbms_sidebar_notificationScope['icon']) ?></span>
                <?= e($sbms_sidebar_notificationScope['short_label']) ?>
            </span>
            <span id="sidebarNotifBadge" title="<?= e($sbms_sidebar_notificationScope['description']) ?>" style="<?= $sbms_sidebar_unreadCount === 0 ? 'display: none;' : '' ?> background: #ef4444; color: white; border-radius: 12px; padding: 2px 8px; font-size: 0.7rem; font-weight: bold; min-width: 20px; text-align: center;">
                <?= $sbms_sidebar_unreadCount ?>
            </span>
        </a>

        <?php if ($sbms_sidebar_is_admin): ?>
            <div class="sidebar-group" style="margin-top: 0.5rem;">
                <button type="button" class="sidebar-link submenu-toggle" id="adminSubmenuToggle" aria-expanded="false" aria-controls="adminSubmenu">
                    <span class="sidebar-link-inner">
                        <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v3"/><path d="M12 18v3"/><path d="M3 12h3"/><path d="M18 12h3"/><circle cx="12" cy="12" r="4"/><path d="M19.4 4.6l-2.1 2.1"/><path d="M6.7 17.3l-2.1 2.1"/><path d="M19.4 19.4l-2.1-2.1"/><path d="M6.7 6.7 4.6 4.6"/></svg></span>
                        <span class="sidebar-link-text"><?= e(__('administrative')) ?></span>
                    </span>
                    <span class="submenu-chevron" aria-hidden="true">&#9662;</span>
                </button>
                <div class="sidebar-submenu" id="adminSubmenu">
                    <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/users/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/users/index.php">
                        <span class="sidebar-link-inner">
                            <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1"/><circle cx="9.5" cy="7" r="3"/><path d="M22 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                            <span class="sidebar-link-text"><?= e(__('user_management')) ?></span>
                        </span>
                    </a>
                    <a class="sidebar-link <?= ($sbms_sidebar_currentPath === app_base_url() . '/settings/index.php') ? 'active' : '' ?>" href="<?= e(app_base_url()) ?>/settings/index.php#system-settings">
                        <span class="sidebar-link-inner">
                            <span class="sidebar-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V20a2 2 0 1 1-4 0v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1A2 2 0 1 1 4.6 17l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3.2a2 2 0 1 1 0-4h.2a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8L4.6 7.1A2 2 0 1 1 7.4 4.3l.1.1a1.7 1.7 0 0 0 1.8.3h.1A1.7 1.7 0 0 0 10.4 3.2V3a2 2 0 1 1 4 0v.2a1.7 1.7 0 0 0 1 1.5h.1a1.7 1.7 0 0 0 1.8-.3l.1-.1A2 2 0 1 1 19.7 6.6l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1A1.7 1.7 0 0 0 20.8 10h.2a2 2 0 1 1 0 4h-.2a1.7 1.7 0 0 0-1.5 1Z"/></svg></span>
                            <span class="sidebar-link-text"><?= e(__('system_settings')) ?></span>
                        </span>
                    </a>
                    <div class="sidebar-term-switcher" style="padding: 0.5rem 1rem;">
                        <p class="eyebrow" style="font-size: 0.65rem; margin-bottom: 0.5rem; opacity: 0.8; color: var(--sidebar-muted, #94a3b8);">School Year</p>
                        <form action="<?= e(app_base_url()) ?>/index.php" method="POST" class="school-year-switcher-form" style="display: flex; flex-direction: column; gap: 5px;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="update_school_year">
                            <select name="school_year" style="flex: 1; font-size: 0.75rem; padding: 4px; border-radius: 4px; background: var(--sidebar-bg, #1e293b); color: var(--sidebar-text, white); border: 1px solid var(--sidebar-border, rgba(255,255,255,0.1));">
                                <?php foreach ($sbms_sidebar_schoolYears as $schoolYear): ?>
                                    <option value="<?= e($schoolYear['name']) ?>" <?= $sbms_sidebar_selectedSchoolYear === (string) $schoolYear['name'] ? 'selected' : '' ?>><?= e($schoolYear['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" style="background: var(--brand-2); border: none; color: #ffffff; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">Set</button>
                        </form>
                        <p class="eyebrow" style="font-size: 0.65rem; margin: 0.5rem 0 0; opacity: 0.8; color: var(--sidebar-muted, #94a3b8);"><?= e(__('active_term')) ?></p>
                        <form action="<?= e(app_base_url()) ?>/index.php" method="POST" class="term-switcher-form" style="display: flex; gap: 5px; margin-top: 0.25rem;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="update_term">
                            <select name="current_term" style="flex: 1; font-size: 0.75rem; padding: 4px; border-radius: 4px; background: var(--sidebar-bg, #1e293b); color: var(--sidebar-text, white); border: 1px solid var(--sidebar-border, rgba(255,255,255,0.1));">
                                <?php foreach (SchoolYear::terms() as $t): ?>
                                    <option value="<?= $t ?>" <?= $sbms_sidebar_currentTerm === $t ? 'selected' : '' ?>>Term <?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" style="background: var(--brand-2); border: none; color: #ffffff; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;"><?= e(__('set')) ?></button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer" style="display: flex; flex-direction: column; gap: 1rem;">
        
        <!-- Language Switcher -->
        <form action="<?= e(app_base_url()) ?>/api/set_language.php" method="POST" style="margin: 0; padding: 0 0.5rem;">
            <?= csrf_input() ?>
            <select name="lang" onchange="this.form.submit()" style="width: 100%; font-size: 0.8rem; padding: 6px; border-radius: 4px; background: var(--sidebar-bg, #1e293b); color: var(--sidebar-text, white); border: 1px solid var(--sidebar-border, rgba(255,255,255,0.1)); cursor: pointer;">
                <option value="en" <?= get_current_language() === 'en' ? 'selected' : '' ?>>English</option>
                <option value="es" <?= get_current_language() === 'es' ? 'selected' : '' ?>>Espa&ntilde;ol</option>
                <option value="fr" <?= get_current_language() === 'fr' ? 'selected' : '' ?>>Fran&ccedil;ais</option>
                <option value="rw" <?= get_current_language() === 'rw' ? 'selected' : '' ?>>Kinyarwanda</option>
            </select>
        </form>

        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: var(--sidebar-muted, #94a3b8); font-size: 0.85rem; padding: 0 0.5rem;">
            <input type="checkbox" id="muteSoundToggle" style="width: 16px; height: 16px; cursor: pointer;">
            <span><?= e(__('mute_notifications')) ?></span>
        </label>
        <button type="button" class="btn-secondary" onclick="window.toggleTheme()" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; padding: 0.75rem; border-radius: 8px; font-size: 0.9rem; cursor: pointer; background: var(--sidebar-hover-bg, rgba(255,255,255,0.08)); border: 1px solid var(--sidebar-border, rgba(255,255,255,0.1)); color: var(--sidebar-text, #f8fafc);">
            <?= e(__('toggle_theme')) ?>
        </button>
        <a class="btn-secondary" href="<?= e(app_base_url()) ?>/auth/logout.php" style="width: 100%; display: block; text-align: center; text-decoration: none; padding: 0.75rem; border-radius: 8px; font-size: 0.9rem; color: var(--sidebar-text, #f8fafc); border-color: var(--sidebar-border, rgba(255,255,255,0.1)); background: transparent;"><?= e(__('logout')) ?></a>
    </div>
</nav>

<script>
    (function () {
        var toggle = document.getElementById('adminSubmenuToggle');
        var submenu = document.getElementById('adminSubmenu');
        if (!toggle || !submenu) {
            return;
        }

        function setOpen(open) {
            submenu.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function (event) {
            // The built dashboard bundle still carries a legacy delegated
            // slideToggle handler; stop it from double-toggling this submenu.
            event.stopPropagation();
            setOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });

        // Keep the group revealed when the current page lives inside it.
        if (submenu.querySelector('.sidebar-link.active')) {
            setOpen(true);
        }
    })();
</script>

<!-- AI Assistant Widget -->
<link rel="stylesheet" href="<?= htmlspecialchars(vite_asset('assets/css/assistant.css')) ?>">

<button id="ai-assistant-btn" aria-label="Open AI Assistant">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
    </svg>
    <svg class="close-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
    </svg>
</button>

<div id="ai-assistant-container">
    <div class="ai-assistant-header">
        <div class="avatar">AI</div>
        <div class="info">
            <h3>SBMS Assistant</h3>
            <p>Online</p>
        </div>
    </div>
    <div id="ai-assistant-messages" class="ai-assistant-messages">
        <!-- Messages appear here -->
    </div>
    <div class="ai-assistant-input-area">
        <input type="text" id="ai-assistant-input" placeholder="Ask about student risks, data, or system help..." aria-label="Ask assistant">
        <button id="ai-assistant-send" aria-label="Send message">
            <span>Send</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
            </svg>
        </button>
    </div>
</div>

<script>window.appBaseUrl = "<?= e(app_base_url()) ?>";</script>
<script src="<?= htmlspecialchars(vite_asset('assets/js/assistant.js')) ?>"></script>
