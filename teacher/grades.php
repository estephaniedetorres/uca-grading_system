<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('teacher');

if (!function_exists('findTermByLabels')) {
    function findTermByLabels(array $terms, array $labels): ?array {
        foreach ($labels as $label) {
            foreach ($terms as $term) {
                if (isset($term['term_name']) && stripos($term['term_name'], $label) !== false) {
                    return $term;
                }
            }
        }
        return null;
    }
}

$pageTitle = 'Grade Entry';
$teacherId = $_SESSION['teacher_id'] ?? 0;
$message = '';
$messageType = '';

// Handle grade submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Handle direct grade input (only final grade, no components)
    if ($action === 'save_direct') {
        $enrollId = (int)$_POST['enroll_id'];
        $termId = (int)$_POST['term_id'];
        $gradingPeriod = sanitize($_POST['grading_period']);
        $isCollegeGrade = isset($_POST['is_college']) && $_POST['is_college'] == '1';
        
        if ($isCollegeGrade) {
            $collegeGrade = sanitize($_POST['direct_grade']);
            $percentGrade = collegeGradeToPercentage($collegeGrade);
            $directGrade = $percentGrade ?? 0;
            $periodGrade = (float)$collegeGrade;
        } else {
            $directGrade = (float)$_POST['direct_grade'];
            $directGrade = max(0, min(100, $directGrade));
            $periodGrade = round($directGrade);
        }
        
        $newStatus = 'draft';
        
        try {
            // Check if grade exists
            $checkStmt = db()->prepare("SELECT id, status FROM tbl_grades WHERE enroll_id = ? AND term_id = ? AND grading_period = ?");
            $checkStmt->execute([$enrollId, $termId, $gradingPeriod]);
            $existingGrade = $checkStmt->fetch();
            
            if ($existingGrade && !in_array($existingGrade['status'], ['pending', 'draft'])) {
                $message = 'Cannot modify grades that have been submitted or approved.';
                $messageType = 'error';
            } else {
                if ($existingGrade) {
                    $stmt = db()->prepare("
                        UPDATE tbl_grades SET 
                            teacher_id = ?, ww_total = NULL, pt_total = NULL, qa_score = NULL, 
                            initial_grade = ?, period_grade = ?, status = ?, is_direct_input = 1,
                            submitted_at = CASE WHEN ? = 'submitted' THEN NOW() ELSE submitted_at END,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$teacherId, $directGrade, $periodGrade, $newStatus, $newStatus, $existingGrade['id']]);
                } else {
                    $stmt = db()->prepare("
                        INSERT INTO tbl_grades (enroll_id, teacher_id, term_id, grading_period, ww_total, pt_total, qa_score, initial_grade, period_grade, status, is_direct_input, submitted_at)
                        VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, 1, CASE WHEN ? = 'submitted' THEN NOW() ELSE NULL END)
                    ");
                    $stmt->execute([$enrollId, $teacherId, $termId, $gradingPeriod, $directGrade, $periodGrade, $newStatus, $newStatus]);
                }
                
                $message = 'Grade saved as draft!';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Failed to save grade: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if ($action === 'save_grades' || $action === 'save_draft') {
        $enrollId = (int)$_POST['enroll_id'];
        $termId = (int)$_POST['term_id'];
        $gradingPeriod = sanitize($_POST['grading_period']);
        $wwPs = (float)$_POST['ww_ps'];
        $ptPs = (float)$_POST['pt_ps'];
        $qaPs = (float)$_POST['qa_ps'];
        $weightCategory = $_POST['weight_category'] ?? 'core';
        $gradeLevel = $_POST['grade_level'] ?? 'jhs';
        $isCollegeInput = isset($_POST['is_college_input']) && $_POST['is_college_input'] == '1';
        
        $newStatus = 'draft';
        
        // Determine weights based on grade level and subject category
        // Grades 1-10 (PRE, ELE, JHS)
        // Languages/AP/EsP: WW=30%, PT=50%, QA=20%
        // Science/Math: WW=40%, PT=40%, QA=20%
        // MAPEH/EPP/TLE: WW=20%, PT=60%, QA=20%
        
        // Grades 11-12 (SHS)
        // Core: WW=25%, PT=50%, QA=25%
        // Academic: WW=25%, PT=45%, QA=30%
        // Work Immersion: WW=35%, PT=40%, QA=25%
        // TVL/Sports/Arts: WW=20%, PT=60%, QA=20%
        
        $wwWeight = 0.25;
        $ptWeight = 0.50;
        $qaWeight = 0.25;
        
        if (in_array($gradeLevel, ['pre', 'ele', 'jhs'])) {
            // Grades 1-10
            switch ($weightCategory) {
                case 'languages':
                    $wwWeight = 0.30; $ptWeight = 0.50; $qaWeight = 0.20;
                    break;
                case 'science_math':
                    $wwWeight = 0.40; $ptWeight = 0.40; $qaWeight = 0.20;
                    break;
                case 'mapeh_epp':
                    $wwWeight = 0.20; $ptWeight = 0.60; $qaWeight = 0.20;
                    break;
                default: // core/general
                    $wwWeight = 0.30; $ptWeight = 0.50; $qaWeight = 0.20;
            }
        } else {
            // Grades 11-12 (SHS)
            switch ($weightCategory) {
                case 'core':
                    $wwWeight = 0.25; $ptWeight = 0.50; $qaWeight = 0.25;
                    break;
                case 'academic':
                    $wwWeight = 0.25; $ptWeight = 0.45; $qaWeight = 0.30;
                    break;
                case 'work_immersion':
                    $wwWeight = 0.35; $ptWeight = 0.40; $qaWeight = 0.25;
                    break;
                case 'tvl_sports_arts':
                    $wwWeight = 0.20; $ptWeight = 0.60; $qaWeight = 0.20;
                    break;
                default:
                    $wwWeight = 0.25; $ptWeight = 0.50; $qaWeight = 0.25;
            }
        }
        
        // Calculate Weighted Scores (WS) from Percentage Scores (PS)
        $wwWs = $wwPs * $wwWeight;
        $ptWs = $ptPs * $ptWeight;
        $qaWs = $qaPs * $qaWeight;
        
        // Calculate initial grade as sum of weighted scores
        $initialGrade = $wwWs + $ptWs + $qaWs;
        
        // Transmutation table (DepEd standard)
        
        // Transmutation (simple linear for demo)
        if ($initialGrade >= 100) $periodGrade = 100;
        elseif ($initialGrade >= 98.40) $periodGrade = 99;
        elseif ($initialGrade >= 96.80) $periodGrade = 98;
        elseif ($initialGrade >= 95.20) $periodGrade = 97;
        elseif ($initialGrade >= 93.60) $periodGrade = 96;
        elseif ($initialGrade >= 92.00) $periodGrade = 95;
        elseif ($initialGrade >= 90.40) $periodGrade = 94;
        elseif ($initialGrade >= 88.80) $periodGrade = 93;
        elseif ($initialGrade >= 87.20) $periodGrade = 92;
        elseif ($initialGrade >= 85.60) $periodGrade = 91;
        elseif ($initialGrade >= 84.00) $periodGrade = 90;
        elseif ($initialGrade >= 82.40) $periodGrade = 89;
        elseif ($initialGrade >= 80.80) $periodGrade = 88;
        elseif ($initialGrade >= 79.20) $periodGrade = 87;
        elseif ($initialGrade >= 77.60) $periodGrade = 86;
        elseif ($initialGrade >= 76.00) $periodGrade = 85;
        elseif ($initialGrade >= 74.40) $periodGrade = 84;
        elseif ($initialGrade >= 72.80) $periodGrade = 83;
        elseif ($initialGrade >= 71.20) $periodGrade = 82;
        elseif ($initialGrade >= 69.60) $periodGrade = 81;
        elseif ($initialGrade >= 68.00) $periodGrade = 80;
        elseif ($initialGrade >= 66.40) $periodGrade = 79;
        elseif ($initialGrade >= 64.80) $periodGrade = 78;
        elseif ($initialGrade >= 63.20) $periodGrade = 77;
        elseif ($initialGrade >= 61.60) $periodGrade = 76;
        elseif ($initialGrade >= 60.00) $periodGrade = 75;
        else $periodGrade = round(50 + ($initialGrade / 60) * 24);
        
        // For college students, convert the percentage grade to college scale (1.00-5.00)
        if ($isCollegeInput) {
            $periodGrade = percentageToCollegeGrade($periodGrade);
        }
        
        try {
            // Check if grade exists
            $checkStmt = db()->prepare("SELECT id, status FROM tbl_grades WHERE enroll_id = ? AND term_id = ? AND grading_period = ?");
            $checkStmt->execute([$enrollId, $termId, $gradingPeriod]);
            $existingGrade = $checkStmt->fetch();
            
            // Teachers can only edit grades that are in 'pending' or 'draft' status
            if ($existingGrade && !in_array($existingGrade['status'], ['pending', 'draft'])) {
                $message = 'Cannot modify grades that have been submitted or approved.';
                $messageType = 'error';
            } else {
                if ($existingGrade) {
                    // Update existing grade
                    $stmt = db()->prepare("
                        UPDATE tbl_grades SET 
                            teacher_id = ?, ww_ps = ?, pt_ps = ?, qa_ps = ?,
                            ww_ws = ?, pt_ws = ?, qa_ws = ?,
                            initial_grade = ?, period_grade = ?, status = ?,
                            submitted_at = CASE WHEN ? = 'submitted' THEN NOW() ELSE submitted_at END,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$teacherId, $wwPs, $ptPs, $qaPs, $wwWs, $ptWs, $qaWs, $initialGrade, $periodGrade, $newStatus, $newStatus, $existingGrade['id']]);
                } else {
                    // Insert new grade
                    $stmt = db()->prepare("
                        INSERT INTO tbl_grades (enroll_id, teacher_id, term_id, grading_period, ww_ps, pt_ps, qa_ps, ww_ws, pt_ws, qa_ws, initial_grade, period_grade, status, submitted_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 'submitted' THEN NOW() ELSE NULL END)
                    ");
                    $stmt->execute([$enrollId, $teacherId, $termId, $gradingPeriod, $wwPs, $ptPs, $qaPs, $wwWs, $ptWs, $qaWs, $initialGrade, $periodGrade, $newStatus, $newStatus]);
                }
                
                $message = 'Grades saved as draft!';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Failed to save grades.';
            $messageType = 'error';
        }
    }
    
    // Handle submit all periods for approval
    if ($action === 'submit_all_periods') {
        $submitSection = (int)$_POST['submit_section'];
        $submitSubject = (int)$_POST['submit_subject'];
        $submitTermId = (int)$_POST['submit_term'];
        $submitIsYearly = ($_POST['submit_is_yearly'] ?? '0') === '1';
        $submitIsShsDept = ($_POST['submit_is_shs'] ?? '0') === '1';
        
        try {
            // Get all enrollment IDs for this section+subject
            $enrollStmt = db()->prepare("
                SELECT e.id FROM tbl_enroll e
                JOIN tbl_student st ON e.student_id = st.id
                WHERE st.section_id = ? AND e.subject_id = ?
            ");
            $enrollStmt->execute([$submitSection, $submitSubject]);
            $allEnrollIds = array_column($enrollStmt->fetchAll(), 'id');
            
            if (empty($allEnrollIds)) {
                $message = 'No enrollments found.';
                $messageType = 'error';
            } else {
                $ePlaceholders = implode(',', array_fill(0, count($allEnrollIds), '?'));
                
                if ($submitIsYearly) {
                    // K-12: Need all 4 quarters (Q1-Q4 across both semesters in same SY)
                    $syStmt = db()->prepare("SELECT sy_id FROM tbl_term WHERE id = ?");
                    $syStmt->execute([$submitTermId]);
                    $syRow = $syStmt->fetch();
                    $syId = $syRow['sy_id'] ?? 0;
                    
                    $semStmt = db()->prepare("SELECT id FROM tbl_term WHERE sy_id = ? AND term_name LIKE 'Semester%' AND status = 'active'");
                    $semStmt->execute([$syId]);
                    $semTermIds = array_column($semStmt->fetchAll(), 'id');
                    
                    if (count($semTermIds) < 2) {
                        $message = "Both Semester 1 and Semester 2 terms are required for K-12 submission.";
                        $messageType = 'error';
                    } else {
                        $tPlaceholders = implode(',', array_fill(0, count($semTermIds), '?'));
                        $requiredPeriods = ['Q1', 'Q2', 'Q3', 'Q4'];
                        $pPlaceholders = implode(',', array_fill(0, 4, '?'));
                        
                        // Find enrollments with all 4 quarters complete across semesters
                        $checkStmt = db()->prepare("
                            SELECT enroll_id, COUNT(DISTINCT grading_period) as cnt
                            FROM tbl_grades 
                            WHERE enroll_id IN ($ePlaceholders) AND term_id IN ($tPlaceholders)
                              AND grading_period IN ($pPlaceholders)
                              AND (ww_ps IS NOT NULL OR ww_total IS NOT NULL OR period_grade IS NOT NULL)
                            GROUP BY enroll_id
                            HAVING cnt >= 4
                        ");
                        $checkStmt->execute(array_merge($allEnrollIds, $semTermIds, $requiredPeriods));
                        $completeEnrollIds = array_column($checkStmt->fetchAll(), 'enroll_id');
                        
                        if (empty($completeEnrollIds)) {
                            $message = 'No students have all 4 quarters (Q1-Q4) complete. Complete all quarters before submitting.';
                            $messageType = 'error';
                        } else {
                            $cePlaceholders = implode(',', array_fill(0, count($completeEnrollIds), '?'));
                            $stmt = db()->prepare("
                                UPDATE tbl_grades SET status = 'submitted', submitted_at = NOW(), updated_at = NOW()
                                WHERE enroll_id IN ($cePlaceholders) AND term_id IN ($tPlaceholders) AND grading_period IN ($pPlaceholders) AND status = 'draft'
                            ");
                            $stmt->execute(array_merge($completeEnrollIds, $semTermIds, $requiredPeriods));
                            $count = $stmt->rowCount();
                            $studentCount = count($completeEnrollIds);
                            $totalStudents = count($allEnrollIds);
                            $message = "$studentCount of $totalStudents student(s) grades submitted for approval ($count grade records).";
                            $messageType = 'success';
                            if ($studentCount < $totalStudents) {
                                $incomplete = $totalStudents - $studentCount;
                                $message .= " $incomplete student(s) still have incomplete quarters.";
                            }
                        }
                    }
                } else {
                    // SHS/College: Check within current term
                    $requiredPeriods = $submitIsShsDept ? ['Q1', 'Q2'] : ['PRELIM', 'MIDTERM', 'SEMIFINAL', 'FINAL'];
                    $requiredCount = count($requiredPeriods);
                    $pPlaceholders = implode(',', array_fill(0, $requiredCount, '?'));
                    
                    $checkStmt = db()->prepare("
                        SELECT enroll_id, COUNT(DISTINCT grading_period) as cnt
                        FROM tbl_grades 
                        WHERE enroll_id IN ($ePlaceholders) AND term_id = ? AND grading_period IN ($pPlaceholders)
                          AND (ww_ps IS NOT NULL OR ww_total IS NOT NULL OR period_grade IS NOT NULL)
                        GROUP BY enroll_id
                        HAVING cnt >= ?
                    ");
                    $checkStmt->execute(array_merge($allEnrollIds, [$submitTermId], $requiredPeriods, [$requiredCount]));
                    $completeEnrollIds = array_column($checkStmt->fetchAll(), 'enroll_id');
                    
                    $periodNames = implode(', ', $requiredPeriods);
                    if (empty($completeEnrollIds)) {
                        $message = "No students have all required grading periods ($periodNames) complete.";
                        $messageType = 'error';
                    } else {
                        $cePlaceholders = implode(',', array_fill(0, count($completeEnrollIds), '?'));
                        $stmt = db()->prepare("
                            UPDATE tbl_grades SET status = 'submitted', submitted_at = NOW(), updated_at = NOW()
                            WHERE enroll_id IN ($cePlaceholders) AND term_id = ? AND grading_period IN ($pPlaceholders) AND status = 'draft'
                        ");
                        $stmt->execute(array_merge($completeEnrollIds, [$submitTermId], $requiredPeriods));
                        $count = $stmt->rowCount();
                        $studentCount = count($completeEnrollIds);
                        $totalStudents = count($allEnrollIds);
                        $message = "$studentCount of $totalStudents student(s) grades submitted for approval ($count grade records).";
                        $messageType = 'success';
                        if ($studentCount < $totalStudents) {
                            $incomplete = $totalStudents - $studentCount;
                            $message .= " $incomplete student(s) still have incomplete grading periods.";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $message = 'Failed to submit grades: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get filters
$sectionFilter = $_GET['section'] ?? '';
$subjectFilter = $_GET['subject'] ?? '';
$termFilter = $_GET['term'] ?? '';
$studentFilter = $_GET['student'] ?? '';

// Get student info if student filter is set
$selectedStudent = null;
if ($studentFilter) {
    $studentStmt = db()->prepare("
        SELECT st.*, sec.section_code, sec.id as section_id, lv.code as level_code
        FROM tbl_student st
        JOIN tbl_section sec ON st.section_id = sec.id
        LEFT JOIN level lv ON sec.level_id = lv.id
        WHERE st.id = ?
    ");
    $studentStmt->execute([$studentFilter]);
    $selectedStudent = $studentStmt->fetch();
    if ($selectedStudent && !$sectionFilter) {
        $sectionFilter = $selectedStudent['section_id'];
    }
}

// Get teacher's sections (using tbl_teacher_subject for sections where teacher teaches)
$sectionsStmt = db()->prepare("
    SELECT DISTINCT s.id, s.section_code, lv.code as level_code FROM tbl_section s 
    JOIN tbl_teacher_subject ts ON ts.section_id = s.id
    LEFT JOIN level lv ON s.level_id = lv.id
    WHERE ts.teacher_id = ? AND ts.status = 'active' 
    ORDER BY s.section_code
");
$sectionsStmt->execute([$teacherId]);
$sections = $sectionsStmt->fetchAll();

// Get subjects that teacher teaches (filtered by section if selected)
if ($sectionFilter) {
    $subjectsStmt = db()->prepare("
        SELECT DISTINCT sub.* FROM tbl_subjects sub
        JOIN tbl_teacher_subject ts ON ts.subject_id = sub.id
        WHERE ts.teacher_id = ? AND ts.section_id = ? AND ts.status = 'active'
        ORDER BY sub.subjcode
    ");
    $subjectsStmt->execute([$teacherId, $sectionFilter]);
    $subjects = $subjectsStmt->fetchAll();
} else {
    $subjectsStmt = db()->prepare("
        SELECT DISTINCT sub.* FROM tbl_subjects sub
        JOIN tbl_teacher_subject ts ON ts.subject_id = sub.id
        WHERE ts.teacher_id = ? AND ts.status = 'active'
        ORDER BY sub.subjcode
    ");
    $subjectsStmt->execute([$teacherId]);
    $subjects = $subjectsStmt->fetchAll();
}

// Get terms/quarters based on section's enrollment type
$enrollmentType = 'semestral'; // Default for SHS/College
$isYearly = false;
$sectionTrack = [];
if ($sectionFilter) {
    // Check if selected section uses yearly or semestral enrollment
    $trackCheckStmt = db()->prepare("
        SELECT at.code, at.enrollment_type, d.code as dept_code, lv.code as level_code
        FROM tbl_section s 
        JOIN tbl_academic_track at ON s.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        LEFT JOIN level lv ON s.level_id = lv.id
        WHERE s.id = ?
    ");
    $trackCheckStmt->execute([$sectionFilter]);
    $sectionTrack = $trackCheckStmt->fetch();
    if ($sectionTrack) {
        $enrollmentType = $sectionTrack['enrollment_type'] ?? 'semestral';
        $isYearly = ($enrollmentType === 'yearly');
    }
}

$deptCode = strtoupper($sectionTrack['dept_code'] ?? '');
$isShsDept = $deptCode === 'SHS';
$isCollege = in_array($deptCode, ['CCTE', 'CON']);

// All education levels now use the same terms (Semester 1, Semester 2, Summer)
// SHS only shows semesters (no Summer)
// K-10 (yearly): no term dropdown — grading period only; term resolved internally
$terms = [];
if (!$isYearly) {
    $termQuery = "
        SELECT t.*, sy.sy_name FROM tbl_term t
        LEFT JOIN tbl_sy sy ON t.sy_id = sy.id
        WHERE t.status = 'active'
    ";
    if ($isShsDept) {
        $termQuery .= " AND t.term_name LIKE 'Semester%'";
    } else {
        // College: show semesters + summer
        $termQuery .= " AND (t.term_name LIKE 'Semester%' OR t.term_name = 'Summer')";
    }
    $termQuery .= " ORDER BY t.start_date ASC, t.id ASC";
    $termStmt = db()->prepare($termQuery);
    $termStmt->execute();
    $terms = $termStmt->fetchAll();
}

// Auto-select current term if no term is selected (SHS/College only)
if (!$isYearly && !$termFilter && $sectionFilter) {
    $currentPeriod = getCurrentGradingPeriod();
    if ($currentPeriod) {
        $termFilter = $currentPeriod['id'];
    } elseif (!empty($terms)) {
        $termFilter = $terms[0]['id'];
    }
}

$gradingPeriodFilter = $_GET['grading_period'] ?? '';

// Grading period options
// K-10: always Q1-Q4 (no term dependency)
// SHS: Q1, Q2 per semester
// College: PRELIM, MIDTERM, SEMIFINAL, FINAL per semester
$educationLevelKey = $isCollege ? 'college' : ($isShsDept ? 'shs' : 'k12');
$gradingPeriodCode = '';
$selectedTermInfo = null;

if (!$isYearly && $termFilter) {
    foreach ($terms as $t) {
        if ($t['id'] == $termFilter) {
            $selectedTermInfo = $t;
            break;
        }
    }
}

$selectedTermName = $selectedTermInfo['term_name'] ?? '';
$gradingPeriodOptions = getGradingPeriodOptions($educationLevelKey, $selectedTermName);

// Set grading period code from filter or default to first option
if ($gradingPeriodFilter && isset($gradingPeriodOptions[$gradingPeriodFilter])) {
    $gradingPeriodCode = $gradingPeriodFilter;
} elseif (!empty($gradingPeriodOptions)) {
    $gradingPeriodCode = array_key_first($gradingPeriodOptions);
}
if (!$gradingPeriodFilter && !empty($gradingPeriodOptions)) {
    $gradingPeriodFilter = array_key_first($gradingPeriodOptions);
}

// For K-10 (yearly): resolve term_id from grading period + section's school year
if ($isYearly && $gradingPeriodCode && $sectionFilter) {
    $sySt = db()->prepare("SELECT sy_id FROM tbl_section WHERE id = ?");
    $sySt->execute([$sectionFilter]);
    $sectionSyId = (int)($sySt->fetchColumn() ?: 0);
    if ($sectionSyId) {
        $termFilter = resolveK10TermId($gradingPeriodCode, $sectionSyId) ?? 0;
        // Store selected term info
        if ($termFilter) {
            $tiStmt = db()->prepare("SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.id = ?");
            $tiStmt->execute([$termFilter]);
            $selectedTermInfo = $tiStmt->fetch();
        }
    }
}

// Get enrolled students based on filters
$enrollments = [];

// If student is selected, show all their enrolled subjects for this teacher
if ($studentFilter && $termFilter) {
    $stmt = db()->prepare("
         SELECT e.*, CONCAT_WS(' ', st.given_name, st.middle_name, st.last_name) as student_name, st.id as student_id, sub.subjcode, sub.`desc` as subject_name, sec.section_code,
             sub.weight_category, at.code as course_code, d.code as dept_code,
               g.id as grade_id, g.ww_total, g.pt_total, g.qa_score, g.ww_ps, g.pt_ps, g.qa_ps, g.ww_ws, g.pt_ws, g.qa_ws, g.initial_grade, g.period_grade, g.status as grade_status, g.grading_period, g.remarks as grade_remarks
        FROM tbl_enroll e
        JOIN tbl_student st ON e.student_id = st.id
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
         LEFT JOIN tbl_departments d ON at.dept_id = d.id
        JOIN tbl_teacher_subject ts ON ts.section_id = sec.id AND ts.subject_id = e.subject_id
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id AND g.term_id = ? AND g.grading_period = ?
        WHERE st.id = ? AND ts.teacher_id = ? AND ts.status = 'active'
          AND (e.term_id IS NULL OR e.term_id = ?)
        ORDER BY sub.subjcode
    ");
    $stmt->execute([$termFilter, $gradingPeriodCode, $studentFilter, $teacherId, $termFilter]);
    $enrollments = $stmt->fetchAll();
} elseif ($sectionFilter && $subjectFilter && $termFilter) {
    $stmt = db()->prepare("
         SELECT e.*, CONCAT_WS(' ', st.given_name, st.middle_name, st.last_name) as student_name, st.id as student_id, sub.subjcode, sub.`desc` as subject_name, sec.section_code,
             sub.weight_category, at.code as course_code, d.code as dept_code,
               g.id as grade_id, g.ww_total, g.pt_total, g.qa_score, g.ww_ps, g.pt_ps, g.qa_ps, g.ww_ws, g.pt_ws, g.qa_ws, g.initial_grade, g.period_grade, g.status as grade_status, g.grading_period, g.remarks as grade_remarks
        FROM tbl_enroll e
        JOIN tbl_student st ON e.student_id = st.id
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
         LEFT JOIN tbl_departments d ON at.dept_id = d.id
        JOIN tbl_teacher_subject ts ON ts.section_id = sec.id AND ts.subject_id = e.subject_id
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id AND g.term_id = ? AND g.grading_period = ?
        WHERE sec.id = ? AND e.subject_id = ? AND ts.teacher_id = ? AND ts.status = 'active'
          AND (e.term_id IS NULL OR e.term_id = ?)
        ORDER BY st.last_name, st.given_name
    ");
    $stmt->execute([$termFilter, $gradingPeriodCode, $sectionFilter, $subjectFilter, $teacherId, $termFilter]);
    $enrollments = $stmt->fetchAll();
}

// --- Completion check: how many grading periods does each enrollment have grades for? ---
$completionData = [];
$requiredPeriodCount = 0;
$requiredPeriodLabels = [];

if (!empty($enrollments) && $sectionFilter && $subjectFilter && $termFilter) {
    $enrollIds = array_column($enrollments, 'id');
    $ePlaceholders = implode(',', array_fill(0, count($enrollIds), '?'));

    if ($isYearly) {
        // K-12: need 4 quarters (Q1-Q4 across both semesters)
        $requiredPeriodCount = 4;
        $requiredPeriodLabels = ['Q1', 'Q2', 'Q3', 'Q4'];
        $syId = $selectedTermInfo['sy_id'] ?? null;

        if ($syId) {
            $semStmt = db()->prepare("SELECT id FROM tbl_term WHERE sy_id = ? AND term_name LIKE 'Semester%' AND status = 'active'");
            $semStmt->execute([$syId]);
            $allSemTermIds = array_column($semStmt->fetchAll(), 'id');

            if (!empty($allSemTermIds)) {
                $tPlaceholders = implode(',', array_fill(0, count($allSemTermIds), '?'));
                $compStmt = db()->prepare("
                    SELECT enroll_id,
                           COUNT(DISTINCT grading_period) as period_count,
                           GROUP_CONCAT(DISTINCT grading_period ORDER BY grading_period) as completed_periods
                    FROM tbl_grades
                    WHERE enroll_id IN ($ePlaceholders) AND term_id IN ($tPlaceholders)
                      AND grading_period IN ('Q1','Q2','Q3','Q4')
                      AND (ww_ps IS NOT NULL OR ww_total IS NOT NULL OR period_grade IS NOT NULL)
                    GROUP BY enroll_id
                ");
                $compStmt->execute(array_merge($enrollIds, $allSemTermIds));
                foreach ($compStmt->fetchAll() as $row) {
                    $completionData[$row['enroll_id']] = [
                        'count' => (int)$row['period_count'],
                        'completed' => explode(',', $row['completed_periods'])
                    ];
                }
            }
        }
    } elseif ($isShsDept) {
        // SHS: need Q1 and Q2 within current term
        $requiredPeriodCount = 2;
        $requiredPeriodLabels = ['Q1', 'Q2'];

        $compStmt = db()->prepare("
            SELECT enroll_id,
                   COUNT(DISTINCT grading_period) as period_count,
                   GROUP_CONCAT(DISTINCT grading_period ORDER BY grading_period) as completed_periods
            FROM tbl_grades
            WHERE enroll_id IN ($ePlaceholders) AND term_id = ?
              AND grading_period IN ('Q1', 'Q2')
              AND (ww_ps IS NOT NULL OR ww_total IS NOT NULL OR period_grade IS NOT NULL)
            GROUP BY enroll_id
        ");
        $compStmt->execute(array_merge($enrollIds, [$termFilter]));
        foreach ($compStmt->fetchAll() as $row) {
            $completionData[$row['enroll_id']] = [
                'count' => (int)$row['period_count'],
                'completed' => explode(',', $row['completed_periods'])
            ];
        }
    } else {
        // College: PRELIM, MIDTERM, SEMIFINAL, FINAL within current term
        $requiredPeriodLabels = array_keys($gradingPeriodOptions);
        $requiredPeriodCount = count($requiredPeriodLabels);

        if ($requiredPeriodCount > 0) {
            $pPlaceholders = implode(',', array_fill(0, $requiredPeriodCount, '?'));
            $compStmt = db()->prepare("
                SELECT enroll_id,
                       COUNT(DISTINCT grading_period) as period_count,
                       GROUP_CONCAT(DISTINCT grading_period ORDER BY grading_period) as completed_periods
                FROM tbl_grades
                WHERE enroll_id IN ($ePlaceholders) AND term_id = ?
                  AND grading_period IN ($pPlaceholders)
                  AND (ww_ps IS NOT NULL OR ww_total IS NOT NULL OR period_grade IS NOT NULL)
                GROUP BY enroll_id
            ");
            $compStmt->execute(array_merge($enrollIds, [$termFilter], $requiredPeriodLabels));
            foreach ($compStmt->fetchAll() as $row) {
                $completionData[$row['enroll_id']] = [
                    'count' => (int)$row['period_count'],
                    'completed' => explode(',', $row['completed_periods'])
                ];
            }
        }
    }
}

include '../includes/header.php';
include '../includes/sidebar_teacher.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Grade Entry</h1>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <svg class="w-5 h-5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <?php if ($message): ?>
        <div class="alert-auto-hide mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
            <?= $message ?>
        </div>
        <?php endif; ?>

        <?php if ($selectedStudent): ?>
        <!-- Student Info Banner -->
        <div class="bg-black rounded-xl p-6 text-white mb-6">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center text-2xl font-bold text-gray-700">
                    <?= strtoupper(substr(formatPersonName($selectedStudent) ?: 'S', 0, 1)) ?>
                </div>
                <div>
                    <h2 class="text-xl font-bold"><?= htmlspecialchars(formatPersonName($selectedStudent)) ?></h2>
                    <p class="text-gray-300">Section: <?= htmlspecialchars($selectedStudent['section_code']) ?><?php if (!empty($selectedStudent['level_code'])): ?> &bull; Level: <?= htmlspecialchars($selectedStudent['level_code']) ?><?php endif; ?></p>
                </div>
                <a href="students.php" class="ml-auto px-4 py-2 bg-white text-black rounded-lg hover:bg-gray-100 transition text-sm">
                    ← Back to Students
                </a>
            </div>
        </div>

        <!-- Term/Quarter Selection for Student -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-800 mb-4">Select <?= $isYearly ? 'Grading Period' : 'Term & Grading Period' ?></h3>
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <input type="hidden" name="student" value="<?= $studentFilter ?>">
                <input type="hidden" name="section" value="<?= $sectionFilter ?>">
                <?php if (!$isYearly): ?>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Term/Semester
                    </label>
                    <select name="term" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Term</option>
                        <?php foreach ($terms as $term): ?>
                        <option value="<?= $term['id'] ?>" <?= $termFilter == $term['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($term['term_name'] . ' (' . ($term['sy_name'] ?? '') . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <!-- Grading Period Selection -->
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Grading Period</label>
                    <select name="grading_period" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <?php foreach ($gradingPeriodOptions as $code => $name): ?>
                        <option value="<?= $code ?>" <?= $gradingPeriodCode == $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="px-6 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                    Load Subjects
                </button>
            </form>
        </div>
        <?php else: ?>
        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-800 mb-4">Select Class</h3>
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-<?= $isYearly ? '4' : '5' ?> gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionFilter == $sec['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sec['section_code'] . ($sec['level_code'] ? ' (' . $sec['level_code'] . ')' : '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                    <select name="subject" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= $subjectFilter == $sub['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sub['subjcode'] . ' - ' . $sub['desc']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$isYearly): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Term/Semester
                    </label>
                    <select name="term" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Term</option>
                        <?php foreach ($terms as $term): ?>
                        <option value="<?= $term['id'] ?>" <?= $termFilter == $term['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($term['term_name'] . ' (' . ($term['sy_name'] ?? '') . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Grading Period</label>
                    <select name="grading_period" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <?php foreach ($gradingPeriodOptions as $code => $name): ?>
                        <option value="<?= $code ?>" <?= $gradingPeriodCode == $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                        Load Students
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (($studentFilter && $termFilter) || ($sectionFilter && $subjectFilter && $termFilter)): ?>
        
        
        <!-- Grading Mode Toggle -->
        <?php
        $gradingMode = $_GET['mode'] ?? 'detailed';
        // Build shared filter params for mode toggle links
        $toggleParams = array_filter([
            'section' => $sectionFilter,
            'subject' => $subjectFilter,
            'term' => $termFilter,
            'grading_period' => $gradingPeriodCode ?? '',
            'student' => $studentFilter,
        ]);
        ?>
        <div class="mb-4 flex items-center gap-4">
            <span class="text-sm font-medium text-gray-700">Grading Mode:</span>
            <div class="flex flex-wrap bg-gray-100 rounded-lg p-1">
                <a href="?<?= http_build_query(array_merge($toggleParams, ['mode' => 'detailed'])) ?>" 
                   class="px-4 py-2 rounded-md text-sm font-medium transition <?= $gradingMode === 'detailed' ? 'bg-white shadow text-black' : 'text-gray-600 hover:text-black' ?>">
                    Detailed (WW, PT, QA)
                </a>
                <a href="?<?= http_build_query(array_merge($toggleParams, ['mode' => 'direct'])) ?>" 
                   class="px-4 py-2 rounded-md text-sm font-medium transition <?= $gradingMode === 'direct' ? 'bg-white shadow text-black' : 'text-gray-600 hover:text-black' ?>">
                    Direct (Final Grade Only)
                </a>
                <a href="raw_scores.php?<?= http_build_query($toggleParams) ?>" 
                   class="px-4 py-2 rounded-md text-sm font-medium transition text-gray-600 hover:text-black">
                    Raw Score (DepEd Format)
                </a>
            </div>
        </div>
        
        <?php
        // Compute weight percentages for column headers (class view = single subject)
        $headerWwPct = $headerPtPct = $headerQaPct = 0;
        if ($subjectFilter && !$studentFilter) {
            $selectedWeightCat = 'core';
            foreach ($subjects as $sub) {
                if ($sub['id'] == $subjectFilter) {
                    $selectedWeightCat = $sub['weight_category'] ?? 'core';
                    break;
                }
            }
            // Determine grade level from section track
            $headerGradeLevel = 'jhs';
            if ($isShsDept) {
                $headerGradeLevel = 'shs';
            } elseif ($sectionTrack) {
                $tc = strtolower($sectionTrack['code'] ?? '');
                if (strpos($tc, 'pre') !== false) $headerGradeLevel = 'pre';
                elseif (strpos($tc, 'ele') !== false) $headerGradeLevel = 'ele';
            }
            if (in_array($headerGradeLevel, ['pre', 'ele', 'jhs'])) {
                switch ($selectedWeightCat) {
                    case 'languages':    $headerWwPct = 30; $headerPtPct = 50; $headerQaPct = 20; break;
                    case 'science_math': $headerWwPct = 40; $headerPtPct = 40; $headerQaPct = 20; break;
                    case 'mapeh_epp':    $headerWwPct = 20; $headerPtPct = 60; $headerQaPct = 20; break;
                    default:             $headerWwPct = 30; $headerPtPct = 50; $headerQaPct = 20;
                }
            } else {
                switch ($selectedWeightCat) {
                    case 'core':            $headerWwPct = 25; $headerPtPct = 50; $headerQaPct = 25; break;
                    case 'academic':        $headerWwPct = 25; $headerPtPct = 45; $headerQaPct = 30; break;
                    case 'work_immersion':  $headerWwPct = 35; $headerPtPct = 40; $headerQaPct = 25; break;
                    case 'tvl_sports_arts': $headerWwPct = 20; $headerPtPct = 60; $headerQaPct = 20; break;
                    default:                $headerWwPct = 25; $headerPtPct = 50; $headerQaPct = 25;
                }
            }
        }
        ?>

        <!-- Grade Entry Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <?php if ($studentFilter): ?>
                    <h3 class="text-lg font-semibold text-gray-800">Subject Grades for <?= htmlspecialchars(formatPersonName($selectedStudent)) ?></h3>
                    <?php else: ?>
                    <h3 class="text-lg font-semibold text-gray-800">Student Grades</h3>
                    <?php endif; ?>
                    <p class="text-sm text-gray-500">
                        <?php if ($gradingMode === 'direct'): ?>
                        Enter the final quarterly/periodical grade directly (75-100)
                        <?php else: ?>
                        Enter WW (Written Work), PT (Performance Task), and QA (Quarterly Assessment) percentage scores
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px]">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-4 py-4 font-medium">#</th>
                            <th class="px-4 py-4 font-medium"><?= $studentFilter ? 'Subject' : 'Student Name' ?></th>
                            <?php if ($gradingMode === 'direct'): ?>
                            <th class="px-4 py-4 font-medium text-center">Quarter Grade</th>
                            <?php else: ?>
                            <?php if ($headerWwPct): ?>
                            <th class="px-4 py-4 font-medium text-center">WW PS (<?= $headerWwPct ?>%)</th>
                            <th class="px-4 py-4 font-medium text-center">PT PS (<?= $headerPtPct ?>%)</th>
                            <th class="px-4 py-4 font-medium text-center">QA PS (<?= $headerQaPct ?>%)</th>
                            <?php else: ?>
                            <th class="px-4 py-4 font-medium text-center">WW PS</th>
                            <th class="px-4 py-4 font-medium text-center">PT PS</th>
                            <th class="px-4 py-4 font-medium text-center">QA PS</th>
                            <?php endif; ?>
                            <th class="px-4 py-4 font-medium text-center">Initial</th>
                            <?php endif; ?>
                            <th class="px-4 py-4 font-medium text-center">Final Grade</th>
                            <th class="px-4 py-4 font-medium text-center">Status</th>
                            <th class="px-4 py-4 font-medium text-center"><?= $requiredPeriodCount > 0 ? 'Progress' : '' ?></th>
                            <th class="px-4 py-4 font-medium text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rowNum = 0; foreach ($enrollments as $enroll): $rowNum++;
                            // Determine grade level from course code
                            $deptCode = strtolower($enroll['dept_code'] ?? '');
                            $courseCode = strtolower($enroll['course_code'] ?? 'jhs');
                            $gradeLevel = 'jhs'; // default
                            if ($deptCode === 'shs') {
                                $gradeLevel = 'shs';
                            } elseif (strpos($courseCode, 'pre') !== false) {
                                $gradeLevel = 'pre';
                            } elseif (strpos($courseCode, 'ele') !== false) {
                                $gradeLevel = 'ele';
                            } elseif (strpos($courseCode, 'jhs') !== false) {
                                $gradeLevel = 'jhs';
                            }
                            
                            $weightCat = $enroll['weight_category'] ?? 'core';
                            
                            // Get display weights for table header
                            if (in_array($gradeLevel, ['pre', 'ele', 'jhs'])) {
                                switch ($weightCat) {
                                    case 'languages': $wwPct = 30; $ptPct = 50; $qaPct = 20; break;
                                    case 'science_math': $wwPct = 40; $ptPct = 40; $qaPct = 20; break;
                                    case 'mapeh_epp': $wwPct = 20; $ptPct = 60; $qaPct = 20; break;
                                    default: $wwPct = 30; $ptPct = 50; $qaPct = 20;
                                }
                            } else {
                                switch ($weightCat) {
                                    case 'core': $wwPct = 25; $ptPct = 50; $qaPct = 25; break;
                                    case 'academic': $wwPct = 25; $ptPct = 45; $qaPct = 30; break;
                                    case 'work_immersion': $wwPct = 35; $ptPct = 40; $qaPct = 25; break;
                                    case 'tvl_sports_arts': $wwPct = 20; $ptPct = 60; $qaPct = 20; break;
                                    default: $wwPct = 25; $ptPct = 50; $qaPct = 25;
                                }
                            }
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50 <?= in_array($enroll['grade_status'] ?? '', ['submitted', 'approved', 'finalized']) ? 'bg-gray-50' : '' ?>">
                            <form method="POST" action="?<?= http_build_query($_GET) ?>">
                                <input type="hidden" name="enroll_id" value="<?= $enroll['id'] ?>">
                                <input type="hidden" name="term_id" value="<?= $termFilter ?>">
                                <input type="hidden" name="grading_period" value="<?= htmlspecialchars($gradingPeriodCode) ?>">
                                <input type="hidden" name="weight_category" value="<?= htmlspecialchars($weightCat) ?>">
                                <input type="hidden" name="grade_level" value="<?= htmlspecialchars($gradeLevel) ?>">
                                <input type="hidden" name="is_college_input" value="<?= $isCollege ? '1' : '0' ?>">
                                
                                <?php 
                                $gradeStatus = $enroll['grade_status'] ?? 'pending';
                                $isEditable = in_array($gradeStatus, ['pending', 'draft']);
                                $gradeId = $enroll['grade_id'] ?? null;
                                ?>
                                
                                <td class="px-4 py-4 text-center text-sm">
                                    <?= $rowNum ?>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium">
                                    <?php if ($studentFilter): ?>
                                        <span class="text-gray-800"><?= htmlspecialchars($enroll['subjcode']) ?></span>
                                        <span class="text-gray-500 text-xs block"><?= htmlspecialchars($enroll['subject_name']) ?></span>
                                        <?php if ($gradingMode !== 'direct'): ?>
                                        <span class="text-gray-400 text-xs">(WW PS:<?= $wwPct ?>% PT PS:<?= $ptPct ?>% QA PS:<?= $qaPct ?>%)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="flex items-center gap-1">
                                            <?= htmlspecialchars($enroll['student_name']) ?>
                                            <?php if (!$isEditable): ?>
                                            <svg class="w-3.5 h-3.5 text-gray-800 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" title="Grade locked"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                        $gradeRemarks = $enroll['grade_remarks'] ?? '';
                                        if ($gradeRemarks !== '' && in_array($gradeStatus, ['draft', 'pending'])):
                                    ?>
                                    <div class="mt-1 flex items-start gap-1 px-2 py-1 bg-red-50 border border-red-200 rounded text-xs text-red-700 leading-tight">
                                        <svg class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span><strong>Returned:</strong> <?= htmlspecialchars($gradeRemarks) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                
                                <?php if ($gradingMode === 'direct'): ?>
                                <!-- Direct Grade Input Mode -->
                                <td class="px-4 py-4">
                                    <?php if ($isCollege): ?>
                                    <input type="hidden" name="is_college" value="1">
                                    <select name="direct_grade" 
                                        <?= !$isEditable ? 'disabled' : '' ?>
                                        class="w-32 px-2 py-1 border border-gray-200 rounded focus:ring-2 focus:ring-black <?= !$isEditable ? 'bg-gray-100 cursor-not-allowed' : '' ?>">
                                        <option value="">Select</option>
                                        <?php 
                                        $collegeScale = getCollegeGradeScale();
                                        $currentGrade = $enroll['period_grade'] ?? '';
                                        foreach ($collegeScale as $gradeVal => $info): 
                                        ?>
                                        <option value="<?= $gradeVal ?>" <?= (string)$currentGrade === $gradeVal ? 'selected' : '' ?>>
                                            <?= $info['label'] ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php else: ?>
                                    <!-- K-12/SHS: Number input for 75-100 -->
                                    <input type="hidden" name="is_college" value="0">
                                    <input type="number" name="direct_grade" step="1" min="60" max="100" 
                                        value="<?= $enroll['period_grade'] ?? '' ?>"
                                        placeholder="75-100"
                                        <?= !$isEditable ? 'disabled' : '' ?>
                                        class="w-24 px-2 py-1 border border-gray-200 rounded text-center focus:ring-2 focus:ring-black <?= !$isEditable ? 'bg-gray-100 cursor-not-allowed' : '' ?>">
                                    <?php endif; ?>
                                </td>
                                <?php else: ?>
                                <!-- Detailed Grade Input Mode (Percentage Scores) -->
                                <td class="px-4 py-4">
                                    <input type="number" name="ww_ps" step="0.01" min="0" max="100" 
                                        value="<?= $enroll['ww_ps'] ?? '' ?>"
                                        <?= !$isEditable ? 'disabled' : '' ?>
                                        oninput="autoComputeGrade(this)"
                                        class="w-20 px-2 py-1 border border-gray-200 rounded text-center focus:ring-2 focus:ring-black <?= !$isEditable ? 'bg-gray-100 cursor-not-allowed' : '' ?>">
                                </td>
                                <td class="px-4 py-4">
                                    <input type="number" name="pt_ps" step="0.01" min="0" max="100" 
                                        value="<?= $enroll['pt_ps'] ?? '' ?>"
                                        <?= !$isEditable ? 'disabled' : '' ?>
                                        oninput="autoComputeGrade(this)"
                                        class="w-20 px-2 py-1 border border-gray-200 rounded text-center focus:ring-2 focus:ring-black <?= !$isEditable ? 'bg-gray-100 cursor-not-allowed' : '' ?>">
                                </td>
                                <td class="px-4 py-4">
                                    <input type="number" name="qa_ps" step="0.01" min="0" max="100" 
                                        value="<?= $enroll['qa_ps'] ?? '' ?>"
                                        <?= !$isEditable ? 'disabled' : '' ?>
                                        oninput="autoComputeGrade(this)"
                                        class="w-20 px-2 py-1 border border-gray-200 rounded text-center focus:ring-2 focus:ring-black <?= !$isEditable ? 'bg-gray-100 cursor-not-allowed' : '' ?>">
                                </td>
                                <td class="px-4 py-4 text-center text-sm initial-grade-cell">
                                    <?= $enroll['initial_grade'] ? number_format($enroll['initial_grade'], 2) : '-' ?>
                                </td>
                                <?php endif; ?>
                                
                                <td class="px-4 py-4 text-center final-grade-cell" data-is-college="<?= $isCollege ? '1' : '0' ?>">
                                    <?php if ($enroll['period_grade']): ?>
                                    <?php if ($isCollege): ?>
                                    <!-- College: Display as 1.00-5.00 format -->
                                    <?php 
                                    $gradeVal = $enroll['period_grade'];
                                    $isPassing = $gradeVal <= 3.00;
                                    ?>
                                    <span class="px-2 py-1 text-sm font-medium rounded-full <?= $isPassing ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= number_format($gradeVal, 2) ?>
                                    </span>
                                    <?php else: ?>
                                    <!-- K-12/SHS: Display as 75-100 format -->
                                    <span class="px-2 py-1 text-sm font-medium rounded-full <?= $enroll['period_grade'] >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= number_format($enroll['period_grade'], 0) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <?php
                                    $statusColors = [
                                        'pending' => 'bg-gray-100 text-gray-600',
                                        'draft' => 'bg-blue-100 text-blue-600',
                                        'submitted' => 'bg-yellow-100 text-yellow-600',
                                        'approved' => 'bg-green-100 text-green-600',
                                        'finalized' => 'bg-purple-100 text-purple-600'
                                    ];
                                    $statusColor = $statusColors[$gradeStatus] ?? 'bg-gray-100 text-gray-600';
                                    ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?= $statusColor ?>">
                                        <?= ucfirst($gradeStatus) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <?php
                                    $comp = $completionData[$enroll['id']] ?? ['count' => 0, 'completed' => []];
                                    $isComplete = ($requiredPeriodCount > 0 && $comp['count'] >= $requiredPeriodCount);
                                    if ($requiredPeriodCount > 0):
                                    ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?= $isComplete ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' ?>" title="<?= implode(', ', $comp['completed']) ?>">
                                        <?= $comp['count'] ?>/<?= $requiredPeriodCount ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <?php if ($isEditable): ?>
                                    <?php if ($gradingMode === 'direct'): ?>
                                    <button type="submit" name="action" value="save_direct" class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700" title="Save as Draft">
                                        Save
                                    </button>
                                    <?php else: ?>
                                    <button type="submit" name="action" value="save_draft" class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700" title="Save as Draft">
                                        Save
                                    </button>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="inline-flex items-center gap-1 text-gray-500 text-xs">
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                                        Locked
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </form>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($enrollments)): ?>
                        <tr>
                            <td colspan="<?= $gradingMode === 'direct' ? '8' : '10' ?>" class="px-6 py-8 text-center text-gray-500">
                                <?= $studentFilter ? 'No subjects found that you teach for this student' : 'No enrolled students found for this selection' ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Submission Panel -->
        <?php if (!empty($enrollments) && $requiredPeriodCount > 0 && $sectionFilter && $subjectFilter): ?>
        <?php
            $totalStudents = count($enrollments);
            $completeStudents = 0;
            $incompleteStudents = 0;
            foreach ($enrollments as $e) {
                $c = $completionData[$e['id']] ?? ['count' => 0];
                if ($c['count'] >= $requiredPeriodCount) $completeStudents++;
                else $incompleteStudents++;
            }
            $allComplete = ($completeStudents === $totalStudents);
            $periodLabel = $isYearly ? 'quarters' : ($isShsDept ? 'quarters' : 'grading periods');
        ?>
        <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h4 class="text-sm font-semibold text-gray-800 mb-3">Submission Status</h4>
            <div class="flex flex-wrap items-center gap-6 mb-4">
                <div class="flex items-center gap-2">
                    <span class="text-2xl font-bold <?= $allComplete ? 'text-green-600' : 'text-orange-500' ?>"><?= $completeStudents ?>/<?= $totalStudents ?></span>
                    <span class="text-sm text-gray-600">students have all <?= $requiredPeriodCount ?> <?= $periodLabel ?> complete</span>
                </div>
                <?php if ($incompleteStudents > 0): ?>
                <div class="flex items-center gap-2 text-sm text-orange-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <?= $incompleteStudents ?> student(s) still need grades in some <?= $periodLabel ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-4">
                Required <?= $periodLabel ?>: 
                <?php foreach ($requiredPeriodLabels as $lbl): ?>
                <span class="px-2 py-0.5 bg-gray-100 rounded font-medium"><?= $lbl ?></span>
                <?php endforeach; ?>
            </div>
            <?php if ($completeStudents > 0): ?>
            <form method="POST" action="?<?= http_build_query($_GET) ?>" onsubmit="return confirm('Submit grades for <?= $completeStudents ?> complete student(s) for approval? This will lock all their grading periods.');">
                <input type="hidden" name="action" value="submit_all_periods">
                <input type="hidden" name="submit_section" value="<?= $sectionFilter ?>">
                <input type="hidden" name="submit_subject" value="<?= $subjectFilter ?>">
                <input type="hidden" name="submit_term" value="<?= $termFilter ?>">
                <input type="hidden" name="submit_is_yearly" value="<?= $isYearly ? '1' : '0' ?>">
                <input type="hidden" name="submit_is_shs" value="<?= $isShsDept ? '1' : '0' ?>">
                <button type="submit" class="px-6 py-2.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm font-medium">
                    Submit <?= $allComplete ? 'All' : $completeStudents ?> Complete Student(s) for Approval
                </button>
            </form>
            <?php else: ?>
            <p class="text-sm text-gray-500 italic">Complete all <?= $requiredPeriodCount ?> <?= $periodLabel ?> for at least one student to enable submission.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Status Legend -->
        <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h4 class="text-sm font-medium text-gray-700 mb-3">Grade Status Legend</h4>
            <div class="flex flex-wrap gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-600">Pending</span>
                    <span class="text-gray-500">No grades entered</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-600">Draft</span>
                    <span class="text-gray-500">Saved, can edit</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-600">Submitted</span>
                    <span class="text-gray-500">Awaiting approval</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-600">Approved</span>
                    <span class="text-gray-500">Approved by admin</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-600">Finalized</span>
                    <span class="text-gray-500">Visible to student</span>
                </div>
            </div>
        </div>
        <?php elseif ($studentFilter && !$termFilter): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">Select a Term</h3>
            <p class="text-gray-500">Please select a term to view and enter grades for this student.</p>
        </div>
        <?php elseif (!$studentFilter): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">Select a Class</h3>
            <p class="text-gray-500">Please select a section, subject, and term to enter grades.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
function getWeights(gradeLevel, weightCategory) {
    if (['pre', 'ele', 'jhs'].includes(gradeLevel)) {
        switch (weightCategory) {
            case 'languages': return { ww: 0.30, pt: 0.50, qa: 0.20 };
            case 'science_math': return { ww: 0.40, pt: 0.40, qa: 0.20 };
            case 'mapeh_epp': return { ww: 0.20, pt: 0.60, qa: 0.20 };
            default: return { ww: 0.30, pt: 0.50, qa: 0.20 };
        }
    } else {
        switch (weightCategory) {
            case 'core': return { ww: 0.25, pt: 0.50, qa: 0.25 };
            case 'academic': return { ww: 0.25, pt: 0.45, qa: 0.30 };
            case 'work_immersion': return { ww: 0.35, pt: 0.40, qa: 0.25 };
            case 'tvl_sports_arts': return { ww: 0.20, pt: 0.60, qa: 0.20 };
            default: return { ww: 0.25, pt: 0.50, qa: 0.25 };
        }
    }
}

function transmute(initialGrade) {
    if (initialGrade >= 100) return 100;
    if (initialGrade >= 98.40) return 99;
    if (initialGrade >= 96.80) return 98;
    if (initialGrade >= 95.20) return 97;
    if (initialGrade >= 93.60) return 96;
    if (initialGrade >= 92.00) return 95;
    if (initialGrade >= 90.40) return 94;
    if (initialGrade >= 88.80) return 93;
    if (initialGrade >= 87.20) return 92;
    if (initialGrade >= 85.60) return 91;
    if (initialGrade >= 84.00) return 90;
    if (initialGrade >= 82.40) return 89;
    if (initialGrade >= 80.80) return 88;
    if (initialGrade >= 79.20) return 87;
    if (initialGrade >= 77.60) return 86;
    if (initialGrade >= 76.00) return 85;
    if (initialGrade >= 74.40) return 84;
    if (initialGrade >= 72.80) return 83;
    if (initialGrade >= 71.20) return 82;
    if (initialGrade >= 69.60) return 81;
    if (initialGrade >= 68.00) return 80;
    if (initialGrade >= 66.40) return 79;
    if (initialGrade >= 64.80) return 78;
    if (initialGrade >= 63.20) return 77;
    if (initialGrade >= 61.60) return 76;
    if (initialGrade >= 60.00) return 75;
    return Math.round(50 + (initialGrade / 60) * 24);
}

function percentageToCollegeGrade(pct) {
    if (pct >= 99) return '1.00';
    if (pct >= 96) return '1.25';
    if (pct >= 93) return '1.50';
    if (pct >= 90) return '1.75';
    if (pct >= 87) return '2.00';
    if (pct >= 84) return '2.25';
    if (pct >= 81) return '2.50';
    if (pct >= 78) return '2.75';
    if (pct >= 75) return '3.00';
    return '5.00';
}

function autoComputeGrade(input) {
    const form = input.closest('form');
    if (!form) return;

    const wwPs = parseFloat(form.querySelector('[name="ww_ps"]')?.value) || 0;
    const ptPs = parseFloat(form.querySelector('[name="pt_ps"]')?.value) || 0;
    const qaPs = parseFloat(form.querySelector('[name="qa_ps"]')?.value) || 0;

    const row = form.closest('tr');
    const initCell = row.querySelector('.initial-grade-cell');
    const finalCell = row.querySelector('.final-grade-cell');

    if (wwPs === 0 && ptPs === 0 && qaPs === 0) {
        if (initCell) initCell.textContent = '-';
        if (finalCell) finalCell.innerHTML = '-';
        return;
    }

    const gradeLevel = form.querySelector('[name="grade_level"]')?.value || 'jhs';
    const weightCat = form.querySelector('[name="weight_category"]')?.value || 'core';
    const isCollege = (finalCell?.dataset.isCollege === '1');

    const weights = getWeights(gradeLevel, weightCat);
    const initialGrade = (wwPs * weights.ww) + (ptPs * weights.pt) + (qaPs * weights.qa);

    if (initCell) initCell.textContent = initialGrade.toFixed(2);

    const transmutedGrade = transmute(initialGrade);

    if (finalCell) {
        if (isCollege) {
            const collegeGrade = percentageToCollegeGrade(transmutedGrade);
            const isPassing = parseFloat(collegeGrade) <= 3.00;
            const cls = isPassing ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
            finalCell.innerHTML = '<span class="px-2 py-1 text-sm font-medium rounded-full ' + cls + '">' + collegeGrade + '</span>';
        } else {
            const cls = transmutedGrade >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
            finalCell.innerHTML = '<span class="px-2 py-1 text-sm font-medium rounded-full ' + cls + '">' + transmutedGrade + '</span>';
        }
    }
}
</script>

<?php include '../includes/footer.php'; ?>
