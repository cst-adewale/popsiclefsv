<?php
/**
 * lecturer_app.php  —  Popsicle FSV  |  Redesigned Lecturer App
 *
 * CHANGES FROM ORIGINAL:
 *  1. White / off-white minimalist UI  (matches admin redesign)
 *  2. Real calendar grid  —  week numbers (W1, W2 …) left column,
 *     clicking a week number OR the whole row highlights it and plots
 *     that week's timetable below; prev / next month arrows around grid
 *  3. Re-sign-in allowed after auto sign-out as long as time < 16:00
 *  4. Session kept alive on refresh  (no logout-on-refresh)
 *  5. "Install App" button appears automatically when browser supports PWA install
 *  6. Streamline-style flat SVG icons throughout
 */
require 'config.php';
session_start();

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header('Location: login.php');
    exit;
}

$lecturer_id = $_SESSION['user_id'];
$full_name   = $_SESSION['full_name'];

// ── Today's classes ───────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT sc.class_id, sc.course_code, sc.course_title,
           sc.scheduled_start_time, sc.scheduled_end_time,
           lh.hall_name, lh.latitude, lh.longitude,
           lh.altitude_meters, lh.tolerance_radius_meters, sc.status
    FROM scheduled_classes sc
    JOIN lecture_halls lh ON sc.hall_id = lh.hall_id
    WHERE sc.lecturer_id = ? AND sc.scheduled_date = CURRENT_DATE
    ORDER BY sc.scheduled_start_time ASC
");
$stmt->execute([$lecturer_id]);
$scheduled_classes = $stmt->fetchAll();

// ── Today's shift  ────────────────────────────────────────────────────────────
$stmt_shift = $conn->prepare("
    SELECT sign_in_time, sign_out_time, sign_out_method
    FROM lecturer_shifts
    WHERE lecturer_id = ? AND work_date = CURRENT_DATE
");
$stmt_shift->execute([$lecturer_id]);
$today_shift = $stmt_shift->fetch();

// ── All halls (for schedule modal) ───────────────────────────────────────────
$all_halls = $conn->query(
    "SELECT hall_id, hall_name, hall_code FROM lecture_halls ORDER BY hall_name ASC"
)->fetchAll();

// ── All classes for JS calendar ───────────────────────────────────────────────
try {
    $stmt_all = $conn->prepare("
        SELECT sc.class_id, sc.course_code, sc.course_title,
               sc.scheduled_start_time, sc.scheduled_end_time,
               sc.scheduled_date, lh.hall_name, sc.status
        FROM scheduled_classes sc
        JOIN lecture_halls lh ON sc.hall_id = lh.hall_id
        WHERE sc.lecturer_id = ?
        ORDER BY sc.scheduled_date ASC, sc.scheduled_start_time ASC
    ");
    $stmt_all->execute([$lecturer_id]);
    $all_classes_js = $stmt_all->fetchAll();
} catch (Exception $e) {
    $all_classes_js = [];
}

// ── Sign-in / sign-out eligibility ───────────────────────────────────────────
// Lecturer can sign in if:  no active shift OR shift was signed-out  AND  current hour < 16
$now_hour    = (int)date('H');
$can_signin  = ($now_hour < 16) && (!$today_shift || $today_shift['sign_out_time'] !== null);
$can_signout = $today_shift && $today_shift['sign_out_time'] === null;
$is_shift_active = $today_shift && $today_shift['sign_out_time'] === null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Popsicle FSV — Lecturer</title>
<!-- PWA -->
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#8B5CF6">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="FSV">
<link rel="apple-touch-icon" href="/icons/icon-512.png">
<link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(r  => console.log('[PWA] SW:', r.scope))
            .catch(er => console.warn('[PWA] SW failed:', er));
    });
}
</script>
<link rel="stylesheet" href="css/lecturer_app.css">
</head>
<body class="light">
<div class="frame">

  <!-- Loader -->
  <div class="loader" id="loader">
    <div class="spinner"></div>
    <p>Verifying 3D geofence…</p>
    <small>Checking coordinates &amp; altitude</small>
  </div>

  <!-- ── Header ─────────────────────────────────────────── -->
  <div class="app-header">
    <div class="hdr-row">
      <div class="logo-row">
        <div class="logo-dot">
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
        </div>
        <span class="app-name">Popsicle FSV</span>
      </div>
      <div class="hdr-actions">
        <!-- Appears automatically when browser fires beforeinstallprompt -->
        <button class="install-btn" id="installBtn" onclick="triggerInstall()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Install App
        </button>
        <a href="logout.php" class="logout-link">Sign out</a>
      </div>
    </div>
    <div class="welcome-lbl">Welcome back,</div>
    <div class="welcome-name"><?php echo htmlspecialchars($full_name); ?></div>
    <div class="date-chip">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <?php echo date('l, F j, Y'); ?>
    </div>
  </div>

  <!-- ── Body ───────────────────────────────────────────── -->
  <div class="app-body">

    <!-- ── Shift card ──────────────────────────────────── -->
    <div class="shift-card">
      <div class="shift-top">
        <div class="shift-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Daily shift
        </div>
        <?php if (!$today_shift): ?>
          <span class="status-pill pill-none">Not signed in</span>
        <?php elseif ($today_shift['sign_out_time'] === null): ?>
          <span class="status-pill pill-active"><span class="dot-live"></span>Active</span>
        <?php else: ?>
          <span class="status-pill pill-out">Signed out <?php echo date('H:i', strtotime($today_shift['sign_out_time'])); ?></span>
        <?php endif; ?>
      </div>

      <div class="shift-info">
        <?php if (!$today_shift): ?>
          Sign in starts at 08:00. You can sign in any time before 16:00.
        <?php elseif ($today_shift['sign_out_time'] === null): ?>
          Signed in at <strong><?php echo date('H:i', strtotime($today_shift['sign_in_time'])); ?></strong>.
          Live tracking active. Leaving campus geofence auto-signs you out.
        <?php else: ?>
          Signed in <strong><?php echo date('H:i', strtotime($today_shift['sign_in_time'])); ?></strong>
          &rarr; Signed out <strong><?php echo date('H:i', strtotime($today_shift['sign_out_time'])); ?></strong>
          (<?php echo $today_shift['sign_out_method'] === 'auto_geofence' ? 'Auto — left campus' : 'Manual'; ?>).
          <?php if ($can_signin): ?>
          <div class="re-signin-note">You can sign back in — shift window is open until 16:00.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="shift-btns">
        <button class="btn-shift btn-in" id="shiftInBtn"
          onclick="handleShift('signin')"
          <?php echo $can_signin ? '' : 'disabled'; ?>>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Sign In
        </button>
        <button class="btn-shift btn-out" id="shiftOutBtn"
          onclick="handleShift('signout')"
          <?php echo $can_signout ? '' : 'disabled'; ?>>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign Out
        </button>
      </div>
    </div>

    <!-- ── Tabs ────────────────────────────────────────── -->
    <div class="tab-row" id="tabRowContainer">
      <button class="tab-btn active" id="tabToday" onclick="switchTab('today')">Today's Classes</button>
      <button class="tab-btn"        id="tabWeek"  onclick="switchTab('week')">Weekly Timetable</button>
    </div>

    <!-- ════════════════════════════════════════════════ -->
    <!--  PANEL A — TODAY                                -->
    <!-- ════════════════════════════════════════════════ -->
    <div id="panelToday">

      <div id="classesList">
        <div class="sec-lbl">Scheduled for today</div>
        <?php if (count($scheduled_classes) === 0): ?>
          <div class="empty-day">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <p>No classes scheduled for today</p>
          </div>
        <?php else: ?>
          <?php foreach ($scheduled_classes as $cls): ?>
          <div class="class-card <?php echo $cls['status'] === 'completed' ? 'completed' : ''; ?>"
               onclick="selectClass(<?php echo htmlspecialchars(json_encode($cls)); ?>)">
            <div class="cc-top">
              <span class="cc-code"><?php echo htmlspecialchars($cls['course_code']); ?></span>
              <span class="badge <?php echo $cls['status'] === 'completed' ? 'b-done' : 'b-sched'; ?>">
                <?php echo ucfirst($cls['status']); ?>
              </span>
            </div>
            <div class="cc-title"><?php echo htmlspecialchars($cls['course_title']); ?></div>
            <div class="cc-meta">
              <span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?php echo date('H:i', strtotime($cls['scheduled_start_time'])) . '–' . date('H:i', strtotime($cls['scheduled_end_time'])); ?>
              </span>
              <span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <?php echo htmlspecialchars($cls['hall_name']); ?>
              </span>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Check-in form  -->
      <div class="ci-wrap" id="ciWrap">
        <div class="ci-card">
          <div class="ci-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Check-in — <span id="ciCode"></span>
          </div>

          <!-- Altitude simulator -->
          <div class="alt-sim">
            <div class="alt-sim-lbl">Floor / altitude simulator</div>
            <input type="range" id="altOffset" min="-12" max="12" step="3" value="0" oninput="updateAltLbl(this.value)">
            <div class="alt-sim-val" id="altLbl">0 m — Ground floor (match)</div>
          </div>

          <form id="attendanceForm">
            <input type="hidden" id="class_id" name="class_id">
            <div id="map-container"></div>

            <div class="field">
              <label>Target venue</label>
              <input type="text" id="hall_name" readonly>
            </div>
            <div class="two-col">
              <div class="field"><label>Latitude</label><input type="text" id="latitude"  name="latitude"  readonly placeholder="Awaiting GPS…"></div>
              <div class="field"><label>Longitude</label><input type="text" id="longitude" name="longitude" readonly placeholder="Awaiting GPS…"></div>
            </div>
            <div class="two-col">
              <div class="field"><label>Altitude (m)</label><input type="text" id="altitude" name="altitude" readonly placeholder="Awaiting GPS…"></div>
              <div class="field"><label>GPS accuracy (m)</label><input type="text" id="accuracy" name="accuracy" readonly placeholder="Awaiting GPS…"></div>
            </div>
            <div class="field">
              <label>Lecture notes</label>
              <textarea id="desc" name="description" placeholder="Describe today's lecture topic…" required minlength="5"></textarea>
            </div>

            <button type="button" class="btn-gps" id="gpsBtn" onclick="captureLocation()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="4 2"/></svg>
              Capture my location
            </button>
            <button type="submit" class="btn-sub" id="submitBtn" disabled>Submit attendance</button>
            <button type="button" class="btn-back" onclick="closeCheckin()">&#8592; Back to schedule</button>
          </form>

          <div id="resultBox" class="result-box"></div>
        </div>
      </div>

    </div><!-- /panelToday -->

    <!-- ════════════════════════════════════════════════ -->
    <!--  PANEL B — WEEKLY TIMETABLE + CALENDAR         -->
    <!-- ════════════════════════════════════════════════ -->
    <div id="panelWeek" class="d-none">

      <!-- Calendar grid -->
      <div class="cal-wrap">
        <div class="cal-hdr">
          <button class="cal-nav" onclick="calNav(-1)" title="Previous month">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          </button>
          <div class="cal-month-lbl" id="calMonthLbl"></div>
          <button class="cal-nav" onclick="calNav(1)" title="Next month">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
        </div>
        <div class="cal-body" id="calBody"></div>
      </div>

      <!-- Timetable for selected week -->
      <div class="wt-wrap">
        <div class="wt-title" id="wtTitle">Select a week to view schedule</div>
        <div id="wtBody"><div class="wt-empty">Click any week row or W-number in the calendar above.</div></div>
      </div>

      <button class="add-slot-btn" onclick="openModal()">
        + Add / manage schedule slots
      </button>
    </div><!-- /panelWeek -->

    <!-- ════════════════════════════════════════════════ -->
    <!--  PANEL C — LIVE STATUS & 2D SVG CAMPUS MAP      -->
    <!-- ════════════════════════════════════════════════ -->
    <div id="panelLive" class="d-none">
      
      <!-- Tracking flat banner -->
      <div class="tracking-status-banner <?php echo $is_shift_active ? 'tracking-active' : 'tracking-inactive'; ?>" id="liveTrackingStatus">
        <div class="banner-dot"></div>
        <div class="banner-text">
          <strong id="liveStatusText"><?php echo $is_shift_active ? 'Live Tracking Active' : 'Tracking Inactive'; ?></strong>
          <span id="liveStatusSub"><?php echo $is_shift_active ? 'Admin is viewing your real-time campus coordinate updates.' : 'Sign in to start live location updates.'; ?></span>
        </div>
      </div>

      <!-- Leaflet Map Card -->
      <div class="svg-map-card">
        <div class="map-title">
          Caleb University Campus — Live View
          <span id="liveMapHint" style="font-size:10px;font-weight:500;color:var(--text-dim);margin-left:8px;">Locating you…</span>
        </div>
        <div id="live-map" style="width:100%;height:320px;border-radius:12px;border:1px solid var(--border);overflow:hidden;"></div>
      </div>

    </div><!-- /panelLive -->

  </div><!-- /app-body -->

  <!-- ── Bottom nav ──────────────────────────────────────── -->
  <div class="bottom-nav">
    <button class="nav-btn active" id="navSchedule" onclick="switchMainTab('schedule')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Schedule
    </button>
    <button class="nav-btn" id="navLive" onclick="switchMainTab('live')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="4 2"/></svg>
      Live Status
    </button>
  </div>

</div><!-- /frame -->

<!-- ── Add-slot modal ──────────────────────────────────── -->
<div class="modal-bg" id="schedModal">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3 id="modalTitle">Add schedule slot</h3>
      <button class="modal-close" onclick="closeModal()">&#215;</button>
    </div>
    <form id="scheduleForm">
      <input type="hidden" id="sched_action"   name="action"   value="create">
      <input type="hidden" id="sched_class_id" name="class_id">
      <div class="m-field"><label>Course code</label><input type="text" id="sched_code"  name="course_code"  placeholder="e.g. CSC 401" required></div>
      <div class="m-field"><label>Course title</label><input type="text" id="sched_title" name="course_title" placeholder="e.g. Compiler Construction" required></div>
      <div class="m-field">
        <label>Lecture hall</label>
        <select id="sched_hall" name="hall_id" required>
          <option value="" disabled selected>Select hall</option>
          <?php foreach ($all_halls as $h): ?>
          <option value="<?php echo $h['hall_id']; ?>">
            <?php echo htmlspecialchars($h['hall_name'] . ' (' . $h['hall_code'] . ')'); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="m-field"><label>Date</label><input type="date" id="sched_date" name="scheduled_date" required></div>
      <div class="m-two">
        <div class="m-field"><label>Start</label><input type="time" id="sched_start" name="start_time" required></div>
        <div class="m-field"><label>End</label><input type="time" id="sched_end"   name="end_time"   required></div>
      </div>
      <button type="submit" class="btn-modal-save">Save slot</button>
    </form>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
/* ─── All classes passed from PHP ─────────────────────── */
const ALL_CLASSES = <?php echo json_encode($all_classes_js, JSON_UNESCAPED_UNICODE); ?>;
const CLASS_DATES = new Set(ALL_CLASSES.map(c => c.scheduled_date));

/* ─── State ───────────────────────────────────────────── */
let mapInst = null, userMarker = null;
let rawLat = null, rawLon = null, rawAlt = 0, rawAcc = 15;
let isShiftActive = <?php echo $is_shift_active ? 'true' : 'false'; ?>;
let calYear  = <?php echo (int)date('Y'); ?>;
let calMonth = <?php echo (int)date('n') - 1; ?>; /* 0-based */
let selWn = null, selWy = null;
let calDone = false;

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAYS   = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

/* ─── PWA Install ─────────────────────────────────────── */
let deferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredPrompt = e;
    document.getElementById('installBtn').classList.add('show');
});
function triggerInstall() {
    if (!deferredPrompt) {
        alert('To install this app:\n• Android/Chrome: tap ⋮ menu → "Add to Home screen"\n• iOS/Safari: tap Share → "Add to Home Screen"');
        return;
    }
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(() => {
        deferredPrompt = null;
        document.getElementById('installBtn').classList.remove('show');
    });
}
window.addEventListener('appinstalled', () => {
    document.getElementById('installBtn').classList.remove('show');
});

/* ─── Live Leaflet Map ────────────────────────────────── */
let liveMap = null, liveMarker = null;

function initLiveMap() {
    if (liveMap) return; // already initialised
    liveMap = L.map('live-map').setView([6.6718, 3.4908], 17);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(liveMap);

    // Draw the 800 m campus geofence boundary ring
    L.circle([6.6718, 3.4908], {
        radius: 800,
        color: '#8B5CF6',
        weight: 2,
        fillOpacity: 0.05
    }).addTo(liveMap);
}

function updateLiveMap(lat, lon) {
    if (!liveMap) return;
    if (liveMarker) {
        liveMarker.setLatLng([lat, lon]);
    } else {
        liveMarker = L.circleMarker([lat, lon], {
            radius: 10,
            color: '#000000',
            fillColor: '#65FE08',
            fillOpacity: 1,
            weight: 2
        }).addTo(liveMap).bindPopup('Your position');
    }
    liveMap.setView([lat, lon], 17);
    const hint = document.getElementById('liveMapHint');
    if (hint) hint.textContent = `${lat.toFixed(5)}, ${lon.toFixed(5)}`;
}

/* ─── Tab switching ───────────────────────────────────── */
function switchMainTab(t) {
    if (t === 'schedule') {
        const tr = document.getElementById('tabRowContainer');
        if (tr) tr.style.display = 'flex';
        document.getElementById('panelLive').classList.add('d-none');
        const isTodayActive = document.getElementById('tabToday').classList.contains('active');
        switchTab(isTodayActive ? 'today' : 'week');
        setNav('navSchedule');
    } else if (t === 'live') {
        const tr = document.getElementById('tabRowContainer');
        if (tr) tr.style.display = 'none';
        document.getElementById('panelToday').style.display = 'none';
        document.getElementById('panelWeek').style.display = 'none';
        document.getElementById('panelLive').classList.remove('d-none');
        setNav('navLive');

        // Init map (first visit only), then grab current position
        setTimeout(() => {
            initLiveMap();
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(pos => {
                    updateLiveMap(pos.coords.latitude, pos.coords.longitude);
                }, () => {
                    // GPS denied — just show campus centre
                    if (liveMap) {
                        const hint = document.getElementById('liveMapHint');
                        if (hint) hint.textContent = 'GPS unavailable';
                    }
                }, {enableHighAccuracy: true, timeout: 6000});
            }
            // Force Leaflet to recalculate size after panel is visible
            liveMap.invalidateSize();
        }, 80);
    }
}

function switchTab(t) {
    document.getElementById('panelToday').style.display = t === 'today' ? 'block' : 'none';
    document.getElementById('panelWeek').style.display  = t === 'week'  ? 'block' : 'none';
    document.getElementById('tabToday').classList.toggle('active', t === 'today');
    document.getElementById('tabWeek').classList.toggle('active', t === 'week');
    if (t === 'week' && !calDone) { calDone = true; initCal(); }
}
function setNav(id) {
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

/* ─── Shift actions ───────────────────────────────────── */
async function handleShift(action) {
    if (action === 'signin' && new Date().getHours() >= 16) {
        alert('Sign-in is only available before 16:00.'); return;
    }
    document.getElementById('loader').style.display = 'flex';
    const doSubmit = async (lat, lon, alt) => {
        const fd = new FormData();
        fd.append('action', action); fd.append('latitude', lat);
        fd.append('longitude', lon); fd.append('altitude', alt);
        try {
            const d = await (await fetch('api_lecturer_shift.php', {method:'POST',body:fd})).json();
            document.getElementById('loader').style.display = 'none';
            if (d.status === 'SUCCESS' || d.status === 'ALREADY_SIGNED_IN') location.reload();
            else alert(d.message || 'Shift action failed.');
        } catch(e) {
            document.getElementById('loader').style.display = 'none';
            alert('Network error. Please try again.');
        }
    };
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => {
                const off = parseFloat(document.getElementById('altOffset')?.value || 0);
                const alt = pos.coords.altitude !== null ? pos.coords.altitude + off : off;
                doSubmit(pos.coords.latitude, pos.coords.longitude, alt);
            },
            () => doSubmit(6.6718, 3.4908, 0.00),
            {enableHighAccuracy:true, timeout:6000}
        );
    } else doSubmit(6.6718, 3.4908, 0.00);
}

/* ─── Class selection ─────────────────────────────────── */
function selectClass(cls) {
    document.getElementById('class_id').value = cls.class_id;
    document.getElementById('hall_name').value = cls.hall_name;
    ['latitude','longitude','altitude','accuracy'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('ciCode').textContent = cls.course_code;
    document.getElementById('resultBox').className = 'result-box';
    document.getElementById('submitBtn').disabled = true;
    rawLat = null;

    document.getElementById('classesList').style.display = 'none';
    document.getElementById('ciWrap').classList.add('show');

    setTimeout(() => {
        if (mapInst) { mapInst.remove(); mapInst = null; }
        mapInst = L.map('map-container').setView([cls.latitude, cls.longitude], 17);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OSM'}).addTo(mapInst);
        L.circle([cls.latitude, cls.longitude],
            {radius:cls.tolerance_radius_meters, color:'#8B5CF6', fillOpacity:.1, weight:2}).addTo(mapInst);
        L.marker([cls.latitude, cls.longitude]).addTo(mapInst).bindPopup(cls.hall_name).openPopup();
    }, 80);
}
function closeCheckin() {
    document.getElementById('ciWrap').classList.remove('show');
    document.getElementById('classesList').style.display = 'block';
    if (mapInst) { mapInst.remove(); mapInst = null; }
}

/* ─── GPS capture ─────────────────────────────────────── */
function captureLocation() {
    if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
    const btn = document.getElementById('gpsBtn');
    btn.style.background = '#52A878';
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="4 2"/></svg> Locating\u2026';

    navigator.geolocation.getCurrentPosition(pos => {
        const off = parseFloat(document.getElementById('altOffset').value) || 0;
        rawLat = pos.coords.latitude;
        rawLon = pos.coords.longitude;
        rawAlt = (pos.coords.altitude !== null) ? (pos.coords.altitude + off) : off;
        rawAcc = pos.coords.accuracy || 15;

        document.getElementById('latitude').value  = rawLat.toFixed(7);
        document.getElementById('longitude').value = rawLon.toFixed(7);
        document.getElementById('altitude').value  = rawAlt.toFixed(2);
        document.getElementById('accuracy').value  = rawAcc.toFixed(1);

        if (userMarker) mapInst.removeLayer(userMarker);
        userMarker = L.marker([rawLat, rawLon]).addTo(mapInst);
        mapInst.setView([rawLat, rawLon], 17);

        btn.style.background = '#8B5CF6';
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Location captured';
        document.getElementById('submitBtn').disabled = false;
    }, err => {
        btn.style.background = '#8B5CF6';
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="4 2"/></svg> Retry location';
        alert('GPS error: ' + err.message);
    }, {enableHighAccuracy:true, timeout:10000});
}
function updateAltLbl(v) {
    const n = parseFloat(v);
    document.getElementById('altLbl').textContent =
        `${n} m — ${n===0 ? 'Ground floor (match)' : (n>0 ? `+${n}m (~${Math.round(n/3)} floor up)` : `${n}m below`)}`;
}

/* ─── Attendance submit ───────────────────────────────── */
document.getElementById('attendanceForm').addEventListener('submit', async e => {
    e.preventDefault();
    if (!rawLat) { alert('Capture your location first.'); return; }
    const notes = document.getElementById('desc').value.trim();
    if (notes.length < 5) { alert('Add a brief lecture note (min 5 characters).'); return; }

    document.getElementById('loader').style.display = 'flex';
    const fd = new FormData();
    fd.append('class_id',    document.getElementById('class_id').value);
    fd.append('latitude',    rawLat);
    fd.append('longitude',   rawLon);
    fd.append('altitude',    rawAlt);
    fd.append('accuracy',    rawAcc);
    fd.append('description', notes);

    try {
        const d = await (await fetch('api_submit_attendance.php', {method:'POST', body:fd})).json();
        document.getElementById('loader').style.display = 'none';
        const box = document.getElementById('resultBox');
        box.style.display = 'block';
        if (d.verification_status === 'VERIFIED') {
            box.className = 'result-box success';
            box.innerHTML = `<strong>Attendance verified!</strong><br>${d.spatial?.message||''}<br>${d.altitude?.message||''}<br>${d.temporal?.message||''}`;
        } else if (d.verification_status === 'OUT_OF_RANGE') {
            box.className = 'result-box error';
            box.innerHTML = `<strong>Out of range</strong><br>${d.spatial?.message||''}<br>Distance: ${d.spatial?.distance}m`;
        } else if (d.verification_status === 'INVALID_ALTITUDE') {
            box.className = 'result-box warning';
            box.innerHTML = `<strong>Wrong floor / altitude</strong><br>${d.altitude?.message||''}`;
        } else {
            box.className = 'result-box warning';
            box.innerHTML = `<strong>${d.verification_status}</strong><br>${d.temporal?.message||d.anomaly?.reason||'Recorded for review.'}`;
        }
        document.getElementById('submitBtn').disabled = true;
    } catch(err) {
        document.getElementById('loader').style.display = 'none';
        alert('Submission error: ' + err.message);
    }
});

/* ─────────────────────────────────────────────────────── */
/*  CALENDAR                                               */
/* ─────────────────────────────────────────────────────── */
function isoWeek(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
    const ys = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    return { w: Math.ceil(((d - ys) / 86400000 + 1) / 7), y: d.getUTCFullYear() };
}
function weekBounds(wn, wy) {
    const s = new Date(Date.UTC(wy, 0, 1 + (wn - 1) * 7));
    const dow = s.getUTCDay() || 7;
    const mon = new Date(s); mon.setUTCDate(s.getUTCDate() - dow + 1);
    const sun = new Date(mon); sun.setUTCDate(mon.getUTCDate() + 6);
    return { mon, sun };
}
function ymd(d) { return d.toISOString().slice(0, 10); }

function initCal() {
    const now = new Date();
    const { w, y } = isoWeek(now);
    selWn = w; selWy = y;
    renderCal();
    renderWT(w, y);
}
function calNav(dir) {
    calMonth += dir;
    if (calMonth > 11) { calMonth = 0; calYear++; }
    if (calMonth <  0) { calMonth = 11; calYear--; }
    renderCal();
}

function renderCal() {
    document.getElementById('calMonthLbl').textContent = MONTHS[calMonth] + ' ' + calYear;
    const today = ymd(new Date());
    const first = new Date(calYear, calMonth, 1);
    const last  = new Date(calYear, calMonth + 1, 0);
    const startDow = (first.getDay() || 7) - 1;
    const cur = new Date(first);
    cur.setDate(first.getDate() - startDow);

    let html = '<div class="cal-dow-row">';
    DAYS.forEach(d => html += `<div class="cal-dow-cell">${d}</div>`);
    html += '</div>';

    while (true) {
        const { w: wn, y: wy } = isoWeek(cur);
        const isSel = wn === selWn && wy === selWy;
        html += `<div class="cal-week-row${isSel?' sel':''}" onclick="selectWeek(${wn},${wy})">`;
        html += `<div class="wn">W${wn}</div>`;
        for (let i = 0; i < 7; i++) {
            const ds = ymd(cur);
            const isT = ds === today;
            const isO = cur.getMonth() !== calMonth;
            const hasCl = CLASS_DATES.has(ds);
            html += `<div class="cal-day${isT?' today':''}${isO?' other':''}${hasCl?' has-cls':''}">${cur.getDate()}</div>`;
            cur.setDate(cur.getDate() + 1);
        }
        html += '</div>';
        if (cur > last && cur.getMonth() !== calMonth) break;
    }
    document.getElementById('calBody').innerHTML = html;
}

function selectWeek(wn, wy) {
    selWn = wn; selWy = wy;
    renderCal();
    renderWT(wn, wy);
}

function renderWT(wn, wy) {
    const { mon } = weekBounds(wn, wy);
    const dayNames = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
    const dates = [];
    for (let i = 0; i < 5; i++) {
        const d = new Date(mon);
        d.setDate(mon.getDate() + i);
        dates.push(ymd(d));
    }

    const { sun } = weekBounds(wn, wy);
    const fmt = d => new Date(d + 'T12:00:00').toLocaleDateString('en-GB', {day:'numeric', month:'short'});
    document.getElementById('wtTitle').textContent =
        `Week ${wn} — ${fmt(dates[0])} to ${new Date(sun).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})}`;

    let html = '';
    dates.forEach((ds, i) => {
        const day_cls = ALL_CLASSES.filter(c => c.scheduled_date === ds);
        html += `<div class="wt-day-lbl">${dayNames[i]} · ${fmt(ds)}</div>`;
        if (day_cls.length === 0) {
            html += `<div class="wt-none">No classes scheduled</div>`;
        } else {
            day_cls.forEach(c => {
                const st = c.scheduled_start_time.slice(0,5);
                const et = c.scheduled_end_time.slice(0,5);
                const done = c.status === 'completed' ? '<span class="badge b-done b-done-sm">Done</span>' : '';
                html += `<div class="wt-row">
                    <div class="wt-time">${st}&#8211;${et}</div>
                    <div>
                        <div class="wt-course">${c.course_code}${done}</div>
                        <div class="wt-hall">${c.hall_name}</div>
                    </div>
                </div>`;
            });
        }
    });
    document.getElementById('wtBody').innerHTML = html || '<div class="wt-empty">No classes this week.</div>';
}

/* ─── Schedule modal ──────────────────────────────────── */
function openModal(cls) {
    document.getElementById('schedModal').classList.add('show');
    if (cls) {
        document.getElementById('modalTitle').textContent      = 'Edit slot';
        document.getElementById('sched_action').value          = 'update';
        document.getElementById('sched_class_id').value        = cls.class_id;
        document.getElementById('sched_code').value            = cls.course_code;
        document.getElementById('sched_title').value           = cls.course_title;
        document.getElementById('sched_date').value            = cls.scheduled_date;
        document.getElementById('sched_start').value           = cls.scheduled_start_time.slice(0,5);
        document.getElementById('sched_end').value             = cls.scheduled_end_time.slice(0,5);
    } else {
        document.getElementById('modalTitle').textContent = 'Add schedule slot';
        document.getElementById('sched_action').value = 'create';
        document.getElementById('scheduleForm').reset();
    }
}
function closeModal() { document.getElementById('schedModal').classList.remove('show'); }

document.getElementById('scheduleForm').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        const d = await (await fetch('api_manage_schedule.php', {method:'POST', body:fd})).json();
        closeModal();
        if (d.status === 'SUCCESS') location.reload();
        else alert(d.message || 'Failed to save.');
    } catch(err) { alert('Error: ' + err.message); }
});

/* ─── Live location pinger ────────────────────────────── */
if (isShiftActive) {
    setInterval(() => {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(pos => {
            const fd = new FormData();
            fd.append('latitude',  pos.coords.latitude);
            fd.append('longitude', pos.coords.longitude);
            fd.append('altitude',  pos.coords.altitude || 0);
            fetch('api_ping_location.php', {method:'POST', body:fd}).catch(() => {});
            
            // Update Leaflet live map in real-time
            updateLiveMap(pos.coords.latitude, pos.coords.longitude);
        }, () => {}, {enableHighAccuracy:false, timeout:8000});
    }, 30000);
}
</script>
</body>
</html>
