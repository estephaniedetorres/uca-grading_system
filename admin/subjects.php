<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Subjects';
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $subjcode = sanitize($_POST['subjcode']);
        $desc = sanitize($_POST['desc']);
        $education_level = sanitize($_POST['education_level']);
        $unit = $_POST['unit'] !== '' ? (int)$_POST['unit'] : null;
        $lec_u = $_POST['lec_u'] !== '' ? (int)$_POST['lec_u'] : null;
        $lab_u = $_POST['lab_u'] !== '' ? (int)$_POST['lab_u'] : null;
        $lec_h = (int)$_POST['lec_h'];
        $lab_h = (int)$_POST['lab_h'];
        $type = sanitize($_POST['type']);
        $weight_category = sanitize($_POST['weight_category']);
        $term_restriction = sanitize($_POST['term_restriction']);
        $status = sanitize($_POST['status']);
        
        // Check for duplicate subject code
        $checkStmt = db()->prepare("SELECT COUNT(*) FROM tbl_subjects WHERE subjcode = ?");
        $checkStmt->execute([$subjcode]);
        if ($checkStmt->fetchColumn() > 0) {
            $message = 'Subject code "' . htmlspecialchars($subjcode) . '" already exists.';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$subjcode, $desc, $unit, $lec_u, $lab_u, $lec_h, $lab_h, $type, $education_level, $weight_category, $term_restriction, $status])) {
                $message = 'Subject added successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to add subject.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $subjcode = sanitize($_POST['subjcode']);
        $desc = sanitize($_POST['desc']);
        $education_level = sanitize($_POST['education_level']);
        $unit = $_POST['unit'] !== '' ? (int)$_POST['unit'] : null;
        $lec_u = $_POST['lec_u'] !== '' ? (int)$_POST['lec_u'] : null;
        $lab_u = $_POST['lab_u'] !== '' ? (int)$_POST['lab_u'] : null;
        $lec_h = (int)$_POST['lec_h'];
        $lab_h = (int)$_POST['lab_h'];
        $type = sanitize($_POST['type']);
        $weight_category = sanitize($_POST['weight_category']);
        $term_restriction = sanitize($_POST['term_restriction']);
        $status = sanitize($_POST['status']);
        
        $stmt = db()->prepare("UPDATE tbl_subjects SET subjcode = ?, `desc` = ?, unit = ?, lec_u = ?, lab_u = ?, lec_h = ?, lab_h = ?, type = ?, education_level = ?, weight_category = ?, term_restriction = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$subjcode, $desc, $unit, $lec_u, $lab_u, $lec_h, $lab_h, $type, $education_level, $weight_category, $term_restriction, $status, $id])) {
            $message = 'Subject updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update subject.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare("DELETE FROM tbl_subjects WHERE id = ?");
        try {
            $stmt->execute([$id]);
            $message = 'Subject deleted successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Cannot delete this subject because it is referenced elsewhere. Remove related records first.';
            $messageType = 'error';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? '';
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArray)) {
            $placeholders = implode(',', array_fill(0, count($idArray), '?'));
            $stmt = db()->prepare("DELETE FROM tbl_subjects WHERE id IN ($placeholders)");
            try {
                $stmt->execute($idArray);
                $message = count($idArray) . ' subject(s) deleted successfully!';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Some subjects could not be deleted because they are referenced elsewhere. Remove those references first.';
                $messageType = 'error';
            }
        }
    }
}

// Fetch all subjects
$subjects = db()->query("SELECT * FROM tbl_subjects ORDER BY id ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Subjects</h1>
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
                Add Subject
            </button>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[1000px]" id="dataTable">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-4 py-4 font-medium">
                            <input type="checkbox" class="rounded border-gray-300">
                        </th>
                        <th class="px-4 py-4 font-medium sortable">Code</th>
                        <th class="px-4 py-4 font-medium sortable">Description</th>
                        <th class="px-4 py-4 font-medium sortable">Level</th>
                        <th class="px-4 py-4 font-medium sortable">Units</th>
                        <th class="px-4 py-4 font-medium sortable">Lec U</th>
                        <th class="px-4 py-4 font-medium sortable">Lab U</th>
                        <th class="px-4 py-4 font-medium sortable">Lec H</th>
                        <th class="px-4 py-4 font-medium sortable">Lab H</th>
                        <th class="px-4 py-4 font-medium sortable">Type</th>
                        <th class="px-4 py-4 font-medium sortable">Weight Cat.</th>
                        <th class="px-4 py-4 font-medium sortable">Status</th>
                        <th class="px-4 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $subj): ?>
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-4 py-4">
                            <input type="checkbox" class="rounded border-gray-300">
                        </td>
                        <td class="px-4 py-4 text-sm font-medium text-neutral-700"><?= htmlspecialchars($subj['subjcode']) ?></td>
                        <td class="px-4 py-4 text-sm"><?= htmlspecialchars($subj['desc']) ?></td>
                        <td class="px-4 py-4 text-sm">
                            <?php
                            $level = $subj['education_level'] ?? 'both';
                            $levelLabels = ['k12' => 'K-12', 'college' => 'College', 'both' => 'Both'];
                            $levelColors = ['k12' => 'bg-green-100 text-green-800', 'college' => 'bg-blue-100 text-blue-800', 'both' => 'bg-gray-100 text-gray-800'];
                            ?>
                            <span class="px-2 py-1 rounded-full text-xs <?= $levelColors[$level] ?>"><?= $levelLabels[$level] ?></span>
                        </td>
                        <td class="px-4 py-4 text-sm"><?= $subj['unit'] !== null ? $subj['unit'] : '<span class="text-gray-400">-</span>' ?></td>
                        <td class="px-4 py-4 text-sm"><?= $subj['lec_u'] !== null ? $subj['lec_u'] : '<span class="text-gray-400">-</span>' ?></td>
                        <td class="px-4 py-4 text-sm"><?= $subj['lab_u'] !== null ? $subj['lab_u'] : '<span class="text-gray-400">-</span>' ?></td>
                        <td class="px-4 py-4 text-sm"><?= $subj['lec_h'] ?></td>
                        <td class="px-4 py-4 text-sm"><?= $subj['lab_h'] ?></td>
                        <td class="px-4 py-4 text-sm"><?= htmlspecialchars($subj['type']) ?></td>
                        <td class="px-4 py-4 text-sm"><?= htmlspecialchars($subj['weight_category'] ?? 'core') ?></td>
                        <td class="px-4 py-4 text-sm"><?= $subj['status'] ?></td>
                        <td class="px-4 py-4 text-sm whitespace-nowrap">
                            <button onclick="editSubject(<?= htmlspecialchars(json_encode($subj)) ?>)" 
                                class="text-neutral-700 hover:text-black mr-3">Edit</button>
                            <button onclick="deleteSubject(<?= $subj['id'] ?>, '<?= htmlspecialchars($subj['subjcode']) ?>')" 
                                class="text-red-600 hover:text-red-800">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($subjects)): ?>
                    <tr>
                        <td colspan="12" class="px-6 py-8 text-center text-gray-500">No subjects found</td>
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
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Add Subject</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="add">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject Code</label>
                    <input type="text" name="subjcode" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="Core">Core</option>
                        <option value="Major">Major</option>
                        <option value="Minor">Minor</option>
                        <option value="Elective">Elective</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="desc" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Education Level</label>
                    <select name="education_level" id="addEducationLevel" onchange="toggleUnitFields('add')" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="both">Both (K-12 & College)</option>
                        <option value="k12">K-12 Only</option>
                        <option value="college">College/Higher Education</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Units are optional for K-12 subjects</p>
                </div>
                <div id="addUnitFields">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total Units <span class="text-gray-400">(College)</span></label>
                    <input type="number" name="unit" value="" min="0" placeholder="Leave blank for K-12"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="addLecUnitField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lecture Units</label>
                    <input type="number" name="lec_u" value="" min="0" placeholder="Optional"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="addLabUnitField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lab Units</label>
                    <input type="number" name="lab_u" value="" min="0" placeholder="Optional"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lecture Hours</label>
                    <input id="addLecHours" type="number" name="lec_h" value="3" min="0" data-default-value="3"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lab Hours</label>
                    <input id="addLabHours" type="number" name="lab_h" value="0" min="0" data-default-value="0"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Weight Category</label>
                    <select name="weight_category" id="addWeightCategory" onchange="toggleTermField('add')" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <optgroup label="Grades 1-10 (Elementary/JHS)">
                            <option value="languages">Languages, AP, EsP (30/50/20)</option>
                            <option value="science_math">Science, Math (40/40/20)</option>
                            <option value="mapeh_epp">MAPEH, EPP/TLE (20/60/20)</option>
                        </optgroup>
                        <optgroup label="Grades 11-12 (SHS)">
                            <option value="core" selected>Core Subjects (25/50/25)</option>
                            <option value="academic">Academic Track (25/45/30)</option>
                            <option value="work_immersion">Work Immersion/Research (35/40/25)</option>
                            <option value="tvl_sports_arts">TVL/Sports/Arts (20/60/20)</option>
                        </optgroup>
                    </select>
                </div>
                <div id="addTermField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Term Restriction</label>
                    <select name="term_restriction" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="any" selected>Any Term</option>
                        <option value="term1">Term 1 Only</option>
                        <option value="term2">Term 2 Only</option>
                    </select>
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
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Edit Subject</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject Code</label>
                    <input type="text" name="subjcode" id="editSubjcode" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" id="editType" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="Core">Core</option>
                        <option value="Major">Major</option>
                        <option value="Minor">Minor</option>
                        <option value="Elective">Elective</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="desc" id="editDesc" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Education Level</label>
                    <select name="education_level" id="editEducationLevel" onchange="toggleUnitFields('edit')" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="both">Both (K-12 & College)</option>
                        <option value="k12">K-12 Only</option>
                        <option value="college">College/Higher Education</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Units are optional for K-12 subjects</p>
                </div>
                <div id="editUnitFields">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total Units <span class="text-gray-400">(College)</span></label>
                    <input type="number" name="unit" id="editUnit" min="0" placeholder="Leave blank for K-12"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="editLecUnitField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lecture Units</label>
                    <input type="number" name="lec_u" id="editLecU" min="0" placeholder="Optional"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="editLabUnitField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lab Units</label>
                    <input type="number" name="lab_u" id="editLabU" min="0" placeholder="Optional"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lecture Hours</label>
                    <input id="editLecHours" type="number" name="lec_h" min="0" 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lab Hours</label>
                    <input id="editLabHours" type="number" name="lab_h" min="0" 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Weight Category</label>
                    <select name="weight_category" id="editWeightCategory" onchange="toggleTermField('edit')" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <optgroup label="Grades 1-10 (Elementary/JHS)">
                            <option value="languages">Languages, AP, EsP (30/50/20)</option>
                            <option value="science_math">Science, Math (40/40/20)</option>
                            <option value="mapeh_epp">MAPEH, EPP/TLE (20/60/20)</option>
                        </optgroup>
                        <optgroup label="Grades 11-12 (SHS)">
                            <option value="core">Core Subjects (25/50/25)</option>
                            <option value="academic">Academic Track (25/45/30)</option>
                            <option value="work_immersion">Work Immersion/Research (35/40/25)</option>
                            <option value="tvl_sports_arts">TVL/Sports/Arts (20/60/20)</option>
                        </optgroup>
                    </select>
                </div>
                <div id="editTermField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Term Restriction</label>
                    <select name="term_restriction" id="editTermRestriction" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="any">Any Term</option>
                        <option value="term1">Term 1 Only</option>
                        <option value="term2">Term 2 Only</option>
                    </select>
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
            <h3 class="text-lg font-semibold text-gray-800">Delete Subject</h3>
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
            <h3 class="text-lg font-semibold text-gray-800">Delete Multiple Subjects</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="ids" id="bulkDeleteIds">
            <p class="text-gray-600 mb-6">Are you sure you want to delete <span id="bulkDeleteCount" class="font-semibold">0</span> subject(s)? This action cannot be undone.</p>
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
    // Toggle unit fields visibility based on education level
    function toggleUnitFields(prefix) {
        const level = document.getElementById(prefix + 'EducationLevel').value;
        const unitFields = document.getElementById(prefix + 'UnitFields');
        const lecUnitField = document.getElementById(prefix + 'LecUnitField');
        const labUnitField = document.getElementById(prefix + 'LabUnitField');
        
        if (level === 'k12') {
            // For K-12, show units as optional with dimmed appearance
            if (unitFields) unitFields.style.opacity = '0.6';
            if (lecUnitField) lecUnitField.style.opacity = '0.6';
            if (labUnitField) labUnitField.style.opacity = '0.6';
        } else {
            // For College or Both, show units normally
            if (unitFields) unitFields.style.opacity = '1';
            if (lecUnitField) lecUnitField.style.opacity = '1';
            if (labUnitField) labUnitField.style.opacity = '1';
        }

        styleHourInputs(prefix, level);
    }

    function styleHourInputs(prefix, level) {
        const lecHours = document.getElementById(prefix + 'LecHours');
        const labHours = document.getElementById(prefix + 'LabHours');
        const isK12 = level === 'k12';
        const isAddForm = prefix === 'add';

        [lecHours, labHours].forEach(el => {
            if (!el) return;
            el.classList.toggle('k12-hour-dim', isK12);

            if (isAddForm) {
                if (isK12) {
                    el.value = '';
                    el.placeholder = 'Optional for K-12';
                } else {
                    const defaultValue = el.dataset.defaultValue;
                    if (defaultValue !== undefined && el.value.trim() === '') {
                        el.value = defaultValue;
                    }
                    el.placeholder = '';
                }
            }
        });
    }

    function editSubject(subj) {
        document.getElementById('editId').value = subj.id;
        document.getElementById('editSubjcode').value = subj.subjcode;
        document.getElementById('editDesc').value = subj.desc;
        document.getElementById('editUnit').value = subj.unit || '';
        document.getElementById('editLecU').value = subj.lec_u || '';
        document.getElementById('editLabU').value = subj.lab_u || '';
        document.getElementById('editLecHours').value = subj.lec_h;
        document.getElementById('editLabHours').value = subj.lab_h;
        document.getElementById('editType').value = subj.type;
        document.getElementById('editEducationLevel').value = subj.education_level || 'both';
        document.getElementById('editWeightCategory').value = subj.weight_category || 'core';
        document.getElementById('editTermRestriction').value = subj.term_restriction || 'any';
        document.getElementById('editStatus').value = subj.status;
        toggleUnitFields('edit');
        toggleTermField('edit');
        openModal('editModal');
    }

    // Grade 1-10 weight categories have no term restriction
    const k12WeightCategories = ['languages', 'science_math', 'mapeh_epp'];

    function toggleTermField(prefix) {
        const weightCat = document.getElementById(prefix + 'WeightCategory').value;
        const termField = document.getElementById(prefix + 'TermField');
        if (!termField) return;
        const isK12Cat = k12WeightCategories.includes(weightCat);
        termField.style.display = isK12Cat ? 'none' : '';
        // Reset to "any" when hidden so it doesn't submit a stale value
        if (isK12Cat) {
            const sel = termField.querySelector('select');
            if (sel) sel.value = 'any';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        setupSearch('searchInput', 'dataTable');
        toggleUnitFields('add');
        toggleUnitFields('edit');
        toggleTermField('add');
        toggleTermField('edit');
    });

    function deleteSubject(id, name) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteName').textContent = name;
        openModal('deleteModal');
    }
</script>

<?php include '../includes/footer.php'; ?>
