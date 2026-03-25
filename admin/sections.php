<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Sections';
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $section_code = sanitize($_POST['section_code']);
        $academic_track_id = !empty($_POST['academic_track_id']) ? (int)$_POST['academic_track_id'] : null;
        $level_id = !empty($_POST['level_id']) ? (int)$_POST['level_id'] : null;
        $sy_id = !empty($_POST['sy_id']) ? (int)$_POST['sy_id'] : null;
        $adviser_id = !empty($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : null;
        $status = sanitize($_POST['status']);
        // Check for duplicate section code
        $dupStmt = db()->prepare("SELECT COUNT(*) FROM tbl_section WHERE section_code = ?");
        $dupStmt->execute([$section_code]);
        if ($dupStmt->fetchColumn() > 0) {
            $message = 'Section already exists!';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("INSERT INTO tbl_section (section_code, academic_track_id, level_id, sy_id, adviser_id, status) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$section_code, $academic_track_id, $level_id, $sy_id, $adviser_id, $status])) {
                $message = 'Section added successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to add section.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $section_code = sanitize($_POST['section_code']);
        $academic_track_id = !empty($_POST['academic_track_id']) ? (int)$_POST['academic_track_id'] : null;
        $level_id = !empty($_POST['level_id']) ? (int)$_POST['level_id'] : null;
        $sy_id = !empty($_POST['sy_id']) ? (int)$_POST['sy_id'] : null;
        $adviser_id = !empty($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : null;
        $status = sanitize($_POST['status']);
        
        $stmt = db()->prepare("UPDATE tbl_section SET section_code = ?, academic_track_id = ?, level_id = ?, sy_id = ?, adviser_id = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$section_code, $academic_track_id, $level_id, $sy_id, $adviser_id, $status, $id])) {
            $message = 'Section updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update section.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = db()->prepare("DELETE FROM tbl_section WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Section deleted successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Failed to delete section. It may have students or enrollments.';
            $messageType = 'error';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? '';
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArray)) {
            try {
                $placeholders = implode(',', array_fill(0, count($idArray), '?'));
                $stmt = db()->prepare("DELETE FROM tbl_section WHERE id IN ($placeholders)");
                $stmt->execute($idArray);
                $message = count($idArray) . ' section(s) deleted successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to delete sections. Some may have students or enrollments.';
                $messageType = 'error';
            }
        }
    }
}

// Fetch all sections with related data
$sections = db()->query("
    SELECT s.*, 
           at.code as course_code, at.`desc` as course_name,
           sy.sy_name,
           t.name as adviser_name,
           l.code as level_code, l.description as level_desc,
           (SELECT COUNT(*) FROM tbl_student st WHERE st.section_id = s.id) as student_count
    FROM tbl_section s
    LEFT JOIN tbl_academic_track at ON s.academic_track_id = at.id
    LEFT JOIN level l ON s.level_id = l.id
    LEFT JOIN tbl_sy sy ON s.sy_id = sy.id
    LEFT JOIN tbl_teacher t ON s.adviser_id = t.id
    ORDER BY s.section_code
")->fetchAll();

// Fetch courses for dropdown
$courses = db()->query("SELECT * FROM tbl_academic_track WHERE status = 'active' ORDER BY code")->fetchAll();

// Fetch levels for dropdown
$levels = db()->query("SELECT l.*, at.code as track_code FROM level l LEFT JOIN tbl_academic_track at ON l.academic_track_id = at.id ORDER BY l.academic_track_id, l.`order`")->fetchAll();

// Fetch school years for dropdown
$schoolYears = db()->query("SELECT * FROM tbl_sy ORDER BY id DESC")->fetchAll();

// Fetch teachers for dropdown
$teachers = db()->query("SELECT * FROM tbl_teacher ORDER BY name")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Sections</h1>
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

        <!-- Actions Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div class="flex items-center gap-4">
                <input type="text" id="searchInput" placeholder="Search..." 
                    class="px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                <button id="bulkDeleteBtn" onclick="bulkDelete()" class="hidden items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Delete Selected ( <span id="selectedCount">0</span> )
                </button>
            </div>
            <button onclick="openModal('addModal')" class="flex items-center gap-2 px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add Section
            </button>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]" id="dataTable">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-4 font-medium">
                            <input type="checkbox" id="selectAll" class="rounded border-gray-300">
                        </th>
                        <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Section Code</th>
                        <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Course</th>
                        <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Level</th>
                        <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">School Year</th>
                        <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Adviser</th>
                        <th class="px-7 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Students</th>
                        <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Status</th>
                        <th class="px-6 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $section): ?>
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <input type="checkbox" class="row-checkbox rounded border-gray-300" value="<?= $section['id'] ?>">
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-gray-800"><?= htmlspecialchars($section['section_code']) ?></td>
                        <td class="px-6 py-4 text-sm">
                            <?php if ($section['course_code']): ?>
                            <span class="font-medium"><?= htmlspecialchars($section['course_code']) ?></span>
                            <span class="text-gray-500 text-xs block"><?= htmlspecialchars($section['course_name']) ?></span>
                            <?php else: ?>
                            <span class="text-gray-400">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <?php if ($section['level_code']): ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800">
                                <?= htmlspecialchars($section['level_desc']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-sm"><?= htmlspecialchars($section['sy_name'] ?? 'N/A') ?></td>
                        <td class="px-6 py-4 text-sm"><?= htmlspecialchars($section['adviser_name'] ?? 'N/A') ?></td>
                        <td class="px-6 py-4 text-sm">
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                                <?= $section['student_count'] ?> students
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?= $section['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                <?= ucfirst($section['status']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <button onclick='editSection(<?= json_encode($section) ?>)' class="p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition" title="Edit">
                                    Edit
                                </button>
                                <button onclick="deleteSection(<?= $section['id'] ?>, '<?= htmlspecialchars($section['section_code'], ENT_QUOTES) ?>')" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition" title="Delete">
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sections)): ?>
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center text-gray-500">No sections found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</main>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/50" onclick="closeModal('addModal')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Add Section</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="add">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section Code</label>
                    <input type="text" name="section_code" required placeholder="e.g., GRADE7-A, BSIT-1A"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Course/Track</label>
                    <select name="academic_track_id" id="addCourse" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" onchange="filterLevels('add')">
                        <option value="">Select Course</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['code'] . ' - ' . $course['desc']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Level</label>
                    <select name="level_id" id="addLevel" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Course First</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                    <select name="sy_id" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select School Year</option>
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>"><?= htmlspecialchars($sy['sy_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Adviser (Optional)</label>
                    <select name="adviser_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Adviser</option>
                        <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">Add Section</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/50" onclick="closeModal('editModal')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Edit Section</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section Code</label>
                    <input type="text" name="section_code" id="editSectionCode" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Course/Track</label>
                    <select name="academic_track_id" id="editCourse" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" onchange="filterLevels('edit')">
                        <option value="">Select Course</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['code'] . ' - ' . $course['desc']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Level</label>
                    <select name="level_id" id="editLevel" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Course First</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                    <select name="sy_id" id="editSY" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select School Year</option>
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>"><?= htmlspecialchars($sy['sy_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Adviser (Optional)</label>
                    <select name="adviser_id" id="editAdviser" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Adviser</option>
                        <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" id="editStatus" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">Update Section</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<!-- Bulk Delete Form -->
<form id="bulkDeleteForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="ids" id="bulkDeleteIds">
</form>

<script>
const levelsData = <?= json_encode(array_map(function($l) {
    return ['id' => $l['id'], 'code' => $l['code'], 'description' => $l['description'], 'academic_track_id' => $l['academic_track_id']];
}, $levels)) ?>;

function filterLevels(prefix) {
    const courseSelect = document.getElementById(prefix + 'Course');
    const levelSelect = document.getElementById(prefix + 'Level');
    const trackId = parseInt(courseSelect.value) || 0;
    
    levelSelect.innerHTML = '<option value="">Select Level</option>';
    
    const filtered = levelsData.filter(l => l.academic_track_id == trackId);
    filtered.forEach(l => {
        const opt = document.createElement('option');
        opt.value = l.id;
        opt.textContent = l.description;
        levelSelect.appendChild(opt);
    });
    
    // Auto-select if only one level
    if (filtered.length === 1) {
        levelSelect.value = filtered[0].id;
    }
}

function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function editSection(section) {
    document.getElementById('editId').value = section.id;
    document.getElementById('editSectionCode').value = section.section_code;
    document.getElementById('editCourse').value = section.academic_track_id || '';
    filterLevels('edit');
    document.getElementById('editLevel').value = section.level_id || '';
    document.getElementById('editSY').value = section.sy_id || '';
    document.getElementById('editAdviser').value = section.adviser_id || '';
    document.getElementById('editStatus').value = section.status;
    openModal('editModal');
}

function deleteSection(id, name) {
    if (confirm('Are you sure you want to delete section "' + name + '"?')) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

function bulkDelete() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);
    if (ids.length > 0 && confirm('Are you sure you want to delete ' + ids.length + ' section(s)?')) {
        document.getElementById('bulkDeleteIds').value = ids.join(',');
        document.getElementById('bulkDeleteForm').submit();
    }
}

// Checkbox selection functionality
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    function updateBulkActions() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        selectedCountSpan.textContent = checkedCount;
        
        if (checkedCount > 0) {
            bulkDeleteBtn.classList.remove('hidden');
            bulkDeleteBtn.classList.add('flex');
        } else {
            bulkDeleteBtn.classList.add('hidden');
            bulkDeleteBtn.classList.remove('flex');
        }
        
        // Update selectAll checkbox state
        if (checkedCount === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedCount === rowCheckboxes.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }
    
    // Select All checkbox
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            rowCheckboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            updateBulkActions();
        });
    }
    
    // Individual row checkboxes
    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkActions);
    });
});
</script>

<?php include '../includes/footer.php'; ?>
