<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../libs/fpdf.php';
requireRole('student');

$studentId = $_SESSION['student_id'] ?? 0;
if (!$studentId) {
    die('Unauthorized');
}

if (getSetting('grades_visible', '0') !== '1') {
    die('Grades are not yet available for viewing.');
}

// Get student info
$studentStmt = db()->prepare("
    SELECT st.*, sec.section_code, sec.sy_id, at.code as course_code, at.`desc` as course_name,
           at.enrollment_type, d.code as dept_code, lv.code as level_code
    FROM tbl_student st
    JOIN tbl_section sec ON st.section_id = sec.id
    JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN tbl_departments d ON at.dept_id = d.id
    LEFT JOIN level lv ON sec.level_id = lv.id
    WHERE st.id = ?
");
$studentStmt->execute([$studentId]);
$studentInfo = $studentStmt->fetch();

if (!$studentInfo) {
    die('Student not found');
}

$isCollege = in_array(strtoupper($studentInfo['dept_code'] ?? ''), ['CCTE', 'CON']);
$isYearly = ($studentInfo['enrollment_type'] ?? 'semestral') === 'yearly';
$isShsDept = strtoupper($studentInfo['dept_code'] ?? '') === 'SHS';

// Get school year (from query param or student's section)
$syId = (int)($_GET['sy_id'] ?? $studentInfo['sy_id'] ?? 0);

// Verify student has enrollments in this SY
$syCheck = db()->prepare("SELECT COUNT(*) FROM tbl_enroll WHERE student_id = ? AND sy_id = ?");
$syCheck->execute([$studentId, $syId]);
if ($syCheck->fetchColumn() == 0) {
    die('No enrollments found for this school year');
}

// Get SY name
$syStmt = db()->prepare("SELECT sy_name FROM tbl_sy WHERE id = ?");
$syStmt->execute([$syId]);
$syName = $syStmt->fetchColumn() ?: '';

// Extended FPDF class
class StudentGradeReportPDF extends FPDF {
    protected $schoolName = 'School';
    protected $reportTitle = 'STUDENT GRADE REPORT';
    protected $termName = '';
    
    function setTermName($name) {
        $this->termName = $name;
    }
    
    function Header() {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 8, $this->schoolName, 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Address Line 1, City, Country', 0, 1, 'C');
        $this->Cell(0, 5, 'Tel: (123) 456-7890 | Email: info@school.edu', 0, 1, 'C');
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, $this->reportTitle, 0, 1, 'C');
        if ($this->termName) {
            $this->SetFont('Arial', '', 11);
            $this->Cell(0, 6, $this->termName, 0, 1, 'C');
        }
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetX(10);
        $this->Cell(0, 10, 'Generated: ' . date('F d, Y h:i A'), 0, 0, 'L');
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
    
    function StudentInfo($student, $isCollege = true) {
        $this->SetFont('Arial', '', 10);
        $this->Cell(30, 6, 'Student Name:', 0, 0);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(70, 6, formatPersonName($student), 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->Cell(25, 6, 'Section:', 0, 0);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 6, $student['section_code'], 0, 1);
        
        $this->SetFont('Arial', '', 10);
        $label = $isCollege ? 'Course:' : 'Level:';
        $this->Cell(30, 6, $label, 0, 0);
        $this->SetFont('Arial', 'B', 10);
        if ($isCollege) {
            $this->Cell(70, 6, $student['course_code'] . ' - ' . $student['course_name'], 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->Cell(25, 6, 'Year/Level:', 0, 0);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 6, $student['level_code'] ?? 'N/A', 0, 1);
        } else {
            $this->Cell(70, 6, $student['level_code'] ?? 'N/A', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->Cell(25, 6, 'Date:', 0, 0);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 6, date('F d, Y'), 0, 1);
        }

        if ($isCollege) {
            $this->SetFont('Arial', '', 10);
            $this->Cell(30, 6, 'Date:', 0, 0);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 6, date('F d, Y'), 0, 1);
        }
        $this->Ln(5);
    }
    
    function K12GradeTable($subjects, $allComplete) {
        $quarterLabels = ['Q1', 'Q2', 'Q3', 'Q4'];
        $qCount = 4;
        $qW = 18;
        $laW = 190 - ($qW * $qCount) - 28 - 30;
        $fgW = 28;
        $rmW = 30;
        
        // Header
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 9);
        
        $y = $this->GetY();
        $x0 = 10;
        
        $this->SetXY($x0, $y);
        $this->Cell($laW, 14, 'Learning Areas', 1, 0, 'C', true);
        $this->Cell($qW * $qCount, 7, 'Quarter', 1, 0, 'C', true);
        $this->Cell($fgW, 14, 'Final Grade', 1, 0, 'C', true);
        $this->Cell($rmW, 14, 'Remarks', 1, 0, 'C', true);
        
        $this->SetXY($x0 + $laW, $y + 7);
        foreach ($quarterLabels as $ql) {
            $this->Cell($qW, 7, $ql, 1, 0, 'C', true);
        }
        $this->SetXY($x0, $y + 14);
        
        // Data
        $this->SetFont('Arial', '', 9);
        $allFinals = [];
        
        foreach ($subjects as $subj) {
            // Calculate row height for wrapping subject name
            $nbL = $this->NbLines($laW, $subj['subject_name']);
            $h = max(7, $nbL * 4.5);
            $y0 = $this->GetY(); $x0 = 10;
            
            // Subject name with wrapping
            $this->SetXY($x0, $y0);
            $this->MultiCell($laW, $h / $nbL, $subj['subject_name'], 0, 'L');
            $this->Rect($x0, $y0, $laW, $h);
            
            // Quarter grades
            $this->SetXY($x0 + $laW, $y0);
            $qGrades = [];
            foreach ($quarterLabels as $ql) {
                if (isset($subj['quarters'][$ql]) && $subj['quarters'][$ql] !== null) {
                    $this->Cell($qW, $h, number_format($subj['quarters'][$ql], 0), 1, 0, 'C');
                    $qGrades[] = $subj['quarters'][$ql];
                } else {
                    $this->Cell($qW, $h, '-', 1, 0, 'C');
                }
            }
            
            // Final grade: only if all 4 quarters present
            $finalGrade = count($qGrades) === $qCount ? round(array_sum($qGrades) / $qCount, 0) : null;
            
            $this->SetFont('Arial', 'B', 9);
            if ($finalGrade !== null) {
                $allFinals[] = $finalGrade;
                $this->Cell($fgW, $h, number_format($finalGrade, 0), 1, 0, 'C');
                $this->SetFont('Arial', '', 9);
                $this->Cell($rmW, $h, $finalGrade >= 75 ? 'PASSED' : 'FAILED', 1, 0, 'C');
            } else {
                $this->Cell($fgW, $h, '-', 1, 0, 'C');
                $this->SetFont('Arial', '', 9);
                $this->Cell($rmW, $h, '', 1, 0, 'C');
            }
            $this->SetXY($x0, $y0 + $h);
        }
        
        // General Average
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $colSpan = $laW + $qW * $qCount;
        
        if ($allComplete && count($allFinals) > 0 && count($allFinals) === count($subjects)) {
            $genAvg = round(array_sum($allFinals) / count($allFinals), 0);
            $this->Cell($colSpan, 8, 'General Average', 1, 0, 'L', true);
            $this->Cell($fgW, 8, $genAvg, 1, 0, 'C', true);
            $this->Cell($rmW, 8, $genAvg >= 75 ? 'PASSED' : 'FAILED', 1, 1, 'C', true);
        } else {
            $this->Cell($colSpan, 8, 'General Average', 1, 0, 'L', true);
            $this->Cell($fgW, 8, 'N/A', 1, 0, 'C', true);
            $this->Cell($rmW, 8, 'Incomplete', 1, 1, 'C', true);
        }
        
        // Grading Scale
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 5, 'Grading Scale:', 0, 1);
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 4, 'Outstanding: 90-100  |  Very Satisfactory: 85-89  |  Satisfactory: 80-84  |  Fairly Satisfactory: 75-79  |  Did Not Meet Expectations: Below 75', 0, 1);
    }
    
    function ShsGradeTable($gradesByTerm, $termCompleteness) {
        foreach ($gradesByTerm as $termName => $termGrades) {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 7, $termName, 0, 1, 'L');
            $this->Ln(2);
            
            $this->SetFillColor(240, 240, 240);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(60, 8, 'Learning Areas', 1, 0, 'C', true);
            $this->Cell(25, 8, 'Q1', 1, 0, 'C', true);
            $this->Cell(25, 8, 'Q2', 1, 0, 'C', true);
            $this->Cell(30, 8, 'Final Grade', 1, 0, 'C', true);
            $this->Cell(30, 8, 'Remarks', 1, 1, 'C', true);
            
            $this->SetFont('Arial', '', 9);
            $termTotal = 0;
            $termCount = 0;
            $isComplete = $termCompleteness[$termName] ?? false;
            
            foreach ($termGrades as $g) {
                $q1 = $g['q1_grade'];
                $q2 = $g['q2_grade'];
                $allQ = ($q1 !== null && $q2 !== null);
                $finalGrade = $allQ ? round(($q1 + $q2) / 2, 0) : null;
                
                $label = $g['subject_name'];
                $subW = 60;
                $nbL = $this->NbLines($subW, $label);
                $h = max(7, $nbL * 4.5);
                $y0 = $this->GetY(); $x0 = 10;
                
                $this->SetXY($x0, $y0);
                $this->MultiCell($subW, $h / $nbL, $label, 0, 'L');
                $this->Rect($x0, $y0, $subW, $h);
                $this->SetXY($x0 + $subW, $y0);
                $this->Cell(25, $h, $q1 !== null ? number_format($q1, 0) : '-', 1, 0, 'C');
                $this->Cell(25, $h, $q2 !== null ? number_format($q2, 0) : '-', 1, 0, 'C');
                
                $this->SetFont('Arial', 'B', 9);
                if ($finalGrade !== null) {
                    $termTotal += $finalGrade;
                    $termCount++;
                    $this->Cell(30, $h, number_format($finalGrade, 0), 1, 0, 'C');
                    $this->SetFont('Arial', '', 9);
                    $this->Cell(30, $h, $finalGrade >= 75 ? 'PASSED' : 'FAILED', 1, 0, 'C');
                } else {
                    $this->Cell(30, $h, '-', 1, 0, 'C');
                    $this->SetFont('Arial', '', 9);
                    $this->Cell(30, $h, '', 1, 0, 'C');
                }
                $this->SetXY($x0, $y0 + $h);
            }
            
            // Term Average
            $this->SetFont('Arial', 'B', 10);
            $this->SetFillColor(230, 230, 230);
            if ($isComplete && $termCount > 0) {
                $termAvg = round($termTotal / $termCount, 0);
                $this->Cell(110, 8, 'Term Average', 1, 0, 'L', true);
                $this->Cell(30, 8, $termAvg, 1, 0, 'C', true);
                $this->Cell(30, 8, $termAvg >= 75 ? 'PASSED' : 'FAILED', 1, 1, 'C', true);
            } else {
                $this->Cell(110, 8, 'Term Average', 1, 0, 'L', true);
                $this->Cell(30, 8, 'N/A', 1, 0, 'C', true);
                $this->Cell(30, 8, 'Incomplete', 1, 1, 'C', true);
            }
            $this->Ln(5);
        }
    }
    
    function CollegeGradeTable($gradesByTerm, $termCompleteness) {
        foreach ($gradesByTerm as $termName => $termGrades) {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 7, $termName, 0, 1, 'L');
            $this->Ln(2);
            
            $this->SetFillColor(240, 240, 240);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(75, 8, 'Subject', 1, 0, 'C', true);
            $this->Cell(15, 8, 'Units', 1, 0, 'C', true);
            $this->Cell(20, 8, 'Prelim', 1, 0, 'C', true);
            $this->Cell(20, 8, 'Mid', 1, 0, 'C', true);
            $this->Cell(20, 8, 'Semi', 1, 0, 'C', true);
            $this->Cell(20, 8, 'Final', 1, 0, 'C', true);
            $this->Cell(20, 8, 'Avg', 1, 1, 'C', true);
            
            $this->SetFont('Arial', '', 9);
            $isComplete = $termCompleteness[$termName] ?? false;
            $totalWeighted = 0;
            $totalUnits = 0;
            
            foreach ($termGrades as $g) {
                $p = $g['prelim_grade'];
                $m = $g['midterm_grade'];
                $s = $g['semifinal_grade'];
                $f = $g['final_grade'];
                $unit = $g['unit'] ?? 0;
                $allPresent = ($p !== null && $m !== null && $s !== null && $f !== null);
                $avg = $allPresent ? round(($p + $m + $s + $f) / 4, 2) : null;
                
                $subW = 75;
                $nbL = $this->NbLines($subW, $g['subject_name']);
                $h = max(7, $nbL * 4.5);
                $y0 = $this->GetY(); $x0 = 10;
                
                $this->SetXY($x0, $y0);
                $this->MultiCell($subW, $h / $nbL, $g['subject_name'], 0, 'L');
                $this->Rect($x0, $y0, $subW, $h);
                $this->SetXY($x0 + $subW, $y0);
                $this->Cell(15, $h, $unit, 1, 0, 'C');
                $this->Cell(20, $h, $p !== null ? number_format($p, 2) : '-', 1, 0, 'C');
                $this->Cell(20, $h, $m !== null ? number_format($m, 2) : '-', 1, 0, 'C');
                $this->Cell(20, $h, $s !== null ? number_format($s, 2) : '-', 1, 0, 'C');
                $this->Cell(20, $h, $f !== null ? number_format($f, 2) : '-', 1, 0, 'C');
                
                $this->SetFont('Arial', 'B', 9);
                if ($avg !== null) {
                    $totalWeighted += $avg * $unit;
                    $totalUnits += $unit;
                    $this->Cell(20, $h, number_format($avg, 2), 1, 0, 'C');
                } else {
                    $this->Cell(20, $h, '-', 1, 0, 'C');
                }
                $this->SetFont('Arial', '', 9);
                $this->SetXY($x0, $y0 + $h);
            }
            
            // GWA
            $this->SetFont('Arial', 'B', 10);
            $this->SetFillColor(230, 230, 230);
            if ($isComplete && $totalUnits > 0) {
                $gwa = round($totalWeighted / $totalUnits, 2);
                $this->Cell(170, 8, 'GWA', 1, 0, 'L', true);
                $this->Cell(20, 8, number_format($gwa, 2), 1, 1, 'C', true);
            } else {
                $this->Cell(170, 8, 'GWA', 1, 0, 'L', true);
                $this->Cell(20, 8, 'N/A', 1, 1, 'C', true);
            }
            $this->Ln(5);
        }
    }
    
    function SignatureSection() {
        $this->Ln(15);
        $this->SetFont('Arial', '', 10);
        $this->Cell(60, 5, '________________________', 0, 0, 'C');
        $this->Cell(60, 5, '________________________', 0, 0, 'C');
        $this->Cell(60, 5, '________________________', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(60, 5, 'Class Adviser', 0, 0, 'C');
        $this->Cell(60, 5, 'Registrar', 0, 0, 'C');
        $this->Cell(60, 5, 'Principal', 0, 1, 'C');
    }
}

// Create PDF
$pdf = new StudentGradeReportPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->setTermName('School Year: ' . $syName);
$pdf->AddPage();
$pdf->StudentInfo($studentInfo, $isCollege);

if ($isYearly) {
    // K-10: Quarterly grades
    $stmt = db()->prepare("
        SELECT e.id as enroll_id, sub.subjcode, sub.`desc` as subject_name,
               MAX(CASE WHEN g.grading_period = 'Q1' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q1_grade,
               MAX(CASE WHEN g.grading_period = 'Q2' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q2_grade,
               MAX(CASE WHEN g.grading_period = 'Q3' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q3_grade,
               MAX(CASE WHEN g.grading_period = 'Q4' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q4_grade
        FROM tbl_enroll e
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id AND g.status IN ('approved', 'finalized')
        WHERE e.student_id = ? AND e.sy_id = ?
        GROUP BY e.id, sub.subjcode, sub.`desc`
        ORDER BY sub.subjcode
    ");
    $stmt->execute([$studentId, $syId]);
    $grades = $stmt->fetchAll();
    
    // Build subject data for PDF
    $subjects = [];
    $allComplete = !empty($grades);
    foreach ($grades as $g) {
        $quarters = [];
        $complete = true;
        foreach (['Q1' => 'q1_grade', 'Q2' => 'q2_grade', 'Q3' => 'q3_grade', 'Q4' => 'q4_grade'] as $label => $key) {
            if ($g[$key] !== null) {
                $quarters[$label] = $g[$key];
            } else {
                $complete = false;
            }
        }
        if (!$complete) $allComplete = false;
        
        $subjects[] = [
            'subjcode' => $g['subjcode'],
            'subject_name' => $g['subject_name'],
            'quarters' => $quarters,
        ];
    }
    
    $pdf->K12GradeTable($subjects, $allComplete);
    
} elseif ($isShsDept) {
    // SHS: semestral with Q1/Q2
    $stmt = db()->prepare("
        SELECT e.id as enroll_id, sub.subjcode, sub.`desc` as subject_name,
               t.term_name, t.id as term_id,
               MAX(CASE WHEN g.grading_period = 'Q1' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q1_grade,
               MAX(CASE WHEN g.grading_period = 'Q2' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q2_grade
        FROM tbl_enroll e
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id AND g.status IN ('approved', 'finalized')
        LEFT JOIN tbl_term t ON g.term_id = t.id
        WHERE e.student_id = ? AND e.sy_id = ?
        GROUP BY e.id, sub.subjcode, sub.`desc`, t.term_name, t.id
        ORDER BY t.id, sub.subjcode
    ");
    $stmt->execute([$studentId, $syId]);
    $grades = $stmt->fetchAll();
    
    $gradesByTerm = [];
    foreach ($grades as $g) {
        $tn = $g['term_name'] ?? 'Ungraded';
        $gradesByTerm[$tn][] = $g;
    }
    
    $termCompleteness = [];
    foreach ($gradesByTerm as $tName => $tGrades) {
        $termCompleteness[$tName] = true;
        foreach ($tGrades as $g) {
            if ($g['q1_grade'] === null || $g['q2_grade'] === null) {
                $termCompleteness[$tName] = false;
                break;
            }
        }
    }
    
    $pdf->ShsGradeTable($gradesByTerm, $termCompleteness);
    
} else {
    // College: 4 grading periods per term
    $stmt = db()->prepare("
        SELECT e.id as enroll_id, sub.subjcode, sub.`desc` as subject_name, sub.unit,
               t.term_name, t.id as term_id,
               MAX(CASE WHEN g.grading_period = 'PRELIM' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as prelim_grade,
               MAX(CASE WHEN g.grading_period = 'MIDTERM' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as midterm_grade,
               MAX(CASE WHEN g.grading_period = 'SEMIFINAL' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as semifinal_grade,
               MAX(CASE WHEN g.grading_period = 'FINAL' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as final_grade
        FROM tbl_enroll e
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id AND g.status IN ('approved', 'finalized')
        LEFT JOIN tbl_term t ON g.term_id = t.id
        WHERE e.student_id = ? AND e.sy_id = ?
        GROUP BY e.id, sub.subjcode, sub.`desc`, sub.unit, t.term_name, t.id
        ORDER BY t.id, sub.subjcode
    ");
    $stmt->execute([$studentId, $syId]);
    $grades = $stmt->fetchAll();
    
    $gradesByTerm = [];
    foreach ($grades as $g) {
        $tn = $g['term_name'] ?? 'Ungraded';
        $gradesByTerm[$tn][] = $g;
    }
    
    $termCompleteness = [];
    foreach ($gradesByTerm as $tName => $tGrades) {
        $termCompleteness[$tName] = true;
        foreach ($tGrades as $g) {
            if ($g['prelim_grade'] === null || $g['midterm_grade'] === null ||
                $g['semifinal_grade'] === null || $g['final_grade'] === null) {
                $termCompleteness[$tName] = false;
                break;
            }
        }
    }
    
    $pdf->CollegeGradeTable($gradesByTerm, $termCompleteness);
}

$pdf->SignatureSection();

$filename = 'Grade_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', formatPersonName($studentInfo)) . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $filename);
