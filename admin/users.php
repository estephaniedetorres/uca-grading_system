<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole(['admin','principal']);

// CSV template download for teachers
if (isset($_GET['action']) && $_GET['action'] === 'teachers_csv_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="teachers_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'username', 'email']);
    fputcsv($out, ['Juan Dela Cruz', 't_delacruz', 'juan.delacruz@school.edu']);
    fputcsv($out, ['Maria Santos', 't_santos', 'maria.santos@school.edu']);
    fputcsv($out, ['Jose Reyes', 't_reyes', '']);
    fclose($out);
    exit;
}

$pageTitle = 'Users';
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];
        $role = sanitize($_POST['role']);
        $name = sanitize($_POST['name']);
        $status = sanitize($_POST['status']);
        
        try {
            db()->beginTransaction();
            
            // Insert user
            $stmt = db()->prepare("INSERT INTO tbl_users (username, password, role, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, hashPassword($password), $role, $status]);
            $userId = db()->lastInsertId();
            
            // Insert into role-specific table
            if ($role === 'admin' || $role === 'principal' || $role === 'dean' || $role === 'registrar') {
                $stmt = db()->prepare("INSERT INTO tbl_admin (user_id, full_name) VALUES (?, ?)");
                $stmt->execute([$userId, $name]);
                $adminId = db()->lastInsertId();
                
                // Insert department assignments for principal/dean
                if (($role === 'principal' || $role === 'dean') && !empty($_POST['dept_ids'])) {
                    $deptInsert = db()->prepare("INSERT INTO tbl_admin_departments (admin_id, dept_id) VALUES (?, ?)");
                    foreach ($_POST['dept_ids'] as $did) {
                        $deptInsert->execute([$adminId, (int)$did]);
                    }
                }
            } elseif ($role === 'teacher') {
                $email = sanitize($_POST['email'] ?? '');
                $stmt = db()->prepare("INSERT INTO tbl_teacher (user_id, name, email) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $name, $email]);
            } elseif ($role === 'student') {
                $sectionId = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
                $studentNo = sanitize($_POST['student_no'] ?? '');
                // Parse name into parts (assume "Given Middle Last" format)
                $nameParts = explode(' ', trim($name));
                $givenName = $nameParts[0] ?? '';
                $lastName = count($nameParts) > 1 ? array_pop($nameParts) : '';
                array_shift($nameParts); // remove given name
                $middleName = implode(' ', $nameParts); // remaining is middle
                if (empty($lastName)) { $lastName = $givenName; $givenName = ''; }
                
                // Auto-generate student number if not provided
                if (empty($studentNo)) {
                    $studentNo = generateNextStudentNumber();
                }
                
                $stmt = db()->prepare("INSERT INTO tbl_student (user_id, student_no, given_name, middle_name, last_name, section_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $studentNo, $givenName, $middleName, $lastName, $sectionId]);
            }
            
            db()->commit();
            $message = 'User added successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            db()->rollBack();
            $message = 'Failed to add user: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $username = sanitize($_POST['username']);
        $role = sanitize($_POST['role']);
        $status = sanitize($_POST['status']);
        $name = sanitize($_POST['name']);
        
        try {
            // Update user
            if (!empty($_POST['password'])) {
                $stmt = db()->prepare("UPDATE tbl_users SET username = ?, password = ?, status = ? WHERE id = ?");
                $stmt->execute([$username, hashPassword($_POST['password']), $status, $id]);
            } else {
                $stmt = db()->prepare("UPDATE tbl_users SET username = ?, status = ? WHERE id = ?");
                $stmt->execute([$username, $status, $id]);
            }
            
            // Update role-specific table
            if ($role === 'admin' || $role === 'principal' || $role === 'dean' || $role === 'registrar') {
                $stmt = db()->prepare("UPDATE tbl_admin SET full_name = ? WHERE user_id = ?");
                $stmt->execute([$name, $id]);
                
                // Update department assignments for principal/dean
                $adminRow = db()->prepare("SELECT id FROM tbl_admin WHERE user_id = ?");
                $adminRow->execute([$id]);
                $adminRec = $adminRow->fetch();
                if ($adminRec && ($role === 'principal' || $role === 'dean')) {
                    db()->prepare("DELETE FROM tbl_admin_departments WHERE admin_id = ?")->execute([$adminRec['id']]);
                    if (!empty($_POST['dept_ids'])) {
                        $deptInsert = db()->prepare("INSERT INTO tbl_admin_departments (admin_id, dept_id) VALUES (?, ?)");
                        foreach ($_POST['dept_ids'] as $did) {
                            $deptInsert->execute([$adminRec['id'], (int)$did]);
                        }
                    }
                }
            } elseif ($role === 'teacher') {
                $email = sanitize($_POST['email'] ?? '');
                $stmt = db()->prepare("UPDATE tbl_teacher SET name = ?, email = ? WHERE user_id = ?");
                $stmt->execute([$name, $email, $id]);
            } elseif ($role === 'student') {
                $sectionId = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
                $studentNo = sanitize($_POST['student_no'] ?? '');
                // Parse name into parts
                $nameParts = explode(' ', trim($name));
                $givenName = $nameParts[0] ?? '';
                $lastName = count($nameParts) > 1 ? array_pop($nameParts) : '';
                array_shift($nameParts);
                $middleName = implode(' ', $nameParts);
                if (empty($lastName)) { $lastName = $givenName; $givenName = ''; }
                $stmt = db()->prepare("UPDATE tbl_student SET student_no = ?, given_name = ?, middle_name = ?, last_name = ?, section_id = ? WHERE user_id = ?");
                $stmt->execute([$studentNo, $givenName, $middleName, $lastName, $sectionId, $id]);
            }
            
            $message = 'User updated successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Failed to update user.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            // Delete from role tables first
            db()->prepare("DELETE FROM tbl_admin WHERE user_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM tbl_teacher WHERE user_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM tbl_student WHERE user_id = ?")->execute([$id]);
            
            // Delete user
            $stmt = db()->prepare("DELETE FROM tbl_users WHERE id = ?");
            $stmt->execute([$id]);
            
            $message = 'User deleted successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Failed to delete user. They may have related records.';
            $messageType = 'error';
        }
    } elseif ($action === 'upload_teachers_csv') {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $file = $_FILES['csv_file']['tmp_name'];
            $defaultPassword = trim($_POST['default_password'] ?? 'teacherpass');
            if (strlen($defaultPassword) < 6) {
                $defaultPassword = 'teacherpass';
            }
            $hashedDefault = hashPassword($defaultPassword);

            $handle = fopen($file, 'r');
            if ($handle === false) {
                $message = 'Failed to open CSV file.';
                $messageType = 'error';
            } else {
                $header = fgetcsv($handle);
                if (!$header) {
                    $message = 'CSV file is empty.';
                    $messageType = 'error';
                } else {
                    // Normalize header names to lowercase/trimmed
                    $header = array_map(function($h) { return strtolower(trim($h)); }, $header);
                    $nameIdx = array_search('name', $header);
                    $emailIdx = array_search('email', $header);
                    $usernameIdx = array_search('username', $header);

                    if ($nameIdx === false || $usernameIdx === false) {
                        $message = 'CSV must have "name" and "username" columns.';
                        $messageType = 'error';
                    } else {
                        $imported = 0;
                        $skipped = 0;
                        $errors = [];
                        $row = 1;

                        while (($data = fgetcsv($handle)) !== false) {
                            $row++;
                            $name = trim($data[$nameIdx] ?? '');
                            $username = trim($data[$usernameIdx] ?? '');
                            if (empty($name) || empty($username)) { $skipped++; continue; }

                            $email = ($emailIdx !== false) ? trim($data[$emailIdx] ?? '') : '';

                            // Check if username already exists
                            $check = db()->prepare("SELECT id FROM tbl_users WHERE username = ?");
                            $check->execute([$username]);
                            if ($check->fetch()) {
                                // Append number to make unique
                                $base = $username;
                                $n = 2;
                                do {
                                    $username = $base . $n;
                                    $check->execute([$username]);
                                    $n++;
                                } while ($check->fetch());
                            }

                            try {
                                db()->beginTransaction();
                                $stmt = db()->prepare("INSERT INTO tbl_users (username, password, role, status) VALUES (?, ?, 'teacher', 'active')");
                                $stmt->execute([$username, $hashedDefault]);
                                $uid = db()->lastInsertId();

                                $stmt = db()->prepare("INSERT INTO tbl_teacher (user_id, name, email) VALUES (?, ?, ?)");
                                $stmt->execute([$uid, $name, $email]);
                                db()->commit();
                                $imported++;
                            } catch (Exception $e) {
                                db()->rollBack();
                                $errors[] = "Row $row ($name): " . $e->getMessage();
                            }
                        }

                        fclose($handle);
                        $message = "CSV import complete: $imported teacher(s) created";
                        if ($skipped > 0) $message .= ", $skipped row(s) skipped";
                        if (!empty($errors)) $message .= ". Errors: " . implode('; ', array_slice($errors, 0, 5));
                        $message .= ". Default password: $defaultPassword";
                        $messageType = empty($errors) ? 'success' : 'error';
                    }
                }
            }
        } else {
            $message = 'Please select a CSV file.';
            $messageType = 'error';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? '';
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArray)) {
            try {
                $placeholders = implode(',', array_fill(0, count($idArray), '?'));
                // Delete from role tables first
                db()->prepare("DELETE FROM tbl_admin WHERE user_id IN ($placeholders)")->execute($idArray);
                db()->prepare("DELETE FROM tbl_teacher WHERE user_id IN ($placeholders)")->execute($idArray);
                db()->prepare("DELETE FROM tbl_student WHERE user_id IN ($placeholders)")->execute($idArray);
                // Delete users
                $stmt = db()->prepare("DELETE FROM tbl_users WHERE id IN ($placeholders)");
                $stmt->execute($idArray);
                $message = count($idArray) . ' user(s) deleted successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to delete users.';
                $messageType = 'error';
            }
        }
    }
}

// Fetch all users with their names
$users = db()->query("
    SELECT u.id, u.username, u.role, u.status, u.created_at,
        COALESCE(MAX(a.full_name), MAX(t.name), MAX(CONCAT_WS(' ', s.given_name, s.middle_name, s.last_name))) as display_name,
        MAX(t.email) as teacher_email,
        MAX(s.section_id) as section_id,
        MAX(s.student_no) as student_no,
        MAX(s.given_name) as given_name, MAX(s.middle_name) as middle_name, MAX(s.last_name) as last_name,
        GROUP_CONCAT(DISTINCT d.id ORDER BY d.code SEPARATOR ',') as dept_ids_str,
        GROUP_CONCAT(DISTINCT d.code ORDER BY d.code SEPARATOR ', ') as dept_codes,
        GROUP_CONCAT(DISTINCT d.description ORDER BY d.code SEPARATOR ', ') as dept_descriptions
    FROM tbl_users u
    LEFT JOIN tbl_admin a ON u.id = a.user_id
    LEFT JOIN tbl_admin_departments ad ON a.id = ad.admin_id
    LEFT JOIN tbl_departments d ON ad.dept_id = d.id
    LEFT JOIN tbl_teacher t ON u.id = t.user_id
    LEFT JOIN tbl_student s ON u.id = s.user_id
    GROUP BY u.id, u.username, u.role, u.status, u.created_at
    ORDER BY u.id ASC
")->fetchAll();

// Fetch sections for dropdown
$sections = db()->query("SELECT * FROM tbl_section WHERE status = 'active' ORDER BY section_code")->fetchAll();

// Fetch departments for principal/dean dropdown
$departments = db()->query("SELECT * FROM tbl_departments WHERE status = 'active' ORDER BY code")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Users</h1>
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

        <!-- Action Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search..." 
                        class="pl-4 pr-10 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent w-full sm:w-64">
                </div>
                <button id="bulkDeleteBtn" onclick="confirmBulkDelete('dataTable')" 
                    class="hidden flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Delete Selected (<span id="selectedCount">0</span>)
                </button>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="openModal('uploadCsvModal')" 
                    class="flex items-center gap-2 bg-white text-black border border-gray-200 px-4 py-2 rounded-lg hover:bg-gray-50 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Upload Teachers CSV
                </button>
                <button onclick="openModal('addModal')" 
                    class="flex items-center gap-2 bg-black text-white px-4 py-2 rounded-lg hover:bg-neutral-800 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add User
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[700px]" id="dataTable">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-4 font-medium">
                            <input type="checkbox" class="rounded border-gray-300">
                        </th>
                        <th class="px-6 py-4 font-medium sortable">Username</th>
                        <th class="px-6 py-4 font-medium sortable">Name</th>
                        <th class="px-6 py-4 font-medium sortable">Role</th>
                        <th class="px-6 py-4 font-medium sortable">Status</th>
                        <th class="px-6 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <input type="checkbox" class="rounded border-gray-300">
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-neutral-700"><?= htmlspecialchars($user['username']) ?></td>
                        <td class="px-6 py-4 text-sm"><?= htmlspecialchars($user['display_name'] ?? 'N/A') ?></td>
                        <td class="px-6 py-4 text-sm">
                            <span class="px-2 py-1 text-xs font-medium rounded-full 
                                          <?= $user['role'] === 'admin' ? 'bg-purple-100 text-purple-800' :
                                              ($user['role'] === 'registrar' ? 'bg-indigo-100 text-indigo-800' :
                                              ($user['role'] === 'principal' ? 'bg-yellow-100 text-yellow-800' :
                                              ($user['role'] === 'dean' ? 'bg-orange-100 text-orange-800' :
                                              ($user['role'] === 'teacher' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800')))) ?>">
                                          <?= ucfirst($user['role']) ?>
                                          <?php if (($user['role'] === 'principal' || $user['role'] === 'dean') && !empty($user['dept_codes'])): ?>
                                            (<?= htmlspecialchars($user['dept_codes']) ?>)
                                          <?php endif; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm"><?= $user['status'] ?></td>
                        <td class="px-6 py-4 text-sm">
                            <button onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)" 
                                class="text-neutral-700 hover:text-black mr-3">Edit</button>
                            <button onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')" 
                                class="text-red-600 hover:text-red-800">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">No users found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</main>

<!-- Add Modal -->
<div id="addModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Add User</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="add">
            <div class="space-y-4">
                 <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                    <select name="role" id="addRole" onchange="toggleRoleFields('add')" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="admin">Admin</option>
                        <option value="registrar">Registrar</option>
                        <option value="principal">Principal (K-12)</option>
                        <option value="dean">Dean (College)</option>
                        <option value="teacher">Teacher</option>
                        <option value="student">Student</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="username" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                    <input type="text" name="name" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="addTeacherFields" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="addDeptFields" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Assigned Department(s) *</label>
                    <div class="space-y-2 max-h-48 overflow-y-auto border border-gray-200 rounded-lg p-3">
                        <?php foreach ($departments as $dept): ?>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="dept_ids[]" value="<?= $dept['id'] ?>" class="rounded border-gray-300 text-black focus:ring-black">
                            <span class="text-sm"><?= htmlspecialchars($dept['code'] . ' - ' . $dept['description']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Select one or more departments. A principal/dean can handle multiple departments.</p>
                </div>
                <div id="addStudentFields" class="hidden space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Student Number</label>
                        <input type="text" name="student_no" placeholder="Auto-generated if blank (e.g., 26-00001)"
                            class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                        <select name="section_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $sec): ?>
                            <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['section_code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeModal('addModal')" 
                    class="flex-1 px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                <button type="submit" 
                    class="flex-1 px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Edit User</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <input type="hidden" name="role" id="editRole">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="username" id="editUsername" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password (leave blank to keep current)</label>
                    <input type="password" name="password" 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                    <input type="text" name="name" id="editName" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                    <input type="text" id="editRoleDisplay" readonly 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg bg-gray-50">
                </div>
                <div id="editTeacherFields" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="editEmail" 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="editDeptFields" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Assigned Department(s) *</label>
                    <div class="space-y-2 max-h-48 overflow-y-auto border border-gray-200 rounded-lg p-3">
                        <?php foreach ($departments as $dept): ?>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="dept_ids[]" value="<?= $dept['id'] ?>" class="edit-dept-cb rounded border-gray-300 text-black focus:ring-black">
                            <span class="text-sm"><?= htmlspecialchars($dept['code'] . ' - ' . $dept['description']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Select one or more departments. A principal/dean can handle multiple departments.</p>
                </div>
                <div id="editStudentFields" class="hidden space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Student Number</label>
                        <input type="text" name="student_no" id="editStudentNo"
                            class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                        <select name="section_id" id="editSectionId" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $sec): ?>
                            <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['section_code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" id="editStatus" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeModal('editModal')" 
                    class="flex-1 px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                <button type="submit" 
                    class="flex-1 px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Delete User</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <p class="text-gray-600 mb-6">Are you sure you want to delete <span id="deleteName" class="font-semibold"></span>?</p>
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('deleteModal')" 
                    class="flex-1 px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                <button type="submit" 
                    class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Delete Modal -->
<div id="bulkDeleteModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Delete Multiple Users</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="ids" id="bulkDeleteIds">
            <p class="text-gray-600 mb-6">Are you sure you want to delete <span id="bulkDeleteCount" class="font-semibold">0</span> user(s)? This action cannot be undone.</p>
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('bulkDeleteModal')" 
                    class="flex-1 px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                <button type="submit" 
                    class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete All</button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Teachers CSV Modal -->
<div id="uploadCsvModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Upload Teachers via CSV</h3>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-6">
            <input type="hidden" name="action" value="upload_teachers_csv">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">CSV File</label>
                    <input type="file" name="csv_file" accept=".csv" required
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                    <p class="text-xs text-gray-500 mt-1">Required columns: <strong>name</strong>, <strong>username</strong>. Optional: <strong>email</strong>.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Default Password</label>
                    <input type="text" name="default_password" value="teacherpass" 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                    <p class="text-xs text-gray-500 mt-1">All imported teachers will use this password. Min 6 characters.</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-medium text-gray-700">CSV Format Example:</p>
                        <a href="/admin/users.php?action=teachers_csv_template" class="inline-flex items-center gap-1 px-3 py-1 text-xs bg-white border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-100 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download Template
                        </a>
                    </div>
                    <code class="text-xs text-gray-600 block">name,username,email<br>Juan Dela Cruz,t_delacruz,juan@email.com<br>Maria Santos,t_santos,</code>
                    <p class="text-xs text-gray-500 mt-2">Username must be unique. Email is optional and can be left blank.</p>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeModal('uploadCsvModal')" 
                    class="flex-1 px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                <button type="submit" 
                    class="flex-1 px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800">Upload & Import</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        setupSearch('searchInput', 'dataTable');
    });

    function toggleRoleFields(prefix) {
        const role = document.getElementById(prefix + 'Role').value;
        document.getElementById(prefix + 'TeacherFields').classList.toggle('hidden', role !== 'teacher');
        document.getElementById(prefix + 'StudentFields').classList.toggle('hidden', role !== 'student');
        document.getElementById(prefix + 'DeptFields').classList.toggle('hidden', role !== 'principal' && role !== 'dean');
    }

    function editUser(user) {
        document.getElementById('editId').value = user.id;
        document.getElementById('editUsername').value = user.username;
        document.getElementById('editName').value = user.display_name || '';
        document.getElementById('editRole').value = user.role;
        document.getElementById('editRoleDisplay').value = user.role.charAt(0).toUpperCase() + user.role.slice(1);
        document.getElementById('editStatus').value = user.status;
        
        // Show/hide role-specific fields
        document.getElementById('editTeacherFields').classList.toggle('hidden', user.role !== 'teacher');
        document.getElementById('editStudentFields').classList.toggle('hidden', user.role !== 'student');
        document.getElementById('editDeptFields').classList.toggle('hidden', user.role !== 'principal' && user.role !== 'dean');
        
        // Toggle required on student fields so hidden required doesn't block form submit
        document.getElementById('editStudentNo').required = (user.role === 'student');
        
        if (user.role === 'teacher') {
            document.getElementById('editEmail').value = user.teacher_email || '';
        }
        if (user.role === 'student') {
            document.getElementById('editStudentNo').value = user.student_no || '';
            document.getElementById('editSectionId').value = user.section_id || '';
        }
        if (user.role === 'principal' || user.role === 'dean') {
            const deptIds = (user.dept_ids_str || '').split(',');
            document.querySelectorAll('.edit-dept-cb').forEach(cb => {
                cb.checked = deptIds.includes(cb.value);
            });
        }
        
        openModal('editModal');
    }

    function deleteUser(id, name) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteName').textContent = name;
        openModal('deleteModal');
    }
</script>

<?php include '../includes/footer.php'; ?>
