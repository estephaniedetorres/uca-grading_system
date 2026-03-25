<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('student');

$pageTitle = 'My Profile';
$studentId = $_SESSION['student_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_guardian') {
        $guardianName = sanitize($_POST['guardian_name']);
        $guardianContact = sanitize($_POST['guardian_contact']);
        $guardianEmail = sanitize($_POST['guardian_email']);
        
        $stmt = db()->prepare("UPDATE tbl_student SET guardian_name = ?, guardian_contact = ?, guardian_email = ? WHERE id = ?");
        if ($stmt->execute([$guardianName, $guardianContact, $guardianEmail, $studentId])) {
            $message = 'Guardian information updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update guardian information.';
            $messageType = 'error';
        }
    } elseif ($action === 'change_password') {
        $currentPass = $_POST['current_password'];
        $newPass = $_POST['new_password'];
        $confirmPass = $_POST['confirm_password'];
        
        // Verify current password
        $stmt = db()->prepare("SELECT password FROM tbl_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!verifyPassword($currentPass, $user['password'], $userId)) {
            $message = 'Current password is incorrect.';
            $messageType = 'error';
        } elseif ($newPass !== $confirmPass) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } elseif (strlen($newPass) < 6) {
            $message = 'Password must be at least 6 characters.';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("UPDATE tbl_users SET password = ? WHERE id = ?");
            if ($stmt->execute([hashPassword($newPass), $userId])) {
                $message = 'Password changed successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to change password.';
                $messageType = 'error';
            }
        }
    }
}

// Get student info
$stmt = db()->prepare("
    SELECT st.*, u.username, u.created_at as member_since, u.status,
           sec.section_code, at.code as course_code, at.`desc` as course_name,
           d.description as dept_name, t.name as adviser_name, sy.sy_name
    FROM tbl_student st
    JOIN tbl_users u ON st.user_id = u.id
    LEFT JOIN tbl_section sec ON st.section_id = sec.id
    LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN tbl_departments d ON at.dept_id = d.id
    LEFT JOIN tbl_teacher t ON sec.adviser_id = t.id
    LEFT JOIN tbl_sy sy ON sec.sy_id = sy.id
    WHERE st.id = ?
");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

include '../includes/header.php';
include '../includes/sidebar_student.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">My Profile</h1>
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
        <div class="alert-auto-hide mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
            <?= $message ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Profile Card -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="bg-black p-8 text-center">
                        <div class="w-24 h-24 bg-white rounded-full mx-auto flex items-center justify-center text-4xl font-bold text-gray-700">
                            <?= strtoupper(substr(formatPersonName($student) ?: 'S', 0, 1)) ?>
                        </div>
                        <h2 class="text-xl font-bold text-white mt-4"><?= htmlspecialchars(formatPersonName($student)) ?></h2>
                        <p class="text-gray-300">Student</p>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center gap-3 text-gray-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                <span><?= htmlspecialchars($student['username'] ?? '') ?></span>
                            </div>
                            <div class="flex items-center gap-3 text-gray-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                                <span><?= htmlspecialchars($student['section_code'] ?? 'N/A') ?></span>
                            </div>
                            <div class="flex items-center gap-3 text-gray-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <span>Member since <?= formatDate($student['member_since'] ?? date('Y-m-d')) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Details & Password Change -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Academic Information -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Academic Information</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                        <div>
                            <p class="text-sm text-gray-500">Department</p>
                            <p class="font-medium"><?= htmlspecialchars($student['dept_name'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Course/Program</p>
                            <p class="font-medium"><?= htmlspecialchars($student['course_name'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Section</p>
                            <p class="font-medium"><?= htmlspecialchars($student['section_code'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">School Year</p>
                            <p class="font-medium"><?= htmlspecialchars($student['sy_name'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Adviser</p>
                            <p class="font-medium"><?= htmlspecialchars($student['adviser_name'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Status</p>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                <?= ucfirst($student['status'] ?? 'active') ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Guardian Information -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Parent/Guardian Information</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="save_guardian">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Guardian/Parent Name</label>
                            <input type="text" name="guardian_name" value="<?= htmlspecialchars($student['guardian_name'] ?? '') ?>" 
                                placeholder="e.g. Juan Dela Cruz"
                                class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                                <input type="text" name="guardian_contact" value="<?= htmlspecialchars($student['guardian_contact'] ?? '') ?>" 
                                    placeholder="e.g. 09171234567"
                                    class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                                <input type="email" name="guardian_email" value="<?= htmlspecialchars($student['guardian_email'] ?? '') ?>" 
                                    placeholder="e.g. parent@email.com"
                                    class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                            </div>
                        </div>
                        <button type="submit" 
                            class="px-6 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                            Save Guardian Info
                        </button>
                    </form>
                </div>

                <!-- Change Password -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Change Password</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="change_password">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                            <input type="password" name="current_password" required 
                                class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                            <input type="password" name="new_password" required minlength="6"
                                class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                            <input type="password" name="confirm_password" required 
                                class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        </div>
                        <button type="submit" 
                            class="px-6 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                            Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
