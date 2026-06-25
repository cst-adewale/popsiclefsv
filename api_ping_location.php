<?php
/**
 * School Attendance Verification System
 * api_ping_location.php - Background location update ping API (For Admin Live Map tracking)
 */

require 'config.php';
session_start();

// Auth check - only lecturers can submit location logs
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    sendJsonResponse(['status' => 'ERROR', 'message' => 'Unauthorized access'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'ERROR', 'message' => 'Invalid request method'], 400);
}

$latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
$longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
$altitude = isset($_POST['altitude']) ? floatval($_POST['altitude']) : 0.00;

if ($latitude === null || $longitude === null) {
    sendJsonResponse(['status' => 'ERROR', 'message' => 'Missing location coordinates'], 400);
}

$lecturer_id = $_SESSION['user_id'];

try {
    // Upsert live location record using PostgreSQL syntax
    $stmt = $conn->prepare("
        INSERT INTO live_locations (lecturer_id, latitude, longitude, altitude, last_updated)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT (lecturer_id)
        DO UPDATE SET 
            latitude = EXCLUDED.latitude,
            longitude = EXCLUDED.longitude,
            altitude = EXCLUDED.altitude,
            last_updated = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$lecturer_id, $latitude, $longitude, $altitude]);
    
    sendJsonResponse(['status' => 'SUCCESS', 'message' => 'Location logged']);
} catch (PDOException $e) {
    sendJsonResponse(['status' => 'ERROR', 'message' => 'Database error: ' . $e->getMessage()], 500);
}
?>
