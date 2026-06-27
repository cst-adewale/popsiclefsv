<?php
/**
 * School Attendance Verification System
 * api_manage_schedule.php - Allows lecturers to add/edit their classes
 */

require 'config.php';
session_start();

header('Content-Type: application/json');

// Ensure lecturer is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    echo json_encode(['status' => 'ERROR', 'message' => 'Unauthorized']);
    exit;
}

$lecturer_id = $_SESSION['user_id'];
$action = isset($_POST['action']) ? $_POST['action'] : '';

$course_code = isset($_POST['course_code']) ? sanitizeInput($_POST['course_code']) : '';
$course_title = isset($_POST['course_title']) ? sanitizeInput($_POST['course_title']) : '';
$hall_id = isset($_POST['hall_id']) ? intval($_POST['hall_id']) : null;
$start_time = isset($_POST['start_time']) ? $_POST['start_time'] : '';
$end_time = isset($_POST['end_time']) ? $_POST['end_time'] : '';
$scheduled_date = isset($_POST['scheduled_date']) ? $_POST['scheduled_date'] : '';

if ($action === 'create') {
    if (empty($course_code) || empty($course_title) || !$hall_id || empty($start_time) || empty($end_time) || empty($scheduled_date)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Missing required fields']);
        exit;
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO scheduled_classes (lecturer_id, hall_id, course_code, course_title, scheduled_start_time, scheduled_end_time, scheduled_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$lecturer_id, $hall_id, $course_code, $course_title, $start_time, $end_time, $scheduled_date, $lecturer_id]);
        
        $new_id = $conn->lastInsertId('scheduled_classes_class_id_seq');
        logAuditTrail($lecturer_id, 'CREATE_SCHEDULE', 'scheduled_classes', $new_id ?: 0);

        echo json_encode(['status' => 'SUCCESS', 'message' => 'Schedule created successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Database error: ' . $e->getMessage()]);
    }

} elseif ($action === 'edit') {
    $class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : null;
    if (!$class_id || empty($course_code) || empty($course_title) || !$hall_id || empty($start_time) || empty($end_time) || empty($scheduled_date)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Missing required fields for update']);
        exit;
    }

    try {
        // Verify ownership
        $stmt = $conn->prepare("SELECT class_id FROM scheduled_classes WHERE class_id = ? AND lecturer_id = ?");
        $stmt->execute([$class_id, $lecturer_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Schedule not found or unauthorized']);
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE scheduled_classes
            SET hall_id = ?, course_code = ?, course_title = ?, scheduled_start_time = ?, scheduled_end_time = ?, scheduled_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE class_id = ? AND lecturer_id = ?
        ");
        $stmt->execute([$hall_id, $course_code, $course_title, $start_time, $end_time, $scheduled_date, $class_id, $lecturer_id]);

        logAuditTrail($lecturer_id, 'EDIT_SCHEDULE', 'scheduled_classes', $class_id);

        echo json_encode(['status' => 'SUCCESS', 'message' => 'Schedule updated successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} elseif ($action === 'delete') {
    $class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : null;
    if (!$class_id) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Missing class_id']);
        exit;
    }

    try {
        // Verify ownership
        $stmt = $conn->prepare("SELECT class_id FROM scheduled_classes WHERE class_id = ? AND lecturer_id = ?");
        $stmt->execute([$class_id, $lecturer_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Schedule not found or unauthorized']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM scheduled_classes WHERE class_id = ? AND lecturer_id = ?");
        $stmt->execute([$class_id, $lecturer_id]);

        logAuditTrail($lecturer_id, 'DELETE_SCHEDULE', 'scheduled_classes', $class_id);

        echo json_encode(['status' => 'SUCCESS', 'message' => 'Schedule deleted successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'ERROR', 'message' => 'Invalid action']);
}
?>
