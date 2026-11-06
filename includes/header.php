<?php
declare(strict_types=1);

require_once __DIR__ . '/vite.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/auth/csrf.php';

$pageTitle = $pageTitle ?? 'SBMS';
$bodyClass = $bodyClass ?? 'sbms-plexus-body';
$mainClass = $mainClass ?? 'dashboard-shell';
$hideSidebar = $hideSidebar ?? false;
$viteAssets = $viteAssets ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1e2a78">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SBMS">
    <link rel="manifest" href="<?= e(app_base_url()) ?>/manifest.webmanifest">
    <link rel="apple-touch-icon" href="<?= e(app_base_url()) ?>/assets/img/app-icon-192.png">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <?php
    require_once dirname(__DIR__) . '/models/Settings.php';
    $sbms_header_db = Database::connection();
    $sbms_header_settingsModel = new Settings($sbms_header_db);
    $custom_favicon = $sbms_header_settingsModel->get('app_favicon', '');
    $sbms_header_appLogo = $sbms_header_settingsModel->get('app_logo', 'assets/img/auth-icon.svg');
    ?>
    <?php if ($custom_favicon): ?>
        <link rel="icon" href="<?= htmlspecialchars(app_base_url() . '/' . $custom_favicon) ?>">
    <?php else: ?>
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect width=%22100%22 height=%22100%22 rx=%2220%22 fill=%22%231e2a78%22/><text x=%2250%22 y=%2272%22 font-family=%22sans-serif%22 font-size=%2265%22 font-weight=%22bold%22 fill=%22%23ffffff%22 text-anchor=%22middle%22>S</text></svg>">
    <?php endif; ?>
    <?php render_plexus_head(); ?>
    <?php vite_tags($viteAssets); ?>
    <style>
        button,
        input[type="button"],
        input[type="submit"],
        input[type="reset"],
        .btn-primary,
        .btn-secondary,
        .primary-btn,
        .ghost-btn,
        .inline-btn,
        .icon-btn,
        .ghost-link {
            font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
        }

        body, html { max-width: 100%; overflow-x: hidden; touch-action: manipulation; }
        .app-container { display: flex; min-height: 100vh; width: 100%; max-width: 100%; overflow-x: hidden; }
        .main-content { flex: 1; min-height: 0; display: flex; flex-direction: column; padding: 2.5rem; padding-bottom: 0; overflow-y: auto; width: 100%; max-width: 100%; box-sizing: border-box; }
        .main-content > * { flex-shrink: 0; }

        /* Ensure all touch targets are at least 44x44px */
        button,
        input[type="button"],
        input[type="submit"],
        input[type="reset"],
        .btn-primary,
        .btn-secondary,
        a.btn,
        [role="button"] {
            min-height: 44px;
            min-width: 44px;
            padding: 10px 16px;
            cursor: pointer;
            -webkit-user-select: none;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            transition: all 0.2s ease;
        }

        button:active,
        input[type="button"]:active,
        input[type="submit"]:active,
        input[type="reset"]:active,
        .btn-primary:active,
        .btn-secondary:active {
            transform: scale(0.98);
        }

        /* Improve form inputs for mobile */
        input,
        select,
        textarea {
            font-size: 16px !important;
            min-height: 44px;
            padding: 10px 12px;
            border-radius: 8px;
            box-sizing: border-box;
        }

        /* Prevent zoom on input focus on iOS */
        input:focus,
        select:focus,
        textarea:focus {
            font-size: 16px !important;
        }

        select,
        select option,
        select optgroup {
            background-color: var(--card, #ffffff) !important;
            color: var(--text, #092032) !important;
            background-image: none !important;
        }

        select:focus,
        select:hover {
            background-color: var(--card, #ffffff) !important;
        }

        .sidebar select,
        .sidebar select option,
        .sidebar select optgroup {
            background-color: var(--sidebar-bg, #1e2a78) !important;
            color: var(--sidebar-text, #e8eef6) !important;
        }

        [data-theme="dark"] select,
        [data-theme="dark"] select option,
        [data-theme="dark"] select optgroup {
            background-color: var(--card, #0a1424) !important;
            color: var(--text, #f5f7fb) !important;
        }

        /* Responsive layouts */
        @media (max-width: 900px) {
            .app-container { flex-direction: column; }
            .main-content { padding: 1rem; width: 100%; max-width: 100vw; }
        }

        @media (max-width: 600px) {
            .main-content { padding: 0.75rem; }
            
            button,
            input[type="button"],
            input[type="submit"],
            input[type="reset"],
            .btn-primary,
            .btn-secondary {
                width: 100%;
                padding: 12px 16px;
                font-size: 1rem;
            }
        }
    </style>
    <link rel="stylesheet" href="<?= htmlspecialchars(vite_asset('assets/css/official.css')) ?>?v=<?= (int) (@filemtime(dirname(__DIR__) . '/assets/css/official.css') ?: 1) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(vite_asset('assets/css/tokens.css')) ?>?v=<?= (int) (@filemtime(dirname(__DIR__) . '/assets/css/tokens.css') ?: 1) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(vite_asset('assets/css/components.css')) ?>?v=<?= (int) (@filemtime(dirname(__DIR__) . '/assets/css/components.css') ?: 1) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(vite_asset('assets/css/mobile-first.css')) ?>?v=<?= (int) (@filemtime(dirname(__DIR__) . '/assets/css/mobile-first.css') ?: 1) ?>">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <a class="sbms-skip-link" href="#main-content">Skip to content</a>
    <?php render_plexus_background(); ?>
    <?php if (!$hideSidebar): ?>
    <header class="mobile-topbar">
        <button type="button" class="mobile-menu-btn" data-sidebar-toggle aria-controls="sbms-sidebar" aria-expanded="false" aria-label="Open navigation">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></svg>
        </button>
        <a class="mobile-topbar-brand" href="<?= e(app_base_url()) ?>/index.php">
            <img src="<?= e(app_base_url()) ?>/<?= e($sbms_header_appLogo) ?>" alt="">
            <span>SBMS</span>
        </a>
        <a class="mobile-topbar-action" href="<?= e(app_base_url()) ?>/notifications/index.php" aria-label="Open notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
        </a>
    </header>
    <div class="sidebar-backdrop" aria-hidden="true"></div>
    <?php endif; ?>
    <?php if (empty($hideSidebar)): ?>
    <nav class="mobile-bottom-nav" aria-label="Mobile quick navigation">
        <a class="mbn-item" data-mbn="dashboard" href="<?= e(app_base_url()) ?>/index.php">
            <span class="mbn-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
            <span class="mbn-label">Home</span>
        </a>
        <a class="mbn-item" data-mbn="students" href="<?= e(app_base_url()) ?>/students/index.php">
            <span class="mbn-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"/><circle cx="10" cy="7" r="3"/><path d="M20 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
            <span class="mbn-label">Students</span>
        </a>
        <a class="mbn-item mbn-item--primary" data-mbn="log" href="<?= e(app_base_url()) ?>/discipline/quick-log.php" aria-label="Log a discipline record">
            <span class="mbn-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14"/><path d="M5 12h14"/></svg></span>
            <span class="mbn-label">Log</span>
        </a>
        <a class="mbn-item" data-mbn="alerts" href="<?= e(app_base_url()) ?>/notifications/index.php">
            <span class="mbn-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></span>
            <span class="mbn-label">Alerts</span>
        </a>
        <a class="mbn-item" data-mbn="more" href="<?= e(app_base_url()) ?>/settings/index.php">
            <span class="mbn-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.56V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1.11-1.56 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.56-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.56-1.11 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34H9a1.7 1.7 0 0 0 1-1.56V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.56 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06-.06a1.7 1.7 0 0 0-.34 1.87V9a1.7 1.7 0 0 0 1.56 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.56 1Z"/></svg></span>
            <span class="mbn-label">More</span>
        </a>
    </nav>
    <?php endif; ?>
    <div class="app-container">
        <?php if (!$hideSidebar) require_once __DIR__ . '/sidebar.php'; ?>
        <main id="main-content" tabindex="-1" class="<?= htmlspecialchars($mainClass) ?> main-content">
    <script>
        (function () {
            var body = document.body;
            var toggle = document.querySelector('[data-sidebar-toggle]');
            var backdrop = document.querySelector('.sidebar-backdrop');
            var sidebar = document.querySelector('.sidebar');
            if (!toggle || !backdrop || !sidebar) {
                return;
            }

            function setOpen(open) {
                body.classList.toggle('sidebar-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
                if (open) {
                    var closeBtn = sidebar.querySelector('[data-sidebar-close]');
                    if (closeBtn) {
                        closeBtn.focus({ preventScroll: true });
                    }
                }
            }

            toggle.addEventListener('click', function () {
                setOpen(!body.classList.contains('sidebar-open'));
            });

            backdrop.addEventListener('click', function () {
                setOpen(false);
            });

            var closeBtn = sidebar.querySelector('[data-sidebar-close]');
            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    setOpen(false);
                    toggle.focus({ preventScroll: true });
                });
            }

            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    setOpen(false);
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && body.classList.contains('sidebar-open')) {
                    setOpen(false);
                    toggle.focus({ preventScroll: true });
                }
            });

            var desktop = window.matchMedia('(min-width: 1025px)');
            var handleViewport = function (event) {
                if (event.matches) {
                    setOpen(false);
                }
            };
            if (desktop.addEventListener) {
                desktop.addEventListener('change', handleViewport);
            } else if (desktop.addListener) {
                desktop.addListener(handleViewport);
            }
        })();
    </script>
    <script>
        (function () {
            var nav = document.querySelector('.mobile-bottom-nav');
            if (!nav) {
                return;
            }

            document.body.classList.add('has-mobile-nav');

            var links = Array.prototype.slice.call(nav.querySelectorAll('[data-mbn]'));
            var home = nav.querySelector('[data-mbn="dashboard"]');
            var homePath = home ? new URL(home.href, window.location.origin).pathname : '/';
            var base = homePath.replace(/\/index\.php$/, '');
            var path = window.location.pathname;
            var section = '';

            if (path === homePath || path === base + '/') {
                section = 'dashboard';
            } else if (path.indexOf(base + '/students') === 0) {
                section = 'students';
            } else if (path.indexOf(base + '/discipline') === 0) {
                section = 'log';
            } else if (path.indexOf(base + '/notifications') === 0) {
                section = 'alerts';
            } else if (path.indexOf(base + '/settings') === 0 || path.indexOf(base + '/profile') === 0) {
                section = 'more';
            }

            links.forEach(function (link) {
                var active = link.getAttribute('data-mbn') === section;
                link.classList.toggle('active', active);
                if (active) {
                    link.setAttribute('aria-current', 'page');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        })();
    </script>
