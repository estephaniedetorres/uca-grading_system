<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../config/mail.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;
requireRole('principal');

$pageTitle = 'Grade Approval';
$adminId = $_SESSION['admin_id'] ?? 0;
$principalDeptIds = $_SESSION['dept_ids'] ?? [];
$message = '';
$messageType = '';

// Handle grade actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $gradeId = (int)($_POST['grade_id'] ?? 0);
    $remarks = sanitize($_POST['remarks'] ?? '');

    try {
        switch ($action) {
            case 'approve':
                $currentGrade = db()->prepare("SELECT g.*, e.student_id, e.subject_id FROM tbl_grades g JOIN tbl_enroll e ON g.enroll_id = e.id WHERE g.id = ?");
                $currentGrade->execute([$gradeId]);
                $oldData = $currentGrade->fetch();

                if ($oldData && $oldData['status'] === 'submitted') {
                    $stmt = db()->prepare("
                        UPDATE tbl_grades SET 
                            status = 'approved', 
                            approved_by = ?, 
                            approved_at = NOW(),
                            finalized_at = NOW(),
                            remarks = ?,
                            updated_at = NOW()
                        WHERE id = ? AND status = 'submitted'
                    ");
                    $stmt->execute([$adminId, $remarks, $gradeId]);

                    logGradeHistory($gradeId, $adminId, 'approved', $oldData['status'], 'approved', $remarks);
                    updateEnrollmentGrades($oldData['enroll_id']);
                    sendGuardianGradeNotification($oldData['student_id'], $oldData['subject_id'], $gradeId);

                    $message = 'Grade approved! It is now visible to the student and guardian has been notified.';
                    $messageType = 'success';
                }
                break;

            case 'reject':
                $currentGrade = db()->prepare("SELECT * FROM tbl_grades WHERE id = ?");
                $currentGrade->execute([$gradeId]);
                $oldData = $currentGrade->fetch();

                if ($oldData && $oldData['status'] === 'submitted') {
                    $stmt = db()->prepare("
                        UPDATE tbl_grades SET 
                            status = 'draft', 
                            submitted_at = NULL,
                            remarks = ?,
                            updated_at = NOW()
                        WHERE id = ? AND status = 'submitted'
                    ");
                    $stmt->execute([$remarks, $gradeId]);

                    logGradeHistory($gradeId, $adminId, 'rejected', $oldData['status'], 'draft', $remarks);

                    $message = 'Grade rejected and returned to teacher for revision.';
                    $messageType = 'warning';
                }
                break;

            case 'bulk_approve':
                $gradeIds = $_POST['grade_ids'] ?? [];
                if (!empty($gradeIds)) {
                    $count = 0;
                    $studentSubjects = [];
                    foreach ($gradeIds as $gId) {
                        $gId = (int)$gId;
                        $currentGrade = db()->prepare("SELECT g.*, e.student_id, e.subject_id FROM tbl_grades g JOIN tbl_enroll e ON g.enroll_id = e.id WHERE g.id = ? AND g.status = 'submitted'");
                        $currentGrade->execute([$gId]);
                        $oldData = $currentGrade->fetch();

                        if ($oldData) {
                            $stmt = db()->prepare("
                                UPDATE tbl_grades SET 
                                    status = 'approved', 
                                    approved_by = ?, 
                                    approved_at = NOW(),
                                    finalized_at = NOW(),
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$adminId, $gId]);
                            logGradeHistory($gId, $adminId, 'bulk_approved', 'submitted', 'approved', 'Bulk approval');
                            updateEnrollmentGrades($oldData['enroll_id']);
                            $count++;

                            $key = $oldData['student_id'] . '-' . $oldData['subject_id'];
                            if (!isset($studentSubjects[$key])) {
                                $studentSubjects[$key] = ['student_id' => $oldData['student_id'], 'subject_id' => $oldData['subject_id'], 'grade_id' => $gId];
                            }
                        }
                    }
                    foreach ($studentSubjects as $info) {
                        sendGuardianGradeNotification($info['student_id'], $info['subject_id'], $info['grade_id']);
                    }
                    $message = "$count grade(s) approved! Guardians have been notified.";
                    $messageType = 'success';
                }
                break;
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

function logGradeHistory($gradeId, $changedBy, $action, $oldStatus, $newStatus, $remarks) {
    try {
        $stmt = db()->prepare("
            INSERT INTO tbl_grade_history (grade_id, changed_by, action, old_status, new_status, remarks)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$gradeId, $changedBy, $action, $oldStatus, $newStatus, $remarks]);
    } catch (Exception $e) {
        // Table might not exist yet
    }
}

function sendGuardianGradeNotification($studentId, $subjectId, $gradeId) {
    try {
        $stmt = db()->prepare("
            SELECT st.given_name, st.last_name, st.guardian_name, st.guardian_email,
                   sub.subjcode, sub.`desc` as subject_name,
                   g.period_grade, g.grading_period, t.term_name
            FROM tbl_student st
            JOIN tbl_enroll e ON e.student_id = st.id AND e.subject_id = ?
            JOIN tbl_grades g ON g.id = ?
            JOIN tbl_term t ON g.term_id = t.id
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            WHERE st.id = ?
            LIMIT 1
        ");
        $stmt->execute([$subjectId, $gradeId, $studentId]);
        $data = $stmt->fetch();

        if ($data && !empty($data['guardian_email'])) {
            $studentName = trim($data['given_name'] . ' ' . $data['last_name']);
            $periodName = getGradingPeriodName($data['grading_period']);
            $grade = $data['period_grade'] !== null ? number_format($data['period_grade'], 0) : 'N/A';
            $gradeColor = ($data['period_grade'] >= 75) ? '#16a34a' : '#dc2626';

            $body = "<html><body style='font-family: Arial, sans-serif;'>";
            $body .= "<h2 style='color: #333;'>Grade Notification</h2>";
            $body .= "<p>Dear <strong>" . htmlspecialchars($data['guardian_name'] ?? 'Parent/Guardian') . "</strong>,</p>";
            $body .= "<p>This is to inform you that the following grade has been approved for your child/ward:</p>";
            $body .= "<table style='border-collapse:collapse; margin:16px 0;'>";
            $body .= "<tr><td style='padding:8px 16px; background:#f3f4f6; font-weight:bold;'>Student</td><td style='padding:8px 16px;'>" . htmlspecialchars($studentName) . "</td></tr>";
            $body .= "<tr><td style='padding:8px 16px; background:#f3f4f6; font-weight:bold;'>Subject</td><td style='padding:8px 16px;'>" . htmlspecialchars($data['subjcode'] . ' - ' . $data['subject_name']) . "</td></tr>";
            $body .= "<tr><td style='padding:8px 16px; background:#f3f4f6; font-weight:bold;'>Term</td><td style='padding:8px 16px;'>" . htmlspecialchars($data['term_name']) . "</td></tr>";
            $body .= "<tr><td style='padding:8px 16px; background:#f3f4f6; font-weight:bold;'>Period</td><td style='padding:8px 16px;'>" . htmlspecialchars($periodName) . "</td></tr>";
            $body .= "<tr><td style='padding:8px 16px; background:#f3f4f6; font-weight:bold;'>Grade</td><td style='padding:8px 16px; font-size:18px; font-weight:bold; color:{$gradeColor};'>{$grade}</td></tr>";
            $body .= "</table>";
            $body .= "<p>You can log in to the student portal to view the complete grades.</p>";
            $body .= "<p style='color:#666; font-size:12px;'>This is an automated notification from the Grading Management System.</p>";
            $body .= "</body></html>";

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USERNAME;
            $mail->Password   = MAIL_PASSWORD;
            $mail->SMTPSecure = (MAIL_ENCRYPTION === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = MAIL_PORT;

            $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
            $mail->addAddress($data['guardian_email'], $data['guardian_name'] ?? '');

            $mail->isHTML(true);
            $mail->Subject = "Grade Notification - {$studentName} ({$data['subjcode']})";
            $mail->Body    = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>'], "\n", $body));

            $mail->send();
            return true;
        }
        return false;
    } catch (MailException $e) {
        error_log('Grade notification email failed: ' . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log('Grade notification error: ' . $e->getMessage());
        return false;
    }
}

// Get filters
$statusFilter = $_GET['status'] ?? 'submitted';
$termFilter = $_GET['term'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$teacherFilter = $_GET['teacher'] ?? '';

// Fetch filter options scoped to departments
$terms = db()->query("SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.status = 'active' ORDER BY t.id")->fetchAll();

$sections = [];
$teachers = [];
if (!empty($principalDeptIds)) {
    $ph = implode(',', array_fill(0, count($principalDeptIds), '?'));
    $sectionsStmt = db()->prepare("SELECT s.* FROM tbl_section s JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE at.dept_id IN ($ph) AND s.status = 'active' ORDER BY s.section_code");
    $sectionsStmt->execute($principalDeptIds);
    $sections = $sectionsStmt->fetchAll();

    $teachersStmt = db()->prepare("SELECT DISTINCT teach.* FROM tbl_teacher teach JOIN tbl_teacher_subject ts ON teach.id = ts.teacher_id JOIN tbl_section s ON ts.section_id = s.id JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE at.dept_id IN ($ph) ORDER BY teach.name");
    $teachersStmt->execute($principalDeptIds);
    $teachers = $teachersStmt->fetchAll();
}

// Build grade query with department scoping
$params = [];
$whereConditions = ["g.status = ?"];
$params[] = $statusFilter;

if ($termFilter) {
    $whereConditions[] = "g.term_id = ?";
    $params[] = $termFilter;
}

if ($sectionFilter) {
    $whereConditions[] = "st.section_id = ?";
    $params[] = $sectionFilter;
}

if ($teacherFilter) {
    $whereConditions[] = "g.teacher_id = ?";
    $params[] = $teacherFilter;
}

// Scope to principal's departments
if (!empty($principalDeptIds)) {
    $ph = implode(',', array_fill(0, count($principalDeptIds), '?'));
    $whereConditions[] = "at.dept_id IN ($ph)";
    $params = array_merge($params, $principalDeptIds);
}

$whereClause = implode(' AND ', $whereConditions);

$gradesStmt = db()->prepare("
    SELECT g.*, 
           e.student_id, e.subject_id,
           CONCAT_WS(' ', st.given_name, st.middle_name, st.last_name) as student_name, st.section_id,
           sub.subjcode, sub.`desc` as subject_name,
           t.term_name, sy.sy_name,
           teach.name as teacher_name,
           sec.section_code,
           lv.code as level_code,
           approver.full_name as approved_by_name
    FROM tbl_grades g
    JOIN tbl_enroll e ON g.enroll_id = e.id
    JOIN tbl_student st ON e.student_id = st.id
    JOIN tbl_subjects sub ON e.subject_id = sub.id
    JOIN tbl_term t ON g.term_id = t.id
    LEFT JOIN tbl_sy sy ON t.sy_id = sy.id
    JOIN tbl_teacher teach ON g.teacher_id = teach.id
    JOIN tbl_section sec ON st.section_id = sec.id
    JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN level lv ON sec.level_id = lv.id
    LEFT JOIN tbl_admin approver ON g.approved_by = approver.id
    WHERE $whereClause
    ORDER BY g.updated_at DESC, st.last_name, st.given_name, sub.subjcode
");
$gradesStmt->execute($params);
$grades = $gradesStmt->fetchAll();

// Count grades by status (scoped to departments)
$statusCounts = [];
if (!empty($principalDeptIds)) {
    $ph = implode(',', array_fill(0, count($principalDeptIds), '?'));
    $countStmt = db()->prepare("
        SELECT g.status, COUNT(*) as count FROM tbl_grades g
        JOIN tbl_enroll e ON g.enroll_id = e.id
        JOIN tbl_student st ON e.student_id = st.id
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        WHERE at.dept_id IN ($ph)
        GROUP BY g.status
    ");
    $countStmt->execute($principalDeptIds);
    $statusCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

include '../includes/header.php';
include '../includes/sidebar_principal.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Grade Approval</h1>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <?php if ($message): ?>
        <div class="alert-auto-hide mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-800' : ($messageType === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
            <?= $message ?>
        </div>
        <?php endif; ?>

        <?php if (empty($principalDeptIds)): ?>
        <div class="mb-6 p-4 rounded-lg bg-yellow-100 text-yellow-800 border border-yellow-200">
            <strong>Notice:</strong> You have not been assigned to a department yet. Please contact the admin.
        </div>
        <?php endif; ?>

        <!-- Status Tabs -->
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="?status=pending<?= $termFilter ? "&term=$termFilter" : '' ?><?= $sectionFilter ? "&section=$sectionFilter" : '' ?>" 
               class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $statusFilter === 'pending' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                Pending <span class="ml-1 px-2 py-0.5 rounded-full bg-gray-200 text-gray-700 text-xs"><?= $statusCounts['pending'] ?? 0 ?></span>
            </a>
            <a href="?status=draft<?= $termFilter ? "&term=$termFilter" : '' ?><?= $sectionFilter ? "&section=$sectionFilter" : '' ?>" 
               class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $statusFilter === 'draft' ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-600 hover:bg-blue-200' ?>">
                Draft <span class="ml-1 px-2 py-0.5 rounded-full bg-blue-200 text-blue-700 text-xs"><?= $statusCounts['draft'] ?? 0 ?></span>
            </a>
            <a href="?status=submitted<?= $termFilter ? "&term=$termFilter" : '' ?><?= $sectionFilter ? "&section=$sectionFilter" : '' ?>" 
               class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $statusFilter === 'submitted' ? 'bg-yellow-500 text-white' : 'bg-yellow-100 text-yellow-600 hover:bg-yellow-200' ?>">
                Submitted <span class="ml-1 px-2 py-0.5 rounded-full bg-yellow-200 text-yellow-700 text-xs"><?= $statusCounts['submitted'] ?? 0 ?></span>
            </a>
            <a href="?status=approved<?= $termFilter ? "&term=$termFilter" : '' ?><?= $sectionFilter ? "&section=$sectionFilter" : '' ?>" 
               class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $statusFilter === 'approved' ? 'bg-green-600 text-white' : 'bg-green-100 text-green-600 hover:bg-green-200' ?>">
                Approved <span class="ml-1 px-2 py-0.5 rounded-full bg-green-200 text-green-700 text-xs"><?= $statusCounts['approved'] ?? 0 ?></span>
            </a>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Term</label>
                    <select name="term" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">All Terms</option>
                        <?php foreach ($terms as $term): ?>
                        <option value="<?= $term['id'] ?>" <?= $termFilter == $term['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($term['term_name'] . ' (' . ($term['sy_name'] ?? '') . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionFilter == $sec['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sec['section_code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Teacher</label>
                    <select name="teacher" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">All Teachers</option>
                        <?php foreach ($teachers as $teach): ?>
                        <option value="<?= $teach['id'] ?>" <?= $teacherFilter == $teach['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($teach['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                        Filter
                    </button>
                </div>
                <div class="flex items-end">
                    <a href="?status=<?= $statusFilter ?>" class="w-full px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition text-center">
                        Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Grades Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">
                            <?= ucfirst($statusFilter) ?> Grades
                        </h3>
                        <p class="text-sm text-gray-500"><span id="resultCount"><?= count($grades) ?></span> record(s) found</p>
                    </div>
                    <input type="text" id="searchInput" placeholder="Search grades..." 
                        class="px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none text-sm">
                </div>
                
                <?php if ($statusFilter === 'submitted' && count($grades) > 0): ?>
                <form method="POST" id="bulkApproveForm">
                    <input type="hidden" name="action" value="bulk_approve">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm">
                        Approve Selected
                    </button>
                </form>
                <?php endif; ?>
            </div>
            
            <div class="overflow-x-auto">
                <table id="dataTable" class="w-full min-w-[900px]">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <?php if (in_array($statusFilter, ['submitted'])): ?>
                            <th class="px-4 py-4 font-medium">
                                <input type="checkbox" id="selectAll" class="rounded">
                            </th>
                            <?php endif; ?>
                            <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Student</th>
                            <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Subject</th>
                            <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Section</th>
                            <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Level</th>
                            <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Term</th>
                            <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Period</th>
                            <th class="px-4 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Grade</th>
                            <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Teacher</th>
                            <th class="px-4 py-4 font-medium text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grades as $grade): ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <?php if (in_array($statusFilter, ['submitted'])): ?>
                            <td class="px-4 py-4">
                                <input type="checkbox" name="grade_ids[]" value="<?= $grade['id'] ?>" 
                                       form="bulkApproveForm" 
                                       class="grade-checkbox rounded">
                            </td>
                            <?php endif; ?>
                            <td class="px-4 py-4 text-sm font-medium text-gray-800">
                                <?= htmlspecialchars($grade['student_name']) ?>
                            </td>
                            <td class="px-4 py-4 text-sm">
                                <span class="font-medium"><?= htmlspecialchars($grade['subjcode']) ?></span>
                                <span class="text-gray-500 text-xs block"><?= htmlspecialchars($grade['subject_name']) ?></span>
                            </td>
                            <td class="px-4 py-4 text-sm"><?= htmlspecialchars($grade['section_code']) ?></td>
                            <td class="px-4 py-4 text-sm">
                                <?php if (!empty($grade['level_code'])): ?>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700"><?= htmlspecialchars($grade['level_code']) ?></span>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-600"><?= htmlspecialchars($grade['term_name']) ?></td>
                            <td class="px-4 py-4 text-sm">
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700">
                                    <?= htmlspecialchars(getGradingPeriodName($grade['grading_period'])) ?>
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <?php if ($grade['period_grade'] !== null): 
                                    $isCollege = in_array($grade['grading_period'], ['PRELIM','MIDTERM','SEMIFINAL','FINAL']);
                                    $displayGrade = $isCollege ? number_format($grade['period_grade'], 2) : number_format($grade['period_grade'], 0);
                                    $isPassing = $isCollege ? ($grade['period_grade'] <= 3.00) : ($grade['period_grade'] >= 75);
                                ?>
                                <span class="px-2 py-1 text-sm font-bold rounded-full <?= $isPassing ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $displayGrade ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-600"><?= htmlspecialchars($grade['teacher_name']) ?></td>
                            <td class="px-4 py-4 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <?php if ($statusFilter === 'submitted'): ?>
                                    <button onclick="openGradeModal('approve', <?= $grade['id'] ?>)" 
                                            class="p-2 text-green-600 hover:bg-green-100 rounded-lg transition" title="Approve">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </button>
                                    <button onclick="openGradeModal('reject', <?= $grade['id'] ?>)" 
                                            class="p-2 text-red-600 hover:bg-red-100 rounded-lg transition" title="Reject">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                    <?php elseif ($statusFilter === 'approved'): ?>
                                    <span class="text-xs text-green-600 font-medium">
                                        Approved <?= $grade['approved_at'] ? date('M j, Y', strtotime($grade['approved_at'])) : '' ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-gray-400 text-sm">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($grades)): ?>
                        <tr>
                            <td colspan="<?= $statusFilter === 'submitted' ? '10' : '9' ?>" class="px-6 py-12 text-center text-gray-500">
                                <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                                <p>No <?= $statusFilter ?> grades found</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Action Modal -->
<div id="actionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
        <form method="POST" id="actionForm">
            <input type="hidden" name="action" id="modalAction">
            <input type="hidden" name="grade_id" id="modalGradeId">
            
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 id="modalTitle" class="text-lg font-semibold text-gray-800"></h3>
            </div>
            
            <div class="px-6 py-4">
                <p id="modalMessage" class="text-gray-600 mb-4"></p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Remarks (optional)</label>
                    <textarea name="remarks" rows="3" 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black"
                        placeholder="Add any notes or comments..."></textarea>
                </div>
            </div>
            
            <div class="px-6 py-4 bg-gray-50 rounded-b-xl flex justify-end gap-3">
                <button type="button" onclick="closeGradeModal()" class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg transition">
                    Cancel
                </button>
                <button type="submit" id="modalSubmitBtn" class="px-4 py-2 text-white rounded-lg transition">
                    Confirm
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.grade-checkbox').forEach(cb => {
        cb.checked = this.checked;
    });
});

function openGradeModal(action, gradeId) {
    const modal = document.getElementById('actionModal');
    const title = document.getElementById('modalTitle');
    const message = document.getElementById('modalMessage');
    const submitBtn = document.getElementById('modalSubmitBtn');
    
    document.getElementById('modalAction').value = action;
    document.getElementById('modalGradeId').value = gradeId;
    
    if (action === 'approve') {
        title.textContent = 'Approve Grade';
        message.textContent = 'Are you sure you want to approve this grade? It will become visible to the student and the guardian will be notified via email.';
        submitBtn.textContent = 'Approve';
        submitBtn.className = 'px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition';
    } else if (action === 'reject') {
        title.textContent = 'Reject Grade';
        message.textContent = 'Are you sure you want to reject this grade? It will be returned to the teacher for revision. Please provide a reason below.';
        submitBtn.textContent = 'Reject';
        submitBtn.className = 'px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition';
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeGradeModal() {
    const modal = document.getElementById('actionModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.getElementById('actionModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeGradeModal();
});
</script>

<?php include '../includes/footer.php'; ?>
