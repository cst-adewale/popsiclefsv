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
    </script>
</body>
</html>
