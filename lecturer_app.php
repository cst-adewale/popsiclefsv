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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Attendance App — Lecturer Portal</title>

    <!-- ======= PWA MANIFEST ======= -->
    <link rel="manifest" href="/muyiwa/manifest.json">

    <!-- ======= PWA THEME ======= -->
    <meta name="theme-color" content="#3a7bd5">
    <meta name="mobile-web-app-capable" content="yes">

    <!-- ======= iOS / APPLE PWA SUPPORT ======= -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="AttendanceApp">
    <link rel="apple-touch-icon" href="/muyiwa/icons/icon-512.png">
    <!-- iOS Splash Screens (optional but recommended for best experience) -->
    <link rel="apple-touch-startup-image" href="/muyiwa/icons/icon-512.png">

    <!-- ======= STANDARD FAVICON ======= -->
    <link rel="icon" type="image/png" sizes="192x192" href="/muyiwa/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/muyiwa/icons/icon-512.png">

    <!-- ======= SEO / DESCRIPTION ======= -->
    <meta name="description" content="GPS-verified lecturer check-in portal with 3D floor-level geofencing.">

    <!-- Leaflet CSS for Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

    <!-- ======= SERVICE WORKER REGISTRATION ======= -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/muyiwa/sw.js')
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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #f5f6fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: #2f3640;
        }

        /* Mobile Container Frame to simulate a phone */
        .mobile-frame {
            background-color: #ffffff;
            width: 100%;
            max-width: 450px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        @media (min-width: 450px) {
            .mobile-frame {
                height: 870px;
                border-radius: 30px;
                border: 8px solid #2f3640;
            }
        }

        /* App Header */
        .app-header {
            background: linear-gradient(135deg, #3a7bd5 0%, #00d2ff 100%);
            padding: 20px 15px;
            color: white;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
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

        /* Content Area */
        .app-content {
            flex-grow: 1;
            overflow-y: auto;
            padding: 15px;
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
    <div class="mobile-frame">
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
                <a href="logout.php" class="logout-btn">Log Out</a>
            </div>
            <div style="font-size: 12px; opacity: 0.9; display: flex; align-items: center; gap: 5px;">
                <span>📅 Today:</span> <strong><?php echo date('F d, Y'); ?></strong>
            </div>
        </div>

        <!-- Main Scrollable Content -->
        <div class="app-content" id="appContent">
            
            <!-- Dynamic Classes View -->
            <div id="scheduleSection">
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

    <!-- Leaflet JS Map Library -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        let currentClass = null;
        let map = null;
        let userMarker = null;
        let hallCircle = null;
        let rawLatitude = null;
        let rawLongitude = null;
        let rawAltitude = 0.00;
        let rawAccuracy = 15;

        // Background tracking pinger initialization
        window.addEventListener('load', () => {
            startLiveLocationPinger();
        });

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
            if (navigator.geolocation) {
                // Ping every 30 seconds
                setInterval(() => {
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
                await fetch('api_ping_location.php', { method: 'POST', body: formData });
                console.log("Background location ping sent:", {lat, lon, alt});
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
    </script>
</body>
</html>
