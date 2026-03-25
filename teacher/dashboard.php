<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('teacher');

$pageTitle = 'Dashboard';
$teacherId = $_SESSION['teacher_id'] ?? 0;

// Get teacher's assigned subjects grouped by course/grade level
$assignmentsStmt = db()->prepare("
    SELECT DISTINCT at.id as course_id, at.code as course_code, at.`desc` as course_name,
           (SELECT COUNT(DISTINCT ts2.section_id) FROM tbl_teacher_subject ts2 
            JOIN tbl_section s2 ON ts2.section_id = s2.id 
            WHERE ts2.teacher_id = ? AND s2.academic_track_id = at.id AND ts2.status = 'active') as section_count,
           (SELECT COUNT(DISTINCT ts3.subject_id) FROM tbl_teacher_subject ts3 
            JOIN tbl_section s3 ON ts3.section_id = s3.id 
            WHERE ts3.teacher_id = ? AND s3.academic_track_id = at.id AND ts3.status = 'active') as subject_count
    FROM tbl_teacher_subject ts
    JOIN tbl_section s ON ts.section_id = s.id
    JOIN tbl_academic_track at ON s.academic_track_id = at.id
    WHERE ts.teacher_id = ? AND ts.status = 'active'
    ORDER BY at.code
");
$assignmentsStmt->execute([$teacherId, $teacherId, $teacherId]);
$courses = $assignmentsStmt->fetchAll();

// Get total students the teacher handles
$totalStudentsStmt = db()->prepare("
    SELECT COUNT(DISTINCT st.id) as total
    FROM tbl_student st
    JOIN tbl_section s ON st.section_id = s.id
    JOIN tbl_teacher_subject ts ON ts.section_id = s.id
    WHERE ts.teacher_id = ? AND ts.status = 'active'
");
$totalStudentsStmt->execute([$teacherId]);
$totalStudents = $totalStudentsStmt->fetch()['total'] ?? 0;

// Get total subjects assigned
$totalSubjectsStmt = db()->prepare("
    SELECT COUNT(DISTINCT subject_id) as total
    FROM tbl_teacher_subject WHERE teacher_id = ? AND status = 'active'
");
$totalSubjectsStmt->execute([$teacherId]);
$totalSubjects = $totalSubjectsStmt->fetch()['total'] ?? 0;

// Get total sections
$totalSectionsStmt = db()->prepare("
    SELECT COUNT(DISTINCT section_id) as total
    FROM tbl_teacher_subject WHERE teacher_id = ? AND status = 'active'
");
$totalSectionsStmt->execute([$teacherId]);
$totalSections = $totalSectionsStmt->fetch()['total'] ?? 0;

// Get pending grades count
$pendingGradesStmt = db()->prepare("
    SELECT COUNT(*) as pending FROM tbl_enroll e
    JOIN tbl_student st ON e.student_id = st.id
    JOIN tbl_teacher_subject ts ON ts.section_id = st.section_id AND ts.subject_id = e.subject_id
    LEFT JOIN tbl_grades g ON e.id = g.enroll_id
    WHERE ts.teacher_id = ? AND g.id IS NULL
");
$pendingGradesStmt->execute([$teacherId]);
$pendingGrades = $pendingGradesStmt->fetch()['pending'] ?? 0;

include '../includes/header.php';
include '../includes/sidebar_teacher.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Dashboard</h1>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <svg class="w-5 h-5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <!-- Welcome Message -->
        <div class="bg-black rounded-xl p-6 text-white mb-8">
            <h2 class="text-2xl font-bold mb-2">Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'Teacher') ?>!</h2>
            <p class="text-gray-300">Here's an overview of your classes, subjects, and students.</p>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 sm:gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= count($courses) ?></p>
                        <p class="text-sm text-gray-500">Grade Levels</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $totalSections ?></p>
                        <p class="text-sm text-gray-500">Sections</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $totalSubjects ?></p>
                        <p class="text-sm text-gray-500">Subjects</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $totalStudents ?></p>
                        <p class="text-sm text-gray-500">Students</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grade Levels / Courses -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-8">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-800">My Grade Levels / Courses</h2>
                <p class="text-sm text-gray-500">Select a grade level to view sections and enter grades</p>
            </div>
            <div class="p-6">
                <?php if (empty($courses)): ?>
                <p class="text-gray-500 text-center py-4">No classes assigned yet</p>
                <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($courses as $course): ?>
                    <a href="classes.php?course=<?= $course['course_id'] ?>" 
                       class="block p-6 bg-gray-50 rounded-xl border border-gray-200 hover:border-black hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($course['course_code']) ?></span>
                            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                        <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($course['course_name']) ?></p>
                        <div class="flex items-center gap-4 text-sm">
                            <span class="px-2 py-1 bg-white rounded-lg border border-gray-200">
                                <?= $course['section_count'] ?> Sections
                            </span>
                            <span class="px-2 py-1 bg-white rounded-lg border border-gray-200">
                                <?= $course['subject_count'] ?> Subjects
                            </span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <a href="grades.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:border-black hover:shadow-md transition flex items-center gap-4">
                <div class="w-14 h-14 bg-black rounded-lg flex items-center justify-center">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Enter Grades</h3>
                    <p class="text-sm text-gray-500">Go to grade entry page</p>
                </div>
            </a>
            <a href="students.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:border-black hover:shadow-md transition flex items-center gap-4">
                <div class="w-14 h-14 bg-black rounded-lg flex items-center justify-center">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">View Students</h3>
                    <p class="text-sm text-gray-500">See all your students</p>
                </div>
            </a>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
