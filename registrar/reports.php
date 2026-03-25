<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('registrar');

$pageTitle = 'Grade Reports';

// Get filters
$syFilter = $_GET['sy'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$studentFilter = $_GET['student'] ?? '';

// Fetch school years
$schoolYears = db()->query("SELECT * FROM tbl_sy ORDER BY id DESC")->fetchAll();

// Fetch all active sections
$sections = db()->query("SELECT s.*, at.code as track_code, at.`desc` as track_name FROM tbl_section s JOIN tbl_academic_track at ON s.academic_track_id = at.id WHERE s.status = 'active' ORDER BY s.section_code")->fetchAll();

// Get students based on section filter
$students = [];
if ($sectionFilter) {
    $studentsStmt = db()->prepare("SELECT * FROM tbl_student WHERE section_id = ? ORDER BY last_name, given_name");
    $studentsStmt->execute([$sectionFilter]);
    $students = $studentsStmt->fetchAll();
} elseif ($syFilter) {
    // All students enrolled in this SY
    $studentsStmt = db()->prepare("
        SELECT DISTINCT s.* FROM tbl_student s
        JOIN tbl_enroll e ON e.student_id = s.id
        WHERE e.sy_id = ? AND e.status = 'enrolled'
        ORDER BY s.last_name, s.given_name
    ");
    $studentsStmt->execute([$syFilter]);
    $students = $studentsStmt->fetchAll();
}

// Resolve SY to term IDs
$termIds = [];
$syName = '';
if ($syFilter) {
    $syStmt = db()->prepare("SELECT sy_name FROM tbl_sy WHERE id = ?");
    $syStmt->execute([$syFilter]);
    $syRow = $syStmt->fetch();
    $syName = $syRow['sy_name'] ?? '';

    $termStmt = db()->prepare("SELECT id FROM tbl_term WHERE sy_id = ? AND status = 'active'");
    $termStmt->execute([$syFilter]);
    $termIds = $termStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch grades
$grades = [];
$studentInfo = null;
$gradesByStudent = [];
$summary = ['total_students' => 0, 'passed' => 0, 'failed' => 0, 'average' => 0];

if (!empty($termIds) && ($sectionFilter || $studentFilter || $syFilter)) {
    $termPlaceholders = implode(',', array_fill(0, count($termIds), '?'));

    if ($studentFilter) {
        // Individual student
        $studentStmt = db()->prepare("
            SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name, d.code as dept_code
            FROM tbl_student st
            JOIN tbl_section sec ON st.section_id = sec.id
            JOIN tbl_academic_track at ON sec.academic_track_id = at.id
            LEFT JOIN tbl_departments d ON at.dept_id = d.id
            WHERE st.id = ?
        ");
        $studentStmt->execute([$studentFilter]);
        $studentInfo = $studentStmt->fetch();

        $sql = "
            SELECT g.period_grade, g.grading_period, g.status as grade_status,
                   sub.subjcode, sub.`desc` as subject_name, sub.unit,
                   t.term_name, teach.name as teacher_name
            FROM tbl_enroll e
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            LEFT JOIN tbl_grades g ON g.enroll_id = e.id AND g.term_id IN ($termPlaceholders)
            LEFT JOIN tbl_term t ON g.term_id = t.id
            LEFT JOIN tbl_teacher teach ON g.teacher_id = teach.id
            WHERE e.student_id = ? AND e.sy_id = ? AND e.status != 'dropped'
            ORDER BY sub.subjcode, t.id
        ";
        $params = array_merge($termIds, [$studentFilter, $syFilter]);

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $grades = $stmt->fetchAll();

        // Count enrolled subjects
        $enrolledCountStmt = db()->prepare("
            SELECT COUNT(DISTINCT subject_id) FROM tbl_enroll
            WHERE student_id = ? AND sy_id = ? AND status != 'dropped'
        ");
        $enrolledCountStmt->execute([$studentFilter, $syFilter]);
        $totalEnrolled = (int)$enrolledCountStmt->fetchColumn();

    } else {
        // Section or all-section report
        $sectionWhere = $sectionFilter ? "AND sec.id = ?" : "";
        $sectionParams = $sectionFilter ? [$sectionFilter] : [];

        $sql = "
            SELECT g.period_grade, g.grading_period,
                   CONCAT_WS(' ', st.given_name, st.middle_name, st.last_name) as student_name, st.id as student_id,
                   sub.subjcode, sub.`desc` as subject_name, sub.unit,
                   t.term_name, sec.section_code
            FROM tbl_enroll e
            JOIN tbl_student st ON e.student_id = st.id
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            JOIN tbl_section sec ON st.section_id = sec.id
            LEFT JOIN tbl_grades g ON g.enroll_id = e.id AND g.term_id IN ($termPlaceholders)
            LEFT JOIN tbl_term t ON g.term_id = t.id
            WHERE e.sy_id = ? AND e.status != 'dropped' $sectionWhere
            ORDER BY st.last_name, st.given_name, sub.subjcode
        ";
        $params = array_merge($termIds, [$syFilter], $sectionParams);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $grades = $stmt->fetchAll();

        // Enrolled counts per student
        $enrolledSql = "
            SELECT e.student_id, COUNT(DISTINCT e.subject_id) as enrolled_count
            FROM tbl_enroll e
            JOIN tbl_student st ON e.student_id = st.id
            WHERE e.sy_id = ? AND e.status != 'dropped'
            " . ($sectionFilter ? "AND st.section_id = ?" : "") . "
            GROUP BY e.student_id
        ";
        $enrolledParams = [$syFilter];
        if ($sectionFilter) $enrolledParams[] = $sectionFilter;
        $enrolledPerStudentStmt = db()->prepare($enrolledSql);
        $enrolledPerStudentStmt->execute($enrolledParams);
        $enrolledCounts = $enrolledPerStudentStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Summary
        if ($grades) {
            $summaryStudents = [];
            foreach ($grades as $g) {
                $summaryStudents[$g['student_id']] = true;
            }
            $summary['total_students'] = count($summaryStudents);
            $validGrades = array_filter(array_column($grades, 'period_grade'), fn($v) => $v !== null);
            $summary['average'] = count($validGrades) > 0 ? round(array_sum($validGrades) / count($validGrades), 2) : 0;
        }
    }
}

// Determine college context
$isReportCollege = false;
if ($studentInfo) {
    $isReportCollege = in_array(strtoupper($studentInfo['dept_code'] ?? ''), ['CCTE', 'CON']);
} elseif ($sectionFilter) {
    $secInfoStmt = db()->prepare("SELECT d.code as dept_code FROM tbl_section s JOIN tbl_academic_track at ON s.academic_track_id = at.id LEFT JOIN tbl_departments d ON at.dept_id = d.id WHERE s.id = ?");
    $secInfoStmt->execute([$sectionFilter]);
    $secInfo = $secInfoStmt->fetch();
    $isReportCollege = in_array(strtoupper($secInfo['dept_code'] ?? ''), ['CCTE', 'CON']);
}

// Build individual student: group by subject (deduplicate periods)
$gradesBySubject = [];
if ($studentFilter && $grades) {
    foreach ($grades as $g) {
        $subj = $g['subjcode'];
        if (!isset($gradesBySubject[$subj])) {
            $gradesBySubject[$subj] = [
                'subjcode' => $subj,
                'subject_name' => $g['subject_name'],
                'unit' => $g['unit'],
                'teacher_name' => $g['teacher_name'] ?? 'N/A',
                'period_grades' => [],
                'has_grade' => false,
            ];
        }
        if ($g['period_grade'] !== null) {
            $gradesBySubject[$subj]['period_grades'][] = $g['period_grade'];
            $gradesBySubject[$subj]['has_grade'] = true;
        }
        if (!empty($g['teacher_name'])) {
            $gradesBySubject[$subj]['teacher_name'] = $g['teacher_name'];
        }
    }
    foreach ($gradesBySubject as &$subj) {
        $subj['final_grade'] = count($subj['period_grades']) > 0
            ? round(array_sum($subj['period_grades']) / count($subj['period_grades']), $isReportCollege ? 2 : 0)
            : null;
    }
    unset($subj);
}

// Build section summary grouping (dedup by subject per student)
if (!$studentFilter && $grades) {
    foreach ($grades as $grade) {
        $sid = $grade['student_id'];
        if (!isset($gradesByStudent[$sid])) {
            $gradesByStudent[$sid] = [
                'name' => $grade['student_name'],
                'section' => $grade['section_code'],
                'subjects' => [],
                'total_units' => 0,
                'weighted_sum' => 0,
            ];
        }
        $subjCode = $grade['subjcode'];
        if (!isset($gradesByStudent[$sid]['subjects'][$subjCode])) {
            $gradesByStudent[$sid]['subjects'][$subjCode] = [
                'name' => $grade['subject_name'],
                'unit' => $grade['unit'],
                'period_grades' => [],
            ];
        }
        if ($grade['period_grade'] !== null) {
            $gradesByStudent[$sid]['subjects'][$subjCode]['period_grades'][] = $grade['period_grade'];
        }
    }

    foreach ($gradesByStudent as $sid => &$student) {
        $subjectFinals = [];
        $totalUnits = 0;
        $weightedSum = 0;
        $gradedCount = 0;
        foreach ($student['subjects'] as &$subj) {
            $subj['final_grade'] = count($subj['period_grades']) > 0
                ? round(array_sum($subj['period_grades']) / count($subj['period_grades']), $isReportCollege ? 2 : 0)
                : null;
            if ($subj['final_grade'] !== null) {
                $subjectFinals[] = $subj['final_grade'];
                $totalUnits += $subj['unit'];
                $weightedSum += ($subj['final_grade'] * $subj['unit']);
                $gradedCount++;
            }
        }
        unset($subj);
        $student['subject_count'] = count($student['subjects']);
        $student['total_units'] = $totalUnits;
        $student['all_complete'] = ($gradedCount >= $student['subject_count'] && $student['subject_count'] > 0);

        if ($isReportCollege) {
            $student['gwa'] = ($student['all_complete'] && $totalUnits > 0)
                ? round($weightedSum / $totalUnits, 2) : 0;
        } else {
            $student['gwa'] = ($student['all_complete'] && count($subjectFinals) > 0)
                ? round(array_sum($subjectFinals) / count($subjectFinals), 0) : 0;
        }
    }
    unset($student);

    // Count passed/failed
    $summary['passed'] = 0;
    foreach ($gradesByStudent as $st) {
        if (!empty($st['all_complete'])) {
            $allPassed = true;
            foreach ($st['subjects'] as $subj) {
                $isFailing = $isReportCollege ? ($subj['final_grade'] > 3.00) : ($subj['final_grade'] < 75);
                if ($subj['final_grade'] === null || $isFailing) { $allPassed = false; break; }
            }
            if ($allPassed) $summary['passed']++;
        }
    }
    $summary['failed'] = $summary['total_students'] - $summary['passed'];
}

include '../includes/header.php';
include '../includes/sidebar_registrar.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4 no-print">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Grade Reports</h1>
            <div class="flex items-center gap-2 text-gray-500 text-sm">
                <svg class="w-5 h-5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span><?= getCurrentDate() ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6 no-print">
            <h3 class="text-lg font-medium text-gray-800 mb-4">Generate Report</h3>
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                    <select name="sy" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="">Select School Year</option>
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>" <?= $syFilter == $sy['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sy['sy_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionFilter == $sec['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sec['section_code'] . ' (' . $sec['track_code'] . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Student (Optional)</label>
                    <select name="student" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">All Students</option>
                        <?php foreach ($students as $st): ?>
                        <option value="<?= $st['id'] ?>" <?= $studentFilter == $st['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(formatPersonName($st)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                        Generate
                    </button>
                    <?php if ($grades || $gradesByStudent): ?>
                    <a href="report_pdf.php?sy=<?= $syFilter ?>&section=<?= $sectionFilter ?>&student=<?= $studentFilter ?>"
                       class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        PDF
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($studentFilter && $studentInfo && $gradesBySubject): ?>
        <!-- Individual Student Report -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print-area">
            <div class="px-6 py-6 border-b border-gray-100 print-header">
                <div class="text-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">GRADE REPORT</h2>
                    <p class="text-gray-500">S.Y. <?= htmlspecialchars($syName) ?></p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <p><span class="font-medium">Student Name:</span> <?= htmlspecialchars(formatPersonName($studentInfo)) ?></p>
                        <p><span class="font-medium">Section:</span> <?= htmlspecialchars($studentInfo['section_code']) ?></p>
                    </div>
                    <div>
                        <p><span class="font-medium"><?= $isReportCollege ? 'Course' : 'Level' ?>:</span> <?= htmlspecialchars($studentInfo['course_code'] . ' - ' . $studentInfo['course_name']) ?></p>
                        <p><span class="font-medium">Date Generated:</span> <?= date('F d, Y') ?></p>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium">Subject</th>
                            <?php if ($isReportCollege): ?>
                            <th class="px-6 py-4 font-medium text-center">Units</th>
                            <?php endif; ?>
                            <th class="px-6 py-4 font-medium text-center">Final Grade</th>
                            <th class="px-6 py-4 font-medium text-center">Remarks</th>
                            <th class="px-6 py-4 font-medium no-print">Teacher</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totalUnits = 0;
                        $weightedSum = 0;
                        $gradeSum = 0;
                        $gradeCount = 0;
                        foreach ($gradesBySubject as $subj):
                            $fg = $subj['final_grade'];
                            $hasGrade = $subj['has_grade'];
                            $totalUnits += $subj['unit'];
                            if ($hasGrade) {
                                $weightedSum += ($fg * $subj['unit']);
                                $gradeSum += $fg;
                                $gradeCount++;
                            }
                            $passed = $hasGrade && ($isReportCollege ? ($fg <= 3.00) : ($fg >= 75));
                        ?>
                        <tr class="border-t border-gray-100">
                            <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($subj['subject_name']) ?></td>
                            <?php if ($isReportCollege): ?>
                            <td class="px-6 py-4 text-sm text-center"><?= $subj['unit'] ?></td>
                            <?php endif; ?>
                            <?php if ($hasGrade): ?>
                            <td class="px-6 py-4 text-sm text-center font-bold <?= $passed ? 'text-green-600' : 'text-red-600' ?>">
                                <?= $isReportCollege ? number_format($fg, 2) : number_format($fg, 0) ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-center">
                                <span class="px-2 py-1 rounded-full text-xs <?= $passed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $passed ? 'PASSED' : 'FAILED' ?>
                                </span>
                            </td>
                            <?php else: ?>
                            <td class="px-6 py-4 text-sm text-center text-gray-400">N/A</td>
                            <td class="px-6 py-4 text-sm text-center">
                                <span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-500">No Grade</span>
                            </td>
                            <?php endif; ?>
                            <td class="px-6 py-4 text-sm text-gray-500 no-print"><?= htmlspecialchars($subj['teacher_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr class="border-t-2 border-gray-200">
                            <?php
                            $allSubjectsGraded = ($gradeCount >= $totalEnrolled && $totalEnrolled > 0);
                            ?>
                            <?php if ($isReportCollege): ?>
                            <td class="px-6 py-4 text-sm font-bold">General Weighted Average (GWA)</td>
                            <td class="px-6 py-4 text-sm text-center font-bold"><?= $totalUnits ?></td>
                            <td class="px-6 py-4 text-sm text-center font-bold text-lg">
                                <?= ($allSubjectsGraded && $totalUnits > 0) ? number_format($weightedSum / $totalUnits, 2) : '<span class="text-gray-400">—</span>' ?>
                            </td>
                            <td colspan="2" class="px-6 py-4">
                                <?php if (!$allSubjectsGraded): ?><span class="text-gray-400 text-xs">Incomplete</span><?php endif; ?>
                            </td>
                            <?php else: ?>
                            <td class="px-6 py-4 text-sm font-bold">General Average</td>
                            <td class="px-6 py-4 text-sm text-center font-bold text-lg">
                                <?= ($allSubjectsGraded && $gradeCount > 0) ? number_format($gradeSum / $gradeCount, 0) : '<span class="text-gray-400">—</span>' ?>
                            </td>
                            <td colspan="2" class="px-6 py-4">
                                <?php if (!$allSubjectsGraded): ?><span class="text-gray-400 text-xs">Incomplete</span><?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Signature Section -->
            <div class="px-4 sm:px-6 py-8 border-t border-gray-100 print-footer">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 sm:gap-8 mt-8 sm:mt-12 max-w-lg mx-auto">
                    <div class="text-center">
                        <div class="border-t border-black pt-2">
                            <p class="text-sm font-medium">Registrar</p>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="border-t border-black pt-2">
                            <p class="text-sm font-medium">Principal</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($syFilter && $gradesByStudent): ?>
        <!-- Section/All Summary Report -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 sm:gap-6 mb-6 no-print">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Total Students</p>
                        <p class="text-2xl font-bold text-gray-800"><?= $summary['total_students'] ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Passed</p>
                        <p class="text-2xl font-bold text-green-600"><?= $summary['passed'] ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Failed</p>
                        <p class="text-2xl font-bold text-red-600"><?= $summary['failed'] ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Class Average</p>
                        <p class="text-2xl font-bold text-purple-600"><?= $summary['average'] ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Grade List -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print-area">
            <div class="px-6 py-4 border-b border-gray-100 print-header">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Grade Summary</h3>
                        <p class="text-sm text-gray-500">
                            <?= $sectionFilter ? 'Section: ' . htmlspecialchars($sections[array_search($sectionFilter, array_column($sections, 'id'))]['section_code'] ?? 'All') . ' | ' : '' ?>
                            S.Y. <?= htmlspecialchars($syName) ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium">#</th>
                            <th class="px-6 py-4 font-medium">Student Name</th>
                            <th class="px-6 py-4 font-medium text-center">Section</th>
                            <th class="px-6 py-4 font-medium text-center">Subjects</th>
                            <?php if ($isReportCollege): ?>
                            <th class="px-6 py-4 font-medium text-center">Total Units</th>
                            <th class="px-6 py-4 font-medium text-center">GWA</th>
                            <?php else: ?>
                            <th class="px-6 py-4 font-medium text-center">Gen. Avg</th>
                            <?php endif; ?>
                            <th class="px-6 py-4 font-medium text-center">Remarks</th>
                            <th class="px-6 py-4 font-medium text-center no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 1;
                        uasort($gradesByStudent, function($a, $b) {
                            return $b['gwa'] <=> $a['gwa'];
                        });
                        foreach ($gradesByStudent as $studentId => $student):
                            $allPassed = true;
                            foreach ($student['subjects'] as $subj) {
                                $isFailing = $isReportCollege ? ($subj['final_grade'] > 3.00) : ($subj['final_grade'] < 75);
                                if ($subj['final_grade'] === null || $isFailing) { $allPassed = false; break; }
                            }
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm"><?= $rank++ ?></td>
                            <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($student['name']) ?></td>
                            <td class="px-6 py-4 text-sm text-center"><?= htmlspecialchars($student['section']) ?></td>
                            <td class="px-6 py-4 text-sm text-center"><?= $student['subject_count'] ?></td>
                            <?php if ($isReportCollege): ?>
                            <td class="px-6 py-4 text-sm text-center"><?= $student['total_units'] ?></td>
                            <?php endif; ?>
                            <td class="px-6 py-4 text-sm text-center font-bold <?= ($student['gwa'] > 0 && ($isReportCollege ? ($student['gwa'] <= 3.00) : ($student['gwa'] >= 75))) ? 'text-green-600' : ($student['gwa'] > 0 ? 'text-red-600' : 'text-gray-400') ?>">
                                <?= empty($student['all_complete']) ? 'N/A' : ($isReportCollege ? number_format($student['gwa'], 2) : number_format($student['gwa'], 0)) ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-center">
                                <?php if (empty($student['all_complete'])): ?>
                                <span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-600">Incomplete</span>
                                <?php else: ?>
                                <span class="px-2 py-1 rounded-full text-xs <?= $allPassed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $allPassed ? 'PASSED' : 'FAILED' ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-center no-print">
                                <a href="?sy=<?= $syFilter ?>&section=<?= $sectionFilter ?>&student=<?= $studentId ?>"
                                   class="text-blue-600 hover:text-blue-800 mr-2">View</a>
                                <a href="report_pdf.php?sy=<?= $syFilter ?>&student=<?= $studentId ?>"
                                   class="text-red-600 hover:text-red-800">PDF</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($syFilter): ?>
        <!-- No Data -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No Grades Found</h3>
            <p class="text-gray-500">No grade records found for the selected filters. Please select different options.</p>
        </div>

        <?php else: ?>
        <!-- Initial State -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">Generate Grade Report</h3>
            <p class="text-gray-500">Select a school year to generate a grade report. You can filter by section and student.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
