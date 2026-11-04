<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/check.php';
require_once __DIR__ . '/models/plexus.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Notification.php';

require_auth();
$user = current_auth_user();

$db = Database::connection();
$userModel = new User($db);
$notificationModel = new Notification($db);

$unreadCount = $notificationModel->countUnread((int)$user['id']);
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<?php
$pageTitle = 'Notification Settings - SBMS';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'dashboard-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css'];
$viteFooterAssets = ['assets/js/theme.js', 'assets/js/settings.js'];
require_once __DIR__ . '/includes/header.php';
?>
            <header class="dashboard-header" style="margin-bottom: 2.5rem;">
                <p class="eyebrow" style="color: var(--brand-2);">Configuration</p>
                <h1 style="margin: 0; font-size: 2rem;">User Preferences</h1>
            </header>

            <section class="dashboard-grid" style="grid-template-columns: 1fr;">
                <article class="auth-panel dashboard-module-card">
                    <p class="eyebrow">Alerts & Sounds</p>
                    <h2>Notification Settings</h2>
                    <p class="lead compact-lead">Manage how you receive real-time behavioral alerts and system triggers.</p>

                    <div style="margin-top: 2rem; display: grid; gap: 1rem;">
                        <label style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 1rem; background: var(--of-page); border-radius: 12px; border: 1px solid var(--of-line);">
                            <div>
                                <strong style="display: block; color: var(--of-ink);">Audio Alerts</strong>
                                <span style="font-size: 0.85rem; color: var(--of-muted);">Play a sound when a new notification arrives.</span>
                            </div>
                            <input type="checkbox" id="settingMuteToggle" style="width: 20px; height: 20px; cursor: pointer;">
                        </label>

                        <label style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 1rem; background: var(--of-page); border-radius: 12px; border: 1px solid var(--of-line);">
                            <div>
                                <strong style="display: block; color: var(--of-ink);">Desktop Notifications</strong>
                                <span style="font-size: 0.85rem; color: var(--of-muted);">Show browser push notifications for important events.</span>
                            </div>
                            <input type="checkbox" id="settingDesktopToggle" style="width: 20px; height: 20px; cursor: pointer;">
                        </label>
                    </div>

                    <div style="margin-top: 1.5rem; display: grid; gap: 1rem;">
                        <div style="padding: 1rem; border-radius: 14px; background: var(--of-page); border: 1px solid var(--of-line);">
                            <p class="eyebrow" style="margin-bottom: 0.35rem;">Sound Rules</p>
                            <h3 style="margin: 0 0 0.35rem 0; font-size: 1.05rem;">Priority-based alerts</h3>
                            <p style="margin: 0; font-size: 0.9rem; color: var(--of-muted);">Choose which alert levels should make a sound. These settings are saved in your browser.</p>

                            <div style="margin-top: 1rem; display: grid; gap: 0.85rem;">
                                <label style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.9rem 1rem; background: var(--of-page); border-radius: 10px; border: 1px solid var(--of-line);">
                                    <div>
                                        <strong style="display: block; color: var(--of-ink);">High priority sound</strong>
                                        <span style="font-size: 0.85rem; color: var(--of-muted);">Critical disciplinary alerts and urgent events.</span>
                                    </div>
                                    <input type="checkbox" id="settingHighPrioritySound" style="width: 18px; height: 18px; cursor: pointer;">
                                </label>

                                <label style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.9rem 1rem; background: var(--of-page); border-radius: 10px; border: 1px solid var(--of-line);">
                                    <div>
                                        <strong style="display: block; color: var(--of-ink);">Medium priority sound</strong>
                                        <span style="font-size: 0.85rem; color: var(--of-muted);">Standard behavior updates and follow-up prompts.</span>
                                    </div>
                                    <input type="checkbox" id="settingMediumPrioritySound" style="width: 18px; height: 18px; cursor: pointer;">
                                </label>

                                <label style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.9rem 1rem; background: var(--of-page); border-radius: 10px; border: 1px solid var(--of-line);">
                                    <div>
                                        <strong style="display: block; color: var(--of-ink);">Low priority sound</strong>
                                        <span style="font-size: 0.85rem; color: var(--of-muted);">Gentle reminders and low-urgency updates.</span>
                                    </div>
                                    <input type="checkbox" id="settingLowPrioritySound" style="width: 18px; height: 18px; cursor: pointer;">
                                </label>
                            </div>
                        </div>

                        <div style="padding: 1rem; border-radius: 14px; background: var(--of-page); border: 1px solid var(--of-line);">
                            <p class="eyebrow" style="margin-bottom: 0.35rem;">Custom Audio</p>
                            <h3 style="margin: 0 0 0.35rem 0; font-size: 1.05rem;">Preview a custom sound</h3>
                            <p style="margin: 0; font-size: 0.9rem; color: var(--of-muted);">Paste a direct audio URL for your own alert sound. Browser autoplay rules still apply, so use the test button after saving.</p>

                            <div class="field" style="margin-top: 1rem;">
                                <label for="settingCustomSoundUrl">Custom sound URL</label>
                                <input type="url" id="settingCustomSoundUrl" placeholder="https://example.com/alert.mp3" style="width: 100%;">
                            </div>

                            <div style="margin-top: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                <button type="button" id="testSoundBtn" class="btn-secondary" style="width: auto;">Test Sound</button>
                                <button type="button" id="testCustomSoundBtn" class="btn-secondary" style="width: auto;">Test Custom Sound</button>
                                <button type="button" id="testDesktopBtn" class="btn-secondary" style="width: auto;">Test Desktop</button>
                            </div>
                        </div>
                    </div>

                    <div id="settingsFeedback" class="feedback" style="margin-top: 1rem;"></div>

                    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--of-line);">
                        <button type="button" id="resetPreferencesBtn" class="btn-secondary" style="width: auto; padding: 10px 20px; font-size: 0.85rem; border-color: rgba(251, 113, 133, 0.3); color: var(--danger);">Reset All Preferences</button>
                    </div>

                    <audio id="notificationSound" preload="auto" aria-hidden="true" style="display: none;"></audio>
                </article>
            </section>
        <?php require_once __DIR__ . '/includes/footer.php'; ?>
