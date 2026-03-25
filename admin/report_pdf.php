<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../libs/fpdf.php';
requireRole('admin');

// Get parameters
$termFilter = $_GET['term'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$studentFilter = $_GET['student'] ?? '';
$reportType = $_GET['type'] ?? 'student'; // student or section

if (!$termFilter) {
    die('Term is required');
}

// Resolve term filter to array of term IDs (supports "All Quarters" combined views)
$termIds = [];
$isMultiTerm = false;
$termDisplayName = '';

if (is_string($termFilter) && str_starts_with($termFilter, 'all_q_')) {
    $syId = (int) substr($termFilter, 6);
    $qtStmt = db()->prepare("SELECT t.id, t.term_name, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.sy_id = ? AND t.term_name LIKE 'Semester%' AND t.status = 'active' ORDER BY t.id");
    $qtStmt->execute([$syId]);
    $qtTerms = $qtStmt->fetchAll();
    $termIds = array_column($qtTerms, 'id');
    $isMultiTerm = count($termIds) > 1;
    $termDisplayName = 'All Quarters Q1-Q4 (' . ($qtTerms[0]['sy_name'] ?? '') . ')';
} elseif (is_string($termFilter) && str_starts_with($termFilter, 'all_s_')) {
    $syId = (int) substr($termFilter, 6);
    $stStmt = db()->prepare("SELECT t.id, t.term_name, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.sy_id = ? AND t.term_name LIKE 'Semester%' AND t.status = 'active' ORDER BY t.id");
    $stStmt->execute([$syId]);
    $sTerms = $stStmt->fetchAll();
    $termIds = array_column($sTerms, 'id');
    $isMultiTerm = count($termIds) > 1;
    $termDisplayName = 'All Semesters Q1-Q2 (' . ($sTerms[0]['sy_name'] ?? '') . ')';
} elseif ($termFilter) {
    $termIds = [(int)$termFilter];
}

if (empty($termIds)) {
    die('Invalid term selection');
}

$termPlaceholders = implode(',', array_fill(0, count($termIds), '?'));

// Get term info for display
if ($isMultiTerm) {
    $term = ['term_name' => $termDisplayName, 'sy_name' => ''];
} else {
    $termStmt = db()->prepare("SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.id = ?");
    $termStmt->execute([$termIds[0]]);
    $term = $termStmt->fetch();
}

// Extended FPDF class for grade reports
class GradeReportPDF extends FPDF {
    protected $schoolName = 'School';
    protected $reportTitle = 'GRADE REPORT';
    protected $termName = '';
    
    function setTermName($name) {
        $this->termName = $name;
    }
    
    function Header() {
        // School Logo placeholder
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
    
    function GradeTable($grades, $isCollege = true, $isMultiTerm = false) {
        // Header
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 9);
        if ($isCollege) {
            $this->Cell(80, 8, 'Subject', 1, 0, 'C', true);
            if ($isMultiTerm) $this->Cell(22, 8, 'Term', 1, 0, 'C', true);
            $this->Cell(20, 8, 'Units', 1, 0, 'C', true);
            $this->Cell(25, 8, 'Final Grade', 1, 0, 'C', true);
            $this->Cell($isMultiTerm ? 20 : 25, 8, 'Remarks', 1, 0, 'C', true);
            $this->Cell($isMultiTerm ? 23 : 25, 8, 'Teacher', 1, 1, 'C', true);
        } else {
            $this->Cell($isMultiTerm ? 80 : 100, 8, 'Subject', 1, 0, 'C', true);
            if ($isMultiTerm) $this->Cell(25, 8, 'Quarter', 1, 0, 'C', true);
            $this->Cell(30, 8, 'Final Grade', 1, 0, 'C', true);
            $this->Cell(30, 8, 'Remarks', 1, 0, 'C', true);
            $this->Cell(25, 8, 'Teacher', 1, 1, 'C', true);
        }
        
        // Data
        $this->SetFont('Arial', '', 9);
        $totalUnits = 0;
        $weightedSum = 0;
        $gradeSum = 0;
        $gradeCount = 0;
        
        foreach ($grades as $grade) {
            $totalUnits += $grade['unit'];
            $weightedSum += ($grade['period_grade'] * $grade['unit']);
            $gradeSum += $grade['period_grade'];
            $gradeCount++;
            $passed = $isCollege ? ($grade['period_grade'] <= 3.00) : ($grade['period_grade'] >= 75);
            $qLabel = $grade['grading_period'] ?? $grade['term_name'] ?? '';
            
            if ($isCollege) {
                $subW = 80;
                $nbL = $this->NbLines($subW, $grade['subject_name']);
                $h = max(7, $nbL * 4.5);
                $y0 = $this->GetY(); $x0 = 10;
                $this->SetXY($x0, $y0);
                $this->MultiCell($subW, $h / $nbL, $grade['subject_name'], 0, 'L');
                $this->Rect($x0, $y0, $subW, $h);
                $this->SetXY($x0 + $subW, $y0);
                if ($isMultiTerm) $this->Cell(22, $h, $qLabel, 1, 0, 'C');
                $this->Cell(20, $h, $grade['unit'], 1, 0, 'C');
                $this->Cell(25, $h, number_format($grade['period_grade'], 2), 1, 0, 'C');
                $this->Cell($isMultiTerm ? 20 : 25, $h, $passed ? 'PASSED' : 'FAILED', 1, 0, 'C');
                $this->Cell($isMultiTerm ? 23 : 25, $h, substr($grade['teacher_name'] ?? 'N/A', 0, 12), 1, 0, 'C');
                $this->SetXY($x0, $y0 + $h);
            } else {
                $subW = $isMultiTerm ? 80 : 100;
                $nbL = $this->NbLines($subW, $grade['subject_name']);
                $h = max(7, $nbL * 4.5);
                $y0 = $this->GetY(); $x0 = 10;
                $this->SetXY($x0, $y0);
                $this->MultiCell($subW, $h / $nbL, $grade['subject_name'], 0, 'L');
                $this->Rect($x0, $y0, $subW, $h);
                $this->SetXY($x0 + $subW, $y0);
                if ($isMultiTerm) $this->Cell(25, $h, $qLabel, 1, 0, 'C');
                $this->Cell(30, $h, number_format($grade['period_grade'], 0), 1, 0, 'C');
                $this->Cell(30, $h, $passed ? 'PASSED' : 'FAILED', 1, 0, 'C');
                $this->Cell(25, $h, substr($grade['teacher_name'] ?? 'N/A', 0, 12), 1, 0, 'C');
                $this->SetXY($x0, $y0 + $h);
            }
        }
        
        // Summary Row
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        if ($isCollege) {
            $gwa = $totalUnits > 0 ? $weightedSum / $totalUnits : 0;
            $sumW = $isMultiTerm ? 102 : 80;
            $this->Cell($sumW, 8, 'General Weighted Average (GWA)', 1, 0, 'L', true);
            // Note: sumW already accounts for removed Code column
            $this->Cell(20, 8, $totalUnits, 1, 0, 'C', true);
            $this->Cell(25, 8, number_format($gwa, 2), 1, 0, 'C', true);
            $remW = 190 - $sumW - 20 - 25;
            $this->Cell($remW, 8, ($isCollege ? ($gwa <= 3.00) : ($gwa >= 75)) ? 'PASSED' : 'FAILED', 1, 1, 'C', true);
        } else {
            $avg = $gradeCount > 0 ? $gradeSum / $gradeCount : 0;
            $sumW = $isMultiTerm ? 105 : 105;
            $this->Cell($sumW, 8, 'General Average', 1, 0, 'L', true);
            $this->Cell(30, 8, number_format($avg, 0), 1, 0, 'C', true);
            $remW = 190 - $sumW - 30;
            $this->Cell($remW, 8, $avg >= 75 ? 'PASSED' : 'FAILED', 1, 1, 'C', true);
        }
        
        return $isCollege ? ($totalUnits > 0 ? $weightedSum / $totalUnits : 0) : ($gradeCount > 0 ? $gradeSum / $gradeCount : 0);
    }
    
    function GradeTableK12($gradesBySubject, $quarterLabels, $allSubjectsComplete = false) {
        // Header
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 9);
        $qCount = count($quarterLabels);
        $qW = 18; // width per quarter column
        $laW = 190 - ($qW * $qCount) - 28 - 30; // Learning Areas width
        $fgW = 28; // Final Grade width
        $rmW = 30; // Remarks width
        
        $y = $this->GetY();
        $x0 = 10; // left margin
        
        // Row 1: Learning Areas (14h), Quarter group (7h), Final Grade (14h), Remarks (14h)
        $this->SetXY($x0, $y);
        $this->Cell($laW, 14, 'Learning Areas', 1, 0, 'C', true);
        $this->Cell($qW * $qCount, 7, 'Quarter', 1, 0, 'C', true);
        $this->Cell($fgW, 14, 'Final Grade', 1, 0, 'C', true);
        $this->Cell($rmW, 14, 'Remarks', 1, 0, 'C', true);
        
        // Row 2: individual quarter labels (below "Quarter" group header)
        $this->SetXY($x0 + $laW, $y + 7);
        foreach ($quarterLabels as $ql) {
            $this->Cell($qW, 7, $ql, 1, 0, 'C', true);
        }
        
        // Move cursor below full header
        $this->SetXY($x0, $y + 14);
        
        // Data rows
        $this->SetFont('Arial', '', 9);
        $allFinals = [];
        
        foreach ($gradesBySubject as $subj) {
            $finalGrade = $subj['final_grade'];
            $allFinals[] = $finalGrade;
            $passed = $finalGrade >= 75;
            
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
            foreach ($quarterLabels as $ql) {
                if (isset($subj['quarters'][$ql])) {
                    $this->Cell($qW, $h, number_format($subj['quarters'][$ql], 0), 1, 0, 'C');
                } else {
                    $this->Cell($qW, $h, '-', 1, 0, 'C');
                }
            }
            
            // Final Grade
            $this->SetFont('Arial', 'B', 9);
            $this->Cell($fgW, $h, $finalGrade > 0 ? number_format($finalGrade, 0) : '-', 1, 0, 'C');
            $this->SetFont('Arial', '', 9);
            
            // Remarks
            $this->Cell($rmW, $h, $finalGrade > 0 ? ($passed ? 'PASSED' : 'FAILED') : '', 1, 0, 'C');
            $this->SetXY($x0, $y0 + $h);
        }
        
        // General Average footer
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $genAvg = ($allSubjectsComplete && count($allFinals) > 0) ? round(array_sum($allFinals) / count($allFinals), 0) : 0;
        $colSpan = $laW + $qW * $qCount;
        $this->Cell($colSpan, 8, 'General Average', 1, 0, 'L', true);
        $this->Cell($fgW, 8, $allSubjectsComplete ? $genAvg : 'N/A', 1, 0, 'C', true);
        $this->Cell($rmW, 8, $allSubjectsComplete ? ($genAvg >= 75 ? 'PASSED' : 'FAILED') : 'Incomplete', 1, 1, 'C', true);
        
        // Grading Scale Legend
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 5, 'Grading Scale:', 0, 1);
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 4, 'Outstanding: 90-100  |  Very Satisfactory: 85-89  |  Satisfactory: 80-84  |  Fairly Satisfactory: 75-79  |  Did Not Meet Expectations: Below 75', 0, 1);
        
        return $genAvg;
    }
    
    function SignatureSection() {
        $this->Ln(20);
        $this->SetFont('Arial', '', 10);
        
        $y = $this->GetY();
        $this->Cell(60, 5, '________________________', 0, 0, 'C');
        $this->Cell(60, 5, '________________________', 0, 0, 'C');
        $this->Cell(60, 5, '________________________', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(60, 5, 'Class Adviser', 0, 0, 'C');
        $this->Cell(60, 5, 'Registrar', 0, 0, 'C');
        $this->Cell(60, 5, 'Principal', 0, 1, 'C');
    }
    
    function SectionTable($students, $isCollege = true) {
        // Header
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 9);
        if ($isCollege) {
            $this->Cell(10, 8, '#', 1, 0, 'C', true);
            $this->Cell(70, 8, 'Student Name', 1, 0, 'C', true);
            $this->Cell(25, 8, 'Subjects', 1, 0, 'C', true);
            $this->Cell(25, 8, 'Units', 1, 0, 'C', true);
            $this->Cell(30, 8, 'GWA', 1, 0, 'C', true);
            $this->Cell(30, 8, 'Remarks', 1, 1, 'C', true);
        } else {
            $this->Cell(10, 8, '#', 1, 0, 'C', true);
            $this->Cell(80, 8, 'Student Name', 1, 0, 'C', true);
            $this->Cell(25, 8, 'Subjects', 1, 0, 'C', true);
            $this->Cell(35, 8, 'Gen. Avg', 1, 0, 'C', true);
            $this->Cell(40, 8, 'Remarks', 1, 1, 'C', true);
        }
        
        // Data
        $this->SetFont('Arial', '', 9);
        $rank = 1;
        
        foreach ($students as $student) {
            $allPassed = true;
            if (!empty($student['subjects'])) {
                // K-12 multi-term: check final grades per subject
                foreach ($student['subjects'] as $subj) {
                    if ($isCollege ? ($subj['final_grade'] > 3.00) : ($subj['final_grade'] < 75)) {
                        $allPassed = false;
                        break;
                    }
                }
            } else {
                foreach ($student['grades'] as $g) {
                    if ($isCollege ? ($g['period_grade'] > 3.00) : ($g['period_grade'] < 75)) {
                        $allPassed = false;
                        break;
                    }
                }
            }
            
            $subjCount = $student['subject_count'] ?? count($student['grades']);
            
            if ($isCollege) {
                $this->Cell(10, 7, $rank++, 1, 0, 'C');
                $this->Cell(70, 7, $student['name'] ?? '', 1, 0, 'L');
                $this->Cell(25, 7, $subjCount, 1, 0, 'C');
                $this->Cell(25, 7, $student['total_units'], 1, 0, 'C');
                $this->Cell(30, 7, number_format($student['gwa'], 2), 1, 0, 'C');
                $this->Cell(30, 7, $allPassed ? 'PASSED' : 'FAILED', 1, 1, 'C');
            } else {
                $isIncomplete = !empty($student['subjects']) && empty($student['all_complete']);
                $this->Cell(10, 7, $rank++, 1, 0, 'C');
                $this->Cell(80, 7, $student['name'] ?? '', 1, 0, 'L');
                $this->Cell(25, 7, $subjCount, 1, 0, 'C');
                $this->Cell(35, 7, $isIncomplete ? 'N/A' : number_format($student['gwa'], 0), 1, 0, 'C');
                $this->Cell(40, 7, $isIncomplete ? 'Incomplete' : ($allPassed ? 'PASSED' : 'FAILED'), 1, 1, 'C');
            }
        }
    }
}

// Create PDF
$pdf = new GradeReportPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetTermName($isMultiTerm ? $termDisplayName : ($term['term_name'] . ' (' . ($term['sy_name'] ?? '') . ')'));

if ($studentFilter) {
    // Individual Student Report
    $studentStmt = db()->prepare("
        SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name,
               d.code as dept_code, lv.code as level_code
        FROM tbl_student st
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        LEFT JOIN level lv ON sec.level_id = lv.id
        WHERE st.id = ?
    ");
    $studentStmt->execute([$studentFilter]);
    $studentInfo = $studentStmt->fetch();
    
    if (!$studentInfo) {
        die('Student not found');
    }
    
    $isCollege = in_array(strtoupper($studentInfo['dept_code'] ?? ''), ['CCTE', 'CON']);
    
    $gradesStmt = db()->prepare("
        SELECT g.*, sub.subjcode, sub.`desc` as subject_name, sub.unit,
               t.term_name, teach.name as teacher_name
        FROM tbl_grades g
        JOIN tbl_enroll e ON g.enroll_id = e.id
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        JOIN tbl_term t ON g.term_id = t.id
        LEFT JOIN tbl_teacher teach ON g.teacher_id = teach.id
        WHERE g.term_id IN ($termPlaceholders) AND e.student_id = ?
        ORDER BY sub.subjcode, t.id
    ");
    $gradesStmt->execute(array_merge($termIds, [$studentFilter]));
    $grades = $gradesStmt->fetchAll();
    
    $pdf->AddPage();
    $pdf->StudentInfo($studentInfo, $isCollege);
    
    if ($isMultiTerm && !$isCollege) {
        // Build DepEd K-12 pivot: subjects as rows, quarters as columns
        $gradesBySubject = [];
        foreach ($grades as $g) {
            $subj = $g['subjcode'];
            if (!isset($gradesBySubject[$subj])) {
                $gradesBySubject[$subj] = [
                    'subjcode' => $subj,
                    'subject_name' => $g['subject_name'],
                    'unit' => $g['unit'],
                    'quarters' => [],
                ];
            }
            $qKey = $g['grading_period'] ?? $g['term_name'];
            $gradesBySubject[$subj]['quarters'][$qKey] = $g['period_grade'];
        }
        // Calculate final grade per subject + check completeness
        $expectedQ = str_starts_with($termFilter, 'all_q_') ? 4 : 2;
        $allSubjectsComplete = !empty($gradesBySubject);
        foreach ($gradesBySubject as &$subj) {
            $qg = array_values($subj['quarters']);
            $subj['final_grade'] = count($qg) > 0 ? round(array_sum($qg) / count($qg), 0) : 0;
            if (count($subj['quarters']) < $expectedQ) {
                $allSubjectsComplete = false;
            }
        }
        unset($subj);
        
        $quarterLabels = str_starts_with($termFilter, 'all_q_') ? ['Q1','Q2','Q3','Q4'] : ['Q1','Q2'];
        $pdf->GradeTableK12($gradesBySubject, $quarterLabels, $allSubjectsComplete);
    } else {
        $pdf->GradeTable($grades, $isCollege, $isMultiTerm);
    }
    
    $pdf->SignatureSection();
    
    $filename = 'Grade_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', formatPersonName($studentInfo)) . '_' . date('Ymd') . '.pdf';
    
} elseif ($sectionFilter) {
    // Section Report
    $sectionStmt = db()->prepare("SELECT s.*, at.code as track_code, d.code as dept_code FROM tbl_section s JOIN tbl_academic_track at ON s.academic_track_id = at.id LEFT JOIN tbl_departments d ON at.dept_id = d.id WHERE s.id = ?");
    $sectionStmt->execute([$sectionFilter]);
    $section = $sectionStmt->fetch();
    
    if (!$section) {
        die('Section not found');
    }
    
    $isSectionCollege = in_array(strtoupper($section['dept_code'] ?? ''), ['CCTE', 'CON']);
    
    $gradesStmt = db()->prepare("
        SELECT g.*, CONCAT_WS(' ', st.given_name, st.middle_name, st.last_name) as student_name, st.id as student_id,
               sub.subjcode, sub.`desc` as subject_name, sub.unit,
               t.term_name, sec.section_code
        FROM tbl_grades g
        JOIN tbl_enroll e ON g.enroll_id = e.id
        JOIN tbl_student st ON e.student_id = st.id
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        JOIN tbl_term t ON g.term_id = t.id
        JOIN tbl_section sec ON st.section_id = sec.id
        WHERE g.term_id IN ($termPlaceholders) AND sec.id = ?
        ORDER BY st.last_name, st.given_name, sub.subjcode
    ");
    $gradesStmt->execute(array_merge($termIds, [$sectionFilter]));
    $allGrades = $gradesStmt->fetchAll();
    
    // Group by student
    $gradesByStudent = [];
    foreach ($allGrades as $grade) {
        $sid = $grade['student_id'];
        if (!isset($gradesByStudent[$sid])) {
            $gradesByStudent[$sid] = [
                'name' => $grade['student_name'],
                'section' => $grade['section_code'],
                'grades' => [],
                'subjects' => [],
                'total_units' => 0,
                'weighted_sum' => 0
            ];
        }
        $gradesByStudent[$sid]['grades'][] = $grade;
        $gradesByStudent[$sid]['total_units'] += $grade['unit'];
        $gradesByStudent[$sid]['weighted_sum'] += ($grade['period_grade'] * $grade['unit']);
        
        // Track subjects for K-12 multi-term
        if ($isMultiTerm && !$isSectionCollege) {
            $subjCode = $grade['subjcode'];
            if (!isset($gradesByStudent[$sid]['subjects'][$subjCode])) {
                $gradesByStudent[$sid]['subjects'][$subjCode] = [
                    'name' => $grade['subject_name'],
                    'quarters' => [],
                ];
            }
            $gradesByStudent[$sid]['subjects'][$subjCode]['quarters'][] = $grade['period_grade'];
        }
    }
    
    // Calculate GWA
    $expectedQ = str_starts_with($termFilter, 'all_q_') ? 4 : 2;
    foreach ($gradesByStudent as &$student) {
        if ($isMultiTerm && !$isSectionCollege) {
            // K-12: final grade per subject = avg of quarter grades, gen avg = avg of subject finals
            $subjectFinals = [];
            $studentAllComplete = true;
            foreach ($student['subjects'] as &$subj) {
                $subj['final_grade'] = count($subj['quarters']) > 0
                    ? array_sum($subj['quarters']) / count($subj['quarters'])
                    : 0;
                if (count($subj['quarters']) >= $expectedQ) {
                    $subjectFinals[] = $subj['final_grade'];
                } else {
                    $studentAllComplete = false;
                }
            }
            unset($subj);
            $student['subject_count'] = count($student['subjects']);
            $student['all_complete'] = $studentAllComplete;
            $student['gwa'] = ($studentAllComplete && count($subjectFinals) > 0)
                ? round(array_sum($subjectFinals) / count($subjectFinals), 2)
                : 0;
        } elseif ($isSectionCollege) {
            $student['gwa'] = $student['total_units'] > 0 
                ? $student['weighted_sum'] / $student['total_units'] 
                : 0;
            $student['subject_count'] = count($student['grades']);
        } else {
            // Non-college single term: simple average
            $gradeCount = count($student['grades']);
            $gradeSum = array_sum(array_column($student['grades'], 'period_grade'));
            $student['gwa'] = $gradeCount > 0 ? round($gradeSum / $gradeCount, 0) : 0;
            $student['subject_count'] = $gradeCount;
        }
    }
    unset($student);
    
    // Sort by GWA descending
    uasort($gradesByStudent, function($a, $b) {
        return $b['gwa'] <=> $a['gwa'];
    });
    
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Section: ' . $section['section_code'], 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Total Students: ' . count($gradesByStudent), 0, 1, 'L');
    $pdf->Ln(5);
    
    $pdf->SectionTable($gradesByStudent, $isSectionCollege);
    
    $filename = 'Section_Report_' . $section['section_code'] . '_' . date('Ymd') . '.pdf';
    
} else {
    die('Please specify a section or student');
}

// Output PDF
$pdf->Output('I', $filename);