<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('student');

$pageTitle = 'My Grades';
$studentId = $_SESSION['student_id'] ?? 0;

// Check if grades are visible
$gradesVisible = getSetting('grades_visible', '0');

// Get student info to determine education level and enrollment type
$studentInfoStmt = db()->prepare("
    SELECT st.*, sec.academic_track_id, sec.sy_id, at.desc as track_name, at.enrollment_type,
           d.code as dept_code, lv.code as level_code
    FROM tbl_student st
    JOIN tbl_section sec ON st.section_id = sec.id
    JOIN tbl_academic_track at ON sec.academic_track_id = at.id
    JOIN tbl_departments d ON at.dept_id = d.id
    LEFT JOIN level lv ON sec.level_id = lv.id
    WHERE st.id = ?
");
$studentInfoStmt->execute([$studentId]);
$studentInfo = $studentInfoStmt->fetch();

// Current school year from student's section
$currentSyId = (int)($studentInfo['sy_id'] ?? 0);

// Get all school years the student has enrollments in (for switcher)
$syListStmt = db()->prepare("
    SELECT DISTINCT sy.id, sy.sy_name
    FROM tbl_enroll e
    JOIN tbl_sy sy ON e.sy_id = sy.id
    WHERE e.student_id = ?
    ORDER BY sy.id DESC
");
$syListStmt->execute([$studentId]);
$schoolYears = $syListStmt->fetchAll();

// Selected school year (default to current)
$selectedSyId = (int)($_GET['sy_id'] ?? $currentSyId);
// Validate selected SY is one the student has enrollments in
$validSyIds = array_column($schoolYears, 'id');
if (!in_array($selectedSyId, $validSyIds)) {
    $selectedSyId = $currentSyId;
}
$selectedSyName = '';
foreach ($schoolYears as $sy) {
    if ((int)$sy['id'] === $selectedSyId) {
        $selectedSyName = $sy['sy_name'];
        break;
    }
}

// Determine enrollment type (yearly for K-10, semestral for SHS/College)
$isYearly = ($studentInfo['enrollment_type'] ?? 'semestral') === 'yearly';
$isShsDept = ($studentInfo['dept_code'] ?? '') === 'SHS';

// Determine education level
$educationLevel = 'k12'; // Default K-12
if ($studentInfo && in_array($studentInfo['dept_code'], ['CCTE', 'CON'])) {
    $educationLevel = 'college';
}

// Get pending/in-progress grades count for selected SY
$pendingStmt = db()->prepare("
    SELECT COUNT(*) as count FROM tbl_enroll e
    INNER JOIN tbl_grades g ON e.id = g.enroll_id
    WHERE e.student_id = ? AND e.sy_id = ? AND g.status IN ('submitted', 'draft')
");
$pendingStmt->execute([$studentId, $selectedSyId]);
$pendingCount = $pendingStmt->fetch()['count'] ?? 0;

if ($isYearly) {
    // K-10: Get all quarterly grades for each subject in selected SY
    $stmt = db()->prepare("
        SELECT e.id as enroll_id, sub.subjcode, sub.`desc` as subject_name, 
               sy.sy_name,
               MAX(CASE WHEN g.grading_period = 'Q1' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q1_grade,
               MAX(CASE WHEN g.grading_period = 'Q2' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q2_grade,
               MAX(CASE WHEN g.grading_period = 'Q3' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q3_grade,
               MAX(CASE WHEN g.grading_period = 'Q4' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q4_grade,
               MAX(CASE WHEN g.grading_period = 'Q1' AND g.status IN ('approved', 'finalized') THEN g.status END) as q1_status,
               MAX(CASE WHEN g.grading_period = 'Q2' AND g.status IN ('approved', 'finalized') THEN g.status END) as q2_status,
               MAX(CASE WHEN g.grading_period = 'Q3' AND g.status IN ('approved', 'finalized') THEN g.status END) as q3_status,
               MAX(CASE WHEN g.grading_period = 'Q4' AND g.status IN ('approved', 'finalized') THEN g.status END) as q4_status
        FROM tbl_enroll e
        JOIN tbl_subjects sub ON e.subject_id = sub.id
        LEFT JOIN tbl_grades g ON e.id = g.enroll_id
        LEFT JOIN tbl_sy sy ON e.sy_id = sy.id
        WHERE e.student_id = ? AND e.sy_id = ?
        GROUP BY e.id, sub.subjcode, sub.`desc`, sy.sy_name
        ORDER BY sub.subjcode
    ");
    $stmt->execute([$studentId, $selectedSyId]);
    $grades = $stmt->fetchAll();

    // Group by school year
    $gradesByYear = [];
    foreach ($grades as $grade) {
        $syName = $grade['sy_name'] ?? 'Current Year';
        if (!isset($gradesByYear[$syName])) {
            $gradesByYear[$syName] = [];
        }
        $gradesByYear[$syName][] = $grade;
    }

    // Check if ALL subjects have ALL 4 quarters approved
    $allSubjectsComplete = !empty($grades);
    foreach ($grades as $grade) {
        foreach (['q1_status', 'q2_status', 'q3_status', 'q4_status'] as $statusKey) {
            if (!in_array($grade[$statusKey], ['approved', 'finalized'])) {
                $allSubjectsComplete = false;
                break 2;
            }
        }
    }

    // Calculate General Average only when all subjects are complete
    $totalGrades = 0;
    $gradeCount = 0;
    $generalAverage = 0;
    if ($allSubjectsComplete) {
        foreach ($grades as $grade) {
            foreach (['q1_grade', 'q2_grade', 'q3_grade', 'q4_grade'] as $qKey) {
                if ($grade[$qKey] !== null) {
                    $totalGrades += $grade[$qKey];
                    $gradeCount++;
                }
            }
        }
        $generalAverage = $gradeCount > 0 ? $totalGrades / $gradeCount : 0;
    }
} else {
    if ($isShsDept) {
        // SHS: 2 semesters, each semester has 2 quarters (Q1, Q2)
        $stmt = db()->prepare("
            SELECT e.id as enroll_id, sub.subjcode, sub.`desc` as subject_name,
                   t.term_name, t.id as term_id, sy.sy_name,
                   MAX(CASE WHEN g.grading_period = 'Q1' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q1_grade,
                   MAX(CASE WHEN g.grading_period = 'Q2' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as q2_grade,
                   MAX(CASE WHEN g.grading_period = 'Q1' AND g.status IN ('approved', 'finalized') THEN g.status END) as q1_status,
                   MAX(CASE WHEN g.grading_period = 'Q2' AND g.status IN ('approved', 'finalized') THEN g.status END) as q2_status
            FROM tbl_enroll e
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            LEFT JOIN tbl_grades g ON e.id = g.enroll_id
            LEFT JOIN tbl_term t ON g.term_id = t.id
            LEFT JOIN tbl_sy sy ON e.sy_id = sy.id
            WHERE e.student_id = ? AND e.sy_id = ?
            GROUP BY e.id, sub.subjcode, sub.`desc`, t.term_name, t.id, sy.sy_name
            ORDER BY t.id, sub.subjcode
        ");
        $stmt->execute([$studentId, $selectedSyId]);
        $grades = $stmt->fetchAll();

        $gradesByTerm = [];
        foreach ($grades as $grade) {
            $termName = $grade['term_name'] ?? 'Ungraded';
            if (!isset($gradesByTerm[$termName])) {
                $gradesByTerm[$termName] = [];
            }
            $gradesByTerm[$termName][] = $grade;
        }

        // Check per-term completeness: all subjects in each term must have both Q1+Q2 approved
        $termCompleteness = [];
        foreach ($gradesByTerm as $tName => $tGrades) {
            $termCompleteness[$tName] = true;
            foreach ($tGrades as $g) {
                if (!in_array($g['q1_status'], ['approved', 'finalized']) || !in_array($g['q2_status'], ['approved', 'finalized'])) {
                    $termCompleteness[$tName] = false;
                    break;
                }
            }
        }
        // Overall: all terms must be complete
        $allTermsComplete = !empty($termCompleteness) && !in_array(false, $termCompleteness, true);

        $totalGrades = 0;
        $gradeCount = 0;
        $generalAverage = 0;
        if ($allTermsComplete) {
            foreach ($grades as $grade) {
                $q1 = $grade['q1_grade'];
                $q2 = $grade['q2_grade'];
                if ($q1 !== null && $q2 !== null) {
                    $subjectAvg = ($q1 + $q2) / 2;
                    $totalGrades += $subjectAvg;
                    $gradeCount++;
                }
            }
            $generalAverage = $gradeCount > 0 ? $totalGrades / $gradeCount : 0;
        }
    } else {
        // College: Get grades grouped by term/semester with grading periods (Prelim, Midterm, Semi-Finals, Finals)
        $stmt = db()->prepare("
            SELECT e.id as enroll_id, sub.subjcode, sub.`desc` as subject_name, sub.unit,
                   t.term_name, t.id as term_id, sy.sy_name,
                   MAX(CASE WHEN g.grading_period = 'PRELIM' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as prelim_grade,
                   MAX(CASE WHEN g.grading_period = 'MIDTERM' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as midterm_grade,
                   MAX(CASE WHEN g.grading_period = 'SEMIFINAL' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as semifinal_grade,
                   MAX(CASE WHEN g.grading_period = 'FINAL' AND g.status IN ('approved', 'finalized') THEN g.period_grade END) as final_grade,
                   MAX(CASE WHEN g.grading_period = 'PRELIM' AND g.status IN ('approved', 'finalized') THEN g.status END) as prelim_status,
                   MAX(CASE WHEN g.grading_period = 'MIDTERM' AND g.status IN ('approved', 'finalized') THEN g.status END) as midterm_status,
                   MAX(CASE WHEN g.grading_period = 'SEMIFINAL' AND g.status IN ('approved', 'finalized') THEN g.status END) as semifinal_status,
                   MAX(CASE WHEN g.grading_period = 'FINAL' AND g.status IN ('approved', 'finalized') THEN g.status END) as final_status
            FROM tbl_enroll e
            JOIN tbl_subjects sub ON e.subject_id = sub.id
            LEFT JOIN tbl_grades g ON e.id = g.enroll_id
            LEFT JOIN tbl_term t ON g.term_id = t.id
            LEFT JOIN tbl_sy sy ON e.sy_id = sy.id
            WHERE e.student_id = ? AND e.sy_id = ?
            GROUP BY e.id, sub.subjcode, sub.`desc`, sub.unit, t.term_name, t.id, sy.sy_name
            ORDER BY t.id, sub.subjcode
        ");
        $stmt->execute([$studentId, $selectedSyId]);
        $grades = $stmt->fetchAll();

        $gradesByTerm = [];
        foreach ($grades as $grade) {
            $termName = $grade['term_name'] ?? 'Ungraded';
            if (!isset($gradesByTerm[$termName])) {
                $gradesByTerm[$termName] = [];
            }
            $gradesByTerm[$termName][] = $grade;
        }

        // Check per-term completeness: all subjects must have all 4 periods approved
        $termCompleteness = [];
        foreach ($gradesByTerm as $tName => $tGrades) {
            $termCompleteness[$tName] = true;
            foreach ($tGrades as $g) {
                if (!in_array($g['prelim_status'], ['approved', 'finalized']) ||
                    !in_array($g['midterm_status'], ['approved', 'finalized']) ||
                    !in_array($g['semifinal_status'], ['approved', 'finalized']) ||
                    !in_array($g['final_status'], ['approved', 'finalized'])) {
                    $termCompleteness[$tName] = false;
                    break;
                }
            }
        }
        $allTermsComplete = !empty($termCompleteness) && !in_array(false, $termCompleteness, true);

        $totalWeightedGrade = 0;
        $totalUnits = 0;
        $gwa = 0;
        if ($allTermsComplete) {
            foreach ($grades as $grade) {
                $prelim = $grade['prelim_grade'];
                $midterm = $grade['midterm_grade'];
                $semifinal = $grade['semifinal_grade'];
                $final = $grade['final_grade'];
                if ($prelim !== null && $midterm !== null && $semifinal !== null && $final !== null && $grade['unit']) {
                    $subjectAvg = ($prelim + $midterm + $semifinal + $final) / 4;
                    $totalWeightedGrade += $subjectAvg * $grade['unit'];
                    $totalUnits += $grade['unit'];
                }
            }
            $gwa = $totalUnits > 0 ? $totalWeightedGrade / $totalUnits : 0;
        }
    }
}

include '../includes/header.php';
include '../includes/sidebar_student.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">My Grades</h1>
<?php if (!empty($studentInfo['level_code'])): ?>
            <span class="px-2.5 py-1 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700"><?= htmlspecialchars($studentInfo['level_code']) ?></span>
<?php endif; ?>
<?php if ($gradesVisible !== '1'): ?>
        </div>
    </div>
    <div class="p-4 sm:p-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
            </svg>
            <h2 class="text-xl font-semibold text-gray-700 mb-2">Grades Not Yet Available</h2>
            <p class="text-gray-500">The registrar has not yet released grades for viewing. Please check back later.</p>
        </div>
    </div>
</main>
<?php include '../includes/footer.php'; ?>
<?php return; endif; ?>
            <div class="flex items-center gap-3">
                <a href="report_pdf.php?sy_id=<?= $selectedSyId ?>" target="_blank" class="no-print px-3 py-2 bg-neutral-900 text-white rounded-lg hover:bg-neutral-700 transition text-sm flex items-center gap-2" title="Download PDF">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    PDF
                </a>
                <?php if (count($schoolYears) > 1): ?>
                <select id="sySelector" onchange="window.location.href='grades.php?sy_id='+this.value"
                    class="no-print px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-black focus:outline-none bg-white">
                    <?php foreach ($schoolYears as $sy): ?>
                    <option value="<?= $sy['id'] ?>" <?= (int)$sy['id'] === $selectedSyId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sy['sy_name']) ?><?= (int)$sy['id'] === $currentSyId ? ' (Current)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <div class="flex items-center gap-2 text-gray-500 text-sm no-print">
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-8">
        <?php if ($pendingCount > 0): ?>
        <!-- Pending Grades Notice -->
        <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg flex items-start gap-3 no-print">
            <svg class="w-5 h-5 text-yellow-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-yellow-800">Grades Pending Approval</p>
                <p class="text-sm text-yellow-700">You have <?= $pendingCount ?> grade(s) awaiting admin approval. Grades will appear here once approved by the registrar.</p>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($isYearly): ?>
        <!-- K-10 Yearly/Quarterly Grades View -->
        
        <?php if ($allSubjectsComplete): ?>
        <!-- General Average Card - only shown when all subjects have complete approved grades -->
        <div class="bg-black rounded-xl p-6 text-white mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-300 text-sm">General Average</p>
                    <p class="text-4xl font-bold mt-1"><?= $generalAverage > 0 ? number_format($generalAverage, 2) : 'N/A' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Based on all approved quarterly grades</p>
                </div>
                <div class="text-right">
                    <p class="text-gray-300 text-sm">Subjects Enrolled</p>
                    <p class="text-2xl font-bold mt-1"><?= count($grades) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($grades)): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No Grades Yet</h3>
            <p class="text-gray-500">Your grades will appear here once they have been entered by your teachers.</p>
        </div>
        <?php else: ?>

        <?php foreach ($gradesByYear as $syName => $yearGrades): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-800">School Year: <?= htmlspecialchars($syName) ?></h2>
                <input type="text" class="grades-search-input px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none text-sm" placeholder="Search subjects...">
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px] grades-table">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Subject</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">1st Qtr</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">2nd Qtr</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">3rd Qtr</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">4th Qtr</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Final Avg</th>
                            <th class="px-6 py-4 font-medium text-center">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $yearTotalFinal = 0;
                        $yearSubjectCount = 0;
                        foreach ($yearGrades as $grade): 
                            // Calculate final average for this subject (average of 4 quarters)
                            // Only calculate if ALL 4 quarters have grades
                            $q1 = $grade['q1_grade'];
                            $q2 = $grade['q2_grade'];
                            $q3 = $grade['q3_grade'];
                            $q4 = $grade['q4_grade'];
                            $allQuartersComplete = ($q1 !== null && $q2 !== null && $q3 !== null && $q4 !== null);
                            
                            $finalAvg = null;
                            if ($allQuartersComplete) {
                                $finalAvg = ($q1 + $q2 + $q3 + $q4) / 4;
                                $yearTotalFinal += $finalAvg;
                                $yearSubjectCount++;
                            }
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($grade['subject_name']) ?></td>
                            <?php foreach (['q1_grade', 'q2_grade', 'q3_grade', 'q4_grade'] as $qKey): 
                                $statusKey = str_replace('grade', 'status', $qKey);
                            ?>
                            <td class="px-6 py-4 text-center">
                                <?php if ($grade[$qKey] !== null): ?>
                                <span class="px-2 py-1 text-sm font-bold rounded <?= $grade[$qKey] >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= number_format($grade[$qKey], 0) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="px-6 py-4 text-center">
                                <?php if ($allQuartersComplete && $finalAvg !== null): ?>
                                <span class="px-3 py-1 text-sm font-bold rounded-full <?= $finalAvg >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= number_format($finalAvg, 0) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($allQuartersComplete && $finalAvg !== null): ?>
                                <span class="text-sm font-medium <?= $finalAvg >= 75 ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= $finalAvg >= 75 ? 'PASSED' : 'FAILED' ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-400 text-sm">Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-sm font-medium text-right">General Average:</td>
                            <td class="px-6 py-4 text-center">
                                <span class="text-lg font-bold text-neutral-700">
                                    <?= ($allSubjectsComplete && $yearSubjectCount > 0) ? number_format($yearTotalFinal / $yearSubjectCount, 2) : 'N/A' ?>
                                </span>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php elseif ($isShsDept): ?>
        <!-- SHS Semestral Grades View -->

        <?php if ($allTermsComplete): ?>
        <div class="bg-black rounded-xl p-6 text-white mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-300 text-sm">General Average</p>
                    <p class="text-4xl font-bold mt-1"><?= $generalAverage > 0 ? number_format($generalAverage, 2) : 'N/A' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Based on all approved grades per semester</p>
                </div>
                <div class="text-right">
                    <p class="text-gray-300 text-sm">Subjects Enrolled</p>
                    <p class="text-2xl font-bold mt-1"><?= count($grades) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($grades) || !array_filter($grades, fn($g) => $g['q1_grade'] !== null || $g['q2_grade'] !== null)): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No Grades Yet</h3>
            <p class="text-gray-500">Your grades will appear here once they have been reviewed and approved by the registrar.</p>
        </div>
        <?php else: ?>

        <?php foreach ($gradesByTerm as $termName => $termGrades): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($termName) ?></h2>
                <input type="text" class="grades-search-input px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none text-sm" placeholder="Search subjects...">
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px] grades-table">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Subject</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">1st Quarter</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">2nd Quarter</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Final Avg</th>
                            <th class="px-6 py-4 font-medium text-center">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $termTotal = 0;
                        $termSubjectCount = 0;
                        foreach ($termGrades as $grade):
                            $q1 = $grade['q1_grade'];
                            $q2 = $grade['q2_grade'];
                            $allComplete = ($q1 !== null && $q2 !== null);
                            $allFinalized = (in_array($grade['q1_status'], ['approved', 'finalized']) && in_array($grade['q2_status'], ['approved', 'finalized']));
                            $subjectAvg = null;
                            if ($allComplete) {
                                $subjectAvg = ($q1 + $q2) / 2;
                                if ($allFinalized) {
                                    $termTotal += $subjectAvg;
                                    $termSubjectCount++;
                                }
                            }
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($grade['subject_name']) ?></td>
                            <?php foreach ([['grade' => $q1, 'status' => $grade['q1_status']], ['grade' => $q2, 'status' => $grade['q2_status']]] as $period): ?>
                            <td class="px-6 py-4 text-center">
                                <?php if ($period['grade'] !== null): ?>
                                <span class="px-2 py-1 text-sm font-bold rounded <?= $period['grade'] >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= number_format($period['grade'], 0) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="px-6 py-4 text-center">
                                <?php if ($allComplete && $subjectAvg !== null): ?>
                                <span class="px-3 py-1 text-sm font-bold rounded-full <?= $subjectAvg >= 75 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= number_format($subjectAvg, 0) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($allComplete && $subjectAvg !== null): ?>
                                <span class="text-sm font-medium <?= $subjectAvg >= 75 ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= $subjectAvg >= 75 ? 'PASSED' : 'FAILED' ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-400 text-sm">Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="3" class="px-6 py-4 text-sm font-medium text-right">Term Average:</td>
                            <td class="px-6 py-4 text-center">
                                <span class="text-lg font-bold text-neutral-700">
                                    <?= ($allTermsComplete && $termSubjectCount > 0) ? number_format($termTotal / $termSubjectCount, 2) : 'N/A' ?>
                                </span>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php else: ?>
        <!-- College Semestral Grades View -->
        
        <?php if ($allTermsComplete): ?>
        <!-- GWA Card - only shown when all terms have complete approved grades -->
        <div class="bg-black rounded-xl p-6 text-white mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-300 text-sm">General Weighted Average (GWA)</p>
                    <p class="text-4xl font-bold mt-1"><?= $gwa > 0 ? number_format($gwa, 2) : 'N/A' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Based on all approved grades</p>
                </div>
                <div class="text-right">
                    <p class="text-gray-300 text-sm">Total Units</p>
                    <p class="text-2xl font-bold mt-1"><?= $totalUnits ?></p>
                </div>
            </div>
            
            <!-- College Grading Scale Reference -->
            <div class="mt-4 pt-4 border-t border-gray-700">
                <p class="text-xs text-gray-400 mb-2">Grading Scale Reference:</p>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="px-2 py-1 bg-green-900 text-green-200 rounded">1.00-1.75 Excellent</span>
                    <span class="px-2 py-1 bg-blue-900 text-blue-200 rounded">2.00-2.50 Good</span>
                    <span class="px-2 py-1 bg-yellow-900 text-yellow-200 rounded">2.75-3.00 Passing</span>
                    <span class="px-2 py-1 bg-red-900 text-red-200 rounded">5.00 Failed</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($grades) || !array_filter($grades, fn($g) => $g['prelim_grade'] !== null || $g['midterm_grade'] !== null || $g['semifinal_grade'] !== null || $g['final_grade'] !== null)): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No Grades Yet</h3>
            <p class="text-gray-500">Your grades will appear here once they have been reviewed and approved by the registrar.</p>
        </div>
        <?php else: ?>

        <?php foreach ($gradesByTerm as $termName => $termGrades): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($termName) ?></h2>
                <input type="text" class="grades-search-input px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none text-sm" placeholder="Search subjects...">
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] grades-table">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium sortable cursor-pointer hover:bg-gray-100">Subject</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Units</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Prelim</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Midterm</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Semi-Finals</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Finals</th>
                            <th class="px-6 py-4 font-medium text-center sortable cursor-pointer hover:bg-gray-100">Final Avg</th>
                            <th class="px-6 py-4 font-medium text-center">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $termTotal = 0;
                        $termUnits = 0;
                        foreach ($termGrades as $grade): 
                            // Calculate subject final average
                            $prelim = $grade['prelim_grade'];
                            $midterm = $grade['midterm_grade'];
                            $semifinal = $grade['semifinal_grade'];
                            $final = $grade['final_grade'];
                            $allComplete = ($prelim !== null && $midterm !== null && $semifinal !== null && $final !== null);
                            $allFinalized = (in_array($grade['prelim_status'], ['approved', 'finalized']) && 
                                            in_array($grade['midterm_status'], ['approved', 'finalized']) && 
                                            in_array($grade['semifinal_status'], ['approved', 'finalized']) && 
                                            in_array($grade['final_status'], ['approved', 'finalized']));
                            
                            $subjectAvg = null;
                            if ($allComplete) {
                                $subjectAvg = ($prelim + $midterm + $semifinal + $final) / 4;
                                if ($allFinalized && $grade['unit']) {
                                    $termTotal += $subjectAvg * $grade['unit'];
                                    $termUnits += $grade['unit'];
                                }
                            }
                        ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($grade['subject_name']) ?></td>
                            <td class="px-6 py-4 text-sm text-center"><?= $grade['unit'] ?? '-' ?></td>
                            <?php 
                            $periods = [
                                ['grade' => $prelim, 'status' => $grade['prelim_status']],
                                ['grade' => $midterm, 'status' => $grade['midterm_status']],
                                ['grade' => $semifinal, 'status' => $grade['semifinal_status']],
                                ['grade' => $final, 'status' => $grade['final_status']]
                            ];
                            foreach ($periods as $period): 
                                // For college: grade values are stored as 1.00-5.00 (lower is better)
                                // 3.00 or below = passing, above 3.00 = failed
                                $isPassing = ($period['grade'] !== null && $period['grade'] <= 3.00);
                            ?>
                            <td class="px-6 py-4 text-center">
                                <?php if ($period['grade'] !== null): ?>
                                <span class="px-2 py-1 text-sm font-bold rounded <?= $isPassing ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= number_format($period['grade'], 2) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="px-6 py-4 text-center">
                                <?php if ($allComplete && $subjectAvg !== null): ?>
                                <?php $avgPassing = $subjectAvg <= 3.00; ?>
                                <span class="px-3 py-1 text-sm font-bold rounded-full <?= $avgPassing ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= number_format($subjectAvg, 2) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($allComplete && $subjectAvg !== null): ?>
                                <?php $avgPassing = $subjectAvg <= 3.00; ?>
                                <span class="text-sm font-medium <?= $avgPassing ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= $avgPassing ? 'PASSED' : 'FAILED' ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-400 text-sm">Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-sm font-medium text-right">Term Average:</td>
                            <td class="px-6 py-4 text-center">
                                <span class="text-lg font-bold text-neutral-700">
                                    <?= ($allTermsComplete && $termUnits > 0) ? number_format($termTotal / $termUnits, 2) : 'N/A' ?>
                                </span>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize search and sorting for each grades table
    document.querySelectorAll('.grades-table').forEach(function(table, tableIndex) {
        const container = table.closest('.bg-white');
        const searchInput = container.querySelector('.grades-search-input');
        
        // Setup search for this table
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                const tbody = table.querySelector('tbody');
                if (!tbody) return;
                
                const rows = tbody.querySelectorAll('tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }
        
        // Setup sortable headers for this table
        const headers = table.querySelectorAll('th.sortable');
        headers.forEach((header, index) => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', function() {
                sortGradesTable(table, index, this);
            });
        });
    });
    
    function sortGradesTable(table, columnIndex, header) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const isAscending = header.classList.contains('sort-asc');
        
        // Remove sort classes from all headers in this table
        table.querySelectorAll('th').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
        });
        
        // Toggle sort direction
        header.classList.add(isAscending ? 'sort-desc' : 'sort-asc');
        
        rows.sort((a, b) => {
            const aCell = a.cells[columnIndex];
            const bCell = b.cells[columnIndex];
            if (!aCell || !bCell) return 0;
            
            let aVal = aCell.textContent.trim();
            let bVal = bCell.textContent.trim();
            
            // Try numeric sort
            const aNum = parseFloat(aVal);
            const bNum = parseFloat(bVal);
            
            if (!isNaN(aNum) && !isNaN(bNum)) {
                return isAscending ? bNum - aNum : aNum - bNum;
            }
            
            // String sort
            return isAscending ? bVal.localeCompare(aVal) : aVal.localeCompare(bVal);
        });
        
        rows.forEach(row => tbody.appendChild(row));
    }
});
</script>
