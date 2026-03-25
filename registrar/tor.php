<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('registrar');

$pageTitle = 'Student Records';
$message = '';
$messageType = '';

// Handle education background save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_education_bg') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        
        // Personal info
        $dateOfBirth = sanitize($_POST['date_of_birth'] ?? '');
        $placeOfBirth = sanitize($_POST['place_of_birth'] ?? '');
        $sex = sanitize($_POST['sex'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        
        // Education background
        $primarySchool = sanitize($_POST['primary_school'] ?? '');
        $primarySchoolAddress = sanitize($_POST['primary_school_address'] ?? '');
        $primarySchoolYear = sanitize($_POST['primary_school_year'] ?? '');
        $intermediateSchool = sanitize($_POST['intermediate_school'] ?? '');
        $intermediateSchoolAddress = sanitize($_POST['intermediate_school_address'] ?? '');
        $intermediateSchoolYear = sanitize($_POST['intermediate_school_year'] ?? '');
        $secondarySchool = sanitize($_POST['secondary_school'] ?? '');
        $secondarySchoolAddress = sanitize($_POST['secondary_school_address'] ?? '');
        $secondarySchoolYear = sanitize($_POST['secondary_school_year'] ?? '');
        $shsSchool = sanitize($_POST['shs_school'] ?? '');
        $shsSchoolAddress = sanitize($_POST['shs_school_address'] ?? '');
        $shsSchoolYear = sanitize($_POST['shs_school_year'] ?? '');
        $shsStrand = sanitize($_POST['shs_strand'] ?? '');
        
        $stmt = db()->prepare("
            UPDATE tbl_student SET 
                date_of_birth = NULLIF(?, ''),
                place_of_birth = NULLIF(?, ''),
                sex = NULLIF(?, ''),
                address = NULLIF(?, ''),
                primary_school = NULLIF(?, ''),
                primary_school_address = NULLIF(?, ''),
                primary_school_year = NULLIF(?, ''),
                intermediate_school = NULLIF(?, ''),
                intermediate_school_address = NULLIF(?, ''),
                intermediate_school_year = NULLIF(?, ''),
                secondary_school = NULLIF(?, ''),
                secondary_school_address = NULLIF(?, ''),
                secondary_school_year = NULLIF(?, ''),
                shs_school = NULLIF(?, ''),
                shs_school_address = NULLIF(?, ''),
                shs_school_year = NULLIF(?, ''),
                shs_strand = NULLIF(?, '')
            WHERE id = ?
        ");
        
        if ($stmt->execute([
            $dateOfBirth, $placeOfBirth, $sex, $address,
            $primarySchool, $primarySchoolAddress, $primarySchoolYear,
            $intermediateSchool, $intermediateSchoolAddress, $intermediateSchoolYear,
            $secondarySchool, $secondarySchoolAddress, $secondarySchoolYear,
            $shsSchool, $shsSchoolAddress, $shsSchoolYear, $shsStrand,
            $studentId
        ])) {
            $message = 'Student education background updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update student information.';
            $messageType = 'error';
        }
    }
}

// Filters
$courseFilter = $_GET['course'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$levelFilter = $_GET['level'] ?? ''; // 'k12' or 'college' or ''
$editStudent = (int)($_GET['edit'] ?? 0);

// Get all courses/tracks grouped by department (dynamic via education_level)
$courses = db()->query("SELECT at.id, at.code, at.`desc`, d.code as dept_code, d.description as dept_name, d.education_level
    FROM tbl_academic_track at 
    JOIN tbl_departments d ON at.dept_id = d.id 
    WHERE at.status = 'active'
    ORDER BY d.education_level DESC, d.id, at.code")->fetchAll();

// Filter tracks by education level if selected
$filteredCourses = $courses;
if ($levelFilter) {
    $filteredCourses = array_filter($courses, fn($c) => $c['education_level'] === $levelFilter);
}
$filteredTrackIds = array_column($filteredCourses, 'id');

// Get sections
if ($courseFilter) {
    $sectionsStmt = db()->prepare("SELECT * FROM tbl_section WHERE academic_track_id = ? AND status = 'active' ORDER BY section_code");
    $sectionsStmt->execute([$courseFilter]);
    $sections = $sectionsStmt->fetchAll();
} elseif (!empty($filteredTrackIds)) {
    $ph = implode(',', array_fill(0, count($filteredTrackIds), '?'));
    $sections = db()->prepare("SELECT * FROM tbl_section WHERE academic_track_id IN ($ph) AND status = 'active' ORDER BY section_code");
    $sections->execute($filteredTrackIds);
    $sections = $sections->fetchAll();
} else {
    $sections = [];
}

// Get ALL students (dynamically determine document type via education_level)
$studentsSql = "
    SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name,
           d.code as dept_code, d.description as dept_name, d.education_level
    FROM tbl_student st
    JOIN tbl_section sec ON st.section_id = sec.id
    JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    JOIN tbl_departments d ON at.dept_id = d.id
    WHERE 1=1
";
$params = [];

if ($levelFilter) {
    $studentsSql .= " AND d.education_level = ?";
    $params[] = $levelFilter;
}

if ($sectionFilter) {
    $studentsSql .= " AND st.section_id = ?";
    $params[] = $sectionFilter;
} elseif ($courseFilter) {
    $studentsSql .= " AND sec.academic_track_id = ?";
    $params[] = $courseFilter;
}

if ($searchQuery) {
    $studentsSql .= " AND (st.given_name LIKE ? OR st.last_name LIKE ? OR st.student_no LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$studentsSql .= " ORDER BY d.education_level DESC, st.last_name, st.given_name";
$studentsStmt = db()->prepare($studentsSql);
$studentsStmt->execute($params);
$studentsList = $studentsStmt->fetchAll();

// If editing a student, get their full data
$editData = null;
if ($editStudent) {
    $editStmt = db()->prepare("
        SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name,
               d.code as dept_code, d.description as dept_name, d.education_level
        FROM tbl_student st
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        JOIN tbl_departments d ON at.dept_id = d.id
        WHERE st.id = ?
    ");
    $editStmt->execute([$editStudent]);
    $editData = $editStmt->fetch();
}

include '../includes/header.php';
include '../includes/sidebar_registrar.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Student Records</h1>
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

        <?php if ($editData): ?>
        <!-- Edit Education Background / Form 137 -->
        <div class="mb-6">
            <a href="/registrar/tor.php?<?= http_build_query(array_filter(['course' => $courseFilter, 'section' => $sectionFilter, 'search' => $searchQuery])) ?>" 
               class="inline-flex items-center gap-2 text-gray-600 hover:text-black transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Back to Student List
            </a>
        </div>

        <?php $isCollege = ($editData['education_level'] ?? '') === 'college'; ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Education Background</h2>
                    <p class="text-sm text-gray-500 mt-1">
                        <?= htmlspecialchars(formatPersonName($editData)) ?> 
                        (<?= htmlspecialchars($editData['student_no'] ?? 'N/A') ?>) - 
                        <?= htmlspecialchars($editData['course_code'] ?? '') ?> / <?= htmlspecialchars($editData['section_code'] ?? '') ?>
                        <span class="ml-2 px-2 py-0.5 text-xs font-medium rounded-full <?= $isCollege ? 'bg-blue-100 text-blue-800' : 'bg-emerald-100 text-emerald-800' ?>">
                            <?= $isCollege ? 'College' : 'K-12' ?>
                        </span>
                    </p>
                </div>
                <?php if ($isCollege): ?>
                <a href="/registrar/tor_pdf.php?student=<?= $editData['id'] ?>" target="_blank"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Generate TOR (PDF)
                </a>
                <?php else: ?>
                <a href="/registrar/form137_pdf.php?student=<?= $editData['id'] ?>" target="_blank"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-700 text-white rounded-lg hover:bg-emerald-800 transition text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Generate Form 137 (PDF)
                </a>
                <?php endif; ?>
            </div>

            <form method="POST" class="space-y-8">
                <input type="hidden" name="action" value="save_education_bg">
                <input type="hidden" name="student_id" value="<?= $editData['id'] ?>">

                <!-- Personal Information -->
                <div>
                    <h3 class="text-md font-semibold text-gray-700 mb-4 pb-2 border-b border-gray-200">Personal Information</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                            <input type="date" name="date_of_birth" value="<?= htmlspecialchars($editData['date_of_birth'] ?? '') ?>"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Place of Birth</label>
                            <input type="text" name="place_of_birth" value="<?= htmlspecialchars($editData['place_of_birth'] ?? '') ?>"
                                placeholder="e.g. Manila, Philippines"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Sex</label>
                            <select name="sex" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                                <option value="">-- Select --</option>
                                <option value="Male" <?= ($editData['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($editData['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <input type="text" name="address" value="<?= htmlspecialchars($editData['address'] ?? '') ?>"
                                placeholder="Complete address"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                    </div>
                </div>

                <!-- Primary Course (Elementary - Grades 1-3) -->
                <div>
                    <h3 class="text-md font-semibold text-gray-700 mb-4 pb-2 border-b border-gray-200">
                        <span class="inline-flex items-center gap-2">
                            <span class="w-6 h-6 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                            Primary Course (Elementary - Grades 1-3)
                        </span>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">School Name</label>
                            <input type="text" name="primary_school" value="<?= htmlspecialchars($editData['primary_school'] ?? '') ?>"
                                placeholder="e.g. ABC Elementary School"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">School Address</label>
                            <input type="text" name="primary_school_address" value="<?= htmlspecialchars($editData['primary_school_address'] ?? '') ?>"
                                placeholder="e.g. Brgy. San Jose, Manila"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Year Completed</label>
                            <input type="text" name="primary_school_year" value="<?= htmlspecialchars($editData['primary_school_year'] ?? '') ?>"
                                placeholder="e.g. 2012-2015"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                    </div>
                </div>

                <!-- Intermediate Course (Elementary - Grades 4-6) -->
                <div>
                    <h3 class="text-md font-semibold text-gray-700 mb-4 pb-2 border-b border-gray-200">
                        <span class="inline-flex items-center gap-2">
                            <span class="w-6 h-6 bg-green-100 text-green-700 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                            Intermediate Course (Elementary - Grades 4-6)
                        </span>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">School Name</label>
                            <input type="text" name="intermediate_school" value="<?= htmlspecialchars($editData['intermediate_school'] ?? '') ?>"
                                placeholder="e.g. ABC Elementary School"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">School Address</label>
                            <input type="text" name="intermediate_school_address" value="<?= htmlspecialchars($editData['intermediate_school_address'] ?? '') ?>"
                                placeholder="e.g. Brgy. San Jose, Manila"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Year Completed</label>
                            <input type="text" name="intermediate_school_year" value="<?= htmlspecialchars($editData['intermediate_school_year'] ?? '') ?>"
                                placeholder="e.g. 2015-2018"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                    </div>
                </div>

                <!-- High School Course (Junior High School - Grades 7-10) -->
                <div>
                    <h3 class="text-md font-semibold text-gray-700 mb-4 pb-2 border-b border-gray-200">
                        <span class="inline-flex items-center gap-2">
                            <span class="w-6 h-6 bg-yellow-100 text-yellow-700 rounded-full flex items-center justify-center text-xs font-bold">3</span>
                            High School Course (Junior High School - Grades 7-10)
                        </span>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">School Name</label>
                            <input type="text" name="secondary_school" value="<?= htmlspecialchars($editData['secondary_school'] ?? '') ?>"
                                placeholder="e.g. XYZ National High School"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">School Address</label>
                            <input type="text" name="secondary_school_address" value="<?= htmlspecialchars($editData['secondary_school_address'] ?? '') ?>"
                                placeholder="e.g. Brgy. Poblacion, Quezon City"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Year Completed</label>
                            <input type="text" name="secondary_school_year" value="<?= htmlspecialchars($editData['secondary_school_year'] ?? '') ?>"
                                placeholder="e.g. 2018-2022"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                    </div>
                </div>

                <!-- Senior High School Course -->
                <div>
                    <h3 class="text-md font-semibold text-gray-700 mb-4 pb-2 border-b border-gray-200">
                        <span class="inline-flex items-center gap-2">
                            <span class="w-6 h-6 bg-purple-100 text-purple-700 rounded-full flex items-center justify-center text-xs font-bold">4</span>
                            Senior High School Course (Grades 11-12)
                        </span>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">School Name</label>
                            <input type="text" name="shs_school" value="<?= htmlspecialchars($editData['shs_school'] ?? '') ?>"
                                placeholder="e.g. XYZ Senior High School"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">School Address</label>
                            <input type="text" name="shs_school_address" value="<?= htmlspecialchars($editData['shs_school_address'] ?? '') ?>"
                                placeholder="e.g. Brgy. Poblacion, Quezon City"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Year Completed</label>
                            <input type="text" name="shs_school_year" value="<?= htmlspecialchars($editData['shs_school_year'] ?? '') ?>"
                                placeholder="e.g. 2022-2024"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Track / Strand</label>
                            <input type="text" name="shs_strand" value="<?= htmlspecialchars($editData['shs_strand'] ?? '') ?>"
                                placeholder="e.g. STEM, ABM, HUMSS"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black text-sm">
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4 pt-4 border-t border-gray-200">
                    <button type="submit" class="px-6 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition text-sm">
                        Save Education Background
                    </button>
                    <?php if ($isCollege): ?>
                    <a href="/registrar/tor_pdf.php?student=<?= $editData['id'] ?>" target="_blank"
                       class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition text-sm">
                        Preview TOR PDF
                    </a>
                    <?php else: ?>
                    <a href="/registrar/form137_pdf.php?student=<?= $editData['id'] ?>" target="_blank"
                       class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition text-sm">
                        Preview Form 137 PDF
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div class="min-w-[150px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Education Level</label>
                    <select name="level" onchange="this.form.submit()" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">All Levels</option>
                        <option value="college" <?= $levelFilter === 'college' ? 'selected' : '' ?>>College (TOR)</option>
                        <option value="k12" <?= $levelFilter === 'k12' ? 'selected' : '' ?>>K-12 (Form 137)</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Course / Level</label>
                    <select name="course" onchange="this.form.submit()" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">All Courses</option>
                        <?php 
                        $lastDept = '';
                        foreach ($filteredCourses as $c): 
                            if ($c['dept_name'] !== $lastDept):
                                if ($lastDept !== '') echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($c['dept_name']) . '">';
                                $lastDept = $c['dept_name'];
                            endif;
                        ?>
                        <option value="<?= $c['id'] ?>" <?= $courseFilter == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['code']) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($lastDept !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" onchange="this.form.submit()" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionFilter == $sec['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sec['section_code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <div class="relative">
                        <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" 
                            placeholder="Name or Student No."
                            class="w-full px-3 py-2 pl-9 border border-gray-200 rounded-lg text-sm">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                </div>
                <button type="submit" class="px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition text-sm">
                    Filter
                </button>
                <?php if ($courseFilter || $sectionFilter || $searchQuery || $levelFilter): ?>
                <a href="/registrar/tor.php" class="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 transition text-sm">
                    Clear
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Student List -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-800">Students (<?= count($studentsList) ?>)</h2>
                <p class="text-sm text-gray-500 mt-1">College students generate TOR, K-12 students generate Form 137. Select a student to manage education background.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full" id="studentsTable">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase">
                        <tr>
                            <th class="px-6 py-3">Student No.</th>
                            <th class="px-6 py-3">Name</th>
                            <th class="px-6 py-3">Course / Level</th>
                            <th class="px-6 py-3">Section</th>
                            <th class="px-6 py-3">Type</th>
                            <th class="px-6 py-3">Ed. Background</th>
                            <th class="px-6 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($studentsList)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                No students found. Use the filters above to search for students.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($studentsList as $st): 
                            $hasForm137 = !empty($st['primary_school']) || !empty($st['secondary_school']) || !empty($st['shs_school']);
                            $isComplete = !empty($st['primary_school']) && !empty($st['intermediate_school']) && !empty($st['secondary_school']) && !empty($st['shs_school']);
                            $stIsCollege = ($st['education_level'] ?? '') === 'college';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-800"><?= htmlspecialchars($st['student_no'] ?? 'N/A') ?></td>
                            <td class="px-6 py-4 text-sm text-gray-800"><?= htmlspecialchars(formatPersonName($st)) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= htmlspecialchars($st['course_code']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= htmlspecialchars($st['section_code']) ?></td>
                            <td class="px-6 py-4">
                                <?php if ($stIsCollege): ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">College</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-emerald-100 text-emerald-800">K-12</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($isComplete): ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">Complete</span>
                                <?php elseif ($hasForm137): ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">Partial</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-600">Not Set</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="/registrar/tor.php?edit=<?= $st['id'] ?>&<?= http_build_query(array_filter(['course' => $courseFilter, 'section' => $sectionFilter, 'search' => $searchQuery, 'level' => $levelFilter])) ?>" 
                                       class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition"
                                       title="Edit Education Background">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                        Edit
                                    </a>
                                    <?php if ($stIsCollege): ?>
                                    <a href="/registrar/tor_pdf.php?student=<?= $st['id'] ?>" target="_blank"
                                       class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium bg-black text-white rounded-lg hover:bg-neutral-800 transition"
                                       title="Generate Transcript of Records">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        TOR
                                    </a>
                                    <?php else: ?>
                                    <a href="/registrar/form137_pdf.php?student=<?= $st['id'] ?>" target="_blank"
                                       class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium bg-emerald-700 text-white rounded-lg hover:bg-emerald-800 transition"
                                       title="Generate Form 137">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        Form 137
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
