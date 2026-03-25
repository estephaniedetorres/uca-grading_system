<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Academic Tracks';
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $code = sanitize($_POST['code']);
        $desc = sanitize($_POST['desc']);
        $dept_id = (int)$_POST['dept_id'];
        $status = sanitize($_POST['status']);
        // Check for duplicate course code or description
        $dupStmt = db()->prepare("SELECT COUNT(*) FROM tbl_academic_track WHERE code = ? OR `desc` = ?");
        $dupStmt->execute([$code, $desc]);
        if ($dupStmt->fetchColumn() > 0) {
            $message = 'Academic track already exists!';
            $messageType = 'error';
        } else {
            $enrollment_type = sanitize($_POST['enrollment_type']);
            $stmt = db()->prepare("INSERT INTO tbl_academic_track (code, `desc`, dept_id, enrollment_type, status) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$code, $desc, $dept_id, $enrollment_type, $status])) {
                $message = 'Academic track added successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to add academic track.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $code = sanitize($_POST['code']);
        $desc = sanitize($_POST['desc']);
        $dept_id = (int)$_POST['dept_id'];
        $status = sanitize($_POST['status']);
        
        $enrollment_type = sanitize($_POST['enrollment_type']);
        
        $stmt = db()->prepare("UPDATE tbl_academic_track SET code = ?, `desc` = ?, dept_id = ?, enrollment_type = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$code, $desc, $dept_id, $enrollment_type, $status, $id])) {
            $message = 'Academic track updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update academic track.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare("DELETE FROM tbl_academic_track WHERE id = ?");
        try {
            if ($stmt->execute([$id])) {
                $message = 'Academic track deleted successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to delete academic track. It may have related records.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'Cannot delete academic track because related records exist. Remove dependent entries first.';
            } else {
                $message = 'Failed to delete academic track. ' . $e->getMessage();
            }
            $messageType = 'error';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? '';
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArray)) {
            $placeholders = implode(',', array_fill(0, count($idArray), '?'));
            $stmt = db()->prepare("DELETE FROM tbl_academic_track WHERE id IN ($placeholders)");
            try {
                if ($stmt->execute($idArray)) {
                    $message = count($idArray) . ' academic track(s) deleted successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to delete academic tracks.';
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $message = 'Cannot delete selected academic tracks due to related records. Remove dependencies first.';
                } else {
                    $message = 'Failed to delete academic tracks. ' . $e->getMessage();
                }
                $messageType = 'error';
            }
        }
    }
}

// Fetch all courses with department info
$courses = db()->query("SELECT c.*, d.code as dept_code, d.description as dept_name 
    FROM tbl_academic_track c 
    LEFT JOIN tbl_departments d ON c.dept_id = d.id 
    ORDER BY c.id ASC")->fetchAll();

// Fetch all departments for dropdown
$departments = db()->query("SELECT * FROM tbl_departments WHERE status = 'active' ORDER BY code")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Academic Tracks</h1>
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
            <button onclick="openModal('addModal')" 
                class="flex items-center gap-2 bg-black text-white px-4 py-2 rounded-lg hover:bg-neutral-800 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add Course
            </button>
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
                        <th class="px-6 py-4 font-medium sortable">Code</th>
                        <th class="px-6 py-4 font-medium sortable">Description</th>
                        <th class="px-6 py-4 font-medium sortable">Department</th>
                        <th class="px-6 py-4 font-medium sortable">Enrollment Type</th>
                        <th class="px-6 py-4 font-medium sortable">Status</th>
                        <th class="px-6 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course): ?>
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <input type="checkbox" class="rounded border-gray-300">
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-neutral-700"><?= htmlspecialchars($course['code']) ?></td>
                        <td class="px-6 py-4 text-sm"><?= htmlspecialchars($course['desc']) ?></td>
                        <td class="px-6 py-4 text-sm"><?= htmlspecialchars($course['dept_code'] ?? 'N/A') ?></td>
                        <td class="px-6 py-4 text-sm">
                            <span class="px-2 py-1 rounded-full text-xs <?= ($course['enrollment_type'] ?? 'yearly') === 'semestral' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' ?>">
                                <?= ucfirst($course['enrollment_type'] ?? 'yearly') ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm"><?= $course['status'] ?></td>
                        <td class="px-6 py-4 text-sm">
                            <button onclick="editCourse(<?= htmlspecialchars(json_encode($course)) ?>)" 
                                class="text-neutral-700 hover:text-black mr-3">Edit</button>
                            <button onclick="deleteCourse(<?= $course['id'] ?>, '<?= htmlspecialchars($course['code']) ?>')" 
                                class="text-red-600 hover:text-red-800">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($courses)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500">No courses found</td>
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
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Add Course</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="add">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                    <input type="text" name="code" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="desc" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                    <select name="dept_id" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['code'] . ' - ' . $dept['description']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Enrollment Type</label>
                    <select name="enrollment_type" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="yearly">Yearly</option>
                        <option value="semestral">Semestral</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Yearly for K-12 (Grades 1-10), Semestral for SHS/College</p>
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
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Edit Course</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                    <input type="text" name="code" id="editCode" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="desc" id="editDesc" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                    <select name="dept_id" id="editDeptId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['code'] . ' - ' . $dept['description']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Enrollment Type</label>
                    <select name="enrollment_type" id="editEnrollmentType" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="yearly">Yearly</option>
                        <option value="semestral">Semestral</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Yearly for K-12 (Grades 1-10), Semestral for SHS/College</p>
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
            <h3 class="text-lg font-semibold text-gray-800">Delete Course</h3>
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
            <h3 class="text-lg font-semibold text-gray-800">Delete Multiple Courses</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="ids" id="bulkDeleteIds">
            <p class="text-gray-600 mb-6">Are you sure you want to delete <span id="bulkDeleteCount" class="font-semibold">0</span> course(s)? This action cannot be undone.</p>
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('bulkDeleteModal')" 
                    class="flex-1 px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                <button type="submit" 
                    class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete All</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        setupSearch('searchInput', 'dataTable');
    });

    function editCourse(course) {
        document.getElementById('editId').value = course.id;
        document.getElementById('editCode').value = course.code;
        document.getElementById('editDesc').value = course.desc;
        document.getElementById('editDeptId').value = course.dept_id;
        document.getElementById('editEnrollmentType').value = course.enrollment_type || 'yearly';
        document.getElementById('editStatus').value = course.status;
        openModal('editModal');
    }

    function deleteCourse(id, name) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteName').textContent = name;
        openModal('deleteModal');
    }
</script>

<?php include '../includes/footer.php'; ?>
