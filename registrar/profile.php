<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('registrar');

$pageTitle = 'My Profile';
$userId = $_SESSION['user_id'] ?? 0;
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_username') {
        $newUsername = trim($_POST['new_username'] ?? '');
        if (strlen($newUsername) < 3) {
            $message = 'Username must be at least 3 characters.';
            $messageType = 'error';
        } else {
            $check = db()->prepare("SELECT id FROM tbl_users WHERE username = ? AND id != ?");
            $check->execute([$newUsername, $userId]);
            if ($check->fetch()) {
                $message = 'Username is already taken.';
                $messageType = 'error';
            } else {
                $stmt = db()->prepare("UPDATE tbl_users SET username = ? WHERE id = ?");
                if ($stmt->execute([$newUsername, $userId])) {
                    $_SESSION['username'] = $newUsername;
                    $message = 'Username changed successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to change username.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'change_password') {
        $currentPass = $_POST['current_password'];
        $newPass = $_POST['new_password'];
        $confirmPass = $_POST['confirm_password'];

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

// Get registrar info
$stmt = db()->prepare("
    SELECT a.*, u.username, u.created_at as member_since, u.status
    FROM tbl_admin a
    JOIN tbl_users u ON a.user_id = u.id
    WHERE a.user_id = ?
");
$stmt->execute([$userId]);
$registrar = $stmt->fetch();

include '../includes/header.php';
include '../includes/sidebar_registrar.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">My Profile</h1>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <?php if ($message): ?>
        <div class="alert-auto-hide mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Profile Card -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="bg-black p-8 text-center">
                        <div class="w-24 h-24 bg-white rounded-full mx-auto flex items-center justify-center text-4xl font-bold text-gray-700">
                            <?= strtoupper(substr($registrar['full_name'] ?? 'R', 0, 1)) ?>
                        </div>
                        <h2 class="text-xl font-bold text-white mt-4"><?= htmlspecialchars($registrar['full_name'] ?? '') ?></h2>
                        <p class="text-gray-300">Registrar</p>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center gap-3 text-gray-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                <span><?= htmlspecialchars($registrar['username'] ?? '') ?></span>
                            </div>
                            <div class="flex items-center gap-3 text-gray-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <span>Member since <?= formatDate($registrar['member_since'] ?? date('Y-m-d')) ?></span>
                            </div>
                            <div class="flex items-center gap-3 text-gray-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                    <?= ucfirst($registrar['status'] ?? 'active') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <!-- Change Username -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Change Username</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="change_username">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">New Username</label>
                            <input type="text" name="new_username" required minlength="3" value="<?= htmlspecialchars($registrar['username'] ?? '') ?>"
                                class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        </div>
                        <button type="submit"
                            class="px-6 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                            Update Username
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
