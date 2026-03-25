<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('principal');

$pageTitle = 'Teacher Subject Management';
$message = '';
$messageType = '';

// Get the principal's assigned departments (array)
$principalDeptIds = $_SESSION['dept_ids'] ?? [];
$principalDeptNames = $_SESSION['dept_names'] ?? [];
$principalDeptLabel = !empty($principalDeptNames) ? implode(', ', $principalDeptNames) : 'All Departments';

// If principal has no department assigned, show a warning
if (empty($principalDeptIds)) {
    $deptWarning = 'You have not been assigned to a department yet. Please contact the admin to assign you to a department.';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $teacher_id = (int)$_POST['teacher_id'];
        $section_id = (int)$_POST['section_id'];
        $subject_id = (int)$_POST['subject_id'];
        $sy_id = (int)$_POST['sy_id'];
        $status = sanitize($_POST['status']);
        
        // Verify section belongs to principal's departments
        if (!empty($principalDeptIds)) {
            $ph = deptPlaceholders($principalDeptIds);
            $checkDept = db()->prepare("SELECT s.id FROM tbl_section s JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE s.id = ? AND at.dept_id IN ($ph)");
            $checkDept->execute(array_merge([$section_id], $principalDeptIds));
            if (!$checkDept->fetch()) {
                $message = 'You can only assign teachers to sections within your departments.';
                $messageType = 'error';
                goto skipPrincipalAdd;
            }
        }
        
        // Check if assignment already exists
        $checkStmt = db()->prepare("SELECT id FROM tbl_teacher_subject WHERE teacher_id = ? AND section_id = ? AND subject_id = ? AND sy_id = ?");
        $checkStmt->execute([$teacher_id, $section_id, $subject_id, $sy_id]);
        
        if ($checkStmt->fetch()) {
            $message = 'This teacher is already assigned to this subject for this section.';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("INSERT INTO tbl_teacher_subject (teacher_id, section_id, subject_id, sy_id, status) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$teacher_id, $section_id, $subject_id, $sy_id, $status])) {
                $message = 'Teacher subject assignment added successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to add assignment.';
                $messageType = 'error';
            }
        }
        skipPrincipalAdd:
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $teacher_id = (int)$_POST['teacher_id'];
        $section_id = (int)$_POST['section_id'];
        $subject_id = (int)$_POST['subject_id'];
        $sy_id = (int)$_POST['sy_id'];
        $status = sanitize($_POST['status']);
        
        // Verify section belongs to principal's departments
        if (!empty($principalDeptIds)) {
            $ph = deptPlaceholders($principalDeptIds);
            $checkDept = db()->prepare("SELECT s.id FROM tbl_section s JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE s.id = ? AND at.dept_id IN ($ph)");
            $checkDept->execute(array_merge([$section_id], $principalDeptIds));
            if (!$checkDept->fetch()) {
                $message = 'You can only assign teachers to sections within your departments.';
                $messageType = 'error';
                goto skipPrincipalEdit;
            }
        }
        
        $stmt = db()->prepare("UPDATE tbl_teacher_subject SET teacher_id = ?, section_id = ?, subject_id = ?, sy_id = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$teacher_id, $section_id, $subject_id, $sy_id, $status, $id])) {
            $message = 'Assignment updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update assignment.';
            $messageType = 'error';
        }
        skipPrincipalEdit:
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare("DELETE FROM tbl_teacher_subject WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = 'Assignment deleted successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to delete assignment.';
            $messageType = 'error';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? '';
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArray)) {
            $placeholders = implode(',', array_fill(0, count($idArray), '?'));
            $stmt = db()->prepare("DELETE FROM tbl_teacher_subject WHERE id IN ($placeholders)");
            if ($stmt->execute($idArray)) {
                $message = count($idArray) . ' assignment(s) deleted successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to delete assignments.';
                $messageType = 'error';
            }
        }
    }
}

// Get filters
$teacherFilter = $_GET['teacher'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$syFilter = $_GET['sy'] ?? '';

// Fetch filter options — scoped to principal's departments
if (!empty($principalDeptIds)) {
    $ph = deptPlaceholders($principalDeptIds);
    $sections = db()->prepare("SELECT s.*, at.code as track_code FROM tbl_section s LEFT JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE s.status = 'active' AND at.dept_id IN ($ph) ORDER BY s.section_code");
    $sections->execute($principalDeptIds);
    $sections = $sections->fetchAll();
    
    // Get section IDs for these departments to scope teachers
    $sectionIds = array_column($sections, 'id');
    if (!empty($sectionIds)) {
        $secPh = implode(',', array_fill(0, count($sectionIds), '?'));
        $teachers = db()->prepare("SELECT DISTINCT t.* FROM tbl_teacher t JOIN tbl_teacher_subject ts ON t.id = ts.teacher_id WHERE ts.section_id IN ($secPh) ORDER BY t.name");
        $teachers->execute($sectionIds);
        $teachers = $teachers->fetchAll();
        
        if (empty($teachers)) {
            $teachers = db()->query("SELECT * FROM tbl_teacher ORDER BY name")->fetchAll();
        }
    } else {
        $teachers = db()->query("SELECT * FROM tbl_teacher ORDER BY name")->fetchAll();
    }
} else {
    $teachers = db()->query("SELECT * FROM tbl_teacher ORDER BY name")->fetchAll();
    $sections = db()->query("SELECT s.*, at.code as track_code FROM tbl_section s LEFT JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE s.status = 'active' ORDER BY s.section_code")->fetchAll();
}
$subjects = db()->query("SELECT * FROM tbl_subjects WHERE status = 'active' ORDER BY subjcode")->fetchAll();
$schoolYears = db()->query("SELECT * FROM tbl_sy ORDER BY id DESC")->fetchAll();

// For the add/edit modal, always show all teachers so principal can assign new teachers
$allTeachers = db()->query("SELECT * FROM tbl_teacher ORDER BY name")->fetchAll();

// Build query with filters — always scoped to principal's departments
$params = [];
$whereConditions = [];

// Department scope filter (always applied if principal has departments)
if (!empty($principalDeptIds)) {
    $ph = deptPlaceholders($principalDeptIds);
    $whereConditions[] = "at.dept_id IN ($ph)";
    $params = array_merge($params, $principalDeptIds);
}

if ($teacherFilter) {
    $whereConditions[] = "ts.teacher_id = ?";
    $params[] = $teacherFilter;
}
if ($sectionFilter) {
    $whereConditions[] = "ts.section_id = ?";
    $params[] = $sectionFilter;
}
if ($syFilter) {
    $whereConditions[] = "ts.sy_id = ?";
    $params[] = $syFilter;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$assignmentsStmt = db()->prepare("
    SELECT ts.*, 
           t.name as teacher_name,
           sec.section_code,
           at.code as track_code, at.desc as track_desc,
           sub.subjcode, sub.desc as subject_name,
           sy.sy_name
    FROM tbl_teacher_subject ts
    JOIN tbl_teacher t ON ts.teacher_id = t.id
    JOIN tbl_section sec ON ts.section_id = sec.id
    LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    JOIN tbl_subjects sub ON ts.subject_id = sub.id
    LEFT JOIN tbl_sy sy ON ts.sy_id = sy.id
    $whereClause
    ORDER BY t.name, sec.section_code, sub.subjcode
");
$assignmentsStmt->execute($params);
$assignments = $assignmentsStmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_principal.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Teacher Subject Management</h1>
                <?php if (!empty($principalDeptIds)): ?>
                <p class="text-sm text-gray-500 mt-1">Department(s): <span class="font-medium text-gray-700"><?= htmlspecialchars($principalDeptLabel) ?></span></p>
                <?php endif; ?>
            </div>
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

        <?php if (isset($deptWarning)): ?>
        <div class="mb-6 p-4 rounded-lg bg-yellow-100 text-yellow-800 border border-yellow-200">
            <strong>Notice:</strong> <?= $deptWarning ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-800 mb-4">Filters</h3>
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Teacher</label>
                    <select name="teacher" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">All Teachers</option>
                        <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= $teacher['id'] ?>" <?= $teacherFilter == $teacher['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($teacher['name']) ?>
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                    <select name="sy" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">All School Years</option>
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>" <?= $syFilter == $sy['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sy['sy_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                        Filter
                    </button>
                    <a href="teacher_subjects.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                        Reset
                    </a>
                </div>
            </form>
        </div>

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
            <button onclick="openModal('addModal')" 
                class="flex items-center gap-2 bg-black text-white px-4 py-2 rounded-lg hover:bg-neutral-800 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add Assignment
            </button>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]" id="dataTable">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-4 py-4 font-medium">
                            <input type="checkbox" class="rounded border-gray-300" id="selectAllCheckbox">
                        </th>
                        <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">ID</th>
                        <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Teacher</th>
                        <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Section</th>
                        <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Subject</th>
                        <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">School Year</th>
                        <th class="px-4 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Status</th>
                        <th class="px-4 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $assign): ?>
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-4 py-4">
                            <input type="checkbox" class="row-checkbox rounded border-gray-300" value="<?= $assign['id'] ?>">
                        </td>
                        <td class="px-4 py-4 text-sm"><?= $assign['id'] ?></td>
                        <td class="px-4 py-4 text-sm font-medium text-neutral-700"><?= htmlspecialchars($assign['teacher_name']) ?></td>
                        <td class="px-4 py-4 text-sm">
                            <span class="font-medium"><?= htmlspecialchars($assign['section_code']) ?></span>
                            <span class="text-gray-500 text-xs block"><?= htmlspecialchars($assign['track_desc'] ?? '') ?></span>
                        </td>
                        <td class="px-4 py-4 text-sm">
                            <span class="font-medium"><?= htmlspecialchars($assign['subjcode']) ?></span>
                            <span class="text-gray-500 text-xs block"><?= htmlspecialchars($assign['subject_name']) ?></span>
                        </td>
                        <td class="px-4 py-4 text-sm"><?= htmlspecialchars($assign['sy_name'] ?? 'N/A') ?></td>
                        <td class="px-4 py-4 text-sm">
                            <span class="px-2 py-1 rounded-full text-xs <?= $assign['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= ucfirst($assign['status']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 text-sm whitespace-nowrap">
                            <button onclick="editAssignment(<?= htmlspecialchars(json_encode($assign)) ?>)" 
                                class="text-neutral-700 hover:text-black mr-3">Edit</button>
                            <button onclick="deleteAssignment(<?= $assign['id'] ?>, '<?= htmlspecialchars($assign['teacher_name'] . ' - ' . $assign['subjcode']) ?>')" 
                                class="text-red-600 hover:text-red-800">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($assignments)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-8 text-center text-gray-500">No assignments found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        
        <!-- Summary -->
        <div class="mt-4 text-sm text-gray-500">
            Showing <?= count($assignments) ?> assignment(s)
        </div>
    </div>
</main>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal('addModal')"></div>
    <div class="relative w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-xl p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Add Teacher Subject Assignment</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Teacher *</label>
                    <select name="teacher_id" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Teacher</option>
                        <?php foreach ($allTeachers as $teacher): ?>
                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section *</label>
                    <select name="section_id" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['section_code'] . ' (' . ($sec['track_code'] ?? 'N/A') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject *</label>
                    <select name="subject_id" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $subj): ?>
                        <option value="<?= $subj['id'] ?>"><?= htmlspecialchars($subj['subjcode'] . ' - ' . $subj['desc']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year *</label>
                    <select name="sy_id" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select School Year</option>
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>" <?= $sy['status'] === 'active' ? 'selected' : '' ?>><?= htmlspecialchars($sy['sy_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">Add Assignment</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal('editModal')"></div>
    <div class="relative w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-xl p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Edit Assignment</h3>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Teacher *</label>
                    <select name="teacher_id" id="editTeacherId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <?php foreach ($allTeachers as $teacher): ?>
                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section *</label>
                    <select name="section_id" id="editSectionId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['section_code'] . ' (' . ($sec['track_code'] ?? 'N/A') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject *</label>
                    <select name="subject_id" id="editSubjectId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <?php foreach ($subjects as $subj): ?>
                        <option value="<?= $subj['id'] ?>"><?= htmlspecialchars($subj['subjcode'] . ' - ' . $subj['desc']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year *</label>
                    <select name="sy_id" id="editSyId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>"><?= htmlspecialchars($sy['sy_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" id="editStatus" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">Update Assignment</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal('deleteModal')"></div>
    <div class="relative w-full max-w-md mx-4 bg-white rounded-xl shadow-xl p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Delete Assignment</h3>
        <p class="text-gray-600 mb-6">Are you sure you want to delete <span id="deleteName" class="font-semibold"></span>?</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeModal('deleteModal')" class="px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Delete Modal -->
<div id="bulkDeleteModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal('bulkDeleteModal')"></div>
    <div class="relative w-full max-w-md mx-4 bg-white rounded-xl shadow-xl p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Delete Multiple Assignments</h3>
        <p class="text-gray-600 mb-6">Are you sure you want to delete <span id="bulkDeleteCount" class="font-semibold">0</span> assignment(s)? This action cannot be undone.</p>
        <form method="POST">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="ids" id="bulkDeleteIds">
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeModal('bulkDeleteModal')" class="px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">Delete All</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function editAssignment(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editTeacherId').value = data.teacher_id;
    document.getElementById('editSectionId').value = data.section_id;
    document.getElementById('editSubjectId').value = data.subject_id;
    document.getElementById('editSyId').value = data.sy_id;
    document.getElementById('editStatus').value = data.status;
    openModal('editModal');
}

function deleteAssignment(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    openModal('deleteModal');
}

function confirmBulkDelete() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);
    
    if (ids.length === 0) {
        alert('Please select at least one item to delete.');
        return;
    }
    
    document.getElementById('bulkDeleteIds').value = ids.join(',');
    document.getElementById('bulkDeleteCount').textContent = ids.length;
    openModal('bulkDeleteModal');
}

// Select all checkbox
document.getElementById('selectAllCheckbox')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateBulkDeleteButton();
});

// Individual checkboxes
document.querySelectorAll('.row-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkDeleteButton);
});

function updateBulkDeleteButton() {
    const checked = document.querySelectorAll('.row-checkbox:checked').length;
    const btn = document.getElementById('bulkDeleteBtn');
    const count = document.getElementById('selectedCount');
    
    if (checked > 0) {
        btn.classList.remove('hidden');
        btn.classList.add('flex');
        count.textContent = checked;
    } else {
        btn.classList.add('hidden');
        btn.classList.remove('flex');
    }
}

// Search functionality
document.getElementById('searchInput')?.addEventListener('input', function() {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll('#dataTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
});

// Sortable columns
document.querySelectorAll('.sortable').forEach(header => {
    header.addEventListener('click', function() {
        const table = this.closest('table');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const index = Array.from(this.parentNode.children).indexOf(this);
        const isAsc = this.classList.contains('asc');
        
        rows.sort((a, b) => {
            const aText = a.children[index]?.textContent.trim() || '';
            const bText = b.children[index]?.textContent.trim() || '';
            return isAsc ? bText.localeCompare(aText) : aText.localeCompare(bText);
        });
        
        this.classList.toggle('asc', !isAsc);
        rows.forEach(row => tbody.appendChild(row));
    });
});

// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menuToggle');
    const closeSidebarBtn = document.getElementById('closeSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (sidebarOverlay) sidebarOverlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebarFn() {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('open');
        document.body.style.overflow = '';
    }

    if (menuToggle) menuToggle.addEventListener('click', openSidebar);
    if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', closeSidebarFn);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebarFn);

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth < 1024) closeSidebarFn();
            });
        });
    }

    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) closeSidebarFn();
    });
});
</script>
</body>
</html>
