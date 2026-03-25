<?php declare(strict_types=1);
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('teacher');

$pageTitle = 'Raw Score Entry';
$teacherId = (int)($_SESSION['teacher_id'] ?? 0);
$message = '';
$messageType = '';

// ---------- AJAX endpoints ----------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $ajaxAction = $_GET['ajax'];

    // --- Add column ---
    if ($ajaxAction === 'add_column' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sectionId = (int)($data['section_id'] ?? 0);
        $subjectId = (int)($data['subject_id'] ?? 0);
        $termId = (int)($data['term_id'] ?? 0);
        $gradingPeriod = sanitize($data['grading_period'] ?? '');
        $componentType = $data['component_type'] ?? '';
        $hps = (float)($data['highest_possible_score'] ?? 0);

        if (!in_array($componentType, ['ww', 'pt', 'qa'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid component type']);
            exit;
        }

        // Get next column number
        $stmt = db()->prepare("SELECT COALESCE(MAX(column_number), 0) + 1 as next_num FROM tbl_grade_columns WHERE teacher_id = ? AND section_id = ? AND subject_id = ? AND term_id = ? AND grading_period = ? AND component_type = ?");
        $stmt->execute([$teacherId, $sectionId, $subjectId, $termId, $gradingPeriod, $componentType]);
        $nextNum = (int)$stmt->fetchColumn();

        $stmt = db()->prepare("INSERT INTO tbl_grade_columns (teacher_id, section_id, subject_id, term_id, grading_period, component_type, column_number, highest_possible_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$teacherId, $sectionId, $subjectId, $termId, $gradingPeriod, $componentType, $nextNum, $hps]);
        $columnId = (int)db()->lastInsertId();

        echo json_encode(['success' => true, 'column_id' => $columnId, 'column_number' => $nextNum]);
        exit;
    }

    // --- Delete column ---
    if ($ajaxAction === 'delete_column' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $columnId = (int)($data['column_id'] ?? 0);

        // Verify ownership
        $stmt = db()->prepare("SELECT id FROM tbl_grade_columns WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$columnId, $teacherId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Column not found']);
            exit;
        }

        // Delete column (cascade deletes raw scores)
        db()->prepare("DELETE FROM tbl_grade_columns WHERE id = ?")->execute([$columnId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- Update HPS ---
    if ($ajaxAction === 'update_hps' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $columnId = (int)($data['column_id'] ?? 0);
        $hps = (float)($data['highest_possible_score'] ?? 0);

        $stmt = db()->prepare("UPDATE tbl_grade_columns SET highest_possible_score = ? WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$hps, $columnId, $teacherId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- Save score ---
    if ($ajaxAction === 'save_score' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $enrollId = (int)($data['enroll_id'] ?? 0);
        $columnId = (int)($data['column_id'] ?? 0);
        $score = $data['score'];

        if ($score === '' || $score === null) {
            // Clear score
            db()->prepare("DELETE FROM tbl_raw_scores WHERE enroll_id = ? AND column_id = ?")->execute([$enrollId, $columnId]);
        } else {
            $score = (float)$score;
            $stmt = db()->prepare("INSERT INTO tbl_raw_scores (enroll_id, column_id, score) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()");
            $stmt->execute([$enrollId, $columnId, $score]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // --- Save all & compute grades ---
    if ($ajaxAction === 'save_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sectionId = (int)($data['section_id'] ?? 0);
        $subjectId = (int)($data['subject_id'] ?? 0);
        $termId = (int)($data['term_id'] ?? 0);
        $gradingPeriod = sanitize($data['grading_period'] ?? '');
        $scores = $data['scores'] ?? []; // { enroll_id: { column_id: score, ... }, ... }
        $hpsUpdates = $data['hps'] ?? []; // { column_id: hps, ... }
        $gradeLevel = $data['grade_level'] ?? 'jhs';
        $weightCategory = $data['weight_category'] ?? 'core';
        $submitForApproval = (bool)($data['submit'] ?? false);

        try {
            db()->beginTransaction();

            // Update HPS values
            foreach ($hpsUpdates as $colId => $hps) {
                $stmt = db()->prepare("UPDATE tbl_grade_columns SET highest_possible_score = ? WHERE id = ? AND teacher_id = ?");
                $stmt->execute([(float)$hps, (int)$colId, $teacherId]);
            }

            // Get column definitions grouped by component type
            $colStmt = db()->prepare("SELECT * FROM tbl_grade_columns WHERE teacher_id = ? AND section_id = ? AND subject_id = ? AND term_id = ? AND grading_period = ? ORDER BY component_type, column_number");
            $colStmt->execute([$teacherId, $sectionId, $subjectId, $termId, $gradingPeriod]);
            $columns = $colStmt->fetchAll();

            $colsByType = ['ww' => [], 'pt' => [], 'qa' => []];
            foreach ($columns as $col) {
                $colsByType[$col['component_type']][] = $col;
            }

            // Calculate HPS totals per component
            $hpsTotals = [];
            foreach (['ww', 'pt', 'qa'] as $type) {
                $hpsTotals[$type] = 0;
                foreach ($colsByType[$type] as $col) {
                    // Use updated HPS if provided
                    $h = isset($hpsUpdates[$col['id']]) ? (float)$hpsUpdates[$col['id']] : (float)$col['highest_possible_score'];
                    $hpsTotals[$type] += $h;
                }
            }

            // Determine weights based on subject weight_category
            $wwWeight = 0.30; $ptWeight = 0.50; $qaWeight = 0.20;
            if (in_array($gradeLevel, ['pre', 'ele', 'jhs'])) {
                switch ($weightCategory) {
                    case 'languages': $wwWeight = 0.30; $ptWeight = 0.50; $qaWeight = 0.20; break;
                    case 'science_math': $wwWeight = 0.40; $ptWeight = 0.40; $qaWeight = 0.20; break;
                    case 'mapeh_epp': $wwWeight = 0.20; $ptWeight = 0.60; $qaWeight = 0.20; break;
                    default: $wwWeight = 0.30; $ptWeight = 0.50; $qaWeight = 0.20;
                }
            } else {
                switch ($weightCategory) {
                    case 'core': $wwWeight = 0.25; $ptWeight = 0.50; $qaWeight = 0.25; break;
                    case 'academic': $wwWeight = 0.25; $ptWeight = 0.45; $qaWeight = 0.30; break;
                    case 'work_immersion': $wwWeight = 0.35; $ptWeight = 0.40; $qaWeight = 0.25; break;
                    case 'tvl_sports_arts': $wwWeight = 0.20; $ptWeight = 0.60; $qaWeight = 0.20; break;
                    default: $wwWeight = 0.25; $ptWeight = 0.50; $qaWeight = 0.25;
                }
            }

            // Save each student's scores and compute grades
            foreach ($scores as $enrollId => $studentScores) {
                $enrollId = (int)$enrollId;

                // Save individual raw scores
                foreach ($studentScores as $colId => $scoreVal) {
                    $colId = (int)$colId;
                    if ($scoreVal === '' || $scoreVal === null) {
                        db()->prepare("DELETE FROM tbl_raw_scores WHERE enroll_id = ? AND column_id = ?")->execute([$enrollId, $colId]);
                    } else {
                        $stmt = db()->prepare("INSERT INTO tbl_raw_scores (enroll_id, column_id, score) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()");
                        $stmt->execute([$enrollId, $colId, (float)$scoreVal]);
                    }
                }

                // Calculate totals per component for this student
                $studentTotals = ['ww' => 0, 'pt' => 0, 'qa' => 0];
                foreach (['ww', 'pt', 'qa'] as $type) {
                    foreach ($colsByType[$type] as $col) {
                        $cid = (string)$col['id'];
                        $val = isset($studentScores[$cid]) && $studentScores[$cid] !== '' ? (float)$studentScores[$cid] : 0;
                        $studentTotals[$type] += $val;
                    }
                }

                // PS = (total / HPS) * 100
                $wwPs = $hpsTotals['ww'] > 0 ? ($studentTotals['ww'] / $hpsTotals['ww']) * 100 : 0;
                $ptPs = $hpsTotals['pt'] > 0 ? ($studentTotals['pt'] / $hpsTotals['pt']) * 100 : 0;
                $qaPs = $hpsTotals['qa'] > 0 ? ($studentTotals['qa'] / $hpsTotals['qa']) * 100 : 0;

                // WS = PS * weight
                $wwWs = $wwPs * $wwWeight;
                $ptWs = $ptPs * $ptWeight;
                $qaWs = $qaPs * $qaWeight;

                // Initial Grade = sum of WS
                $initialGrade = $wwWs + $ptWs + $qaWs;

                // Transmutation (DepEd standard)
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

                $newStatus = $submitForApproval ? 'submitted' : 'draft';

                // Save or update tbl_grades
                $checkStmt = db()->prepare("SELECT id, status FROM tbl_grades WHERE enroll_id = ? AND term_id = ? AND grading_period = ?");
                $checkStmt->execute([$enrollId, $termId, $gradingPeriod]);
                $existingGrade = $checkStmt->fetch();

                if ($existingGrade && !in_array($existingGrade['status'], ['pending', 'draft'])) {
                    continue; // Skip locked grades
                }

                if ($existingGrade) {
                    $stmt = db()->prepare("UPDATE tbl_grades SET teacher_id = ?, ww_total = ?, pt_total = ?, qa_score = ?, ww_ps = ?, pt_ps = ?, qa_ps = ?, ww_ws = ?, pt_ws = ?, qa_ws = ?, initial_grade = ?, period_grade = ?, status = ?, is_direct_input = 0, submitted_at = CASE WHEN ? = 'submitted' THEN NOW() ELSE submitted_at END, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$teacherId, round($studentTotals['ww'], 2), round($studentTotals['pt'], 2), round($studentTotals['qa'], 2), round($wwPs, 2), round($ptPs, 2), round($qaPs, 2), round($wwWs, 2), round($ptWs, 2), round($qaWs, 2), round($initialGrade, 2), $periodGrade, $newStatus, $newStatus, $existingGrade['id']]);
                } else {
                    $stmt = db()->prepare("INSERT INTO tbl_grades (enroll_id, teacher_id, term_id, grading_period, ww_total, pt_total, qa_score, ww_ps, pt_ps, qa_ps, ww_ws, pt_ws, qa_ws, initial_grade, period_grade, status, is_direct_input, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, CASE WHEN ? = 'submitted' THEN NOW() ELSE NULL END)");
                    $stmt->execute([$enrollId, $teacherId, $termId, $gradingPeriod, round($studentTotals['ww'], 2), round($studentTotals['pt'], 2), round($studentTotals['qa'], 2), round($wwPs, 2), round($ptPs, 2), round($qaPs, 2), round($wwWs, 2), round($ptWs, 2), round($qaWs, 2), round($initialGrade, 2), $periodGrade, $newStatus, $newStatus]);
                }
            }

            db()->commit();
            echo json_encode(['success' => true, 'message' => $submitForApproval ? 'Grades submitted for approval!' : 'All grades saved!']);
        } catch (Exception $e) {
            db()->rollBack();
            echo json_encode(['success' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ---------- Page Load ----------

// Get filters
$sectionFilter = (int)($_GET['section'] ?? 0);
$subjectFilter = (int)($_GET['subject'] ?? 0);
$termFilter = (int)($_GET['term'] ?? 0);
$gradingPeriodFilter = $_GET['grading_period'] ?? '';

// Get teacher's sections
$sectionsStmt = db()->prepare("
    SELECT DISTINCT s.id, s.section_code FROM tbl_section s 
    JOIN tbl_teacher_subject ts ON ts.section_id = s.id
    WHERE ts.teacher_id = ? AND ts.status = 'active' 
    ORDER BY s.section_code
");
$sectionsStmt->execute([$teacherId]);
$sections = $sectionsStmt->fetchAll();

// Get subjects (filtered by section)
if ($sectionFilter) {
    $subjectsStmt = db()->prepare("
        SELECT DISTINCT sub.* FROM tbl_subjects sub
        JOIN tbl_teacher_subject ts ON ts.subject_id = sub.id
        WHERE ts.teacher_id = ? AND ts.section_id = ? AND ts.status = 'active'
        ORDER BY sub.subjcode
    ");
    $subjectsStmt->execute([$teacherId, $sectionFilter]);
} else {
    $subjectsStmt = db()->prepare("
        SELECT DISTINCT sub.* FROM tbl_subjects sub
        JOIN tbl_teacher_subject ts ON ts.subject_id = sub.id
        WHERE ts.teacher_id = ? AND ts.status = 'active'
        ORDER BY sub.subjcode
    ");
    $subjectsStmt->execute([$teacherId]);
}
$subjects = $subjectsStmt->fetchAll();

// Determine enrollment type from section
$enrollmentType = 'semestral';
$isYearly = false;
$sectionTrack = [];
$deptCode = '';
$isShsDept = false;

if ($sectionFilter) {
    $trackStmt = db()->prepare("
        SELECT at.code, at.enrollment_type, d.code as dept_code
        FROM tbl_section s 
        JOIN tbl_academic_track at ON s.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        WHERE s.id = ?
    ");
    $trackStmt->execute([$sectionFilter]);
    $sectionTrack = $trackStmt->fetch();
    if ($sectionTrack) {
        $enrollmentType = $sectionTrack['enrollment_type'] ?? 'semestral';
        $isYearly = ($enrollmentType === 'yearly');
        $deptCode = strtoupper($sectionTrack['dept_code'] ?? '');
        $isShsDept = ($deptCode === 'SHS');
    }
}

// Get terms - all education levels now use semester terms
$isCollege = in_array($deptCode, ['CCTE', 'CON']);
$terms = [];
if (!$isYearly) {
    $termQuery = "SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.status = 'active'";
    if ($isShsDept) {
        $termQuery .= " AND t.term_name LIKE 'Semester%'";
    } else {
        $termQuery .= " AND (t.term_name LIKE 'Semester%' OR t.term_name = 'Summer')";
    }
    $termQuery .= " ORDER BY t.start_date ASC, t.id ASC";
    $termStmt = db()->prepare($termQuery);
    $termStmt->execute();
    $terms = $termStmt->fetchAll();
}

// Auto-select term (SHS/College only)
if (!$isYearly && !$termFilter && $sectionFilter && !empty($terms)) {
    $termFilter = (int)$terms[0]['id'];
}

// Grading period options - determined by education level
$educationLevelKey = $isCollege ? 'college' : ($isShsDept ? 'shs' : 'k12');
$selectedTermName = '';
if (!$isYearly) {
    foreach ($terms as $t) {
        if ($t['id'] == $termFilter) {
            $selectedTermName = $t['term_name'];
            break;
        }
    }
}
$gradingPeriodOptions = getGradingPeriodOptions($educationLevelKey, $selectedTermName);
$gradingPeriodCode = '';
if ($gradingPeriodFilter && isset($gradingPeriodOptions[$gradingPeriodFilter])) {
    $gradingPeriodCode = $gradingPeriodFilter;
}
if (!$gradingPeriodCode && !empty($gradingPeriodOptions)) {
    $gradingPeriodCode = array_key_first($gradingPeriodOptions);
}

// For K-10 (yearly): resolve term_id from grading period + section's school year
if ($isYearly && $gradingPeriodCode && $sectionFilter) {
    $sySt = db()->prepare("SELECT sy_id FROM tbl_section WHERE id = ?");
    $sySt->execute([$sectionFilter]);
    $sectionSyId = (int)($sySt->fetchColumn() ?: 0);
    if ($sectionSyId) {
        $termFilter = resolveK10TermId($gradingPeriodCode, $sectionSyId) ?? 0;
    }
}

// Get enrolled students for this class
$enrollments = [];
$gradeColumns = ['ww' => [], 'pt' => [], 'qa' => []];
$rawScores = []; // enroll_id => column_id => score
$selectedSubjectInfo = null;

if ($sectionFilter && $subjectFilter && $termFilter && $gradingPeriodCode) {
    // Get students
    $stmt = db()->prepare("
        SELECT e.id as enroll_id, e.student_id,
               CONCAT_WS(' ', st.last_name, ',', st.given_name, st.middle_name) as student_name,
               sub.weight_category, at.code as course_code, d.code as dept_code,
               g.id as grade_id, g.ww_ps, g.pt_ps, g.qa_ps, g.ww_ws, g.pt_ws, g.qa_ws,
               g.initial_grade, g.period_grade, g.status as grade_status, g.remarks as grade_remarks
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

    // Get subject info
    $subStmt = db()->prepare("SELECT * FROM tbl_subjects WHERE id = ?");
    $subStmt->execute([$subjectFilter]);
    $selectedSubjectInfo = $subStmt->fetch();

    // Get column definitions
    $colStmt = db()->prepare("SELECT * FROM tbl_grade_columns WHERE teacher_id = ? AND section_id = ? AND subject_id = ? AND term_id = ? AND grading_period = ? ORDER BY component_type, column_number");
    $colStmt->execute([$teacherId, $sectionFilter, $subjectFilter, $termFilter, $gradingPeriodCode]);
    $allCols = $colStmt->fetchAll();
    foreach ($allCols as $col) {
        $gradeColumns[$col['component_type']][] = $col;
    }

    // Get raw scores for all enrolled students
    if (!empty($enrollments) && !empty($allCols)) {
        $enrollIds = array_column($enrollments, 'enroll_id');
        $colIds = array_column($allCols, 'id');
        $ePh = implode(',', array_fill(0, count($enrollIds), '?'));
        $cPh = implode(',', array_fill(0, count($colIds), '?'));
        $scoreStmt = db()->prepare("SELECT * FROM tbl_raw_scores WHERE enroll_id IN ($ePh) AND column_id IN ($cPh)");
        $scoreStmt->execute(array_merge($enrollIds, $colIds));
        foreach ($scoreStmt->fetchAll() as $rs) {
            $rawScores[(int)$rs['enroll_id']][(int)$rs['column_id']] = $rs['score'];
        }
    }
}

// Determine grade level and weights
$gradeLevel = 'jhs';
if ($isShsDept) $gradeLevel = 'shs';
elseif (!empty($enrollments)) {
    $cc = strtolower($enrollments[0]['course_code'] ?? '');
    if (strpos($cc, 'pre') !== false || strpos($cc, 'nursery') !== false || strpos($cc, 'kinder') !== false) $gradeLevel = 'pre';
    elseif (strpos($cc, 'grade1') !== false || strpos($cc, 'grade2') !== false || strpos($cc, 'grade3') !== false || strpos($cc, 'grade4') !== false || strpos($cc, 'grade5') !== false || strpos($cc, 'grade6') !== false) $gradeLevel = 'ele';
}

$weightCat = $selectedSubjectInfo['weight_category'] ?? 'languages';
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

include '../includes/header.php';
include '../includes/sidebar_teacher.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
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

<!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-800 mb-4">Select Class</h3>
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" id="sectionSelect" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionFilter == $sec['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sec['section_code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                    <select name="subject" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= $subjectFilter == $sub['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['subjcode'] . ' - ' . $sub['desc']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$isYearly): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Term/Semester</label>
                    <select name="term" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Term</option>
                        <?php foreach ($terms as $term): ?>
                        <option value="<?= $term['id'] ?>" <?= $termFilter == $term['id'] ? 'selected' : '' ?>><?= htmlspecialchars($term['term_name'] . ' (' . ($term['sy_name'] ?? '') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Grading Period</label>
                    <select name="grading_period" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <?php foreach ($gradingPeriodOptions as $code => $name): ?>
                        <option value="<?= $code ?>" <?= $gradingPeriodCode == $code ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="w-full px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">Load</button>
                </div>
            </form>
        </div>
    <div class="p-4 sm:p-8">
        <?php
        // Build shared filter params for mode toggle links
        $toggleParams = array_filter([
            'section' => $sectionFilter,
            'subject' => $subjectFilter,
            'term' => $termFilter,
            'grading_period' => $gradingPeriodCode ?? '',
        ]);
        ?>
        <!-- Grading Mode Toggle -->
        <div class="mb-4 flex items-center gap-4">
            <span class="text-sm font-medium text-gray-700">Grading Mode:</span>
            <div class="flex flex-wrap bg-gray-100 rounded-lg p-1">
                <a href="grades.php?<?= http_build_query(array_merge($toggleParams, ['mode' => 'detailed'])) ?>" 
                   class="px-4 py-2 rounded-md text-sm font-medium transition text-gray-600 hover:text-black">
                    Detailed (WW, PT, QA)
                </a>
                <a href="grades.php?<?= http_build_query(array_merge($toggleParams, ['mode' => 'direct'])) ?>" 
                   class="px-4 py-2 rounded-md text-sm font-medium transition text-gray-600 hover:text-black">
                    Direct (Final Grade Only)
                </a>
                <a href="raw_scores.php?<?= http_build_query($toggleParams) ?>" 
                   class="px-4 py-2 rounded-md text-sm font-medium transition bg-white shadow text-black">
                    Raw Score (DepEd Format)
                </a>
            </div>
        </div>
        
        

        <?php if ($sectionFilter && $subjectFilter && $termFilter && $gradingPeriodCode && !empty($enrollments)): ?>
        <!-- Weight Info -->
        <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800">
            <strong>Weights:</strong> Written Work = <?= $wwPct ?>%, Performance Task = <?= $ptPct ?>%, Quarterly Assessment = <?= $qaPct ?>%
            <?php if ($selectedSubjectInfo): ?>
            &nbsp;|&nbsp; <strong>Subject:</strong> <?= htmlspecialchars($selectedSubjectInfo['subjcode'] . ' - ' . ($selectedSubjectInfo['desc'] ?? '')) ?>
            (<?= ucfirst(str_replace('_', ' ', $weightCat)) ?>)
            <?php endif; ?>
        </div>

        <!-- Status message area -->
        <div id="statusMsg" class="hidden mb-4 p-3 rounded-lg text-sm"></div>

        <!-- Raw Score Spreadsheet -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-lg font-semibold text-gray-800">Raw Score Sheet</h3>
                <div class="flex gap-2">
                    <button onclick="saveAllGrades(false)" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm">Save as Draft</button>
                    <button onclick="saveAllGrades(true)" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm">Save & Submit</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="rawScoreTable">
                    <thead>
                        <!-- Component Header Row -->
                        <tr class="bg-gray-800 text-white text-xs">
                            <th rowspan="3" class="px-3 py-2 text-left font-medium border-r border-gray-600 sticky left-0 bg-gray-800 z-10 min-w-[180px]">Learner</th>
                            <!-- Written Work columns -->
                            <th colspan="<?= max(count($gradeColumns['ww']), 1) + 3 ?>" class="px-2 py-2 text-center font-medium border-r border-gray-600 bg-gray-700" id="wwHeader">
                                Written Work (<?= $wwPct ?>%)
                                <button onclick="addColumn('ww')" class="ml-1 px-1.5 py-0.5 bg-green-500 text-white rounded text-xs hover:bg-green-600" title="Add WW column">+</button>
                            </th>
                            <!-- Performance Task columns -->
                            <th colspan="<?= max(count($gradeColumns['pt']), 1) + 3 ?>" class="px-2 py-2 text-center font-medium border-r border-gray-600 bg-gray-700" id="ptHeader">
                                Performance Tasks (<?= $ptPct ?>%)
                                <button onclick="addColumn('pt')" class="ml-1 px-1.5 py-0.5 bg-green-500 text-white rounded text-xs hover:bg-green-600" title="Add PT column">+</button>
                            </th>
                            <!-- Quarterly Assessment columns -->
                            <th colspan="<?= max(count($gradeColumns['qa']), 1) + 3 ?>" class="px-2 py-2 text-center font-medium border-r border-gray-600 bg-gray-700" id="qaHeader">
                                Quarterly Assessment (<?= $qaPct ?>%)
                                <button onclick="addColumn('qa')" class="ml-1 px-1.5 py-0.5 bg-green-500 text-white rounded text-xs hover:bg-green-600" title="Add QA column">+</button>
                            </th>
                            <th rowspan="3" class="px-2 py-2 text-center font-medium bg-yellow-600 min-w-[60px]">Initial<br>Grade</th>
                            <th rowspan="3" class="px-2 py-2 text-center font-medium bg-green-700 min-w-[70px]">Quarterly<br>Grade</th>
                        </tr>
                        <!-- Sub-header: individual column numbers -->
                        <tr class="bg-gray-100 text-gray-700 text-xs" id="colNumberRow">
                            <?php foreach (['ww', 'pt', 'qa'] as $type): ?>
                                <?php if (empty($gradeColumns[$type])): ?>
                                <th class="px-2 py-1 text-center border border-gray-200 text-gray-400 italic">—</th>
                                <?php else: ?>
                                    <?php foreach ($gradeColumns[$type] as $col): ?>
                                    <th class="px-2 py-1 text-center border border-gray-200 min-w-[55px] col-<?= $type ?>-<?= $col['id'] ?>">
                                        <?= strtoupper($type) . $col['column_number'] ?>
                                        <button onclick="deleteColumn(<?= $col['id'] ?>, '<?= $type ?>')" class="ml-0.5 text-red-400 hover:text-red-600" title="Delete column">×</button>
                                    </th>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <th class="px-2 py-1 text-center border border-gray-200 font-semibold bg-gray-50">Total</th>
                                <th class="px-2 py-1 text-center border border-gray-200 font-semibold bg-gray-50">PS</th>
                                <th class="px-2 py-1 text-center border border-gray-200 font-semibold bg-gray-50">WS</th>
                            <?php endforeach; ?>
                        </tr>
                        <!-- HPS Row -->
                        <tr class="bg-yellow-50 text-xs font-semibold" id="hpsRow">
                            <?php foreach (['ww', 'pt', 'qa'] as $type): ?>
                                <?php if (empty($gradeColumns[$type])): ?>
                                <td class="px-2 py-1 text-center border border-gray-200 text-gray-400">—</td>
                                <?php else: ?>
                                    <?php foreach ($gradeColumns[$type] as $col): ?>
                                    <td class="px-1 py-1 text-center border border-gray-200 hps-cell col-<?= $type ?>-<?= $col['id'] ?>">
                                        <input type="number" min="0" step="1" value="<?= (int)$col['highest_possible_score'] ?>"
                                            data-col-id="<?= $col['id'] ?>" data-type="<?= $type ?>"
                                            class="hps-input w-14 px-1 py-0.5 border border-gray-300 rounded text-center text-xs focus:ring-1 focus:ring-black"
                                            onchange="autoSaveHps(this)">
                                    </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <td class="px-2 py-1 text-center border border-gray-200 font-bold hps-total-<?= $type ?>">
                                    <?php $hpsTotal = 0; foreach ($gradeColumns[$type] as $c) $hpsTotal += (float)$c['highest_possible_score']; echo $hpsTotal; ?>
                                </td>
                                <td class="px-2 py-1 text-center border border-gray-200">100%</td>
                                <td class="px-2 py-1 text-center border border-gray-200"><?= $type === 'ww' ? $wwPct : ($type === 'pt' ? $ptPct : $qaPct) ?>%</td>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $idx => $enroll): 
                            $eid = $enroll['enroll_id'];
                            $isEditable = in_array($enroll['grade_status'] ?? 'pending', ['pending', 'draft']);
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50 student-row" data-enroll-id="<?= $eid ?>">
                            <td class="px-3 py-2 font-medium text-gray-800 border-r border-gray-200 sticky left-0 bg-white z-10 text-xs">
                                <div class="flex items-center gap-1">
                                    <?= htmlspecialchars($enroll['student_name']) ?>
                                    <?php if (!$isEditable): ?>
                                    <svg class="w-3.5 h-3.5 text-gray-800 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" title="Grade locked"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                                    <?php endif; ?>
                                </div>
                                <?php
                                    $remarks = $enroll['grade_remarks'] ?? '';
                                    if ($remarks !== '' && in_array($enroll['grade_status'] ?? '', ['draft', 'pending'])):
                                ?>
                                <div class="mt-1 flex items-start gap-1 px-1.5 py-1 bg-red-50 border border-red-200 rounded text-[10px] text-red-700 leading-tight max-w-[220px]">
                                    <svg class="w-3 h-3 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span><strong>Returned:</strong> <?= htmlspecialchars($remarks) ?></span>
                                </div>
                                <?php endif; ?>
                            </td>
                            <?php foreach (['ww', 'pt', 'qa'] as $type): 
                                $typeCols = $gradeColumns[$type];
                                $studentTotal = 0;
                            ?>
                                <?php if (empty($typeCols)): ?>
                                <td class="px-2 py-1 text-center border border-gray-200 text-gray-400 text-xs">—</td>
                                <?php else: ?>
                                    <?php foreach ($typeCols as $col): 
                                        $scoreVal = $rawScores[$eid][$col['id']] ?? '';
                                        if ($scoreVal !== '') $studentTotal += (float)$scoreVal;
                                    ?>
                                    <td class="px-1 py-1 border border-gray-200 score-cell col-<?= $type ?>-<?= $col['id'] ?>">
                                        <input type="number" min="0" step="0.01" 
                                            value="<?= $scoreVal !== '' ? $scoreVal : '' ?>"
                                            max="<?= (int)$col['highest_possible_score'] ?>"
                                            data-enroll-id="<?= $eid ?>" data-col-id="<?= $col['id'] ?>" data-type="<?= $type ?>"
                                            class="score-input w-14 px-1 py-0.5 border border-gray-300 rounded text-center text-xs focus:ring-1 focus:ring-black <?= !$isEditable ? 'bg-gray-100 cursor-not-allowed' : '' ?>"
                                            <?= !$isEditable ? 'disabled' : '' ?>
                                            onchange="autoSaveScore(this)">
                                    </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php
                                    // Calculate PS
                                    $hpsTotal = 0;
                                    foreach ($typeCols as $c) $hpsTotal += (float)$c['highest_possible_score'];
                                    $ps = $hpsTotal > 0 ? ($studentTotal / $hpsTotal) * 100 : 0;
                                    $weight = ($type === 'ww' ? $wwPct : ($type === 'pt' ? $ptPct : $qaPct)) / 100;
                                    $ws = $ps * $weight;
                                ?>
                                <td class="px-2 py-1 text-center border border-gray-200 text-xs font-medium total-<?= $type ?>"><?= round($studentTotal, 2) ?></td>
                                <td class="px-2 py-1 text-center border border-gray-200 text-xs ps-<?= $type ?>"><?= number_format($ps, 2) ?></td>
                                <td class="px-2 py-1 text-center border border-gray-200 text-xs font-medium ws-<?= $type ?>"><?= number_format($ws, 2) ?></td>
                            <?php endforeach; ?>
                            <td class="px-2 py-1 text-center border border-gray-200 text-xs font-semibold bg-yellow-50 initial-grade"><?= $enroll['initial_grade'] ? number_format((float)$enroll['initial_grade'], 2) : '—' ?></td>
                            <td class="px-2 py-1 text-center border border-gray-200 text-xs font-bold quarterly-grade">
                                <?php if ($enroll['period_grade']): ?>
                                <span class="px-1.5 py-0.5 rounded-full text-xs <?= $enroll['period_grade'] >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= (int)$enroll['period_grade'] ?>
                                </span>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-4 sm:px-6 py-4 border-t border-gray-100 flex flex-wrap items-center justify-between gap-3 bg-gray-50">
                <p class="text-xs text-gray-500"></p>
                <div class="flex gap-2">
                    <button onclick="saveAllGrades(false)" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm">Save as Draft</button>
                    <button onclick="saveAllGrades(true)" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm">Save & Submit</button>
                </div>
            </div>
        </div>

        <?php elseif ($sectionFilter && $subjectFilter && $termFilter && empty($enrollments)): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No Students Found</h3>
            <p class="text-gray-500">No enrolled students for this section/subject combination.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">Select a Class</h3>
            <p class="text-gray-500">Choose a section, subject, and term to start entering raw scores.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
const CONFIG = {
    sectionId: <?= $sectionFilter ?: 0 ?>,
    subjectId: <?= $subjectFilter ?: 0 ?>,
    termId: <?= $termFilter ?: 0 ?>,
    gradingPeriod: '<?= htmlspecialchars($gradingPeriodCode) ?>',
    gradeLevel: '<?= htmlspecialchars($gradeLevel) ?>',
    weightCategory: '<?= htmlspecialchars($weightCat) ?>',
    wwWeight: <?= $wwPct / 100 ?>,
    ptWeight: <?= $ptPct / 100 ?>,
    qaWeight: <?= $qaPct / 100 ?>
};

// Transmutation table
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

function getHpsTotals() {
    const totals = { ww: 0, pt: 0, qa: 0 };
    document.querySelectorAll('.hps-input').forEach(inp => {
        const type = inp.dataset.type;
        totals[type] += parseFloat(inp.value) || 0;
    });
    return totals;
}

function recalcRow(row) {
    const hps = getHpsTotals();

    ['ww', 'pt', 'qa'].forEach(type => {
        let total = 0;
        row.querySelectorAll(`.score-input[data-type="${type}"]`).forEach(inp => {
            total += parseFloat(inp.value) || 0;
        });
        const ps = hps[type] > 0 ? (total / hps[type]) * 100 : 0;
        const weight = CONFIG[type + 'Weight'];
        const ws = ps * weight;

        const totalCell = row.querySelector(`.total-${type}`);
        const psCell = row.querySelector(`.ps-${type}`);
        const wsCell = row.querySelector(`.ws-${type}`);
        if (totalCell) totalCell.textContent = total.toFixed(2);
        if (psCell) psCell.textContent = ps.toFixed(2);
        if (wsCell) wsCell.textContent = ws.toFixed(2);
    });

    // Initial grade = sum of WS
    const wwWs = parseFloat(row.querySelector('.ws-ww')?.textContent) || 0;
    const ptWs = parseFloat(row.querySelector('.ws-pt')?.textContent) || 0;
    const qaWs = parseFloat(row.querySelector('.ws-qa')?.textContent) || 0;
    const initialGrade = wwWs + ptWs + qaWs;

    row.querySelector('.initial-grade').textContent = initialGrade.toFixed(2);

    const qg = transmute(initialGrade);
    const qgCell = row.querySelector('.quarterly-grade');
    const colorClass = qg >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
    qgCell.innerHTML = `<span class="px-1.5 py-0.5 rounded-full text-xs ${colorClass}">${qg}</span>`;
}

function recalcAll() {
    // Update HPS totals display
    const hps = getHpsTotals();
    ['ww', 'pt', 'qa'].forEach(type => {
        const cell = document.querySelector(`.hps-total-${type}`);
        if (cell) cell.textContent = hps[type];
    });
    // Recalc each student
    document.querySelectorAll('.student-row').forEach(row => recalcRow(row));
}

function flashSaved(el) {
    el.classList.remove('border-gray-300');
    el.classList.add('border-green-500', 'bg-green-50');
    setTimeout(() => {
        el.classList.remove('border-green-500', 'bg-green-50');
        el.classList.add('border-gray-300');
    }, 600);
}

function flashError(el) {
    el.classList.remove('border-gray-300');
    el.classList.add('border-red-500', 'bg-red-50');
    setTimeout(() => {
        el.classList.remove('border-red-500', 'bg-red-50');
        el.classList.add('border-gray-300');
    }, 1200);
}

async function autoSaveScore(input) {
    recalcRow(input.closest('tr'));
    const enrollId = input.dataset.enrollId;
    const colId = input.dataset.colId;
    const score = input.value;
    try {
        const res = await fetch('?ajax=save_score', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enroll_id: parseInt(enrollId), column_id: parseInt(colId), score: score })
        });
        const data = await res.json();
        if (data.success) {
            flashSaved(input);
        } else {
            flashError(input);
        }
    } catch (e) {
        flashError(input);
    }
}

async function autoSaveHps(input) {
    recalcAll();
    const colId = input.dataset.colId;
    const hps = parseFloat(input.value) || 0;
    try {
        const res = await fetch('?ajax=update_hps', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ column_id: parseInt(colId), highest_possible_score: hps })
        });
        const data = await res.json();
        if (data.success) {
            flashSaved(input);
        } else {
            flashError(input);
        }
    } catch (e) {
        flashError(input);
    }
}

// Enter key: save current input and move to next input below (same column, next row)
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    const el = e.target;
    if (!el.classList.contains('score-input') && !el.classList.contains('hps-input')) return;
    e.preventDefault();

    // Trigger change to auto-save
    el.dispatchEvent(new Event('change'));

    // Move to next row same column
    const td = el.closest('td');
    const tr = td.closest('tr');
    const cellIndex = Array.from(tr.cells).indexOf(td);
    const nextRow = tr.nextElementSibling;
    if (nextRow && nextRow.classList.contains('student-row')) {
        const nextTd = nextRow.cells[cellIndex];
        if (nextTd) {
            const nextInput = nextTd.querySelector('input[type="number"]');
            if (nextInput && !nextInput.disabled) {
                nextInput.focus();
                nextInput.select();
            }
        }
    }
});

async function addColumn(type) {
    const hps = prompt(`Enter Highest Possible Score for new ${type.toUpperCase()} column:`, '20');
    if (hps === null) return;
    const hpsVal = parseFloat(hps);
    if (isNaN(hpsVal) || hpsVal <= 0) { alert('Invalid score.'); return; }

    try {
        const res = await fetch('?ajax=add_column', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                section_id: CONFIG.sectionId,
                subject_id: CONFIG.subjectId,
                term_id: CONFIG.termId,
                grading_period: CONFIG.gradingPeriod,
                component_type: type,
                highest_possible_score: hpsVal
            })
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Failed to add column');
        }
    } catch (e) {
        alert('Network error');
    }
}

async function deleteColumn(columnId, type) {
    if (!confirm(`Delete this ${type.toUpperCase()} column? All scores in this column will be deleted.`)) return;

    try {
        const res = await fetch('?ajax=delete_column', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ column_id: columnId })
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Failed to delete column');
        }
    } catch (e) {
        alert('Network error');
    }
}

async function saveAllGrades(submit) {
    const msg = submit ? 'Submit all grades for approval? This will lock them for editing.' : 'Save all grades as draft?';
    if (!confirm(msg)) return;

    // Collect all scores
    const scores = {};
    const hpsData = {};

    document.querySelectorAll('.hps-input').forEach(inp => {
        hpsData[inp.dataset.colId] = parseFloat(inp.value) || 0;
    });

    document.querySelectorAll('.student-row').forEach(row => {
        const enrollId = row.dataset.enrollId;
        scores[enrollId] = {};
        row.querySelectorAll('.score-input').forEach(inp => {
            scores[enrollId][inp.dataset.colId] = inp.value !== '' ? parseFloat(inp.value) : '';
        });
    });

    const statusEl = document.getElementById('statusMsg');
    statusEl.className = 'mb-4 p-3 rounded-lg text-sm bg-blue-50 text-blue-700';
    statusEl.textContent = 'Saving...';
    statusEl.classList.remove('hidden');

    try {
        const res = await fetch('?ajax=save_all', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                section_id: CONFIG.sectionId,
                subject_id: CONFIG.subjectId,
                term_id: CONFIG.termId,
                grading_period: CONFIG.gradingPeriod,
                grade_level: CONFIG.gradeLevel,
                weight_category: CONFIG.weightCategory,
                scores: scores,
                hps: hpsData,
                submit: submit
            })
        });
        const data = await res.json();
        if (data.success) {
            statusEl.className = 'mb-4 p-3 rounded-lg text-sm bg-green-50 text-green-700';
            statusEl.textContent = data.message || 'Saved!';
            if (submit) {
                setTimeout(() => location.reload(), 1000);
            }
        } else {
            statusEl.className = 'mb-4 p-3 rounded-lg text-sm bg-red-50 text-red-700';
            statusEl.textContent = data.error || 'Save failed';
        }
    } catch (e) {
        statusEl.className = 'mb-4 p-3 rounded-lg text-sm bg-red-50 text-red-700';
        statusEl.textContent = 'Network error';
    }
}

// Initial calculation on page load
document.addEventListener('DOMContentLoaded', recalcAll);
</script>

<?php include '../includes/footer.php'; ?>
