<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('registrar');

$pageTitle = 'Grade Completion';
$message = '';
$messageType = '';

// Filters
$courseFilter = $_GET['course'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$syFilter = $_GET['sy'] ?? '';
$statusFilter = $_GET['status'] ?? 'enrolled'; // enrolled, completed, incomplete, all

// Get filter options
$courses = db()->query("SELECT * FROM tbl_academic_track WHERE status = 'active' ORDER BY code")->fetchAll();
$schoolYears = db()->query("SELECT * FROM tbl_sy ORDER BY id DESC")->fetchAll();

// Get sections based on course filter
if ($courseFilter) {
    $sectionsStmt = db()->prepare("SELECT * FROM tbl_section WHERE academic_track_id = ? AND status = 'active' ORDER BY section_code");
    $sectionsStmt->execute([$courseFilter]);
    $sections = $sectionsStmt->fetchAll();
} else {
    $sections = db()->query("SELECT * FROM tbl_section WHERE status = 'active' ORDER BY section_code")->fetchAll();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'complete':
            $enrollId = (int)($_POST['enroll_id'] ?? 0);
            if ($enrollId > 0) {
                // Determine expected periods for this enrollment's section
                $expStmt = db()->prepare("
                    SELECT d.code as dept_code
                    FROM tbl_enroll e
                    JOIN tbl_section s ON e.section_id = s.id
                    JOIN tbl_academic_track at ON s.academic_track_id = at.id
                    JOIN tbl_departments d ON at.dept_id = d.id
                    WHERE e.id = ?
                ");
                $expStmt->execute([$enrollId]);
                $expInfo = $expStmt->fetch();
                $expDept = strtoupper($expInfo['dept_code'] ?? '');
                $expCount = ($expDept === 'SHS') ? 2 : 4;
                
                // Verify all expected grading periods are approved/finalized
                $checkStmt = db()->prepare("
                    SELECT COUNT(DISTINCT g.grading_period) as approved_periods
                    FROM tbl_grades g 
                    WHERE g.enroll_id = ? AND g.status IN ('approved', 'finalized')
                ");
                $checkStmt->execute([$enrollId]);
                $check = $checkStmt->fetch();
                
                if ((int)$check['approved_periods'] >= $expCount) {
                    $stmt = db()->prepare("UPDATE tbl_enroll SET status = 'completed', updated_at = NOW() WHERE id = ? AND status = 'enrolled'");
                    $stmt->execute([$enrollId]);
                    if ($stmt->rowCount() > 0) {
                        $message = 'Enrollment marked as completed.';
                        $messageType = 'success';
                    } else {
                        $message = 'Enrollment could not be updated. It may already be completed or dropped.';
                        $messageType = 'error';
                    }
                } else {
                    $message = 'Cannot complete: Not all grading periods (' . $check['approved_periods'] . '/' . $expCount . ') are approved/finalized for this enrollment.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'incomplete':
            $enrollId = (int)($_POST['enroll_id'] ?? 0);
            $remarks = sanitize($_POST['remarks'] ?? '');
            if ($enrollId > 0) {
                $stmt = db()->prepare("UPDATE tbl_enroll SET status = 'incomplete', updated_at = NOW() WHERE id = ? AND status = 'enrolled'");
                $stmt->execute([$enrollId]);
                if ($stmt->rowCount() > 0) {
                    $message = 'Enrollment marked as incomplete.';
                    $messageType = 'warning';
                } else {
                    $message = 'Status could not be updated.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'revert':
            $enrollId = (int)($_POST['enroll_id'] ?? 0);
            if ($enrollId > 0) {
                $stmt = db()->prepare("UPDATE tbl_enroll SET status = 'enrolled', updated_at = NOW() WHERE id = ? AND status IN ('completed', 'incomplete')");
                $stmt->execute([$enrollId]);
                if ($stmt->rowCount() > 0) {
                    $message = 'Enrollment reverted to enrolled status.';
                    $messageType = 'success';
                } else {
                    $message = 'Status could not be reverted.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'bulk_complete':
            $enrollIds = $_POST['enroll_ids'] ?? [];
            if (!empty($enrollIds)) {
                $count = 0;
                foreach ($enrollIds as $eid) {
                    $eid = (int)$eid;
                    // Determine expected periods for this enrollment's section
                    $expStmt = db()->prepare("
                        SELECT d.code as dept_code
                        FROM tbl_enroll e
                        JOIN tbl_section s ON e.section_id = s.id
                        JOIN tbl_academic_track at ON s.academic_track_id = at.id
                        JOIN tbl_departments d ON at.dept_id = d.id
                        WHERE e.id = ?
                    ");
                    $expStmt->execute([$eid]);
                    $expInfo = $expStmt->fetch();
                    $expDept = strtoupper($expInfo['dept_code'] ?? '');
                    $expCount = ($expDept === 'SHS') ? 2 : 4;
                    
                    // Verify all expected grading periods are approved/finalized
                    $checkStmt = db()->prepare("
                        SELECT COUNT(DISTINCT g.grading_period) as approved_periods
                        FROM tbl_grades g WHERE g.enroll_id = ? AND g.status IN ('approved', 'finalized')
                    ");
                    $checkStmt->execute([$eid]);
                    $check = $checkStmt->fetch();
                    
                    if ((int)$check['approved_periods'] >= $expCount) {
                        $stmt = db()->prepare("UPDATE tbl_enroll SET status = 'completed', updated_at = NOW() WHERE id = ? AND status = 'enrolled'");
                        $stmt->execute([$eid]);
                        $count += $stmt->rowCount();
                    }
                }
                $message = "$count enrollment(s) marked as completed.";
                $messageType = $count > 0 ? 'success' : 'warning';
            }
            break;
    }
}

// Determine expected grading periods based on section's education level
$expectedPeriods = 4; // default K-12
$educationLevel = 'k12';
if ($sectionFilter) {
    $eduStmt = db()->prepare("
        SELECT d.code as dept_code, at.enrollment_type 
        FROM tbl_section s 
        JOIN tbl_academic_track at ON s.academic_track_id = at.id 
        JOIN tbl_departments d ON at.dept_id = d.id 
        WHERE s.id = ?
    ");
    $eduStmt->execute([$sectionFilter]);
    $eduInfo = $eduStmt->fetch();
    if ($eduInfo) {
        $deptCode = strtoupper($eduInfo['dept_code']);
        if ($deptCode === 'SHS') {
            $expectedPeriods = 2;
            $educationLevel = 'shs';
        } elseif (in_array($deptCode, ['CCTE', 'CON'])) {
            $expectedPeriods = 4;
            $educationLevel = 'college';
        } else {
            $expectedPeriods = 4;
            $educationLevel = 'k12';
        }
    }
}

// Fetch enrollments
$enrollments = [];
if ($sectionFilter) {
    $statusCondition = '';
    $params = [$sectionFilter];
    
    if ($statusFilter && $statusFilter !== 'all') {
        $statusCondition = "AND e.status = ?";
        $params[] = $statusFilter;
    }
    
    $syCondition = '';
    if ($syFilter) {
        $syCondition = "AND e.sy_id = ?";
        $params[] = $syFilter;
    }
    
    $enrollStmt = db()->prepare("
        SELECT e.*, 
               CONCAT_WS(' ', st.last_name, ',', st.given_name, st.middle_name) as student_name,
               st.student_no,
               sub.subjcode, sub.`desc` as subject_name, sub.unit,
               sec.section_code,
               sy.sy_name,
               t.term_name,
               teach.name as teacher_name,
               (SELECT COUNT(DISTINCT g.grading_period) FROM tbl_grades g WHERE g.enroll_id = e.id) as total_graded,
               (SELECT COUNT(DISTINCT g.grading_period) FROM tbl_grades g WHERE g.enroll_id = e.id AND g.status IN ('approved', 'finalized')) as approved_periods
        FROM tbl_enroll e
        JOIN tbl_student st ON e.student_id = st.id
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        JOIN tbl_section sec ON e.section_id = sec.id
        LEFT JOIN tbl_sy sy ON e.sy_id = sy.id
        LEFT JOIN tbl_term t ON e.term_id = t.id
        LEFT JOIN tbl_teacher teach ON e.teacher_id = teach.id
        WHERE e.section_id = ? $statusCondition $syCondition
        ORDER BY st.last_name, st.given_name, sub.subjcode
    ");
    $enrollStmt->execute($params);
    $enrollments = $enrollStmt->fetchAll();
}

// Summary stats for selected section
$sectionStats = ['enrolled' => 0, 'completed' => 0, 'incomplete' => 0, 'dropped' => 0];
if ($sectionFilter) {
    $statsParams = [$sectionFilter];
    $syStatsCondition = '';
    if ($syFilter) {
        $syStatsCondition = "AND e.sy_id = ?";
        $statsParams[] = $syFilter;
    }
    $statStmt = db()->prepare("
        SELECT e.status, COUNT(*) as cnt 
        FROM tbl_enroll e 
        WHERE e.section_id = ? $syStatsCondition
        GROUP BY e.status
    ");
    $statStmt->execute($statsParams);
    foreach ($statStmt->fetchAll() as $s) {
        $sectionStats[$s['status']] = $s['cnt'];
    }
}

include '../includes/header.php';
include '../includes/sidebar_registrar.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Grade Completion</h1>
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
        <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : ($messageType === 'warning' ? 'bg-yellow-50 text-yellow-800 border border-yellow-200' : 'bg-red-50 text-red-800 border border-red-200') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-800 mb-4">Filter Enrollments</h3>
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                    <select name="sy" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="">All School Years</option>
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>" <?= $syFilter == $sy['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sy['sy_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Course/Program</label>
                    <select name="course" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>" <?= $courseFilter == $course['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['code'] . ' - ' . $course['desc']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionFilter == $sec['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sec['section_code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="enrolled" <?= $statusFilter === 'enrolled' ? 'selected' : '' ?>>Enrolled</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="incomplete" <?= $statusFilter === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                        Filter
                    </button>
                </div>
            </form>
        </div>

        <?php if ($sectionFilter): ?>
        <!-- Section Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-gray-800"><?= $sectionStats['enrolled'] ?></p>
                        <p class="text-xs text-gray-500">Enrolled</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-green-600"><?= $sectionStats['completed'] ?></p>
                        <p class="text-xs text-gray-500">Completed</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-yellow-600"><?= $sectionStats['incomplete'] ?></p>
                        <p class="text-xs text-gray-500">Incomplete</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-red-600"><?= $sectionStats['dropped'] ?></p>
                        <p class="text-xs text-gray-500">Dropped</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enrollment List -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <h3 class="text-lg font-semibold text-gray-800">
                    Enrollment Records
                    <span class="text-sm font-normal text-gray-500">(<?= count($enrollments) ?> records)</span>
                </h3>
                <?php if ($statusFilter === 'enrolled' && !empty($enrollments)): ?>
                <form method="POST" id="bulkForm">
                    <input type="hidden" name="action" value="bulk_complete">
                    <button type="submit" onclick="return confirmBulkComplete()" class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition">
                        Complete Selected
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <?php if ($statusFilter === 'enrolled'): ?>
                            <th class="px-4 py-3 font-medium">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300">
                            </th>
                            <?php endif; ?>
                            <th class="px-4 py-3 font-medium">Student</th>
                            <th class="px-4 py-3 font-medium">Subject</th>
                            <th class="px-4 py-3 font-medium text-center">Units</th>
                            <th class="px-4 py-3 font-medium text-center">Grades</th>
                            <th class="px-4 py-3 font-medium text-center">Status</th>
                            <th class="px-4 py-3 font-medium">Teacher</th>
                            <th class="px-4 py-3 font-medium text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($enrollments)): ?>
                        <tr>
                            <td colspan="<?= $statusFilter === 'enrolled' ? 8 : 7 ?>" class="px-6 py-12 text-center text-gray-500">
                                No enrollment records found matching the selected filters.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($enrollments as $enroll): 
                            $allGradesComplete = ($enroll['approved_periods'] >= $expectedPeriods);
                            $statusColor = match($enroll['status']) {
                                'enrolled' => 'bg-blue-100 text-blue-800',
                                'completed' => 'bg-green-100 text-green-800',
                                'incomplete' => 'bg-yellow-100 text-yellow-800',
                                'dropped' => 'bg-red-100 text-red-800',
                                default => 'bg-gray-100 text-gray-800',
                            };
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <?php if ($statusFilter === 'enrolled'): ?>
                            <td class="px-4 py-3">
                                <?php if ($allGradesComplete): ?>
                                <input type="checkbox" name="enroll_ids[]" value="<?= $enroll['id'] ?>" form="bulkForm" class="enroll-checkbox rounded border-gray-300">
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-gray-800"><?= htmlspecialchars($enroll['student_name']) ?></div>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars($enroll['student_no']) ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium"><?= htmlspecialchars($enroll['subjcode']) ?></div>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars($enroll['subject_name']) ?></div>
                            </td>
                            <td class="px-4 py-3 text-sm text-center"><?= $enroll['unit'] ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="text-sm font-medium <?= $allGradesComplete ? 'text-green-600' : 'text-gray-500' ?>">
                                    <?= $enroll['approved_periods'] ?>/<?= $expectedPeriods ?>
                                </span>
                                <?php if ($enroll['total_graded'] == 0): ?>
                                <span class="block text-xs text-gray-400">No grades</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-1 rounded-full text-xs font-medium <?= $statusColor ?>">
                                    <?= ucfirst($enroll['status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($enroll['teacher_name'] ?? 'N/A') ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($enroll['status'] === 'enrolled'): ?>
                                    <?php if ($allGradesComplete): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="enroll_id" value="<?= $enroll['id'] ?>">
                                        <button type="submit" onclick="return confirm('Mark this enrollment as completed?')" class="px-3 py-1 text-xs bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                                            Complete
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="incomplete">
                                        <input type="hidden" name="enroll_id" value="<?= $enroll['id'] ?>">
                                        <button type="submit" onclick="return confirm('Mark as incomplete?')" class="px-3 py-1 text-xs bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition">
                                            Incomplete
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif ($enroll['status'] === 'completed' || $enroll['status'] === 'incomplete'): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="revert">
                                        <input type="hidden" name="enroll_id" value="<?= $enroll['id'] ?>">
                                        <button type="submit" onclick="return confirm('Revert to enrolled status?')" class="px-3 py-1 text-xs bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition">
                                            Revert
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <!-- Initial State -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">Grade Completion Management</h3>
            <p class="text-gray-500">Select a section to view and manage enrollment completion status. Mark enrollments as completed when all grades are finalized.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.enroll-checkbox').forEach(cb => cb.checked = this.checked);
});

function confirmBulkComplete() {
    const checked = document.querySelectorAll('.enroll-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one enrollment to complete.');
        return false;
    }
    return confirm(`Mark ${checked.length} enrollment(s) as completed?`);
}
</script>

<?php include '../includes/footer.php'; ?>
