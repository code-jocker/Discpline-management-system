<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

final class Notification
{
    private PDO $db;

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH   = 'high';

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureNotificationSchema();
    }

    private function hasNotificationTable(): bool
    {
        return Database::tableExists('notifications');
    }

    private function timestampSelectable(): string
    {
        if (Database::columnExists('notifications', 'timestamp')) {
            return '`timestamp` AS `timestamp`';
        }

        if (Database::columnExists('notifications', 'created_at')) {
            return 'created_at AS `timestamp`';
        }

        return 'NULL AS `timestamp`';
    }

    private function statusSelectable(): string
    {
        if (Database::columnExists('notifications', 'status')) {
            return 'status';
        }

        if (Database::columnExists('notifications', 'is_read')) {
            return 'CASE WHEN is_read = 1 THEN \'read\' ELSE \'unread\' END AS status';
        }

        return '\'unread\' AS status';
    }

    private function statusCondition(): string
    {
        if (Database::columnExists('notifications', 'status')) {
            return 'status = :status';
        }

        if (Database::columnExists('notifications', 'is_read')) {
            return 'is_read = 0';
        }

        return '1=1'; // Always true if no status column
    }

    public function resolveDeductionRecipients(array $studentSnapshot): array
    {
        $recipientIds = [];

        try {
            $classTeacherId = $this->findClassTeacherId((int) ($studentSnapshot['class_id'] ?? 0));
            if ($classTeacherId !== null) {
                $recipientIds[] = $classTeacherId;
            }
        } catch (Throwable $exception) {
            Database::logError('Class teacher recipient lookup failed', ['exception' => $exception->getMessage()]);
        }

        try {
            $recipientIds = array_merge(
                $recipientIds,
                $this->findUsersByRolePatterns([
                    '%deputy headteacher%discipline%',
                    '%headteacher%',
                    '%discipline%',
                    '%admin%'
                ])
            );
        } catch (Throwable $exception) {
            Database::logError('Role-based recipient lookup failed', ['exception' => $exception->getMessage()]);
        }

        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
        return $recipientIds;
    }

    /**
     * Describe which notifications the user can see.
     *
     * @return array{short_label:string,label:string,description:string,class_names:list<string>,tone:string,icon:string}
     */
    public function describeVisibilityScope(int $userId): array
    {
        if ($this->userCanSeeAllNotifications($userId)) {
            return [
                'short_label' => 'All',
                'label' => 'All notifications',
                'description' => 'You can see system-wide and class notifications.',
                'class_names' => [],
                'tone' => 'all',
                'icon' => '🌐',
            ];
        }

        $classNames = $this->findAssignedClassNames($userId);
        if ($classNames !== []) {
            return [
                'short_label' => 'Class',
                'label' => 'Class notifications only',
                'description' => sprintf('Showing notifications for %s.', implode(', ', $classNames)),
                'class_names' => $classNames,
                'tone' => 'class',
                'icon' => '🏫',
            ];
        }

        return [
            'short_label' => 'General',
            'label' => 'General notifications only',
            'description' => 'Showing school-wide notifications that are not tied to a class.',
            'class_names' => [],
            'tone' => 'general',
            'icon' => '🔔',
        ];
    }

    public function createBulk(array $userIds, string $message, string $priority = self::PRIORITY_LOW, ?int $classId = null): void
    {
        if ($userIds === [] || !$this->hasNotificationTable()) {
            return;
        }

        $columns = ['user_id', 'message'];
        $placeholders = [':user_id', ':message'];

        if (Database::columnExists('notifications', 'status')) {
            $columns[] = 'status';
            $placeholders[] = ':status';
        } elseif (Database::columnExists('notifications', 'is_read')) {
            $columns[] = 'is_read';
            $placeholders[] = ':is_read';
        }

        if (Database::columnExists('notifications', 'priority')) {
            $columns[] = 'priority';
            $placeholders[] = ':priority';
        }

        if ($classId !== null && Database::columnExists('notifications', 'class_id')) {
            $columns[] = 'class_id';
            $placeholders[] = ':class_id';
        }

        $timestampColumn = Database::columnExists('notifications', 'timestamp') ? 'timestamp' : (Database::columnExists('notifications', 'created_at') ? 'created_at' : null);
        if ($timestampColumn !== null) {
            $columns[] = $timestampColumn;
            $placeholders[] = 'NOW()';
        }

        $transactionOwner = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $transactionOwner = true;
        }

        try {
            $statement = $this->db->prepare(
                'INSERT INTO notifications (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            );

            foreach ($userIds as $userId) {
                $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $statement->bindValue(':message', $message, PDO::PARAM_STR);

                if (Database::columnExists('notifications', 'status')) {
                    $statement->bindValue(':status', 'unread', PDO::PARAM_STR);
                } elseif (Database::columnExists('notifications', 'is_read')) {
                    $statement->bindValue(':is_read', 0, PDO::PARAM_INT);
                }

                if (Database::columnExists('notifications', 'priority')) {
                    $statement->bindValue(':priority', $priority, PDO::PARAM_STR);
                }

                if ($classId !== null && Database::columnExists('notifications', 'class_id')) {
                    $statement->bindValue(':class_id', $classId, PDO::PARAM_INT);
                }

                $statement->execute();
            }

            if ($transactionOwner) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($transactionOwner && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function fetchAll(int $userId, int $limit = 50, int $offset = 0): array
    {
        if (!$this->hasNotificationTable()) {
            return [];
        }

        [$visibilitySql, $visibilityBindings] = $this->buildVisibilityScope((int) $userId);
        $statement = $this->db->prepare(
            'SELECT id, message, ' . $this->statusSelectable() . ', ' . $this->timestampSelectable() . '
             FROM notifications
             WHERE user_id = :user_id AND ' . $visibilitySql . '
             ORDER BY `timestamp` DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($visibilityBindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }

        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchUnread(int $userId, int $limit = 10): array
    {
        if (!$this->hasNotificationTable()) {
            return [];
        }

        [$visibilitySql, $visibilityBindings] = $this->buildVisibilityScope((int) $userId);
        $statement = $this->db->prepare(
            'SELECT id, message, ' . $this->statusSelectable() . ', ' . $this->timestampSelectable() . '
             FROM notifications
             WHERE user_id = :user_id AND ' . $this->statusCondition() . ' AND ' . $visibilitySql . '
             ORDER BY `timestamp` DESC
             LIMIT :limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach ($visibilityBindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }

        if (Database::columnExists('notifications', 'status')) {
            $statement->bindValue(':status', 'unread', PDO::PARAM_STR);
        }

        $statement->execute();

        return $statement->fetchAll();
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        if (!$this->hasNotificationTable()) {
            return false;
        }

        if (Database::columnExists('notifications', 'status')) {
            $statement = $this->db->prepare(
                'UPDATE notifications SET status = "read" WHERE id = :id AND user_id = :user_id'
            );
        } elseif (Database::columnExists('notifications', 'is_read')) {
            $statement = $this->db->prepare(
                'UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id'
            );
        } else {
            return false; // No status column to update
        }

        return $statement->execute([
            'id' => $notificationId,
            'user_id' => $userId,
        ]);
    }

    public function markAllAsRead(int $userId): bool
    {
        if (!$this->hasNotificationTable()) {
            return false;
        }

        [$visibilitySql, $visibilityBindings] = $this->buildVisibilityScope((int) $userId);
        if (Database::columnExists('notifications', 'status')) {
            $statement = $this->db->prepare(
                'UPDATE notifications SET status = "read" WHERE user_id = :user_id AND status = "unread" AND ' . $visibilitySql
            );
        } elseif (Database::columnExists('notifications', 'is_read')) {
            $statement = $this->db->prepare(
                'UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0 AND ' . $visibilitySql
            );
        } else {
            return false; // No status column to update
        }

        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        foreach ($visibilityBindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }

        return $statement->execute();
    }

    public function delete(int $notificationId, int $userId): bool
    {
        if (!$this->hasNotificationTable()) {
            return false;
        }

        $statement = $this->db->prepare(
            'DELETE FROM notifications WHERE id = :id AND user_id = :user_id'
        );

        return $statement->execute([
            'id' => $notificationId,
            'user_id' => $userId,
        ]);
    }

    public function deleteAll(int $userId): bool
    {
        if (!$this->hasNotificationTable()) {
            return false;
        }

        $statement = $this->db->prepare(
            'DELETE FROM notifications WHERE user_id = :user_id'
        );

        return $statement->execute([
            'user_id' => $userId,
        ]);
    }

    /**
     * Groups a flat array of notifications into date-based categories.
     */
    public function formatGrouped(array $notifications): array
    {
        $grouped = [
            'Today'     => [],
            'Yesterday' => [],
            'Older'     => []
        ];

        $todayDate     = date('Y-m-d');
        $yesterdayDate = date('Y-m-d', strtotime('-1 day'));

        foreach ($notifications as $notif) {
            $timestamp = (string) ($notif['timestamp'] ?? $notif['created_at'] ?? '');
            $notifDate = $timestamp !== '' ? date('Y-m-d', strtotime($timestamp)) : '';

            if ($notifDate === $todayDate) {
                $grouped['Today'][] = $notif;
            } elseif ($notifDate === $yesterdayDate) {
                $grouped['Yesterday'][] = $notif;
            } else {
                $grouped['Older'][] = $notif;
            }
        }

        return array_filter($grouped);
    }

    public function countUnread(int $userId): int
    {
        if (!$this->hasNotificationTable()) {
            return 0;
        }

        [$visibilitySql, $visibilityBindings] = $this->buildVisibilityScope((int) $userId);
        if (Database::columnExists('notifications', 'status')) {
            $statement = $this->db->prepare(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND status = "unread" AND ' . $visibilitySql
            );
        } elseif (Database::columnExists('notifications', 'is_read')) {
            $statement = $this->db->prepare(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0 AND ' . $visibilitySql
            );
        } else {
            return 0; // No status column, assume all are read
        }

        foreach ($visibilityBindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function ensureNotificationSchema(): void
    {
        if (!$this->hasNotificationTable()) {
            return;
        }

        if (!Database::columnExists('notifications', 'class_id')) {
            $afterColumn = Database::columnExists('notifications', 'tenant_id') ? 'tenant_id' : 'user_id';
            $this->db->exec(
                'ALTER TABLE notifications
                 ADD COLUMN class_id INT UNSIGNED DEFAULT NULL AFTER ' . $afterColumn
            );
        }

        if (!Database::columnExists('notifications', 'class_id')) {
            return;
        }

        try {
            $indexName = Database::columnExists('notifications', 'tenant_id')
                ? 'idx_notifications_class'
                : 'idx_notifications_class';
            $statement = $this->db->query("SHOW INDEX FROM notifications WHERE Key_name = '{$indexName}'");
            if ($statement->fetch() === false) {
                $this->db->exec('CREATE INDEX idx_notifications_class ON notifications (class_id)');
            }
        } catch (Throwable $exception) {
            // Index creation is best-effort.
        }
    }

    /**
     * Build the notification visibility scope for the authenticated user.
     *
     * Privileged roles can see all notifications.
     * Class teachers see only notifications for their assigned class(es).
     * Everyone else sees only general notifications.
     *
     * @return array{0:string,1:array<string,int>}
     */
    private function buildVisibilityScope(int $userId): array
    {
        if ($this->userCanSeeAllNotifications($userId)) {
            return ['1=1', []];
        }

        $classIds = $this->findAssignedClassIds($userId);
        if ($classIds !== []) {
            $placeholders = [];
            $bindings = [];

            foreach (array_values($classIds) as $index => $classId) {
                $key = 'class_id_' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $classId;
            }

            return [
                'class_id IN (' . implode(', ', $placeholders) . ')',
                $bindings,
            ];
        }

        return ['class_id IS NULL', []];
    }

    private function userCanSeeAllNotifications(int $userId): bool
    {
        $roleName = strtolower((string) $this->fetchUserRoleName($userId));
        if ($roleName === '') {
            return false;
        }

        $configured = getenv('SBMS_PRIVILEGED_ROLE_NAMES') ?: 'admin,headteacher,it,deputy headteacher discipline,deputy headteacher in charge of discipline';
        $roles = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($configured))))));

        return in_array($roleName, $roles, true);
    }

    private function fetchUserRoleName(int $userId): string
    {
        if (!Database::tableExists('users') || !Database::tableExists('roles')) {
            return '';
        }

        $statement = $this->db->prepare(
            'SELECT r.name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $roleName = $statement->fetchColumn();

        return $roleName !== false && $roleName !== null ? (string) $roleName : '';
    }

    /**
     * @return list<int>
     */
    private function findAssignedClassIds(int $userId): array
    {
        if (!Database::tableExists('classes') || !Database::columnExists('classes', 'class_teacher_user_id')) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT id
             FROM classes
             WHERE class_teacher_user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @return list<string>
     */
    private function findAssignedClassNames(int $userId): array
    {
        if (!Database::tableExists('classes') || !Database::columnExists('classes', 'class_teacher_user_id')) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT name
             FROM classes
             WHERE class_teacher_user_id = :user_id
             ORDER BY name ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function findClassTeacherId(int $classId): ?int
    {
        $column = getenv('SBMS_CLASS_TEACHER_COLUMN') ?: 'class_teacher_user_id';
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            $column = 'class_teacher_user_id';
        }

        $sql = sprintf('SELECT %s FROM classes WHERE id = :class_id LIMIT 1', $column);
        $statement = $this->db->prepare($sql);
        $statement->execute(['class_id' => $classId]);
        $userId = $statement->fetchColumn();

        return $userId === false || $userId === null ? null : (int) $userId;
    }

    public function findUsersByRolePatterns(array $patterns): array
    {
        if ($patterns === []) {
            return [];
        }

        $clauses = [];
        $bindings = [];

        foreach ($patterns as $index => $pattern) {
            $key = 'pattern_' . $index;
            $clauses[] = 'LOWER(r.name) LIKE :' . $key;
            $bindings[$key] = strtolower($pattern);
        }

        $sql = sprintf(
            'SELECT DISTINCT u.id
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE %s',
            implode(' OR ', $clauses)
        );

        $statement = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        return array_map('intval', array_column($statement->fetchAll(), 'id'));
    }
}
