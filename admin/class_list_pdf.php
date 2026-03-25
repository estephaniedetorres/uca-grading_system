<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../libs/fpdf.php';
requireRole('admin');

$syId = (int)($_GET['sy_id'] ?? 0);
$studentFilter = (int)($_GET['student'] ?? 0);
$sectionFilter = $_GET['section'] ?? '';

if (!$syId) {
    die('School year is required.');
}

$syStmt = db()->prepare("SELECT sy_name FROM tbl_sy WHERE id = ?");
$syStmt->execute([$syId]);
$sy = $syStmt->fetch();

// Get students to include
$studentsWhere = ["e.sy_id = ?", "e.status = 'enrolled'"];
$studentsParams = [$syId];

if ($studentFilter) {
    $studentsWhere[] = "s.id = ?";
    $studentsParams[] = $studentFilter;
} elseif ($sectionFilter) {
    $studentsWhere[] = "sec.id = ?";
    $studentsParams[] = (int)$sectionFilter;
}

$studentsClause = implode(' AND ', $studentsWhere);

$studentsStmt = db()->prepare("
    SELECT DISTINCT s.id, s.student_no, s.given_name, s.middle_name, s.last_name, s.suffix,
           sec.section_code, at.`desc` as course_name, lv.code as level_code
    FROM tbl_enroll e
    JOIN tbl_student s ON e.student_id = s.id
    JOIN tbl_section sec ON e.section_id = sec.id
    LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN level lv ON sec.level_id = lv.id
    WHERE $studentsClause
    ORDER BY s.last_name, s.given_name
");
$studentsStmt->execute($studentsParams);
$students = $studentsStmt->fetchAll();

if (empty($students)) {
    die('No students found.');
}

class ClassListPDF extends FPDF {
    protected $syName = '';

    function setSyName($name) { $this->syName = $name; }

    function Header() {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 7, 'School', 0, 1, 'C');
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, 'Address Line 1, City, Country', 0, 1, 'C');
        $this->Cell(0, 5, 'Tel: (123) 456-7890 | Email: info@school.edu', 0, 1, 'C');
        $this->Ln(3);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetX(10);
        $this->Cell(0, 10, 'Generated: ' . date('F d, Y h:i A'), 0, 0, 'L');
    }

    function StudentClassList($student, $rows, $syName) {
        // Student info block
        $this->SetFont('Arial', '', 9);
        $name = strtoupper($student['last_name'] . ', ' . $student['given_name'] . ' ' . ($student['middle_name'] ? substr($student['middle_name'], 0, 1) . '.' : '') . ($student['suffix'] ? ' ' . $student['suffix'] : ''));

        $this->Cell(25, 6, 'Student # :', 0, 0);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(55, 6, $student['student_no'], 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell(18, 6, 'Name :', 0, 0);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 6, $name, 0, 1);

        $this->SetFont('Arial', '', 9);
        $this->Cell(25, 6, 'Course :', 0, 0);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(55, 6, $student['course_name'] ?? 'N/A', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell(18, 6, 'Level :', 0, 0);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 6, $student['level_code'] ?? 'N/A', 0, 1);

        $this->SetFont('Arial', '', 9);
        $this->Cell(25, 6, 'S.Y. :', 0, 0);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 6, $syName, 0, 1);

        $this->Ln(3);

        // Table header
        $w = [10, 32, 80, 30, 30, 25, 25, 25];
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(220, 220, 220);
        $this->Cell($w[0], 7, 'No.', 1, 0, 'C', true);
        $this->Cell($w[1], 7, 'Code', 1, 0, 'C', true);
        $this->Cell($w[2], 7, 'Description', 1, 0, 'C', true);
        $this->Cell($w[3], 7, 'Section', 1, 0, 'C', true);
        $this->Cell($w[4], 7, 'Teacher', 1, 0, 'C', true);
        $this->Cell($w[5], 7, 'Lec Units', 1, 0, 'C', true);
        $this->Cell($w[6], 7, 'Lab Units', 1, 0, 'C', true);
        $this->Cell($w[7], 7, 'Total Units', 1, 0, 'C', true);
        $this->Ln();

        // Table rows
        $this->SetFont('Arial', '', 8);
        $totalLec = 0;
        $totalLab = 0;
        $totalUnits = 0;

        foreach ($rows as $i => $r) {
            $lec = $r['lec_u'] ?? $r['unit'] ?? 0;
            $lab = $r['lab_u'] ?? 0;
            $units = $r['unit'] ?? ($lec + $lab);
            $totalLec += $lec;
            $totalLab += $lab;
            $totalUnits += $units;

            $this->Cell($w[0], 6, $i + 1, 1, 0, 'C');
            $this->Cell($w[1], 6, $r['subjcode'], 1, 0, 'L');
            $this->Cell($w[2], 6, substr($r['subject_desc'], 0, 45), 1, 0, 'L');
            $this->Cell($w[3], 6, $r['section_code'], 1, 0, 'C');
            $this->Cell($w[4], 6, substr($r['teacher_name'] ?? 'TBA', 0, 18), 1, 0, 'L');
            $this->Cell($w[5], 6, $lec ? number_format($lec, 1) : '-', 1, 0, 'C');
            $this->Cell($w[6], 6, $lab ? number_format($lab, 1) : '-', 1, 0, 'C');
            $this->Cell($w[7], 6, $units ? number_format($units, 1) : '-', 1, 0, 'C');
            $this->Ln();
        }

        // Totals row
        $this->SetFont('Arial', 'B', 8);
        $totalWidth = $w[0] + $w[1] + $w[2] + $w[3] + $w[4];
        $this->Cell($totalWidth, 7, 'Total', 1, 0, 'R');
        $this->Cell($w[5], 7, $totalLec ? number_format($totalLec, 1) : '-', 1, 0, 'C');
        $this->Cell($w[6], 7, $totalLab ? number_format($totalLab, 1) : '-', 1, 0, 'C');
        $this->Cell($w[7], 7, $totalUnits ? number_format($totalUnits, 1) : '-', 1, 0, 'C');
        $this->Ln();
    }
}

$pdf = new ClassListPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->setSyName($sy['sy_name'] ?? '');
$pdf->SetAutoPageBreak(true, 20);

foreach ($students as $student) {
    $pdf->AddPage();

    // Get this student's enrolled subjects
    $stmtClasses = db()->prepare("
        SELECT sub.subjcode, sub.`desc` as subject_desc, sub.unit, sub.lec_u, sub.lab_u,
               sec.section_code, t.name as teacher_name
        FROM tbl_enroll e
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        JOIN tbl_section sec ON e.section_id = sec.id
        LEFT JOIN tbl_teacher t ON e.teacher_id = t.id
        WHERE e.student_id = ? AND e.sy_id = ? AND e.status = 'enrolled'
        ORDER BY sub.subjcode
    ");
    $stmtClasses->execute([$student['id'], $syId]);
    $rows = $stmtClasses->fetchAll();

    $pdf->StudentClassList($student, $rows, $sy['sy_name'] ?? '');
}

$filename = $studentFilter ? 'Class_List_' . ($students[0]['student_no'] ?? '') . '.pdf' : 'Class_List_All.pdf';
$pdf->Output('I', $filename);
