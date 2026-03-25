<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('principal');

$pageTitle = 'Principal Dashboard';
$principalDeptIds = $_SESSION['dept_ids'] ?? [];
$principalDeptNames = $_SESSION['dept_names'] ?? [];
$principalDeptCodes = $_SESSION['dept_codes'] ?? [];
$principalName = $_SESSION['name'] ?? 'Principal';
$deptLabel = !empty($principalDeptNames) ? implode(', ', $principalDeptNames) : '';

// Get stats for this principal's departments
$stats = ['teachers' => 0, 'sections' => 0, 'assignments' => 0];
if (!empty($principalDeptIds)) {
    $placeholders = implode(',', array_fill(0, count($principalDeptIds), '?'));
    
    $stmt = db()->prepare("SELECT COUNT(DISTINCT s.id) as sections FROM tbl_section s JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE at.dept_id IN ($placeholders) AND s.status = 'active'");
    $stmt->execute($principalDeptIds);
    $stats['sections'] = $stmt->fetchColumn();
    
    $stmt = db()->prepare("SELECT COUNT(DISTINCT ts.teacher_id) as teachers FROM tbl_teacher_subject ts JOIN tbl_section s ON ts.section_id = s.id JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE at.dept_id IN ($placeholders)");
    $stmt->execute($principalDeptIds);
    $stats['teachers'] = $stmt->fetchColumn();
    
    $stmt = db()->prepare("SELECT COUNT(*) as assignments FROM tbl_teacher_subject ts JOIN tbl_section s ON ts.section_id = s.id JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE at.dept_id IN ($placeholders) AND ts.status = 'active'");
    $stmt->execute($principalDeptIds);
    $stats['assignments'] = $stmt->fetchColumn();
}

include '../includes/header.php';
include '../includes/sidebar_principal.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold text-gray-800">Welcome, <?= htmlspecialchars($principalName) ?>!</h1>
                <?php if ($deptLabel): ?>
                <p class="text-gray-500 mt-1">Principal for <span class="font-medium text-gray-700"><?= htmlspecialchars($deptLabel) ?></span></p>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <?php if (empty($principalDeptIds)): ?>
        <div class="mb-6 p-4 rounded-lg bg-yellow-100 text-yellow-800 border border-yellow-200">
            <strong>Notice:</strong> You have not been assigned to a department yet. Please contact the admin.
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['teachers'] ?></p>
                        <p class="text-sm text-gray-500">Teachers</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['sections'] ?></p>
                        <p class="text-sm text-gray-500">Sections</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['assignments'] ?></p>
                        <p class="text-sm text-gray-500">Active Assignments</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Quick Actions</h2>
            <p class="text-gray-600 mb-4">Manage teacher-subject assignments for your department.</p>
            <a href="/principal/teacher_subjects.php" class="inline-block px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                Go to Teacher-Subject Management
            </a>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
