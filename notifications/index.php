<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/plexus.php';
require_once dirname(__DIR__) . '/models/Notification.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/auth/session.php';

require_auth();
$user = current_auth_user();

$db = Database::connection();
$notificationModel = new Notification($db);
$scope = $notificationModel->describeVisibilityScope((int) $user['id']);

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$allNotifications = $notificationModel->fetchAll((int)$user['id'], $limit, $offset);
$groupedNotifications = $notificationModel->formatGrouped($allNotifications);
$unreadCount = $notificationModel->countUnread((int)$user['id']);
$scope = $notificationModel->describeVisibilityScope((int) $user['id']);

?>
<?php
$pageTitle = 'All Notifications - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/notifications.js', 'assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
            <header class="dashboard-header" style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <p class="eyebrow" style="color: var(--brand-2);">System Alerts</p>
                    <h1 style="margin: 0; font-size: 2rem;">All Notifications</h1>
                    <p class="lead compact-lead" style="margin-top: 0.5rem; max-width: 760px;">
                        <span class="sidebar-scope-tag scope-<?= e($scope['tone']) ?>" style="margin-left: 0; margin-right: 0.5rem; vertical-align: middle;">
                            <span aria-hidden="true" style="margin-right: 0.25rem;"><?= e($scope['icon']) ?></span>
                            <?= e($scope['short_label']) ?>
                        </span>
                        <strong><?= e($scope['label']) ?>:</strong> <?= e($scope['description']) ?>
                    </p>
                </div>
                <?php if ($unreadCount > 0): ?>
                    <button id="markAllReadPageBtn" class="primary-btn" style="padding: 10px 20px;">
                        Mark All as Read
                    </button>
                <?php endif; ?>
            </header>

            <section class="dashboard-grid" style="grid-template-columns: 1fr;">
                <article class="auth-panel dashboard-module-card" style="min-height: 500px;">
                    <?= csrf_input() ?>
                    <div id="notificationsContainer">
                        <?php if (empty($groupedNotifications)): ?>
                            <div style="text-align: center; padding: 3rem 1rem;">
                                <p style="color: #64748b; font-size: 1.1rem;">You have no notifications.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($groupedNotifications as $label => $items): ?>
                                <p class="group-label" style="font-size: 0.8rem; color: var(--brand-2); text-transform: uppercase; letter-spacing: 0.05em; margin: 20px 0 10px; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 5px;">
                                    <?= e($label) ?>
                                </p>
                                <div style="display: grid; gap: 10px;">
                                    <?php foreach ($items as $notif): ?>
                                        <?php
                                        $priorityColor = match($notif['priority'] ?? 'low') {
                                            'high' => '#fb7185',
                                            'medium' => '#f59e0b',
                                            default => '#38bdf8'
                                        };
                                        $isUnread = ($notif['status'] === 'unread');
                                        ?>
                                        <div class="notification-item <?= $isUnread ? 'unread' : 'read' ?>" style="padding: 15px; background: rgba(255,255,255,0.02); border-radius: 8px; display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; border-left: 4px solid <?= $priorityColor ?>; <?= !$isUnread ? 'opacity: 0.7;' : '' ?>">
                                            <div style="flex: 1;">
                                                <p style="margin: 0 0 5px 0; font-size: 1rem; color: #f8fafc; <?= !$isUnread ? 'font-weight: normal;' : 'font-weight: 600;' ?>"><?= e($notif['message']) ?></p>
                                                <span style="font-size: 0.8rem; color: #94a3b8;"><?= e(date('F j, Y, g:i a', strtotime((string)$notif['timestamp']))) ?></span>
                                            </div>
                                            <?php if ($isUnread): ?>
                                                <button class="mark-read-btn ghost-btn" data-id="<?= (int)$notif['id'] ?>" title="Mark as read" 
                                                    style="padding: 5px 10px; font-size: 0.8rem;">✓ Mark Read</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (count($allNotifications) === $limit): ?>
                        <div style="margin-top: 30px; text-align: center;">
                            <a href="?page=<?= $page + 1 ?>" class="btn-secondary" style="display: inline-block; padding: 10px 20px; text-decoration: none;">Load More</a>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
