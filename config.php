<?php
/**
 * School Attendance Verification System
 * config.php - Supabase (PostgreSQL) connection, security and 3D validation algorithms
 * Academic Project - 2026
 */

// ============================================================
// DATABASE CONFIGURATION
// Uses Supabase CONNECTION POOLER to avoid IPv6 issues on Render.
// Env variables are set in Render Dashboard → Environment.
// ============================================================

// Pooler host (Supabase Dashboard → Project Settings → Database → Connection Pooling)
define('DB_HOST', getenv('DB_HOST') ?: 'aws-0-eu-west-1.pooler.supabase.com');

// Pooler uses port 6543 (Transaction mode) — NOT 5432
define('DB_PORT', getenv('DB_PORT') ?: '6543');

// Pooler username format: postgres.[project-ref]
define('DB_USER', getenv('DB_USER') ?: 'postgres.qikyorppbzokktianreo');

define('DB_PASS', getenv('DB_PASS') ?: 'YOUR_SUPABASE_DATABASE_PASSWORD');
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');

// System Configuration
define('MAX_DISTANCE_TOLERANCE', 50); // default horizontal tolerance (meters)
define('ENABLE_LOGS', true);
define('TIMEZONE', 'Africa/Lagos');

// Caleb University Imota Campus Center Geofence
define('CAMPUS_LAT', 6.67180000);
define('CAMPUS_LON', 3.49080000);
define('CAMPUS_RADIUS_METERS', 800);


// Set timezone in PHP
date_default_timezone_set(TIMEZONE);

// Create connection using PDO (PostgreSQL via Supabase Pooler)
try {
    // sslmode=require is mandatory for Supabase
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=require";
    $conn = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Set Timezone in PostgreSQL session
    $conn->exec("SET TIME ZONE 'Africa/Lagos'");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage() . "<br>Please check your Supabase pooler credentials.");
}

/**
 * HAVERSINE FORMULA
 * Calculate horizontal distance in meters between two coordinates
 */
function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius_meters = 6371000;
    
    $lat1_rad = deg2rad($lat1);
    $lat2_rad = deg2rad($lat2);
    $delta_lat = deg2rad($lat2 - $lat1);
    $delta_lon = deg2rad($lon2 - $lon1);
    
    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
         cos($lat1_rad) * cos($lat2_rad) * 
         sin($delta_lon / 2) * sin($delta_lon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distance = $earth_radius_meters * $c;
    
    return round($distance, 2);
}

/**
 * VELOCITY ANOMALY CHECK (Impossible Travel)
 */
function detectVelocityAnomaly($lecturer_id, $current_lat, $current_lon, $current_time) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT submitted_latitude, submitted_longitude, server_timestamp
                            FROM attendance_submissions
                            WHERE lecturer_id = ?
                            ORDER BY server_timestamp DESC
                            LIMIT 1");
    $stmt->execute([$lecturer_id]);
    $last_submission = $stmt->fetch();
    
    if (!$last_submission) {
        return ['is_anomalous' => false, 'reason' => ''];
    }
    
    $last_lat = $last_submission['submitted_latitude'];
    $last_lon = $last_submission['submitted_longitude'];
    $last_time = strtotime($last_submission['server_timestamp']);
    $current_time_stamp = strtotime($current_time);
    
    $distance = calculateHaversineDistance($last_lat, $last_lon, $current_lat, $current_lon);
    $time_diff_minutes = ($current_time_stamp - $last_time) / 60;
    
    $distance_km = $distance / 1000;
    $time_hours = $time_diff_minutes / 60;
    $implied_speed = ($time_hours > 0) ? $distance_km / $time_hours : 0;
    
    $max_realistic_speed = 50; // km/h
    
    if ($implied_speed > $max_realistic_speed && $time_diff_minutes < 15) {
        return [
            'is_anomalous' => true,
            'reason' => "Impossible travel: {$distance}m in " . round($time_diff_minutes, 1) . " minutes (implied speed: " . round($implied_speed, 1) . " km/h)"
        ];
    }
    
    return ['is_anomalous' => false, 'reason' => ''];
}

/**
 * GET SERVER TIMESTAMP
 */
function getServerTimestamp() {
    return date('Y-m-d H:i:s');
}

/**
 * VALIDATE TIME WINDOW
 */
function validateTimeWindow($start_time, $end_time, $scheduled_date) {
    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');
    
    if ($current_date !== $scheduled_date) {
        return [
            'is_valid' => false,
            'reason' => "Class scheduled for $scheduled_date but submission is on $current_date"
        ];
    }
    
    $earliest_check_in = date('H:i:s', strtotime($start_time) - 900); // 15 min early
    
    if ($current_time < $earliest_check_in) {
        $minutes_early = round((strtotime($start_time) - strtotime($current_time)) / 60);
        return [
            'is_valid' => false,
            'reason' => "Too early: Class starts at $start_time (you are {$minutes_early} minutes early)"
        ];
    }
    
    if ($current_time > $end_time) {
        $minutes_late = round((strtotime($current_time) - strtotime($end_time)) / 60);
        return [
            'is_valid' => false,
            'reason' => "Too late: Class ended at $end_time (you are {$minutes_late} minutes late)"
        ];
    }
    
    return ['is_valid' => true, 'reason' => '✓ On schedule: Class is currently active'];
}

/**
 * VALIDATE 3D GEOLOCATION ATTENDANCE
 */
function verifyAttendanceSubmission($class_id, $lecturer_id, $submitted_lat, $submitted_lon, $submitted_alt, $description, $accuracy_meters = null) {
    global $conn;
    
    // Fetch schedule and assigned lecture hall parameters
    $stmt = $conn->prepare("SELECT 
                                sc.class_id,
                                sc.scheduled_start_time,
                                sc.scheduled_end_time,
                                sc.scheduled_date,
                                lh.latitude as assigned_lat,
                                lh.longitude as assigned_lon,
                                lh.altitude_meters as assigned_alt,
                                lh.tolerance_radius_meters,
                                lh.altitude_tolerance_meters,
                                lh.hall_name
                            FROM scheduled_classes sc
                            JOIN lecture_halls lh ON sc.hall_id = lh.hall_id
                            WHERE sc.class_id = ? AND sc.lecturer_id = ?");
    $stmt->execute([$class_id, $lecturer_id]);
    $class = $stmt->fetch();
    
    if (!$class) {
        return [
            'status' => 'ERROR',
            'message' => 'Class not found or not assigned to you'
        ];
    }
    
    // 1. SPATIAL GEOLOCATION (2D Geofence)
    $distance = calculateHaversineDistance(
        $class['assigned_lat'],
        $class['assigned_lon'],
        $submitted_lat,
        $submitted_lon
    );
    $spatial_valid = ($distance <= $class['tolerance_radius_meters']) ? 'PASS' : 'FAIL';
    $spatial_message = ($spatial_valid == 'PASS') 
        ? "✓ Location verified: You are in {$class['hall_name']}"
        : "✗ Out of range: You are {$distance}m away (limit: {$class['tolerance_radius_meters']}m)";
        
    // 2. ALTITUDE HEIGHT GEOLOCATION (3D Geofence)
    $altitude_diff = abs($class['assigned_alt'] - $submitted_alt);
    $altitude_valid = ($altitude_diff <= $class['altitude_tolerance_meters']) ? 'PASS' : 'FAIL';
    $altitude_message = ($altitude_valid == 'PASS')
        ? "✓ Floor verified: Height difference is " . round($altitude_diff, 2) . "m (tolerance: {$class['altitude_tolerance_meters']}m)"
        : "✗ Wrong floor: Height difference is " . round($altitude_diff, 2) . "m (expected hall height: {$class['assigned_alt']}m, you are at {$submitted_alt}m)";
    
    // 3. TEMPORAL VALIDATION
    $time_validation = validateTimeWindow(
        $class['scheduled_start_time'],
        $class['scheduled_end_time'],
        $class['scheduled_date']
    );
    $temporal_valid = $time_validation['is_valid'] ? 'PASS' : 'FAIL';
    $temporal_message = $time_validation['reason'];
    
    // 4. VELOCITY ANOMALY CHECK
    $server_timestamp = getServerTimestamp();
    $anomaly_check = detectVelocityAnomaly($lecturer_id, $submitted_lat, $submitted_lon, $server_timestamp);
    
    // 5. STATUS DECISION TREE
    if ($anomaly_check['is_anomalous']) {
        $final_status = 'PENDING';
    } else if ($spatial_valid == 'FAIL') {
        $final_status = 'OUT_OF_RANGE';
    } else if ($altitude_valid == 'FAIL') {
        $final_status = 'INVALID_ALTITUDE';
    } else if ($temporal_valid == 'FAIL') {
        $final_status = 'INVALID_TIME';
    } else {
        $final_status = 'VERIFIED';
    }
    
    // 6. DB INSERTION
    $submit_stmt = $conn->prepare(
        "INSERT INTO attendance_submissions 
         (class_id, lecturer_id, submitted_latitude, submitted_longitude, submitted_altitude, 
          assigned_latitude, assigned_longitude, assigned_altitude, server_timestamp, distance_from_assigned_location,
          location_accuracy_meters, spatial_validation, temporal_validation, altitude_validation,
          verification_status, submission_description, is_anomalous, anomaly_reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    
    $submit_stmt->execute([
        $class_id,
        $lecturer_id,
        $submitted_lat,
        $submitted_lon,
        $submitted_alt,
        $class['assigned_lat'],
        $class['assigned_lon'],
        $class['assigned_alt'],
        $server_timestamp,
        $distance,
        $accuracy_meters,
        $spatial_valid,
        $temporal_valid,
        $altitude_valid,
        $final_status,
        $description,
        $anomaly_check['is_anomalous'] ? 1 : 0,
        $anomaly_check['reason']
    ]);
    
    $submission_id = $conn->lastInsertId('attendance_submissions_submission_id_seq');
    
    // Mark class as completed if verified successfully
    if ($final_status === 'VERIFIED') {
        $update_stmt = $conn->prepare("UPDATE scheduled_classes SET status = 'completed' WHERE class_id = ?");
        $update_stmt->execute([$class_id]);
    }
    
    return [
        'status' => 'SUCCESS',
        'submission_id' => $submission_id,
        'verification_status' => $final_status,
        'spatial' => [
            'valid' => ($spatial_valid == 'PASS'),
            'message' => $spatial_message,
            'distance' => $distance,
            'tolerance' => $class['tolerance_radius_meters']
        ],
        'altitude' => [
            'valid' => ($altitude_valid == 'PASS'),
            'message' => $altitude_message,
            'altitude' => $submitted_alt,
            'assigned' => $class['assigned_alt'],
            'tolerance' => $class['altitude_tolerance_meters']
        ],
        'temporal' => [
            'valid' => ($temporal_valid == 'PASS'),
            'message' => $temporal_message
        ],
        'anomaly' => [
            'detected' => $anomaly_check['is_anomalous'],
            'reason' => $anomaly_check['reason']
        ],
        'timestamp' => $server_timestamp
    ];
}

/**
 * LOG SYSTEM EVENT FOR AUDIT TRAIL
 */
function logAuditTrail($user_id, $action, $table, $record_id, $ip_address = '') {
    global $conn;
    if (!ENABLE_LOGS) return;
    
    if (empty($ip_address)) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, ip_address)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$user_id, $action, $table, $record_id, $ip_address]);
}

/**
 * JSON RESPONSE HELPER
 */
function sendJsonResponse($data, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * SANITIZE INPUT
 */
function sanitizeInput($input) {
    return htmlspecialchars(stripslashes(trim($input)), ENT_QUOTES, 'UTF-8');
}
?>
