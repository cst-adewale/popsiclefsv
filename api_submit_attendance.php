<?php
/**
 * School Attendance Verification System
 * api_submit_attendance.php - API endpoint for submitting attendance logs (Supabase Edition)
 */

require 'config.php';
session_start();

// Ensure session exists and has lecturer role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    sendJsonResponse(['status' => 'ERROR', 'message' => 'Unauthorized or session expired'], 401);
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'ERROR', 'message' => 'Invalid request method'], 400);
}

// Extract inputs
$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : null;
$latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
$longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
$altitude = isset($_POST['altitude']) ? floatval($_POST['altitude']) : 0.00;
$description = isset($_POST['description']) ? sanitizeInput($_POST['description']) : '';
$accuracy = isset($_POST['accuracy']) ? intval($_POST['accuracy']) : null;

// Validation
if (!$class_id || $latitude === null || $longitude === null) {
    sendJsonResponse([
        'status' => 'ERROR',
        'message' => 'Missing required fields (class_id, latitude, longitude)'
    ], 400);
}

// Coordinate checks
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    sendJsonResponse([
        'status' => 'ERROR',
        'message' => 'Invalid location coordinates'
    ], 400);
}

// Description length constraint
if (strlen($description) < 5) {
    sendJsonResponse([
        'status' => 'ERROR',
        'message' => 'Description must explain your check-in reason (min 5 chars)'
    ], 400);
}

$lecturer_id = $_SESSION['user_id'];

// Call core verification logic
$result = verifyAttendanceSubmission(
    $class_id,
    $lecturer_id,
    $latitude,
    $longitude,
    $altitude,
    $description,
    $accuracy
);

if ($result['status'] === 'ERROR') {
    sendJsonResponse($result, 400);
}

// Log check-in submission in audit logs
logAuditTrail(
    $lecturer_id,
    'ATTENDANCE_CHECKIN',
    'attendance_submissions',
    $result['submission_id'],
    $_SERVER['REMOTE_ADDR']
);

createNotification(
    $lecturer_id,
    'attendance',
    'Attendance submitted',
    'Your attendance submission was recorded with status ' . $result['verification_status'] . '.',
    'attendance_submissions',
    $result['submission_id']
);

// Respond with verification outcome details
sendJsonResponse([
    'status' => 'SUCCESS',
    'submission_id' => $result['submission_id'],
    'message' => 'Attendance logged successfully',
    'verification' => [
        'status' => $result['verification_status'],
        'spatial' => [
            'passed' => $result['spatial']['valid'],
            'message' => $result['spatial']['message'],
            'distance_meters' => $result['spatial']['distance'],
            'tolerance_meters' => $result['spatial']['tolerance']
        ],
        'altitude' => [
            'passed' => $result['altitude']['valid'],
            'message' => $result['altitude']['message'],
            'submitted_altitude' => $result['altitude']['altitude'],
            'assigned_altitude' => $result['altitude']['assigned']
        ],
        'temporal' => [
            'passed' => $result['temporal']['valid'],
            'message' => $result['temporal']['message']
        ],
        'anomaly' => [
            'detected' => $result['anomaly']['detected'],
            'reason' => $result['anomaly']['reason'] ?? ''
        ]
    ],
    'timestamp' => $result['timestamp']
], 200);
?>
