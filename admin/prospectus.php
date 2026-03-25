<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

$curriculum_id = (int)($_GET['curriculum_id'] ?? 0);

if (!$curriculum_id) {
    header('Location: curricula.php');
    exit;
}

// Get curriculum info
$stmt = db()->prepare("
    SELECT cu.*, at.code as course_code, at.`desc` as course_name, d.code as dept_code, at.enrollment_type
    FROM tbl_curriculum cu 
    LEFT JOIN tbl_academic_track at ON cu.academic_track_id = at.id
    LEFT JOIN tbl_departments d ON at.dept_id = d.id
    WHERE cu.id = ?
");
$stmt->execute([$curriculum_id]);
$curriculum = $stmt->fetch();

if (!$curriculum) {
    header('Location: curricula.php');
    exit;
}

$deptCode = strtoupper($curriculum['dept_code'] ?? '');
$enrollmentType = $curriculum['enrollment_type'] ?? 'semestral';
$isShs = $deptCode === 'SHS';
$isPrimaryK12 = in_array($deptCode, ['PRE-EL', 'ELE', 'JHS'], true) || $enrollmentType === 'yearly';

$isK12 = $isPrimaryK12 || $isShs;
$showTermSelect = !$isPrimaryK12;

// Fetch levels for this curriculum's academic track
$levelStmt = db()->prepare("SELECT * FROM level WHERE academic_track_id = ? ORDER BY `order`");
$levelStmt->execute([$curriculum['academic_track_id'] ?? 0]);
$curriculumLevels = $levelStmt->fetchAll();
$showLevelSelect = count($curriculumLevels) > 1;



$pageTitle = 'Prospectus - ' . $curriculum['curriculum'];
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $subject_id = (int)$_POST['subject_id'];
        $termInput = $_POST['term_id'] ?? '';
        $term_id = $showTermSelect && $termInput !== '' ? (int)$termInput : null;
        $levelInput = $_POST['level_id'] ?? '';
        $level_id = $levelInput !== '' ? (int)$levelInput : null;
        $status = sanitize($_POST['status']);
        
        // Check if already exists
        if ($term_id === null) {
            if ($level_id === null) {
                $checkStmt = db()->prepare("SELECT id FROM tbl_prospectus WHERE curriculum_id = ? AND subject_id = ? AND term_id IS NULL AND level_id IS NULL");
                $checkParams = [$curriculum_id, $subject_id];
            } else {
                $checkStmt = db()->prepare("SELECT id FROM tbl_prospectus WHERE curriculum_id = ? AND subject_id = ? AND term_id IS NULL AND level_id = ?");
                $checkParams = [$curriculum_id, $subject_id, $level_id];
            }
        } else {
            if ($level_id === null) {
                $checkStmt = db()->prepare("SELECT id FROM tbl_prospectus WHERE curriculum_id = ? AND subject_id = ? AND term_id = ? AND level_id IS NULL");
                $checkParams = [$curriculum_id, $subject_id, $term_id];
            } else {
                $checkStmt = db()->prepare("SELECT id FROM tbl_prospectus WHERE curriculum_id = ? AND subject_id = ? AND term_id = ? AND level_id = ?");
                $checkParams = [$curriculum_id, $subject_id, $term_id, $level_id];
            }
        }
        $checkStmt->execute($checkParams);
        
        if ($checkStmt->fetch()) {
            $message = 'This subject is already in the prospectus for this term/level.';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("INSERT INTO tbl_prospectus (curriculum_id, subject_id, term_id, level_id, status) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$curriculum_id, $subject_id, $term_id, $level_id, $status])) {
                $message = 'Subject added to prospectus successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to add subject.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $subject_id = (int)$_POST['subject_id'];
        $termInput = $_POST['term_id'] ?? '';
        $term_id = $showTermSelect && $termInput !== '' ? (int)$termInput : null;
        $levelInput = $_POST['level_id'] ?? '';
        $level_id = $levelInput !== '' ? (int)$levelInput : null;
        $status = sanitize($_POST['status']);
        
        $stmt = db()->prepare("UPDATE tbl_prospectus SET subject_id = ?, term_id = ?, level_id = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$subject_id, $term_id, $level_id, $status, $id])) {
            $message = 'Prospectus entry updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update entry.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare("DELETE FROM tbl_prospectus WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = 'Subject removed from prospectus!';
            $messageType = 'success';
        } else {
            $message = 'Failed to remove subject.';
            $messageType = 'error';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? '';
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArray)) {
            $placeholders = implode(',', array_fill(0, count($idArray), '?'));
            $stmt = db()->prepare("DELETE FROM tbl_prospectus WHERE id IN ($placeholders)");
            if ($stmt->execute($idArray)) {
                $message = count($idArray) . ' subject(s) removed from prospectus!';
                $messageType = 'success';
            } else {
                $message = 'Failed to remove subjects.';
                $messageType = 'error';
            }
        }
    }
}

// Fetch prospectus entries
$prospectusStmt = db()->prepare("
    SELECT p.*, s.subjcode, s.`desc` as subject_name, s.unit, t.term_name, l.code as level_code, l.description as level_desc
    FROM tbl_prospectus p 
    LEFT JOIN tbl_subjects s ON p.subject_id = s.id 
    LEFT JOIN tbl_term t ON p.term_id = t.id 
    LEFT JOIN level l ON p.level_id = l.id
    WHERE p.curriculum_id = ? 
    ORDER BY l.`order`, t.id, s.subjcode
");
$prospectusStmt->execute([$curriculum_id]);
$prospectus = $prospectusStmt->fetchAll();

// Fetch all subjects for dropdown
$subjects = db()->query("SELECT * FROM tbl_subjects WHERE status = 'active' ORDER BY subjcode")->fetchAll();

// Fetch terms for dropdown
if ($isShs || $isK12) {
    // K-12 and SHS: only semesters (no summer)
    $terms = db()->query("SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.status = 'active' AND t.term_name LIKE 'Semester%' ORDER BY t.id")->fetchAll();
} else {
    // College: semesters + summer
    $terms = db()->query("SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.status = 'active' AND (t.term_name LIKE 'Semester%' OR t.term_name = 'Summer') ORDER BY t.id")->fetchAll();
}

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <a href="curricula.php" class="text-neutral-700 hover:text-black text-sm flex items-center gap-1 mb-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    Back to Curricula
                </a>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Prospectus</h1>
                <p class="text-gray-500 text-sm mt-1">
                    <?= htmlspecialchars($curriculum['curriculum']) ?> - <?= htmlspecialchars($curriculum['course_code'] . ' - ' . $curriculum['course_name']) ?>
                </p>
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

        <!-- Action Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search subjects..." 
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
            <button onclick="openAddSubjectModal()" 
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
            <table class="w-full" id="dataTable">
                <thead class="bg-gray-50">
                    <tr class="text-left text-sm text-gray-600">
                        <th class="px-6 py-4 font-medium">
                            <input type="checkbox" class="rounded border-gray-300">
                        </th>
                        <th class="px-6 py-4 font-medium sortable">Subject Code</th>
                        <th class="px-6 py-4 font-medium sortable">Subject Name</th>
                        <th class="px-6 py-4 font-medium sortable">Units</th>
                        <?php if ($showLevelSelect): ?>
                        <th class="px-6 py-4 font-medium sortable">Level</th>
                        <?php endif; ?>
                        <?php if ($showTermSelect): ?>
                        <th class="px-6 py-4 font-medium sortable">Term</th>
                        <?php endif; ?>
                        <th class="px-6 py-4 font-medium sortable">Status</th>
                        <th class="px-6 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($prospectus as $item): ?>
                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <input type="checkbox" class="rounded border-gray-300">
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-neutral-700"><?= htmlspecialchars($item['subjcode'] ?? 'N/A') ?></td>
                        <td class="px-6 py-4 text-sm"><?= htmlspecialchars($item['subject_name'] ?? 'N/A') ?></td>
                        <td class="px-6 py-4 text-sm"><?= $item['unit'] ?? 0 ?></td>
                        <?php if ($showLevelSelect): ?>
                        <td class="px-6 py-4 text-sm">
                            <span class="px-2 py-1 bg-indigo-100 text-indigo-800 rounded-full text-xs">
                                <?= htmlspecialchars($item['level_desc'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <?php if ($showTermSelect): ?>
                        <td class="px-6 py-4 text-sm">
                            <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded-full text-xs">
                                <?= htmlspecialchars($item['term_name'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td class="px-6 py-4 text-sm">
                            <span class="px-2 py-1 <?= $item['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?> rounded-full text-xs">
                                <?= $item['status'] ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <button type="button" 
                                data-id="<?= $item['id'] ?>"
                                data-subject-id="<?= $item['subject_id'] ?>"
                                data-term-id="<?= $item['term_id'] ?>"
                                data-level-id="<?= $item['level_id'] ?>"
                                data-status="<?= htmlspecialchars($item['status']) ?>"
                                onclick="editEntryFromBtn(this)"
                                class="text-neutral-700 hover:text-black mr-3">Edit</button>
                            <button onclick="deleteEntry(<?= $item['id'] ?>, '<?= htmlspecialchars($item['subjcode'] ?? '') ?>')" 
                                class="text-red-600 hover:text-red-800">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($prospectus)): ?>
                    <tr>
                        <td colspan="<?= ($showTermSelect ? 7 : 6) + ($showLevelSelect ? 1 : 0) ?>" class="px-6 py-8 text-center text-gray-500">No subjects in this prospectus yet. Click "Add Subject" to get started.</td>
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
            <h3 class="text-lg font-semibold text-gray-800">Add Subject to Prospectus</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="add">
            <div class="space-y-4">
                <?php if ($showLevelSelect): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Level</label>
                    <select name="level_id" id="addLevelId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Level</option>
                        <?php foreach ($curriculumLevels as $lvl): ?>
                        <option value="<?= $lvl['id'] ?>"><?= htmlspecialchars($lvl['code'] . ' - ' . $lvl['description']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div id="addTermWrapper" class="<?= $showTermSelect ? '' : 'hidden' ?>">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Term</label>
                    <select name="term_id" id="addTermId" <?= $showTermSelect ? 'required' : '' ?> class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" onchange="filterSubjectsByTerm('add')">
                        <option value="">Select Term</option>
                        <?php foreach ($terms as $term): ?>
                        <option value="<?= $term['id'] ?>" data-term-name="<?= htmlspecialchars(strtolower($term['term_name'])) ?>"><?= htmlspecialchars($term['term_name'] . ' (' . ($term['sy_name'] ?? '') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                    <select name="subject_id" id="addSubjectId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value=""><?= $showTermSelect ? 'Select Term First' : 'Select Subject' ?></option>
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
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Edit Prospectus Entry</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="space-y-4">
                <?php if ($showLevelSelect): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Level</label>
                    <select name="level_id" id="editLevelId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Level</option>
                        <?php foreach ($curriculumLevels as $lvl): ?>
                        <option value="<?= $lvl['id'] ?>"><?= htmlspecialchars($lvl['code'] . ' - ' . $lvl['description']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div id="editTermWrapper" class="<?= $showTermSelect ? '' : 'hidden' ?>">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Term</label>
                    <select name="term_id" id="editTermId" <?= $showTermSelect ? 'required' : '' ?> class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" onchange="filterSubjectsByTerm('edit')">
                        <option value="">Select Term</option>
                        <?php foreach ($terms as $term): ?>
                        <option value="<?= $term['id'] ?>" data-term-name="<?= htmlspecialchars(strtolower($term['term_name'])) ?>"><?= htmlspecialchars($term['term_name'] . ' (' . ($term['sy_name'] ?? '') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                    <select name="subject_id" id="editSubjectId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value=""><?= $showTermSelect ? 'Select Term First' : 'Select Subject' ?></option>
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
            <h3 class="text-lg font-semibold text-gray-800">Remove Subject</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <p class="text-gray-600 mb-6">Are you sure you want to remove <span id="deleteName" class="font-semibold"></span> from the prospectus?</p>
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
            <h3 class="text-lg font-semibold text-gray-800">Remove Multiple Subjects</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="ids" id="bulkDeleteIds">
            <p class="text-gray-600 mb-6">Are you sure you want to remove <span id="bulkDeleteCount" class="font-semibold">0</span> subject(s) from the prospectus? This action cannot be undone.</p>
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('bulkDeleteModal')" 
                    class="flex-1 px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                <button type="submit" 
                    class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete All</button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
    setupSearch('searchInput', 'dataTable');
    
    // Subjects data with term restrictions
    const subjectsData = <?= json_encode(array_map(function($s) {
        return [
            'id' => $s['id'],
            'subjcode' => $s['subjcode'],
            'desc' => $s['desc'],
            'term_restriction' => $s['term_restriction'] ?? 'any'
        ];
    }, $subjects)) ?>;
    const showTermSelection = <?= $showTermSelect ? 'true' : 'false' ?>;

    function populateAllSubjects(mode, selectedSubjectId) {
        const subjectSelect = document.getElementById(mode + 'SubjectId');
        if (!subjectSelect) return;
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        subjectsData.forEach(function(subject) {
            const option = document.createElement('option');
            option.value = subject.id;
            option.textContent = subject.subjcode + ' - ' + subject.desc;
            if (selectedSubjectId && subject.id == selectedSubjectId) {
                option.selected = true;
            }
            subjectSelect.appendChild(option);
        });
    }
    
    function filterSubjectsByTerm(mode) {
        const termSelect = document.getElementById(mode + 'TermId');
        const subjectSelect = document.getElementById(mode + 'SubjectId');
        if (!showTermSelection || !termSelect) {
            populateAllSubjects(mode);
            return;
        }
        const selectedOption = termSelect.options[termSelect.selectedIndex];
        const termName = selectedOption ? selectedOption.getAttribute('data-term-name') : '';
        
        // Clear current options
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        
        if (!termName) {
            subjectSelect.innerHTML = '<option value="">Select Term First</option>';
            return;
        }
        
        // Determine term number (term1, term2, etc.)
        let termKey = 'any';
        if (termName.includes('semester 1') || termName.includes('term 1') || termName.includes('1st')) {
            termKey = 'term1';
        } else if (termName.includes('semester 2') || termName.includes('term 2') || termName.includes('2nd')) {
            termKey = 'term2';
        }
        
        // Filter subjects
        subjectsData.forEach(function(subject) {
            const restriction = subject.term_restriction || 'any';
            // Show if: no restriction (any) OR matches the term
            if (restriction === 'any' || restriction === termKey) {
                const option = document.createElement('option');
                option.value = subject.id;
                option.textContent = subject.subjcode + ' - ' + subject.desc;
                subjectSelect.appendChild(option);
            }
        });
    }

    function editEntryFromBtn(btn) {
        const item = {
            id: btn.dataset.id,
            subject_id: btn.dataset.subjectId,
            term_id: btn.dataset.termId,
            level_id: btn.dataset.levelId,
            status: btn.dataset.status
        };
        
        document.getElementById('editId').value = item.id;
        document.getElementById('editStatus').value = item.status;

        const editLevelEl = document.getElementById('editLevelId');
        if (editLevelEl) {
            editLevelEl.value = item.level_id || '';
        }

        if (showTermSelection && document.getElementById('editTermId')) {
            document.getElementById('editTermId').value = item.term_id || '';
            // Filter subjects by term, then set the current subject
            filterSubjectsByTermForEdit(item.term_id, item.subject_id);
        } else {
            populateAllSubjects('edit', item.subject_id);
        }
        
        openModal('editModal');
    }
    
    function filterSubjectsByTermForEdit(termId, selectedSubjectId) {
        const termSelect = document.getElementById('editTermId');
        const subjectSelect = document.getElementById('editSubjectId');
        if (!showTermSelection || !termSelect) {
            populateAllSubjects('edit', selectedSubjectId);
            return;
        }
        const selectedOption = termSelect.options[termSelect.selectedIndex];
        const termName = selectedOption ? selectedOption.getAttribute('data-term-name') : '';
        
        // Clear current options
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        
        if (!termName) {
            subjectSelect.innerHTML = '<option value="">Select Term First</option>';
            return;
        }
        
        // Determine term number (term1, term2, etc.)
        let termKey = 'any';
        if (termName.includes('semester 1') || termName.includes('1st')) {
            termKey = 'term1';
        } else if (termName.includes('semester 2') || termName.includes('2nd')) {
            termKey = 'term2';
        }
        
        // Filter subjects and add options
        let selectedFound = false;
        subjectsData.forEach(function(subject) {
            const restriction = subject.term_restriction || 'any';
            // Show if: no restriction (any) OR matches the term OR is the currently selected subject
            if (restriction === 'any' || restriction === termKey || subject.id == selectedSubjectId) {
                const option = document.createElement('option');
                option.value = subject.id;
                option.textContent = subject.subjcode + ' - ' + subject.desc;
                if (subject.id == selectedSubjectId) {
                    option.selected = true;
                    selectedFound = true;
                }
                subjectSelect.appendChild(option);
            }
        });
        
        // If the selected subject wasn't found in the filtered list, add it explicitly
        if (!selectedFound && selectedSubjectId) {
            const subject = subjectsData.find(s => s.id == selectedSubjectId);
            if (subject) {
                const option = document.createElement('option');
                option.value = subject.id;
                option.textContent = subject.subjcode + ' - ' + subject.desc;
                option.selected = true;
                subjectSelect.appendChild(option);
            }
        }
    }

    function deleteEntry(id, name) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteName').textContent = name;
        openModal('deleteModal');
    }

    // For K1-10 curricula (no term selection), populate subject lists immediately
    document.addEventListener('DOMContentLoaded', function() {
        if (!showTermSelection) {
            populateAllSubjects('add');
        }
    });

    // Also populate when Add modal opens (ensures list is always ready)
    function openAddSubjectModal() {
        if (!showTermSelection) {
            populateAllSubjects('add');
        }
        openModal('addModal');
    }
</script>
