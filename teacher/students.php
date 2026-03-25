<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('teacher');

$pageTitle = 'My Students';
$teacherId = $_SESSION['teacher_id'] ?? 0;
$sectionFilter = $_GET['section'] ?? '';

// Get teacher's sections from teaching assignments
$sectionsStmt = db()->prepare("
    SELECT DISTINCT s.id, s.section_code 
    FROM tbl_section s 
    JOIN tbl_teacher_subject ts ON ts.section_id = s.id
    WHERE ts.teacher_id = ? AND ts.status = 'active' AND s.status = 'active'
    ORDER BY s.section_code
");
$sectionsStmt->execute([$teacherId]);
$sections = $sectionsStmt->fetchAll();

// Build query for students in sections where teacher teaches
$query = "
    SELECT DISTINCT st.*, sec.section_code, at.code as course_code, u.username
    FROM tbl_student st
    JOIN tbl_section sec ON st.section_id = sec.id
    JOIN tbl_teacher_subject ts ON ts.section_id = sec.id
    LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN tbl_users u ON st.user_id = u.id
    WHERE ts.teacher_id = ? AND ts.status = 'active' AND sec.status = 'active'
";
$params = [$teacherId];

if ($sectionFilter) {
    $query .= " AND sec.id = ?";
    $params[] = $sectionFilter;
}

$query .= " ORDER BY sec.section_code, st.last_name, st.given_name";

$stmt = db()->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_teacher.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">My Students</h1>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <svg class="w-5 h-5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <!-- Filters -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search students..." 
                        class="pl-4 pr-10 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent w-full sm:w-64">
                </div>
                <select id="sectionFilter" onchange="filterBySection()" 
                    class="px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= $sectionFilter == $sec['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sec['section_code']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="text-sm text-gray-500">
                Total: <span class="font-medium"><?= count($students) ?></span> students
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[600px]" id="dataTable">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-4 font-medium sortable">Name</th>
                        <th class="px-6 py-4 font-medium sortable">Username</th>
                        <th class="px-6 py-4 font-medium sortable">Section</th>
                        <th class="px-6 py-4 font-medium sortable">Course</th>
                        <th class="px-6 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars(formatPersonName($student)) ?></td>
                        <td class="px-6 py-4 text-sm text-gray-500"><?= htmlspecialchars($student['username'] ?? 'N/A') ?></td>
                        <td class="px-6 py-4 text-sm">
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                <?= htmlspecialchars($student['section_code']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm"><?= htmlspecialchars($student['course_code'] ?? 'N/A') ?></td>
                        <td class="px-6 py-4 text-sm">
                            <a href="grades.php?student=<?= $student['id'] ?>&section=<?= $student['section_id'] ?>"
                                class="text-neutral-700 hover:text-black">Input Grades</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">No students found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</main>

<script>
    function filterBySection() {
        const sectionId = document.getElementById('sectionFilter').value;
        if (sectionId) {
            window.location.href = '?section=' + sectionId;
        } else {
            window.location.href = 'students.php';
        }
    }
</script>

<?php include '../includes/footer.php'; ?>
