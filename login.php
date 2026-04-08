<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: /admin/dashboard.php');
            break;
        case 'teacher':
            header('Location: /teacher/dashboard.php');
            break;
        case 'student':
            header('Location: /student/dashboard.php');
            break;
        case 'principal':
            header('Location: /principal/dashboard.php');
            break;
        case 'dean':
            header('Location: /dean/dashboard.php');
            break;
        case 'registrar':
            header('Location: /registrar/dashboard.php');
            break;
    }
    exit;
}

// Portal definitions
$portals = [
    'admin' => [
        'label' => 'Admin',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'desc' => 'System Administration',
        'input_label' => 'Username',
        'input_placeholder' => 'Enter admin username',
        'allowed_roles' => ['admin'],
    ],
    'registrar' => [
        'label' => 'Registrar',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
        'desc' => 'Records & Enrollment',
        'input_label' => 'Username',
        'input_placeholder' => 'Enter registrar username',
        'allowed_roles' => ['registrar'],
    ],
    'principal-dean' => [
        'label' => 'Principal / Dean',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
        'desc' => 'School & Department Management',
        'input_label' => 'Username',
        'input_placeholder' => 'Enter your username',
        'allowed_roles' => ['principal', 'dean'],
    ],
    'teacher' => [
        'label' => 'Teacher',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
        'desc' => 'Grades & Classes',
        'input_label' => 'Username',
        'input_placeholder' => 'Enter teacher username',
        'allowed_roles' => ['teacher'],
    ],
    'student' => [
        'label' => 'Student',
        'icon' => '<path d="M12 14l9-5-9-5-9 5 9 5z"/><path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222"/>',
        'desc' => 'View Grades & Subjects',
        'input_label' => 'Student No',
        'input_placeholder' => 'Enter student number',
        'allowed_roles' => ['student'],
    ],
];

// Determine current portal
$portal = $_GET['portal'] ?? '';
$currentPortal = $portals[$portal] ?? null;

$error = '';

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentPortal) {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both ' . strtolower($currentPortal['input_label']) . ' and password.';
    } else {
        $authenticated = false;

        // Student portal: try student number first, then username
        if ($portal === 'student') {
            $studentStmt = db()->prepare("
                SELECT s.*, u.id as user_id, u.username, u.password, u.role, u.status
                FROM tbl_student s
                JOIN tbl_users u ON s.user_id = u.id
                WHERE s.student_no = ? AND u.status = 'active'
            ");
            $studentStmt->execute([$username]);
            $studentLogin = $studentStmt->fetch();

            if ($studentLogin && verifyPassword($password, $studentLogin['password'], $studentLogin['user_id'])) {
                $_SESSION['user_id'] = $studentLogin['user_id'];
                $_SESSION['username'] = $studentLogin['username'];
                $_SESSION['role'] = 'student';
                $_SESSION['name'] = formatPersonName($studentLogin) ?: 'Student';
                $_SESSION['student_id'] = $studentLogin['id'];
                header('Location: /student/dashboard.php');
                exit;
            }

            // Also try username-based login for students
            $stmt = db()->prepare("SELECT * FROM tbl_users WHERE username = ? AND status = 'active' AND role = 'student'");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && verifyPassword($password, $user['password'], $user['id'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = 'student';
                $stmt2 = db()->prepare("SELECT id, given_name, middle_name, last_name FROM tbl_student WHERE user_id = ?");
                $stmt2->execute([$user['id']]);
                $student = $stmt2->fetch();
                $_SESSION['name'] = formatPersonName($student) ?: 'Student';
                $_SESSION['student_id'] = $student['id'] ?? 0;
                header('Location: /student/dashboard.php');
                exit;
            }

            $error = 'Invalid student number or password.';
        } else {
            // Non-student portals: username login restricted to allowed roles
            $allowedRoles = $currentPortal['allowed_roles'];
            $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
            $stmt = db()->prepare("SELECT * FROM tbl_users WHERE username = ? AND status = 'active' AND role IN ($placeholders)");
            $stmt->execute(array_merge([$username], $allowedRoles));
            $user = $stmt->fetch();

            if ($user && verifyPassword($password, $user['password'], $user['id'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                switch ($user['role']) {
                    case 'admin':
                        $stmt = db()->prepare("SELECT id, full_name FROM tbl_admin WHERE user_id = ?");
                        $stmt->execute([$user['id']]);
                        $admin = $stmt->fetch();
                        $_SESSION['name'] = $admin['full_name'] ?? 'Admin';
                        $_SESSION['admin_id'] = $admin['id'] ?? 0;
                        header('Location: /admin/dashboard.php');
                        break;
                    case 'principal':
                        $stmt = db()->prepare("SELECT a.id, a.full_name FROM tbl_admin a WHERE a.user_id = ?");
                        $stmt->execute([$user['id']]);
                        $principal = $stmt->fetch();
                        $_SESSION['name'] = $principal['full_name'] ?? 'Principal';
                        $_SESSION['admin_id'] = $principal['id'] ?? 0;
                        $deptStmt = db()->prepare("SELECT d.id, d.code, d.description FROM tbl_admin_departments ad JOIN tbl_departments d ON ad.dept_id = d.id WHERE ad.admin_id = ? ORDER BY d.code");
                        $deptStmt->execute([$principal['id']]);
                        $depts = $deptStmt->fetchAll();
                        $_SESSION['dept_ids'] = array_column($depts, 'id');
                        $_SESSION['dept_codes'] = array_column($depts, 'code');
                        $_SESSION['dept_names'] = array_column($depts, 'description');
                        header('Location: /principal/dashboard.php');
                        break;
                    case 'dean':
                        $stmt = db()->prepare("SELECT a.id, a.full_name FROM tbl_admin a WHERE a.user_id = ?");
                        $stmt->execute([$user['id']]);
                        $dean = $stmt->fetch();
                        $_SESSION['name'] = $dean['full_name'] ?? 'Dean';
                        $_SESSION['admin_id'] = $dean['id'] ?? 0;
                        $deptStmt = db()->prepare("SELECT d.id, d.code, d.description FROM tbl_admin_departments ad JOIN tbl_departments d ON ad.dept_id = d.id WHERE ad.admin_id = ? ORDER BY d.code");
                        $deptStmt->execute([$dean['id']]);
                        $depts = $deptStmt->fetchAll();
                        $_SESSION['dept_ids'] = array_column($depts, 'id');
                        $_SESSION['dept_codes'] = array_column($depts, 'code');
                        $_SESSION['dept_names'] = array_column($depts, 'description');
                        header('Location: /dean/dashboard.php');
                        break;
                    case 'registrar':
                        $stmt = db()->prepare("SELECT id, full_name FROM tbl_admin WHERE user_id = ?");
                        $stmt->execute([$user['id']]);
                        $registrar = $stmt->fetch();
                        $_SESSION['name'] = $registrar['full_name'] ?? 'Registrar';
                        $_SESSION['admin_id'] = $registrar['id'] ?? 0;
                        header('Location: /registrar/dashboard.php');
                        break;
                    case 'teacher':
                        $stmt = db()->prepare("SELECT id, name FROM tbl_teacher WHERE user_id = ?");
                        $stmt->execute([$user['id']]);
                        $teacher = $stmt->fetch();
                        $_SESSION['name'] = $teacher['name'] ?? 'Teacher';
                        $_SESSION['teacher_id'] = $teacher['id'];
                        header('Location: /teacher/dashboard.php');
                        break;
                }
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $currentPortal ? htmlspecialchars($currentPortal['label']) . ' Login' : 'Select Portal' ?> - GradeMate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-black min-h-screen flex items-center justify-center p-4">

<?php if (!$currentPortal): ?>
    <!-- ========== PORTAL SELECTION ========== -->
    <div class="w-full max-w-3xl">
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-2xl shadow-lg mb-4">
                <img class="w-15 h-15 text-black" src="img/uca-logo.png" alt="UCA Logo">
            </div>
            <h1 class="text-3xl font-bold text-white">UCA - NEXUS   </h1>
            <p class="text-neutral-400 mt-2">Select your portal to sign in</p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <?php foreach ($portals as $key => $p): ?>
            <a href="login.php?portal=<?= $key ?>"
               class="group bg-white rounded-2xl p-6 text-center shadow-lg hover:shadow-xl transition-all duration-200 hover:border-neutral-400 border-2 border-transparent">
                <div class="inline-flex items-center justify-center w-14 h-14 bg-neutral-100 rounded-xl mb-4 group-hover:scale-110 group-hover:bg-black transition-all">
                    <svg class="w-7 h-7 text-black group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?= $p['icon'] ?>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-neutral-900"><?= $p['label'] ?></h3>
                <p class="text-sm text-neutral-500 mt-1"><?= $p['desc'] ?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ========== ROLE-SPECIFIC LOGIN FORM ========== -->
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-2xl shadow-lg mb-4">
                <svg class="w-10 h-10 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <?= $currentPortal['icon'] ?>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-white"><?= htmlspecialchars($currentPortal['label']) ?> Portal</h1>
            <p class="text-neutral-400 mt-1"><?= htmlspecialchars($currentPortal['desc']) ?></p>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-8">
            <h2 class="text-2xl font-semibold text-neutral-900 mb-6 text-center">Sign In</h2>

            <?php if ($error): ?>
            <div class="bg-neutral-100 text-neutral-800 p-4 rounded-lg mb-6 text-sm border border-neutral-300">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="login.php?portal=<?= htmlspecialchars($portal) ?>" class="space-y-6">
                <div>
                    <label class="block text-sm font-medium text-neutral-700 mb-2"><?= htmlspecialchars($currentPortal['input_label']) ?></label>
                    <input type="text" name="username" required
                        class="w-full px-4 py-3 border border-neutral-200 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent transition"
                        placeholder="<?= htmlspecialchars($currentPortal['input_placeholder']) ?>"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-neutral-700 mb-2">Password</label>
                    <input type="password" name="password" required
                        class="w-full px-4 py-3 border border-neutral-200 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent transition"
                        placeholder="Enter your password">
                </div>
                <button type="submit"
                    class="w-full bg-black text-white py-3 rounded-lg hover:bg-neutral-800 transition font-medium">
                    Sign In
                </button>
            </form>

            <div class="mt-6 text-center">
                <a href="login.php" class="text-sm text-neutral-600 hover:text-black hover:underline">&larr; Back to portal selection</a>
            </div>
        </div>
    </div>
<?php endif; ?>

</body>
</html>
