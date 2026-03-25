<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('teacher');

$pageTitle = 'My Classes';
$teacherId = $_SESSION['teacher_id'] ?? 0;

// Get all sections + subjects the teacher is assigned to teach
$stmt = db()->prepare("
    SELECT ts.section_id, ts.subject_id,
           s.section_code, s.status as section_status,
           at.code as course_code, at.`desc` as course_name,
           d.code as dept_code,
           sy.sy_name,
           sub.subjcode, sub.`desc` as subject_name,
           (SELECT COUNT(DISTINCT e.student_id)
            FROM tbl_enroll e
            JOIN tbl_student st ON e.student_id = st.id
            WHERE st.section_id = s.id AND e.subject_id = sub.id) as enrolled_count
    FROM tbl_teacher_subject ts
    JOIN tbl_section s ON ts.section_id = s.id
    JOIN tbl_subjects sub ON ts.subject_id = sub.id
    LEFT JOIN tbl_academic_track at ON s.academic_track_id = at.id
    LEFT JOIN tbl_departments d ON at.dept_id = d.id
    LEFT JOIN tbl_sy sy ON s.sy_id = sy.id
    WHERE ts.teacher_id = ? AND ts.status = 'active' AND s.status = 'active'
    ORDER BY s.section_code, sub.subjcode
");
$stmt->execute([$teacherId]);
$rows = $stmt->fetchAll();

// Group by section
$classesBySection = [];
foreach ($rows as $row) {
    $sid = $row['section_id'];
    if (!isset($classesBySection[$sid])) {
        $classesBySection[$sid] = [
            'section_id' => $sid,
            'section_code' => $row['section_code'],
            'course_code' => $row['course_code'],
            'course_name' => $row['course_name'],
            'dept_code' => $row['dept_code'],
            'sy_name' => $row['sy_name'],
            'subjects' => [],
        ];
    }
    $classesBySection[$sid]['subjects'][] = [
        'subject_id' => $row['subject_id'],
        'subjcode' => $row['subjcode'],
        'subject_name' => $row['subject_name'],
        'enrolled_count' => $row['enrolled_count'],
    ];
}

include '../includes/header.php';
include '../includes/sidebar_teacher.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">My Classes</h1>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <svg class="w-5 h-5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <?php if (empty($classesBySection)): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No Classes Assigned</h3>
            <p class="text-gray-500">You don't have any teaching assignments yet.</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            <?php foreach ($classesBySection as $cls): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition">
                <div class="bg-black p-5 text-white">
                    <h3 class="text-xl font-bold"><?= htmlspecialchars($cls['section_code']) ?></h3>
                    <p class="text-gray-300 text-sm mt-1"><?= htmlspecialchars($cls['course_name'] ?? $cls['course_code'] ?? '') ?></p>
                    <p class="text-gray-400 text-xs mt-0.5"><?= htmlspecialchars($cls['sy_name'] ?? '') ?></p>
                </div>
                <div class="p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Subjects You Teach (<?= count($cls['subjects']) ?>)</p>
                    <div class="space-y-2 mb-4 max-h-48 overflow-y-auto">
                        <?php foreach ($cls['subjects'] as $subj): ?>
                        <div class="flex items-center justify-between gap-2 p-2 bg-gray-50 rounded-lg">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($subj['subjcode']) ?></p>
                                <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($subj['subject_name']) ?></p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-xs text-gray-400"><?= $subj['enrolled_count'] ?> students</span>
                                <a href="grades.php?section=<?= $cls['section_id'] ?>&subject=<?= $subj['subject_id'] ?>" 
                                   class="px-2 py-1 bg-black text-white rounded text-xs hover:bg-neutral-800 transition" title="Enter Grades">
                                    Grades
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex gap-2 pt-2 border-t border-gray-100">
                        <a href="students.php?section=<?= $cls['section_id'] ?>" 
                            class="flex-1 text-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition text-sm">
                            View Students
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
