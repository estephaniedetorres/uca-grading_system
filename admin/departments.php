<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Departments';
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $code = sanitize($_POST['code']);
        $description = sanitize($_POST['description']);
        $status = sanitize($_POST['status']);
        // Check for duplicate department code or description
        $dupStmt = db()->prepare("SELECT COUNT(*) FROM tbl_departments WHERE code = ? OR description = ?");
        $dupStmt->execute([$code, $description]);
        if ($dupStmt->fetchColumn() > 0) {
            $message = 'Department already exists!';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("INSERT INTO tbl_departments (code, description, status) VALUES (?, ?, ?)");
            if ($stmt->execute([$code, $description, $status])) {
                $message = 'Department added successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to add department.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $code = sanitize($_POST['code']);
        $description = sanitize($_POST['description']);
        $status = sanitize($_POST['status']);
        
        $stmt = db()->prepare("UPDATE tbl_departments SET code = ?, description = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$code, $description, $status, $id])) {
            $message = 'Department updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update department.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare("DELETE FROM tbl_departments WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = 'Department deleted successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to delete department. It may be referenced by courses.';
            $messageType = 'error';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? '';
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArray)) {
            $placeholders = implode(',', array_fill(0, count($idArray), '?'));
            $stmt = db()->prepare("DELETE FROM tbl_departments WHERE id IN ($placeholders)");
            if ($stmt->execute($idArray)) {
                $message = count($idArray) . ' department(s) deleted successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to delete departments.';
                $messageType = 'error';
            }
        }
    }
}

// Fetch all departments
$departments = db()->query("SELECT * FROM tbl_departments ORDER BY id ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Departments</h1>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2 text-gray-500 text-sm">
                    <svg class="w-5 h-5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span><?= getCurrentDate() ?></span>
                </div>
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
                    <svg class="w-5 h-5 text-gray-400 absolute right-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
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
                Add Department
            </button>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[600px]" id="dataTable">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-4 font-medium">
                            <input type="checkbox" class="rounded border-gray-300">
                        </th>
                        <th class="px-6 py-4 font-medium sortable">Code</th>
                        <th class="px-6 py-4 font-medium sortable">Description</th>
                        <th class="px-6 py-4 font-medium sortable">Status</th>
                        <th class="px-6 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $dept): ?>
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <input type="checkbox" class="rounded border-gray-300">
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-neutral-700"><?= htmlspecialchars($dept['code']) ?></td>
                        <td class="px-6 py-4 text-sm"><?= htmlspecialchars($dept['description']) ?></td>
                        <td class="px-6 py-4 text-sm"><?= $dept['status'] ?></td>
                        <td class="px-6 py-4 text-sm">
                            <button onclick="editDepartment(<?= htmlspecialchars(json_encode($dept)) ?>)" 
                                class="text-neutral-700 hover:text-black mr-3">Edit</button>
                            <button onclick="deleteDepartment(<?= $dept['id'] ?>, '<?= htmlspecialchars($dept['code']) ?>')" 
                                class="text-red-600 hover:text-red-800">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($departments)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">No departments found</td>
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
            <h3 class="text-lg font-semibold text-gray-800">Add Department</h3>
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
                    <input type="text" name="description" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
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
            <h3 class="text-lg font-semibold text-gray-800">Edit Department</h3>
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
                    <input type="text" name="description" id="editDescription" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
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
            <h3 class="text-lg font-semibold text-gray-800">Delete Department</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <p class="text-gray-600 mb-6">Are you sure you want to delete <span id="deleteName" class="font-semibold"></span>? This action cannot be undone.</p>
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
            <h3 class="text-lg font-semibold text-gray-800">Delete Multiple Departments</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="ids" id="bulkDeleteIds">
            <p class="text-gray-600 mb-6">Are you sure you want to delete <span id="bulkDeleteCount" class="font-semibold">0</span> department(s)? This action cannot be undone.</p>
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

    function editDepartment(dept) {
        document.getElementById('editId').value = dept.id;
        document.getElementById('editCode').value = dept.code;
        document.getElementById('editDescription').value = dept.description;
        document.getElementById('editStatus').value = dept.status;
        openModal('editModal');
    }

    function deleteDepartment(id, name) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteName').textContent = name;
        openModal('deleteModal');
    }
</script>

<?php include '../includes/footer.php'; ?>
