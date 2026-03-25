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

// Get student info (K-12 only - determined dynamically via education_level)
$stmt = db()->prepare("
    SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name,
           d.code as dept_code, d.description as dept_name, at.enrollment_type,
           at.id as track_id, d.education_level
    FROM tbl_student st
    JOIN tbl_section sec ON st.section_id = sec.id
    JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    JOIN tbl_departments d ON at.dept_id = d.id
    WHERE st.id = ? AND d.education_level = 'k12'
");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    die('Student not found or not a K-12 student');
}

// Get ALL approved grades for this student across ALL terms/school years
$gradesStmt = db()->prepare("
    SELECT g.id, g.period_grade, g.grading_period, g.status,
           e.id as enroll_id, e.sy_id, e.subject_id, e.section_id,
           sub.id as sub_id, sub.subjcode, sub.`desc` as subject_name, sub.unit,
           t.id as term_id, t.term_name,
           sy.sy_name,
           sec.section_code as enroll_section,
           at.code as enroll_track_code, at.`desc` as enroll_track_desc,
           at.enrollment_type as enroll_type,
           d.code as enroll_dept_code
    FROM tbl_grades g
    JOIN tbl_enroll e ON g.enroll_id = e.id
    JOIN tbl_subjects sub ON e.subject_id = sub.id
    JOIN tbl_term t ON g.term_id = t.id
    LEFT JOIN tbl_sy sy ON t.sy_id = sy.id
    LEFT JOIN tbl_section sec ON e.section_id = sec.id
    LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN tbl_departments d ON at.dept_id = d.id
    WHERE e.student_id = ? AND g.status = 'approved'
    ORDER BY sy.sy_name, at.code, sub.subjcode, g.grading_period
");
$gradesStmt->execute([$studentId]);
$allGrades = $gradesStmt->fetchAll();

// Determine record title based on department
function getRecordTitle($deptCode) {
    switch ($deptCode) {
        case 'PRE-ELEM': return "LEARNER'S PERMANENT ACADEMIC RECORD";
        case 'ELEM': return "LEARNER'S PERMANENT ACADEMIC RECORD FOR ELEMENTARY";
        case 'JHS': return "LEARNER'S PERMANENT ACADEMIC RECORD FOR JUNIOR HIGH SCHOOL";
        case 'SHS': return "LEARNER'S PERMANENT ACADEMIC RECORD FOR SENIOR HIGH SCHOOL";
        default: return "LEARNER'S PERMANENT ACADEMIC RECORD";
    }
}

// Grade level display name from track code
function gradeLevelName($trackCode) {
    $map = [
        'NURSERY' => 'Nursery', 'KINDER1' => 'Kinder 1', 'KINDER2' => 'Kinder 2',
        'GRADE1' => 'Grade 1', 'GRADE2' => 'Grade 2', 'GRADE3' => 'Grade 3',
        'GRADE4' => 'Grade 4', 'GRADE5' => 'Grade 5', 'GRADE6' => 'Grade 6',
        'GRADE7' => 'Grade 7', 'GRADE8' => 'Grade 8', 'GRADE9' => 'Grade 9',
        'GRADE10' => 'Grade 10',
    ];
    if (isset($map[$trackCode])) return $map[$trackCode];
    // SHS tracks: STEM11 -> Grade 11 (STEM), ABM12 -> Grade 12 (ABM)
    if (preg_match('/^([A-Z]+)(\d+)$/', $trackCode, $m)) {
        return 'Grade ' . $m[2] . ' (' . $m[1] . ')';
    }
    return $trackCode;
}

// Sort order for grade levels
function gradeLevelOrder($trackCode) {
    $order = [
        'NURSERY' => 1, 'KINDER1' => 2, 'KINDER2' => 3,
        'GRADE1' => 10, 'GRADE2' => 11, 'GRADE3' => 12,
        'GRADE4' => 13, 'GRADE5' => 14, 'GRADE6' => 15,
        'GRADE7' => 20, 'GRADE8' => 21, 'GRADE9' => 22, 'GRADE10' => 23,
    ];
    if (isset($order[$trackCode])) return $order[$trackCode];
    // SHS: extract grade number
    if (preg_match('/(\d+)$/', $trackCode, $m)) {
        return 30 + (int)$m[1];
    }
    return 99;
}

// Organize grades by grade level (track) > school year > subject > quarters
$organized = [];
foreach ($allGrades as $g) {
    $trackCode = $g['enroll_track_code'] ?? '';
    $syName = $g['sy_name'] ?? '';
    $section = $g['enroll_section'] ?? '';
    $enrollType = $g['enroll_type'] ?? 'yearly';
    $subjCode = $g['subjcode'] ?? '';
    $subjName = $g['subject_name'] ?? '';
    $period = $g['grading_period'] ?? '';
    $grade = (float)$g['period_grade'];
    
    // For semestral tracks (SHS), group by track + semester
    $groupKey = $trackCode . '_' . $syName;
    if ($enrollType === 'semestral') {
        $termName = $g['term_name'] ?? '';
        $semLabel = '';
        if (stripos($termName, '1') !== false || stripos($termName, 'first') !== false) {
            $semLabel = '1st Semester';
        } elseif (stripos($termName, '2') !== false || stripos($termName, 'second') !== false) {
            $semLabel = '2nd Semester';
        }
        if ($semLabel) {
            $groupKey .= '_' . $semLabel;
        }
    }
    
    if (!isset($organized[$groupKey])) {
        $organized[$groupKey] = [
            'track_code' => $trackCode,
            'track_desc' => $g['enroll_track_desc'] ?? '',
            'dept_code' => $g['enroll_dept_code'] ?? '',
            'sy_name' => $syName,
            'section' => $section,
            'enroll_type' => $enrollType,
            'semester' => isset($semLabel) && $semLabel ? $semLabel : '',
            'subjects' => [],
        ];
    }
    
    $subjKey = $subjCode;
    if (!isset($organized[$groupKey]['subjects'][$subjKey])) {
        $organized[$groupKey]['subjects'][$subjKey] = [
            'subjcode' => $subjCode,
            'subject_name' => $subjName,
            'quarters' => [],
        ];
    }
    
    $organized[$groupKey]['subjects'][$subjKey]['quarters'][$period] = $grade;
}

// Sort by grade level order then SY
uasort($organized, function($a, $b) {
    $oa = gradeLevelOrder($a['track_code']);
    $ob = gradeLevelOrder($b['track_code']);
    if ($oa !== $ob) return $oa - $ob;
    return strcmp($a['sy_name'], $b['sy_name']);
});

// Form 137 PDF Class
class Form137PDF extends FPDF {
    protected $schoolName = 'School';
    protected $schoolAddress = 'Address Line 1, City, Country';
    
    function Header() {
        // Empty - we use custom header
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 4, 'Page ' . $this->PageNo() . ' of {nb}', 0, 1, 'C');
    }
    
    function Form137Header($title) {
        // Republic of the Philippines
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, 'Republic of the Philippines', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 4, 'Department of Education', 0, 1, 'C');
        
        // School name
        $this->SetFont('Arial', 'B', 11);
        $this->Ln(1);
        $this->Cell(0, 5, strtoupper($this->schoolName), 0, 1, 'C');
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 3, $this->schoolAddress, 0, 1, 'C');
        
        // Horizontal line
        $this->Ln(2);
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->Ln(3);
        
        // Title
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 5, $title, 0, 1, 'C');
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 3, '(DepEd Form 137)', 0, 1, 'C');
        $this->Ln(3);
    }
    
    function StudentInfoBlock($student) {
        $lm = 10;
        $pageW = 190;
        
        // LRN (student_no)
        $this->SetXY($lm, $this->GetY());
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(10, 5, 'LRN:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(50, 5, $student['student_no'] ?? '', 'B', 1);
        
        // Name + Date of Birth + Sex
        $y = $this->GetY() + 1;
        $this->SetXY($lm, $y);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(12, 5, 'Name:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $lastName = strtoupper($student['last_name'] ?? '');
        $firstName = strtoupper($student['given_name'] ?? '');
        $middleName = strtoupper($student['middle_name'] ?? '');
        $suffix = !empty($student['suffix']) ? ' ' . strtoupper($student['suffix']) : '';
        $this->Cell(75, 5, trim($lastName . ', ' . $firstName . ' ' . $middleName . $suffix), 'B', 0);
        
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(22, 5, 'Date of Birth:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $dob = !empty($student['date_of_birth']) ? date('F d, Y', strtotime($student['date_of_birth'])) : '';
        $this->Cell(42, 5, $dob, 'B', 0);
        
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(10, 5, 'Sex:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(19, 5, $student['sex'] ?? '', 'B', 1);
        
        // Place of Birth
        $y = $this->GetY() + 1;
        $this->SetXY($lm, $y);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(22, 5, 'Place of Birth:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell($pageW - 22, 5, $student['place_of_birth'] ?? '', 'B', 1);
        
        // Parent or Guardian + Address
        $y = $this->GetY() + 1;
        $this->SetXY($lm, $y);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(28, 5, 'Parent/Guardian:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(60, 5, $student['guardian_name'] ?? '', 'B', 0);
        
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(16, 5, 'Address:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell($pageW - 104, 5, $student['address'] ?? '', 'B', 1);
        
        $this->Ln(3);
    }
    
    function ElementarySchoolBlock($student) {
        $lm = 10;
        $y = $this->GetY();
        $this->SetXY($lm, $y);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(38, 5, 'Elem. School Attended:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $elemSchool = $student['primary_school'] ?? '';
        if (!empty($student['intermediate_school'])) {
            $elemSchool = $student['intermediate_school'];
        }
        $this->Cell(78, 5, $elemSchool, 'B', 0);
        
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(8, 5, 'S.Y.:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $elemYear = $student['primary_school_year'] ?? '';
        if (!empty($student['intermediate_school_year'])) {
            $elemYear = $student['intermediate_school_year'];
        }
        $this->Cell(30, 5, $elemYear, 'B', 0);
        
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(16, 5, 'Gen. Ave.:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(10, 5, '', 'B', 1);
        
        $this->Ln(4);
    }
    
    function GradeLevelHeader($levelName, $syName, $section, $semester = '') {
        if ($this->GetY() > 230) {
            $this->AddPage();
        }
        
        $lm = 10;
        
        // "Classified as [Grade Level]" bar
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(220, 220, 220);
        $headerText = 'Classified as ' . $levelName;
        if ($semester) {
            $headerText .= ' - ' . $semester;
        }
        $this->Cell(190, 5, $headerText, 1, 1, 'L', true);
        
        // School, S.Y., Year and Sec
        $y = $this->GetY();
        $this->SetXY($lm, $y);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(12, 4, 'School:', 0, 0);
        $this->SetFont('Arial', '', 7);
        $this->Cell(88, 4, $this->schoolName, 'B', 0);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(8, 4, 'S.Y.:', 0, 0);
        $this->SetFont('Arial', '', 7);
        $this->Cell(32, 4, $syName, 'B', 0);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(20, 4, 'Year & Sec:', 0, 0);
        $this->SetFont('Arial', '', 7);
        $this->Cell(30, 4, $section, 'B', 1);
        
        $this->Ln(1);
    }
    
    function GradeTableHeader($isSemestral = false) {
        $lm = 10;
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(240, 240, 240);
        
        $x0 = $lm;
        $y0 = $this->GetY();
        
        if ($isSemestral) {
            // Semestral format: Subject | Midterm | Finals | Final Rating | Action Taken
            $this->SetXY($x0, $y0);
            $this->Cell(75, 8, 'SUBJECTS', 1, 0, 'C', true);
            
            $periodicX = $this->GetX();
            $this->Cell(50, 4, 'PERIODIC RATINGS', 1, 0, 'C', true);
            
            $this->Cell(25, 8, 'FINAL RATING', 1, 0, 'C', true);
            $this->Cell(40, 8, 'ACTION TAKEN', 1, 0, 'C', true);
            
            // Sub-headers for periodic ratings
            $this->SetXY($periodicX, $y0 + 4);
            $this->SetFont('Arial', '', 6);
            $this->Cell(25, 4, 'Midterm', 1, 0, 'C', true);
            $this->Cell(25, 4, 'Finals', 1, 0, 'C', true);
        } else {
            // Quarterly format: Subject | Q1 | Q2 | Q3 | Q4 | Final Rating | Action Taken
            $this->SetXY($x0, $y0);
            $this->Cell(65, 8, 'SUBJECTS', 1, 0, 'C', true);
            
            $periodicX = $this->GetX();
            $this->Cell(60, 4, 'PERIODIC RATINGS', 1, 0, 'C', true);
            
            $this->Cell(25, 8, 'FINAL RATING', 1, 0, 'C', true);
            $this->Cell(40, 8, 'ACTION TAKEN', 1, 0, 'C', true);
            
            // Sub-headers for quarters
            $this->SetXY($periodicX, $y0 + 4);
            $this->SetFont('Arial', '', 6);
            $this->Cell(15, 4, '1st', 1, 0, 'C', true);
            $this->Cell(15, 4, '2nd', 1, 0, 'C', true);
            $this->Cell(15, 4, '3rd', 1, 0, 'C', true);
            $this->Cell(15, 4, '4th', 1, 0, 'C', true);
        }
        
        $this->SetXY($x0, $y0 + 8);
    }
    
    function GradeTableRow($subjName, $quarters, $isSemestral = false) {
        $lm = 10;
        $y0 = $this->GetY();
        
        if ($y0 > 265) {
            $this->AddPage();
            $y0 = $this->GetY();
        }
        
        $this->SetFont('Arial', '', 7);
        $h = 5;
        
        if ($isSemestral) {
            // Subject name
            $this->SetXY($lm, $y0);
            $this->Cell(75, $h, $this->truncStr($subjName, 75), 1, 0, 'L');
            
            // Midterm (Q1) / Finals (Q2)
            $q1 = $quarters['Q1'] ?? '';
            $q2 = $quarters['Q2'] ?? '';
            $this->Cell(25, $h, $q1 !== '' ? number_format($q1, 0) : '', 1, 0, 'C');
            $this->Cell(25, $h, $q2 !== '' ? number_format($q2, 0) : '', 1, 0, 'C');
            
            // Final Rating (average of available quarters)
            $validGrades = array_filter([$q1, $q2], fn($v) => $v !== '');
            $finalRating = !empty($validGrades) ? array_sum($validGrades) / count($validGrades) : 0;
        } else {
            // Subject name
            $this->SetXY($lm, $y0);
            $this->Cell(65, $h, $this->truncStr($subjName, 65), 1, 0, 'L');
            
            // Q1-Q4
            $q1 = $quarters['Q1'] ?? '';
            $q2 = $quarters['Q2'] ?? '';
            $q3 = $quarters['Q3'] ?? '';
            $q4 = $quarters['Q4'] ?? '';
            $this->Cell(15, $h, $q1 !== '' ? number_format($q1, 0) : '', 1, 0, 'C');
            $this->Cell(15, $h, $q2 !== '' ? number_format($q2, 0) : '', 1, 0, 'C');
            $this->Cell(15, $h, $q3 !== '' ? number_format($q3, 0) : '', 1, 0, 'C');
            $this->Cell(15, $h, $q4 !== '' ? number_format($q4, 0) : '', 1, 0, 'C');
            
            // Final Rating (average of available quarters)
            $validGrades = array_filter([$q1, $q2, $q3, $q4], fn($v) => $v !== '');
            $finalRating = !empty($validGrades) ? array_sum($validGrades) / count($validGrades) : 0;
        }
        
        // Final Rating
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(25, $h, $finalRating > 0 ? number_format($finalRating, 2) : '', 1, 0, 'C');
        
        // Action Taken
        $this->SetFont('Arial', '', 7);
        $action = '';
        if ($finalRating > 0) {
            $action = $finalRating >= 75 ? 'PASSED' : 'FAILED';
        }
        $this->Cell(40, $h, $action, 1, 1, 'C');
        
        return $finalRating;
    }
    
    function GeneralAverageRow($subjectFinals, $isSemestral = false) {
        $lm = 10;
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(235, 235, 235);
        
        $validFinals = array_filter($subjectFinals, fn($v) => $v > 0);
        $genAve = !empty($validFinals) ? array_sum($validFinals) / count($validFinals) : 0;
        
        if ($isSemestral) {
            $this->Cell(75, 5, 'General Average', 1, 0, 'R', true);
            $this->Cell(50, 5, '', 1, 0, 'C', true);
        } else {
            $this->Cell(65, 5, 'General Average', 1, 0, 'R', true);
            $this->Cell(60, 5, '', 1, 0, 'C', true);
        }
        
        $this->Cell(25, 5, $genAve > 0 ? number_format($genAve, 2) : '', 1, 0, 'C', true);
        $passed = $genAve >= 75;
        $this->Cell(40, 5, $genAve > 0 ? ($passed ? 'PROMOTED' : 'RETAINED') : '', 1, 1, 'C', true);
        
        return $genAve;
    }
    
    function AttendanceBlock() {
        $lm = 10;
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 6);
        $this->SetFillColor(240, 240, 240);
        
        $months = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr'];
        $labelW = 30;
        $monthW = (190 - $labelW) / count($months);
        
        // Header
        $this->Cell($labelW, 4, '', 1, 0, 'C', true);
        foreach ($months as $m) {
            $this->Cell($monthW, 4, $m, 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Days of School
        $this->SetFont('Arial', '', 6);
        $this->Cell($labelW, 4, 'No. of School Days', 1, 0, 'L');
        foreach ($months as $m) {
            $this->Cell($monthW, 4, '', 1, 0, 'C');
        }
        $this->Ln();
        
        // Days Present
        $this->Cell($labelW, 4, 'No. of Days Present', 1, 0, 'L');
        foreach ($months as $m) {
            $this->Cell($monthW, 4, '', 1, 0, 'C');
        }
        $this->Ln();
    }
    
    function ClassificationBlock() {
        $lm = 10;
        $this->Ln(2);
        $this->SetFont('Arial', '', 7);
        $this->Cell(190, 4, 'Has advanced units in: ___________________________________________', 0, 1, 'L');
        $this->Cell(190, 4, 'Lacks units in: ___________________________________________', 0, 1, 'L');
        $this->Cell(190, 4, 'To be classified as: ___________________________________________', 0, 1, 'L');
        $this->Ln(3);
    }
    
    function SignatureBlock() {
        if ($this->GetY() > 235) {
            $this->AddPage();
        }
        
        $this->Ln(3);
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->Ln(3);
        
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 4, 'I CERTIFY that this is a true record of the student whose name appears above.', 0, 1, 'L');
        $this->Ln(2);
        
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 4, 'Date Issued: ' . date('F d, Y'), 0, 1, 'L');
        
        $this->Ln(12);
        
        $this->Cell(63, 5, '________________________________', 0, 0, 'C');
        $this->Cell(64, 5, '________________________________', 0, 0, 'C');
        $this->Cell(63, 5, '________________________________', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(63, 4, 'Class Adviser', 0, 0, 'C');
        $this->Cell(64, 4, 'Principal / School Head', 0, 0, 'C');
        $this->Cell(63, 4, 'Registrar', 0, 1, 'C');
        
        $this->Ln(6);
        $this->SetFont('Arial', 'I', 6);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, '*** Nothing Follows ***', 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
    }
    
    function truncStr($text, $cellW) {
        $maxChars = (int)($cellW / 1.6);
        if (strlen($text) > $maxChars) {
            return substr($text, 0, $maxChars - 2) . '..';
        }
        return $text;
    }
}

// === Generate PDF ===
$pdf = new Form137PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

// Header
$recordTitle = getRecordTitle($student['dept_code'] ?? '');
$pdf->Form137Header($recordTitle);

// Student Info
$pdf->StudentInfoBlock($student);

// Elementary School Attended (for secondary students)
if (in_array($student['dept_code'], ['JHS', 'SHS'])) {
    $pdf->ElementarySchoolBlock($student);
}

// Grade Level Sections
if (empty($organized)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Ln(5);
    $pdf->Cell(0, 8, 'No approved academic records found for this student.', 0, 1, 'C');
    $pdf->Ln(5);
} else {
    foreach ($organized as $key => $data) {
        $levelName = gradeLevelName($data['track_code']);
        $syName = $data['sy_name'];
        $section = $data['section'];
        $isSemestral = $data['enroll_type'] === 'semestral';
        $semester = $data['semester'] ?? '';
        
        // Grade Level Header
        $pdf->GradeLevelHeader($levelName, $syName, $section, $semester);
        
        // Grade Table
        $pdf->GradeTableHeader($isSemestral);
        
        // Subject Rows
        $subjectFinals = [];
        foreach ($data['subjects'] as $subj) {
            $final = $pdf->GradeTableRow($subj['subject_name'], $subj['quarters'], $isSemestral);
            $subjectFinals[] = $final;
        }
        
        // General Average
        $pdf->GeneralAverageRow($subjectFinals, $isSemestral);
        
        // Attendance (placeholder)
        $pdf->AttendanceBlock();
        
        // Classification
        $pdf->ClassificationBlock();
    }
}

// Signature Block
$pdf->SignatureBlock();

// Output
$filename = 'Form137_' . preg_replace('/[^a-zA-Z0-9]/', '_', formatPersonName($student)) . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $filename);
