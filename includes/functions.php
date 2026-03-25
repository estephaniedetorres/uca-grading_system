<?php

/**
 * Verify a password against stored hash. Supports both bcrypt and legacy plain text.
 * If plain text matches, automatically upgrades to bcrypt in the database.
 */
function verifyPassword($password, $storedHash, $userId = null) {
    // Try bcrypt first
    if (password_verify($password, $storedHash)) {
        return true;
    }
    // Legacy plain text fallback
    if ($password === $storedHash) {
        // Auto-upgrade to bcrypt
        if ($userId) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = db()->prepare("UPDATE tbl_users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $userId]);
        }
        return true;
    }
    return false;
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function formatDate($date) {
    return date('F d, Y', strtotime($date));
}

/**
 * Build SQL IN clause placeholders for an array of IDs.
 * Usage: $ph = deptPlaceholders($ids); then "WHERE col IN ($ph)"
 */
function deptPlaceholders($ids) {
    return implode(',', array_fill(0, count($ids), '?'));
}

function getCurrentDate() {
    return date('F d, Y');
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateAlert($type, $message) {
    $bgColor = $type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
    return "<div class='p-4 mb-4 rounded-lg $bgColor'>$message</div>";
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function getStatusBadge($status) {
    $colors = [
        'active' => 'bg-green-100 text-green-800',
        'inactive' => 'bg-red-100 text-red-800',
        'pending' => 'bg-yellow-100 text-yellow-800',
        'closed' => 'bg-gray-100 text-gray-800',
        'archived' => 'bg-blue-100 text-blue-800'
    ];
    $color = $colors[$status] ?? 'bg-gray-100 text-gray-800';
    return "<span class='px-2 py-1 text-xs font-medium rounded-full $color'>$status</span>";
}
/**
 * Get a system setting value
 */
function getSetting($key, $default = '') {
    $stmt = db()->prepare("SELECT setting_value FROM tbl_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

/**
 * Set a system setting value
 */
function setSetting($key, $value) {
    $stmt = db()->prepare("INSERT INTO tbl_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
    $stmt->execute([$key, $value, $value]);
}

/**
 * Get active school year
 */
function getActiveSchoolYear() {
    // Prefer the most recently created active SY if data accidentally has multiple active rows.
    $stmt = db()->prepare("SELECT * FROM tbl_sy WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    return $stmt->fetch();
}

function getStudentNumberYearPrefix(?array $schoolYear = null): string {
    $year = null;

    if ($schoolYear) {
        $syName = trim((string)($schoolYear['sy_name'] ?? ''));
        if ($syName !== '' && preg_match_all('/\d{4}/', $syName, $matches) && !empty($matches[0])) {
            $year = (int)end($matches[0]);
        }

        if (!$year && !empty($schoolYear['end_date'])) {
            $timestamp = strtotime((string)$schoolYear['end_date']);
            if ($timestamp) {
                $year = (int)date('Y', $timestamp);
            }
        }

        if (!$year && !empty($schoolYear['start_date'])) {
            $timestamp = strtotime((string)$schoolYear['start_date']);
            if ($timestamp) {
                $year = (int)date('Y', $timestamp);
            }
        }
    }

    if (!$year) {
        $year = (int)date('Y');
    }

    return str_pad((string)($year % 100), 2, '0', STR_PAD_LEFT);
}

function getStudentNumberPrefixForSchoolYearId(?int $syId = null): string {
    $schoolYear = null;

    if ($syId) {
        $stmt = db()->prepare("SELECT * FROM tbl_sy WHERE id = ? LIMIT 1");
        $stmt->execute([$syId]);
        $schoolYear = $stmt->fetch() ?: null;
    }

    if (!$schoolYear) {
        $schoolYear = getActiveSchoolYear() ?: null;
    }

    return getStudentNumberYearPrefix($schoolYear);
}

function generateNextStudentNumber(?int $syId = null): string {
    $prefix = getStudentNumberPrefixForSchoolYearId($syId);
    $pattern = '^' . $prefix . '-[0-9]{5}$';

    $stmt = db()->prepare("
        SELECT student_no
        FROM tbl_student
        WHERE student_no REGEXP ?
        ORDER BY CAST(SUBSTRING_INDEX(student_no, '-', -1) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $stmt->execute([$pattern]);
    $lastStudentNo = $stmt->fetchColumn();

    $nextNumber = 1;
    if ($lastStudentNo) {
        $nextNumber = ((int)substr((string)$lastStudentNo, strpos((string)$lastStudentNo, '-') + 1)) + 1;
    }

    return $prefix . '-' . str_pad((string)$nextNumber, 5, '0', STR_PAD_LEFT);
}

/**
 * Get active term for enrollment
 */
function getActiveTermForEnrollment($syId = null) {
    if (!$syId) {
        $sy = getActiveSchoolYear();
        $syId = $sy['id'] ?? 0;
    }
    
    $stmt = db()->prepare("
        SELECT t.*, es.enrollment_start, es.enrollment_end, es.max_units, es.is_open
        FROM tbl_term t
        LEFT JOIN tbl_enrollment_settings es ON t.id = es.term_id AND es.sy_id = ?
        WHERE t.sy_id = ? AND t.status = 'active'
        AND (es.is_open = 1 OR es.is_open IS NULL)
        AND (NOW() BETWEEN es.enrollment_start AND es.enrollment_end OR es.enrollment_start IS NULL)
        ORDER BY t.id ASC
        LIMIT 1
    ");
    $stmt->execute([$syId, $syId]);
    return $stmt->fetch();
}

/**
 * Check if enrollment is open for a term
 */
function isEnrollmentOpen($termId, $syId) {
    $stmt = db()->prepare("
        SELECT es.* FROM tbl_enrollment_settings es
        WHERE es.term_id = ? AND es.sy_id = ? AND es.is_open = 1
        AND NOW() BETWEEN es.enrollment_start AND es.enrollment_end
    ");
    $stmt->execute([$termId, $syId]);
    return $stmt->fetch() !== false;
}

/**
 * Get student's curriculum based on their section
 */
function getStudentCurriculum($studentId) {
    $stmt = db()->prepare("
        SELECT c.* 
        FROM tbl_student st
        JOIN tbl_section sec ON st.section_id = sec.id
        JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        JOIN tbl_curriculum c ON c.academic_track_id = at.id AND c.status = 'active'
        WHERE st.id = ?
    ");
    $stmt->execute([$studentId]);
    return $stmt->fetch();
}

/**
 * Get available subjects for enrollment based on curriculum and term
 */
function getAvailableSubjectsForEnrollment($curriculumId, $termId, $sectionId) {
    $stmt = db()->prepare("
        SELECT s.*, p.id as prospectus_id, p.max_enrollees, t.term_name,
               (SELECT COUNT(*) FROM tbl_enroll e 
                WHERE e.subject_id = s.id AND e.term_id = ? AND e.section_id = ? AND e.status = 'enrolled'
               ) as current_enrollees
        FROM tbl_prospectus p
        JOIN tbl_subjects s ON p.subject_id = s.id
        JOIN tbl_term t ON p.term_id = t.id
        WHERE p.curriculum_id = ? 
          AND p.term_id = ?
          AND p.status = 'active'
          AND s.status = 'active'
        ORDER BY s.subjcode
    ");
    $stmt->execute([$termId, $sectionId, $curriculumId, $termId]);
    return $stmt->fetchAll();
}

/**
 * Return only the first instance of each subject ID to avoid duplicate prospectus rows.
 *
 * @param array $subjects List of subject rows with at least an 'id' key
 * @return array Deduplicated subject list preserving original order
 */
function uniqueSubjectsById(array $subjects): array {
    $unique = [];
    foreach ($subjects as $subject) {
        $subjectId = isset($subject['id']) ? (int)$subject['id'] : null;
        if ($subjectId === null) {
            $unique[] = $subject;
            continue;
        }
        if (!isset($unique[$subjectId])) {
            $unique[$subjectId] = $subject;
        }
    }
    return array_values($unique);
}

/**
 * Check if student has passed all prerequisites for a subject
 */
function checkSubjectPrerequisites($studentId, $subjectId) {
    $prereqStmt = db()->prepare("
        SELECT sp.*, ps.subjcode as prereq_code
        FROM tbl_subject_prerequisites sp
        JOIN tbl_subjects ps ON sp.prerequisite_subject_id = ps.id
        WHERE sp.subject_id = ? AND sp.status = 'active'
    ");
    $prereqStmt->execute([$subjectId]);
    $prerequisites = $prereqStmt->fetchAll();
    
    if (empty($prerequisites)) {
        return ['passed' => true, 'message' => 'No prerequisites required.', 'missing' => []];
    }
    
    $missing = [];
    foreach ($prerequisites as $prereq) {
        $gradeStmt = db()->prepare("
            SELECT e.final_grade 
            FROM tbl_enroll e
            WHERE e.student_id = ? AND e.subject_id = ? AND e.status = 'completed'
            ORDER BY e.enrolled_at DESC
            LIMIT 1
        ");
        $gradeStmt->execute([$studentId, $prereq['prerequisite_subject_id']]);
        $gradeResult = $gradeStmt->fetch();
        
        if (!$gradeResult || $gradeResult['final_grade'] === null || $gradeResult['final_grade'] < $prereq['min_grade']) {
            $missing[] = $prereq['prereq_code'];
        }
    }
    
    if (!empty($missing)) {
        return [
            'passed' => false, 
            'message' => 'Prerequisites not met: ' . implode(', ', $missing),
            'missing' => $missing
        ];
    }
    
    return ['passed' => true, 'message' => 'All prerequisites met.', 'missing' => []];
}

/**
 * Get student's enrolled subjects for a term
 */
function getStudentEnrolledSubjects($studentId, $termId = null) {
    $query = "
        SELECT e.*, s.subjcode, s.`desc` as subject_name, s.unit, t.term_name
        FROM tbl_enroll e
        JOIN tbl_subjects s ON e.subject_id = s.id
        LEFT JOIN tbl_term t ON e.term_id = t.id
        WHERE e.student_id = ? AND e.status = 'enrolled'
    ";
    $params = [$studentId];
    
    if ($termId) {
        $query .= " AND e.term_id = ?";
        $params[] = $termId;
    }
    
    $query .= " ORDER BY s.subjcode";
    
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get total enrolled units for a student in a term
 */
function getTotalEnrolledUnits($studentId, $termId) {
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(s.unit), 0) as total_units
        FROM tbl_enroll e
        JOIN tbl_subjects s ON e.subject_id = s.id
        WHERE e.student_id = ? AND e.term_id = ? AND e.status = 'enrolled'
    ");
    $stmt->execute([$studentId, $termId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Enroll student in a subject
 */
function enrollStudent($studentId, $subjectId, $sectionId, $syId, $termId) {
    // Find teacher for this subject in student's section
    $teacherStmt = db()->prepare("
        SELECT teacher_id FROM tbl_teacher_subject 
        WHERE section_id = ? AND subject_id = ? AND sy_id = ? AND status = 'active'
    ");
    $teacherStmt->execute([$sectionId, $subjectId, $syId]);
    $teacherAssignment = $teacherStmt->fetch();
    $teacherId = $teacherAssignment ? $teacherAssignment['teacher_id'] : null;
    
    $stmt = db()->prepare("
        INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status, enrolled_at)
        VALUES (?, ?, ?, ?, ?, ?, 'enrolled', NOW())
    ");
    
    return $stmt->execute([$studentId, $subjectId, $sectionId, $teacherId, $syId, $termId]);
}

/**
 * Drop student from a subject
 */
function dropStudentFromSubject($studentId, $subjectId, $termId) {
    $stmt = db()->prepare("
        UPDATE tbl_enroll SET status = 'dropped', updated_at = NOW()
        WHERE student_id = ? AND subject_id = ? AND term_id = ? AND status = 'enrolled'
    ");
    $stmt->execute([$studentId, $subjectId, $termId]);
    return $stmt->rowCount() > 0;
}

/**
 * Get education level based on department code
 */
function getEducationLevel($deptCode) {
    $collegeDepts = ['CCTE', 'CON'];
    return in_array($deptCode, $collegeDepts) ? 'college' : 'k12';
}

/**
 * Format a person's name from available fields.
 * Supports arrays with 'name', 'full_name' or student fields 'given_name','middle_name','last_name'.
 */
function formatPersonName($row) {
    if (!$row || !is_array($row)) return '';
    if (!empty($row['name'])) return $row['name'];
    if (!empty($row['full_name'])) return $row['full_name'];
    $parts = [];
    if (!empty($row['given_name'])) $parts[] = $row['given_name'];
    if (!empty($row['middle_name'])) $parts[] = $row['middle_name'];
    if (!empty($row['last_name'])) $parts[] = $row['last_name'];
    if (!empty($parts)) return trim(implode(' ', $parts));
    return '';
}

/**
 * Get the current term (Semester 1, 2, or Summer) based on today's date.
 * Returns the term row if found, or null if no matching period.
 * 
 * @param int|null $syId School year ID (uses active if not provided)
 * @return array|null The current term record
 */
function getCurrentGradingPeriod($educationLevel = 'k12', $syId = null) {
    if (!$syId) {
        $sy = getActiveSchoolYear();
        $syId = $sy['id'] ?? 0;
    }
    
    $today = date('Y-m-d');
    
    $stmt = db()->prepare("
        SELECT t.*, sy.sy_name 
        FROM tbl_term t
        LEFT JOIN tbl_sy sy ON t.sy_id = sy.id
        WHERE t.sy_id = ? 
        AND t.status = 'active'
        AND t.start_date IS NOT NULL 
        AND t.end_date IS NOT NULL
        AND ? BETWEEN t.start_date AND t.end_date
        ORDER BY t.id ASC
        LIMIT 1
    ");
    $stmt->execute([$syId, $today]);
    return $stmt->fetch() ?: null;
}

/**
 * Get all terms for a school year (Semester 1, Semester 2, Summer).
 * 
 * @param string $educationLevel Unused, kept for backward compatibility
 * @param int|null $syId School year ID
 * @return array List of terms
 */
function getGradingPeriods($educationLevel = 'k12', $syId = null) {
    if (!$syId) {
        $sy = getActiveSchoolYear();
        $syId = $sy['id'] ?? 0;
    }
    
    $stmt = db()->prepare("
        SELECT t.*, sy.sy_name 
        FROM tbl_term t
        LEFT JOIN tbl_sy sy ON t.sy_id = sy.id
        WHERE t.sy_id = ? 
        AND t.status = 'active'
        ORDER BY t.start_date ASC, t.id ASC
    ");
    $stmt->execute([$syId]);
    return $stmt->fetchAll();
}

/**
 * Determine if a grading period is currently active for input
 * based on whether today's date falls within its date range.
 * 
 * @param int $termId The term/quarter ID
 * @return bool True if the period is currently active for grading
 */
function isGradingPeriodActive($termId) {
    $today = date('Y-m-d');
    
    $stmt = db()->prepare("
        SELECT id FROM tbl_term 
        WHERE id = ? 
        AND status = 'active'
        AND start_date IS NOT NULL 
        AND end_date IS NOT NULL
        AND ? BETWEEN start_date AND end_date
    ");
    $stmt->execute([$termId, $today]);
    return $stmt->fetch() !== false;
}

/**
 * Get grading period options based on education level and selected term.
 * 
 * Pre-Elem/Elem/JHS: Q1-Q4 (no semester selection — just school year)
 * SHS: Q1, Q2 per semester
 * College: PRELIM, MIDTERM, SEMIFINAL, FINAL per semester
 * 
 * @param string $educationLevel 'k12', 'shs', or 'college'
 * @param string $termName The selected term name (unused for K-12)
 * @return array Associative array of code => display name
 */
function getGradingPeriodOptions($educationLevel = 'k12', $termName = '') {
    if ($educationLevel === 'college') {
        return [
            'PRELIM' => 'Prelim',
            'MIDTERM' => 'Midterm',
            'SEMIFINAL' => 'Semi-Finals',
            'FINAL' => 'Finals'
        ];
    }

    if ($educationLevel === 'shs') {
        return [
            'Q1' => '1st Quarter',
            'Q2' => '2nd Quarter'
        ];
    }
    
    // K-12 (Pre-Elementary, Elementary, JHS)
    // K-10 has no terms/semesters — just Q1-Q4 across the full school year
    return [
        'Q1' => '1st Quarter',
        'Q2' => '2nd Quarter',
        'Q3' => '3rd Quarter',
        'Q4' => '4th Quarter'
    ];
}

/**
 * For K-10 (yearly enrollment): resolve a grading period code to the
 * correct semester term_id.  Q1/Q2 → Semester 1, Q3/Q4 → Semester 2.
 *
 * @param string $gradingPeriod e.g. 'Q1','Q2','Q3','Q4'
 * @param int    $syId          School-year ID
 * @return int|null  The matching term_id, or null if not found
 */
function resolveK10TermId($gradingPeriod, $syId) {
    $semName = in_array($gradingPeriod, ['Q1', 'Q2']) ? 'Semester 1' : 'Semester 2';
    $stmt = db()->prepare("SELECT id FROM tbl_term WHERE sy_id = ? AND term_name = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$syId, $semName]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

/**
 * Convert a grading period code to its display name.
 * 
 * @param string $code The grading period code (e.g., 'Q1', 'PRELIM')
 * @return string The human-readable name
 */
function getGradingPeriodName($code) {
    $allPeriods = [
        // K-12 quarters
        'Q1' => '1st Quarter',
        'Q2' => '2nd Quarter',
        'Q3' => '3rd Quarter',
        'Q4' => '4th Quarter',
        // College terms
        'PRELIM' => 'Prelim',
        'MIDTERM' => 'Midterm',
        'SEMIFINAL' => 'Semi-Finals',
        'FINAL' => 'Finals',
        // Legacy codes (for backward compatibility)
        'S1' => 'Semester 1',
        'S2' => 'Semester 2',
        'SUM' => 'Summer'
    ];
    
    return $allPeriods[$code] ?? ($code ?? 'N/A');
}

/**
 * Check if an education level uses yearly enrollment (K-12 quarters)
 * or semestral enrollment (College terms).
 * 
 * @param string $deptCode Department code
 * @return bool True if yearly (quarters), false if semestral (terms)
 */
function isYearlyEnrollment($deptCode) {
    $semestralDepts = ['SHS', 'CCTE', 'CON']; // Senior High and College departments
    return !in_array($deptCode, $semestralDepts);
}

/**
 * Get the college grading scale options (1.00 to 5.00)
 * Used for college/university grading (not K-12)
 * 
 * @return array Associative array of grade value => display info
 */
function getCollegeGradeScale() {
    return [
        '1.00' => ['min' => 99, 'max' => 100, 'label' => '1.00 (99-100)', 'remark' => 'Excellent'],
        '1.25' => ['min' => 96, 'max' => 98, 'label' => '1.25 (96-98)', 'remark' => 'Excellent'],
        '1.50' => ['min' => 93, 'max' => 95, 'label' => '1.50 (93-95)', 'remark' => 'Very Good'],
        '1.75' => ['min' => 90, 'max' => 92, 'label' => '1.75 (90-92)', 'remark' => 'Very Good'],
        '2.00' => ['min' => 87, 'max' => 89, 'label' => '2.00 (87-89)', 'remark' => 'Good'],
        '2.25' => ['min' => 84, 'max' => 86, 'label' => '2.25 (84-86)', 'remark' => 'Good'],
        '2.50' => ['min' => 81, 'max' => 83, 'label' => '2.50 (81-83)', 'remark' => 'Satisfactory'],
        '2.75' => ['min' => 78, 'max' => 80, 'label' => '2.75 (78-80)', 'remark' => 'Satisfactory'],
        '3.00' => ['min' => 75, 'max' => 77, 'label' => '3.00 (75-77)', 'remark' => 'Passing'],
        '4.00' => ['min' => null, 'max' => null, 'label' => '4.00 / INC', 'remark' => 'Incomplete'],
        '5.00' => ['min' => 0, 'max' => 74, 'label' => '5.00 (Below 75)', 'remark' => 'Failed'],
    ];
}

/**
 * Convert a percentage grade (0-100) to college grade scale (1.00-5.00)
 * 
 * @param float $percentGrade The percentage grade (0-100)
 * @return string The college grade (e.g., '1.00', '2.50', '5.00')
 */
function percentageToCollegeGrade($percentGrade) {
    if ($percentGrade >= 99) return '1.00';
    if ($percentGrade >= 96) return '1.25';
    if ($percentGrade >= 93) return '1.50';
    if ($percentGrade >= 90) return '1.75';
    if ($percentGrade >= 87) return '2.00';
    if ($percentGrade >= 84) return '2.25';
    if ($percentGrade >= 81) return '2.50';
    if ($percentGrade >= 78) return '2.75';
    if ($percentGrade >= 75) return '3.00';
    return '5.00';
}

/**
 * Convert a college grade (1.00-5.00) to its midpoint percentage
 * 
 * @param string $collegeGrade The college grade (e.g., '1.00', '2.50')
 * @return float The midpoint percentage for that grade range
 */
function collegeGradeToPercentage($collegeGrade) {
    $scale = getCollegeGradeScale();
    if (isset($scale[$collegeGrade])) {
        $info = $scale[$collegeGrade];
        if ($info['min'] !== null && $info['max'] !== null) {
            return ($info['min'] + $info['max']) / 2;
        }
        if ($collegeGrade === '4.00') return null; // INC
        if ($collegeGrade === '5.00') return 65; // Failed
    }
    return null;
}

/**
 * After a grade is approved, sync period grades and average into tbl_enroll.
 * Maps grading periods to q1_grade–q4_grade based on education level:
 *   K-10: Q1→q1, Q2→q2, Q3→q3, Q4→q4
 *   SHS:  Q1→q1, Q2→q2
 *   College: PRELIM→q1, MIDTERM→q2, SEMIFINAL→q3, FINAL→q4
 */
function updateEnrollmentGrades($enrollId) {
    $enrollId = (int)$enrollId;
    if ($enrollId <= 0) return;

    // Determine education level from enrollment's department
    $stmt = db()->prepare("
        SELECT d.education_level
        FROM tbl_enroll e
        JOIN tbl_section s ON s.id = e.section_id
        JOIN tbl_academic_track at2 ON at2.id = s.academic_track_id
        JOIN tbl_departments d ON d.id = at2.dept_id
        WHERE e.id = ?
    ");
    $stmt->execute([$enrollId]);
    $row = $stmt->fetch();
    if (!$row) return;

    $eduLevel = $row['education_level']; // 'k12', 'college', 'both'

    // Define which grading periods map to which columns
    if ($eduLevel === 'college') {
        $periodMap = ['PRELIM' => 'q1_grade', 'MIDTERM' => 'q2_grade', 'SEMIFINAL' => 'q3_grade', 'FINAL' => 'q4_grade'];
    } else {
        $periodMap = ['Q1' => 'q1_grade', 'Q2' => 'q2_grade', 'Q3' => 'q3_grade', 'Q4' => 'q4_grade'];
    }

    // Get all approved/finalized grades for this enrollment
    $stmt = db()->prepare("
        SELECT grading_period, period_grade
        FROM tbl_grades
        WHERE enroll_id = ? AND status IN ('approved', 'finalized')
    ");
    $stmt->execute([$enrollId]);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $values = ['q1_grade' => null, 'q2_grade' => null, 'q3_grade' => null, 'q4_grade' => null];
    foreach ($grades as $g) {
        $col = $periodMap[$g['grading_period']] ?? null;
        if ($col) {
            $values[$col] = $g['period_grade'];
        }
    }

    // Compute average from non-null period grades
    $nonNull = array_filter($values, fn($v) => $v !== null);
    $average = count($nonNull) > 0 ? round(array_sum($nonNull) / count($nonNull), 2) : null;

    $stmt = db()->prepare("
        UPDATE tbl_enroll SET
            q1_grade = ?, q2_grade = ?, q3_grade = ?, q4_grade = ?,
            average_grade = ?, final_grade = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $values['q1_grade'], $values['q2_grade'], $values['q3_grade'], $values['q4_grade'],
        $average, $average, $enrollId
    ]);
}

/**
 * Format a grade for display based on education level
 * K-12: Shows numeric grade (75-100)
 * College: Shows college grade scale (1.00-5.00)
 * 
 * @param float|null $grade The numeric grade
 * @param string $educationLevel 'k12' or 'college'
 * @param bool $showBoth For college, show both formats
 * @return string Formatted grade display
 */
function formatGradeDisplay($grade, $educationLevel = 'k12', $showBoth = false) {
    if ($grade === null) return '-';
    
    if ($educationLevel === 'college') {
        $collegeGrade = percentageToCollegeGrade($grade);
        if ($showBoth) {
            return $collegeGrade . ' (' . number_format($grade, 0) . ')';
        }
        return $collegeGrade;
    }
    
    return number_format($grade, 0);
}