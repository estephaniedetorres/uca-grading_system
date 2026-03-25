<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('registrar');

$pageTitle = 'Dashboard';

// Handle grade visibility toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_grades_visibility') {
    $newValue = ($_POST['grades_visible'] ?? '0') === '1' ? '1' : '0';
    setSetting('grades_visible', $newValue);
    header('Location: dashboard.php');
    exit;
}

$gradesVisible = getSetting('grades_visible', '0');

// Get statistics relevant to registrar
$stats = [
    'pending_grades' => db()->query("SELECT COUNT(*) FROM tbl_grades WHERE status = 'submitted'")->fetchColumn(),
    'approved_grades' => db()->query("SELECT COUNT(*) FROM tbl_grades WHERE status = 'approved'")->fetchColumn(),
    'draft_grades' => db()->query("SELECT COUNT(*) FROM tbl_grades WHERE status = 'draft'")->fetchColumn(),
    'total_students' => db()->query("SELECT COUNT(*) FROM tbl_student")->fetchColumn(),
    'completed_enrollments' => db()->query("SELECT COUNT(*) FROM tbl_enroll WHERE status = 'completed'")->fetchColumn(),
    'dropped_enrollments' => db()->query("SELECT COUNT(*) FROM tbl_enroll WHERE status = 'dropped'")->fetchColumn(),
];

// Get recent grade submissions
$recentSubmissions = db()->query("
    SELECT g.id, g.period_grade, g.status, g.submitted_at, g.grading_period,
           CONCAT_WS(' ', st.given_name, st.middle_name, st.last_name) as student_name,
           sub.subjcode, sub.`desc` as subject_name,
           teach.name as teacher_name
    FROM tbl_grades g
    JOIN tbl_enroll e ON g.enroll_id = e.id
    JOIN tbl_student st ON e.student_id = st.id
    JOIN tbl_subjects sub ON e.subject_id = sub.id
    LEFT JOIN tbl_teacher teach ON g.teacher_id = teach.id
    WHERE g.status = 'submitted'
    ORDER BY g.submitted_at DESC
    LIMIT 10
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_registrar.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Registrar Dashboard</h1>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <svg class="w-5 h-5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 sm:gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['pending_grades'] ?></p>
                        <p class="text-sm text-gray-500">Pending Approval</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['approved_grades'] ?></p>
                        <p class="text-sm text-gray-500">Approved Grades</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['draft_grades'] ?></p>
                        <p class="text-sm text-gray-500">Draft Grades</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['total_students'] ?></p>
                        <p class="text-sm text-gray-500">Total Students</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-emerald-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['completed_enrollments'] ?></p>
                        <p class="text-sm text-gray-500">Completed</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-red-600"><?= $stats['dropped_enrollments'] ?></p>
                        <p class="text-sm text-gray-500">Dropped</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grade Visibility Control -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Student Grade Visibility</h2>
                    <p class="text-sm text-gray-500 mt-1">Control whether students can view their grades in the student portal.</p>
                </div>
                <form method="POST" class="flex items-center gap-3">
                    <input type="hidden" name="action" value="toggle_grades_visibility">
                    <?php if ($gradesVisible === '1'): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-100 text-green-800 text-sm font-medium">
                            <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                            Visible
                        </span>
                        <input type="hidden" name="grades_visible" value="0">
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm">
                            Hide Grades
                        </button>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-100 text-red-800 text-sm font-medium">
                            <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                            Hidden
                        </span>
                        <input type="hidden" name="grades_visible" value="1">
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm">
                            Show Grades
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Recent Submissions -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-800">Recent Grade Submissions</h2>
                <a href="/registrar/grade_approval.php" class="text-sm text-blue-600 hover:text-blue-800">View All</a>
            </div>
            <div class="p-4 sm:p-6 overflow-x-auto">
                <table class="w-full min-w-[600px]">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 border-b">
                            <th class="pb-3 font-medium">Student</th>
                            <th class="pb-3 font-medium">Subject</th>
                            <th class="pb-3 font-medium">Grade</th>
                            <th class="pb-3 font-medium">Teacher</th>
                            <th class="pb-3 font-medium">Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSubmissions as $sub): ?>
                        <tr class="border-b border-gray-50">
                            <td class="py-3 text-sm font-medium"><?= htmlspecialchars($sub['student_name']) ?></td>
                            <td class="py-3 text-sm"><?= htmlspecialchars($sub['subjcode']) ?></td>
                            <td class="py-3 text-sm">
                                <?php if ($sub['period_grade'] !== null): ?>
                                <span class="px-2 py-1 text-xs font-bold rounded-full <?= $sub['period_grade'] >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= number_format($sub['period_grade'], 0) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-sm text-gray-600"><?= htmlspecialchars($sub['teacher_name'] ?? 'N/A') ?></td>
                            <td class="py-3 text-sm text-gray-500"><?= $sub['submitted_at'] ? date('M j, Y g:i A', strtotime($sub['submitted_at'])) : 'N/A' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentSubmissions)): ?>
                        <tr>
                            <td colspan="5" class="py-8 text-center text-gray-500">No pending grade submissions</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
