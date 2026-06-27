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
$current_time = date('H:i:s');
$current_date = date('Y-m-d');

try {
    // 1. Log detailed location movement history
    $stmt_log = $conn->prepare("
        INSERT INTO lecturer_location_logs (lecturer_id, latitude, longitude, altitude)
        VALUES (?, ?, ?, ?)
    ");
    $stmt_log->execute([$lecturer_id, $latitude, $longitude, $altitude]);

    // 2. Upsert live location record for admin live tracking
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

    // 3. Geofence premise checking for automatic sign-out (only between 8 AM and 4 PM)
    if ($current_time >= '08:00:00' && $current_time <= '16:00:00') {
        $distance = calculateHaversineDistance(CAMPUS_LAT, CAMPUS_LON, $latitude, $longitude);
        
        // If they are outside university boundary
        if ($distance > CAMPUS_RADIUS_METERS) {
            // Check if signed in but not signed out today
            $stmt_shift = $conn->prepare("
                SELECT shift_id FROM lecturer_shifts 
                WHERE lecturer_id = ? AND work_date = ? AND sign_in_time IS NOT NULL AND sign_out_time IS NULL
            ");
            $stmt_shift->execute([$lecturer_id, $current_date]);
            $active_shift = $stmt_shift->fetch();
            
            if ($active_shift) {
                // Auto sign out
                $sign_out_time = ($current_time >= '16:00:00')
                    ? date('Y-m-d 16:00:00')
                    : date('Y-m-d H:i:s');
                $stmt_out = $conn->prepare("
                    UPDATE lecturer_shifts 
                    SET sign_out_time = ?, sign_out_latitude = ?, sign_out_longitude = ?, sign_out_altitude = ?, sign_out_method = 'auto_geofence'
                    WHERE shift_id = ?
                ");
                $stmt_out->execute([$sign_out_time, $latitude, $longitude, $altitude, $active_shift['shift_id']]);
                createNotification($lecturer_id, 'shift', 'Auto sign-out', 'You were automatically signed out because you left the university boundary.', 'lecturer_shifts', $active_shift['shift_id']);
                createBroadcastNotification('shift', 'Lecturer auto signed out', 'A lecturer was automatically signed out after leaving the campus boundary.', 'lecturer_shifts', $active_shift['shift_id']);
            }
        }
    }
    
    sendJsonResponse(['status' => 'SUCCESS', 'message' => 'Location logged']);
} catch (PDOException $e) {
    sendJsonResponse(['status' => 'ERROR', 'message' => 'Database error: ' . $e->getMessage()], 500);
}
?>
