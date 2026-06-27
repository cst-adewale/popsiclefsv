<?php
/**
 * School Attendance Verification System
 * admin_dashboard.php - Control panel for configuring rooms, schedules, and viewing verified attendance (Supabase Edition)
 */

require 'config.php';
session_start();

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Get lecturer details AJAX handler
if (isset($_GET['get_lecturer_details'])) {
    try {
        $lid = intval($_GET['get_lecturer_details']);
        $date = isset($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        // 1. Fetch lecturer profile
        $stmt = $conn->prepare("SELECT user_id, username, email, full_name, phone, department, faculty, lecturer_number, profile_pic FROM users WHERE user_id = ?");
        $stmt->execute([$lid]);
        $profile = $stmt->fetch();
        
        if (!$profile) {
            sendJsonResponse(['error' => 'Lecturer not found'], 404);
        }
        
        // 2. Fetch classes scheduled
        $stmt = $conn->prepare("
            SELECT sc.course_code, sc.course_title, sc.scheduled_start_time, sc.scheduled_end_time, sc.scheduled_date, lh.hall_name 
            FROM scheduled_classes sc
            JOIN lecture_halls lh ON sc.hall_id = lh.hall_id
            WHERE sc.lecturer_id = ?
            ORDER BY sc.scheduled_date DESC, sc.scheduled_start_time ASC
        ");
        $stmt->execute([$lid]);
        $schedule = $stmt->fetchAll();
        
        // 3. Fetch shift for selected date
        $stmt = $conn->prepare("
            SELECT sign_in_time, sign_in_latitude, sign_in_longitude, sign_in_altitude,
                   sign_out_time, sign_out_latitude, sign_out_longitude, sign_out_altitude, sign_out_method
            FROM lecturer_shifts
            WHERE lecturer_id = ? AND work_date = ?
        ");
        $stmt->execute([$lid, $date]);
        $shift = $stmt->fetch() ?: null;
        
        // 4. Fetch movement logs for selected date
        $stmt = $conn->prepare("
            SELECT latitude, longitude, altitude, logged_at 
            FROM lecturer_location_logs
            WHERE lecturer_id = ? AND CAST(logged_at AS DATE) = ?
            ORDER BY logged_at ASC
        ");
        $stmt->execute([$lid, $date]);
        $movement = $stmt->fetchAll();
        
        sendJsonResponse([
            'profile' => $profile,
            'schedule' => $schedule,
            'shift' => $shift,
            'movement' => $movement
        ]);
    } catch (PDOException $e) {
        sendJsonResponse(['error' => $e->getMessage()], 500);
    }
}

// Live tracking ping feed AJAX handler
if (isset($_GET['get_live_pings'])) {
    try {
        // Fetch pings updated within the last 10 minutes
        $stmt = $conn->query("
            SELECT ll.latitude, ll.longitude, ll.altitude, ll.last_updated, u.full_name, u.department
            FROM live_locations ll
            JOIN users u ON ll.lecturer_id = u.user_id
            WHERE ll.last_updated > NOW() - INTERVAL '10 minutes'
            ORDER BY ll.last_updated DESC
        ");
        $pings = $stmt->fetchAll();
        sendJsonResponse($pings);
    } catch (PDOException $e) {
        sendJsonResponse(['error' => $e->getMessage()], 500);
    }
}

$full_name = $_SESSION['full_name'];
$msg = '';
$err_msg = '';

// Handle Add Lecture Hall POST
if (isset($_POST['add_hall'])) {
    $hall_name = sanitizeInput($_POST['hall_name']);
    $hall_code = sanitizeInput($_POST['hall_code']);
    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);
    $altitude = floatval($_POST['altitude']);
    $tolerance = intval($_POST['tolerance']);
    $alt_tolerance = floatval($_POST['altitude_tolerance']);
    $desc = sanitizeInput($_POST['description']);

    if (!empty($hall_name) && !empty($hall_code) && $latitude !== 0.0 && $longitude !== 0.0) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO lecture_halls (hall_name, hall_code, latitude, longitude, altitude_meters, tolerance_radius_meters, altitude_tolerance_meters, description) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$hall_name, $hall_code, $latitude, $longitude, $altitude, $tolerance, $alt_tolerance, $desc]);
            
            $msg = "Lecture hall '$hall_name' added successfully!";
            logAuditTrail($_SESSION['user_id'], 'ADD_LECTURE_HALL', 'lecture_halls', $conn->lastInsertId('lecture_halls_hall_id_seq'));
        } catch (PDOException $e) {
            $err_msg = "Error adding lecture hall: " . $e->getMessage();
        }
    } else {
        $err_msg = "Please fill in all required fields and pick coordinates on the map.";
    }
}

// Handle Schedule Class POST
if (isset($_POST['schedule_class'])) {
    $lecturer_id = intval($_POST['lecturer_id']);
    $hall_id = intval($_POST['hall_id']);
    $course_code = sanitizeInput($_POST['course_code']);
    $course_title = sanitizeInput($_POST['course_title']);
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $date = $_POST['scheduled_date'];

    if ($lecturer_id && $hall_id && !empty($course_code) && !empty($start_time) && !empty($end_time) && !empty($date)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO scheduled_classes (lecturer_id, hall_id, course_code, course_title, scheduled_start_time, scheduled_end_time, scheduled_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$lecturer_id, $hall_id, $course_code, $course_title, $start_time, $end_time, $date]);
            
            $msg = "Class scheduled for lecturer successfully!";
            logAuditTrail($_SESSION['user_id'], 'SCHEDULE_CLASS', 'scheduled_classes', $conn->lastInsertId('scheduled_classes_class_id_seq'));
        } catch (PDOException $e) {
            $err_msg = "Error scheduling class: " . $e->getMessage();
        }
    } else {
        $err_msg = "Please fill in all fields to schedule a lecture.";
    }
}

// Fetch Stats (PDO syntax)
try {
    $stats = [
        'scheduled' => $conn->query("SELECT COUNT(*) FROM scheduled_classes WHERE scheduled_date = CURRENT_DATE")->fetchColumn(),
        'verified' => $conn->query("SELECT COUNT(*) FROM attendance_submissions WHERE CAST(server_timestamp AS DATE) = CURRENT_DATE AND verification_status = 'VERIFIED'")->fetchColumn(),
        'out_of_range' => $conn->query("SELECT COUNT(*) FROM attendance_submissions WHERE CAST(server_timestamp AS DATE) = CURRENT_DATE AND verification_status = 'OUT_OF_RANGE'")->fetchColumn(),
        'anomalies' => $conn->query("SELECT COUNT(*) FROM attendance_submissions WHERE CAST(server_timestamp AS DATE) = CURRENT_DATE AND is_anomalous = TRUE")->fetchColumn()
    ];
} catch(PDOException $e) {
    $stats = ['scheduled' => 0, 'verified' => 0, 'out_of_range' => 0, 'anomalies' => 0];
}

// Fetch lecturers
$lecturers = [];
$res = $conn->query("SELECT user_id, full_name, department FROM users WHERE role = 'lecturer' AND is_active = TRUE ORDER BY full_name ASC");
$lecturers = $res->fetchAll();

// Fetch lecture halls
$halls = [];
$res = $conn->query("SELECT hall_id, hall_name, hall_code, latitude, longitude, altitude_meters, tolerance_radius_meters FROM lecture_halls ORDER BY hall_name ASC");
$halls = $res->fetchAll();

// Fetch all attendance logs
$attendance_logs = [];
try {
    $res = $conn->query("
        SELECT sub.submission_id, sc.course_code, u.full_name as lecturer_name, lh.hall_name, 
               sub.distance_from_assigned_location, sub.verification_status, sub.server_timestamp, sub.is_anomalous, sub.submission_description
        FROM attendance_submissions sub
        JOIN scheduled_classes sc ON sub.class_id = sc.class_id
        JOIN users u ON sub.lecturer_id = u.user_id
        JOIN lecture_halls lh ON sc.hall_id = lh.hall_id
        ORDER BY sub.server_timestamp DESC
        LIMIT 50
    ");
    $attendance_logs = $res->fetchAll();
} catch (PDOException $e) {
    // Silent fail
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Attendance Portal</title>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f2f6;
            display: flex;
            min-height: 100vh;
            color: #2c3e50;
        }

        /* Sidebar navigation */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #2f3640 0%, #1e272e 100%);
            color: white;
            padding: 30px 20px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar-brand {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-menu {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 15px;
            flex-grow: 1;
        }

        .sidebar-menu a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            padding: 12px 15px;
            border-radius: 8px;
            display: block;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
        }

        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: #3a7bd5;
            color: white;
        }

        .sidebar-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 20px;
        }

        .btn-logout {
            background-color: #e74c3c;
            color: white;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 8px;
            display: block;
            text-align: center;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-logout:hover { background-color: #c0392b; }

        /* Main Workspace */
        .main-workspace {
            flex-grow: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .main-header h1 {
            font-size: 26px;
            font-weight: 700;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 600;
            font-size: 14px;
        }

        .alert-success { background-color: #d4edda; color: #155724; border-left: 5px solid #28a745; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border-left: 5px solid #dc3545; }

        /* Dashboard Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-info h3 {
            font-size: 13px;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .stat-info p {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-icon {
            font-size: 32px;
            opacity: 0.8;
        }

        /* Tabs Panels */
        .panel {
            background-color: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            display: none;
            animation: fadeIn 0.4s ease;
        }

        .panel.active { display: block; }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .panel-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            border-bottom: 2px solid #f1f2f6;
            padding-bottom: 10px;
        }

        /* Two columns forms */
        .form-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        @media (max-width: 900px) {
            .form-layout { grid-template-columns: 1fr; }
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: #7f8c8d;
            margin-bottom: 6px;
        }

        input, select, textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #dcdde1;
            border-radius: 8px;
            font-size: 14px;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #3a7bd5;
            box-shadow: 0 0 0 3px rgba(58, 123, 213, 0.1);
        }

        .btn-submit {
            background-color: #3a7bd5;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn-submit:hover { background-color: #2962b7; }

        /* Map styling for dashboard coordinate picking */
        #picker-map, #tracker-map {
            width: 100%;
            height: 400px;
            border-radius: 12px;
            border: 1px solid #dcdde1;
            margin-bottom: 10px;
        }

        .map-help {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
            font-style: italic;
        }

        /* Table design */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f1f2f6;
            font-size: 13px;
        }

        th {
            background-color: #f8f9fb;
            color: #7f8c8d;
            font-weight: 700;
            text-transform: uppercase;
        }

        tr:hover { background-color: #fafbfd; }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-verified { background-color: #d1fae5; color: #065f46; }
        .badge-out_of_range { background-color: #fee2e2; color: #991b1b; }
        .badge-invalid_altitude { background-color: #e0f2fe; color: #0369a1; }
        .badge-invalid_time { background-color: #fef3c7; color: #92400e; }
        .badge-pending { background-color: #f3e8ff; color: #6b21a8; }

        .badge-anomaly {
            background-color: #ffcccc;
            color: #cc0000;
            border: 1px solid #ff3333;
        }

        /* Lecturer list item */
        .lec-list-item {
            padding: 12px 10px;
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 6px;
            transition: background 0.2s;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .lec-list-item:hover {
            background: #e8f0fe;
        }
        .lec-list-item.selected {
            background: #3a7bd5;
            color: white;
        }
        .lec-list-item.selected div {
            color: white !important;
        }
    </style>

</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <span>🏫</span>
            <span>Admin Portal</span>
        </div>
        <ul class="sidebar-menu">
            <li><a href="#" class="active" onclick="switchPanel('dashboard', this)">📊 Dashboard Overview</a></li>
            <li><a href="#" onclick="switchPanel('live-tracker-panel', this)">📡 Live Staff Tracker</a></li>
            <li><a href="#" onclick="switchPanel('lecturers-panel', this)">👥 Lecturers Tab</a></li>
            <li><a href="#" onclick="switchPanel('add-hall-panel', this)">📍 Add Lecture Hall (3D)</a></li>
            <li><a href="#" onclick="switchPanel('schedule-panel', this)">📅 Schedule Class</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="logout.php" class="btn-logout">LOG OUT</a>
        </div>
    </div>

    <!-- Main Workspace -->
    <div class="main-workspace">
        <div class="main-header">
            <div>
                <h1 style="color: #2c3e50;">Attendance Dashboard</h1>
                <p style="color: #7f8c8d; font-size: 14px;">Welcome, Administrator <strong><?php echo htmlspecialchars($full_name); ?></strong></p>
            </div>
            <div style="background-color: white; padding: 10px 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); font-size: 13px;">
                🕒 System Time: <strong><?php echo date('H:i'); ?></strong>
            </div>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert alert-success">✓ <?php echo $msg; ?></div>
        <?php endif; ?>

        <?php if (!empty($err_msg)): ?>
            <div class="alert alert-danger">⚠️ <?php echo $err_msg; ?></div>
        <?php endif; ?>

        <!-- Dashboard Panel -->
        <div id="dashboard" class="panel active">
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Classes Today</h3>
                        <p><?php echo $stats['scheduled']; ?></p>
                    </div>
                    <div class="stat-icon">📅</div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Verified Check-ins</h3>
                        <p><?php echo $stats['verified']; ?></p>
                    </div>
                    <div class="stat-icon">✅</div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Out-Of-Range Logs</h3>
                        <p style="color: #e74c3c;"><?php echo $stats['out_of_range']; ?></p>
                    </div>
                    <div class="stat-icon">❌</div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Flagged Anomalies</h3>
                        <p style="color: #d35400;"><?php echo $stats['anomalies']; ?></p>
                    </div>
                    <div class="stat-icon">🚨</div>
                </div>
            </div>

            <!-- Recent Submissions Table -->
            <div class="panel-title">Recent Attendance Check-Ins</div>
            <div style="overflow-x: auto;">
                <?php if (count($attendance_logs) === 0): ?>
                    <p style="text-align: center; color: #7f8c8d; padding: 30px;">No attendance submissions logged yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Lecturer</th>
                                <th>Course</th>
                                <th>Target Hall</th>
                                <th>Dist. (m)</th>
                                <th>Verification Status</th>
                                <th>Anomaly Flag</th>
                                <th>Activity Log</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_logs as $log): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['server_timestamp'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($log['lecturer_name']); ?></strong></td>
                                    <td><code><?php echo htmlspecialchars($log['course_code']); ?></code></td>
                                    <td><?php echo htmlspecialchars($log['hall_name']); ?></td>
                                    <td><?php echo $log['distance_from_assigned_location']; ?>m</td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($log['verification_status']); ?>">
                                            <?php echo $log['verification_status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['is_anomalous']): ?>
                                            <span class="badge badge-anomaly" title="Impossible speed anomaly detected!">🚨 Flagged</span>
                                        <?php else: ?>
                                            <span style="color: #2ecc71;">✓ Clear</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['submission_description']); ?>">
                                        <?php echo htmlspecialchars($log['submission_description']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Live Tracker Panel -->
        <div id="live-tracker-panel" class="panel">
            <div class="panel-title">📡 Real-Time Lecturer Tracking</div>
            <div id="tracker-map"></div>
            <p class="map-help">💡 This map displays active lecturer positions who are currently on shift. Refreshes automatically every 10 seconds.</p>
        </div>

        <!-- Lecturers Tab Panel -->
        <div id="lecturers-panel" class="panel">
            <div class="panel-title">👥 Lecturers Management</div>
            <div style="display: grid; grid-template-columns: 280px 1fr; gap: 25px; min-height: 600px;">
                
                <!-- Lecturer List Sidebar -->
                <div style="background: #f8f9fb; border-radius: 10px; padding: 15px; overflow-y: auto; max-height: 700px;">
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #7f8c8d; margin-bottom: 12px;">All Registered Lecturers</div>
                    <?php
                    $all_lecturers = $conn->query("SELECT user_id, full_name, department, faculty, lecturer_number, profile_pic, is_active FROM users WHERE role = 'lecturer' ORDER BY full_name ASC")->fetchAll();
                    if (count($all_lecturers) === 0): ?>
                        <p style="font-size: 13px; color: #bdc3c7;">No lecturers registered yet.</p>
                    <?php else: ?>
                        <?php foreach ($all_lecturers as $lec): ?>
                            <div class="lec-list-item" onclick="loadLecturerDetails(<?php echo $lec['user_id']; ?>)" id="lec-item-<?php echo $lec['user_id']; ?>">
                                <div style="display:flex; align-items:center; gap: 10px;">
                                    <?php if ($lec['profile_pic']): ?>
                                        <img src="<?php echo htmlspecialchars($lec['profile_pic']); ?>" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid #3a7bd5;">
                                    <?php else: ?>
                                        <div style="width: 38px; height: 38px; border-radius: 50%; background: #3a7bd5; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 15px;">
                                            <?php echo strtoupper(substr($lec['full_name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight: 700; font-size: 13px; color: #2c3e50;"><?php echo htmlspecialchars($lec['full_name']); ?></div>
                                        <div style="font-size: 11px; color: #7f8c8d;"><?php echo htmlspecialchars($lec['department'] ?? ''); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Lecturer Detail Panel -->
                <div id="lecturerDetailPanel" style="display: none;">
                    
                    <!-- Profile Header -->
                    <div style="display: flex; align-items: center; gap: 20px; background: linear-gradient(135deg, #3a7bd5 0%, #00d2ff 100%); border-radius: 12px; padding: 20px; color: white; margin-bottom: 20px;">
                        <img id="ldProfilePic" src="" alt="Profile" style="width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(255,255,255,0.5); display: none;">
                        <div id="ldInitialAvatar" style="width: 72px; height: 72px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: bold; color: white; border: 3px solid rgba(255,255,255,0.4);">?</div>
                        <div>
                            <div id="ldFullName" style="font-size: 22px; font-weight: 700;"></div>
                            <div id="ldFaculty" style="font-size: 13px; opacity: 0.85;"></div>
                            <div id="ldDept" style="font-size: 13px; opacity: 0.85;"></div>
                            <div id="ldLecNum" style="font-size: 12px; opacity: 0.7; margin-top: 4px;"></div>
                        </div>
                    </div>

                    <!-- Bio Data -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px;">
                        <div style="background: white; border-radius: 10px; padding: 15px; box-shadow: 0 1px 5px rgba(0,0,0,0.05);">
                            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #7f8c8d; margin-bottom: 5px;">Email</div>
                            <div id="ldEmail" style="font-size: 13px;"></div>
                        </div>
                        <div style="background: white; border-radius: 10px; padding: 15px; box-shadow: 0 1px 5px rgba(0,0,0,0.05);">
                            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #7f8c8d; margin-bottom: 5px;">Phone</div>
                            <div id="ldPhone" style="font-size: 13px;"></div>
                        </div>
                    </div>

                    <!-- Shift Log & Date Picker -->
                    <div style="background: white; border-radius: 10px; padding: 15px; margin-bottom: 20px; box-shadow: 0 1px 5px rgba(0,0,0,0.05);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <div style="font-size: 13px; font-weight: 700;">📅 Daily Shift Log</div>
                            <input type="date" id="ldDatePicker" value="<?php echo date('Y-m-d'); ?>" onchange="reloadLecturerDate()" style="padding: 5px 10px; border-radius: 6px; border: 1px solid #dcdde1; font-size: 12px; width: auto;">
                        </div>
                        <div id="ldShiftInfo" style="font-size: 13px; color: #57606f;"></div>
                    </div>

                    <!-- Timetable -->
                    <div style="background: white; border-radius: 10px; padding: 15px; margin-bottom: 20px; box-shadow: 0 1px 5px rgba(0,0,0,0.05); max-height: 200px; overflow-y: auto;">
                        <div style="font-size: 13px; font-weight: 700; margin-bottom: 10px;">📚 Schedule / Timetable</div>
                        <table style="margin-top: 0;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Course</th>
                                    <th>Time</th>
                                    <th>Venue</th>
                                </tr>
                            </thead>
                            <tbody id="ldScheduleBody">
                                <tr><td colspan="4" style="text-align:center; color:#bdc3c7;">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 2D Movement Map -->
                    <div style="background: white; border-radius: 10px; padding: 15px; box-shadow: 0 1px 5px rgba(0,0,0,0.05);">
                        <div style="font-size: 13px; font-weight: 700; margin-bottom: 5px;">🗺️ Movement Map (Caleb University Campus)</div>
                        <p style="font-size: 11px; color: #7f8c8d; margin-bottom: 10px;">Nodes (●) and connecting lines show lecturer movement path recorded for the selected date.</p>
                        <div id="lecturerMovementMap" style="width: 100%; height: 350px; border-radius: 8px; border: 1px solid #e1e8ef;"></div>
                        <div id="ldMovementCount" style="font-size: 12px; color: #7f8c8d; margin-top: 8px;"></div>
                    </div>
                </div>

                <!-- Empty State if no lecturer selected -->
                <div id="lecturerDetailEmpty" style="display: flex; align-items: center; justify-content: center; text-align: center; padding: 60px 20px;">
                    <div>
                        <div style="font-size: 50px; margin-bottom: 15px;">👈</div>
                        <p style="color: #7f8c8d; font-weight: 600;">Select a lecturer from the list to view their details.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Lecture Hall Panel (3D) -->

        <div id="add-hall-panel" class="panel">
            <div class="panel-title">Configure 3D Lecture Hall Geofence</div>
            
            <div class="form-layout">
                <form action="admin_dashboard.php" method="POST">
                    <input type="hidden" name="add_hall" value="1">
                    
                    <div class="form-group">
                        <label for="hall_name">Lecture Hall Name *</label>
                        <input type="text" id="hall_name" name="hall_name" placeholder="e.g. Science Auditorium Block A" required>
                    </div>

                    <div class="form-group">
                        <label for="hall_code">Unique Hall Code *</label>
                        <input type="text" id="hall_code" name="hall_code" placeholder="e.g. AUD-SCI-A" required>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label for="tolerance">Horizontal Radius (m)</label>
                            <input type="number" id="tolerance" name="tolerance" value="30" required>
                        </div>
                        <div class="form-group">
                            <label for="altitude_tolerance">Altitude Tolerance (m)</label>
                            <input type="number" step="0.5" id="altitude_tolerance" name="altitude_tolerance" value="2.5" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label for="latitude">Latitude Coordinate *</label>
                            <input type="text" id="latitude" name="latitude" readonly required placeholder="Click map to pick">
                        </div>
                        <div class="form-group">
                            <label for="longitude">Longitude Coordinate *</label>
                            <input type="text" id="longitude" name="longitude" readonly required placeholder="Click map to pick">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="altitude">Base Altitude Reference (Floor Height in Meters) *</label>
                        <select id="altitude" name="altitude">
                            <option value="0.00" selected>Ground Floor (0m)</option>
                            <option value="3.00">First Floor (+3m)</option>
                            <option value="6.00">Second Floor (+6m)</option>
                            <option value="9.00">Third Floor (+9m)</option>
                            <option value="12.00">Fourth Floor (+12m)</option>
                        </select>
                        <small style="color: #7f8c8d; font-size: 11px;">Restricts check-in based on height. A lecturer must be close to this floor level height.</small>
                    </div>

                    <div class="form-group">
                        <label for="description">Additional Notes</label>
                        <textarea id="description" name="description" placeholder="Building floor level, nearby landmarks..."></textarea>
                    </div>

                    <button type="submit" class="btn-submit">SAVE LECTURE HALL</button>
                </form>

                <div>
                    <label>Click on the map to pin room location coordinates</label>
                    <div id="picker-map"></div>
                    <p class="map-help">💡 Drag or zoom the map, then click on the target campus location. The form coordinates will auto-fill.</p>
                </div>
            </div>
        </div>

        <!-- Schedule Class Panel -->
        <div id="schedule-panel" class="panel">
            <div class="panel-title">Schedule Lecturer Assignments</div>
            
            <form action="admin_dashboard.php" method="POST" style="max-width: 600px;">
                <input type="hidden" name="schedule_class" value="1">
                
                <div class="form-group">
                    <label for="lecturer_id">Assign Lecturer *</label>
                    <select id="lecturer_id" name="lecturer_id" required>
                        <option value="">-- Select Lecturer --</option>
                        <?php foreach ($lecturers as $lec): ?>
                            <option value="<?php echo $lec['user_id']; ?>">
                                <?php echo htmlspecialchars($lec['full_name']); ?> (<?php echo htmlspecialchars($lec['department']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="hall_id">Lecture Hall Venue *</label>
                    <select id="hall_id" name="hall_id" required>
                        <option value="">-- Select Hall Venue --</option>
                        <?php foreach ($halls as $h): ?>
                            <option value="<?php echo $h['hall_id']; ?>">
                                <?php echo htmlspecialchars($h['hall_name']); ?> [<?php echo htmlspecialchars($h['hall_code']); ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="course_code">Course Code *</label>
                    <input type="text" id="course_code" name="course_code" placeholder="e.g. CSC 401" required>
                </div>

                <div class="form-group">
                    <label for="course_title">Course Title *</label>
                    <input type="text" id="course_title" name="course_title" placeholder="e.g. Distributed Database Architecture" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="start_time">Start Time *</label>
                        <input type="time" id="start_time" name="start_time" required>
                    </div>
                    <div class="form-group">
                        <label for="end_time">End Time *</label>
                        <input type="time" id="end_time" name="end_time" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="scheduled_date">Schedule Date *</label>
                    <input type="date" id="scheduled_date" name="scheduled_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <button type="submit" class="btn-submit">SCHEDULE CLASS SLOT</button>
            </form>
        </div>
    </div>

    <!-- Leaflet JS Map Library -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        let pickerMap = null;
        let selectedMarker = null;
        let trackerMap = null;
        let trackerMarkers = {};

        function initPickerMap() {
            const centerLat = 6.5244;
            const centerLon = 3.3792;
            
            pickerMap = L.map('picker-map').setView([centerLat, centerLon], 10);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(pickerMap);

            pickerMap.on('click', function(e) {
                const lat = e.latlng.lat;
                const lon = e.latlng.lng;

                document.getElementById('latitude').value = lat.toFixed(7);
                document.getElementById('longitude').value = lon.toFixed(7);

                if (selectedMarker) {
                    selectedMarker.setLatLng(e.latlng);
                } else {
                    selectedMarker = L.marker(e.latlng).addTo(pickerMap);
                }
                pickerMap.panTo(e.latlng);
            });
        }

        function initTrackerMap() {
            const centerLat = 6.5244;
            const centerLon = 3.3792;

            trackerMap = L.map('tracker-map').setView([centerLat, centerLon], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(trackerMap);

            // Fetch pings immediately, and set interval
            fetchLivePings();
            setInterval(fetchLivePings, 10000);
        }

        async function fetchLivePings() {
            if (!trackerMap) return;

            try {
                const response = await fetch('admin_dashboard.php?get_live_pings=1');
                const pings = await response.json();

                // Clear markers not in update
                const activeNames = pings.map(p => p.full_name);
                for (let name in trackerMarkers) {
                    if (!activeNames.includes(name)) {
                        trackerMarkers[name].removeFrom(trackerMap);
                        delete trackerMarkers[name];
                    }
                }

                // Add or update markers
                pings.forEach(ping => {
                    const lat = parseFloat(ping.latitude);
                    const lon = parseFloat(ping.longitude);
                    const name = ping.full_name;
                    const dept = ping.department;
                    const alt = parseFloat(ping.altitude);
                    const time = new Date(ping.last_updated).toLocaleTimeString();

                    const popupText = `
                        <strong>👤 ${name}</strong><br>
                        🏢 Dept: ${dept}<br>
                        📐 Altitude: ${alt.toFixed(1)}m<br>
                        🕒 Last Ping: ${time}
                    `;

                    if (trackerMarkers[name]) {
                        trackerMarkers[name].setLatLng([lat, lon]).setPopupContent(popupText);
                    } else {
                        trackerMarkers[name] = L.marker([lat, lon]).addTo(trackerMap).bindPopup(popupText);
                    }
                });

                // Adjust bounds to fit all markers if any exist
                const markerList = Object.values(trackerMarkers);
                if (markerList.length > 0) {
                    const group = new L.featureGroup(markerList);
                    trackerMap.fitBounds(group.getBounds().pad(0.2));
                }

            } catch (err) {
                console.error("Error loading live tracking coordinates:", err);
            }
        }

        function switchPanel(panelId, element) {
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.sidebar-menu a').forEach(a => a.classList.remove('active'));

            document.getElementById(panelId).classList.add('active');
            element.classList.add('active');

            if (panelId === 'add-hall-panel' && !pickerMap) {
                setTimeout(() => {
                    initPickerMap();
                }, 100);
            }

            if (panelId === 'live-tracker-panel' && !trackerMap) {
                setTimeout(() => {
                    initTrackerMap();
                }, 100);
            }
        }

        // ==============================
        // Lecturer Detail & Movement Map
        // ==============================
        let currentLecturerId = null;
        let movementMap = null;
        let movementPolyline = null;
        let movementMarkers = [];

        async function loadLecturerDetails(lecId) {
            currentLecturerId = lecId;
            const date = document.getElementById('ldDatePicker').value;

            // Highlight selected lecturer in list
            document.querySelectorAll('.lec-list-item').forEach(el => el.classList.remove('selected'));
            const selectedEl = document.getElementById('lec-item-' + lecId);
            if (selectedEl) selectedEl.classList.add('selected');

            // Show detail panel, hide empty state
            document.getElementById('lecturerDetailPanel').style.display = 'block';
            document.getElementById('lecturerDetailEmpty').style.display = 'none';

            try {
                const response = await fetch(`admin_dashboard.php?get_lecturer_details=${lecId}&date=${date}`);
                const data = await response.json();

                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }

                const p = data.profile;

                // Profile
                document.getElementById('ldFullName').textContent = p.full_name || '';
                document.getElementById('ldFaculty').textContent = p.faculty ? `🏛️ ${p.faculty}` : '';
                document.getElementById('ldDept').textContent = p.department ? `📂 ${p.department}` : '';
                document.getElementById('ldLecNum').textContent = p.lecturer_number ? `🪪 ${p.lecturer_number}` : '';
                document.getElementById('ldEmail').textContent = p.email || 'N/A';
                document.getElementById('ldPhone').textContent = p.phone || 'N/A';

                // Profile picture
                const picEl = document.getElementById('ldProfilePic');
                const avatarEl = document.getElementById('ldInitialAvatar');
                if (p.profile_pic) {
                    picEl.src = p.profile_pic;
                    picEl.style.display = 'block';
                    avatarEl.style.display = 'none';
                } else {
                    picEl.style.display = 'none';
                    avatarEl.style.display = 'flex';
                    avatarEl.textContent = (p.full_name || '?').charAt(0).toUpperCase();
                }

                // Shift info
                const shiftEl = document.getElementById('ldShiftInfo');
                if (data.shift) {
                    const s = data.shift;
                    const inTime = s.sign_in_time ? new Date(s.sign_in_time).toLocaleTimeString() : '—';
                    const outTime = s.sign_out_time ? new Date(s.sign_out_time).toLocaleTimeString() : '—';
                    const method = s.sign_out_method === 'auto_geofence' ? ' (Auto – Left Campus)' : s.sign_out_method === 'manual' ? ' (Manual)' : '';
                    shiftEl.innerHTML = `
                        <span style="color:#2ecc71; font-weight:bold;">✅ Signed In:</span> ${inTime} 
                        &nbsp;|&nbsp; 
                        <span style="color:#e74c3c; font-weight:bold;">🔴 Signed Out:</span> ${outTime}${method}
                    `;
                } else {
                    shiftEl.innerHTML = `<span style="color:#7f8c8d;">No shift record found for this date.</span>`;
                }

                // Timetable / Schedule
                const tbody = document.getElementById('ldScheduleBody');
                if (data.schedule && data.schedule.length > 0) {
                    tbody.innerHTML = data.schedule.map(sc => `
                        <tr>
                            <td>${sc.scheduled_date}</td>
                            <td><strong>${sc.course_code}</strong><br><small style="color:#7f8c8d;">${sc.course_title}</small></td>
                            <td>${sc.scheduled_start_time.substring(0,5)} – ${sc.scheduled_end_time.substring(0,5)}</td>
                            <td>${sc.hall_name}</td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#bdc3c7;">No schedule records found.</td></tr>';
                }

                // 2D Movement Map
                setTimeout(() => {
                    drawMovementMap(data.movement);
                }, 150);

            } catch (err) {
                console.error('Failed to load lecturer details:', err);
            }
        }

        function reloadLecturerDate() {
            if (currentLecturerId) {
                loadLecturerDetails(currentLecturerId);
            }
        }

        function drawMovementMap(movementData) {
            // Init map centred on Caleb University
            if (!movementMap) {
                movementMap = L.map('lecturerMovementMap').setView([6.6718, 3.4908], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap'
                }).addTo(movementMap);

                // Draw campus geofence boundary
                L.circle([6.6718, 3.4908], {
                    color: '#3a7bd5',
                    fillColor: '#3a7bd5',
                    fillOpacity: 0.05,
                    radius: 800,
                    dashArray: '8,6',
                    weight: 2
                }).addTo(movementMap).bindTooltip('Caleb University Campus Boundary', { permanent: false });
            } else {
                // Clear old movement layers
                movementMarkers.forEach(m => m.remove());
                movementMarkers = [];
                if (movementPolyline) {
                    movementPolyline.remove();
                    movementPolyline = null;
                }
            }

            const countEl = document.getElementById('ldMovementCount');

            if (!movementData || movementData.length === 0) {
                countEl.textContent = 'No movement data logged for this date.';
                return;
            }

            const latlngs = movementData.map(m => [parseFloat(m.latitude), parseFloat(m.longitude)]);

            // Draw connecting polyline (movement path)
            movementPolyline = L.polyline(latlngs, {
                color: '#3a7bd5',
                weight: 3,
                opacity: 0.75,
                dashArray: '4, 4'
            }).addTo(movementMap);

            // Draw node markers
            latlngs.forEach((latlng, i) => {
                const m = movementData[i];
                const timeStr = new Date(m.logged_at).toLocaleTimeString();
                const isFirst = i === 0;
                const isLast = i === latlngs.length - 1;

                let color = '#3a7bd5';
                if (isFirst) color = '#2ecc71';
                if (isLast) color = '#e74c3c';

                const marker = L.circleMarker(latlng, {
                    radius: isFirst || isLast ? 8 : 5,
                    color: color,
                    fillColor: color,
                    fillOpacity: 0.9,
                    weight: 2
                }).addTo(movementMap)
                  .bindPopup(`<strong>${isFirst ? '🟢 First Ping' : isLast ? '🔴 Last Ping' : '📍 Ping'}</strong><br>Time: ${timeStr}<br>Alt: ${parseFloat(m.altitude).toFixed(1)}m`);

                movementMarkers.push(marker);
            });

            // Fit bounds to movement path
            movementMap.fitBounds(L.polyline(latlngs).getBounds().pad(0.2));

            countEl.textContent = `${movementData.length} location pings logged · First: ${new Date(movementData[0].logged_at).toLocaleTimeString()} · Last: ${new Date(movementData[movementData.length-1].logged_at).toLocaleTimeString()}`;
        }
    </script>
</body>
</html>

