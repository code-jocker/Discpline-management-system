<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/session.php'; // For json_response
require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/Settings.php'; // Added for Settings model

require_auth();

try {
    $db = Database::connection();
    $settingsModel = new Settings($db);
    $selectedSchoolYear = trim((string) $settingsModel->selectedSchoolYear());

    $defaultTerm = $settingsModel->currentTerm();
    $selectedTerm = $defaultTerm;
    if (isset($_GET['term'])) {
        $requestedTerm = SchoolYear::normalizeTerm($_GET['term']);
        $selectedTerm = $requestedTerm;
    }

    // 1. Risk Distribution (Data for the pie/doughnut chart)
    // Use the actual stored risk values when available and keep output stable across legacy/new schema variants.
    $riskData = [
        ['risk_level' => 'Critical', 'count' => 0],
        ['risk_level' => 'Warning', 'count' => 0],
        ['risk_level' => 'Excellent', 'count' => 0],
        ['risk_level' => 'High Risk', 'count' => 0],
        ['risk_level' => 'Medium Risk', 'count' => 0],
        ['risk_level' => 'Low Risk', 'count' => 0],
    ];

    if (Database::tableExists('student_risk_profiles')) {
        try {
            $riskSql = 'SELECT risk_level, COUNT(*) AS count FROM student_risk_profiles';
            $riskBindings = [];
            if (Database::columnExists('student_risk_profiles', 'school_year')) {
                $riskSql .= ' WHERE school_year = :school_year';
                $riskBindings['school_year'] = $selectedSchoolYear;
            }
            $riskSql .= ' GROUP BY risk_level';

            $riskStmt = $db->prepare($riskSql);
            foreach ($riskBindings as $key => $value) {
                $riskStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
            }
            $riskStmt->execute();
            $riskRows = $riskStmt->fetchAll();
            foreach ($riskRows as $row) {
                $riskKey = trim((string) ($row['risk_level'] ?? ''));
                if ($riskKey === '') {
                    continue;
                }

                $normalized = strtolower($riskKey);
                if (str_contains($normalized, 'critical')) {
                    $riskData[0]['count'] = (int) $row['count'];
                } elseif (str_contains($normalized, 'warning') || str_contains($normalized, 'medium')) {
                    $riskData[1]['count'] = (int) $row['count'];
                } elseif (str_contains($normalized, 'excellent') || str_contains($normalized, 'low')) {
                    $riskData[2]['count'] = (int) $row['count'];
                } elseif (str_contains($normalized, 'high')) {
                    $riskData[3]['count'] = (int) $row['count'];
                } elseif (str_contains($normalized, 'medium')) {
                    $riskData[4]['count'] = (int) $row['count'];
                } elseif (str_contains($normalized, 'low')) {
                    $riskData[5]['count'] = (int) $row['count'];
                }
            }
        } catch (Throwable $e) {
            $riskData = [
                ['risk_level' => 'Critical', 'count' => 0],
                ['risk_level' => 'Warning', 'count' => 0],
                ['risk_level' => 'Excellent', 'count' => 0],
            ];
        }
    } elseif (Database::tableExists('student_risk') && Database::tableExists('risk_levels') && Database::columnExists('student_risk', 'risk_level_id')) {
        $riskLabelColumn = 'name';
        if (Database::columnExists('risk_levels', 'level_name')) {
            $riskLabelColumn = 'level_name';
        }

        if (Database::columnExists('risk_levels', $riskLabelColumn)) {
            try {
                $riskStmt = $db->prepare(
                    sprintf(
                        'SELECT rl.%s AS risk_level, COUNT(sr.id) AS count
                         FROM risk_levels rl
                         LEFT JOIN student_risk sr ON sr.risk_level_id = rl.id
                         GROUP BY rl.id, rl.%s
                         ORDER BY FIELD(LOWER(rl.%s), \'critical\', \'warning\', \'excellent\', \'high risk\', \'medium risk\', \'low risk\')',
                        $riskLabelColumn,
                        $riskLabelColumn,
                        $riskLabelColumn
                    )
                );
                $riskStmt->execute();
                $riskRows = $riskStmt->fetchAll();
                foreach ($riskRows as $row) {
                    $riskKey = trim((string) ($row['risk_level'] ?? ''));
                    if ($riskKey === '') {
                        continue;
                    }
                    $normalized = strtolower($riskKey);
                    if (str_contains($normalized, 'critical')) {
                        $riskData[0]['count'] = (int) $row['count'];
                    } elseif (str_contains($normalized, 'warning')) {
                        $riskData[1]['count'] = (int) $row['count'];
                    } elseif (str_contains($normalized, 'excellent')) {
                        $riskData[2]['count'] = (int) $row['count'];
                    } elseif (str_contains($normalized, 'high')) {
                        $riskData[3]['count'] = (int) $row['count'];
                    } elseif (str_contains($normalized, 'medium')) {
                        $riskData[4]['count'] = (int) $row['count'];
                    } elseif (str_contains($normalized, 'low')) {
                        $riskData[5]['count'] = (int) $row['count'];
                    }
                }
            } catch (Throwable $e) {
                $riskData = [
                    ['risk_level' => 'Critical', 'count' => 0],
                    ['risk_level' => 'Warning', 'count' => 0],
                    ['risk_level' => 'Excellent', 'count' => 0],
                ];
            }
        }
    }

    // 2. Class Comparison (Data for the bar chart)
    // Calculating average marks per class using the available grading schema.
    $termFilter = '';
    $parameters = [];

    if (Database::tableExists('student_marks')) {
        $whereClauses = [];
        if (Database::columnExists('student_marks', 'term')) {
            $whereClauses[] = 'm.term = :term_id';
            $parameters['term_id'] = $selectedTerm;
        }
        if (Database::columnExists('student_marks', 'school_year')) {
            $whereClauses[] = 'm.school_year = :school_year';
            $parameters['school_year'] = $selectedSchoolYear;
        }
        if ($whereClauses !== []) {
            $termFilter = ' WHERE ' . implode(' AND ', $whereClauses);
        }
    }

    $marksColumn = 'behavior_score';
    if (Database::columnExists('student_marks', 'marks')) {
        $marksColumn = 'marks';
    }

    try {
        $classAvgSql = 'SELECT c.name as class_name, AVG(m.' . $marksColumn . ') as avg_marks
             FROM classes c
             INNER JOIN students s ON s.class_id = c.id
             INNER JOIN student_marks m ON m.student_id = s.id'
            . $termFilter . '
             GROUP BY c.id
             ORDER BY avg_marks ASC
             LIMIT 10';
        $classAvgStmt = $db->prepare($classAvgSql);
        $classAvgStmt->execute($parameters);
        $classAverages = $classAvgStmt->fetchAll();
    } catch (Throwable $e) {
        $classAverages = [];
    }

    // 3. Top Offenders (Data for the signals list)
    // Prefer deduction history when available, otherwise fall back to the stored risk profiles or marks data.
    try {
        $topOffenders = [];

        if (Database::tableExists('deductions_history')) {
            $where = [];
            $bindings = [];
            if (Database::columnExists('deductions_history', 'term')) {
                $where[] = 'dh.term = :term';
                $bindings['term'] = $selectedTerm;
            }
            if (Database::columnExists('deductions_history', 'school_year')) {
                $where[] = 'dh.school_year = :school_year';
                $bindings['school_year'] = $selectedSchoolYear;
            }

            $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
            $offendersStmt = $db->prepare(
                'SELECT s.id, s.full_name, s.student_code, c.name AS class_name,
                        COALESCE(SUM(CASE WHEN dh.marks_deducted > 0 THEN dh.marks_deducted ELSE 0 END), 0) AS total_deducted,
                        COUNT(CASE WHEN dh.marks_deducted > 0 THEN dh.id END) AS incidents
                 FROM students s
                 LEFT JOIN deductions_history dh ON dh.student_id = s.id
                 LEFT JOIN classes c ON c.id = s.class_id
                 ' . $whereSql . '
                 GROUP BY s.id, s.full_name, s.student_code, c.name
                 HAVING COALESCE(SUM(CASE WHEN dh.marks_deducted > 0 THEN dh.marks_deducted ELSE 0 END), 0) > 0
                 ORDER BY total_deducted DESC, incidents DESC, s.full_name ASC
                 LIMIT 5'
            );
            foreach ($bindings as $key => $value) {
                $offendersStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $offendersStmt->execute();
            $topOffenders = $offendersStmt->fetchAll();
        }

        if ($topOffenders === [] && Database::tableExists('student_risk_profiles')) {
            $riskWhere = [];
            $riskBindings = [];
            if (Database::columnExists('student_risk_profiles', 'risk_level')) {
                $riskWhere[] = 'LOWER(sr.risk_level) IN (\'critical\', \'high risk\', \'warning\', \'medium risk\')';
            }
            if (Database::columnExists('student_risk_profiles', 'school_year')) {
                $riskWhere[] = 'sr.school_year = :school_year';
                $riskBindings['school_year'] = $selectedSchoolYear;
            }
            $riskWhereSql = $riskWhere !== [] ? 'WHERE ' . implode(' AND ', $riskWhere) : '';

            $riskOffendersStmt = $db->prepare(
                'SELECT s.id, s.full_name, s.student_code, c.name AS class_name,
                        COALESCE(sr.total_marks_deducted, 0) AS total_deducted,
                        COALESCE(sr.incidents_count, 0) AS incidents
                 FROM student_risk_profiles sr
                 INNER JOIN students s ON s.id = sr.student_id
                 LEFT JOIN classes c ON c.id = s.class_id
                 ' . $riskWhereSql . '
                 ORDER BY total_deducted DESC, incidents DESC, s.full_name ASC
                 LIMIT 5'
            );
            foreach ($riskBindings as $key => $value) {
                if ($key === 'school_year') {
                    $riskOffendersStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
                    continue;
                }
                $riskOffendersStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $riskOffendersStmt->execute();
            $topOffenders = $riskOffendersStmt->fetchAll();
        }

        if ($topOffenders === [] && Database::tableExists('student_marks')) {
            $marksColumn = 'behavior_score';
            if (Database::columnExists('student_marks', 'marks')) {
                $marksColumn = 'marks';
            }
            $marksWhere = [];
            $marksBindings = [];
            if (Database::columnExists('student_marks', 'term')) {
                $marksWhere[] = 'm.term = :term';
                $marksBindings['term'] = $selectedTerm;
            }
            if (Database::columnExists('student_marks', 'school_year')) {
                $marksWhere[] = 'm.school_year = :school_year';
                $marksBindings['school_year'] = $selectedSchoolYear;
            }
            $marksWhereSql = $marksWhere !== [] ? 'WHERE ' . implode(' AND ', $marksWhere) : '';

            $marksOffendersStmt = $db->prepare(
                'SELECT s.id, s.full_name, s.student_code, c.name AS class_name,
                        COALESCE(SUM(GREATEST(0, 40 - m.' . $marksColumn . ')), 0) AS total_deducted,
                        COUNT(CASE WHEN m.' . $marksColumn . ' < 40 THEN m.id END) AS incidents
                 FROM students s
                 INNER JOIN student_marks m ON m.student_id = s.id
                 LEFT JOIN classes c ON c.id = s.class_id
                 ' . $marksWhereSql . '
                 GROUP BY s.id, s.full_name, s.student_code, c.name
                 HAVING COALESCE(SUM(GREATEST(0, 40 - m.' . $marksColumn . ')), 0) > 0
                 ORDER BY total_deducted DESC, incidents DESC, s.full_name ASC
                 LIMIT 5'
            );
            foreach ($marksBindings as $key => $value) {
                if ($key === 'school_year') {
                    $marksOffendersStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
                    continue;
                }
                $marksOffendersStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $marksOffendersStmt->execute();
            $topOffenders = $marksOffendersStmt->fetchAll();
        }
    } catch (Throwable $e) {
        $topOffenders = [];
    }

    // 4. Summary Stats (For top cards)
    $summaryStats = [
        'students' => (int) $db->query('SELECT COUNT(*) FROM students')->fetchColumn(),
        'classes' => (int) $db->query('SELECT COUNT(*) FROM classes')->fetchColumn(),
        'roles' => Database::tableExists('categories')
            ? (int) $db->query('SELECT COUNT(*) FROM categories')->fetchColumn()
            : (int) $db->query('SELECT COUNT(*) FROM roles')->fetchColumn(),
        'incidents' => 0,
        'high_risk' => 0
    ];

    if (Database::tableExists('deductions_history')) {
        $incidentWhere = ['term = :term'];
        $incidentParams = ['term' => $selectedTerm];
        if (Database::columnExists('deductions_history', 'school_year')) {
            $incidentWhere[] = 'school_year = :school_year';
            $incidentParams['school_year'] = $selectedSchoolYear;
        }

        $incidentSql = 'SELECT COUNT(*) FROM deductions_history WHERE ' . implode(' AND ', $incidentWhere);
        $statement = $db->prepare($incidentSql);
        $statement->execute($incidentParams);
        $summaryStats['incidents'] = (int) $statement->fetchColumn();
    }

    if (Database::tableExists('student_risk') && Database::tableExists('risk_levels') && Database::columnExists('student_risk', 'risk_level_id')) {
        $riskLabelColumn = 'level_name';
        if (Database::columnExists('risk_levels', 'name')) {
            $riskLabelColumn = 'name';
        } elseif (Database::columnExists('risk_levels', 'risk_level')) {
            $riskLabelColumn = 'risk_level';
        }
        if (Database::columnExists('risk_levels', $riskLabelColumn)) {
            $riskWhere = ['LOWER(rl.' . $riskLabelColumn . ') IN (\'critical\', \'high risk\')'];
            $riskParams = [];
            if (Database::columnExists('student_risk', 'school_year')) {
                $riskWhere[] = 'sr.school_year = :school_year';
                $riskParams['school_year'] = $selectedSchoolYear;
            }

            $statement = $db->prepare(
                sprintf(
                    'SELECT COUNT(sr.id) FROM risk_levels rl LEFT JOIN student_risk sr ON sr.risk_level_id = rl.id WHERE %s',
                    implode(' AND ', $riskWhere)
                )
            );
            $statement->execute($riskParams);
            $summaryStats['high_risk'] = (int) $statement->fetchColumn();
        }
    } elseif (Database::tableExists('student_risk_profiles')) {
        $riskWhere = ['LOWER(risk_level) IN (\'critical\', \'high risk\')'];
        $riskParams = [];
        if (Database::columnExists('student_risk_profiles', 'school_year')) {
            $riskWhere[] = 'school_year = :school_year';
            $riskParams['school_year'] = $selectedSchoolYear;
        }

        $statement = $db->prepare('SELECT COUNT(*) FROM student_risk_profiles WHERE ' . implode(' AND ', $riskWhere));
        $statement->execute($riskParams);
        $summaryStats['high_risk'] = (int) $statement->fetchColumn();
    }

    // Format data specifically for Chart.js frontend consumption
    json_response([
        'status' => 'success',
        'data' => [
            'selected_term' => $selectedTerm,
            'selected_school_year' => $selectedSchoolYear,
            'summary_stats' => $summaryStats,
            'risk_distribution' => [
                'labels' => array_column($riskData, 'risk_level'),
                'datasets' => [[
                    'data' => array_map('intval', array_column($riskData, 'count')),
                    'backgroundColor' => ['#ef4444', '#f59e0b', '#10b981'] // Red for Critical, Orange for Warning, Green for Excellent
                ]]
            ],
            'class_averages' => [
                'labels' => array_column($classAverages, 'class_name'),
                'datasets' => [[
                    'label' => 'Avg Marks (Term ' . $selectedTerm . ')',
                    'data' => array_map(fn($v) => round((float)$v, 1), array_column($classAverages, 'avg_marks')),
                    'backgroundColor' => '#38bdf8'
                ]]
            ],
            'top_offenders' => array_map(function($row) {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => $row['full_name'],
                    'code' => $row['student_code'],
                    'impact' => (int)$row['total_deducted'],
                    'incidents' => (int)$row['incidents'],
                    'class_name' => $row['class_name'] ?? null
                ];
            }, $topOffenders)
        ]
    ]);

} catch (Throwable $exception) {
    Database::logError('Metrics API failed', ['error' => $exception->getMessage()]);
    json_response([
        'status' => 'error',
        'message' => 'Unable to retrieve analytics data.'
    ], 500);
}
