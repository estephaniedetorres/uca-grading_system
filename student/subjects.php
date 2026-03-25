<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('student');

$pageTitle = 'My Subjects';
$studentId = $_SESSION['student_id'] ?? 0;

// Get current school year from student's section
$currentSyId = 0;
$currentSyName = '';
$isCollege = false;
if ($studentId) {
    $infoStmt = db()->prepare("
        SELECT sec.sy_id, sy.sy_name, d.code as dept_code
        FROM tbl_student st
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        JOIN tbl_departments d ON at.dept_id = d.id
        LEFT JOIN tbl_sy sy ON sec.sy_id = sy.id
        WHERE st.id = ?
    ");
    $infoStmt->execute([$studentId]);
    $infoRow = $infoStmt->fetch();
    $currentSyId = (int)($infoRow['sy_id'] ?? 0);
    $currentSyName = $infoRow['sy_name'] ?? '';
    $isCollege = in_array($infoRow['dept_code'] ?? '', ['CCTE', 'CON']);
}

// College students can switch school year to view historical subjects.
$schoolYears = [];
$selectedSyId = $currentSyId;
$selectedSyName = $currentSyName;
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

// Get enrolled subjects for the selected school year (college) or current school year (non-college)
$stmt = db()->prepare("
    SELECT e.*, sub.subjcode, sub.`desc` as subject_name, sub.unit, sub.lec_h, sub.lab_h, sub.type,
           sy.sy_name
    FROM tbl_enroll e
    JOIN tbl_subjects sub ON e.subject_id = sub.id
    LEFT JOIN tbl_sy sy ON e.sy_id = sy.id
    WHERE e.student_id = ? AND e.sy_id = ?
    ORDER BY sub.subjcode
");
$stmt->execute([$studentId, $viewSyId]);
$subjects = $stmt->fetchAll();

// Calculate total units
$totalUnits = array_sum(array_column($subjects, 'unit'));

include '../includes/header.php';
include '../includes/sidebar_student.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">My Subjects</h1>
            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                <?php if ($isCollege && !empty($schoolYears)): ?>
                <select
                    onchange="window.location.href='subjects.php?sy_id='+this.value"
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
        <!-- Summary Card -->
        <div class="bg-black rounded-xl p-6 text-white mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-300 text-sm">Enrolled Subjects</p>
                    <p class="text-4xl font-bold mt-1"><?= count($subjects) ?></p>
                    <?php if ($isCollege): ?>
                    <p class="text-xs text-gray-400 mt-1">School Year: <?= htmlspecialchars($selectedSyName ?: $currentSyName ?: 'N/A') ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($isCollege): ?>
                <div class="text-right">
                    <p class="text-gray-300 text-sm">Total Units</p>
                    <p class="text-2xl font-bold mt-1"><?= $totalUnits ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($subjects)): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No Subjects Enrolled</h3>
            <p class="text-gray-500">You haven't been enrolled in any subjects yet.</p>
        </div>
        <?php else: ?>

        <!-- Subjects Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
            <?php foreach ($subjects as $subject): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition">
                <div class="bg-black p-4 text-white">
                    <h3 class="text-lg font-bold"><?= htmlspecialchars($subject['subjcode']) ?></h3>
                    <p class="text-gray-300 text-sm"><?= htmlspecialchars($subject['subject_name']) ?></p>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <?php if ($isCollege): ?>
                        <div>
                            <p class="text-gray-500">Units</p>
                            <p class="font-semibold text-lg"><?= $subject['unit'] ?></p>
                        </div>
                        <?php endif; ?>
                        <div>
                            <p class="text-gray-500">Type</p>
                            <p class="font-medium"><?= htmlspecialchars($subject['type'] ?? 'N/A') ?></p>
                        </div>
                        <?php if ($isCollege): ?>
                        <div>
                            <p class="text-gray-500">Lec Hours</p>
                            <p class="font-medium"><?= $subject['lec_h'] ?> hrs</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Lab Hours</p>
                            <p class="font-medium"><?= $subject['lab_h'] ?> hrs</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-500">School Year: <?= htmlspecialchars($subject['sy_name'] ?? 'N/A') ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Subjects Table View -->
        <div class="mt-8 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-800">Subject Details</h2>
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search subjects..." 
                        class="pl-4 pr-10 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent w-full sm:w-64">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full" id="dataTable">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium sortable">Code</th>
                            <th class="px-6 py-4 font-medium sortable">Description</th>
                            <?php if ($isCollege): ?>
                            <th class="px-6 py-4 font-medium text-center sortable">Units</th>
                            <th class="px-6 py-4 font-medium text-center sortable">Lec Hours</th>
                            <th class="px-6 py-4 font-medium text-center sortable">Lab Hours</th>
                            <?php endif; ?>
                            <th class="px-6 py-4 font-medium sortable">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $subject): ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-700"><?= htmlspecialchars($subject['subjcode']) ?></td>
                            <td class="px-6 py-4 text-sm"><?= htmlspecialchars($subject['subject_name']) ?></td>
                            <?php if ($isCollege): ?>
                            <td class="px-6 py-4 text-sm text-center"><?= $subject['unit'] ?></td>
                            <td class="px-6 py-4 text-sm text-center"><?= $subject['lec_h'] ?></td>
                            <td class="px-6 py-4 text-sm text-center"><?= $subject['lab_h'] ?></td>
                            <?php endif; ?>
                            <td class="px-6 py-4 text-sm">
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                    <?= htmlspecialchars($subject['type'] ?? 'N/A') ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($isCollege): ?>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="2" class="px-6 py-4 text-sm font-medium text-right">Total:</td>
                            <td class="px-6 py-4 text-sm text-center font-bold"><?= $totalUnits ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
