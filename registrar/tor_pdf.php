<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../libs/fpdf.php';
requireRole('registrar');

$studentId = (int)($_GET['student'] ?? 0);
if (!$studentId) {
    die('Student ID is required');
}

// Get student info (college only - determined dynamically via education_level)
$stmt = db()->prepare("
    SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name,
           d.code as dept_code, d.description as dept_name, at.enrollment_type,
           at.id as track_id, d.education_level
    FROM tbl_student st
    JOIN tbl_section sec ON st.section_id = sec.id
    JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    JOIN tbl_departments d ON at.dept_id = d.id
    WHERE st.id = ? AND d.education_level = 'college'
");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    die('Student not found or not a college student');
}

// Get ALL approved grades for this student across ALL terms/school years
$gradesStmt = db()->prepare("
    SELECT g.id, g.period_grade, g.grading_period, g.status,
           e.id as enroll_id, e.sy_id, e.subject_id, e.section_id,
           sub.id as sub_id, sub.subjcode, sub.`desc` as subject_name, sub.unit, sub.lec_u, sub.lab_u, sub.lec_h, sub.lab_h,
           t.id as term_id, t.term_name,
           sy.sy_name,
           sec.section_code as enroll_section,
           sec.level_id as section_level_id, lv.`order` as level_order,
           at.code as enroll_track_code,
           teach.name as teacher_name
    FROM tbl_grades g
    JOIN tbl_enroll e ON g.enroll_id = e.id
    JOIN tbl_subjects sub ON e.subject_id = sub.id
    JOIN tbl_term t ON g.term_id = t.id
    LEFT JOIN tbl_sy sy ON t.sy_id = sy.id
    LEFT JOIN tbl_section sec ON e.section_id = sec.id
    LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN tbl_teacher teach ON g.teacher_id = teach.id
    LEFT JOIN level lv ON sec.level_id = lv.id
    WHERE e.student_id = ? AND g.status = 'approved'
    ORDER BY sy.sy_name, t.id, sub.subjcode
");
$gradesStmt->execute([$studentId]);
$allGrades = $gradesStmt->fetchAll();

// Derive year level from level.order or fallback to section code regex (e.g., BSCS-1A => 1)
function getYearLevel($sectionCode, $levelOrder = null) {
    if ($levelOrder !== null && $levelOrder > 0) {
        return (int)$levelOrder;
    }
    if (preg_match('/-(\d+)/', $sectionCode, $m)) {
        return (int)$m[1];
    }
    return 1;
}

// Semester label from term name
function getSemesterLabel($termName) {
    $lower = strtolower($termName);
    if (strpos($lower, 'summer') !== false) return 'Summer';
    if (strpos($lower, '1') !== false || strpos($lower, 'first') !== false) return 'First Semester';
    if (strpos($lower, '2') !== false || strpos($lower, 'second') !== false) return 'Second Semester';
    return $termName;
}

// Year level ordinal
function yearLevelLabel($y) {
    $labels = [1 => 'FIRST YEAR', 2 => 'SECOND YEAR', 3 => 'THIRD YEAR', 4 => 'FOURTH YEAR', 5 => 'FIFTH YEAR'];
    return $labels[$y] ?? "YEAR $y";
}

// Convert raw percentage to college grade point equivalent (1.00 - 5.00 scale)
function percentageToGradeEquiv(float $pct): float {
    if ($pct >= 99) return 1.00;
    if ($pct >= 96) return 1.25;
    if ($pct >= 93) return 1.50;
    if ($pct >= 90) return 1.75;
    if ($pct >= 87) return 2.00;
    if ($pct >= 84) return 2.25;
    if ($pct >= 81) return 2.50;
    if ($pct >= 78) return 2.75;
    if ($pct >= 75) return 3.00;
    return 5.00; // Below 75 = Failed
}

// Get descriptive remark from grade equivalent
function gradeEquivRemark(float $equiv): string {
    if ($equiv <= 3.00) return 'PASSED';
    if ($equiv == 5.00) return 'FAILED';
    return 'INC';
}

// Organize grades: Year Level > Semester > subjects (consolidate period grades per subject)
$organized = [];
foreach ($allGrades as $g) {
    $yearLevel = getYearLevel($g['enroll_section'] ?? '', $g['level_order'] ?? null);
    $semLabel = getSemesterLabel($g['term_name']);
    $key = $yearLevel . '_' . $semLabel;
    
    if (!isset($organized[$key])) {
        $organized[$key] = [
            'year_level' => $yearLevel,
            'semester' => $semLabel,
            'sy_name' => $g['sy_name'] ?? '',
            'subjects' => [],
        ];
    }
    
    // Consolidate multiple grading periods into one subject entry
    $subjKey = $g['subjcode'];
    if (!isset($organized[$key]['subjects'][$subjKey])) {
        $organized[$key]['subjects'][$subjKey] = [
            'subjcode' => $g['subjcode'],
            'subject_name' => $g['subject_name'],
            'unit' => $g['unit'],
            'lec_u' => $g['lec_u'],
            'lab_u' => $g['lab_u'],
            'lec_h' => $g['lec_h'],
            'lab_h' => $g['lab_h'],
            'teacher_name' => $g['teacher_name'],
            'period_grades' => [],
        ];
    }
    $organized[$key]['subjects'][$subjKey]['period_grades'][$g['grading_period'] ?? ''] = (float)$g['period_grade'];
}

// Compute final grade per subject (average of all period grades) and build flat grades array
foreach ($organized as $key => &$data) {
    $grades = [];
    foreach ($data['subjects'] as $subj) {
        $periodGrades = $subj['period_grades'];
        $finalGrade = !empty($periodGrades) ? array_sum($periodGrades) / count($periodGrades) : 0;
        $grades[] = [
            'subjcode' => $subj['subjcode'],
            'subject_name' => $subj['subject_name'],
            'unit' => $subj['unit'],
            'lec_u' => $subj['lec_u'],
            'lab_u' => $subj['lab_u'],
            'lec_h' => $subj['lec_h'],
            'lab_h' => $subj['lab_h'],
            'teacher_name' => $subj['teacher_name'],
            'period_grade' => round($finalGrade, 2),
        ];
    }
    $data['grades'] = $grades;
    unset($data['subjects']);
}
unset($data);

// Sort by year level then semester order
uasort($organized, function($a, $b) {
    if ($a['year_level'] !== $b['year_level']) return $a['year_level'] - $b['year_level'];
    $order = ['First Semester' => 1, 'Second Semester' => 2, 'Summer' => 3];
    return ($order[$a['semester']] ?? 9) - ($order[$b['semester']] ?? 9);
});

// TOR PDF Class
class TORPDF extends FPDF {
    protected $schoolName = 'School';
    protected $schoolAddress = 'Address Line 1, City, Country';
    protected $schoolContact = 'Tel: (123) 456-7890 | Email: info@school.edu';
    
    function Header() {
        // Empty - we use custom TORHeader
    }
    
    function Footer() {
        $this->SetY(-18);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 4, 'Page ' . $this->PageNo() . ' of {nb}', 0, 1, 'C');
        $this->SetFont('Arial', '', 6);
        $this->Cell(0, 3, 'THIS DOCUMENT IS NOT VALID WITHOUT THE SCHOOL DRY SEAL AND AUTHORIZED SIGNATURE', 0, 0, 'C');
    }
    
    function TORHeader() {
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(0, 6, $this->schoolName, 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, $this->schoolAddress, 0, 1, 'C');
        $this->Cell(0, 4, $this->schoolContact, 0, 1, 'C');
        $this->Ln(2);
        
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 7, 'OFFICIAL TRANSCRIPT OF RECORDS', 0, 1, 'C');
        
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->SetLineWidth(0.2);
        $this->Ln(4);
    }
    
    function StudentInfoSection($student) {
        $lm = 10;
        $leftW = 95;
        $rightW = 95;
        
        // Row 1: Name & Student Number
        $y = $this->GetY();
        $this->SetXY($lm, $y);
        $this->SetFont('Arial', '', 8);
        $this->Cell(18, 5, 'Name:', 0, 0);
        $this->SetFont('Arial', 'B', 9);
        $lastName = strtoupper($student['last_name'] ?? '');
        $firstName = strtoupper($student['given_name'] ?? '');
        $middleName = strtoupper($student['middle_name'] ?? '');
        $suffix = !empty($student['suffix']) ? ' ' . strtoupper($student['suffix']) : '';
        $fullName = $lastName . ', ' . $firstName . ' ' . $middleName . $suffix;
        $this->Cell($leftW - 18, 5, trim($fullName), 'B', 0);
        
        $this->SetFont('Arial', '', 8);
        $this->Cell(22, 5, 'Student No.:', 0, 0);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($rightW - 22, 5, $student['student_no'] ?? '', 'B', 1);
        
        // Row 2: Course & Department
        $this->SetXY($lm, $this->GetY() + 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(18, 5, 'Course:', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell($leftW - 18, 5, ($student['course_code'] ?? '') . ' - ' . ($student['course_name'] ?? ''), 'B', 0);
        
        $this->SetFont('Arial', '', 8);
        $this->Cell(22, 5, 'Department:', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell($rightW - 22, 5, $student['dept_name'] ?? '', 'B', 1);
        
        // Row 3: Date of Birth & Sex
        $this->SetXY($lm, $this->GetY() + 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(18, 5, 'Birthdate:', 0, 0);
        $this->SetFont('Arial', '', 9);
        $dob = !empty($student['date_of_birth']) ? date('F d, Y', strtotime($student['date_of_birth'])) : '';
        $this->Cell($leftW - 18, 5, $dob, 'B', 0);
        
        $this->SetFont('Arial', '', 8);
        $this->Cell(22, 5, 'Sex:', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell($rightW - 22, 5, $student['sex'] ?? '', 'B', 1);
        
        // Row 4: Place of Birth
        $this->SetXY($lm, $this->GetY() + 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(18, 5, 'Birthplace:', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell(172, 5, $student['place_of_birth'] ?? '', 'B', 1);
        
        // Row 5: Address
        $this->SetXY($lm, $this->GetY() + 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(18, 5, 'Address:', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell(172, 5, $student['address'] ?? '', 'B', 1);
        
        // Row 6: Guardian
        $this->SetXY($lm, $this->GetY() + 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(18, 5, 'Guardian:', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell($leftW - 18, 5, $student['guardian_name'] ?? '', 'B', 0);
        
        $this->SetFont('Arial', '', 8);
        $this->Cell(22, 5, 'Contact:', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->Cell($rightW - 22, 5, $student['guardian_contact'] ?? '', 'B', 1);
        
        $this->Ln(4);
    }
    
    function EducationBgSection($student) {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(220, 220, 220);
        $this->Cell(190, 5, 'EDUCATIONAL BACKGROUND', 1, 1, 'C', true);
        
        // Table header
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(42, 5, 'Level', 1, 0, 'C', true);
        $this->Cell(60, 5, 'School', 1, 0, 'C', true);
        $this->Cell(58, 5, 'Address', 1, 0, 'C', true);
        $this->Cell(30, 5, 'S.Y. Attended', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 7);
        
        // Show only education rows that have data (depends on Form 137)
        $rows = [];
        if (!empty($student['primary_school'])) {
            $rows[] = ['Primary (Grades 1-3)', $student['primary_school'], $student['primary_school_address'] ?? '', $student['primary_school_year'] ?? ''];
        }
        if (!empty($student['intermediate_school'])) {
            $rows[] = ['Intermediate (Grades 4-6)', $student['intermediate_school'], $student['intermediate_school_address'] ?? '', $student['intermediate_school_year'] ?? ''];
        }
        if (!empty($student['secondary_school'])) {
            $rows[] = ['High School (Grades 7-10)', $student['secondary_school'], $student['secondary_school_address'] ?? '', $student['secondary_school_year'] ?? ''];
        }
        if (!empty($student['shs_school'])) {
            $label = 'Senior High School';
            if (!empty($student['shs_strand'])) {
                $label .= ' (' . $student['shs_strand'] . ')';
            }
            $rows[] = [$label, $student['shs_school'], $student['shs_school_address'] ?? '', $student['shs_school_year'] ?? ''];
        }
        
        if (empty($rows)) {
            $this->SetFont('Arial', 'I', 7);
            $this->Cell(190, 5, 'No education background records on file', 1, 1, 'C');
        } else {
            foreach ($rows as $row) {
                $this->Cell(42, 5, $row[0], 1, 0, 'L');
                $this->Cell(60, 5, $this->truncStr($row[1], 60), 1, 0, 'L');
                $this->Cell(58, 5, $this->truncStr($row[2], 58), 1, 0, 'L');
                $this->Cell(30, 5, $row[3], 1, 1, 'C');
            }
        }
        
        $this->Ln(4);
    }
    
    function SemesterHeader($yearLabel, $semesterLabel, $syName) {
        if ($this->GetY() > 245) {
            $this->AddPage();
            $this->TORHeader();
        }
        
        // Year-Semester title bar
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(210, 210, 210);
        $headerText = $yearLabel . ' - ' . $semesterLabel;
        if ($syName) $headerText .= '  (S.Y. ' . $syName . ')';
        $this->Cell(190, 6, $headerText, 1, 1, 'C', true);
        
        // Column headers with Units sub-headers
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(240, 240, 240);
        
        $x0 = 10;
        $y0 = $this->GetY();
        
        // Row 1
        $this->SetXY($x0, $y0);
        $this->Cell(22, 10, 'Subject Code', 1, 0, 'C', true);
        $this->Cell(73, 10, 'Descriptive Title', 1, 0, 'C', true);
        
        // "Units" header spanning 3 sub-columns
        $unitsX = $this->GetX();
        $this->Cell(36, 5, 'Units', 1, 0, 'C', true);
        
        $this->Cell(25, 10, 'Final Grade', 1, 0, 'C', true);
        $this->Cell(34, 10, 'Remarks', 1, 0, 'C', true);
        
        // Row 2: Units sub-headers
        $this->SetXY($unitsX, $y0 + 5);
        $this->SetFont('Arial', '', 6);
        $this->Cell(12, 5, 'Lec', 1, 0, 'C', true);
        $this->Cell(12, 5, 'Lab', 1, 0, 'C', true);
        $this->Cell(12, 5, 'Credit', 1, 0, 'C', true);
        
        $this->SetXY($x0, $y0 + 10);
    }
    
    function GradeRow($g) {
        $y0 = $this->GetY();
        
        if ($y0 > 260) {
            $this->AddPage();
            $this->TORHeader();
            $y0 = $this->GetY();
        }
        
        $lec = (int)($g['lec_u'] ?? 0);
        $lab = (int)($g['lab_u'] ?? 0);
        $credit = (int)($g['unit'] ?? ($lec + $lab));
        $rawGrade = (float)$g['period_grade'];
        $gradeEquiv = percentageToGradeEquiv($rawGrade);
        $passed = $gradeEquiv <= 3.00;
        
        $this->SetFont('Arial', '', 7);
        $nbL = $this->NbLines(73, $g['subject_name']);
        $h = max(5, $nbL * 3.5);
        $x0 = 10;
        
        // Subject Code
        $this->SetXY($x0, $y0);
        $this->Cell(22, $h, $g['subjcode'], 0, 0, 'C');
        $this->Rect($x0, $y0, 22, $h);
        
        // Descriptive Title
        $this->SetXY($x0 + 22, $y0);
        $this->MultiCell(73, $h / max($nbL, 1), $g['subject_name'], 0, 'L');
        $this->Rect($x0 + 22, $y0, 73, $h);
        
        // Lec
        $this->SetXY($x0 + 95, $y0);
        $this->Cell(12, $h, $lec > 0 ? $lec : '-', 0, 0, 'C');
        $this->Rect($x0 + 95, $y0, 12, $h);
        
        // Lab
        $this->SetXY($x0 + 107, $y0);
        $this->Cell(12, $h, $lab > 0 ? $lab : '-', 0, 0, 'C');
        $this->Rect($x0 + 107, $y0, 12, $h);
        
        // Credit
        $this->SetXY($x0 + 119, $y0);
        $this->Cell(12, $h, $credit > 0 ? $credit : '-', 0, 0, 'C');
        $this->Rect($x0 + 119, $y0, 12, $h);
        
        // Final Grade (Grade Equivalent)
        $this->SetFont('Arial', 'B', 7);
        $this->SetXY($x0 + 131, $y0);
        $this->Cell(25, $h, number_format($gradeEquiv, 2), 0, 0, 'C');
        $this->Rect($x0 + 131, $y0, 25, $h);
        
        // Remarks
        $this->SetFont('Arial', '', 7);
        $this->SetXY($x0 + 156, $y0);
        $this->Cell(34, $h, gradeEquivRemark($gradeEquiv), 0, 0, 'C');
        $this->Rect($x0 + 156, $y0, 34, $h);
        
        $this->SetXY($x0, $y0 + $h);
        
        return ['lec' => $lec, 'lab' => $lab, 'credit' => $credit, 'grade' => $gradeEquiv, 'weighted' => $gradeEquiv * $credit];
    }
    
    function SemesterSummary($totals) {
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(235, 235, 235);
        
        $totalCredit = $totals['credit'];
        $gwa = $totalCredit > 0 ? $totals['weighted'] / $totalCredit : 0;
        
        $this->Cell(22, 5, '', 1, 0, 'C', true);
        $this->Cell(73, 5, 'TOTAL / GWA', 1, 0, 'R', true);
        $this->Cell(12, 5, $totals['lec'], 1, 0, 'C', true);
        $this->Cell(12, 5, $totals['lab'], 1, 0, 'C', true);
        $this->Cell(12, 5, $totalCredit, 1, 0, 'C', true);
        $this->Cell(25, 5, number_format($gwa, 2), 1, 0, 'C', true);
        $this->Cell(34, 5, $gwa <= 3.00 ? 'PASSED' : '', 1, 1, 'C', true);
        
        $this->Ln(4);
        return $gwa;
    }
    
    function CumulativeSummary($cumTotals) {
        if ($this->GetY() > 252) {
            $this->AddPage();
            $this->TORHeader();
        }
        
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(200, 200, 200);
        
        $totalCredit = $cumTotals['credit'];
        $cumGWA = $totalCredit > 0 ? $cumTotals['weighted'] / $totalCredit : 0;
        
        $this->Cell(95, 7, 'CUMULATIVE SUMMARY', 1, 0, 'L', true);
        $this->Cell(12, 7, $cumTotals['lec'], 1, 0, 'C', true);
        $this->Cell(12, 7, $cumTotals['lab'], 1, 0, 'C', true);
        $this->Cell(12, 7, $totalCredit, 1, 0, 'C', true);
        $this->Cell(25, 7, number_format($cumGWA, 2), 1, 0, 'C', true);
        $this->Cell(34, 7, $cumGWA <= 3.00 ? 'PASSED' : 'FAILED', 1, 1, 'C', true);
        
        $this->Ln(2);
        $this->SetFont('Arial', '', 8);
        $this->Cell(95, 5, 'Total Units Earned:', 0, 0, 'R');
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(20, 5, $totalCredit, 0, 1, 'L');
        $this->SetFont('Arial', '', 8);
        $this->Cell(95, 5, 'Cumulative GWA:', 0, 0, 'R');
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(20, 5, number_format($cumGWA, 2), 0, 1, 'L');
    }
    
    function GradeEquivalentTable() {
        // Check if we need a new page (table is ~55mm tall)
        if ($this->GetY() > 210) {
            $this->AddPage();
            $this->TORHeader();
        }
        
        $this->Ln(4);
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(210, 210, 210);
        $this->Cell(190, 5, 'GRADE EQUIVALENT TABLE', 1, 1, 'C', true);
        
        // Table headers
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(240, 240, 240);
        $colW = 190 / 3; // 3 columns across
        
        // Two-column layout: left side = grade table, right side = legend
        $startY = $this->GetY();
        $startX = 10;
        $tblW = 70; // width for each of two sub-tables
        $c1 = 25; // grade equiv col
        $c2 = 45; // percentage range col
        
        // Left table header
        $this->SetXY($startX, $startY);
        $this->Cell($c1, 5, 'Grade', 1, 0, 'C', true);
        $this->Cell($c2, 5, 'Percentage Range', 1, 0, 'C', true);
        
        // Right table header
        $rightX = $startX + $tblW + 5;
        $this->SetXY($rightX, $startY);
        $this->Cell($c1, 5, 'Grade', 1, 0, 'C', true);
        $this->Cell($c2, 5, 'Percentage Range', 1, 0, 'C', true);
        
        // Grade data
        $grades = [
            ['1.00', '99 - 100'],
            ['1.25', '96 - 98'],
            ['1.50', '93 - 95'],
            ['1.75', '90 - 92'],
            ['2.00', '87 - 89'],
            ['2.25', '84 - 86'],
            ['2.50', '81 - 83'],
            ['2.75', '78 - 80'],
            ['3.00', '75 - 77'],
            ['5.00', 'Below 75 (Failed)'],
            ['INC', 'Incomplete'],
            ['DRP', 'Dropped'],
        ];
        
        $this->SetFont('Arial', '', 7);
        $half = (int)ceil(count($grades) / 2);
        $rowH = 4.5;
        
        for ($i = 0; $i < $half; $i++) {
            $y = $startY + 5 + ($i * $rowH);
            
            // Left column
            $this->SetXY($startX, $y);
            $this->SetFont('Arial', 'B', 7);
            $this->Cell($c1, $rowH, $grades[$i][0], 1, 0, 'C');
            $this->SetFont('Arial', '', 7);
            $this->Cell($c2, $rowH, $grades[$i][1], 1, 0, 'C');
            
            // Right column
            $ri = $i + $half;
            if ($ri < count($grades)) {
                $this->SetXY($rightX, $y);
                $this->SetFont('Arial', 'B', 7);
                $this->Cell($c1, $rowH, $grades[$ri][0], 1, 0, 'C');
                $this->SetFont('Arial', '', 7);
                $this->Cell($c2, $rowH, $grades[$ri][1], 1, 0, 'C');
            }
        }
        
        $this->SetXY($startX, $startY + 5 + ($half * $rowH));
        $this->Ln(2);
    }
    
    function SignatureBlock() {
        if ($this->GetY() > 235) {
            $this->AddPage();
            $this->TORHeader();
        }
        
        $this->Ln(5);
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->Ln(3);
        
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 4, 'I hereby certify that this is a true and correct record of the academic performance of the student named herein.', 0, 1, 'L');
        $this->Ln(2);
        
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, 'Date Issued: ' . date('F d, Y'), 0, 1, 'L');
        $this->Cell(0, 4, 'Purpose: ________________________________________________', 0, 1, 'L');
        
        $this->Ln(12);
        
        $this->Cell(63, 5, '________________________________', 0, 0, 'C');
        $this->Cell(64, 5, '________________________________', 0, 0, 'C');
        $this->Cell(63, 5, '________________________________', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(63, 4, 'Prepared by', 0, 0, 'C');
        $this->Cell(64, 4, 'Registrar', 0, 0, 'C');
        $this->Cell(63, 4, 'School President', 0, 1, 'C');
        
        $this->Ln(8);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, '*** Nothing Follows ***', 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
    }
    
    function truncStr($text, $cellW) {
        $maxChars = (int)($cellW / 1.8);
        if (strlen($text) > $maxChars) {
            return substr($text, 0, $maxChars - 2) . '..';
        }
        return $text;
    }
    
    function NbLines($w, $txt) {
        if (!isset($this->CurrentFont)) return 1;
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) { if ($i == $j) $i++; } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }
}

// === Generate PDF ===
$pdf = new TORPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 22);
$pdf->AddPage();

// Header
$pdf->TORHeader();

// Student Info
$pdf->StudentInfoSection($student);

// Education Background from Form 137
$pdf->EducationBgSection($student);

// Academic Records Header
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(180, 180, 180);
$pdf->Cell(190, 6, 'ACADEMIC RECORDS', 1, 1, 'C', true);
$pdf->Ln(2);

// Output grades grouped by Year Level > Semester
$cumulativeTotals = ['lec' => 0, 'lab' => 0, 'credit' => 0, 'weighted' => 0];

if (empty($organized)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Ln(5);
    $pdf->Cell(0, 8, 'No approved academic records found for this student.', 0, 1, 'C');
    $pdf->Ln(5);
} else {
    foreach ($organized as $key => $data) {
        $yearLabel = yearLevelLabel($data['year_level']);
        $semLabel = $data['semester'];
        $syName = $data['sy_name'];
        $grades = $data['grades'];
        
        // Semester header with column headers
        $pdf->SemesterHeader($yearLabel, $semLabel, $syName);
        
        // Grade rows
        $semTotals = ['lec' => 0, 'lab' => 0, 'credit' => 0, 'weighted' => 0];
        foreach ($grades as $g) {
            $row = $pdf->GradeRow($g);
            $semTotals['lec'] += $row['lec'];
            $semTotals['lab'] += $row['lab'];
            $semTotals['credit'] += $row['credit'];
            $semTotals['weighted'] += $row['weighted'];
        }
        
        // Semester summary
        $pdf->SemesterSummary($semTotals);
        
        // Accumulate
        $cumulativeTotals['lec'] += $semTotals['lec'];
        $cumulativeTotals['lab'] += $semTotals['lab'];
        $cumulativeTotals['credit'] += $semTotals['credit'];
        $cumulativeTotals['weighted'] += $semTotals['weighted'];
    }
    
    // Cumulative Summary
    $pdf->CumulativeSummary($cumulativeTotals);
}

// Grade Equivalent Reference Table
$pdf->GradeEquivalentTable();

// Signature block
$pdf->SignatureBlock();

// Output
$filename = 'TOR_' . preg_replace('/[^a-zA-Z0-9]/', '_', formatPersonName($student)) . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $filename);
