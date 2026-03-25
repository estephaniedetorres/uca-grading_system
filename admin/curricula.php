<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Curricula';
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $curriculum = sanitize($_POST['curriculum']);
        $academic_track_id = (int)$_POST['academic_track_id'];
        $status = sanitize($_POST['status']);
        
        $stmt = db()->prepare("INSERT INTO tbl_curriculum (curriculum, academic_track_id, status) VALUES (?, ?, ?)");
        if ($stmt->execute([$curriculum, $academic_track_id, $status])) {
            $message = 'Curriculum added successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to add curriculum.';
            $messageType = 'error';
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $curriculum = sanitize($_POST['curriculum']);
        $academic_track_id = (int)$_POST['academic_track_id'];
        $status = sanitize($_POST['status']);
        
        $stmt = db()->prepare("UPDATE tbl_curriculum SET curriculum = ?, academic_track_id = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$curriculum, $academic_track_id, $status, $id])) {
            $message = 'Curriculum updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update curriculum.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare("DELETE FROM tbl_curriculum WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = 'Curriculum deleted successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to delete curriculum.';
            $messageType = 'error';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? '';
        $idArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArray)) {
            $placeholders = implode(',', array_fill(0, count($idArray), '?'));
            $stmt = db()->prepare("DELETE FROM tbl_curriculum WHERE id IN ($placeholders)");
            if ($stmt->execute($idArray)) {
                $message = count($idArray) . ' curriculum(s) deleted successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to delete curricula.';
                $messageType = 'error';
            }
        }
    }
}

// Fetch all curricula with course info
$curricula = db()->query("SELECT cu.*, at.code as course_code, at.`desc` as course_name 
    FROM tbl_curriculum cu 
    LEFT JOIN tbl_academic_track at ON cu.academic_track_id = at.id 
    ORDER BY cu.id ASC")->fetchAll();

// Fetch all courses for dropdown
$courses = db()->query("SELECT * FROM tbl_academic_track WHERE status = 'active' ORDER BY code")->fetchAll();

// School years for filter
$schoolYears = db()->query("SELECT * FROM tbl_sy ORDER BY sy_name DESC")->fetchAll();
$activeSy = getActiveSchoolYear();

// Selected filters
$selectedCurrId = (int)($_GET['curriculum_id'] ?? 0);
$selectedSyId = (int)($_GET['sy_id'] ?? ($activeSy['id'] ?? 0));

// Load prospectus data when curriculum is selected
$prospectusData = [];
$curriculumLevels = [];
$curriculumInfo = null;
$terms = [];

if ($selectedCurrId) {
    // Get selected curriculum info
    $stmt = db()->prepare("
        SELECT cu.*, at.code as course_code, at.`desc` as course_name, d.code as dept_code, at.enrollment_type
        FROM tbl_curriculum cu 
        LEFT JOIN tbl_academic_track at ON cu.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        WHERE cu.id = ?
    ");
    $stmt->execute([$selectedCurrId]);
    $curriculumInfo = $stmt->fetch();

    if ($curriculumInfo) {
        $deptCode = strtoupper($curriculumInfo['dept_code'] ?? '');
        $enrollmentType = $curriculumInfo['enrollment_type'] ?? 'semestral';
        $isShs = $deptCode === 'SHS';
        $isPrimaryK12 = in_array($deptCode, ['PRE-EL', 'ELE', 'JHS'], true) || $enrollmentType === 'yearly';
        $showTerms = !$isPrimaryK12;

        // Get levels for this curriculum's track
        $levelStmt = db()->prepare("SELECT * FROM level WHERE academic_track_id = ? ORDER BY `order`");
        $levelStmt->execute([$curriculumInfo['academic_track_id'] ?? 0]);
        $curriculumLevels = $levelStmt->fetchAll();

        // Get terms for the selected school year
        if ($showTerms) {
            if ($isShs) {
                $termStmt = db()->prepare("SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.status = 'active' AND t.sy_id = ? AND t.term_name LIKE 'Semester%' ORDER BY t.id");
            } else {
                $termStmt = db()->prepare("SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.status = 'active' AND t.sy_id = ? AND (t.term_name LIKE 'Semester%' OR t.term_name = 'Summer') ORDER BY t.id");
            }
            $termStmt->execute([$selectedSyId]);
            $terms = $termStmt->fetchAll();
        }

        // Fetch prospectus entries grouped by level and term
        $prospStmt = db()->prepare("
            SELECT p.*, s.subjcode, s.`desc` as subject_name, s.unit, s.lec_u, s.lab_u, s.type,
                   t.term_name, l.code as level_code, l.description as level_desc
            FROM tbl_prospectus p 
            LEFT JOIN tbl_subjects s ON p.subject_id = s.id 
            LEFT JOIN tbl_term t ON p.term_id = t.id 
            LEFT JOIN level l ON p.level_id = l.id
            WHERE p.curriculum_id = ? 
            ORDER BY l.`order`, t.id, s.subjcode
        ");
        $prospStmt->execute([$selectedCurrId]);
        $allEntries = $prospStmt->fetchAll();

        // Group by level_id then term_id
        foreach ($allEntries as $entry) {
            $lvlKey = $entry['level_id'] ?: 0;
            $termKey = $entry['term_id'] ?: 0;
            $prospectusData[$lvlKey][$termKey][] = $entry;
        }
    }
}

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Curricula</h1>
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

        <!-- Curriculum & School Year Selector -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Curriculum</label>
                    <select name="curriculum_id" required class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        <option value="">-- Select Curriculum --</option>
                        <?php foreach ($curricula as $curr): ?>
                        <option value="<?= $curr['id'] ?>" <?= $curr['id'] == $selectedCurrId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($curr['curriculum']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                    <select name="sy_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>" <?= $sy['id'] == $selectedSyId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sy['sy_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="w-full px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition text-sm">
                        Load Prospectus
                    </button>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="openModal('addModal')" class="flex-1 px-4 py-2 bg-white text-black border border-gray-200 rounded-lg hover:bg-gray-50 transition text-sm">
                        + Add Curriculum
                    </button>
                    <?php if ($selectedCurrId && $curriculumInfo): ?>
                    <button type="button" onclick="editCurriculum(<?= htmlspecialchars(json_encode($curriculumInfo)) ?>)" class="px-3 py-2 text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 transition text-sm" title="Edit Curriculum">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($selectedCurrId && $curriculumInfo): ?>
        <!-- Curriculum Info Banner -->
        <div class="bg-black rounded-xl p-4 text-white mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div>
                    <h2 class="text-lg font-bold"><?= htmlspecialchars($curriculumInfo['curriculum']) ?></h2>
                    <p class="text-gray-300 text-sm"><?= htmlspecialchars($curriculumInfo['course_code'] . ' - ' . $curriculumInfo['course_name']) ?></p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-300"><?= count($allEntries ?? []) ?> subject(s) total</span>
                    <a href="prospectus.php?curriculum_id=<?= $selectedCurrId ?>" class="px-4 py-2 bg-white text-black rounded-lg hover:bg-gray-100 transition text-sm">
                        Manage Prospectus
                    </a>
                </div>
            </div>
        </div>

        <!-- Prospectus Per Level -->
        <?php if (empty($prospectusData)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500">
            No prospectus entries found for this curriculum. 
            <a href="prospectus.php?curriculum_id=<?= $selectedCurrId ?>" class="text-black underline hover:no-underline">Add subjects to the prospectus</a>.
        </div>
        <?php else: ?>

        <?php
        // Build level lookup
        $levelLookup = [];
        foreach ($curriculumLevels as $lv) {
            $levelLookup[$lv['id']] = $lv;
        }
        ?>

        <?php foreach ($prospectusData as $lvlId => $termGroups): ?>
        <?php 
            $lvlInfo = $levelLookup[$lvlId] ?? null;
            $lvlLabel = $lvlInfo ? htmlspecialchars($lvlInfo['code'] . ' - ' . $lvlInfo['description']) : 'General';
        ?>
        <div class="mb-6">
            <div class="flex items-center gap-3 mb-3 cursor-pointer group" onclick="toggleLevel(this)">
                <svg class="w-5 h-5 text-gray-400 transition-transform level-chevron rotate-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
                <h3 class="text-lg font-semibold text-gray-800 group-hover:text-black"><?= $lvlLabel ?></h3>
                <?php 
                    $lvlTotal = 0; $lvlUnits = 0;
                    foreach ($termGroups as $entries) { 
                        $lvlTotal += count($entries);
                        foreach ($entries as $e) { $lvlUnits += ($e['unit'] ?? 0); }
                    } 
                ?>
                <span class="text-xs text-gray-500"><?= $lvlTotal ?> subject(s) &bull; <?= $lvlUnits ?> units</span>
            </div>
            <div class="level-content">
                <?php foreach ($termGroups as $termId => $entries): ?>
                <?php 
                    $termLabel = $entries[0]['term_name'] ?? 'No Term';
                    $termUnits = 0;
                    foreach ($entries as $e) { $termUnits += ($e['unit'] ?? 0); }
                ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-4">
                    <div class="px-6 py-3 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-purple-100 text-purple-700"><?= htmlspecialchars($termLabel) ?></span>
                            <span class="text-xs text-gray-500"><?= count($entries) ?> subject(s) &bull; <?= $termUnits ?> units</span>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                                    <th class="px-6 py-2 font-medium w-10">#</th>
                                    <th class="px-6 py-2 font-medium">Code</th>
                                    <th class="px-6 py-2 font-medium">Description</th>
                                    <th class="px-6 py-2 font-medium text-center">Lec</th>
                                    <th class="px-6 py-2 font-medium text-center">Lab</th>
                                    <th class="px-6 py-2 font-medium text-center">Units</th>
                                    <th class="px-6 py-2 font-medium">Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $i => $entry): ?>
                                <tr class="border-t border-gray-50 hover:bg-gray-50">
                                    <td class="px-6 py-2 text-sm text-gray-400"><?= $i + 1 ?></td>
                                    <td class="px-6 py-2 text-sm font-medium text-gray-800"><?= htmlspecialchars($entry['subjcode'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-2 text-sm text-gray-600"><?= htmlspecialchars($entry['subject_name'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-2 text-sm text-center"><?= $entry['lec_u'] ?? '-' ?></td>
                                    <td class="px-6 py-2 text-sm text-center"><?= $entry['lab_u'] ?? '-' ?></td>
                                    <td class="px-6 py-2 text-sm text-center font-medium"><?= $entry['unit'] ?? '-' ?></td>
                                    <td class="px-6 py-2 text-sm">
                                        <span class="px-2 py-0.5 text-xs rounded-full <?= ($entry['type'] ?? '') === 'Major' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' ?>">
                                            <?= htmlspecialchars($entry['type'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

        <?php elseif (!$selectedCurrId): ?>
        <!-- No curriculum selected yet -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
            <h3 class="text-lg font-medium text-gray-700 mb-2">Select a Curriculum</h3>
            <p class="text-gray-500 text-sm">Choose a curriculum and school year above to view the prospectus per level.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Add Modal -->
<div id="addModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Add Curriculum</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="add">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Curriculum Name</label>
                    <input type="text" name="curriculum" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Academic Track</label>
                    <select name="academic_track_id" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Academic Track</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['code'] . ' - ' . $course['desc']) ?></option>
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
            <h3 class="text-lg font-semibold text-gray-800">Edit Curriculum</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Curriculum Name</label>
                    <input type="text" name="curriculum" id="editCurriculum" required 
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Academic Track</label>
                    <select name="academic_track_id" id="editAcademicTrackId" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Academic Track</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['code'] . ' - ' . $course['desc']) ?></option>
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
            <h3 class="text-lg font-semibold text-gray-800">Delete Curriculum</h3>
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

<script>
    function editCurriculum(curr) {
        document.getElementById('editId').value = curr.id;
        document.getElementById('editCurriculum').value = curr.curriculum;
        document.getElementById('editAcademicTrackId').value = curr.academic_track_id;
        document.getElementById('editStatus').value = curr.status;
        openModal('editModal');
    }

    function deleteCurriculum(id, name) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteName').textContent = name;
        openModal('deleteModal');
    }

    function toggleLevel(el) {
        const content = el.nextElementSibling;
        const chevron = el.querySelector('.level-chevron');
        if (content.style.display === 'none') {
            content.style.display = '';
            chevron.classList.remove('-rotate-90');
            chevron.classList.add('rotate-0');
        } else {
            content.style.display = 'none';
            chevron.classList.remove('rotate-0');
            chevron.classList.add('-rotate-90');
        }
    }
</script>

<?php include '../includes/footer.php'; ?>
