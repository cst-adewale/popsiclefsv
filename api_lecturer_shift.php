<?php
/**
 * School Attendance Verification System
 * api_lecturer_shift.php - Handles daily sign-in and sign-out operations
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
$latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
$longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
$altitude = isset($_POST['altitude']) ? floatval($_POST['altitude']) : 0.00;

if ($latitude === null || $longitude === null) {
    echo json_encode(['status' => 'ERROR', 'message' => 'Missing location coordinates']);
    exit;
}

$current_time = date('H:i:s');
$current_date = date('Y-m-d');

if ($action === 'signin') {
    // Cannot sign in before 8:00 AM
    if ($current_time < '08:00:00') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Sign-in is not active until 8:00 AM.']);
        exit;
    }

    try {
        // Check if already signed in today
        $stmt = $conn->prepare("SELECT shift_id FROM lecturer_shifts WHERE lecturer_id = ? AND work_date = ?");
        $stmt->execute([$lecturer_id, $current_date]);
        if ($stmt->fetch()) {
            echo json_encode(['status' => 'ERROR', 'message' => 'You have already signed in today.']);
            exit;
        }

        // Insert sign-in record
        $stmt = $conn->prepare("
            INSERT INTO lecturer_shifts (lecturer_id, work_date, sign_in_time, sign_in_latitude, sign_in_longitude, sign_in_altitude)
            VALUES (?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([$lecturer_id, $current_date, $latitude, $longitude, $altitude]);

        echo json_encode(['status' => 'SUCCESS', 'message' => 'Successfully signed in for the day.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Database error: ' . $e->getMessage()]);
    }

} elseif ($action === 'signout') {
    try {
        // Fetch current active shift
        $stmt = $conn->prepare("SELECT shift_id, sign_in_time, sign_out_time FROM lecturer_shifts WHERE lecturer_id = ? AND work_date = ?");
        $stmt->execute([$lecturer_id, $current_date]);
        $shift = $stmt->fetch();

        if (!$shift) {
            echo json_encode(['status' => 'ERROR', 'message' => 'You must sign in first.']);
            exit;
        }

        if ($shift['sign_out_time'] !== null) {
            echo json_encode(['status' => 'ERROR', 'message' => 'You have already signed out today.']);
            exit;
        }

        // Clamp signout time: if past 4pm, log exactly 4pm
        if ($current_time > '16:00:00') {
            // Set sign out time to 4:00 PM of today
            $four_pm_today = date('Y-m-d 16:00:00');
            $stmt = $conn->prepare("
                UPDATE lecturer_shifts 
                SET sign_out_time = ?, sign_out_latitude = ?, sign_out_longitude = ?, sign_out_altitude = ?, sign_out_method = 'manual'
                WHERE shift_id = ?
            ");
            $stmt->execute([$four_pm_today, $latitude, $longitude, $altitude, $shift['shift_id']]);
        } else {
            $stmt = $conn->prepare("
                UPDATE lecturer_shifts 
                SET sign_out_time = NOW(), sign_out_latitude = ?, sign_out_longitude = ?, sign_out_altitude = ?, sign_out_method = 'manual'
                WHERE shift_id = ?
            ");
            $stmt->execute([$latitude, $longitude, $altitude, $shift['shift_id']]);
        }

        echo json_encode(['status' => 'SUCCESS', 'message' => 'Successfully signed out for the day.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'ERROR', 'message' => 'Invalid action']);
}
?>
