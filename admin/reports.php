<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Grade Reports';

// Get filters
$termFilter = $_GET['term'] ?? '';
$sectionFilter = $_GET['section'] ?? '';
$courseFilter = $_GET['course'] ?? '';
$studentFilter = $_GET['student'] ?? '';

// Fetch filter options
$terms = db()->query("SELECT t.*, sy.sy_name FROM tbl_term t LEFT JOIN tbl_sy sy ON t.sy_id = sy.id WHERE t.status = 'active' ORDER BY t.id")->fetchAll();
$courses = db()->query("SELECT * FROM tbl_academic_track WHERE status = 'active' ORDER BY code")->fetchAll();

// Build "All Semesters" option groups from terms (group by SY)
$termsBySy = [];
foreach ($terms as $t) {
    $syId = $t['sy_id'];
    $syName = $t['sy_name'] ?? '';
    if (!isset($termsBySy[$syId])) {
        $termsBySy[$syId] = ['sy_name' => $syName, 'semesters' => []];
    }
    if (stripos($t['term_name'], 'Semester') !== false) {
        $termsBySy[$syId]['semesters'][] = $t;
    }
}

// Resolve term filter to array of term IDs
$termIds = [];
$isMultiTerm = false;
$termDisplayName = '';

if (is_string($termFilter) && str_starts_with($termFilter, 'all_q_')) {
    // "All Quarters" = both semesters (K-12 grades span Q1-Q4 across both semesters)
    $syId = (int) substr($termFilter, 6);
    if (isset($termsBySy[$syId]) && count($termsBySy[$syId]['semesters']) > 0) {
        $termIds = array_column($termsBySy[$syId]['semesters'], 'id');
        $isMultiTerm = true;
        $termDisplayName = 'All Quarters Q1-Q4 (' . $termsBySy[$syId]['sy_name'] . ')';
    }
} elseif (is_string($termFilter) && str_starts_with($termFilter, 'all_s_')) {
    // "All Semesters" = both semesters (SHS grades span Q1-Q2 per semester)
    $syId = (int) substr($termFilter, 6);
    if (isset($termsBySy[$syId]) && count($termsBySy[$syId]['semesters']) > 0) {
        $termIds = array_column($termsBySy[$syId]['semesters'], 'id');
        $isMultiTerm = true;
        $termDisplayName = 'All Semesters Q1-Q2 (' . $termsBySy[$syId]['sy_name'] . ')';
    }
} elseif ($termFilter) {
    $termIds = [(int)$termFilter];
}

// Get sections based on course filter
if ($courseFilter) {
    $sectionsStmt = db()->prepare("SELECT * FROM tbl_section WHERE academic_track_id = ? AND status = 'active' ORDER BY section_code");
    $sectionsStmt->execute([$courseFilter]);
    $sections = $sectionsStmt->fetchAll();
} else {
    $sections = db()->query("SELECT * FROM tbl_section WHERE status = 'active' ORDER BY section_code")->fetchAll();
}

// Get students based on section filter
if ($sectionFilter) {
    $studentsStmt = db()->prepare("SELECT * FROM tbl_student WHERE section_id = ? ORDER BY last_name, given_name");
    $studentsStmt->execute([$sectionFilter]);
    $students = $studentsStmt->fetchAll();
} else {
    $students = [];
}

// Fetch grades based on filters
$grades = [];
$studentInfo = null;
$summary = ['total_students' => 0, 'passed' => 0, 'failed' => 0, 'average' => 0];

if (!empty($termIds) && ($sectionFilter || $studentFilter)) {
    $termPlaceholders = implode(',', array_fill(0, count($termIds), '?'));
    
    if ($studentFilter) {
        // Individual student report
        $studentStmt = db()->prepare("
            SELECT st.*, sec.section_code, at.code as course_code, at.`desc` as course_name,
                   d.code as dept_code
            FROM tbl_student st
            JOIN tbl_section sec ON st.section_id = sec.id
            JOIN tbl_academic_track at ON sec.academic_track_id = at.id
            LEFT JOIN tbl_departments d ON at.dept_id = d.id
            WHERE st.id = ?
        ");
        $studentStmt->execute([$studentFilter]);
        $studentInfo = $studentStmt->fetch();
        
        $sql = "
            SELECT g.*, sub.subjcode, sub.`desc` as subject_name, sub.unit,
                   t.term_name, teach.name as teacher_name
            FROM tbl_grades g
            JOIN tbl_enroll e ON g.enroll_id = e.id
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            JOIN tbl_term t ON g.term_id = t.id
            LEFT JOIN tbl_teacher teach ON g.teacher_id = teach.id
            WHERE g.term_id IN ($termPlaceholders) AND e.student_id = ?
            ORDER BY sub.subjcode, t.id
        ";
        $params = array_merge($termIds, [$studentFilter]);
    } else {
        // Section report
        $sql = "
            SELECT g.*, CONCAT_WS(' ', st.given_name, st.middle_name, st.last_name) as student_name, st.id as student_id,
                   sub.subjcode, sub.`desc` as subject_name, sub.unit,
                   t.term_name, sec.section_code
            FROM tbl_grades g
            JOIN tbl_enroll e ON g.enroll_id = e.id
            JOIN tbl_student st ON e.student_id = st.id
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            JOIN tbl_term t ON g.term_id = t.id
            JOIN tbl_section sec ON st.section_id = sec.id
            WHERE g.term_id IN ($termPlaceholders) AND sec.id = ?
            ORDER BY st.last_name, st.given_name, sub.subjcode
        ";
        $params = array_merge($termIds, [$sectionFilter]);
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $grades = $stmt->fetchAll();
    
    // Determine if this is a college context (needed before summary)
    $isReportCollege = false;
    if ($studentInfo) {
        $isReportCollege = in_array(strtoupper($studentInfo['dept_code'] ?? ''), ['CCTE', 'CON']);
    } elseif ($sectionFilter) {
        $secInfoStmt = db()->prepare("SELECT d.code as dept_code FROM tbl_section s JOIN tbl_academic_track at ON s.academic_track_id = at.id LEFT JOIN tbl_departments d ON at.dept_id = d.id WHERE s.id = ?");
        $secInfoStmt->execute([$sectionFilter]);
        $secInfo = $secInfoStmt->fetch();
        $isReportCollege = in_array(strtoupper($secInfo['dept_code'] ?? ''), ['CCTE', 'CON']);
    }
    
    // Calculate summary
    if (!$studentFilter && $sectionFilter) {
        $summaryStmt = db()->prepare("
            SELECT 
                COUNT(DISTINCT st.id) as total_students,
                AVG(g.period_grade) as average_grade
            FROM tbl_grades g
            JOIN tbl_enroll e ON g.enroll_id = e.id
            JOIN tbl_student st ON e.student_id = st.id
            JOIN tbl_section sec ON st.section_id = sec.id
            WHERE g.term_id IN ($termPlaceholders) AND sec.id = ?
        ");
        $summaryStmt->execute(array_merge($termIds, [$sectionFilter]));
        $summaryData = $summaryStmt->fetch();
        
        $summary['total_students'] = $summaryData['total_students'] ?? 0;
        $summary['average'] = round($summaryData['average_grade'] ?? 0, 2);
        
        // Count passed/failed (college: <= 3.00 is passing; K-12: >= 75 is passing)
        $failCondition = $isReportCollege ? 'g2.period_grade > 3.00' : 'g2.period_grade < 75';
        $passedStmt = db()->prepare("
            SELECT COUNT(DISTINCT st.id) as count
            FROM tbl_student st
            JOIN tbl_section sec ON st.section_id = sec.id
            WHERE sec.id = ? AND NOT EXISTS (
                SELECT 1 FROM tbl_grades g2
                JOIN tbl_enroll e2 ON g2.enroll_id = e2.id
                WHERE e2.student_id = st.id AND g2.term_id IN ($termPlaceholders) AND $failCondition
            ) AND EXISTS (
                SELECT 1 FROM tbl_grades g3
                JOIN tbl_enroll e3 ON g3.enroll_id = e3.id
                WHERE e3.student_id = st.id AND g3.term_id IN ($termPlaceholders)
            )
        ");
        $passedStmt->execute(array_merge([$sectionFilter], $termIds, $termIds));
        $summary['passed'] = $passedStmt->fetchColumn() ?? 0;
        $summary['failed'] = $summary['total_students'] - $summary['passed'];
    }
}

// Group grades by student for section view
$gradesByStudent = [];
// For multi-term individual student: pivot by subject
$gradesBySubject = [];

// $isReportCollege already determined above (before summary)

// Build individual student pivot (DepEd format: subjects as rows, quarters as columns)
if ($studentFilter && $grades && $isMultiTerm && !$isReportCollege) {
    foreach ($grades as $g) {
        $subj = $g['subjcode'];
        if (!isset($gradesBySubject[$subj])) {
            $gradesBySubject[$subj] = [
                'subjcode' => $subj,
                'subject_name' => $g['subject_name'],
                'unit' => $g['unit'],
                'quarters' => [],
            ];
        }
        $qKey = $g['grading_period'] ?? $g['term_name'];
        $gradesBySubject[$subj]['quarters'][$qKey] = $g['period_grade'];
    }
    // Determine expected quarter count
    $expectedQuarters = str_starts_with($termFilter, 'all_q_') ? 4 : 2;
    $allSubjectsComplete = true;
    // Calculate final grade per subject
    foreach ($gradesBySubject as &$subj) {
        $qg = array_values($subj['quarters']);
        $subj['final_grade'] = count($qg) > 0 ? round(array_sum($qg) / count($qg), 0) : 0;
        if (count($subj['quarters']) < $expectedQuarters) {
            $allSubjectsComplete = false;
        }
    }
    unset($subj);
}

// Build section summary grouping
if (!$studentFilter && $grades) {
    if ($isMultiTerm && !$isReportCollege) {
        // K-12/SHS All Quarters: group by student then by subject
        foreach ($grades as $grade) {
            $sid = $grade['student_id'];
            if (!isset($gradesByStudent[$sid])) {
                $gradesByStudent[$sid] = [
                    'name' => $grade['student_name'],
                    'section' => $grade['section_code'],
                    'subjects' => [],
                    'grades' => [],
                    'total_units' => 0,
                    'weighted_sum' => 0,
                ];
            }
            $subjCode = $grade['subjcode'];
            if (!isset($gradesByStudent[$sid]['subjects'][$subjCode])) {
                $gradesByStudent[$sid]['subjects'][$subjCode] = [
                    'name' => $grade['subject_name'],
                    'quarters' => [],
                ];
            }
            $gradesByStudent[$sid]['subjects'][$subjCode]['quarters'][] = $grade['period_grade'];
            $gradesByStudent[$sid]['grades'][] = $grade;
        }
        // Calculate final grade per subject, then gen avg (only if all complete)
        $expectedQ = str_starts_with($termFilter, 'all_q_') ? 4 : 2;
        foreach ($gradesByStudent as &$student) {
            $subjectFinals = [];
            $studentAllComplete = true;
            foreach ($student['subjects'] as &$subj) {
                $subj['final_grade'] = count($subj['quarters']) > 0
                    ? round(array_sum($subj['quarters']) / count($subj['quarters']), 0)
                    : 0;
                if (count($subj['quarters']) >= $expectedQ) {
                    $subjectFinals[] = $subj['final_grade'];
                } else {
                    $studentAllComplete = false;
                }
            }
            unset($subj);
            $student['subject_count'] = count($student['subjects']);
            $student['all_complete'] = $studentAllComplete;
            $student['gwa'] = ($studentAllComplete && count($subjectFinals) > 0)
                ? round(array_sum($subjectFinals) / count($subjectFinals), 2)
                : 0;
        }
        unset($student);
    } else {
        // Single term or college: original grouping
        foreach ($grades as $grade) {
            $sid = $grade['student_id'];
            if (!isset($gradesByStudent[$sid])) {
                $gradesByStudent[$sid] = [
                    'name' => $grade['student_name'],
                    'section' => $grade['section_code'],
                    'grades' => [],
                    'total_units' => 0,
                    'weighted_sum' => 0
                ];
            }
            $gradesByStudent[$sid]['grades'][] = $grade;
            $gradesByStudent[$sid]['total_units'] += $grade['unit'];
            $gradesByStudent[$sid]['weighted_sum'] += ($grade['period_grade'] * $grade['unit']);
        }
        // Calculate GWA for each student
        foreach ($gradesByStudent as &$student) {
            if ($isReportCollege) {
                $student['gwa'] = $student['total_units'] > 0
                    ? round($student['weighted_sum'] / $student['total_units'], 2)
                    : 0;
            } else {
                // Non-college single term: simple average
                $gradeCount = count($student['grades']);
                $gradeSum = array_sum(array_column($student['grades'], 'period_grade'));
                $student['gwa'] = $gradeCount > 0 ? round($gradeSum / $gradeCount, 0) : 0;
            }
            $student['subject_count'] = count($student['grades']);
        }
        unset($student);
    }
}

include '../includes/header.php';
include '../includes/sidebar_admin.php';
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
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Term</label>
                    <select name="term" required class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black">
                        <option value="">Select Term</option>
                        <?php foreach ($terms as $term): ?>
                        <option value="<?= $term['id'] ?>" <?= $termFilter == $term['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($term['term_name'] . ' (' . ($term['sy_name'] ?? '') . ')') ?>
                        </option>
                        <?php endforeach; ?>
                        <?php
                        $hasAllOptions = false;
                        foreach ($termsBySy as $syId => $syData) {
                            if (count($syData['semesters']) >= 2) {
                                $hasAllOptions = true;
                                break;
                            }
                        }
                        if ($hasAllOptions): ?>
                        <optgroup label="── Combined Views ──">
                            <?php foreach ($termsBySy as $syId => $syData): ?>
                                <?php if (count($syData['semesters']) >= 2): ?>
                                <option value="all_q_<?= $syId ?>" <?= $termFilter === "all_q_$syId" ? 'selected' : '' ?>>
                                    All Quarters Q1-Q4 (<?= htmlspecialchars($syData['sy_name']) ?>)
                                </option>
                                <option value="all_s_<?= $syId ?>" <?= $termFilter === "all_s_$syId" ? 'selected' : '' ?>>
                                    All Semesters Q1-Q2 (<?= htmlspecialchars($syData['sy_name']) ?>)
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Course/Program</label>
                    <select name="course" id="courseSelect" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>" <?= $courseFilter == $course['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['code'] . ' - ' . $course['desc']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" id="sectionSelect" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black" onchange="this.form.submit()">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionFilter == $sec['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sec['section_code']) ?>
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
                    <?php if ($grades): ?>
                    <a href="report_pdf.php?term=<?= $termFilter ?>&section=<?= $sectionFilter ?>&student=<?= $studentFilter ?>" 
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

        <?php if ($studentFilter && $studentInfo && $grades): ?>
        <!-- Individual Student Report -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print-area">
            <!-- Report Header -->
            <div class="px-6 py-6 border-b border-gray-100 print-header">
                <div class="text-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">GRADE REPORT</h2>
                    <p class="text-gray-500"><?= htmlspecialchars($isMultiTerm ? $termDisplayName : ($terms[array_search($termFilter, array_column($terms, 'id'))]['term_name'] ?? '')) ?></p>
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
            
            <!-- Grades Table -->
            <div class="overflow-x-auto">
                <?php if ($isMultiTerm && !$isReportCollege && !empty($gradesBySubject)): ?>
                <!-- DepEd K-12 Report Card Format -->
                <?php
                    // Determine quarter labels
                    $quarterLabels = str_starts_with($termFilter, 'all_q_') ? ['Q1','Q2','Q3','Q4'] : ['Q1','Q2'];
                ?>
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr class="text-sm text-gray-600">
                            <th class="px-4 py-3 font-medium text-left" rowspan="2">Learning Areas</th>
                            <th class="px-2 py-2 font-medium text-center border-l border-gray-200" colspan="<?= count($quarterLabels) ?>">Quarter</th>
                            <th class="px-3 py-3 font-medium text-center border-l border-gray-200" rowspan="2">Final<br>Grade</th>
                            <th class="px-3 py-3 font-medium text-center border-l border-gray-200" rowspan="2">Remarks</th>
                        </tr>
                        <tr class="text-sm text-gray-600">
                            <?php foreach ($quarterLabels as $ql): ?>
                            <th class="px-2 py-2 font-medium text-center border-l border-gray-200 w-14"><?= $ql ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $allFinals = [];
                        foreach ($gradesBySubject as $subj):
                            $finalGrade = $subj['final_grade'];
                            if (count($subj['quarters']) >= (str_starts_with($termFilter, 'all_q_') ? 4 : 2)) {
                                $allFinals[] = $finalGrade;
                            }
                            $passed = $finalGrade >= 75;
                        ?>
                        <tr class="border-t border-gray-100">
                            <td class="px-4 py-3 text-sm font-medium">
                                <?= htmlspecialchars($subj['subject_name']) ?>
                            </td>
                            <?php foreach ($quarterLabels as $ql): ?>
                            <td class="px-2 py-3 text-sm text-center border-l border-gray-200 font-medium">
                                <?php if (isset($subj['quarters'][$ql])): ?>
                                    <?= number_format($subj['quarters'][$ql], 0) ?>
                                <?php else: ?>
                                    <span class="text-gray-300">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="px-3 py-3 text-sm text-center border-l border-gray-200 font-bold <?= $passed ? 'text-green-600' : 'text-red-600' ?>">
                                <?= $finalGrade > 0 ? number_format($finalGrade, 0) : '—' ?>
                            </td>
                            <td class="px-3 py-3 text-sm text-center border-l border-gray-200">
                                <?php if ($finalGrade > 0): ?>
                                <span class="px-2 py-0.5 rounded-full text-xs <?= $passed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $passed ? 'PASSED' : 'FAILED' ?>
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr class="border-t-2 border-gray-200">
                            <td class="px-4 py-3 text-sm font-bold" colspan="<?= count($quarterLabels) + 1 ?>">General Average</td>
                            <td class="px-3 py-3 text-sm text-center border-l border-gray-200 font-bold text-lg">
                                <?php
                                $expectedQ = str_starts_with($termFilter, 'all_q_') ? 4 : 2;
                                $completeCount = count($allFinals);
                                $totalSubjects = count($gradesBySubject);
                                $allComplete = ($completeCount === $totalSubjects && $totalSubjects > 0);
                                $genAvg = $allComplete ? round(array_sum($allFinals) / count($allFinals), 0) : 0;
                                echo $allComplete ? $genAvg : '—';
                                ?>
                            </td>
                            <td class="px-3 py-3 text-sm text-center border-l border-gray-200">
                                <?php if ($allComplete && $genAvg > 0): ?>
                                <span class="px-2 py-0.5 rounded-full text-xs <?= $genAvg >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $genAvg >= 75 ? 'PASSED' : 'FAILED' ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-400 text-xs">Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Grading Scale Legend -->
                <div class="px-6 py-4 border-t border-gray-100">
                    <p class="text-xs font-semibold text-gray-600 mb-2">Grading Scale</p>
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs text-gray-600">
                        <div class="flex items-center gap-1"><span class="w-2 h-2 bg-green-500 rounded-full"></span> Outstanding: 90–100</div>
                        <div class="flex items-center gap-1"><span class="w-2 h-2 bg-green-400 rounded-full"></span> Very Satisfactory: 85–89</div>
                        <div class="flex items-center gap-1"><span class="w-2 h-2 bg-yellow-400 rounded-full"></span> Satisfactory: 80–84</div>
                        <div class="flex items-center gap-1"><span class="w-2 h-2 bg-orange-400 rounded-full"></span> Fairly Satisfactory: 75–79</div>
                        <div class="flex items-center gap-1"><span class="w-2 h-2 bg-red-500 rounded-full"></span> Did Not Meet Expectations: Below 75</div>
                    </div>
                </div>

                <?php else: ?>
                <!-- Standard table (single term or college) -->
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
                        foreach ($grades as $grade): 
                            $totalUnits += $grade['unit'];
                            $weightedSum += ($grade['period_grade'] * $grade['unit']);
                            $passed = $isReportCollege ? ($grade['period_grade'] <= 3.00) : ($grade['period_grade'] >= 75);
                        ?>
                        <tr class="border-t border-gray-100">
                            <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($grade['subject_name']) ?></td>
                            <?php if ($isReportCollege): ?>
                            <td class="px-6 py-4 text-sm text-center"><?= $grade['unit'] ?></td>
                            <?php endif; ?>
                            <td class="px-6 py-4 text-sm text-center font-bold <?= $passed ? 'text-green-600' : 'text-red-600' ?>">
                                <?= number_format($grade['period_grade'], 2) ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-center">
                                <span class="px-2 py-1 rounded-full text-xs <?= $passed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $passed ? 'PASSED' : 'FAILED' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 no-print"><?= htmlspecialchars($grade['teacher_name'] ?? 'N/A') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr class="border-t-2 border-gray-200">
                            <?php if ($isReportCollege): ?>
                            <td class="px-6 py-4 text-sm font-bold">General Weighted Average (GWA)</td>
                            <td class="px-6 py-4 text-sm text-center font-bold"><?= $totalUnits ?></td>
                            <td class="px-6 py-4 text-sm text-center font-bold text-lg">
                                <?= $totalUnits > 0 ? number_format($weightedSum / $totalUnits, 2) : '0.00' ?>
                            </td>
                            <td colspan="2" class="px-6 py-4"></td>
                            <?php else: ?>
                            <td class="px-6 py-4 text-sm font-bold">General Average</td>
                            <td class="px-6 py-4 text-sm text-center font-bold text-lg">
                                <?php
                                $gradeCount = count($grades);
                                $gradeSum = array_sum(array_column($grades, 'period_grade'));
                                echo $gradeCount > 0 ? number_format($gradeSum / $gradeCount, 0) : '0';
                                ?>
                            </td>
                            <td colspan="2" class="px-6 py-4"></td>
                            <?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
            
            <!-- Signature Section -->
            <div class="px-4 sm:px-6 py-8 border-t border-gray-100 print-footer">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 sm:gap-8 mt-8 sm:mt-12">
                    <div class="text-center">
                        <div class="border-t border-black pt-2">
                            <p class="text-sm font-medium">Class Adviser</p>
                        </div>
                    </div>
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

        <?php elseif ($sectionFilter && $termFilter && $gradesByStudent): ?>
        <!-- Section Summary Report -->
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
        
        <!-- Section Grade List -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print-area">
            <div class="px-6 py-4 border-b border-gray-100 print-header">
                <div class="text-center mb-4 hidden print:block">
                    <h2 class="text-xl font-bold">SECTION GRADE REPORT</h2>
                </div>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Section Grade Summary</h3>
                        <p class="text-sm text-gray-500">
                            Section: <?= htmlspecialchars($sections[array_search($sectionFilter, array_column($sections, 'id'))]['section_code'] ?? '') ?> |
                            Term: <?= htmlspecialchars($isMultiTerm ? $termDisplayName : ($terms[array_search($termFilter, array_column($terms, 'id'))]['term_name'] ?? '')) ?>
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
                        // Sort by GWA descending
                        uasort($gradesByStudent, function($a, $b) {
                            return $b['gwa'] <=> $a['gwa'];
                        });
                        foreach ($gradesByStudent as $studentId => $student): 
                            $allPassed = true;
                            if ($isMultiTerm && !$isReportCollege && !empty($student['subjects'])) {
                                // Check final grades per subject
                                foreach ($student['subjects'] as $subj) {
                                    if ($subj['final_grade'] < 75) {
                                        $allPassed = false;
                                        break;
                                    }
                                }
                            } else {
                                foreach ($student['grades'] as $g) {
                                    if ($isReportCollege ? ($g['period_grade'] > 3.00) : ($g['period_grade'] < 75)) {
                                        $allPassed = false;
                                        break;
                                    }
                                }
                            }
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm"><?= $rank++ ?></td>
                            <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($student['name'] ?? formatPersonName($student)) ?></td>
                            <td class="px-6 py-4 text-sm text-center"><?= $student['subject_count'] ?? count($student['grades']) ?></td>
                            <?php if ($isReportCollege): ?>
                            <td class="px-6 py-4 text-sm text-center"><?= $student['total_units'] ?></td>
                            <?php endif; ?>
                            <td class="px-6 py-4 text-sm text-center font-bold <?= ($student['gwa'] > 0 && ($isReportCollege ? ($student['gwa'] <= 3.00) : ($student['gwa'] >= 75))) ? 'text-green-600' : ($student['gwa'] > 0 ? 'text-red-600' : 'text-gray-400') ?>">
                                <?php if ($isMultiTerm && !$isReportCollege && empty($student['all_complete'])): ?>
                                    N/A
                                <?php else: ?>
                                    <?= $isReportCollege ? number_format($student['gwa'], 2) : number_format($student['gwa'], 0) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-center">
                                <?php if ($isMultiTerm && !$isReportCollege && empty($student['all_complete'])): ?>
                                <span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-600">Incomplete</span>
                                <?php else: ?>
                                <span class="px-2 py-1 rounded-full text-xs <?= $allPassed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $allPassed ? 'PASSED' : 'FAILED' ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-center no-print">
                                <a href="?term=<?= $termFilter ?>&section=<?= $sectionFilter ?>&student=<?= $studentId ?>" 
                                   class="text-blue-600 hover:text-blue-800 mr-2">View</a>
                                <a href="report_pdf.php?term=<?= $termFilter ?>&student=<?= $studentId ?>" 
                                   class="text-red-600 hover:text-red-800">PDF</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($termFilter || $sectionFilter): ?>
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
            <p class="text-gray-500">Select a term and section to generate a grade report. You can also select a specific student for individual reports.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
