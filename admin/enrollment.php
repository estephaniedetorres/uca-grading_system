<?php declare(strict_types=1);
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
requireRole('admin');

if (isset($_GET['action']) && $_GET['action'] === 'subjects') {
    header('Content-Type: application/json');

    // Convert warnings/notices to exceptions so AJAX always gets valid JSON.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {

    $curriculumId = (int)($_GET['curriculum_id'] ?? 0);
    $termId = (int)($_GET['term_id'] ?? 0);
    $levelId = (int)($_GET['level_id'] ?? 0);
    $enrollmentType = sanitize($_GET['enrollment_type'] ?? 'yearly');
    $studentNo = sanitize($_GET['student_no'] ?? '');
    $syId = (int)($_GET['sy_id'] ?? 0);
    $deptCode = strtoupper(sanitize($_GET['dept_code'] ?? ''));
    $isNoTermDept = ($enrollmentType === 'yearly') || in_array($deptCode, ['PRE-EL', 'ELE', 'JHS'], true);

    if ($isNoTermDept) {
        $enrollmentType = 'yearly';
        $termId = 0;
    }

    $studentId = 0;
    if ($studentNo !== '') {
        $studentStmt = db()->prepare("SELECT id FROM tbl_student WHERE student_no = ?");
        $studentStmt->execute([$studentNo]);
        $studentId = (int)($studentStmt->fetchColumn() ?: 0);
    }

    $subjects = [];
    if ($curriculumId > 0) {
        if ($enrollmentType === 'yearly') {
            $sql = "SELECT DISTINCT s.id, s.subjcode, s.`desc`, s.unit, s.type
                FROM tbl_prospectus p
                JOIN tbl_subjects s ON p.subject_id = s.id
                WHERE p.curriculum_id = ? AND p.status = 'active' AND s.status = 'active'";
            $params = [$curriculumId];
            if ($levelId > 0) {
                // Include level-neutral prospectus rows so legacy prospectus data still loads.
                $sql .= " AND (p.level_id = ? OR p.level_id IS NULL)";
                $params[] = $levelId;
            }
            $sql .= " ORDER BY s.subjcode";
            $subjectsStmt = db()->prepare($sql);
            $subjectsStmt->execute($params);
            $subjects = $subjectsStmt->fetchAll();
        } elseif ($termId > 0) {
            $sql = "SELECT DISTINCT s.id, s.subjcode, s.`desc`, s.unit, s.type, t.term_name
                FROM tbl_prospectus p
                JOIN tbl_subjects s ON p.subject_id = s.id
                JOIN tbl_term t ON p.term_id = t.id
                WHERE p.curriculum_id = ? AND p.term_id = ? AND p.status = 'active' AND s.status = 'active'";
            $params = [$curriculumId, $termId];
            if ($levelId > 0) {
                // Include level-neutral prospectus rows so legacy prospectus data still loads.
                $sql .= " AND (p.level_id = ? OR p.level_id IS NULL)";
                $params[] = $levelId;
            }
            $sql .= " ORDER BY s.subjcode";
            $subjectsStmt = db()->prepare($sql);
            $subjectsStmt->execute($params);
            $subjects = $subjectsStmt->fetchAll();
        }
    }

    // Fallback: if prospectus has no subjects, use teacher_subject assignments for the section
    if (empty($subjects)) {
        $sectionId = (int)($_GET['section_id'] ?? 0);
        if ($sectionId > 0 && $syId > 0) {
            $tsFallback = db()->prepare("
                SELECT DISTINCT s.id, s.subjcode, s.`desc`, s.unit, s.type
                FROM tbl_teacher_subject ts
                JOIN tbl_subjects s ON ts.subject_id = s.id
                WHERE ts.section_id = ? AND ts.sy_id = ? AND ts.status = 'active' AND s.status = 'active'
                ORDER BY s.subjcode
            ");
            $tsFallback->execute([$sectionId, $syId]);
            $subjects = $tsFallback->fetchAll();
        }
    }

    $subjects = uniqueSubjectsById($subjects);

    $enrolledIds = [];
    if ($studentId > 0) {
        if ($enrollmentType === 'yearly' && $syId > 0) {
            $enrolledStmt = db()->prepare("
                SELECT subject_id FROM tbl_enroll
                WHERE student_id = ? AND sy_id = ? AND term_id IS NULL AND status = 'enrolled'
            ");
            $enrolledStmt->execute([$studentId, $syId]);
            $enrolledIds = $enrolledStmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($termId > 0) {
            $enrolledStmt = db()->prepare("
                SELECT subject_id FROM tbl_enroll
                WHERE student_id = ? AND term_id = ? AND status = 'enrolled'
            ");
            $enrolledStmt->execute([$studentId, $termId]);
            $enrolledIds = $enrolledStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $totalUnits = 0;
    foreach ($subjects as $subject) {
        $totalUnits += (int)($subject['unit'] ?? 0);
    }

    // Get level name for the section
    $levelName = '';
    if ($levelId > 0) {
        $lvStmt = db()->prepare("SELECT code AS level_code, description AS level_desc FROM level WHERE id = ?");
        $lvStmt->execute([$levelId]);
        $lvRow = $lvStmt->fetch();
        if ($lvRow) {
            $levelName = $lvRow['level_code'] . ' - ' . $lvRow['level_desc'];
        }
    }

    echo json_encode([
        'subjects' => $subjects,
        'enrolledIds' => $enrolledIds,
        'totalUnits' => $totalUnits,
        'enrollmentType' => $enrollmentType,
        'levelName' => $levelName
    ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'subjects' => [],
            'enrolledIds' => [],
            'totalUnits' => 0,
            'enrollmentType' => 'yearly',
            'levelName' => '',
            'error' => $e->getMessage()
        ]);
    } finally {
        restore_error_handler();
    }
    exit;
}

// CSV template download
if (isset($_GET['action']) && $_GET['action'] === 'csv_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="enrollment_template.csv"');
    $out = fopen('php://output', 'w');
    $studentNumberPrefix = getStudentNumberPrefixForSchoolYearId();
    fputcsv($out, ['student_no', 'given_name', 'middle_name', 'last_name', 'suffix', 'level_code', 'section_code', 'course_code', 'curriculum', 'term']);
    fputcsv($out, [$studentNumberPrefix . '-00100', 'Juan', 'Santos', 'Dela Cruz', '', '', '', '', '', '']);
    fputcsv($out, ['', 'Maria', 'Lopez', 'Reyes', 'Jr.', '1ST YEAR', 'BSCS-1A', 'BSCS', 'BSCS Curriculum 2025', 'Semester 1']);
    fputcsv($out, ['', 'Jose', 'Cruz', 'Garcia', '', 'NURSERY', 'NURSERY-A', 'NURSERY', 'Nursery Curriculum 2025', '']);
    fclose($out);
    exit;
}

// Helper: resolve section/curriculum/term for a CSV row, using per-row values or dropdown defaults
function resolveRowEnrollmentContext(string $csvLevelCode, string $csvSectionCode, string $csvCourseCode, string $csvCurriculum, string $csvTerm, int $defaultLevelId, int $defaultSectionId, int $defaultCurriculumId, int $defaultTermId): array {
    $levelId = $defaultLevelId;
    $sectionId = $defaultSectionId;
    $curriculumId = $defaultCurriculumId;
    $termId = $defaultTermId;

    // Resolve level from CSV level_code (accepts level code or description)
    if ($csvLevelCode !== '') {
        $stmt = db()->prepare("SELECT id FROM level WHERE UPPER(code) = UPPER(?) OR UPPER(description) = UPPER(?) LIMIT 1");
        $stmt->execute([$csvLevelCode, $csvLevelCode]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            $levelId = $id;
        } else {
            return ['error' => "Level '{$csvLevelCode}' not found"];
        }
    }

    // Resolve section from CSV section_code
    if ($csvSectionCode !== '') {
        if ($levelId > 0) {
            $stmt = db()->prepare("
                SELECT s.id FROM tbl_section s
                WHERE s.section_code = ? AND s.level_id = ? AND s.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$csvSectionCode, $levelId]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                $sectionId = $id;
            } else {
                return ['error' => "Section '{$csvSectionCode}' not found for selected level"];
            }
        } else {
            $stmt = db()->prepare("
                SELECT s.id FROM tbl_section s WHERE s.section_code = ? AND s.status = 'active'
            ");
            $stmt->execute([$csvSectionCode]);
            $matches = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($matches) === 1) {
                $sectionId = (int)$matches[0];
            } elseif (count($matches) > 1) {
                return ['error' => "Multiple sections found for '{$csvSectionCode}'. Specify level_code."];
            } else {
                return ['error' => "Section '{$csvSectionCode}' not found"];
            }
        }
    }

    // Resolve curriculum from CSV curriculum name
    if ($csvCurriculum !== '') {
        $stmt = db()->prepare("SELECT id FROM tbl_curriculum WHERE curriculum = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$csvCurriculum]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) $curriculumId = $id;
        else return ['error' => "Curriculum '{$csvCurriculum}' not found"];
    }

    // Resolve term from CSV term name (e.g. 'Semester 1')
    if ($csvTerm !== '') {
        // Get sy_id from section
        $syStmt = db()->prepare("SELECT sy_id FROM tbl_section WHERE id = ? LIMIT 1");
        $syStmt->execute([$sectionId]);
        $rowSyId = (int)($syStmt->fetchColumn() ?: 0);
        $stmt = db()->prepare("SELECT id FROM tbl_term WHERE term_name = ? AND sy_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$csvTerm, $rowSyId]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) $termId = $id;
        else return ['error' => "Term '{$csvTerm}' not found"];
    }

    if ($levelId === 0) return ['error' => 'No level specified (set dropdown or CSV column level_code)'];
    if ($sectionId === 0) return ['error' => 'No section specified (set dropdown or CSV column section_code)'];
    if ($curriculumId === 0) return ['error' => 'No curriculum specified (set dropdown or CSV column)'];

    // Validate section
    $secStmt = db()->prepare("
        SELECT s.*, at.id as course_id, at.enrollment_type, at.dept_id, d.code as dept_code, s.level_id
        FROM tbl_section s
        JOIN tbl_academic_track at ON s.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        WHERE s.id = ? LIMIT 1
    ");
    $secStmt->execute([$sectionId]);
    $sectionInfo = $secStmt->fetch();
    if (!$sectionInfo) return ['error' => 'Invalid section'];
    if ((int)($sectionInfo['level_id'] ?? 0) !== $levelId) {
        return ['error' => 'Selected level does not match section'];
    }

    $curStmt = db()->prepare("SELECT * FROM tbl_curriculum WHERE id = ? LIMIT 1");
    $curStmt->execute([$curriculumId]);
    $curriculumInfo = $curStmt->fetch();
    if (!$curriculumInfo) return ['error' => 'Invalid curriculum'];

    if ((int)$curriculumInfo['academic_track_id'] !== (int)$sectionInfo['course_id']) {
        return ['error' => 'Curriculum does not match section course'];
    }

    $enrollmentType = $sectionInfo['enrollment_type'] ?? 'yearly';
    $syId = (int)($sectionInfo['sy_id'] ?? 0);
    $deptCode = strtoupper($sectionInfo['dept_code'] ?? '');
    $isNoTermDept = ($enrollmentType === 'yearly') || in_array($deptCode, ['PRE-EL', 'ELE', 'JHS'], true);
    $resolvedTermId = $isNoTermDept ? null : ($termId ?: null);

    if (!$isNoTermDept && !$resolvedTermId) {
        return ['error' => 'Term required for this department'];
    }

    return [
        'level_id' => $levelId,
        'section_id' => $sectionId,
        'curriculum_id' => $curriculumId,
        'term_id' => $resolvedTermId,
        'sy_id' => $syId,
        'is_no_term_dept' => $isNoTermDept,
    ];
}

// CSV bulk upload processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_upload') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'results' => []];

    $defaultLevelId = (int)($_POST['upload_level_id'] ?? 0);
    $defaultSectionId = (int)($_POST['upload_section_id'] ?? 0);
    $defaultCurriculumId = (int)($_POST['upload_curriculum_id'] ?? 0);
    $defaultTermId = (int)($_POST['upload_term_id'] ?? 0);

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $response['message'] = 'Please upload a valid CSV file.';
        echo json_encode($response);
        exit;
    }

    $file = $_FILES['csv_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        $response['message'] = 'Only CSV files are accepted.';
        echo json_encode($response);
        exit;
    }

    // Parse CSV
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $response['message'] = 'Failed to read uploaded file.';
        echo json_encode($response);
        exit;
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        $response['message'] = 'CSV file is empty.';
        echo json_encode($response);
        exit;
    }

    // Normalize header names
    $header = array_map(function ($h) {
        return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h)));
    }, $header);

    $requiredCols = ['given_name', 'last_name'];
    $missing = array_diff($requiredCols, $header);
    if (!empty($missing)) {
        fclose($handle);
        $response['message'] = 'Missing required columns: ' . implode(', ', $missing);
        echo json_encode($response);
        exit;
    }

    $colMap = array_flip($header);
    $hasSectionCol = isset($colMap['section_code']);
    $hasLevelCol = isset($colMap['level_code']);
    $hasCourseCol = isset($colMap['course_code']);
    $hasCurriculumCol = isset($colMap['curriculum']);
    $hasTermCol = isset($colMap['term']);

    // If CSV has no per-row columns and no dropdowns selected, require dropdowns
    if (!$hasLevelCol && $defaultLevelId === 0) {
        fclose($handle);
        $response['message'] = 'Select a default level or include level_code in CSV.';
        echo json_encode($response);
        exit;
    }
    if (!$hasSectionCol && $defaultSectionId === 0) {
        fclose($handle);
        $response['message'] = 'Select a default section or include section_code in CSV.';
        echo json_encode($response);
        exit;
    }
    if (!$hasCurriculumCol && $defaultCurriculumId === 0) {
        fclose($handle);
        $response['message'] = 'Select a default curriculum or include curriculum in CSV.';
        echo json_encode($response);
        exit;
    }

    $results = [];
    $successCount = 0;
    $errorCount = 0;
    $row = 1;

    while (($data = fgetcsv($handle)) !== false) {
        $row++;
        if (count($data) < count($requiredCols)) {
            $results[] = ['row' => $row, 'student_no' => '', 'status' => 'error', 'message' => 'Incomplete row'];
            $errorCount++;
            continue;
        }

        $csvStudentNo = trim($data[$colMap['student_no']] ?? '');
        $csvGivenName = trim($data[$colMap['given_name']] ?? '');
        $csvMiddleName = isset($colMap['middle_name']) ? trim($data[$colMap['middle_name']] ?? '') : '';
        $csvLastName = trim($data[$colMap['last_name']] ?? '');
        $csvSuffix = isset($colMap['suffix']) ? trim($data[$colMap['suffix']] ?? '') : '';

        // Per-row overrides (empty = use dropdown default)
        $csvLevelCode = $hasLevelCol ? trim($data[$colMap['level_code']] ?? '') : '';
        $csvSectionCode = $hasSectionCol ? trim($data[$colMap['section_code']] ?? '') : '';
        $csvCourseCode = $hasCourseCol ? trim($data[$colMap['course_code']] ?? '') : '';
        $csvCurriculum = $hasCurriculumCol ? trim($data[$colMap['curriculum']] ?? '') : '';
        $csvTerm = $hasTermCol ? trim($data[$colMap['term']] ?? '') : '';

        if ($csvGivenName === '' || $csvLastName === '') {
            $results[] = ['row' => $row, 'student_no' => $csvStudentNo, 'status' => 'error', 'message' => 'Missing required fields'];
            $errorCount++;
            continue;
        }

        // Resolve enrollment context for this row
        $ctx = resolveRowEnrollmentContext($csvLevelCode, $csvSectionCode, $csvCourseCode, $csvCurriculum, $csvTerm, $defaultLevelId, $defaultSectionId, $defaultCurriculumId, $defaultTermId);
        if (isset($ctx['error'])) {
            $results[] = ['row' => $row, 'student_no' => $csvStudentNo, 'status' => 'error', 'message' => $ctx['error']];
            $errorCount++;
            continue;
        }

        $rowSectionId = $ctx['section_id'];
        $rowCurriculumId = $ctx['curriculum_id'];
        $rowTermId = $ctx['term_id'];
        $rowSyId = $ctx['sy_id'];
        $rowIsNoTerm = $ctx['is_no_term_dept'];
        $rowLevelId = $ctx['level_id'];

        if ($csvStudentNo === '') {
            $csvStudentNo = generateNextStudentNumber($rowSyId);
        }

        // Get subjects from prospectus for this row
        if ($rowIsNoTerm) {
            $sql = "SELECT DISTINCT s.id as subject_id
                FROM tbl_prospectus p JOIN tbl_subjects s ON p.subject_id = s.id
                WHERE p.curriculum_id = ? AND p.status = 'active' AND s.status = 'active'";
            $params = [$rowCurriculumId];
            if ($rowLevelId > 0) {
                // Include level-neutral prospectus rows so CSV upload works with mixed prospectus data.
                $sql .= " AND (p.level_id = ? OR p.level_id IS NULL)";
                $params[] = $rowLevelId;
            }
            $subjectsStmt = db()->prepare($sql);
            $subjectsStmt->execute($params);
        } else {
            $sql = "SELECT DISTINCT s.id as subject_id
                FROM tbl_prospectus p JOIN tbl_subjects s ON p.subject_id = s.id
                WHERE p.curriculum_id = ? AND p.term_id = ? AND p.status = 'active' AND s.status = 'active'";
            $params = [$rowCurriculumId, $rowTermId];
            if ($rowLevelId > 0) {
                // Include level-neutral prospectus rows so CSV upload works with mixed prospectus data.
                $sql .= " AND (p.level_id = ? OR p.level_id IS NULL)";
                $params[] = $rowLevelId;
            }
            $subjectsStmt = db()->prepare($sql);
            $subjectsStmt->execute($params);
        }
        $subjects = $subjectsStmt->fetchAll();

        // Fallback: if prospectus has no subjects, use teacher_subject assignments for the section
        if (empty($subjects)) {
            $tsFallback = db()->prepare("
                SELECT DISTINCT ts.subject_id
                FROM tbl_teacher_subject ts
                JOIN tbl_subjects s ON ts.subject_id = s.id
                WHERE ts.section_id = ? AND ts.sy_id = ? AND ts.status = 'active' AND s.status = 'active'
            ");
            $tsFallback->execute([$rowSectionId, $rowSyId]);
            $subjects = $tsFallback->fetchAll();
        }

        try {
            db()->beginTransaction();

            // Check if student already exists
            $existStmt = db()->prepare("SELECT id FROM tbl_student WHERE student_no = ? LIMIT 1");
            $existStmt->execute([$csvStudentNo]);
            $existingStudent = $existStmt->fetch();
            $createdAccount = false;

            if ($existingStudent) {
                $studentId = (int)$existingStudent['id'];
                // Update section assignment
                $updateStmt = db()->prepare("UPDATE tbl_student SET section_id = ? WHERE id = ?");
                $updateStmt->execute([$rowSectionId, $studentId]);
            } else {
                // Create user account
                $userStmt = db()->prepare("SELECT id FROM tbl_users WHERE username = ? LIMIT 1");
                $userStmt->execute([$csvStudentNo]);
                $userId = (int)($userStmt->fetchColumn() ?: 0);

                if ($userId === 0) {
                    $insertUserStmt = db()->prepare("INSERT INTO tbl_users (username, password, role, status) VALUES (?, ?, 'student', 'active')");
                    $insertUserStmt->execute([$csvStudentNo, hashPassword('studentpass')]);
                    $userId = (int)db()->lastInsertId();
                    $createdAccount = true;
                }

                $insertStudentStmt = db()->prepare("
                    INSERT INTO tbl_student (user_id, student_no, given_name, middle_name, last_name, suffix, section_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStudentStmt->execute([$userId, $csvStudentNo, $csvGivenName, $csvMiddleName, $csvLastName, $csvSuffix, $rowSectionId]);
                $studentId = (int)db()->lastInsertId();
            }

            // Enroll in subjects
            $enrolledCount = 0;
            $alreadyCount = 0;
            foreach ($subjects as $subject) {
                $subjectId = (int)$subject['subject_id'];
                if ($rowIsNoTerm) {
                    $checkStmt = db()->prepare("SELECT id FROM tbl_enroll WHERE student_id = ? AND subject_id = ? AND sy_id = ? AND term_id IS NULL AND status = 'enrolled'");
                    $checkStmt->execute([$studentId, $subjectId, $rowSyId]);
                } else {
                    $checkStmt = db()->prepare("SELECT id FROM tbl_enroll WHERE student_id = ? AND subject_id = ? AND term_id = ? AND status = 'enrolled'");
                    $checkStmt->execute([$studentId, $subjectId, $rowTermId]);
                }
                if ($checkStmt->fetch()) {
                    $alreadyCount++;
                    continue;
                }
                if (enrollStudent($studentId, $subjectId, $rowSectionId, $rowSyId, $rowTermId)) {
                    $enrolledCount++;
                }
            }

            db()->commit();

            $msg = "Enrolled in {$enrolledCount} subject(s)";
            if ($alreadyCount > 0) $msg .= ", {$alreadyCount} already enrolled";
            if ($createdAccount) $msg .= ' (new account created)';
            $results[] = ['row' => $row, 'student_no' => $csvStudentNo, 'status' => 'success', 'message' => $msg];
            $successCount++;
        } catch (Exception $e) {
            db()->rollBack();
            $results[] = [
                'row' => $row,
                'student_no' => $csvStudentNo,
                'status' => 'error',
                'message' => 'Failed to process: ' . $e->getMessage()
            ];
            $errorCount++;
        }
    }

    fclose($handle);

    // Post-enrollment: link teacher_id on all enrolled records where teacher_id is NULL
    // and a matching tbl_teacher_subject assignment exists
    $linkedTeachers = 0;
    $linkStmt = db()->prepare("
        UPDATE tbl_enroll e
        JOIN tbl_teacher_subject ts
            ON ts.section_id = e.section_id
            AND ts.subject_id = e.subject_id
            AND ts.sy_id = e.sy_id
            AND ts.status = 'active'
        SET e.teacher_id = ts.teacher_id
        WHERE e.teacher_id IS NULL AND e.status = 'enrolled'
    ");
    $linkStmt->execute();
    $linkedTeachers = $linkStmt->rowCount();

    // Count enrollments still missing teacher assignment
    $missingStmt = db()->prepare("SELECT COUNT(*) FROM tbl_enroll WHERE teacher_id IS NULL AND status = 'enrolled'");
    $missingStmt->execute();
    $missingTeachers = (int)$missingStmt->fetchColumn();

    $response['success'] = $successCount > 0;
    $summaryMsg = "{$successCount} student(s) enrolled successfully";
    if ($errorCount > 0) $summaryMsg .= ", {$errorCount} error(s)";
    if ($linkedTeachers > 0) $summaryMsg .= ". {$linkedTeachers} enrollment(s) linked to teachers.";
    if ($missingTeachers > 0) $summaryMsg .= " Warning: {$missingTeachers} enrollment(s) have no teacher assigned — assign teachers via Dean > Teacher Subject Management.";
    $response['message'] = $summaryMsg;
    $response['results'] = $results;
    echo json_encode($response);
    exit;
}

$pageTitle = 'Enrollment';
$message = '';
$messageType = '';

$studentNo = sanitize($_POST['student_no'] ?? $_GET['student_no'] ?? '');
$selectedDeptId = (int)($_POST['dept_id'] ?? $_GET['dept_id'] ?? 0);
$selectedCourseId = (int)($_POST['course_id'] ?? $_GET['course_id'] ?? 0);
$selectedLevelId = (int)($_POST['level_id'] ?? $_GET['level_id'] ?? 0);
$selectedSectionId = (int)($_POST['section_id'] ?? $_GET['section_id'] ?? 0);
$selectedCurriculumId = (int)($_POST['curriculum_id'] ?? $_GET['curriculum_id'] ?? 0);
$selectedTermId = (int)($_POST['term_id'] ?? $_GET['term_id'] ?? 0);

$studentInfo = null;
if ($studentNo !== '') {
    $studentStmt = db()->prepare("
         SELECT st.*, st.student_no, sec.id as section_id, sec.section_code, sec.academic_track_id,
             sec.level_id,
               sec.sy_id, at.id as course_id, at.code as course_code, at.`desc` as course_name,
               at.enrollment_type, at.dept_id, d.code as dept_code, d.description as dept_desc,
               c.id as curriculum_id, c.curriculum as curriculum_name
        FROM tbl_student st
        LEFT JOIN tbl_section sec ON st.section_id = sec.id
        LEFT JOIN tbl_academic_track at ON sec.academic_track_id = at.id
        LEFT JOIN tbl_departments d ON at.dept_id = d.id
        LEFT JOIN tbl_curriculum c ON c.academic_track_id = at.id AND c.status = 'active'
        WHERE st.student_no = ?
        LIMIT 1
    ");
    $studentStmt->execute([$studentNo]);
    $studentInfo = $studentStmt->fetch();

    if ($studentInfo) {
        $selectedDeptId = $selectedDeptId ?: (int)($studentInfo['dept_id'] ?? 0);
        $selectedCourseId = $selectedCourseId ?: (int)($studentInfo['course_id'] ?? 0);
        $selectedLevelId = $selectedLevelId ?: (int)($studentInfo['level_id'] ?? 0);
        $selectedSectionId = $selectedSectionId ?: (int)($studentInfo['section_id'] ?? 0);
        $selectedCurriculumId = $selectedCurriculumId ?: (int)($studentInfo['curriculum_id'] ?? 0);
    }
}

$givenName = sanitize($_POST['given_name'] ?? ($studentInfo['given_name'] ?? ''));
$middleName = sanitize($_POST['middle_name'] ?? ($studentInfo['middle_name'] ?? ''));
$lastName = sanitize($_POST['last_name'] ?? ($studentInfo['last_name'] ?? ''));
$suffix = sanitize($_POST['suffix'] ?? ($studentInfo['suffix'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enroll') {
    if ($selectedLevelId === 0 || $selectedSectionId === 0 || $selectedCurriculumId === 0) {
        $message = 'Level, section, and curriculum are required.';
        $messageType = 'error';
    } else {
        $sectionStmt = db()->prepare("
            SELECT s.*, at.id as course_id, at.enrollment_type, at.dept_id, d.code as dept_code, s.level_id
            FROM tbl_section s
            JOIN tbl_academic_track at ON s.academic_track_id = at.id
            LEFT JOIN tbl_departments d ON at.dept_id = d.id
            WHERE s.id = ?
            LIMIT 1
        ");
        $sectionStmt->execute([$selectedSectionId]);
        $sectionInfo = $sectionStmt->fetch();

        $curriculumStmt = db()->prepare("SELECT * FROM tbl_curriculum WHERE id = ? LIMIT 1");
        $curriculumStmt->execute([$selectedCurriculumId]);
        $curriculumInfo = $curriculumStmt->fetch();

        if (!$sectionInfo || !$curriculumInfo) {
            $message = 'Invalid section or curriculum selection.';
            $messageType = 'error';
        } elseif ((int)($sectionInfo['level_id'] ?? 0) !== $selectedLevelId) {
            $message = 'Selected level does not match the selected section.';
            $messageType = 'error';
        } elseif ((int)$curriculumInfo['academic_track_id'] !== (int)$sectionInfo['course_id']) {
            $message = 'Curriculum does not match the selected course.';
            $messageType = 'error';
        } else {
            $enrollmentType = $sectionInfo['enrollment_type'] ?? 'yearly';
            $syId = (int)($sectionInfo['sy_id'] ?? 0);
            $deptCode = strtoupper($sectionInfo['dept_code'] ?? '');
            $sectionLevelId = (int)($sectionInfo['level_id'] ?? 0);
            $isNoTermDept = ($enrollmentType === 'yearly') || in_array($deptCode, ['PRE-EL', 'ELE', 'JHS'], true);
            $termId = $isNoTermDept ? null : ($selectedTermId ?: null);

            if ($studentNo === '') {
                $studentNo = $studentInfo['student_no'] ?? generateNextStudentNumber($syId);
            }

            if (!$isNoTermDept && !$termId) {
                $message = 'Please select a term for enrollment.';
                $messageType = 'error';
            } else {
                try {
                    db()->beginTransaction();

                    $createdAccount = false;
                    if (!$studentInfo) {
                        $userStmt = db()->prepare("SELECT id FROM tbl_users WHERE username = ? LIMIT 1");
                        $userStmt->execute([$studentNo]);
                        $userId = (int)($userStmt->fetchColumn() ?: 0);

                        if ($userId === 0) {
                            $insertUserStmt = db()->prepare("INSERT INTO tbl_users (username, password, role, status) VALUES (?, ?, 'student', 'active')");
                            $insertUserStmt->execute([$studentNo, hashPassword('studentpass')]);
                            $userId = (int)db()->lastInsertId();
                            $createdAccount = true;
                        }

                        $insertStudentStmt = db()->prepare("
                            INSERT INTO tbl_student (user_id, student_no, given_name, middle_name, last_name, suffix, section_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insertStudentStmt->execute([
                            $userId,
                            $studentNo,
                            $givenName,
                            $middleName,
                            $lastName,
                            $suffix,
                            $selectedSectionId
                        ]);
                        $studentId = (int)db()->lastInsertId();
                    } else {
                        $updateStmt = db()->prepare("
                            UPDATE tbl_student
                            SET student_no = ?, given_name = ?, middle_name = ?, last_name = ?, suffix = ?, section_id = ?
                            WHERE id = ?
                        ");
                        $updateStmt->execute([
                            $studentNo,
                            $givenName,
                            $middleName,
                            $lastName,
                            $suffix,
                            $selectedSectionId,
                            $studentInfo['id']
                        ]);
                        $studentId = (int)$studentInfo['id'];
                    }

                    if ($isNoTermDept) {
                        $sql = "SELECT DISTINCT s.id as subject_id
                            FROM tbl_prospectus p
                            JOIN tbl_subjects s ON p.subject_id = s.id
                            WHERE p.curriculum_id = ? AND p.status = 'active' AND s.status = 'active'";
                        $params = [$selectedCurriculumId];
                        if ($sectionLevelId > 0) {
                            // Include level-neutral prospectus rows so manual enrollment remains compatible.
                            $sql .= " AND (p.level_id = ? OR p.level_id IS NULL)";
                            $params[] = $sectionLevelId;
                        }
                        $subjectsStmt = db()->prepare($sql);
                        $subjectsStmt->execute($params);
                    } else {
                        $sql = "SELECT DISTINCT s.id as subject_id
                            FROM tbl_prospectus p
                            JOIN tbl_subjects s ON p.subject_id = s.id
                            WHERE p.curriculum_id = ? AND p.term_id = ? AND p.status = 'active' AND s.status = 'active'";
                        $params = [$selectedCurriculumId, $termId];
                        if ($sectionLevelId > 0) {
                            // Include level-neutral prospectus rows so manual enrollment remains compatible.
                            $sql .= " AND (p.level_id = ? OR p.level_id IS NULL)";
                            $params[] = $sectionLevelId;
                        }
                        $subjectsStmt = db()->prepare($sql);
                        $subjectsStmt->execute($params);
                    }

                    $subjects = $subjectsStmt->fetchAll();

                    // Fallback: if prospectus has no subjects, use teacher_subject assignments for the section
                    if (empty($subjects)) {
                        $tsFallback = db()->prepare("
                            SELECT DISTINCT ts.subject_id
                            FROM tbl_teacher_subject ts
                            JOIN tbl_subjects s ON ts.subject_id = s.id
                            WHERE ts.section_id = ? AND ts.sy_id = ? AND ts.status = 'active' AND s.status = 'active'
                        ");
                        $tsFallback->execute([$selectedSectionId, $syId]);
                        $subjects = $tsFallback->fetchAll();
                    }
                    $successCount = 0;
                    $alreadyEnrolled = 0;

                    foreach ($subjects as $subject) {
                        $subjectId = (int)$subject['subject_id'];
                        if ($isNoTermDept) {
                            $checkStmt = db()->prepare("
                                SELECT id FROM tbl_enroll
                                WHERE student_id = ? AND subject_id = ? AND sy_id = ? AND term_id IS NULL AND status = 'enrolled'
                            ");
                            $checkStmt->execute([$studentId, $subjectId, $syId]);
                        } else {
                            $checkStmt = db()->prepare("
                                SELECT id FROM tbl_enroll
                                WHERE student_id = ? AND subject_id = ? AND term_id = ? AND status = 'enrolled'
                            ");
                            $checkStmt->execute([$studentId, $subjectId, $termId]);
                        }

                        if ($checkStmt->fetch()) {
                            $alreadyEnrolled++;
                            continue;
                        }

                        if (enrollStudent($studentId, $subjectId, $selectedSectionId, $syId, $termId)) {
                            $successCount++;
                        }
                    }

                    db()->commit();

                    // Post-enrollment: link teacher_id on enrolled records where teacher_id is NULL
                    $linkStmt = db()->prepare("
                        UPDATE tbl_enroll e
                        JOIN tbl_teacher_subject ts
                            ON ts.section_id = e.section_id
                            AND ts.subject_id = e.subject_id
                            AND ts.sy_id = e.sy_id
                            AND ts.status = 'active'
                        SET e.teacher_id = ts.teacher_id
                        WHERE e.student_id = ? AND e.teacher_id IS NULL AND e.status = 'enrolled'
                    ");
                    $linkStmt->execute([$studentId]);

                    if ($successCount > 0) {
                        $message = "Successfully enrolled in {$successCount} subject(s).";
                        if ($createdAccount) {
                            $message .= ' Account created with username ' . $studentNo . ' and default password studentpass.';
                        }
                        $messageType = 'success';
                    } elseif ($alreadyEnrolled > 0) {
                        $message = 'Student is already enrolled in these subjects.';
                        $messageType = 'info';
                    } else {
                        $message = 'No subjects available for enrollment.';
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    db()->rollBack();
                    $message = 'Enrollment failed: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
}

$departments = db()->query("SELECT * FROM tbl_departments WHERE status = 'active' ORDER BY code")->fetchAll();
$courses = db()->query("SELECT * FROM tbl_academic_track WHERE status = 'active' ORDER BY code")->fetchAll();
$levels = db()->query("SELECT * FROM level ORDER BY code")->fetchAll();
$sections = db()->query("
    SELECT s.*, at.id as course_id, at.code as course_code, at.`desc` as course_name,
           at.enrollment_type, at.dept_id, d.code as dept_code, d.description as dept_desc,
           sy.sy_name, s.level_id, lv.code as level_code, lv.description as level_desc
    FROM tbl_section s
    JOIN tbl_academic_track at ON s.academic_track_id = at.id
    LEFT JOIN tbl_departments d ON at.dept_id = d.id
    LEFT JOIN tbl_sy sy ON s.sy_id = sy.id
    LEFT JOIN level lv ON s.level_id = lv.id
    WHERE s.status = 'active'
    ORDER BY s.section_code
")->fetchAll();
$curricula = db()->query("SELECT * FROM tbl_curriculum WHERE status = 'active' ORDER BY curriculum")->fetchAll();
$deptById = [];
foreach ($departments as $dept) {
    $deptById[(int)$dept['id']] = $dept['code'];
}
$terms = db()->query("
        SELECT * FROM tbl_term
        WHERE status = 'active'
            AND (term_name LIKE 'Semester%' OR term_name = 'Summer')
        ORDER BY id
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar_admin.php';
?>

<main class="main-content lg:ml-64 min-h-screen">
    <div class="bg-white border-b border-gray-200 px-4 sm:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-800">Enrollment</h1>
                <p class="text-sm text-gray-500">Registrar-managed student enrollment</p>
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
        <div class="alert-auto-hide mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-800' : ($messageType === 'info' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="flex border-b border-gray-200 mb-6">
            <button type="button" id="tabManual" onclick="switchTab('manual')" class="px-5 py-3 text-sm font-medium border-b-2 border-black text-gray-800 transition">
                Manual Enroll
            </button>
            <button type="button" id="tabUpload" onclick="switchTab('upload')" class="px-5 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 transition">
                Upload Students (CSV)
            </button>
        </div>

        <!-- Manual Enroll Tab -->
        <div id="panelManual">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <form method="GET" class="flex flex-col sm:flex-row sm:items-end gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Student Number</label>
                    <input type="text" name="student_no" value="<?= htmlspecialchars($studentNo) ?>" placeholder="Enter student no" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" required>
                </div>
                <button type="submit" class="px-5 py-2 bg-black text-white rounded-lg hover:bg-neutral-800 transition">
                    Find Student
                </button>
            </form>
            <?php if ($studentNo !== '' && !$studentInfo): ?>
            <p class="text-sm text-red-600 mt-3">No student found with that number.</p>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <form method="POST" id="enrollmentForm">
                <input type="hidden" name="action" value="enroll">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Student Number</label>
                        <input type="text" id="student_no" name="student_no" value="<?= htmlspecialchars($studentNo) ?>" placeholder="Auto-generated if blank (e.g., 26-00001)" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Given Name</label>
                        <input type="text" name="given_name" value="<?= htmlspecialchars($givenName) ?>" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Middle Name (optional)</label>
                        <input type="text" name="middle_name" value="<?= htmlspecialchars($middleName) ?>" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($lastName) ?>" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Suffix (optional)</label>
                        <input type="text" name="suffix" value="<?= htmlspecialchars($suffix) ?>" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" placeholder="Jr., Sr., III">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                        <select id="dept_id" name="dept_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" required>
                            <option value="">Select department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" data-code="<?= htmlspecialchars($dept['code']) ?>" <?= $selectedDeptId === (int)$dept['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['code'] . ' - ' . $dept['description']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Course</label>
                        <select id="course_id" name="course_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" required>
                            <option value="">Select course</option>
                            <?php foreach ($courses as $course): ?>
                            <option value="<?= $course['id'] ?>"
                                data-dept-id="<?= $course['dept_id'] ?>"
                                data-dept-code="<?= htmlspecialchars($deptById[(int)$course['dept_id']] ?? '') ?>"
                                data-enrollment-type="<?= htmlspecialchars($course['enrollment_type']) ?>"
                                <?= $selectedCourseId === (int)$course['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($course['code'] . ' - ' . $course['desc']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Level</label>
                        <select id="level_id" name="level_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" required>
                            <option value="">Select level</option>
                            <?php foreach ($levels as $level): ?>
                            <option value="<?= $level['id'] ?>" <?= $selectedLevelId === (int)$level['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level['code'] . ' - ' . $level['description']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                        <select id="section_id" name="section_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" required>
                            <option value="">Select section</option>
                            <?php foreach ($sections as $section): ?>
                            <option value="<?= $section['id'] ?>"
                                data-course-id="<?= $section['course_id'] ?>"
                                data-sy-id="<?= $section['sy_id'] ?>"
                                data-level-id="<?= $section['level_id'] ?? '' ?>"
                                <?= $selectedSectionId === (int)$section['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($section['section_code'] . ' - ' . ($section['level_code'] ?? 'No Level') . ' (' . ($section['sy_name'] ?? 'N/A') . ')') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Curriculum</label>
                        <select id="curriculum_id" name="curriculum_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none" required>
                            <option value="">Select curriculum</option>
                            <?php foreach ($curricula as $curriculum): ?>
                            <option value="<?= $curriculum['id'] ?>"
                                data-course-id="<?= $curriculum['academic_track_id'] ?>"
                                <?= $selectedCurriculumId === (int)$curriculum['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($curriculum['curriculum']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="termRow" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Term</label>
                        <select id="term_id" name="term_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                            <option value="">Select term</option>
                            <?php foreach ($terms as $term): ?>
                            <option value="<?= $term['id'] ?>"
                                data-sy-id="<?= $term['sy_id'] ?>"
                                data-term-name="<?= htmlspecialchars(strtolower($term['term_name'])) ?>"
                                <?= $selectedTermId === (int)$term['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term['term_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-6">
                    <p class="text-sm text-gray-500">Subjects load automatically based on course and curriculum.</p>
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                        Enroll Student
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 mt-6 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Subjects from Prospectus</h2>
                    <p class="text-sm text-gray-500" id="subjectsMeta">Select a curriculum to load subjects.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium">Subject Code</th>
                            <th class="px-6 py-4 font-medium">Description</th>
                            <th class="px-6 py-4 font-medium">Units</th>
                            <th class="px-6 py-4 font-medium">Type</th>
                            <th class="px-6 py-4 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody id="subjectsBody">
                        <tr class="border-t border-gray-100">
                            <td class="px-6 py-4 text-sm text-gray-500" colspan="5">No subjects loaded.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        </div><!-- end panelManual -->

        <!-- Upload Students Tab -->
        <div id="panelUpload" class="hidden">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Bulk Enroll via CSV</h2>
                    <p class="text-sm text-gray-500">Upload a CSV file to enroll multiple students at once.</p>
                </div>
                <a href="/admin/enrollment.php?action=csv_template" class="inline-flex items-center gap-2 px-4 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download Template
                </a>
            </div>

            <div class="mb-5 p-4 bg-blue-50 border border-blue-100 rounded-lg text-sm text-blue-800">
                <p class="font-medium mb-1">Two ways to assign sections &amp; curricula:</p>
                <ul class="list-disc ml-5 space-y-1 text-blue-700">
                    <li><strong>Dropdowns below</strong> — apply the same level/section/curriculum/term to all students in the CSV.</li>
                    <li><strong>CSV columns</strong> — add <code class="bg-blue-100 px-1 rounded">level_code</code>, <code class="bg-blue-100 px-1 rounded">section_code</code>, <code class="bg-blue-100 px-1 rounded">course_code</code>, <code class="bg-blue-100 px-1 rounded">curriculum</code>, <code class="bg-blue-100 px-1 rounded">term</code> columns in the file. Per-row values override the dropdowns.</li>
                </ul>
                <p class="mt-1 text-blue-600">You can mix both — dropdowns serve as defaults, CSV columns override per student. Leave student_no blank to auto-generate the next school-year number.</p>
            </div>

            <form id="bulkUploadForm" enctype="multipart/form-data">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Default Department</label>
                        <select id="upload_dept_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                            <option value="">Select department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" data-code="<?= htmlspecialchars($dept['code']) ?>">
                                <?= htmlspecialchars($dept['code'] . ' - ' . $dept['description']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Default Course</label>
                        <select id="upload_course_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                            <option value="">Select course</option>
                            <?php foreach ($courses as $course): ?>
                            <option value="<?= $course['id'] ?>"
                                data-dept-id="<?= $course['dept_id'] ?>"
                                data-dept-code="<?= htmlspecialchars($deptById[(int)$course['dept_id']] ?? '') ?>"
                                data-enrollment-type="<?= htmlspecialchars($course['enrollment_type']) ?>">
                                <?= htmlspecialchars($course['code'] . ' - ' . $course['desc']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Default Level</label>
                        <select id="upload_level_id" name="upload_level_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                            <option value="">Select level</option>
                            <?php foreach ($levels as $level): ?>
                            <option value="<?= $level['id'] ?>">
                                <?= htmlspecialchars($level['code'] . ' - ' . $level['description']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Default Section</label>
                        <select id="upload_section_id" name="upload_section_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                            <option value="">Select section</option>
                            <?php foreach ($sections as $section): ?>
                            <option value="<?= $section['id'] ?>"
                                data-course-id="<?= $section['course_id'] ?>"
                                data-sy-id="<?= $section['sy_id'] ?>"
                                data-level-id="<?= $section['level_id'] ?? '' ?>">
                                <?= htmlspecialchars($section['section_code'] . ' - ' . ($section['level_code'] ?? 'No Level') . ' (' . ($section['sy_name'] ?? 'N/A') . ')') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Default Curriculum</label>
                        <select id="upload_curriculum_id" name="upload_curriculum_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                            <option value="">Select curriculum</option>
                            <?php foreach ($curricula as $curriculum): ?>
                            <option value="<?= $curriculum['id'] ?>"
                                data-course-id="<?= $curriculum['academic_track_id'] ?>">
                                <?= htmlspecialchars($curriculum['curriculum']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="uploadTermRow" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Term</label>
                        <select id="upload_term_id" name="upload_term_id" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none">
                            <option value="">Select term</option>
                            <?php foreach ($terms as $term): ?>
                            <option value="<?= $term['id'] ?>"
                                data-sy-id="<?= $term['sy_id'] ?>"
                                data-term-name="<?= htmlspecialchars(strtolower($term['term_name'])) ?>">
                                <?= htmlspecialchars($term['term_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">CSV File</label>
                        <input type="file" id="csv_file" name="csv_file" accept=".csv" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-black focus:outline-none text-sm file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-black file:text-white hover:file:bg-neutral-800" required>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <p class="text-sm text-gray-500">Required: given_name, last_name. Optional: student_no, middle_name, suffix, level_code, section_code, course_code, curriculum, term.</p>
                    <button type="submit" id="uploadBtn" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        Upload &amp; Enroll
                    </button>
                </div>
            </form>
        </div>

        <!-- Upload Results -->
        <div id="uploadResults" class="hidden bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-800">Upload Results</h2>
                <p class="text-sm text-gray-500" id="uploadSummary"></p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-sm text-gray-600">
                            <th class="px-6 py-4 font-medium">Row</th>
                            <th class="px-6 py-4 font-medium">Student No</th>
                            <th class="px-6 py-4 font-medium">Status</th>
                            <th class="px-6 py-4 font-medium">Details</th>
                        </tr>
                    </thead>
                    <tbody id="uploadResultsBody"></tbody>
                </table>
            </div>
        </div>
        </div><!-- end panelUpload -->
    </div>
</main>
<script>
const deptSelect = document.getElementById('dept_id');
const courseSelect = document.getElementById('course_id');
const levelSelect = document.getElementById('level_id');
const sectionSelect = document.getElementById('section_id');
const curriculumSelect = document.getElementById('curriculum_id');
const termSelect = document.getElementById('term_id');
const termRow = document.getElementById('termRow');
const subjectsBody = document.getElementById('subjectsBody');
const subjectsMeta = document.getElementById('subjectsMeta');

function filterOptions(select, attribute, value) {
    const options = Array.from(select.options);
    options.forEach((option, index) => {
        if (index === 0) {
            option.hidden = false;
            return;
        }
        if (!value) {
            option.hidden = false;
            return;
        }
        const attrValue = option.getAttribute(attribute);
        option.hidden = attrValue !== String(value);
    });

    if (select.value && select.selectedOptions[0].hidden) {
        select.value = '';
    }
}

function needsTermSelection() {
    // If a course is selected, use its enrollment_type as the authority
    const courseOption = courseSelect.selectedOptions[0];
    if (courseOption && courseOption.value) {
        const enrollmentType = (courseOption.dataset.enrollmentType || '').toLowerCase();
        return enrollmentType !== 'yearly';
    }
    // Fall back to department code check (case-insensitive)
    const deptOption = deptSelect.selectedOptions[0];
    const deptCode = deptOption ? (deptOption.dataset.code || '').toUpperCase() : '';
    if (deptCode) {
        return !['PRE-ELEM', 'ELEM', 'PRE-EL', 'ELE', 'JHS'].includes(deptCode);
    }
    return false;
}

function isNoTermDepartment() {
    return !needsTermSelection();
}

function isShsDepartment() {
    const deptOption = deptSelect.selectedOptions[0];
    const deptCode = deptOption ? deptOption.dataset.code : '';
    if (deptCode) {
        return deptCode === 'SHS';
    }
    const courseOption = courseSelect.selectedOptions[0];
    const courseDeptCode = courseOption ? courseOption.dataset.deptCode : '';
    return courseDeptCode === 'SHS';
}

function updateTermVisibility() {
    if (needsTermSelection()) {
        termRow.classList.remove('hidden');
        termSelect.setAttribute('required', 'required');
    } else {
        termRow.classList.add('hidden');
        termSelect.value = '';
        termSelect.removeAttribute('required');
    }
}

function filterByDepartment() {
    filterOptions(courseSelect, 'data-dept-id', deptSelect.value);
    updateTermVisibility();
    filterByCourse();
}

function filterByCourse() {
    const courseValue = courseSelect.value;
    const levelValue = levelSelect.value;
    const sectionOptions = Array.from(sectionSelect.options);
    sectionOptions.forEach((option, index) => {
        if (index === 0) {
            option.hidden = false;
            return;
        }
        const optionCourseId = option.getAttribute('data-course-id');
        const optionLevelId = option.getAttribute('data-level-id');
        const matchesCourse = !courseValue || optionCourseId === String(courseValue);
        const matchesLevel = !levelValue || optionLevelId === String(levelValue);
        option.hidden = !(matchesCourse && matchesLevel);
    });
    if (sectionSelect.value && sectionSelect.selectedOptions[0].hidden) {
        sectionSelect.value = '';
    }
    filterOptions(curriculumSelect, 'data-course-id', courseSelect.value);
    filterTerms();
    updateTermVisibility();
}

function filterTerms() {
    const sectionOption = sectionSelect.selectedOptions[0];
    const syId = sectionOption ? sectionOption.dataset.syId : '';
    filterOptions(termSelect, 'data-sy-id', syId);

    const isShs = isShsDepartment();
    Array.from(termSelect.options).forEach((option, index) => {
        if (index === 0) {
            return;
        }
        const termName = option.getAttribute('data-term-name') || '';
        if (isShs && termName.includes('summer')) {
            option.hidden = true;
        }
    });

    if (termSelect.value && termSelect.selectedOptions[0].hidden) {
        termSelect.value = '';
    }
}

async function loadSubjects() {
    const curriculumId = curriculumSelect.value;
    const sectionOption = sectionSelect.selectedOptions[0];
    const syId = sectionOption ? sectionOption.dataset.syId : '';
    const levelId = sectionOption ? (sectionOption.dataset.levelId || levelSelect.value) : levelSelect.value;
    const studentNo = document.getElementById('student_no').value;
    const enrollmentType = courseSelect.selectedOptions[0] ? courseSelect.selectedOptions[0].dataset.enrollmentType : 'yearly';
    const termId = termSelect.value;
    const deptOption = deptSelect.selectedOptions[0];
    const deptCode = deptOption ? deptOption.dataset.code : '';
    const isNoTerm = isNoTermDepartment();

    if (!curriculumId) {
        subjectsMeta.textContent = 'Select a curriculum to load subjects.';
        return;
    }

    if (!isNoTerm && !termId) {
        subjectsMeta.textContent = 'Select a term to load subjects.';
        return;
    }

    subjectsMeta.textContent = 'Loading subjects...';

    const params = new URLSearchParams({
        action: 'subjects',
        curriculum_id: curriculumId,
        section_id: sectionSelect.value,
        term_id: termId,
        level_id: levelId,
        enrollment_type: enrollmentType,
        student_no: studentNo,
        sy_id: syId,
        dept_code: deptCode
    });

    try {
        const response = await fetch(`/admin/enrollment.php?${params.toString()}`);
        const raw = await response.text();
        let data = null;

        try {
            data = JSON.parse(raw);
        } catch (parseError) {
            throw new Error(`Server returned non-JSON response (HTTP ${response.status}).`);
        }

        if (!response.ok || data.error) {
            throw new Error(data.error || `Failed to load subjects (HTTP ${response.status}).`);
        }

        const subjects = data.subjects || [];
        const enrolledIds = (data.enrolledIds || []).map(id => String(id));
        subjectsBody.innerHTML = '';

        if (subjects.length === 0) {
            subjectsBody.innerHTML = '<tr class="border-t border-gray-100"><td class="px-6 py-4 text-sm text-gray-500" colspan="5">No subjects found for this selection.</td></tr>';
            subjectsMeta.textContent = 'No subjects found.';
            return;
        }

        subjects.forEach(subject => {
            const row = document.createElement('tr');
            row.className = 'border-t border-gray-100 hover:bg-gray-50';

            const codeCell = document.createElement('td');
            codeCell.className = 'px-6 py-4 font-semibold text-gray-800';
            codeCell.textContent = subject.subjcode || '';

            const descCell = document.createElement('td');
            descCell.className = 'px-6 py-4 text-sm text-gray-600';
            descCell.textContent = subject.desc || '';

            const unitCell = document.createElement('td');
            unitCell.className = 'px-6 py-4 text-sm text-gray-600';
            unitCell.textContent = subject.unit || '0';

            const typeCell = document.createElement('td');
            typeCell.className = 'px-6 py-4 text-sm text-gray-600';
            typeCell.textContent = subject.type || 'Core';

            const statusCell = document.createElement('td');
            const isEnrolled = enrolledIds.includes(String(subject.id));
            statusCell.className = 'px-6 py-4';
            statusCell.innerHTML = isEnrolled
                ? '<span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Enrolled</span>'
                : '<span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">Not Enrolled</span>';

            row.appendChild(codeCell);
            row.appendChild(descCell);
            row.appendChild(unitCell);
            row.appendChild(typeCell);
            row.appendChild(statusCell);

            subjectsBody.appendChild(row);
        });

        const totalUnits = data.totalUnits || 0;
        const levelName = data.levelName || '';
        let metaText = `${subjects.length} subject(s) loaded`;
        if (levelName) metaText += ` • Level: ${levelName}`;
        if (totalUnits) metaText += ` • ${totalUnits} total units`;
        subjectsMeta.textContent = metaText;
    } catch (error) {
        const message = error && error.message ? error.message : 'Failed to load subjects.';
        subjectsMeta.textContent = message;
        subjectsBody.innerHTML = `<tr class="border-t border-gray-100"><td class="px-6 py-4 text-sm text-red-600" colspan="5">${message}</td></tr>`;
    }
}

deptSelect.addEventListener('change', () => {
    filterByDepartment();
    loadSubjects();
});

courseSelect.addEventListener('change', () => {
    filterByCourse();
    loadSubjects();
});

levelSelect.addEventListener('change', () => {
    filterByCourse();
    loadSubjects();
});

sectionSelect.addEventListener('change', () => {
    const selectedOption = sectionSelect.selectedOptions[0];
    if (selectedOption && selectedOption.dataset.levelId && selectedOption.dataset.levelId !== levelSelect.value) {
        levelSelect.value = selectedOption.dataset.levelId;
    }
    filterTerms();
    loadSubjects();
});

curriculumSelect.addEventListener('change', loadSubjects);
termSelect.addEventListener('change', loadSubjects);

filterByDepartment();
filterByCourse();
filterTerms();
updateTermVisibility();
loadSubjects();

// === Tab Switching ===
function switchTab(tab) {
    const tabManual = document.getElementById('tabManual');
    const tabUpload = document.getElementById('tabUpload');
    const panelManual = document.getElementById('panelManual');
    const panelUpload = document.getElementById('panelUpload');

    if (tab === 'upload') {
        panelManual.classList.add('hidden');
        panelUpload.classList.remove('hidden');
        tabManual.classList.remove('border-black', 'text-gray-800');
        tabManual.classList.add('border-transparent', 'text-gray-500');
        tabUpload.classList.remove('border-transparent', 'text-gray-500');
        tabUpload.classList.add('border-black', 'text-gray-800');
    } else {
        panelUpload.classList.add('hidden');
        panelManual.classList.remove('hidden');
        tabUpload.classList.remove('border-black', 'text-gray-800');
        tabUpload.classList.add('border-transparent', 'text-gray-500');
        tabManual.classList.remove('border-transparent', 'text-gray-500');
        tabManual.classList.add('border-black', 'text-gray-800');
    }
}

// === Upload Tab Filtering ===
const uploadDeptSelect = document.getElementById('upload_dept_id');
const uploadCourseSelect = document.getElementById('upload_course_id');
const uploadLevelSelect = document.getElementById('upload_level_id');
const uploadSectionSelect = document.getElementById('upload_section_id');
const uploadCurriculumSelect = document.getElementById('upload_curriculum_id');
const uploadTermSelect = document.getElementById('upload_term_id');
const uploadTermRow = document.getElementById('uploadTermRow');

function uploadNeedsTerm() {
    const opt = uploadCourseSelect.selectedOptions[0];
    if (opt && opt.value) {
        return (opt.dataset.enrollmentType || '').toLowerCase() !== 'yearly';
    }
    const dOpt = uploadDeptSelect.selectedOptions[0];
    const dc = dOpt ? (dOpt.dataset.code || '').toUpperCase() : '';
    return dc ? !['PRE-ELEM', 'ELEM', 'PRE-EL', 'ELE', 'JHS'].includes(dc) : false;
}

function uploadIsShsDept() {
    const dOpt = uploadDeptSelect.selectedOptions[0];
    return dOpt ? dOpt.dataset.code === 'SHS' : false;
}

function uploadUpdateTermVisibility() {
    if (uploadNeedsTerm()) {
        uploadTermRow.classList.remove('hidden');
        uploadTermSelect.setAttribute('required', 'required');
    } else {
        uploadTermRow.classList.add('hidden');
        uploadTermSelect.value = '';
        uploadTermSelect.removeAttribute('required');
    }
}

function uploadFilterByDept() {
    filterOptions(uploadCourseSelect, 'data-dept-id', uploadDeptSelect.value);
    uploadUpdateTermVisibility();
    uploadFilterByCourse();
}

function uploadFilterByCourse() {
    const courseValue = uploadCourseSelect.value;
    const levelValue = uploadLevelSelect.value;
    const sectionOptions = Array.from(uploadSectionSelect.options);
    sectionOptions.forEach((option, index) => {
        if (index === 0) {
            option.hidden = false;
            return;
        }
        const optionCourseId = option.getAttribute('data-course-id');
        const optionLevelId = option.getAttribute('data-level-id');
        const matchesCourse = !courseValue || optionCourseId === String(courseValue);
        const matchesLevel = !levelValue || optionLevelId === String(levelValue);
        option.hidden = !(matchesCourse && matchesLevel);
    });
    if (uploadSectionSelect.value && uploadSectionSelect.selectedOptions[0].hidden) {
        uploadSectionSelect.value = '';
    }
    filterOptions(uploadCurriculumSelect, 'data-course-id', uploadCourseSelect.value);
    uploadFilterTerms();
    uploadUpdateTermVisibility();
}

function uploadFilterTerms() {
    const opt = uploadSectionSelect.selectedOptions[0];
    const syId = opt ? opt.dataset.syId : '';
    filterOptions(uploadTermSelect, 'data-sy-id', syId);
    const isShs = uploadIsShsDept();
    Array.from(uploadTermSelect.options).forEach((option, i) => {
        if (i === 0) return;
        const tn = option.getAttribute('data-term-name') || '';
        if (isShs && tn.includes('summer')) option.hidden = true;
    });
    if (uploadTermSelect.value && uploadTermSelect.selectedOptions[0].hidden) {
        uploadTermSelect.value = '';
    }
}

uploadDeptSelect.addEventListener('change', uploadFilterByDept);
uploadCourseSelect.addEventListener('change', uploadFilterByCourse);
uploadLevelSelect.addEventListener('change', uploadFilterByCourse);
uploadSectionSelect.addEventListener('change', () => {
    const selectedOption = uploadSectionSelect.selectedOptions[0];
    if (selectedOption && selectedOption.dataset.levelId && selectedOption.dataset.levelId !== uploadLevelSelect.value) {
        uploadLevelSelect.value = selectedOption.dataset.levelId;
    }
    uploadFilterTerms();
});
uploadFilterByDept();

// === Bulk Upload Submit ===
document.getElementById('bulkUploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn = document.getElementById('uploadBtn');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg> Processing...';

    const formData = new FormData();
    formData.append('action', 'bulk_upload');
    formData.append('upload_level_id', uploadLevelSelect.value);
    formData.append('upload_section_id', uploadSectionSelect.value);
    formData.append('upload_curriculum_id', uploadCurriculumSelect.value);
    formData.append('upload_term_id', uploadTermSelect.value || '0');
    formData.append('csv_file', document.getElementById('csv_file').files[0]);

    try {
        const response = await fetch('/admin/enrollment.php', { method: 'POST', body: formData });
        const data = await response.json();

        const resultsDiv = document.getElementById('uploadResults');
        const summaryEl = document.getElementById('uploadSummary');
        const tbody = document.getElementById('uploadResultsBody');

        summaryEl.textContent = data.message || '';
        tbody.innerHTML = '';

        (data.results || []).forEach(r => {
            const tr = document.createElement('tr');
            tr.className = 'border-t border-gray-100';
            const statusClass = r.status === 'success'
                ? 'bg-green-100 text-green-700'
                : 'bg-red-100 text-red-700';
            tr.innerHTML = `
                <td class="px-6 py-3 text-sm text-gray-600">${r.row}</td>
                <td class="px-6 py-3 text-sm font-medium text-gray-800">${r.student_no || '-'}</td>
                <td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full ${statusClass}">${r.status}</span></td>
                <td class="px-6 py-3 text-sm text-gray-600">${r.message}</td>
            `;
            tbody.appendChild(tr);
        });

        resultsDiv.classList.remove('hidden');
    } catch (err) {
        alert('Upload failed. Please try again.');
    }

    btn.disabled = false;
    btn.innerHTML = origText;
});
</script>

<?php include '../includes/footer.php'; ?>
