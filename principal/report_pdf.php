<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../libs/fpdf.php';
requireRole('principal');

$principalDeptIds = $_SESSION['dept_ids'] ?? [];

// Get parameters
$syFilter = $_GET['sy'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$studentFilter = $_GET['student'] ?? '';

if (!$syFilter) {
    die('School Year is required');
}

// Get school year name
$syStmt = db()->prepare("SELECT sy_name FROM tbl_sy WHERE id = ?");
$syStmt->execute([(int)$syFilter]);
$syName = $syStmt->fetchColumn();
if (!$syName) die('Invalid school year');

// Resolve SY to all active term IDs
$termStmt = db()->prepare("SELECT id FROM tbl_term WHERE sy_id = ? AND status = 'active'");
$termStmt->execute([(int)$syFilter]);
$termIds = $termStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($termIds)) die('No active terms for this school year');

$termPlaceholders = implode(',', array_fill(0, count($termIds), '?'));

// Determine students to include (scoped to principal's departments)
$studentsList = [];

if ($studentFilter) {
    $stStmt = db()->prepare("
        SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name, d.code as dept_code, lv.code as level_code
        FROM tbl_student st
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        LEFT JOIN level lv ON sec.level_id = lv.id
        WHERE st.id = ?
    ");
    $stStmt->execute([(int)$studentFilter]);
    $s = $stStmt->fetch();
    if ($s) $studentsList[] = $s;
} elseif ($sectionFilter) {
    $stStmt = db()->prepare("
        SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name, d.code as dept_code, lv.code as level_code
        FROM tbl_student st
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        LEFT JOIN level lv ON sec.level_id = lv.id
        WHERE st.section_id = ?
        ORDER BY st.last_name, st.given_name
    ");
    $stStmt->execute([(int)$sectionFilter]);
    $studentsList = $stStmt->fetchAll();
} elseif (!empty($principalDeptIds)) {
    $ph = implode(',', array_fill(0, count($principalDeptIds), '?'));
    $stStmt = db()->prepare("
        SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name, d.code as dept_code, lv.code as level_code
        FROM tbl_student st
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        LEFT JOIN level lv ON sec.level_id = lv.id
        WHERE at.dept_id IN ($ph)
        ORDER BY sec.section_code, st.last_name, st.given_name
    ");
    $stStmt->execute($principalDeptIds);
    $studentsList = $stStmt->fetchAll();
}

if (empty($studentsList)) {
    die('No students found');
}

class GradeReportPDF extends FPDF {
    function Header() {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, 'School', 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, 'Address Line 1, City, Country', 0, 1, 'C');
        $this->Cell(0, 4, 'Tel: (123) 456-7890 | Email: info@school.edu', 0, 1, 'C');
        $this->Ln(2);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 8, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetX(10);
        $this->Cell(0, 8, 'Generated: ' . date('F d, Y h:i A'), 0, 0, 'L');
    }

    function NbLines($w, $txt) {
        $txt = (string)$txt;
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += isset($cw[$c]) ? $cw[$c] : 500;
            if ($l > $wmax) {
                if ($sep == -1) { if ($i == $j) $i++; }
                else { $i = $sep + 1; }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else { $i++; }
        }
        return $nl;
    }

    function TableRow($widths, $texts, $aligns, $lineH = 5) {
        $nb = 0;
        for ($i = 0; $i < count($widths); $i++) {
            $nb = max($nb, $this->NbLines($widths[$i], $texts[$i]));
        }
        $h = $lineH * $nb;
        $x = $this->GetX();
        $y = $this->GetY();

        for ($i = 0; $i < count($widths); $i++) {
            $w = $widths[$i];
            $a = $aligns[$i] ?? 'L';
            $this->Rect($x, $y, $w, $h);
            $this->SetXY($x, $y);
            $this->MultiCell($w, $lineH, $texts[$i], 0, $a);
            $x += $w;
        }
        $this->SetXY($this->lMargin, $y + $h);
    }

    function StudentGradeReport($student, $grades, $syName, $isCollege, $totalEnrolled) {
        $name = strtoupper($student['last_name'] . ', ' . $student['given_name'] . ' '
            . ($student['middle_name'] ? substr($student['middle_name'], 0, 1) . '.' : '')
            . ($student['suffix'] ? ' ' . $student['suffix'] : ''));

        $this->SetFont('Arial', '', 8);
        $this->Cell(20, 5, 'Student # :', 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(40, 5, $student['student_no'] ?? '', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(14, 5, 'Name :', 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(72, 5, $name, 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(10, 5, 'S.Y. :', 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 5, $syName, 0, 1);

        $this->SetFont('Arial', '', 8);
        $this->Cell(20, 5, $isCollege ? 'Course :' : 'Level :', 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(40, 5, $isCollege ? ($student['course_code'] ?? 'N/A') : ($student['level_code'] ?? 'N/A'), 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(14, 5, 'Section :', 0, 0);
        $this->SetFont('Arial', 'B', 8);
        if ($isCollege) {
            $this->Cell(40, 5, $student['section_code'], 0, 0);
            $this->SetFont('Arial', '', 8);
            $this->Cell(18, 5, 'Year/Level :', 0, 0);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(0, 5, $student['level_code'] ?? 'N/A', 0, 1);
        } else {
            $this->Cell(0, 5, $student['section_code'], 0, 1);
        }

        $this->Ln(2);
        $this->GradeTableStandard($grades, $isCollege, $totalEnrolled);
        $this->Ln(8);
        $this->SignatureSection();
    }

    function GradeTableStandard($grades, $isCollege, $totalEnrolled) {
        $subjects = [];
        foreach ($grades as $g) {
            $code = $g['subjcode'];
            if (!isset($subjects[$code])) {
                $subjects[$code] = [
                    'subjcode' => $code,
                    'subject_name' => $g['subject_name'],
                    'unit' => $g['unit'],
                    'teacher_name' => $g['teacher_name'] ?? 'N/A',
                    'period_grades' => [],
                    'has_grade' => false,
                ];
            }
            if ($g['period_grade'] !== null) {
                $subjects[$code]['period_grades'][] = $g['period_grade'];
                $subjects[$code]['has_grade'] = true;
            }
            if (!empty($g['teacher_name'])) {
                $subjects[$code]['teacher_name'] = $g['teacher_name'];
            }
        }
        foreach ($subjects as &$s) {
            $s['final_grade'] = count($s['period_grades']) > 0
                ? round(array_sum($s['period_grades']) / count($s['period_grades']), $isCollege ? 2 : 0)
                : null;
        }
        unset($s);

        $w = [8, 20, 62, 35, 12, 25, 28];

        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(220, 220, 220);
        $this->Cell($w[0], 6, 'No.', 1, 0, 'C', true);
        $this->Cell($w[1], 6, 'Code', 1, 0, 'C', true);
        $this->Cell($w[2], 6, 'Description', 1, 0, 'C', true);
        $this->Cell($w[3], 6, 'Teacher', 1, 0, 'C', true);
        $this->Cell($w[4], 6, 'Units', 1, 0, 'C', true);
        $this->Cell($w[5], 6, 'Grade', 1, 0, 'C', true);
        $this->Cell($w[6], 6, 'Remarks', 1, 0, 'C', true);
        $this->Ln();

        $this->SetFont('Arial', '', 7);
        $totalUnits = 0;
        $weightedSum = 0;
        $gradeSum = 0;
        $gradeCount = 0;
        $num = 1;

        foreach ($subjects as $s) {
            $fg = $s['final_grade'];
            $totalUnits += $s['unit'];
            if ($s['has_grade']) {
                $weightedSum += ($fg * $s['unit']);
                $gradeSum += $fg;
                $gradeCount++;
            }
            $passed = $s['has_grade'] && ($isCollege ? ($fg <= 3.00) : ($fg >= 75));

            $gradeStr = $s['has_grade'] ? ($isCollege ? number_format($fg, 2) : number_format($fg, 0)) : 'N/A';
            $remarkStr = $s['has_grade'] ? ($passed ? 'PASSED' : 'FAILED') : '';

            $this->TableRow(
                $w,
                [$num++, $s['subjcode'], $s['subject_name'], $s['teacher_name'], $s['unit'], $gradeStr, $remarkStr],
                ['C', 'L', 'L', 'L', 'C', 'C', 'C'],
                5
            );
        }

        $allGraded = ($gradeCount >= $totalEnrolled && $totalEnrolled > 0);
        $this->SetFont('Arial', 'B', 7);
        $totalW = $w[0] + $w[1] + $w[2] + $w[3];

        if ($isCollege) {
            $gwa = ($allGraded && $totalUnits > 0) ? $weightedSum / $totalUnits : 0;
            $this->Cell($totalW, 6, 'General Weighted Average (GWA)', 1, 0, 'R');
            $this->Cell($w[4], 6, $totalUnits, 1, 0, 'C');
            $this->Cell($w[5], 6, $allGraded ? number_format($gwa, 2) : 'N/A', 1, 0, 'C');
            $this->Cell($w[6], 6, $allGraded ? (($gwa <= 3.00) ? 'PASSED' : 'FAILED') : 'Incomplete', 1, 0, 'C');
        } else {
            $avg = ($allGraded && $gradeCount > 0) ? $gradeSum / $gradeCount : 0;
            $this->Cell($totalW, 6, 'General Average', 1, 0, 'R');
            $this->Cell($w[4], 6, $totalUnits, 1, 0, 'C');
            $this->Cell($w[5], 6, $allGraded ? number_format($avg, 0) : 'N/A', 1, 0, 'C');
            $this->Cell($w[6], 6, $allGraded ? ($avg >= 75 ? 'PASSED' : 'FAILED') : 'Incomplete', 1, 0, 'C');
        }
        $this->Ln();
    }

    function SignatureSection() {
        $this->SetFont('Arial', '', 8);
        $leftX = 30;
        $rightX = 120;

        $this->SetX($leftX);
        $this->Cell(60, 5, '________________________', 0, 0, 'C');
        $this->SetX($rightX);
        $this->Cell(60, 5, '________________________', 0, 1, 'C');

        $this->SetFont('Arial', 'B', 8);
        $this->SetX($leftX);
        $this->Cell(60, 4, 'Registrar', 0, 0, 'C');
        $this->SetX($rightX);
        $this->Cell(60, 4, 'Principal', 0, 1, 'C');
    }
}

// Create PDF
$pdf = new GradeReportPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(false);

$studentIndex = 0;

foreach ($studentsList as $student) {
    $isCollege = in_array(strtoupper($student['dept_code'] ?? ''), ['CCTE', 'CON']);

    $gradesStmt = db()->prepare("
        SELECT g.period_grade, g.grading_period, g.status as grade_status,
               sub.subjcode, sub.`desc` as subject_name, sub.unit,
               t.term_name, teach.name as teacher_name
        FROM tbl_enroll e
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        LEFT JOIN tbl_grades g ON g.enroll_id = e.id AND g.term_id IN ($termPlaceholders)
        LEFT JOIN tbl_term t ON g.term_id = t.id
        LEFT JOIN tbl_teacher teach ON g.teacher_id = teach.id
        WHERE e.student_id = ? AND e.sy_id = ? AND e.status != 'dropped'
        ORDER BY sub.subjcode, t.id
    ");
    $gradesStmt->execute(array_merge($termIds, [$student['id'], (int)$syFilter]));
    $grades = $gradesStmt->fetchAll();

    $enrolledCountStmt = db()->prepare("
        SELECT COUNT(DISTINCT subject_id) FROM tbl_enroll
        WHERE student_id = ? AND sy_id = ? AND status != 'dropped'
    ");
    $enrolledCountStmt->execute([$student['id'], (int)$syFilter]);
    $totalEnrolled = (int)$enrolledCountStmt->fetchColumn();

    if ($studentIndex % 2 === 0) {
        $pdf->AddPage();
    } else {
        $pdf->Ln(3);
        $pdf->SetDrawColor(150, 150, 150);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Ln(3);
    }

    $pdf->StudentGradeReport($student, $grades, $syName, $isCollege, $totalEnrolled);
    $studentIndex++;
}

if ($studentFilter) {
    $name = formatPersonName($studentsList[0]);
    $filename = 'Grade_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $name) . '_' . date('Ymd') . '.pdf';
} else {
    $filename = 'Grade_Report_Section_' . preg_replace('/[^a-zA-Z0-9]/', '_', $studentsList[0]['section_code'] ?? '') . '_' . date('Ymd') . '.pdf';
}
$pdf->Output('I', $filename);
