<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

// CSV template download for class list
if (isset($_GET['action']) && $_GET['action'] === 'class_list_csv_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="class_list_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['student_no', 'section', 'subject', 'teacher']);
    fputcsv($out, ['25-00015', 'GRADE1-A', 'FIL', 'T. Sarmiento']);
    fputcsv($out, ['25-00015', 'GRADE1-A', 'ENG', 'T. Sarmiento']);
    fputcsv($out, ['25-00015', 'GRADE1-A', 'MATH', 'T. Detorres']);
    fputcsv($out, ['25-00017', 'GRADE1-A', 'FIL', 'T. Sarmiento']);
    fclose($out);
    exit;
}

$pageTitle = 'Class List';
$currentPage = 'class_list';
$message = '';
$messageType = '';

$activeSy = getActiveSchoolYear();
$syId = (int)($_GET['sy_id'] ?? ($activeSy['id'] ?? 0));

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_class_csv') {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $file = $_FILES['csv_file']['tmp_name'];
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
                    $header = array_map(function($h) { return strtolower(trim($h)); }, $header);
                    $studentNoIdx = array_search('student_no', $header);
                    $sectionIdx = array_search('section', $header);
                    $subjectIdx = array_search('subject', $header);
                    $teacherIdx = array_search('teacher', $header);

                    if ($studentNoIdx === false || $sectionIdx === false || $subjectIdx === false || $teacherIdx === false) {
                        $message = 'CSV must have columns: student_no, section, subject, teacher.';
                        $messageType = 'error';
                    } else {
                        $imported = 0;
                        $skipped = 0;
                        $errors = [];
                        $row = 1;
                        $uploadSyId = (int)($_POST['sy_id'] ?? $syId);
                        $termId = !empty($_POST['term_id']) ? (int)$_POST['term_id'] : null;

                        while (($data = fgetcsv($handle)) !== false) {
                            $row++;
                            $studentNo = trim($data[$studentNoIdx] ?? '');
                            $sectionCode = trim($data[$sectionIdx] ?? '');
                            $subjectCode = trim($data[$subjectIdx] ?? '');
                            $teacherName = trim($data[$teacherIdx] ?? '');

                            if (empty($studentNo) || empty($sectionCode) || empty($subjectCode) || empty($teacherName)) {
                                $skipped++;
                                continue;
                            }

                            $stmt = db()->prepare("SELECT id FROM tbl_student WHERE student_no = ?");
                            $stmt->execute([$studentNo]);
                            $student = $stmt->fetch();
                            if (!$student) { $errors[] = "Row $row: Student '$studentNo' not found"; continue; }

                            $stmt = db()->prepare("SELECT id FROM tbl_section WHERE section_code = ? AND status = 'active'");
                            $stmt->execute([$sectionCode]);
                            $section = $stmt->fetch();
                            if (!$section) { $errors[] = "Row $row: Section '$sectionCode' not found"; continue; }

                            $stmt = db()->prepare("SELECT id FROM tbl_subjects WHERE subjcode = ? AND status = 'active'");
                            $stmt->execute([$subjectCode]);
                            $subject = $stmt->fetch();
                            if (!$subject) { $errors[] = "Row $row: Subject '$subjectCode' not found"; continue; }

                            $stmt = db()->prepare("SELECT id FROM tbl_teacher WHERE name = ?");
                            $stmt->execute([$teacherName]);
                            $teacher = $stmt->fetch();
                            if (!$teacher) { $errors[] = "Row $row: Teacher '$teacherName' not found"; continue; }

                            $checkStmt = db()->prepare("SELECT id FROM tbl_enroll WHERE student_id = ? AND subject_id = ? AND sy_id = ? AND (term_id = ? OR (term_id IS NULL AND ? IS NULL))");
                            $checkStmt->execute([$student['id'], $subject['id'], $uploadSyId, $termId, $termId]);
                            if ($checkStmt->fetch()) { $skipped++; continue; }

                            try {
                                $tsCheck = db()->prepare("SELECT id FROM tbl_teacher_subject WHERE teacher_id = ? AND section_id = ? AND subject_id = ? AND sy_id = ? AND status = 'active'");
                                $tsCheck->execute([$teacher['id'], $section['id'], $subject['id'], $uploadSyId]);
                                if (!$tsCheck->fetch()) {
                                    $tsInsert = db()->prepare("INSERT INTO tbl_teacher_subject (teacher_id, section_id, subject_id, sy_id, status) VALUES (?, ?, ?, ?, 'active')");
                                    $tsInsert->execute([$teacher['id'], $section['id'], $subject['id'], $uploadSyId]);
                                }
                                $enrollStmt = db()->prepare("INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status, enrolled_at) VALUES (?, ?, ?, ?, ?, ?, 'enrolled', NOW())");
                                $enrollStmt->execute([$student['id'], $subject['id'], $section['id'], $teacher['id'], $uploadSyId, $termId]);
                                $imported++;
                            } catch (Exception $e) {
                                $errors[] = "Row $row: " . $e->getMessage();
                            }
                        }

                        fclose($handle);
                        $message = "Import complete: $imported enrollment(s) created";
                        if ($skipped > 0) $message .= ", $skipped row(s) skipped (empty or duplicate)";
                        if (!empty($errors)) $message .= ". Issues: " . implode('; ', array_slice($errors, 0, 5));
                        $messageType = empty($errors) && $imported > 0 ? 'success' : ($imported > 0 ? 'success' : 'error');
                    }
                }
            }
        } else {
            $message = 'Please select a CSV file.';
            $messageType = 'error';
        }
    }
}

// Filters
$sectionFilter = $_GET['section'] ?? '';
$studentFilter = (int)($_GET['student'] ?? 0);

// Get filter options
$schoolYears = db()->query("SELECT * FROM tbl_sy ORDER BY sy_name DESC")->fetchAll();
$sections = db()->query("SELECT s.*, lv.code as level_code FROM tbl_section s LEFT JOIN level lv ON s.level_id = lv.id WHERE s.status = 'active' ORDER BY s.section_code")->fetchAll();
$terms = db()->prepare("SELECT * FROM tbl_term WHERE sy_id = ? AND status = 'active' ORDER BY id");
$terms->execute([$syId]);
$termsList = $terms->fetchAll();

// Get students list for the filters
$studentWhere = ["e.sy_id = ?", "e.status = 'enrolled'"];
$studentParams = [$syId];
if ($sectionFilter) {
    $studentWhere[] = "sec.id = ?";
    $studentParams[] = (int)$sectionFilter;
}
$studentWhereClause = implode(' AND ', $studentWhere);

$studentsList = db()->prepare("
    SELECT DISTINCT s.id, s.student_no, s.given_name, s.middle_name, s.last_name, s.suffix,
           sec.section_code, sec.id as section_id,
           at.`desc` as course_name,
           lv.code as level_code
    FROM tbl_enroll e
    JOIN tbl_student s ON e.student_id = s.id
    JOIN tbl_section sec ON e.section_id = sec.id
    LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    LEFT JOIN level lv ON sec.level_id = lv.id
    WHERE $studentWhereClause
    ORDER BY s.last_name, s.given_name
");
$studentsList->execute($studentParams);
$students = $studentsList->fetchAll();

// If a student is selected, get their class list
$selectedStudent = null;
$classListRows = [];
if ($studentFilter) {
    $stmtStudent = db()->prepare("
        SELECT s.*, sec.section_code, at.`desc` as course_name, lv.code as level_code
        FROM tbl_student s
        LEFT JOIN tbl_section sec ON s.section_id = sec.id
        LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        LEFT JOIN level lv ON sec.level_id = lv.id
        WHERE s.id = ?
    ");
    $stmtStudent->execute([$studentFilter]);
    $selectedStudent = $stmtStudent->fetch();

    if ($selectedStudent) {
        $stmtClasses = db()->prepare("
            SELECT sub.subjcode, sub.`desc` as subject_desc, sub.unit,
                   sub.lec_u, sub.lab_u,
                   sec.section_code, t.name as teacher_name
            FROM tbl_enroll e
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            JOIN tbl_section sec ON e.section_id = sec.id
            LEFT JOIN tbl_teacher t ON e.teacher_id = t.id
            WHERE e.student_id = ? AND e.sy_id = ? AND e.status = 'enrolled'
            ORDER BY sub.subjcode
        ");
        $stmtClasses->execute([$studentFilter, $syId]);
        $classListRows = $stmtClasses->fetchAll();
    }
}

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Class List</h1>
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
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                    <select name="sy_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>" <?= $sy['id'] == $syId ? 'selected' : '' ?>><?= htmlspecialchars($sy['sy_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sec['id'] == $sectionFilter ? 'selected' : '' ?>><?= htmlspecialchars($sec['section_code'] . ($sec['level_code'] ? ' (' . $sec['level_code'] . ')' : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Student</label>
                    <select name="student" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        <option value="">-- Select Student --</option>
                        <?php foreach ($students as $st): ?>
                        <option value="<?= $st['id'] ?>" <?= $st['id'] == $studentFilter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($st['student_no'] . ' - ' . $st['last_name'] . ', ' . $st['given_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition text-sm">
                        View
                    </button>
                    <button type="button" onclick="openModal('uploadCsvModal')"
                        class="px-4 py-2 bg-white text-black border border-gray-200 rounded-lg hover:bg-gray-50 transition text-sm">
                        Upload CSV
                    </button>
                </div>
            </form>
        </div>

        <?php if ($selectedStudent && !empty($classListRows)): ?>
        <!-- Per-Student Class List -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
            <!-- Student Info Header -->
            <div class="p-6 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-12 gap-y-2 text-sm">
                        <div><span class="font-semibold text-gray-600">Student # :</span> <span class="font-bold text-gray-900"><?= htmlspecialchars($selectedStudent['student_no']) ?></span></div>
                        <div><span class="font-semibold text-gray-600">Name :</span> <span class="font-bold text-gray-900"><?= htmlspecialchars(strtoupper($selectedStudent['last_name'] . ', ' . $selectedStudent['given_name'] . ' ' . ($selectedStudent['middle_name'] ? substr($selectedStudent['middle_name'], 0, 1) . '.' : '') . ($selectedStudent['suffix'] ? ' ' . $selectedStudent['suffix'] : ''))) ?></span></div>
                        <div><span class="font-semibold text-gray-600">Course :</span> <span class="font-bold text-gray-900"><?= htmlspecialchars($selectedStudent['course_name'] ?? 'N/A') ?></span></div>
                        <div><span class="font-semibold text-gray-600">Section :</span> <span class="font-bold text-gray-900"><?= htmlspecialchars($selectedStudent['section_code'] ?? 'N/A') ?></span></div>
                        <div><span class="font-semibold text-gray-600">Level :</span> <span class="font-bold text-gray-900"><?= htmlspecialchars($selectedStudent['level_code'] ?? 'N/A') ?></span></div>
                    </div>
                    <a href="class_list_pdf.php?sy_id=<?= $syId ?>&student=<?= $studentFilter ?>" target="_blank"
                        class="flex items-center gap-2 bg-black text-white px-4 py-2 rounded-lg hover:bg-neutral-800 transition text-sm whitespace-nowrap">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        Print PDF
                    </a>
                </div>
            </div>

            <!-- Subjects Table -->
            <div class="overflow-x-auto">
                <table class="w-full min-w-[600px]">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-4 py-3 font-semibold text-center w-12">No.</th>
                            <th class="px-4 py-3 font-semibold">Code</th>
                            <th class="px-4 py-3 font-semibold">Description</th>
                            <th class="px-4 py-3 font-semibold">Section</th>
                            <th class="px-4 py-3 font-semibold">Teacher</th>
                            <th class="px-4 py-3 font-semibold text-center">Lec Units</th>
                            <th class="px-4 py-3 font-semibold text-center">Lab Units</th>
                            <th class="px-4 py-3 font-semibold text-center">Total Units</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totalLec = 0;
                        $totalLab = 0;
                        $totalUnits = 0;
                        foreach ($classListRows as $i => $r):
                            $lec = $r['lec_u'] ?? $r['unit'] ?? 0;
                            $lab = $r['lab_u'] ?? 0;
                            $units = $r['unit'] ?? ($lec + $lab);
                            $totalLec += $lec;
                            $totalLab += $lab;
                            $totalUnits += $units;
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-2.5 text-sm text-center"><?= $i + 1 ?></td>
                            <td class="px-4 py-2.5 text-sm font-medium"><?= htmlspecialchars($r['subjcode']) ?></td>
                            <td class="px-4 py-2.5 text-sm"><?= htmlspecialchars($r['subject_desc']) ?></td>
                            <td class="px-4 py-2.5 text-sm"><?= htmlspecialchars($r['section_code']) ?></td>
                            <td class="px-4 py-2.5 text-sm"><?= htmlspecialchars($r['teacher_name'] ?? 'TBA') ?></td>
                            <td class="px-4 py-2.5 text-sm text-center"><?= $lec ?: '-' ?></td>
                            <td class="px-4 py-2.5 text-sm text-center"><?= $lab ?: '-' ?></td>
                            <td class="px-4 py-2.5 text-sm text-center font-medium"><?= $units ?: '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 bg-gray-50 font-bold text-sm">
                            <td colspan="5" class="px-4 py-3 text-right">Total</td>
                            <td class="px-4 py-3 text-center"><?= $totalLec ?: '-' ?></td>
                            <td class="px-4 py-3 text-center"><?= $totalLab ?: '-' ?></td>
                            <td class="px-4 py-3 text-center"><?= $totalUnits ?: '-' ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <?php elseif ($studentFilter && empty($classListRows)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500 mb-6">
            No enrolled subjects found for this student.
        </div>

        <?php else: ?>
        <!-- Student List -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
            <div class="flex items-center gap-2">
                <input type="text" id="searchInput" placeholder="Search students..." 
                    class="pl-4 pr-10 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent w-full sm:w-64 text-sm">
                <span class="text-sm text-gray-500"><?= count($students) ?> student(s)</span>
            </div>
            <?php if (!empty($students)): ?>
            <a href="class_list_pdf.php?sy_id=<?= $syId ?>&section=<?= $sectionFilter ?>" target="_blank"
                class="flex items-center gap-2 bg-black text-white px-4 py-2 rounded-lg hover:bg-neutral-800 transition text-sm w-fit">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                </svg>
                Print All PDF
            </a>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[600px]" id="dataTable">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium">#</th>
                            <th class="px-6 py-4 font-medium">Student No</th>
                            <th class="px-6 py-4 font-medium">Student Name</th>
                            <th class="px-6 py-4 font-medium">Section</th>
                            <th class="px-6 py-4 font-medium">Level</th>
                            <th class="px-6 py-4 font-medium">Course</th>
                            <th class="px-6 py-4 font-medium text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $i => $st): ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-500"><?= $i + 1 ?></td>
                            <td class="px-6 py-3 text-sm font-medium text-gray-800"><?= htmlspecialchars($st['student_no']) ?></td>
                            <td class="px-6 py-3 text-sm"><?= htmlspecialchars($st['last_name'] . ', ' . $st['given_name'] . ' ' . ($st['middle_name'] ? substr($st['middle_name'], 0, 1) . '.' : '')) ?></td>
                            <td class="px-6 py-3 text-sm"><?= htmlspecialchars($st['section_code']) ?></td>
                            <td class="px-6 py-3 text-sm"><?php if (!empty($st['level_code'])): ?><span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700"><?= htmlspecialchars($st['level_code']) ?></span><?php else: ?>—<?php endif; ?></td>
                            <td class="px-6 py-3 text-sm"><?= htmlspecialchars($st['course_name'] ?? 'N/A') ?></td>
                            <td class="px-6 py-3 text-sm text-center">
                                <a href="?sy_id=<?= $syId ?>&section=<?= $sectionFilter ?>&student=<?= $st['id'] ?>"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-black text-white rounded-lg hover:bg-neutral-800 transition text-xs">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">No enrolled students found. Try adjusting the filters.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Upload CSV Modal -->
<div id="uploadCsvModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Upload Class List via CSV</h3>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-6">
            <input type="hidden" name="action" value="upload_class_csv">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                    <select name="sy_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>" <?= $sy['id'] == $syId ? 'selected' : '' ?>><?= htmlspecialchars($sy['sy_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Term (optional)</label>
                    <select name="term_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        <option value="">None (Yearly)</option>
                        <?php foreach ($termsList as $term): ?>
                        <option value="<?= $term['id'] ?>"><?= htmlspecialchars($term['term_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">CSV File</label>
                    <input type="file" name="csv_file" accept=".csv" required
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                    <p class="text-xs text-gray-500 mt-1">Required columns: <strong>student_no</strong>, <strong>section</strong>, <strong>subject</strong>, <strong>teacher</strong>.</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-medium text-gray-700">CSV Format Example:</p>
                        <a href="/admin/class_list.php?action=class_list_csv_template" class="inline-flex items-center gap-1 px-3 py-1 text-xs bg-white border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-100 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download Template
                        </a>
                    </div>
                    <code class="text-xs text-gray-600 block">student_no,section,subject,teacher<br>25-00015,GRADE1-A,FIl,T. Sarmiento<br>25-00015,GRADE1-A,ENG,T. Sarmiento<br>25-00015,GRADE1-A,MATH,T. Detorres</code>
                    <p class="text-xs text-gray-500 mt-2">Values must match existing records: student number, section code, subject code, and teacher name.</p>
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
</script>

<?php include '../includes/footer.php'; ?>
