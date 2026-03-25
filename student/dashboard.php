<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('student');

$pageTitle = 'Dashboard';
$studentId = $_SESSION['student_id'] ?? 0;

// Get student info with section
$stmt = db()->prepare("
    SELECT st.*, sec.section_code, sec.sy_id, at.code as course_code, at.`desc` as course_name,
           t.name as adviser_name, sy.sy_name
    FROM tbl_student st
    LEFT JOIN tbl_section sec ON st.section_id = sec.id
    LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN tbl_teacher t ON sec.adviser_id = t.id
    LEFT JOIN tbl_sy sy ON sec.sy_id = sy.id
    WHERE st.id = ?
");
$stmt->execute([$studentId]);
$studentInfo = $stmt->fetch();

// Get current school year from student's section
$currentSyId = 0;
if ($studentInfo) {
    $currentSyId = (int)($studentInfo['sy_id'] ?? 0);
}

// Get enrolled subjects count for current SY
$subjectsCount = db()->prepare("SELECT COUNT(*) FROM tbl_enroll WHERE student_id = ? AND sy_id = ?");
$subjectsCount->execute([$studentId, $currentSyId]);
$totalSubjects = $subjectsCount->fetchColumn();

// Determine education level and enrollment type
$deptCode = '';
$isYearly = false;
if ($studentInfo) {
    $deptStmt = db()->prepare("
        SELECT d.code, at.enrollment_type
        FROM tbl_section sec
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        JOIN tbl_departments d ON at.dept_id = d.id
        WHERE sec.id = ?
    ");
    $deptStmt->execute([$studentInfo['section_id']]);
    $deptInfo = $deptStmt->fetch();
    $deptCode = $deptInfo['code'] ?? '';
    $isYearly = ($deptInfo['enrollment_type'] ?? 'semestral') === 'yearly';
}
$isShsDept = ($deptCode === 'SHS');
$isCollege = in_array($deptCode, ['CCTE', 'CON']);

// College students can switch school year on dashboard to review previous grades.
$schoolYears = [];
$selectedSyId = $currentSyId;
$selectedSyName = $studentInfo['sy_name'] ?? '';
if ($isCollege && $studentId) {
    $syStmt = db()->prepare("
        SELECT DISTINCT sy.id, sy.sy_name
        FROM tbl_enroll e
        JOIN tbl_sy sy ON e.sy_id = sy.id
        WHERE e.student_id = ?
        ORDER BY sy.id DESC
    ");
    $syStmt->execute([$studentId]);
    $schoolYears = $syStmt->fetchAll();

    $requestedSyId = (int)($_GET['sy_id'] ?? $currentSyId);
    $validSyIds = array_map('intval', array_column($schoolYears, 'id'));
    if (!empty($validSyIds) && in_array($requestedSyId, $validSyIds, true)) {
        $selectedSyId = $requestedSyId;
    }

    foreach ($schoolYears as $sy) {
        if ((int)$sy['id'] === (int)$selectedSyId) {
            $selectedSyName = $sy['sy_name'];
            break;
        }
    }
}

$viewSyId = $isCollege ? $selectedSyId : $currentSyId;

// Check if ALL subject grades are complete (all periods approved for all subjects)
$allSubjectsComplete = false;
$gwa = 0;
$recentGrades = [];

if ($isYearly) {
    // K-12: check all 4 quarters approved for every subject in current SY
    $checkStmt = db()->prepare("
        SELECT e.id,
               SUM(CASE WHEN g.status IN ('approved','finalized') THEN 1 ELSE 0 END) as approved_count
        FROM tbl_enroll e
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id
        WHERE e.student_id = ? AND e.sy_id = ?
        GROUP BY e.id
    ");
    $checkStmt->execute([$studentId, $viewSyId]);
    $enrollChecks = $checkStmt->fetchAll();
    $allSubjectsComplete = !empty($enrollChecks);
    foreach ($enrollChecks as $ec) {
        if ($ec['approved_count'] < 4) { $allSubjectsComplete = false; break; }
    }
} elseif ($isShsDept) {
    // SHS: check both Q1+Q2 approved for every subject in current SY
    $checkStmt = db()->prepare("
        SELECT e.id,
               SUM(CASE WHEN g.status IN ('approved','finalized') THEN 1 ELSE 0 END) as approved_count
        FROM tbl_enroll e
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id
        WHERE e.student_id = ? AND e.sy_id = ?
        GROUP BY e.id
    ");
    $checkStmt->execute([$studentId, $viewSyId]);
    $enrollChecks = $checkStmt->fetchAll();
    $allSubjectsComplete = !empty($enrollChecks);
    foreach ($enrollChecks as $ec) {
        if ($ec['approved_count'] < 2) { $allSubjectsComplete = false; break; }
    }
} else {
    // College: check all 4 periods approved for every subject in current SY
    $checkStmt = db()->prepare("
        SELECT e.id,
               SUM(CASE WHEN g.status IN ('approved','finalized') THEN 1 ELSE 0 END) as approved_count
        FROM tbl_enroll e
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id
        WHERE e.student_id = ? AND e.sy_id = ?
        GROUP BY e.id
    ");
    $checkStmt->execute([$studentId, $viewSyId]);
    $enrollChecks = $checkStmt->fetchAll();
    $allSubjectsComplete = !empty($enrollChecks);
    foreach ($enrollChecks as $ec) {
        if ($ec['approved_count'] < 4) { $allSubjectsComplete = false; break; }
    }
}

// Get recent approved grades for selected school year
$gradesStmt = db()->prepare("
    SELECT g.*, sub.subjcode, sub.`desc` as subject_name, t.term_name
    FROM tbl_grades g
    JOIN tbl_enroll e ON g.enroll_id = e.id
    JOIN tbl_subjects sub ON e.subject_id = sub.id
    LEFT JOIN tbl_term t ON g.term_id = t.id
    WHERE e.student_id = ? AND e.sy_id = ? AND g.status IN ('approved', 'finalized')
    ORDER BY g.updated_at DESC
    LIMIT 5
");
$gradesStmt->execute([$studentId, $viewSyId]);
$recentGrades = $gradesStmt->fetchAll();

// Count total graded subjects for selected school year (approved)
$gradedCountStmt = db()->prepare("
    SELECT COUNT(DISTINCT e.subject_id)
    FROM tbl_grades g
    JOIN tbl_enroll e ON g.enroll_id = e.id
    WHERE e.student_id = ? AND e.sy_id = ? AND g.status IN ('approved', 'finalized')
");
$gradedCountStmt->execute([$studentId, $viewSyId]);
$gradedSubjectsCount = $gradedCountStmt->fetchColumn();

// Recalculate enrolled subjects based on selected school year.
$subjectsCount->execute([$studentId, $viewSyId]);
$totalSubjects = $subjectsCount->fetchColumn();

if ($allSubjectsComplete) {
    if ($isCollege) {
        // CHED weighted GPA for college: SUM(grade * units) / SUM(units)
        $gwaStmt = db()->prepare("
            SELECT
                SUM(e.final_grade * COALESCE(sub.unit, 0)) / NULLIF(SUM(COALESCE(sub.unit, 0)), 0) AS gwa
            FROM tbl_enroll e
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            WHERE e.student_id = ?
              AND e.sy_id = ?
              AND e.final_grade IS NOT NULL
        ");
        $gwaStmt->execute([$studentId, $viewSyId]);
    } else {
        // Existing non-college general average behavior
        $gwaStmt = db()->prepare("
            SELECT AVG(g.period_grade) as gwa
            FROM tbl_grades g
            JOIN tbl_enroll e ON g.enroll_id = e.id
            WHERE e.student_id = ? AND e.sy_id = ? AND g.period_grade IS NOT NULL AND g.status IN ('approved', 'finalized')
        ");
        $gwaStmt->execute([$studentId, $viewSyId]);
    }
    $gwaResult = $gwaStmt->fetch();
    $gwa = $gwaResult['gwa'] ?? 0;
}

// Count pending grades for selected school year
$pendingStmt = db()->prepare("
    SELECT COUNT(*) FROM tbl_enroll e
    INNER JOIN tbl_grades g ON e.id = g.enroll_id
    WHERE e.student_id = ? AND e.sy_id = ? AND g.status IN ('submitted', 'draft')
");
$pendingStmt->execute([$studentId, $viewSyId]);
$pendingCount = $pendingStmt->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar_student.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Dashboard</h1>
            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                <?php if ($isCollege && !empty($schoolYears)): ?>
                <select
                    onchange="window.location.href='dashboard.php?sy_id='+this.value"
                    class="px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none text-sm text-gray-700"
                >
                    <?php foreach ($schoolYears as $sy): ?>
                    <option value="<?= (int)$sy['id'] ?>" <?= (int)$sy['id'] === (int)$selectedSyId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sy['sy_name']) ?><?= (int)$sy['id'] === (int)$currentSyId ? ' (Current)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <div class="flex items-center gap-2 text-gray-500 text-sm">
                <svg class="w-5 h-5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span><?= getCurrentDate() ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <!-- Welcome Message -->
        <div class="bg-black rounded-xl p-6 text-white mb-8">
            <h2 class="text-2xl font-bold mb-2">Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'Student') ?>!</h2>
            <p class="text-gray-300">Here's an overview of your academic progress.</p>
        </div>

        <?php if ($pendingCount > 0 && !$allSubjectsComplete): ?>
        <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg flex items-start gap-3">
            <svg class="w-5 h-5 text-yellow-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-yellow-800">Grades Pending Approval</p>
                <p class="text-sm text-yellow-700">Your grades are still being processed. They will appear here once all have been approved by the registrar.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Student Info Card -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Student Information</h3>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                <div>
                    <p class="text-sm text-gray-500">Section</p>
                    <p class="font-medium text-lg"><?= htmlspecialchars($studentInfo['section_code'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Course</p>
                    <p class="font-medium"><?= htmlspecialchars($studentInfo['course_name'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">School Year</p>
                    <p class="font-medium"><?= htmlspecialchars($isCollege ? ($selectedSyName ?: ($studentInfo['sy_name'] ?? 'N/A')) : ($studentInfo['sy_name'] ?? 'N/A')) ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Adviser</p>
                    <p class="font-medium"><?= htmlspecialchars($studentInfo['adviser_name'] ?? 'N/A') ?></p>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $totalSubjects ?></p>
                        <p class="text-sm text-gray-500">Enrolled Subjects</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $gradedSubjectsCount ?></p>
                        <p class="text-sm text-gray-500">Graded Subjects</p>
                    </div>
                </div>
            </div>
        </div>       
    </div>
</main>

<?php include '../includes/footer.php'; ?>
