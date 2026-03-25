<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('registrar');

$pageTitle = 'Student Dropping';
$message = '';
$messageType = '';

// Filters
$courseFilter = $_GET['course'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$syFilter = $_GET['sy'] ?? '';
$searchFilter = sanitize($_GET['search'] ?? '');
$viewFilter = $_GET['view'] ?? 'enrolled'; // enrolled or dropped

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
        case 'drop':
            $enrollId = (int)($_POST['enroll_id'] ?? 0);
            $reason = sanitize($_POST['reason'] ?? '');
            if ($enrollId > 0) {
                // Check if there are approved/finalized grades - warn but allow
                $gradeCheck = db()->prepare("SELECT COUNT(*) FROM tbl_grades WHERE enroll_id = ? AND status IN ('approved', 'finalized')");
                $gradeCheck->execute([$enrollId]);
                $hasApprovedGrades = (int)$gradeCheck->fetchColumn() > 0;
                
                $stmt = db()->prepare("UPDATE tbl_enroll SET status = 'dropped', updated_at = NOW() WHERE id = ? AND status IN ('enrolled', 'incomplete')");
                $stmt->execute([$enrollId]);
                if ($stmt->rowCount() > 0) {
                    $message = 'Student dropped from subject successfully.';
                    if ($hasApprovedGrades) {
                        $message .= ' Note: This enrollment had approved grades.';
                    }
                    $messageType = 'success';
                } else {
                    $message = 'Could not drop student. Enrollment may already be dropped or completed.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'drop_all':
            $studentId = (int)($_POST['student_id'] ?? 0);
            $sectionId = (int)($_POST['drop_section_id'] ?? 0);
            $syId = (int)($_POST['drop_sy_id'] ?? 0);
            if ($studentId > 0 && $sectionId > 0) {
                $params = [$studentId, $sectionId];
                $syCondition = '';
                if ($syId > 0) {
                    $syCondition = "AND sy_id = ?";
                    $params[] = $syId;
                }
                $stmt = db()->prepare("UPDATE tbl_enroll SET status = 'dropped', updated_at = NOW() WHERE student_id = ? AND section_id = ? $syCondition AND status IN ('enrolled', 'incomplete')");
                $stmt->execute($params);
                $count = $stmt->rowCount();
                $message = $count > 0 ? "$count enrollment(s) dropped for this student." : 'No active enrollments found to drop.';
                $messageType = $count > 0 ? 'success' : 'warning';
            }
            break;
            
        case 'reinstate':
            $enrollId = (int)($_POST['enroll_id'] ?? 0);
            if ($enrollId > 0) {
                $stmt = db()->prepare("UPDATE tbl_enroll SET status = 'enrolled', updated_at = NOW() WHERE id = ? AND status = 'dropped'");
                $stmt->execute([$enrollId]);
                if ($stmt->rowCount() > 0) {
                    $message = 'Student reinstated to enrolled status.';
                    $messageType = 'success';
                } else {
                    $message = 'Could not reinstate. Enrollment may not be in dropped status.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'bulk_drop':
            $enrollIds = $_POST['enroll_ids'] ?? [];
            if (!empty($enrollIds)) {
                $count = 0;
                foreach ($enrollIds as $eid) {
                    $eid = (int)$eid;
                    $stmt = db()->prepare("UPDATE tbl_enroll SET status = 'dropped', updated_at = NOW() WHERE id = ? AND status IN ('enrolled', 'incomplete')");
                    $stmt->execute([$eid]);
                    $count += $stmt->rowCount();
                }
                $message = "$count enrollment(s) dropped successfully.";
                $messageType = $count > 0 ? 'success' : 'warning';
            }
            break;
    }
}

// Determine expected grading periods based on section's education level
$expectedPeriods = 4;
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
        $expectedPeriods = ($deptCode === 'SHS') ? 2 : 4;
    }
}

// Fetch enrollments
$enrollments = [];
if ($sectionFilter) {
    $statusVal = ($viewFilter === 'dropped') ? 'dropped' : 'enrolled';
    $params = [$sectionFilter, $statusVal];
    
    $syCondition = '';
    if ($syFilter) {
        $syCondition = "AND e.sy_id = ?";
        $params[] = $syFilter;
    }
    
    $searchCondition = '';
    if ($searchFilter) {
        $searchCondition = "AND (st.student_no LIKE ? OR st.given_name LIKE ? OR st.last_name LIKE ?)";
        $searchTerm = "%$searchFilter%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $enrollStmt = db()->prepare("
        SELECT e.*, 
               CONCAT_WS(' ', st.last_name, ',', st.given_name, st.middle_name) as student_name,
               st.id as student_id, st.student_no,
               sub.subjcode, sub.`desc` as subject_name, sub.unit,
               sec.section_code,
               sy.sy_name,
               t.term_name,
               teach.name as teacher_name,
               (SELECT COUNT(DISTINCT g.grading_period) FROM tbl_grades g WHERE g.enroll_id = e.id AND g.status IN ('approved', 'finalized')) as approved_periods
        FROM tbl_enroll e
        JOIN tbl_student st ON e.student_id = st.id
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        JOIN tbl_section sec ON e.section_id = sec.id
        LEFT JOIN tbl_sy sy ON e.sy_id = sy.id
        LEFT JOIN tbl_term t ON e.term_id = t.id
        LEFT JOIN tbl_teacher teach ON e.teacher_id = teach.id
        WHERE e.section_id = ? AND e.status = ? $syCondition $searchCondition
        ORDER BY st.last_name, st.given_name, sub.subjcode
    ");
    $enrollStmt->execute($params);
    $enrollments = $enrollStmt->fetchAll();
}

// Group by student for drop-all functionality
$studentGroups = [];
foreach ($enrollments as $e) {
    $sid = $e['student_id'];
    if (!isset($studentGroups[$sid])) {
        $studentGroups[$sid] = [
            'name' => $e['student_name'],
            'student_no' => $e['student_no'],
            'count' => 0,
        ];
    }
    $studentGroups[$sid]['count']++;
}

// Summary stats
$droppedCount = 0;
$enrolledCount = 0;
if ($sectionFilter) {
    $statsParams = [$sectionFilter];
    $syStatsCondition = '';
    if ($syFilter) {
        $syStatsCondition = "AND sy_id = ?";
        $statsParams[] = $syFilter;
    }
    $cntStmt = db()->prepare("SELECT status, COUNT(*) as cnt FROM tbl_enroll WHERE section_id = ? $syStatsCondition GROUP BY status");
    $cntStmt->execute($statsParams);
    foreach ($cntStmt->fetchAll() as $row) {
        if ($row['status'] === 'dropped') $droppedCount = $row['cnt'];
        if ($row['status'] === 'enrolled') $enrolledCount = $row['cnt'];
    }
}

include '../includes/header.php';
include '../includes/sidebar_registrar.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Student Dropping</h1>
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
            <h3 class="text-lg font-medium text-gray-800 mb-4">Filter Students</h3>
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">View</label>
                    <select name="view" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="enrolled" <?= $viewFilter === 'enrolled' ? 'selected' : '' ?>>Active Enrollments</option>
                        <option value="dropped" <?= $viewFilter === 'dropped' ? 'selected' : '' ?>>Dropped</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search Student</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchFilter) ?>" placeholder="Name or Student No." class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                        Filter
                    </button>
                </div>
            </form>
        </div>

        <?php if ($sectionFilter): ?>
        <!-- Summary -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $enrolledCount ?></p>
                        <p class="text-sm text-gray-500">Active Enrollments</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-red-600"><?= $droppedCount ?></p>
                        <p class="text-sm text-gray-500">Dropped Enrollments</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enrollment List -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <h3 class="text-lg font-semibold text-gray-800">
                    <?= $viewFilter === 'dropped' ? 'Dropped' : 'Active' ?> Enrollments
                    <span class="text-sm font-normal text-gray-500">(<?= count($enrollments) ?> records)</span>
                </h3>
                <?php if ($viewFilter === 'enrolled' && !empty($enrollments)): ?>
                <form method="POST" id="bulkDropForm">
                    <input type="hidden" name="action" value="bulk_drop">
                    <button type="submit" onclick="return confirmBulkDrop()" class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition">
                        Drop Selected
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <?php if ($viewFilter === 'enrolled'): ?>
                            <th class="px-4 py-3 font-medium">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300">
                            </th>
                            <?php endif; ?>
                            <th class="px-4 py-3 font-medium">Student</th>
                            <th class="px-4 py-3 font-medium">Subject</th>
                            <th class="px-4 py-3 font-medium text-center">Units</th>
                            <th class="px-4 py-3 font-medium">Term</th>
                            <th class="px-4 py-3 font-medium text-center">Grades</th>
                            <th class="px-4 py-3 font-medium">Teacher</th>
                            <th class="px-4 py-3 font-medium text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($enrollments)): ?>
                        <tr>
                            <td colspan="<?= $viewFilter === 'enrolled' ? 8 : 7 ?>" class="px-6 py-12 text-center text-gray-500">
                                No <?= $viewFilter === 'dropped' ? 'dropped' : 'active' ?> enrollments found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($enrollments as $enroll): ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <?php if ($viewFilter === 'enrolled'): ?>
                            <td class="px-4 py-3">
                                <input type="checkbox" name="enroll_ids[]" value="<?= $enroll['id'] ?>" form="bulkDropForm" class="drop-checkbox rounded border-gray-300">
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
                            <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($enroll['term_name'] ?? 'Yearly') ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($enroll['approved_periods'] > 0): ?>
                                <span class="text-sm font-medium <?= $enroll['approved_periods'] >= $expectedPeriods ? 'text-green-600' : 'text-gray-500' ?>">
                                    <?= $enroll['approved_periods'] ?>/<?= $expectedPeriods ?>
                                </span>
                                <?php else: ?>
                                <span class="text-xs text-gray-400">No grades</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($enroll['teacher_name'] ?? 'N/A') ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($viewFilter === 'enrolled'): ?>
                                <div class="flex items-center justify-center gap-1">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="drop">
                                        <input type="hidden" name="enroll_id" value="<?= $enroll['id'] ?>">
                                        <button type="submit" onclick="return confirm('Drop this student from <?= htmlspecialchars($enroll['subjcode']) ?>?<?= $enroll['approved_periods'] > 0 ? ' This enrollment has approved grades.' : '' ?>')" class="px-3 py-1 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                            Drop
                                        </button>
                                    </form>
                                    <?php if (($studentGroups[$enroll['student_id']]['count'] ?? 0) > 1): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="drop_all">
                                        <input type="hidden" name="student_id" value="<?= $enroll['student_id'] ?>">
                                        <input type="hidden" name="drop_section_id" value="<?= $sectionFilter ?>">
                                        <input type="hidden" name="drop_sy_id" value="<?= $syFilter ?>">
                                        <button type="submit" onclick="return confirm('Drop ALL subjects for <?= htmlspecialchars($enroll['student_name']) ?> in this section?')" class="px-3 py-1 text-xs bg-red-800 text-white rounded-lg hover:bg-red-900 transition" title="Drop all subjects">
                                            Drop All
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="reinstate">
                                    <input type="hidden" name="enroll_id" value="<?= $enroll['id'] ?>">
                                    <button type="submit" onclick="return confirm('Reinstate this enrollment?')" class="px-3 py-1 text-xs bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                        Reinstate
                                    </button>
                                </form>
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
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">Student Dropping Management</h3>
            <p class="text-gray-500">Select a section to manage student dropping. You can drop individual subjects or all subjects for a student.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.drop-checkbox').forEach(cb => cb.checked = this.checked);
});

function confirmBulkDrop() {
    const checked = document.querySelectorAll('.drop-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one enrollment to drop.');
        return false;
    }
    return confirm(`Drop ${checked.length} enrollment(s)? This action can be reversed by reinstating.`);
}
</script>

<?php include '../includes/footer.php'; ?>
