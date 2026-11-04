<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/UserController.php';
require_once dirname(__DIR__) . '/models/plexus.php';

require_auth();

$controller = new UserController();
$authUser = current_auth_user();

if (!$controller->canManageUsers($authUser)) {
    http_response_code(403);
    exit('Unauthorized.');
}

$initialData = $controller->indexData();
$roles = $initialData['roles'] ?? [];

?>
<?php
$pageTitle = 'SBMS User Management';
$bodyClass = 'students-body sbms-plexus-body';
$mainClass = 'students-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/student-management.css'];
$viteFooterAssets = ['assets/js/theme.js', 'assets/js/user-management.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
            <section class="hero-card">
            <div class="hero-copy">
                <p class="eyebrow">Admin Area</p>
                <h1>User Management</h1>
                <p class="hero-text">
                    Create and manage staff accounts, teachers, and assign roles.
                </p>
            </div>
            <div class="hero-actions">
                <button type="button" class="primary-btn" id="openUserModal">Add User</button>
            </div>
        </section>

        <section class="dashboard-grid">
            <article class="table-card" style="grid-column: span 12;">
                <div class="section-heading table-header">
                    <div>
                        <p class="eyebrow">Users</p>
                        <h2>Directory</h2>
                    </div>
                    <div id="tableMeta" class="table-meta">Loading users...</div>
                </div>

                <div id="tableFeedback" class="table-feedback" role="alert" aria-live="polite"></div>

                <div class="table-wrap">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Assigned classes</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <tr>
                                <td colspan="7" class="table-placeholder">Loading users...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar">
                    <button type="button" class="ghost-btn" id="prevPage">Previous</button>
                    <span id="pageIndicator">Page 1</span>
                    <button type="button" class="ghost-btn" id="nextPage">Next</button>
                </div>
            </article>
        </section>

        <div class="modal-backdrop hidden" id="userModal">
            <div class="modal-panel">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">User Form</p>
                        <h2 id="modalTitle">Add a user</h2>
                    </div>
                    <button type="button" class="icon-btn" id="closeUserModal" aria-label="Close modal">&times;</button>
                </div>

                <form id="userForm" class="student-form" method="post" novalidate>
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" id="userId">

                    <div class="form-grid">
                        <div class="field">
                            <label for="fullName">Full name</label>
                            <input type="text" id="fullName" name="full_name" maxlength="120" required>
                        </div>

                        <div class="field">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" maxlength="60" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" maxlength="120" required>
                        </div>

                        <div class="field">
                            <label for="roleId">Role</label>
                            <select id="roleId" name="role_id" required>
                                <option value="">Select role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= e((string) $role['id']) ?>"><?= e($role['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label for="password">Password <span id="passwordHint">(Required)</span></label>
                        <input type="password" id="password" name="password" minlength="8" required>
                    </div>

                    <div id="formFeedback" class="form-feedback" role="alert" aria-live="polite"></div>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" id="cancelUserModal">Cancel</button>
                        <button type="submit" class="primary-btn" id="userSubmitButton">
                            <span class="btn-label">Save User</span>
                            <span class="btn-loader" hidden></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            window.SBMSUserConfig = {
                fetchUrl: '<?= e(app_base_url()) ?>/users/fetch.php',
                createUrl: '<?= e(app_base_url()) ?>/users/create.php',
                updateUrl: '<?= e(app_base_url()) ?>/users/update.php',
                deleteUrl: '<?= e(app_base_url()) ?>/users/delete.php',
                csrfToken: '<?= e(csrf_token()) ?>'
            };
        </script>
        <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
