<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * ReportService — generates HTML reports suitable for browser-printing to PDF.
 *
 * DomPDF note: install via Composer (`composer require dompdf/dompdf`) and
 * replace renderHtmlReport() → DomPDF render for server-side PDF generation.
 */
final class ReportService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function selectStudentParentContact(): string
    {
        return \Database::columnExists('students', 'parent_contact') ? 's.parent_contact' : '';
    }

    private function selectDeductionTimestamp(): string
    {
        if (\Database::columnExists('deductions_history', 'timestamp')) {
            return 'dh.`timestamp` AS `timestamp`';
        }

        if (\Database::columnExists('deductions_history', 'created_at')) {
            return 'dh.created_at AS `timestamp`';
        }

        return 'NULL AS `timestamp`';
    }

    /**
     * Generate a full HTML student report.
     *
     * @return string Complete HTML document, or null if student not found.
     */
    public function generateStudentReport(int $studentId): ?string
    {
        $student = $this->fetchStudent($studentId);
        if (!$student) {
            return null;
        }

        $marks   = $this->fetchMarks($studentId);
        $history = $this->fetchHistory($studentId);
        $risk    = $this->fetchRisk($studentId);

        return $this->renderHtmlReport($student, $marks, $history, $risk);
    }

    // -------------------------------------------------------------------------
    // Data fetchers
    // -------------------------------------------------------------------------

    private function fetchStudent(int $studentId): ?array
    {
        $selectFragment = $this->selectStudentParentContact();
        $selectColumns = 's.id, s.student_code, s.full_name';
        if ($selectFragment !== '') {
            $selectColumns .= ', ' . $selectFragment;
        }

        $stmt = $this->db->prepare(
            'SELECT ' . $selectColumns . ',
                    c.name AS class_name, cat.name AS category_name, s.created_at
             FROM   students s
             INNER JOIN classes    c   ON c.id   = s.class_id
             INNER JOIN categories cat ON cat.id = s.category_id
             WHERE  s.id = :id
             LIMIT  1'
        );
        $stmt->execute(['id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function fetchMarks(int $studentId): array
    {
        $settings = new \Settings($this->db);
        $bindings = ['id' => $studentId];

        if (\Database::columnExists('student_marks', 'school_year')) {
            $sql = 'SELECT term, marks FROM student_marks WHERE student_id = :id AND school_year = :school_year ORDER BY term ASC';
            $bindings['school_year'] = $settings->selectedSchoolYear();
        } else {
            $sql = 'SELECT term, marks FROM student_marks WHERE student_id = :id ORDER BY term ASC';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    private function fetchHistory(int $studentId): array
    {
        $timestampOrder = \Database::columnExists('deductions_history', 'timestamp')
            ? 'dh.`timestamp`'
            : (\Database::columnExists('deductions_history', 'created_at') ? 'dh.created_at' : 'NULL');

        $stmt = $this->db->prepare(
            'SELECT dh.term, dh.marks_deducted, dh.reason, ' . $this->selectDeductionTimestamp() . ',
                    COALESCE(u.full_name, "System") AS by_name
             FROM   deductions_history dh
             LEFT JOIN users u ON u.id = dh.performed_by
             WHERE  dh.student_id = :id
             ORDER  BY ' . $timestampOrder . ' DESC
             LIMIT  25'
        );
        $stmt->execute(['id' => $studentId]);
        return $stmt->fetchAll();
    }

    private function fetchRisk(int $studentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT risk_level, incidents_count, total_marks_deducted,
                    avg_deduction_per_week, predicted_at
             FROM   student_risk_profiles
             WHERE  student_id = :id
             LIMIT  1'
        );
        $stmt->execute(['id' => $studentId]);
        return $stmt->fetch() ?: [];
    }

    private function buildDisciplinaryHistoryText(array $history): string
    {
        if ($history === []) {
            return 'No prior disciplinary incidents recorded.';
        }

        $lines = [];
        foreach ($history as $entry) {
            $term = (int) ($entry['term'] ?? 1);
            $marks = (int) ($entry['marks_deducted'] ?? 0);
            $reason = trim((string) ($entry['reason'] ?? ''));
            $timestamp = (string) ($entry['timestamp'] ?? '');
            $formattedDate = $timestamp !== '' ? date('d M Y', strtotime($timestamp) ?: time()) : date('d M Y');
            $actionLabel = $marks > 0
                ? sprintf('Deducted %d mark(s)', $marks)
                : sprintf('Rewarded %d mark(s)', abs($marks));
            $reasonText = $reason !== '' ? $reason : 'No reason provided';
            $lines[] = sprintf('- %s (Term %d): %s. Action: %s.', $formattedDate, $term, $reasonText, $actionLabel);
        }

        return implode(PHP_EOL, $lines);
    }

    // -------------------------------------------------------------------------
    // HTML rendering
    // -------------------------------------------------------------------------

    private function renderHtmlReport(array $student, array $marks, array $history, array $risk): string
    {
        $h    = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $riskLevel = $risk['risk_level'] ?? 'Excellent';
        $riskClass = strtolower($riskLevel);
        $totalMarks = array_sum(array_column($marks, 'marks'));
        $maxMarks   = count($marks) * 40;
        $date       = date('d M Y, H:i T');
        $reportId   = 'RPT-' . $student['id'] . '-' . date('Ymd');
        $settings = new \Settings($this->db);
        $threshold = max(0, min(40, (int) $settings->get('low_marks_threshold', '20')));
        $letterEnabled = (string) $settings->get('low_marks_letter_enabled', '1') === '1';
        $showLowMarksLetter = $letterEnabled && count($marks) > 0 && min(array_column($marks, 'marks')) < $threshold;

        $template = trim((string) $settings->get('low_marks_letter_template', "Dear Parent/Guardian,\n\nThis letter is being issued by the school regarding the student's conduct and progress. The current score is {{score}} out of 40, below the threshold of {{threshold}}. The student has the following disciplinary record:\n\n{{disciplinary_history}}\n\nWe kindly request your presence so that we can discuss the situation and agree on appropriate support.\n\nThank you for your cooperation.\n\nSincerely,\nSchool Administration"));
        $letterBody = $template;
        if ($showLowMarksLetter) {
            $disciplinaryHistory = $this->buildDisciplinaryHistoryText($history);
            $letterBody = strtr($template, [
                '{{student_name}}' => '',
                '{{student_full_name}}' => '',
                '{{student_code}}' => '',
                '{{class_name}}' => '',
                '{{school_name}}' => trim((string) $settings->get('school_name', 'School')),
                '{{threshold}}' => (string) $threshold,
                '{{score}}' => (string) min(array_column($marks, 'marks')),
                '{{date}}' => date('d M Y'),
                '{{disciplinary_history}}' => $disciplinaryHistory,
            ]);
        }

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Student Report – <?= $h($student['full_name']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Inter,sans-serif;background:#f4f6f9;color:#1a2332;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.wrap{max-width:860px;margin:0 auto;background:#fff;padding:48px;min-height:100vh}
.rpt-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:28px;border-bottom:2px solid #e5e9ef;margin-bottom:28px}
.brand{font-size:1.7rem;font-weight:800;letter-spacing:.06em;color:#1a2332}.brand span{color:#22c55e}
.rpt-meta{text-align:right}.rpt-meta h1{font-size:1.25rem;font-weight:700}.rpt-meta p{color:#6b7280;font-size:.88rem;margin-top:4px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.card{padding:20px;border-radius:12px;border:1px solid #e5e9ef}
.card-title{font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;margin-bottom:12px;font-weight:600}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f3f4f6;font-size:.9rem}
.info-row:last-child{border-bottom:none}
.info-label{color:#6b7280}.info-val{font-weight:600;text-align:right}
.badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:.82rem;font-weight:700}
.badge.excellent{background:#dcfce7;color:#15803d}
.badge.warning{background:#fef9c3;color:#a16207}
.badge.critical{background:#fee2e2;color:#dc2626}
.section-title{font-size:1rem;font-weight:700;margin:26px 0 14px;padding-bottom:8px;border-bottom:1px solid #e5e9ef}
.marks-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:26px}
.mark-card{padding:18px;border-radius:12px;border:1px solid #e5e9ef;text-align:center}
.term-lbl{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:6px}
.mark-val{font-size:2.1rem;font-weight:800;line-height:1;margin-bottom:8px}
.bar{height:6px;border-radius:3px;background:#e5e9ef;overflow:hidden}
.bar-fill{height:100%;border-radius:3px;background:#22c55e;transition:width .3s}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th{background:#f8fafc;padding:9px 12px;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280}
td{padding:9px 12px;border-bottom:1px solid #f3f4f6;vertical-align:top}
tr:last-child td{border-bottom:none}
.signature-row{display:flex;justify-content:space-between;gap:24px;margin-top:36px;padding-top:18px}
.signature-block{flex:1;display:flex;flex-direction:column;align-items:center;text-align:center}
.signature-line{width:100%;max-width:260px;border-top:1px solid #cbd5e1;margin-bottom:10px}
.signature-label{font-size:.78rem;color:#6b7280}
.footer{margin-top:18px;padding-top:18px;border-top:1px solid #e5e9ef;display:flex;justify-content:space-between;font-size:.78rem;color:#9ca3af}
.print-btn{position:fixed;bottom:24px;right:24px;padding:12px 22px;border:0;border-radius:12px;cursor:pointer;font-weight:700;font-size:.95rem;background:linear-gradient(135deg,#22c55e,#38bdf8);color:#04101d}
@media print{.print-btn{display:none}body{background:#fff}.wrap{max-width:none;padding:8mm;box-shadow:none;min-height:auto}a[href]:after{content:none!important}a[href]:after{content:none!important}.letter-print-block{padding:16px 16px;border-width:1px;background:#fffaf0;line-height:1.35}.signature-row{margin-top:18px;padding-top:10px}@page{size:A4;margin:8mm}}
</style>
</head>
<body>
<div class="wrap">
  <div class="rpt-header">
    <div class="brand">SB<span>MS</span></div>
    <div class="rpt-meta">
      <h1>Student Behavior Report</h1>
      <p>Generated: <?= $h($date) ?></p>
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card-title">Student Profile</div>
      <div class="info-row"><span class="info-label">Full Name</span><span class="info-val"><?= $h($student['full_name']) ?></span></div>
      <div class="info-row"><span class="info-label">Student Code</span><span class="info-val"><?= $h($student['student_code']) ?></span></div>
      <div class="info-row"><span class="info-label">Class</span><span class="info-val"><?= $h($student['class_name']) ?></span></div>
      <div class="info-row"><span class="info-label">Category</span><span class="info-val"><?= $h($student['category_name']) ?></span></div>
      <?php if (!empty($student['parent_contact'])): ?>
      <div class="info-row"><span class="info-label">Parent Contact</span><span class="info-val"><?= $h((string) ($student['parent_contact'] ?? '')) ?></span></div>
      <?php endif; ?>
      <div class="info-row"><span class="info-label">Enrolled</span><span class="info-val"><?= $h((string) $student['created_at']) ?></span></div>
    </div>
    <div class="card">
      <div class="card-title">Risk Assessment</div>
      <div class="info-row"><span class="info-label">Risk Level</span><span class="info-val"><span class="badge <?= $h($riskClass) ?>"><?= $h($riskLevel) ?></span></span></div>
      <div class="info-row"><span class="info-label">Incidents</span><span class="info-val"><?= (int) ($risk['incidents_count'] ?? 0) ?></span></div>
      <div class="info-row"><span class="info-label">Total Marks Lost</span><span class="info-val"><?= (int) ($risk['total_marks_deducted'] ?? 0) ?></span></div>
      <div class="info-row"><span class="info-label">Avg / Week</span><span class="info-val"><?= number_format((float) ($risk['avg_deduction_per_week'] ?? 0), 2) ?></span></div>
      <div class="info-row"><span class="info-label">Current Total</span><span class="info-val"><?= $totalMarks ?> / <?= $maxMarks ?></span></div>
      <?php if (!empty($risk['predicted_at'])): ?>
      <div class="info-row"><span class="info-label">Assessed</span><span class="info-val"><?= $h((string) $risk['predicted_at']) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-title">Term Marks</div>
  <div class="marks-grid">
    <?php foreach ($marks as $mark):
        $pct = min(100, (int) $mark['marks'] / 40 * 100); ?>
    <div class="mark-card">
      <div class="term-lbl">Term <?= (int) $mark['term'] ?></div>
      <div class="mark-val"><?= (int) $mark['marks'] ?></div>
      <div class="bar"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($history)): ?>
  <div class="section-title">Deduction History (Last 25)</div>
  <table>
    <thead><tr><th>Term</th><th>Marks</th><th>Reason</th><th>By</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach ($history as $item): ?>
      <tr>
        <td>T<?= (int) $item['term'] ?></td>
        <td><?= (int) $item['marks_deducted'] ?></td>
        <td><?= $h($item['reason']) ?></td>
        <td><?= $h($item['by_name']) ?></td>
        <td><?= $h($item['timestamp']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($showLowMarksLetter): ?>
  <div class="section-title">Parent Invitation Letter</div>
  <div class="letter-print-block" style="padding:24px 24px;border:1px solid #fbbf24;border-radius:10px;background:#fffaf0;line-height:1.45;white-space:pre-wrap;page-break-inside:avoid;">
    <div style="text-align:center; margin-bottom:18px; font-weight:800; text-transform:uppercase; line-height:1.6; letter-spacing:0.04em;">
      Ministry of Education<br>
      Gicumbi district<br>
      Kageyo sector<br>
      KAGEYO TSS
    </div>
    <div style="margin-bottom:18px; font-weight:600;">Date: <?= $h(date('d M Y')) ?></div>
    <div style="white-space:pre-wrap;"><?= htmlspecialchars($letterBody, ENT_QUOTES, 'UTF-8') ?></div>
    <div style="margin-top:42px; display:flex; justify-content:flex-end;">
      <div style="text-align:center; width:260px;">
        <div style="height:58px; border-bottom:1px solid #94a3b8; margin-bottom:8px;"></div>
        <div style="font-size:0.82rem; color:#6b7280;">Stamp & Signature</div>
      </div>
    </div>
    <div style="margin-top:18px; text-align:right; font-weight:700;">Headteacher of KAGEYO TSS</div>
  </div>
  <?php endif; ?>

  <div class="signature-row">
    <div class="signature-block">
      <div class="signature-line"></div>
      <div class="signature-label">Deputy Headteacher in charge of Discipline</div>
    </div>
    <div class="signature-block">
      <div class="signature-line"></div>
      <div class="signature-label">Headteacher</div>
    </div>
  </div>

  <div class="footer">
    <span>SBMS — Student Behavior Management System</span>
    <span>Report ID: <?= $h($reportId) ?></span>
  </div>
</div>
<button class="print-btn" onclick="window.print()">Print / Save PDF</button>
</body>
</html>
        <?php
        return ob_get_clean() ?: '';
    }

    public function generateClassTermReportCsv(int $classId, int $term): ?string
    {
        // Get class info
        $stmtClass = $this->db->prepare('SELECT name FROM classes WHERE id = :id LIMIT 1');
        $stmtClass->execute(['id' => $classId]);
        $className = $stmtClass->fetchColumn();
        if (!$className) {
            return null;
        }

        $selectFragment = $this->selectStudentParentContact();
        $selectColumns = 's.student_code, s.full_name';
        if ($selectFragment !== '') {
            $selectColumns .= ', ' . $selectFragment;
        }

        // Get students and marks for the specific term
        $settings = new \Settings($this->db);
        $bindings = ['class_id' => $classId, 'term' => $term];
        $schoolYearJoin = '';
        if (\Database::columnExists('student_marks', 'school_year')) {
            $schoolYearJoin = ' AND sm.school_year = :school_year';
            $bindings['school_year'] = $settings->selectedSchoolYear();
        }

        $sql = 'SELECT ' . $selectColumns . ', sm.marks'
            . ' FROM students s '
            . ' LEFT JOIN student_marks sm ON sm.student_id = s.id AND sm.term = :term' . $schoolYearJoin
            . ' WHERE s.class_id = :class_id'
            . ' ORDER BY s.full_name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['MINISTRY OF EDUCATION']);
        fputcsv($output, ['GICUMBI DISTRICT']);
        fputcsv($output, ['KAGEYO SECTOR']);
        fputcsv($output, ['KAGEYO TSS']);
        fputcsv($output, []);
        fputcsv($output, ['Class: ' . $className, 'Term: ' . $term, 'Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []); // empty row

        $headers = ['Student Code', 'Full Name', 'Marks'];
        if ($selectFragment !== '') {
            $headers[] = 'Parent Contact';
        }
        fputcsv($output, $headers);

        foreach ($students as $student) {
            $row = [
                $student['student_code'],
                $student['full_name'],
                $student['marks'] ?? 'N/A'
            ];
            if (isset($student['parent_contact'])) {
                $row[] = $student['parent_contact'];
            }
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    public function generateTermReportCsv(int $term): string
    {
        $stmt = $this->db->prepare(
            'SELECT s.student_code, s.full_name, c.name AS class_name, cat.name AS category_name, sm.marks
             FROM students s
             JOIN classes c ON s.class_id = c.id
             JOIN categories cat ON s.category_id = cat.id
             LEFT JOIN student_marks sm ON sm.student_id = s.id AND sm.term = :term
             ORDER BY cat.name ASC, c.name ASC, s.full_name ASC'
        );
        $stmt->execute(['term' => $term]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['MINISTRY OF EDUCATION']);
        fputcsv($output, ['GICUMBI DISTRICT']);
        fputcsv($output, ['KAGEYO SECTOR']);
        fputcsv($output, ['KAGEYO TSS']);
        fputcsv($output, []);
        fputcsv($output, ['School Term Report', 'Term: ' . $term, 'Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Category', 'Class', 'Student Code', 'Full Name', 'Marks']);

        foreach ($students as $student) {
            fputcsv($output, [
                $student['category_name'],
                $student['class_name'],
                $student['student_code'],
                $student['full_name'],
                $student['marks'] ?? 'N/A'
            ]);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function generateYearlyReportCsv(array $classIds = []): string
    {
        $studentQuery =
            'SELECT s.id, s.student_code, s.full_name, c.name AS class_name, cat.name AS category_name
             FROM students s
             JOIN classes c ON s.class_id = c.id
             JOIN categories cat ON s.category_id = cat.id';
        $bindings = [];

        if ($classIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($classIds), '?'));
            $studentQuery .= ' WHERE s.class_id IN (' . $placeholders . ')';
            $bindings = $classIds;
        }

        $studentQuery .= ' ORDER BY cat.name ASC, c.name ASC, s.full_name ASC';

        $stmt = $this->db->prepare($studentQuery);
        $stmt->execute($bindings);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtMarks = $this->db->query('SELECT student_id, term, marks FROM student_marks');
        $marksMap = [];
        foreach ($stmtMarks->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $marksMap[$row['student_id']][$row['term']] = $row['marks'];
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['MINISTRY OF EDUCATION']);
        fputcsv($output, ['GICUMBI DISTRICT']);
        fputcsv($output, ['KAGEYO SECTOR']);
        fputcsv($output, ['KAGEYO TSS']);
        fputcsv($output, []);
        fputcsv($output, ['School Yearly Report', 'Year: ' . date('Y'), 'Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Category', 'Class', 'Student Code', 'Full Name', 'Term 1', 'Term 2', 'Term 3', 'Total', 'Average']);

        foreach ($students as $student) {
            $t1 = $marksMap[$student['id']][1] ?? 'N/A';
            $t2 = $marksMap[$student['id']][2] ?? 'N/A';
            $t3 = $marksMap[$student['id']][3] ?? 'N/A';
            
            $validMarks = [];
            if ($t1 !== 'N/A') $validMarks[] = (int) $t1;
            if ($t2 !== 'N/A') $validMarks[] = (int) $t2;
            if ($t3 !== 'N/A') $validMarks[] = (int) $t3;
            
            $total = count($validMarks) > 0 ? array_sum($validMarks) : 'N/A';
            $avg = count($validMarks) > 0 ? round($total / count($validMarks), 2) : 'N/A';

            fputcsv($output, [
                $student['category_name'],
                $student['class_name'],
                $student['student_code'],
                $student['full_name'],
                $t1, $t2, $t3, $total, $avg
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function generateTermReportHtml(int $term): string
    {
        $stmt = $this->db->prepare(
            'SELECT s.student_code, s.full_name, c.name AS class_name, cat.name AS category_name, sm.marks
             FROM students s
             JOIN classes c ON s.class_id = c.id
             JOIN categories cat ON s.category_id = cat.id
             LEFT JOIN student_marks sm ON sm.student_id = s.id AND sm.term = :term
             ORDER BY cat.name ASC, c.name ASC, s.full_name ASC'
        );
        $stmt->execute(['term' => $term]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $headers = ['Category', 'Class', 'Student Code', 'Full Name', 'Marks'];
        $rows = [];
        foreach ($students as $student) {
            $rows[] = [
                $student['category_name'],
                $student['class_name'],
                $student['student_code'],
                $student['full_name'],
                $student['marks'] ?? 'N/A'
            ];
        }

        return $this->renderTableHtmlReport('School Term Report', "Term: $term", $headers, $rows);
    }

    public function generateYearlyReportHtml(array $classIds = []): string
    {
        $studentQuery =
            'SELECT s.id, s.student_code, s.full_name, c.name AS class_name, cat.name AS category_name
             FROM students s
             JOIN classes c ON s.class_id = c.id
             JOIN categories cat ON s.category_id = cat.id';
        $bindings = [];

        if ($classIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($classIds), '?'));
            $studentQuery .= ' WHERE s.class_id IN (' . $placeholders . ')';
            $bindings = $classIds;
        }

        $studentQuery .= ' ORDER BY cat.name ASC, c.name ASC, s.full_name ASC';

        $stmt = $this->db->prepare($studentQuery);
        $stmt->execute($bindings);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtMarks = $this->db->query('SELECT student_id, term, marks FROM student_marks');
        $marksMap = [];
        foreach ($stmtMarks->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $marksMap[$row['student_id']][$row['term']] = $row['marks'];
        }

        $headers = ['Category', 'Class', 'Student Code', 'Full Name', 'T1', 'T2', 'T3', 'Total', 'Avg'];
        $rows = [];
        foreach ($students as $student) {
            $t1 = $marksMap[$student['id']][1] ?? 'N/A';
            $t2 = $marksMap[$student['id']][2] ?? 'N/A';
            $t3 = $marksMap[$student['id']][3] ?? 'N/A';
            
            $validMarks = [];
            if ($t1 !== 'N/A') $validMarks[] = (int) $t1;
            if ($t2 !== 'N/A') $validMarks[] = (int) $t2;
            if ($t3 !== 'N/A') $validMarks[] = (int) $t3;
            
            $total = count($validMarks) > 0 ? array_sum($validMarks) : 'N/A';
            $avg = count($validMarks) > 0 ? round($total / count($validMarks), 2) : 'N/A';

            $rows[] = [
                $student['category_name'],
                $student['class_name'],
                $student['student_code'],
                $student['full_name'],
                $t1, $t2, $t3, $total, $avg
            ];
        }

        return $this->renderTableHtmlReport('School Yearly Report', "Academic Year: " . date('Y'), $headers, $rows);
    }

    public function generateClassTermReportHtml(int $classId, int $term): ?string
    {
        $stmtClass = $this->db->prepare('SELECT name FROM classes WHERE id = :id LIMIT 1');
        $stmtClass->execute(['id' => $classId]);
        $className = $stmtClass->fetchColumn();
        if (!$className) {
            return null;
        }

        $selectFragment = $this->selectStudentParentContact();
        $selectColumns = 's.student_code, s.full_name';
        if ($selectFragment !== '') {
            $selectColumns .= ', ' . $selectFragment;
        }

        $stmt = $this->db->prepare(
            'SELECT ' . $selectColumns . ', sm.marks
             FROM students s
             LEFT JOIN student_marks sm ON sm.student_id = s.id AND sm.term = :term
             WHERE s.class_id = :class_id
             ORDER BY s.full_name ASC'
        );
        $stmt->execute(['class_id' => $classId, 'term' => $term]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $headers = ['Student Code', 'Full Name', 'Marks'];
        if ($selectFragment !== '') {
            $headers[] = 'Parent Contact';
        }

        $rows = [];
        foreach ($students as $student) {
            $row = [
                $student['student_code'],
                $student['full_name'],
                $student['marks'] ?? 'N/A'
            ];
            if (isset($student['parent_contact'])) {
                $row[] = $student['parent_contact'];
            }
            $rows[] = $row;
        }

        return $this->renderTableHtmlReport("Class Report: $className", "Term: $term", $headers, $rows);
    }

    /**
     * Return class analytics for the dashboard and analytics pages.
     */
    public function getDashboardAnalytics(int $classId, int $term): array
    {
        $term = in_array($term, [1, 2, 3], true) ? $term : 1;

        $stmtClass = $this->db->prepare('SELECT id, name FROM classes WHERE id = :id LIMIT 1');
        $stmtClass->execute(['id' => $classId]);
        $classRow = $stmtClass->fetch(PDO::FETCH_ASSOC);
        if (!$classRow) {
            return [
                'class_id' => $classId,
                'term' => $term,
                'class_name' => null,
                'class_average_marks' => 0,
                'total_students' => 0,
                'total_incidents' => 0,
                'high_risk_students' => 0,
                'students' => [],
                'top_performers' => [],
            ];
        }

        $hasRewardsTable = \Database::tableExists('student_rewards');
        $hasRiskTable = \Database::tableExists('student_risk_profiles');
        $rewardsJoin = $hasRewardsTable ? 'LEFT JOIN student_rewards sr ON sr.student_id = s.id' : '';
        $rewardsSelect = $hasRewardsTable ? 'COALESCE(sr.total_points, 0) AS total_points,' : '0 AS total_points,';
        $riskJoin = $hasRiskTable ? 'LEFT JOIN student_risk_profiles rp ON rp.student_id = s.id' : '';
        $riskSelect = $hasRiskTable
            ? 'COALESCE(rp.risk_level, "Excellent") AS risk_level, COALESCE(rp.incidents_count, 0) AS incident_count, COALESCE(rp.total_marks_deducted, 0) AS total_marks_deducted'
            : '"Excellent" AS risk_level, 0 AS incident_count, 0 AS total_marks_deducted';

        $stmt = $this->db->prepare(
            'SELECT
                s.id,
                s.full_name,
                s.student_code,
                COALESCE(sm.marks, 40) AS current_marks,
                ' . $rewardsSelect . '
                ' . $riskSelect . '
             FROM students s
             LEFT JOIN student_marks sm ON sm.student_id = s.id AND sm.term = :term
             ' . $rewardsJoin . '
             ' . $riskJoin . '
             WHERE s.class_id = :class_id
             ORDER BY current_marks DESC, total_points DESC, incident_count ASC, s.full_name ASC'
        );
        $stmt->execute([
            'class_id' => $classId,
            'term' => $term,
        ]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalStudents = count($students);
        $marks = array_map(static fn (array $row): int => (int) $row['current_marks'], $students);
        $classAverage = $totalStudents > 0 ? round(array_sum($marks) / $totalStudents, 2) : 0.0;
        $highRiskStudents = array_reduce(
            $students,
            static function (int $carry, array $row): int {
                $risk = strtolower((string) ($row['risk_level'] ?? ''));
                return in_array($risk, ['critical', 'high risk'], true) ? $carry + 1 : $carry;
            },
            0
        );

        $stmtIncidents = $this->db->prepare(
            'SELECT COUNT(*)
             FROM deductions_history dh
             INNER JOIN students s ON s.id = dh.student_id
             WHERE s.class_id = :class_id
               AND dh.term = :term
               AND dh.marks_deducted > 0'
        );
        $stmtIncidents->execute([
            'class_id' => $classId,
            'term' => $term,
        ]);
        $totalIncidents = (int) $stmtIncidents->fetchColumn();

        $topPerformers = array_slice($students, 0, 5);

        return [
            'class_id' => (int) $classRow['id'],
            'class_name' => (string) $classRow['name'],
            'term' => $term,
            'class_average_marks' => $classAverage,
            'total_students' => $totalStudents,
            'total_incidents' => $totalIncidents,
            'high_risk_students' => $highRiskStudents,
            'students' => $students,
            'top_performers' => $topPerformers,
        ];
    }

    /**
     * Return incident analytics by category for the selected class and term.
     */
    public function getIncidentAnalytics(int $classId, int $term): array
    {
        $term = in_array($term, [1, 2, 3], true) ? $term : 1;

        if (!\Database::tableExists('deductions_history')) {
            return [];
        }

        $categorySelect = \Database::columnExists('deductions_history', 'incident_category')
            ? 'COALESCE(NULLIF(TRIM(dh.incident_category), ""), "Uncategorised") AS incident_category'
            : '"Uncategorised" AS incident_category';

        $stmt = $this->db->prepare(
            'SELECT
                ' . $categorySelect . ',
                COUNT(*) AS incident_count,
                COALESCE(SUM(CASE WHEN dh.marks_deducted > 0 THEN dh.marks_deducted ELSE 0 END), 0) AS total_deductions,
                COALESCE(AVG(CASE WHEN dh.marks_deducted > 0 THEN dh.marks_deducted ELSE NULL END), 0) AS average_deduction
             FROM deductions_history dh
             INNER JOIN students s ON s.id = dh.student_id
             WHERE s.class_id = :class_id
               AND dh.term = :term
               AND dh.marks_deducted > 0
             GROUP BY incident_category
             ORDER BY incident_count DESC, total_deductions DESC, incident_category ASC'
        );
        $stmt->execute([
            'class_id' => $classId,
            'term' => $term,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalIncidents = array_sum(array_map(static fn (array $row): int => (int) $row['incident_count'], $rows));

        foreach ($rows as $index => $row) {
            $rows[$index]['incident_count'] = (int) $row['incident_count'];
            $rows[$index]['total_deductions'] = (int) $row['total_deductions'];
            $rows[$index]['average_deduction'] = round((float) $row['average_deduction'], 2);
            $rows[$index]['share_percent'] = $totalIncidents > 0
                ? round(($rows[$index]['incident_count'] / $totalIncidents) * 100, 1)
                : 0.0;
        }

        return $rows;
    }

    public function generateHighRiskReportCsv(): string
    {
        $stmt = $this->db->prepare(
            "SELECT cat.name AS category_name, c.name AS class_name, s.student_code, s.full_name, 
                    sr.risk_level, sr.total_marks_deducted, sr.incidents_count
             FROM student_risk_profiles sr
             JOIN students s ON sr.student_id = s.id
             JOIN classes c ON s.class_id = c.id
             JOIN categories cat ON s.category_id = cat.id
             WHERE sr.risk_level IN ('Critical', 'Warning')
             ORDER BY sr.total_marks_deducted DESC"
        );
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $output = fopen('php://temp', 'w+');
        fputcsv($output, ['MINISTRY OF EDUCATION']);
        fputcsv($output, ['GICUMBI DISTRICT']);
        fputcsv($output, ['KAGEYO SECTOR']);
        fputcsv($output, ['KAGEYO TSS']);
        fputcsv($output, []);
        fputcsv($output, ['Category', 'Class', 'Student ID', 'Student Name', 'Risk Level', 'Total Deductions', 'Incidents']);

        foreach ($students as $student) {
            fputcsv($output, [
                $student['category_name'],
                $student['class_name'],
                $student['student_code'],
                $student['full_name'],
                $student['risk_level'],
                $student['total_marks_deducted'],
                $student['incidents_count']
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function generateHighRiskReportHtml(): string
    {
        $stmt = $this->db->prepare(
            "SELECT cat.name AS category_name, c.name AS class_name, s.student_code, s.full_name, 
                    sr.risk_level, sr.total_marks_deducted, sr.incidents_count
             FROM student_risk_profiles sr
             JOIN students s ON sr.student_id = s.id
             JOIN classes c ON s.class_id = c.id
             JOIN categories cat ON s.category_id = cat.id
             WHERE sr.risk_level IN ('Critical', 'Warning')
             ORDER BY sr.total_marks_deducted DESC"
        );
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $headers = ['Category', 'Class', 'Student ID', 'Student Name', 'Risk Level', 'Total Deductions', 'Incidents'];
        $rows = [];

        foreach ($students as $student) {
            $rows[] = [
                $student['category_name'],
                $student['class_name'],
                $student['student_code'],
                $student['full_name'],
                $student['risk_level'],
                $student['total_marks_deducted'],
                $student['incidents_count']
            ];
        }

        return $this->renderTableHtmlReport('High Risk Students Report', 'Active Students (Critical & High)', $headers, $rows);
    }

    private function renderTableHtmlReport(string $title, string $subtitle, array $headers, array $rows): string
    {
        $h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $date = date('d M Y, H:i T');
        $reportId = 'RPT-' . date('Ymd-His');

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title><?= $h($title) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Inter,sans-serif;background:#f4f6f9;color:#1a2332;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.wrap{max-width:1000px;margin:0 auto;background:#fff;padding:48px;min-height:100vh}
.rpt-org{text-align:center;margin:0 0 20px;padding:8px 0;border-bottom:1px dashed #d1d5db}
.rpt-org p{margin:0;font-size:.9rem;font-weight:700;line-height:1.45;color:#0f172a}
.rpt-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:28px;border-bottom:2px solid #e5e9ef;margin-bottom:28px}
.brand{font-size:1.7rem;font-weight:800;letter-spacing:.06em;color:#1a2332}.brand span{color:#22c55e}
.rpt-meta{text-align:right}.rpt-meta h1{font-size:1.25rem;font-weight:700}.rpt-meta p{color:#6b7280;font-size:.88rem;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:.88rem;margin-bottom:28px}
th{background:#f8fafc;padding:9px 12px;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280; border-bottom:2px solid #e5e9ef;}
td{padding:9px 12px;border-bottom:1px solid #f3f4f6;vertical-align:top}
tr:nth-child(even){background-color:#fafafa;}
.signature-row{display:flex;justify-content:space-between;gap:24px;margin-top:36px;padding-top:18px}
.signature-block{flex:1;display:flex;flex-direction:column;align-items:center;text-align:center}
.signature-line{width:100%;max-width:260px;border-top:1px solid #cbd5e1;margin-bottom:10px}
.signature-label{font-size:.78rem;color:#6b7280}
.footer{margin-top:18px;padding-top:18px;border-top:1px solid #e5e9ef;display:flex;justify-content:space-between;font-size:.78rem;color:#9ca3af}
.print-btn{position:fixed;bottom:24px;right:24px;padding:12px 22px;border:0;border-radius:12px;cursor:pointer;font-weight:700;font-size:.95rem;background:linear-gradient(135deg,#22c55e,#38bdf8);color:#04101d;box-shadow:0 4px 12px rgba(0,0,0,0.15);}
@media print{.print-btn{display:none}body{background:#fff}.wrap{max-width:none;padding:8mm;box-shadow:none;min-height:auto}.letter-print-block{padding:16px 16px;border-width:1px;background:#fffaf0;line-height:1.35}.signature-row{margin-top:18px;padding-top:10px}@page{size:A4;margin:8mm}}
</style>
</head>
<body>
<div class="wrap">
  <div class="rpt-org">
    <p>MINISTRY OF EDUCATION</p>
    <p>GICUMBI DISTRICT</p>
    <p>KAGEYO SECTOR</p>
    <p>KAGEYO TSS</p>
  </div>

  <div class="rpt-header">
    <div class="brand">SB<span>MS</span></div>
    <div class="rpt-meta">
      <h1><?= $h($title) ?></h1>
      <p><?= $h($subtitle) ?> &bull; Generated: <?= $h($date) ?></p>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <?php foreach ($headers as $header): ?>
        <th><?= $h($header) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
      <tr>
        <?php foreach ($row as $cell): ?>
        <td><?= $h((string)$cell) ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
      <tr>
        <td colspan="<?= count($headers) ?>" style="text-align:center;color:#9ca3af;padding:20px;">No data available.</td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="signature-row">
    <div class="signature-block">
      <div class="signature-line"></div>
      <div class="signature-label">Deputy Headteacher in charge of Discipline</div>
    </div>
    <div class="signature-block">
      <div class="signature-line"></div>
      <div class="signature-label">Headteacher</div>
    </div>
  </div>

  <div class="footer">
    <span>SBMS — Student Behavior Management System</span>
    <span>Report ID: <?= $h($reportId) ?></span>
  </div>
</div>
<button class="print-btn" onclick="window.print()">Print / Save PDF</button>
<script>
window.onload = function() { window.print(); }
</script>
</body>
</html>
        <?php
        return ob_get_clean() ?: '';
    }
}
