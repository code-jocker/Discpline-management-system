<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class StudentRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private static function selectParentContactFragment(): string
    {
        return \Database::columnExists('students', 'parent_contact') ? 's.parent_contact, ' : '';
    }

    /**
     * ID-card profile columns, detected once per request so paginate() and count()
     * do not repeat the information_schema lookups.
     */
    private static function selectProfileFragment(): string
    {
        static $fragment = null;

        if ($fragment === null) {
            $fragment = '';
            foreach (['date_of_birth', 'address', 'photo_path'] as $column) {
                if (\Database::columnExists('students', $column)) {
                    $fragment .= 's.' . $column . ', ';
                }
            }
        }

        return $fragment;
    }

    public function paginate(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['class_id'])) {
            $where[] = 's.class_id = :class_id';
            $bindings['class_id'] = (int) $filters['class_id'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 's.category_id = :category_id';
            $bindings['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            // Native prepares cannot reuse a named placeholder, so each column gets
            // its own bound copy of the term.
            $where[] = '(s.full_name LIKE :search_name OR s.student_code LIKE :search_code'
                . (ctype_digit($search) ? ' OR s.id = :search_id' : '') . ')';
            $bindings['search_name'] = '%' . $search . '%';
            $bindings['search_code'] = '%' . $search . '%';
            if (ctype_digit($search)) {
                $bindings['search_id'] = (int) $search;
            }
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $statement = $this->db->prepare(
            'SELECT s.id, s.student_code, s.full_name, ' . self::selectParentContactFragment() . self::selectProfileFragment() . 's.class_id, s.category_id,
                    c.name AS class_name, cat.name AS category_name
             FROM students s
             INNER JOIN classes c ON c.id = s.class_id
             INNER JOIN categories cat ON cat.id = s.category_id
             ' . $whereSql . '
             ORDER BY cat.name ASC, c.name ASC, s.full_name ASC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($bindings as $key => $value) {
            $type = is_string($value) ? PDO::PARAM_STR : PDO::PARAM_INT;
            $statement->bindValue(':' . $key, $value, $type);
        }

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function count(array $filters): int
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['class_id'])) {
            $where[] = 'class_id = :class_id';
            $bindings['class_id'] = (int) $filters['class_id'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'category_id = :category_id';
            $bindings['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $where[] = '(full_name LIKE :search_name OR student_code LIKE :search_code'
                . (ctype_digit($search) ? ' OR id = :search_id' : '') . ')';
            $bindings['search_name'] = '%' . $search . '%';
            $bindings['search_code'] = '%' . $search . '%';
            if (ctype_digit($search)) {
                $bindings['search_id'] = (int) $search;
            }
        }

        $sql = 'SELECT COUNT(*) FROM students';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $statement = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $type = is_string($value) ? PDO::PARAM_STR : PDO::PARAM_INT;
            $statement->bindValue(':' . $key, $value, $type);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
