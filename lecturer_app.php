<?php
/**
 * School Attendance Verification System
 * lecturer_app.php - Mobile-first Lecturer Check-in Application (Supabase Edition)
 */

require 'config.php';
session_start();

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header('Location: login.php');
    exit;
}

$lecturer_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

$selected_week = $_GET['week'] ?? '';
$week_start_date = null;
if (preg_match('/^\\d{4}-W\\d{2}$/', $selected_week)) {
    $week_start_date = date('Y-m-d', strtotime($selected_week . '-1'));
} else {
    $week_start_date = date('Y-m-d', strtotime('monday this week'));
    $selected_week = date('o-\\WW', strtotime($week_start_date));
}
$week_end_date = date('Y-m-d', strtotime($week_start_date . ' +7 days'));

function weekLabelFromWeekParam($weekParam) {
    if (!preg_match('/^(\\d{4})-W(\\d{2})$/', $weekParam, $matches)) {
        return $weekParam;
    }

    $year = $matches[1];
    $week = $matches[2];
    $start = new DateTime();
    $start->setISODate((int)$year, (int)$week, 1);
    $end = clone $start;
    $end->modify('+4 days');
    return 'W' . $week . ' (' . $start->format('M j') . ' - ' . $end->format('M j') . ')';
}

$week_navigation = [];
$baseWeek = new DateTime($week_start_date);
for ($i = -4; $i <= 4; $i++) {
    $weekStart = clone $baseWeek;
    if ($i !== 0) {
        $weekStart->modify(($i > 0 ? '+' : '') . ($i * 7) . ' days');
    }
    $weekStart->modify('monday this week');
    $weekKey = $weekStart->format('o-\WW');
    $weekNavigationLabel = weekLabelFromWeekParam($weekKey);
    $week_navigation[] = [
        'key' => $weekKey,
        'label' => $weekNavigationLabel,
        'active' => $weekKey === $selected_week,
        'offset' => $i
    ];
}
$selectedWeekIndex = 0;
foreach ($week_navigation as $idx => $navWeek) {
    if ($navWeek['active']) {
        $selectedWeekIndex = $idx;
        break;
    }
}

// Get classes scheduled for today using PostgreSQL PDO syntax
$stmt = $conn->prepare("
    SELECT sc.class_id, sc.course_code, sc.course_title, sc.scheduled_start_time, sc.scheduled_end_time, 
           lh.hall_name, lh.latitude, lh.longitude, lh.altitude_meters, lh.tolerance_radius_meters, sc.status
    FROM scheduled_classes sc
    JOIN lecture_halls lh ON sc.hall_id = lh.hall_id
    WHERE sc.lecturer_id = ? AND sc.scheduled_date = CURRENT_DATE
    ORDER BY sc.scheduled_start_time ASC
");
$stmt->execute([$lecturer_id]);
$scheduled_classes = $stmt->fetchAll();

// Fetch today's shift status
$stmt_shift = $conn->prepare("
    SELECT sign_in_time, sign_out_time, sign_out_method 
    FROM lecturer_shifts 
    WHERE lecturer_id = ? AND work_date = CURRENT_DATE
");
$stmt_shift->execute([$lecturer_id]);
$today_shift = $stmt_shift->fetch();

// Fetch all lecture halls
$stmt_halls = $conn->query("SELECT hall_id, hall_name, hall_code FROM lecture_halls ORDER BY hall_name ASC");
$all_halls = $stmt_halls->fetchAll();

// Fetch selected week lecturer schedules, then group them in the UI by weekday/date
$stmt_week = $conn->prepare("
    SELECT sc.class_id, sc.course_code, sc.course_title, sc.scheduled_start_time, sc.scheduled_end_time, sc.scheduled_date,
           lh.hall_id, lh.hall_name, sc.status
    FROM scheduled_classes sc
    JOIN lecture_halls lh ON sc.hall_id = lh.hall_id
    WHERE sc.lecturer_id = ?
      AND sc.scheduled_date >= ?
      AND sc.scheduled_date < ?
      AND EXTRACT(ISODOW FROM sc.scheduled_date) BETWEEN 1 AND 5
    ORDER BY sc.scheduled_date ASC, sc.scheduled_start_time ASC
");
$stmt_week->execute([$lecturer_id, $week_start_date, $week_end_date]);
$week_classes = $stmt_week->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Caleb FSV — Lecturer Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- ======= PWA MANIFEST ======= -->
    <link rel="manifest" href="/manifest.json">

    <!-- ======= PWA THEME ======= -->
    <meta name="theme-color" content="#3a7bd5">
    <meta name="mobile-web-app-capable" content="yes">

    <!-- ======= iOS / APPLE PWA SUPPORT ======= -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Caleb FSV">
    <link rel="apple-touch-icon" href="/icons/icon-512.png">
    <!-- iOS Splash Screens (optional but recommended for best experience) -->
    <link rel="apple-touch-startup-image" href="/icons/icon-512.png">

    <!-- ======= STANDARD FAVICON ======= -->
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/icons/icon-512.png">

    <!-- ======= SEO / DESCRIPTION ======= -->
    <meta name="description" content="GPS-verified lecturer check-in portal with 3D floor-level geofencing.">

    <!-- Leaflet CSS for Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

    <!-- ======= SERVICE WORKER REGISTRATION ======= -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then((reg) => console.log('[PWA] Service Worker registered:', reg.scope))
                    .catch((err) => console.warn('[PWA] Service Worker registration failed:', err));
            });
        }
    </script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background: #f7f8fa;
            min-height: 100vh;
            color: #2f3640;
        }

        /* App Header */
        .app-shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: #f7f8fa;
        }

        .app-header {
            background: linear-gradient(135deg, #214f3b 0%, #3a7bd5 100%);
            padding: 18px 16px;
            color: white;
            box-shadow: 0 4px 15px rgba(58, 123, 213, 0.25);
            flex-shrink: 0;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .user-info h2 {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .user-info p {
            font-size: 12px;
            opacity: 0.85;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .install-btn {
            background: #ffffff;
            color: #214f3b;
            border: 1px solid rgba(255,255,255,0.35);
            padding: 6px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: none;
        }

        .install-btn:hover {
            background: #f3f7f4;
        }

        /* Content Area */
        .app-content {
            flex: 1;
            overflow-y: auto;
            padding: 14px;
            background-color: #f8f9fb;
        }

        .section-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            color: #7f8c8d;
            margin-bottom: 10px;
            margin-top: 5px;
            letter-spacing: 0.5px;
        }

        /* Class Card Lists */
        .class-card {
            background: white;
            border-radius: 14px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.04);
            border-left: 5px solid #3a7bd5;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }

        .class-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .calendar-shell {
            background: #fff;
            border: 1px solid #e5e8ee;
            border-radius: 14px;
            padding: 12px;
            box-shadow: 0 6px 18px rgba(26,26,46,0.04);
            margin-bottom: 12px;
        }

        .calendar-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
        }

        .calendar-title {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .week-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(120px, 1fr));
            gap: 8px;
            overflow-x: auto;
        }

        .day-col {
            min-width: 120px;
            border: 1px solid #e5e8ee;
            border-radius: 12px;
            background: #fafbfc;
            overflow: hidden;
        }

        .day-col.active {
            border-color: #214f3b;
            box-shadow: 0 8px 18px rgba(33,79,59,0.08);
        }

        .day-head {
            padding: 8px 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
            background: linear-gradient(135deg, #214f3b, #3a7bd5);
        }

        .day-body {
            padding: 8px;
            min-height: 120px;
        }

        .day-empty {
            font-size: 12px;
            color: #8b93a1;
            padding: 10px 2px;
        }

        .day-event {
            background: #fff;
            border: 1px solid #e5e8ee;
            border-left: 4px solid #214f3b;
            border-radius: 10px;
            padding: 8px;
            margin-bottom: 8px;
        }

        .day-event-time {
            font-size: 11px;
            color: #3a7bd5;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .day-event-code {
            font-size: 12px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .day-event-venue {
            font-size: 11px;
            color: #8b93a1;
            margin-top: 2px;
        }

        .calendar-nav-btn {
            border: 1px solid #e5e8ee;
            background: #fff;
            color: #1a1a2e;
            border-radius: 10px;
            padding: 8px 10px;
            font-weight: 700;
            cursor: pointer;
        }

        .calendar-nav-btn:hover {
            background: #f7f8fa;
        }

        .class-card.completed {
            border-left-color: #2ecc71;
            opacity: 0.85;
        }

        .class-card.active {
            border: 2px solid #3a7bd5;
            border-left-width: 6px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .course-code {
            font-size: 15px;
            font-weight: 700;
            color: #2c3e50;
        }

        .status-badge {
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-scheduled { background-color: #ffeaa7; color: #d63031; }
        .badge-completed { background-color: #d1fae5; color: #065f46; }

        .course-title {
            font-size: 13px;
            color: #7f8c8d;
            margin-bottom: 10px;
        }

        .card-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #57606f;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Checkin Form Style */
        .checkin-form-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            display: none;
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #7f8c8d;
            text-transform: uppercase;
        }

        input[readonly], textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e1e8ef;
            border-radius: 8px;
            font-size: 13px;
            background-color: #fcfdfe;
        }

        textarea {
            resize: none;
            height: 70px;
        }

        textarea:focus {
            outline: none;
            border-color: #3a7bd5;
        }

        /* Action Buttons */
        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn-gps {
            background-color: #3a7bd5;
            color: white;
            margin-bottom: 10px;
        }

        .btn-gps:hover { background-color: #2962b7; }

        .btn-submit {
            background-color: #2ecc71;
            color: white;
        }

        .btn-submit:hover { background-color: #27ae60; }
        .btn-submit:disabled {
            background-color: #cbd5e0;
            cursor: not-allowed;
        }

        .btn-cancel {
            background-color: #e2e8f0;
            color: #4a5568;
            margin-top: 8px;
        }

        /* Leaflet Map Styling */
        #map-container {
            width: 100%;
            height: 150px;
            border-radius: 10px;
            margin-bottom: 15px;
            border: 1px solid #e1e8ef;
        }

        /* Results Box */
        .result-box {
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
            border-left: 4px solid;
            font-size: 13px;
            line-height: 1.5;
        }

        .result-box.success {
            background-color: #ebfaf0;
            border-left-color: #2ecc71;
            color: #1e7e34;
        }

        .result-box.error {
            background-color: #fdf2f2;
            border-left-color: #e74c3c;
            color: #c0392b;
        }

        .result-box.warning {
            background-color: #fffaf0;
            border-left-color: #f39c12;
            color: #d35400;
        }

        /* Loader Overlay */
        .loader-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.85);
            display: none;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            z-index: 1000;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3a7bd5;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin-bottom: 12px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Bottom Nav tab bar mockup */
        .app-nav {
            height: 60px;
            background-color: white;
            border-top: 1px solid #e1e8ef;
            display: flex;
            justify-content: space-around;
            align-items: center;
            flex-shrink: 0;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 10px;
            color: #7f8c8d;
            cursor: pointer;
            text-decoration: none;
        }

        .nav-item.active {
            color: #3a7bd5;
        }

        .nav-icon {
            font-size: 20px;
            margin-bottom: 3px;
        }

        /* Shift Card & Timetable Styles */
        .shift-card {
            background: white;
            border-radius: 14px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.04);
            border: 1px solid #e1e8ef;
        }
        .shift-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            font-weight: bold;
            color: #2c3e50;
            border-bottom: 1px solid #f1f2f6;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .shift-btn-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        .btn-shift {
            padding: 10px;
            font-size: 12px;
            border-radius: 8px;
            font-weight: bold;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn-shift-in {
            background: #2ecc71;
            color: white;
        }
        .btn-shift-out {
            background: #e74c3c;
            color: white;
        }
        .btn-shift:disabled {
            background: #cbd5e0;
            color: #718096;
            cursor: not-allowed;
        }
        .timetable-container {
            background: white;
            border-radius: 14px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.04);
        }
        .timetable-day-header {
            font-weight: 700;
            color: #2c3e50;
            background: #f1f2f6;
            padding: 6px 10px;
            border-radius: 6px;
            margin-top: 15px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .timetable-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #f1f2f6;
            font-size: 13px;
        }
        .timetable-item:last-child {
            border-bottom: none;
        }
        .timetable-time {
            font-weight: bold;
            color: #3a7bd5;
            min-width: 90px;
        }
        .timetable-details {
            flex-grow: 1;
            padding-left: 10px;
        }
        .timetable-actions {
            display: flex;
            gap: 5px;
        }
        .btn-small {
            padding: 4px 8px;
            font-size: 10px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            color: white;
        }
        .btn-edit { background: #3498db; }
        .btn-delete { background: #e74c3c; }
        .btn-create-slot {
            background: #3a7bd5;
            color: white;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: bold;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            margin-top: 10px;
            display: block;
            width: 100%;
            text-align: center;
            text-decoration: none;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            width: 90%;
            max-width: 400px;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            animation: slideUp 0.3s ease-out;
        }
        .modal-header {
            font-weight: bold;
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-close {
            cursor: pointer;
            font-size: 20px;
            color: #7f8c8d;
        }
        .modal select, .modal input {
            width: 100%;
            padding: 10px;
            margin-bottom: 12px;
            border: 1px solid #e1e8ef;
            border-radius: 8px;
            font-size: 13px;
        }


        /* Height/Altitude Simulator Panel style */
        .simulator-badge {
            background-color: #e0f7fa;
            border: 1px solid #00acc1;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 12px;
            color: #006064;
        }

        .slider-group {
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .slider-group input {
            flex-grow: 1;
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <!-- Loader Screen -->
        <div class="loader-overlay" id="loaderOverlay">
            <div class="spinner"></div>
            <p style="font-weight: 600; color: #2c3e50;">Verifying 3D Geofence...</p>
            <p style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">Testing horizontal coordinates & altitude...</p>
        </div>

        <!-- Header -->
        <div class="app-header">
            <div class="header-top">
                <div class="user-info">
                    <p>Welcome back,</p>
                    <h2><?php echo htmlspecialchars($full_name); ?></h2>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
                    <button id="installAppBtn" class="install-btn" type="button">Install App</button>
                    <a href="logout.php" class="logout-btn">Log Out</a>
                </div>
            </div>
            <div style="font-size: 12px; opacity: 0.9; display: flex; align-items: center; gap: 5px;">
                <span>📅 Today:</span> <strong><?php echo date('F d, Y'); ?></strong>
            </div>
        </div>

        <!-- Main Scrollable Content -->
        <div class="app-content" id="appContent">
            
            <!-- Daily Shift Tracking Card -->
            <div class="shift-card">
                <div class="shift-header">
                    <span>🕒 Daily Shift Status</span>
                    <?php if (!$today_shift): ?>
                        <span class="shift-status-inactive">🔴 NOT SIGNED IN</span>
                    <?php elseif ($today_shift['sign_out_time'] === null): ?>
                        <span class="shift-status-active">🟢 SIGNED IN</span>
                    <?php else: ?>
                        <span style="color: #7f8c8d; font-weight: bold;">⚫ SIGNED OUT (<?php echo date('H:i', strtotime($today_shift['sign_out_time'])); ?>)</span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 12px; color: #57606f; line-height: 1.4;">
                    <?php if (!$today_shift): ?>
                        Tracking runs 8am - 4pm. Sign in to start scanning.
                    <?php elseif ($today_shift['sign_out_time'] === null): ?>
                        Signed in at: <strong><?php echo date('H:i', strtotime($today_shift['sign_in_time'])); ?></strong><br>
                        Location tracking is active. Leaving the campus geofence will automatically sign you out.
                    <?php else: ?>
                        Signed out at: <strong><?php echo date('H:i', strtotime($today_shift['sign_out_time'])); ?></strong> 
                        (<?php echo $today_shift['sign_out_method'] === 'auto_geofence' ? 'Auto-detected off-campus' : 'Manual'; ?>)
                    <?php endif; ?>
                </div>
                <div class="shift-btn-container">
                    <button class="btn-shift btn-shift-in" id="shiftInBtn" onclick="handleShiftAction('signin')" 
                        <?php echo ($today_shift) ? 'disabled' : ''; ?>>
                        Sign In
                    </button>
                    <button class="btn-shift btn-shift-out" id="shiftOutBtn" onclick="handleShiftAction('signout')"
                        <?php echo (!$today_shift || $today_shift['sign_out_time'] !== null) ? 'disabled' : ''; ?>>
                        Sign Out
                    </button>
                </div>
            </div>

            <!-- Dynamic Classes View -->
            <div id="scheduleSection">
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <button id="tabTodayBtn" class="btn btn-gps" style="flex: 1; padding: 8px; font-size: 12px; margin-bottom: 0;" onclick="switchScheduleTab('today')">Today's List</button>
                    <button id="tabWeekBtn" class="btn btn-cancel" style="flex: 1; padding: 8px; font-size: 12px; margin-top: 0;" onclick="switchScheduleTab('week')">Weekly Timetable</button>
                </div>
                <button class="btn-create-slot" style="margin-bottom:12px;" onclick="openCreateScheduleModal()">+ Create New Schedule Slot</button>

                <!-- Today Schedule Tab -->
                <div id="todayScheduleTab">
                    <div class="section-title">Today's Class Schedule</div>
                    <?php if (count($scheduled_classes) === 0): ?>
                        <div style="text-align: center; padding: 40px 20px; background: white; border-radius: 12px; margin-top: 10px;">
                            <span style="font-size: 40px;">☕</span>
                            <p style="font-weight: 600; margin-top: 10px; color: #7f8c8d;">No lectures scheduled today</p>
                            <p style="font-size: 12px; color: #bdc3c7; margin-top: 5px;">Enjoy your free hours!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($scheduled_classes as $class): ?>
                            <div class="class-card <?php echo $class['status'] === 'completed' ? 'completed' : ''; ?>" 
                                 onclick="selectClass(<?php echo htmlspecialchars(json_encode($class)); ?>)">
                                <div class="card-header">
                                    <span class="course-code"><?php echo htmlspecialchars($class['course_code']); ?></span>
                                    <span class="status-badge badge-<?php echo $class['status']; ?>">
                                        <?php echo htmlspecialchars($class['status']); ?>
                                    </span>
                                </div>
                                <div class="course-title"><?php echo htmlspecialchars($class['course_title']); ?></div>
                                <div class="card-meta">
                                    <div class="meta-item">
                                        <span>🕒</span>
                                        <span><?php echo date('H:i', strtotime($class['scheduled_start_time'])) . ' - ' . date('H:i', strtotime($class['scheduled_end_time'])); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span>📍</span>
                                        <span><?php echo htmlspecialchars($class['hall_name']); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Weekly Timetable Tab -->
                <div id="weekScheduleTab" style="display: none;">
                    <div class="section-title">Weekly Monday - Friday Timetable</div>
                    <div style="font-size:12px;color:#8B93A1;margin-bottom:10px">Pick an ISO week number first, then review the timetable for that week.</div>
                    <div class="calendar-shell">
                        <div class="calendar-head">
                            <a class="calendar-nav-btn" href="lecturer_app.php?week=<?php echo urlencode($week_navigation[max(0, $selectedWeekIndex - 1)]['key']); ?>#weekScheduleTab">← Prev</a>
                            <div class="calendar-title">Week <?php echo htmlspecialchars(explode(' ', $week_navigation[$selectedWeekIndex]['label'])[0]); ?></div>
                            <a class="calendar-nav-btn" href="lecturer_app.php?week=<?php echo urlencode($week_navigation[min(count($week_navigation) - 1, $selectedWeekIndex + 1)]['key']); ?>#weekScheduleTab">Next →</a>
                        </div>
                        <div style="display:flex;gap:8px;overflow-x:auto;padding-bottom:8px">
                            <?php foreach ($week_navigation as $navWeek): ?>
                                <a href="lecturer_app.php?week=<?php echo urlencode($navWeek['key']); ?>#weekScheduleTab" style="text-decoration:none;flex:0 0 auto;">
                                    <div style="padding:8px 10px;border:1px solid <?php echo $navWeek['active'] ? '#214F3B' : '#E5E8EE'; ?>;border-radius:12px;background:<?php echo $navWeek['active'] ? '#214F3B' : '#fff'; ?>;color:<?php echo $navWeek['active'] ? '#fff' : '#1A1A2E'; ?>;min-width:110px;text-align:center;box-shadow:0 4px 12px rgba(26,26,46,0.04)">
                                        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.4px;opacity:.75">ISO</div>
                                        <div style="font-size:15px;font-weight:700"><?php echo htmlspecialchars($navWeek['key']); ?></div>
                                        <div style="font-size:11px;opacity:.8;margin-top:2px"><?php echo htmlspecialchars($navWeek['label']); ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <?php
                    $days_map = [
                        1 => 'Monday',
                        2 => 'Tuesday',
                        3 => 'Wednesday',
                        4 => 'Thursday',
                        5 => 'Friday'
                    ];
                    $days_payload = [];
                    foreach ($days_map as $dayNum => $dayName) {
                        $date = date('Y-m-d', strtotime($week_start_date . ' +' . ($dayNum - 1) . ' days'));
                        $days_payload[] = [
                            'name' => $dayName,
                            'date' => $date,
                            'classes' => array_values(array_filter($week_classes, function($c) use ($date) {
                                return $c['scheduled_date'] === $date;
                            }))
                        ];
                    }
                    if (empty($week_classes)):
                    ?>
                        <div style="padding: 10px; font-size: 12px; color: #bdc3c7; background: white; border-radius: 8px; margin-top: 5px;">No classes scheduled</div>
                    <?php else: ?>
                        <div class="calendar-shell">
                            <div class="week-grid">
                                <?php foreach ($days_payload as $day): ?>
                                    <div class="day-col <?php echo $day['date'] === date('Y-m-d') ? 'active' : ''; ?>">
                                        <div class="day-head">
                                            <?php echo htmlspecialchars($day['name']); ?>
                                            <div style="font-size:10px;opacity:.8;text-transform:none;font-weight:500"><?php echo date('M j', strtotime($day['date'])); ?></div>
                                        </div>
                                        <div class="day-body">
                                            <?php if (empty($day['classes'])): ?>
                                                <div class="day-empty">No classes</div>
                                            <?php else: ?>
                                                <?php foreach ($day['classes'] as $class): ?>
                                                    <div class="day-event">
                                                        <div class="day-event-time"><?php echo date('H:i', strtotime($class['scheduled_start_time'])) . ' - ' . date('H:i', strtotime($class['scheduled_end_time'])); ?></div>
                                                        <div class="day-event-code"><?php echo htmlspecialchars($class['course_code']); ?></div>
                                                        <div class="day-event-venue"><?php echo htmlspecialchars($class['hall_name']); ?></div>
                                                        <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
                                                            <?php if ($class['scheduled_date'] === date('Y-m-d') && $class['status'] !== 'completed'): ?>
                                                                <button class="btn-small btn-edit" onclick="selectClass(<?php echo htmlspecialchars(json_encode($class)); ?>)">Attend</button>
                                                            <?php endif; ?>
                                                            <button class="btn-small btn-edit" onclick="openEditScheduleModal(<?php echo htmlspecialchars(json_encode($class)); ?>)">Edit</button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                    <?php endif; ?>
                    </div>
                    
                    <button class="btn-create-slot" onclick="openCreateScheduleModal()">+ Add New Class Slot</button>
                </div>
            </div>

            <!-- Verification / Form View -->
            <div id="checkinSection" class="checkin-form-container">
                <div class="section-title" id="formTitle">Check-In Verification</div>
                
                <!-- 3D Altitude Simulator Control for Academic Demonstration -->
                <div class="simulator-badge">
                    <strong>📐 Altitude / Height Simulation</strong>
                    <div class="slider-group">
                        <input type="range" id="altOffset" min="-12" max="12" step="3" value="0" oninput="updateAltOffsetDisplay(this.value)">
                        <span style="font-family: monospace; font-weight: bold; width: 65px; display: inline-block; text-align: right;" id="altOffsetLabel">0m (Match)</span>
                    </div>
                    <small style="font-size: 10px; color: #00838f; display: block; margin-top: 4px;">Slide to simulate being on different floor heights (+3m per floor).</small>
                </div>

                <form id="attendanceForm">
                    <input type="hidden" id="class_id" name="class_id">
                    
                    <div id="map-container"></div>

                    <div class="form-group">
                        <label>Target Lecture Venue</label>
                        <input type="text" id="hall_name" readonly>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Latitude</label>
                            <input type="text" id="latitude" name="latitude" readonly placeholder="Awaiting GPS...">
                        </div>
                        <div class="form-group">
                            <label>Longitude</label>
                            <input type="text" id="longitude" name="longitude" readonly placeholder="Awaiting GPS...">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Altitude (Meters)</label>
                            <input type="text" id="altitude" name="altitude" readonly placeholder="Awaiting GPS...">
                        </div>
                        <div class="form-group">
                            <label>GPS Accuracy (m)</label>
                            <input type="text" id="accuracy" name="accuracy" readonly placeholder="Awaiting GPS...">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Lecturer Log / Description</label>
                        <textarea id="description" name="description" placeholder="e.g. Completed lecture topics on database normalization models." required minlength="5"></textarea>
                    </div>

                    <button type="button" class="btn btn-gps" id="getLocationBtn" onclick="retrieveLocation()">
                        🛰️ Capture My Location
                    </button>

                    <button type="submit" class="btn btn-submit" id="submitBtn" disabled>
                        ✓ Submit Attendance
                    </button>

                    <button type="button" class="btn btn-cancel" onclick="closeCheckinForm()">
                        Back to Schedule
                    </button>
                </form>

                <!-- Checkin Result Alert -->
                <div id="resultBox" style="display: none;"></div>
            </div>

        </div>

        <!-- Navigation Bar Mockup -->
        <div class="app-nav">
            <div class="nav-item active" onclick="closeCheckinForm()">
                <span class="nav-icon">📅</span>
                <span>Schedule</span>
            </div>
            <div class="nav-item" onclick="alert('Live Tracking status: ACTIVE. Admin can view your real-time position on the dashboard map.')">
                <span class="nav-icon">📡</span>
                <span>Live Status</span>
            </div>
        </div>
    </div>

    <!-- Schedule Manage Modal -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="modalTitle">Add Schedule Slot</span>
                <span class="modal-close" onclick="closeScheduleModal()">&times;</span>
            </div>
            <form id="scheduleForm">
                <input type="hidden" id="sched_action" name="action" value="create">
                <input type="hidden" id="sched_class_id" name="class_id">
                
                <label for="sched_course_code">Course Code</label>
                <input type="text" id="sched_course_code" name="course_code" placeholder="e.g. CSC 401" required>

                <label for="sched_course_title">Course Title</label>
                <input type="text" id="sched_course_title" name="course_title" placeholder="e.g. Compiler Construction" required>

                <label for="sched_hall_id">Lecture Hall</label>
                <select id="sched_hall_id" name="hall_id" required>
                    <option value="" disabled selected>-- Select Hall --</option>
                    <?php foreach ($all_halls as $hall): ?>
                        <option value="<?php echo $hall['hall_id']; ?>"><?php echo htmlspecialchars($hall['hall_name'] . ' (' . $hall['hall_code'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="sched_date">Schedule Date</label>
                <input type="date" id="sched_date" name="scheduled_date" required>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label for="sched_start_time">Start Time</label>
                        <input type="time" id="sched_start_time" name="start_time" required>
                    </div>
                    <div>
                        <label for="sched_end_time">End Time</label>
                        <input type="time" id="sched_end_time" name="end_time" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-gps" style="margin-top: 15px; margin-bottom: 0;">Save Schedule</button>
            </form>
        </div>
    </div>

    <!-- Leaflet JS Map Library -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        let deferredInstallPrompt = null;

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            deferredInstallPrompt = event;
            const btn = document.getElementById('installAppBtn');
            if (btn) btn.style.display = 'inline-flex';
        });

        window.addEventListener('appinstalled', () => {
            deferredInstallPrompt = null;
            const btn = document.getElementById('installAppBtn');
            if (btn) btn.style.display = 'none';
        });

        document.getElementById('installAppBtn')?.addEventListener('click', async () => {
            if (!deferredInstallPrompt) return;
            deferredInstallPrompt.prompt();
            await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
            document.getElementById('installAppBtn').style.display = 'none';
        });

        let currentClass = null;
        let map = null;
        let userMarker = null;
        let hallCircle = null;
        let rawLatitude = null;
        let rawLongitude = null;
        let rawAltitude = 0.00;
        let rawAccuracy = 15;

        let isShiftActive = <?php echo ($today_shift && $today_shift['sign_out_time'] === null) ? 'true' : 'false'; ?>;

        // Background tracking pinger initialization
        window.addEventListener('load', () => {
            if (isShiftActive) {
                startLiveLocationPinger();
            }
        });

        function switchScheduleTab(tabName) {
            const todayTab = document.getElementById('todayScheduleTab');
            const weekTab = document.getElementById('weekScheduleTab');
            const todayBtn = document.getElementById('tabTodayBtn');
            const weekBtn = document.getElementById('tabWeekBtn');

            if (tabName === 'today') {
                todayTab.style.display = 'block';
                weekTab.style.display = 'none';
                todayBtn.className = 'btn btn-gps';
                weekBtn.className = 'btn btn-cancel';
                todayBtn.style.marginBottom = '0';
                weekBtn.style.marginTop = '0';
            } else {
                todayTab.style.display = 'none';
                weekTab.style.display = 'block';
                todayBtn.className = 'btn btn-cancel';
                weekBtn.className = 'btn btn-gps';
                todayBtn.style.marginBottom = '0';
                weekBtn.style.marginTop = '0';
            }
        }

        function syncShiftButtons() {
            const shiftInBtn = document.getElementById('shiftInBtn');
            const shiftOutBtn = document.getElementById('shiftOutBtn');
            if (!shiftInBtn || !shiftOutBtn) return;

            const now = new Date();
            const hour = now.getHours();
            const minute = now.getMinutes();
            const afterStart = hour > 8 || (hour === 8 && minute >= 0);
            const beforeEnd = hour < 16;

            if (!shiftInBtn.disabled) {
                shiftInBtn.disabled = !afterStart || !beforeEnd;
            }

            if (shiftOutBtn.disabled && <?php echo isset($today_shift) && $today_shift && $today_shift['sign_out_time'] === null ? 'true' : 'false'; ?>) {
                shiftOutBtn.disabled = false;
            }
        }

        async function handleShiftAction(action) {
            if (action === 'signin') {
                const now = new Date();
                if (now.getHours() < 8) {
                    alert("Sign-in is not active until 8:00 AM.");
                    return;
                }
            }
            
            document.getElementById('loaderOverlay').style.display = 'flex';
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(async (position) => {
                    const lat = position.coords.latitude;
                    const lon = position.coords.longitude;
                    const altOffset = parseFloat(document.getElementById('altOffset').value) || 0;
                    const alt = (position.coords.altitude !== null) ? (position.coords.altitude + altOffset) : (0.00 + altOffset);
                    
                    await submitShift(action, lat, lon, alt);
                }, async (err) => {
                    // Fallback to Caleb University coordinates for demo/testing if GPS fails
                    await submitShift(action, 6.6718, 3.4908, 0.00);
                }, { enableHighAccuracy: true, timeout: 5000 });
            } else {
                await submitShift(action, 6.6718, 3.4908, 0.00);
            }
        }

        async function submitShift(action, lat, lon, alt) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('latitude', lat);
            formData.append('longitude', lon);
            formData.append('altitude', alt);

            try {
                const response = await fetch('api_lecturer_shift.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                document.getElementById('loaderOverlay').style.display = 'none';
                
                alert(data.message);
                if (data.status === 'SUCCESS') {
                    window.location.reload();
                }
            } catch (err) {
                document.getElementById('loaderOverlay').style.display = 'none';
                alert('Request failed: ' + err.message);
            }
        }

        function updateAltOffsetDisplay(val) {
            const label = document.getElementById('altOffsetLabel');
            let text = val + 'm';
            if (val > 0) text = '+' + text;
            if (val == 0) text += ' (Match)';
            label.textContent = text;
            
            // Recalculate and display simulated altitude if location is already captured
            if (rawLatitude !== null) {
                const simulatedAlt = rawAltitude + parseFloat(val);
                document.getElementById('altitude').value = simulatedAlt.toFixed(2);
            }
        }

        // Periodically ping current location to admin dashboard in background
        function startLiveLocationPinger() {
            if (!isShiftActive) return;
            
            if (navigator.geolocation) {
                // Ping every 30 seconds
                setInterval(() => {
                    // Restrict pinger to school hours: 8 AM to 4 PM
                    const now = new Date();
                    const hours = now.getHours();
                    if (hours < 8 || hours >= 16) {
                        console.log("Pinger is inactive outside school working hours (8 AM - 4 PM)");
                        return;
                    }

                    navigator.geolocation.getCurrentPosition((position) => {
                        const lat = position.coords.latitude;
                        const lon = position.coords.longitude;
                        // Add altitude if supported
                        const altOffset = parseFloat(document.getElementById('altOffset').value) || 0;
                        const alt = (position.coords.altitude !== null) ? (position.coords.altitude + altOffset) : (0.00 + altOffset);
                        
                        sendBackgroundLocationPing(lat, lon, alt);
                    }, (err) => {
                        // Fallback pinger if GPS blocked (mock tracking using base values)
                        if (currentClass) {
                            const offset = parseFloat(document.getElementById('altOffset').value) || 0;
                            const mockLat = parseFloat(currentClass.latitude) + (Math.random() - 0.5) * 0.0001;
                            const mockLon = parseFloat(currentClass.longitude) + (Math.random() - 0.5) * 0.0001;
                            const mockAlt = parseFloat(currentClass.altitude_meters) + offset;
                            sendBackgroundLocationPing(mockLat, mockLon, mockAlt);
                        }
                    }, { enableHighAccuracy: true, timeout: 3000 });
                }, 30000);
            }
        }

        async function sendBackgroundLocationPing(lat, lon, alt) {
            const formData = new FormData();
            formData.append('latitude', lat);
            formData.append('longitude', lon);
            formData.append('altitude', alt);
            
            try {
                const response = await fetch('api_ping_location.php', { method: 'POST', body: formData });
                const data = await response.json();
                console.log("Background location ping sent:", {lat, lon, alt});
                // If the response indicates shift has been auto-signed out, reload page
                // (e.g. if the backend auto signs them out because they exited campus)
                if (data.status === 'SUCCESS' && data.message === 'Location logged') {
                    // We can check if status needs reload
                }
            } catch(e) {
                console.warn("Location ping failed:", e);
            }
        }

        // Initialize Map
        function initMap(lat, lon, tolerance) {
            if (map) {
                map.remove();
            }
            map = L.map('map-container').setView([lat, lon], 17);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            // Draw Geofence circle
            hallCircle = L.circle([lat, lon], {
                color: '#3a7bd5',
                fillColor: '#3a7bd5',
                fillOpacity: 0.15,
                radius: tolerance
            }).addTo(map);
        }

        // Action when a scheduled class is clicked
        function selectClass(classObj) {
            if (classObj.status === 'completed') {
                alert('This class attendance has already been verified!');
                return;
            }
            currentClass = classObj;
            
            document.getElementById('scheduleSection').style.display = 'none';
            document.getElementById('checkinSection').style.display = 'block';
            document.getElementById('formTitle').textContent = `Check-In: ${classObj.course_code}`;
            document.getElementById('class_id').value = classObj.class_id;
            document.getElementById('hall_name').value = classObj.hall_name;
            
            // Clear inputs
            document.getElementById('latitude').value = '';
            document.getElementById('longitude').value = '';
            document.getElementById('altitude').value = '';
            document.getElementById('accuracy').value = '';
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('resultBox').style.display = 'none';
            
            rawLatitude = null;

            // Wait until panel is visible before building leaflet map
            setTimeout(() => {
                initMap(parseFloat(classObj.latitude), parseFloat(classObj.longitude), parseInt(classObj.tolerance_radius_meters));
            }, 100);
        }

        function closeCheckinForm() {
            document.getElementById('checkinSection').style.display = 'none';
            document.getElementById('scheduleSection').style.display = 'block';
            currentClass = null;
        }

        // Get Location: HTML5 Geolocation with mock fallback
        function retrieveLocation() {
            if (!currentClass) return;

            const btn = document.getElementById('getLocationBtn');
            btn.disabled = true;
            btn.textContent = 'Acquiring GPS Signal...';

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        rawLatitude = position.coords.latitude;
                        rawLongitude = position.coords.longitude;
                        rawAccuracy = Math.round(position.coords.accuracy);
                        
                        // Grab actual altitude or fallback to target classroom base altitude
                        rawAltitude = (position.coords.altitude !== null) ? position.coords.altitude : parseFloat(currentClass.altitude_meters);
                        
                        updateLocationFormFields(rawLatitude, rawLongitude, rawAltitude, rawAccuracy, false);
                    },
                    function(err) {
                        console.warn('Real GPS failed, using coordinate simulator');
                        simulateCampusGPS();
                    },
                    { enableHighAccuracy: true, timeout: 5000 }
                );
            } else {
                simulateCampusGPS();
            }
        }

        // Simulate location values centered on target lecture hall
        function simulateCampusGPS() {
            const baseLat = parseFloat(currentClass.latitude);
            const baseLon = parseFloat(currentClass.longitude);
            const baseAlt = parseFloat(currentClass.altitude_meters);
            
            // Simulate random variance to demo PASS / FAIL geofence
            const inside = Math.random() > 0.3;
            let varianceLat, varianceLon;
            
            if (inside) {
                varianceLat = (Math.random() - 0.5) * 0.0001;
                varianceLon = (Math.random() - 0.5) * 0.0001;
            } else {
                varianceLat = (Math.random() - 0.5) * 0.002;
                varianceLon = (Math.random() - 0.5) * 0.002;
            }

            rawLatitude = baseLat + varianceLat;
            rawLongitude = baseLon + varianceLon;
            rawAltitude = baseAlt;
            rawAccuracy = Math.round(5 + Math.random() * 10);

            updateLocationFormFields(rawLatitude, rawLongitude, rawAltitude, rawAccuracy, true);
        }

        function updateLocationFormFields(lat, lon, alt, accuracy, isSimulated) {
            // Apply simulation height offset slider to altitude
            const offset = parseFloat(document.getElementById('altOffset').value) || 0;
            const finalAlt = alt + offset;

            document.getElementById('latitude').value = lat.toFixed(7);
            document.getElementById('longitude').value = lon.toFixed(7);
            document.getElementById('altitude').value = finalAlt.toFixed(2);
            document.getElementById('accuracy').value = accuracy;

            const btn = document.getElementById('getLocationBtn');
            btn.disabled = false;
            btn.textContent = isSimulated ? '🛰️ Location Obtained (Simulated)' : '🛰️ Location Obtained (Real GPS)';
            
            document.getElementById('submitBtn').disabled = false;

            // Plot user on map
            if (userMarker) {
                userMarker.removeFrom(map);
            }
            
            userMarker = L.marker([lat, lon]).addTo(map)
                .bindPopup("Your Location Pinpoint").openPopup();
                
            const bounds = L.latLngBounds([
                [lat, lon],
                [parseFloat(currentClass.latitude), parseFloat(currentClass.longitude)]
            ]);
            map.fitBounds(bounds.pad(0.2));
        }

        // AJAX Form submit to api_submit_attendance.php
        document.getElementById('attendanceForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            document.getElementById('loaderOverlay').style.display = 'flex';
            const formData = new FormData(this);

            try {
                const response = await fetch('api_submit_attendance.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                document.getElementById('loaderOverlay').style.display = 'none';

                const rBox = document.getElementById('resultBox');
                rBox.style.display = 'block';
                
                if (data.status === 'SUCCESS') {
                    const verify = data.verification;
                    let resultClass = 'error';
                    let titleIcon = '❌';

                    if (verify.status === 'VERIFIED') {
                        resultClass = 'success';
                        titleIcon = '✓';
                        document.getElementById('submitBtn').disabled = true;
                    } else if (verify.status === 'PENDING') {
                        resultClass = 'warning';
                        titleIcon = '🚨';
                    }

                    rBox.className = `result-box ${resultClass}`;
                    rBox.innerHTML = `
                        <strong>${titleIcon} Status: ${verify.status}</strong><br>
                        • Geofence check: ${verify.spatial.passed ? '✓ PASS' : '✗ FAIL'}<br>
                        <small style="color: #666; margin-left: 10px; display: inline-block;">${verify.spatial.message}</small><br>
                        • Altitude / Height check: ${verify.altitude.passed ? '✓ PASS' : '✗ FAIL'}<br>
                        <small style="color: #666; margin-left: 10px; display: inline-block;">${verify.altitude.message}</small><br>
                        • Time Slot check: ${verify.temporal.passed ? '✓ PASS' : '✗ FAIL'}<br>
                        <small style="color: #666; margin-left: 10px; display: inline-block;">${verify.temporal.message}</small><br>
                        ${verify.anomaly.detected ? `• Anomaly: Yes (Blocked)<br><small style="color: red; margin-left: 10px;">${verify.anomaly.reason}</small><br>` : ''}
                        <hr style="margin: 8px 0; border: none; border-top: 1px solid rgba(0,0,0,0.1);">
                        <small>Transaction ID: #${data.submission_id}<br>Timestamp: ${data.timestamp}</small>
                    `;

                    // Reload page after a delay if verified successfully to update schedule list
                    if (verify.status === 'VERIFIED') {
                        setTimeout(() => {
                            window.location.reload();
                        }, 4000);
                    }

                } else {
                    rBox.className = 'result-box error';
                    rBox.innerHTML = `<strong>Error Logging Attendance</strong><br>${data.message}`;
                }
            } catch (err) {
                document.getElementById('loaderOverlay').style.display = 'none';
                alert('Network request failed: ' + err.message);
            }
        });
        // Schedule modal management functions
        function openCreateScheduleModal() {
            document.getElementById('modalTitle').textContent = "Add Schedule Slot";
            document.getElementById('sched_action').value = "create";
            document.getElementById('sched_class_id').value = "";
            document.getElementById('scheduleForm').reset();
            document.getElementById('scheduleModal').style.display = 'flex';
        }

        function openEditScheduleModal(classObj) {
            document.getElementById('modalTitle').textContent = "Edit Schedule Slot";
            document.getElementById('sched_action').value = "edit";
            document.getElementById('sched_class_id').value = classObj.class_id;
            
            document.getElementById('sched_course_code').value = classObj.course_code;
            document.getElementById('sched_course_title').value = classObj.course_title;
            document.getElementById('sched_hall_id').value = classObj.hall_id;
            document.getElementById('sched_date').value = classObj.scheduled_date;
            
            // Format start and end time (from H:i:s to H:i)
            document.getElementById('sched_start_time').value = classObj.scheduled_start_time.substring(0, 5);
            document.getElementById('sched_end_time').value = classObj.scheduled_end_time.substring(0, 5);
            
            document.getElementById('scheduleModal').style.display = 'flex';
        }

        function closeScheduleModal() {
            document.getElementById('scheduleModal').style.display = 'none';
        }

        async function deleteScheduleSlot(classId) {
            if (!confirm("Are you sure you want to delete this schedule slot?")) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('class_id', classId);

            try {
                const response = await fetch('api_manage_schedule.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                alert(data.message);
                if (data.status === 'SUCCESS') {
                    window.location.reload();
                }
            } catch (err) {
                alert('Request failed: ' + err.message);
            }
        }

        // Form submit handler for schedule
        document.getElementById('scheduleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            try {
                const response = await fetch('api_manage_schedule.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                alert(data.message);
                if (data.status === 'SUCCESS') {
                    window.location.reload();
                }
            } catch (err) {
                alert('Request failed: ' + err.message);
            }
        });

        syncShiftButtons();
        setInterval(syncShiftButtons, 30000);
    </script>
</body>
</html>
