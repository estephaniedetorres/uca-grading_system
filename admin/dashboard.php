<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Dashboard';

// Get statistics
$stats = [
    'departments' => db()->query("SELECT COUNT(*) FROM tbl_departments WHERE status = 'active'")->fetchColumn(),
    'courses' => db()->query("SELECT COUNT(*) FROM tbl_academic_track WHERE status = 'active'")->fetchColumn(),
    'subjects' => db()->query("SELECT COUNT(*) FROM tbl_subjects WHERE status = 'active'")->fetchColumn(),
    'students' => db()->query("SELECT COUNT(*) FROM tbl_student")->fetchColumn(),
    'teachers' => db()->query("SELECT COUNT(DISTINCT user_id) FROM tbl_teacher")->fetchColumn(),
    'users' => db()->query("SELECT COUNT(*) FROM tbl_users WHERE status = 'active'")->fetchColumn(),
];

// Get recent activities
$recentStudents = db()->query("SELECT s.*, sec.section_code FROM tbl_student s 
    LEFT JOIN tbl_section sec ON s.section_id = sec.id 
    ORDER BY s.id DESC LIMIT 5")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_admin.php';
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
        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 sm:gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['departments'] ?></p>
                        <p class="text-sm text-gray-500">Departments</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['courses'] ?></p>
                        <p class="text-sm text-gray-500">Courses</p>
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
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['subjects'] ?></p>
                        <p class="text-sm text-gray-500">Subjects</p>
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
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['students'] ?></p>
                        <p class="text-sm text-gray-500">Students</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['teachers'] ?></p>
                        <p class="text-sm text-gray-500">Teachers</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['users'] ?></p>
                        <p class="text-sm text-gray-500">Total Users</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Students -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-800">Recent Students</h2>
            </div>
            <div class="p-4 sm:p-6 overflow-x-auto">
                <table class="w-full min-w-[400px]">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 border-b">
                            <th class="pb-3 font-medium">ID</th>
                            <th class="pb-3 font-medium">Name</th>
                            <th class="pb-3 font-medium">Section</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentStudents as $student): ?>
                        <tr class="border-b border-gray-50">
                            <td class="py-3 text-sm"><?= $student['id'] ?></td>
                            <td class="py-3 text-sm font-medium"><?= htmlspecialchars(formatPersonName($student)) ?></td>
                            <td class="py-3 text-sm"><?= htmlspecialchars($student['section_code'] ?? 'N/A') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentStudents)): ?>
                        <tr>
                            <td colspan="3" class="py-8 text-center text-gray-500">No students found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
