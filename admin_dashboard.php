<?php
/**
 * admin_dashboard.php - Redesigned admin control panel
 * Supabase Edition — UI redesign preserving all original PHP/JS logic
 */
require 'config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

/* ── AJAX: Lecturer details ────────────────────────────────── */
if (isset($_GET['get_lecturer_details'])) {
    try {
        $lid  = intval($_GET['get_lecturer_details']);
        $date = isset($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        $stmt = $conn->prepare("SELECT user_id,username,email,full_name,phone,department,faculty,lecturer_number,profile_pic FROM users WHERE user_id=?");
        $stmt->execute([$lid]);
        $profile = $stmt->fetch();
        if (!$profile) sendJsonResponse(['error'=>'Lecturer not found'],404);
        $stmt = $conn->prepare("SELECT sc.course_code,sc.course_title,sc.scheduled_start_time,sc.scheduled_end_time,sc.scheduled_date,lh.hall_name FROM scheduled_classes sc JOIN lecture_halls lh ON sc.hall_id=lh.hall_id WHERE sc.lecturer_id=? ORDER BY sc.scheduled_date DESC,sc.scheduled_start_time ASC");
        $stmt->execute([$lid]);
        $schedule = $stmt->fetchAll();
        $stmt = $conn->prepare("SELECT sign_in_time,sign_in_latitude,sign_in_longitude,sign_in_altitude,sign_out_time,sign_out_latitude,sign_out_longitude,sign_out_altitude,sign_out_method FROM lecturer_shifts WHERE lecturer_id=? AND work_date=?");
        $stmt->execute([$lid,$date]);
        $shift = $stmt->fetch() ?: null;
        $stmt = $conn->prepare("SELECT latitude,longitude,altitude,logged_at FROM lecturer_location_logs WHERE lecturer_id=? AND CAST(logged_at AS DATE)=? ORDER BY logged_at ASC");
        $stmt->execute([$lid,$date]);
        $movement = $stmt->fetchAll();
        sendJsonResponse(['profile'=>$profile,'schedule'=>$schedule,'shift'=>$shift,'movement'=>$movement]);
    } catch (PDOException $e) { sendJsonResponse(['error'=>$e->getMessage()],500); }
}

/* ── AJAX: Live pings ──────────────────────────────────────── */
if (isset($_GET['get_live_pings'])) {
    try {
        $stmt = $conn->query("SELECT ll.latitude,ll.longitude,ll.altitude,ll.last_updated,u.full_name,u.department FROM live_locations ll JOIN users u ON ll.lecturer_id=u.user_id WHERE ll.last_updated > NOW() - INTERVAL '10 minutes' ORDER BY ll.last_updated DESC");
        sendJsonResponse($stmt->fetchAll());
    } catch (PDOException $e) { sendJsonResponse(['error'=>$e->getMessage()],500); }
}

$full_name = $_SESSION['full_name'];
$msg = ''; $err_msg = '';

/* ── POST: Add Hall ────────────────────────────────────────── */
if (isset($_POST['add_hall'])) {
    $hall_name   = sanitizeInput($_POST['hall_name']);
    $hall_code   = sanitizeInput($_POST['hall_code']);
    $latitude    = floatval($_POST['latitude']);
    $longitude   = floatval($_POST['longitude']);
    $altitude    = floatval($_POST['altitude']);
    $tolerance   = intval($_POST['tolerance']);
    $alt_tol     = floatval($_POST['altitude_tolerance']);
    $desc        = sanitizeInput($_POST['description']);
    if (!empty($hall_name) && !empty($hall_code) && $latitude !== 0.0 && $longitude !== 0.0) {
        try {
            $stmt = $conn->prepare("INSERT INTO lecture_halls (hall_name,hall_code,latitude,longitude,altitude_meters,tolerance_radius_meters,altitude_tolerance_meters,description) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$hall_name,$hall_code,$latitude,$longitude,$altitude,$tolerance,$alt_tol,$desc]);
            $msg = "Hall '$hall_name' added successfully.";
            logAuditTrail($_SESSION['user_id'],'ADD_LECTURE_HALL','lecture_halls',$conn->lastInsertId('lecture_halls_hall_id_seq'));
        } catch (PDOException $e) { $err_msg = "Error: ".$e->getMessage(); }
    } else { $err_msg = "Fill in all required fields and pick coordinates on the map."; }
}

/* ── POST: Schedule class ──────────────────────────────────── */
if (isset($_POST['schedule_class'])) {
    $lecturer_id  = intval($_POST['lecturer_id']);
    $hall_id      = intval($_POST['hall_id']);
    $course_code  = sanitizeInput($_POST['course_code']);
    $course_title = sanitizeInput($_POST['course_title']);
    $start_time   = $_POST['start_time'];
    $end_time     = $_POST['end_time'];
    $date         = $_POST['scheduled_date'];
    if ($lecturer_id && $hall_id && !empty($course_code) && !empty($start_time) && !empty($end_time) && !empty($date)) {
        try {
            $stmt = $conn->prepare("INSERT INTO scheduled_classes (lecturer_id,hall_id,course_code,course_title,scheduled_start_time,scheduled_end_time,scheduled_date) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$lecturer_id,$hall_id,$course_code,$course_title,$start_time,$end_time,$date]);
            $msg = "Class scheduled successfully.";
            logAuditTrail($_SESSION['user_id'],'SCHEDULE_CLASS','scheduled_classes',$conn->lastInsertId('scheduled_classes_class_id_seq'));
        } catch (PDOException $e) { $err_msg = "Error: ".$e->getMessage(); }
    } else { $err_msg = "Fill in all fields to schedule a lecture."; }
}

/* ── Stats ─────────────────────────────────────────────────── */
try {
    $stats = [
        'scheduled'   => $conn->query("SELECT COUNT(*) FROM scheduled_classes WHERE scheduled_date=CURRENT_DATE")->fetchColumn(),
        'verified'    => $conn->query("SELECT COUNT(*) FROM attendance_submissions WHERE CAST(server_timestamp AS DATE)=CURRENT_DATE AND verification_status='VERIFIED'")->fetchColumn(),
        'out_of_range'=> $conn->query("SELECT COUNT(*) FROM attendance_submissions WHERE CAST(server_timestamp AS DATE)=CURRENT_DATE AND verification_status='OUT_OF_RANGE'")->fetchColumn(),
        'anomalies'   => $conn->query("SELECT COUNT(*) FROM attendance_submissions WHERE CAST(server_timestamp AS DATE)=CURRENT_DATE AND is_anomalous=TRUE")->fetchColumn(),
    ];
} catch(PDOException $e) { $stats=['scheduled'=>0,'verified'=>0,'out_of_range'=>0,'anomalies'=>0]; }

/* ── Data ──────────────────────────────────────────────────── */
$lecturers = $conn->query("SELECT user_id,full_name,department FROM users WHERE role='lecturer' AND is_active=TRUE ORDER BY full_name ASC")->fetchAll();
$halls     = $conn->query("SELECT hall_id,hall_name,hall_code,latitude,longitude,altitude_meters,tolerance_radius_meters FROM lecture_halls ORDER BY hall_name ASC")->fetchAll();
$attendance_logs = [];
try {
    $attendance_logs = $conn->query("SELECT sub.submission_id,sc.course_code,u.full_name as lecturer_name,lh.hall_name,sub.distance_from_assigned_location,sub.verification_status,sub.server_timestamp,sub.is_anomalous,sub.submission_description FROM attendance_submissions sub JOIN scheduled_classes sc ON sub.class_id=sc.class_id JOIN users u ON sub.lecturer_id=u.user_id JOIN lecture_halls lh ON sc.hall_id=lh.hall_id ORDER BY sub.server_timestamp DESC LIMIT 50")->fetchAll();
} catch(PDOException $e) {}

$all_lecturers = $conn->query("SELECT user_id,full_name,department,faculty,lecturer_number,profile_pic,is_active FROM users WHERE role='lecturer' ORDER BY full_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — Caleb FSV</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<style>
/* ── Reset & Base ─────────────────────────────────────── */
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Outfit',sans-serif;background:#F7F8FA;display:flex;min-height:100vh;color:#1A1A2E;font-size:14px}

/* ── Sidebar ──────────────────────────────────────────── */
.sidebar{width:220px;background:#FFFFFF;border-right:1px solid #E5E8EE;display:flex;flex-direction:column;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:100}
.logo-wrap{padding:18px 16px 16px;border-bottom:1px solid #F0F2F5}
.logo{display:flex;align-items:center;gap:10px}
.logo-icon{width:34px;height:34px;background:#214F3B;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.logo-icon svg{width:18px;height:18px}
.logo-name{font-size:14px;font-weight:700;color:#1A1A2E;line-height:1.2}
.logo-sub{font-size:10px;color:#8B93A1}
nav{flex:1;padding:14px 10px;display:flex;flex-direction:column;gap:2px;overflow-y:auto}
.nav-item{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;color:#4B5263;transition:background .15s,color .15s;border:none;background:none;width:100%;text-align:left;font-family:'Inter',sans-serif}
.nav-item:hover{background:#F7F8FA;color:#1A1A2E}
.nav-item.active{background:#EBF5EF;color:#2D6A4F;font-weight:600}
.nav-item svg{width:15px;height:15px;flex-shrink:0;opacity:.7}
.nav-item.active svg{opacity:1}
.nav-sep{height:1px;background:#F0F2F5;margin:8px 8px}
.sidebar-footer{padding:12px 10px;border-top:1px solid #F0F2F5}
.user-card{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:8px;background:#F7F8FA;margin-bottom:8px}
.avatar{width:30px;height:30px;border-radius:50%;background:#EBF5EF;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#2D6A4F;flex-shrink:0}
.user-name{font-size:12px;font-weight:600;color:#1A1A2E;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:10px;color:#8B93A1}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;border-radius:8px;background:none;border:1px solid #E5E8EE;cursor:pointer;font-size:12px;color:#4B5263;width:100%;font-family:'Inter',sans-serif;transition:background .15s}
.btn-logout:hover{background:#FEF2F2;color:#DC2626;border-color:#FECACA}
.btn-logout svg{width:13px;height:13px}

/* ── Main ─────────────────────────────────────────────── */
.main{margin-left:220px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{background:#FFFFFF;border-bottom:1px solid #E5E8EE;padding:12px 22px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.page-title{font-size:16px;font-weight:700;color:#1A1A2E}
.page-sub{font-size:12px;color:#8B93A1;margin-top:1px}
.topbar-chips{display:flex;gap:8px;align-items:center}
.chip{background:#F7F8FA;border:1px solid #E5E8EE;border-radius:20px;padding:5px 12px;font-size:11px;color:#4B5263;font-weight:500}
.content{padding:20px 22px;flex:1}
.panel{display:none;animation:fadeIn .2s ease}
.panel.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}

/* ── Alerts ───────────────────────────────────────────── */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;border:1px solid}
.alert-success{background:#EBF5EF;color:#2D6A4F;border-color:#86EFAC}
.alert-danger{background:#FEF2F2;color:#DC2626;border-color:#FECACA}
.alert svg{width:15px;height:15px;flex-shrink:0}

/* ── Stat cards ───────────────────────────────────────── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:#FFFFFF;border:1px solid #E5E8EE;border-radius:12px;padding:18px 18px 16px}
.stat-label{font-size:11px;font-weight:600;color:#8B93A1;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.stat-label svg{width:13px;height:13px}
.stat-val{font-size:30px;font-weight:700;color:#1A1A2E;line-height:1}
.stat-val.green{color:#2D6A4F}
.stat-val.red{color:#DC2626}
.stat-val.amber{color:#B45309}

/* ── Section header ───────────────────────────────────── */
.section-head{font-size:13px;font-weight:700;color:#1A1A2E;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between}

/* ── Card & table ─────────────────────────────────────── */
.card{background:#FFFFFF;border:1px solid #E5E8EE;border-radius:12px;overflow:hidden}
.card-pad{padding:22px}
table{width:100%;border-collapse:collapse}
th{font-size:11px;font-weight:600;color:#8B93A1;text-transform:uppercase;letter-spacing:.4px;padding:10px 16px;background:#F7F8FA;text-align:left;border-bottom:1px solid #E5E8EE}
td{padding:11px 16px;font-size:12px;color:#4B5263;border-bottom:1px solid #F0F2F5}
tr:last-child td{border-bottom:none}
tr:hover td{background:#F7F8FA}
td strong{color:#1A1A2E;font-weight:600}
code{font-size:11px;background:#F0F2F5;padding:2px 7px;border-radius:5px;font-family:monospace}

/* ── Badges ───────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:.2px}
.badge-verified{background:#EBF5EF;color:#2D6A4F}
.badge-out_of_range{background:#FEF2F2;color:#DC2626}
.badge-invalid_altitude{background:#EFF6FF;color:#1D4ED8}
.badge-invalid_time{background:#FFFBEB;color:#B45309}
.badge-pending{background:#F5F3FF;color:#6D28D9}
.badge-anomaly{background:#FEF2F2;color:#DC2626;border:1px solid #FECACA}
.clear{color:#2D6A4F;font-size:11px;font-weight:600}

/* ── Forms ────────────────────────────────────────────── */
.form-layout{display:grid;grid-template-columns:1fr 1fr;gap:28px}
@media(max-width:900px){.form-layout{grid-template-columns:1fr}}
.form-col{display:flex;flex-direction:column;gap:14px}
.field{display:flex;flex-direction:column;gap:5px}
.field label{font-size:11px;font-weight:600;color:#8B93A1;text-transform:uppercase;letter-spacing:.3px}
.field input,.field select,.field textarea{padding:9px 12px;border:1px solid #E5E8EE;border-radius:10px;font-size:13px;font-family:'Outfit',sans-serif;color:#1A1A2E;background:#FFFFFF;outline:none;transition:border-color .15s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#52A878}
.field input[readonly]{background:#F7F8FA;color:#4B5263;cursor:default}
.field textarea{resize:none;height:80px}
.field small{font-size:10px;color:#8B93A1;margin-top:2px}
.two-col-field{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn-green{padding:10px 18px;background:#214F3B;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Outfit',sans-serif;display:inline-flex;align-items:center;gap:7px;transition:background .15s}
.btn-green:hover{background:#3B7A57}
.btn-green svg{width:14px;height:14px}
.btn-outline{padding:7px 14px;background:#FFFFFF;border:1px solid #E5E8EE;border-radius:10px;font-size:12px;font-weight:500;cursor:pointer;color:#4B5263;font-family:'Outfit',sans-serif;transition:background .15s}
.btn-outline:hover{background:#F7F8FA}

/* ── Map ──────────────────────────────────────────────── */
#picker-map,#tracker-map{width:100%;height:400px;border-radius:10px;border:1px solid #E5E8EE;margin-bottom:8px}
.map-hint{font-size:11px;color:#8B93A1;font-style:italic;margin-top:4px}

/* ── Lecturers panel ──────────────────────────────────── */
.lec-layout{display:grid;grid-template-columns:260px 1fr;gap:16px;min-height:600px}
.lec-list{background:#F7F8FA;border-radius:10px;padding:12px;overflow-y:auto;max-height:680px;display:flex;flex-direction:column;gap:5px}
.lec-list-label{font-size:10px;font-weight:700;text-transform:uppercase;color:#8B93A1;letter-spacing:.4px;padding:0 4px;margin-bottom:6px}
.lec-item{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:8px;cursor:pointer;border:1px solid transparent;transition:all .15s}
.lec-item:hover{background:#FFFFFF;border-color:#E5E8EE}
.lec-item.selected{background:#EBF5EF;border-color:#86EFAC}
.lec-item.selected .lec-item-name{color:#2D6A4F}
.lec-avatar{width:34px;height:34px;border-radius:50%;background:#E5E8EE;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#2D6A4F;flex-shrink:0;overflow:hidden}
.lec-avatar img{width:100%;height:100%;object-fit:cover}
.lec-item-name{font-size:12px;font-weight:600;color:#1A1A2E}
.lec-item-dept{font-size:11px;color:#8B93A1}
.detail-card{background:#FFFFFF;border:1px solid #E5E8EE;border-radius:12px;padding:22px;display:flex;flex-direction:column;gap:16px}
.detail-header{background:linear-gradient(135deg,#2D6A4F,#52A878);border-radius:10px;padding:20px;color:#fff;display:flex;align-items:center;gap:16px}
.detail-header-avatar{width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden}
.detail-header-avatar img{width:100%;height:100%;object-fit:cover}
.detail-name{font-size:18px;font-weight:700}
.detail-meta{font-size:12px;opacity:.85;margin-top:2px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.info-box{background:#F7F8FA;border-radius:8px;padding:12px}
.info-box-label{font-size:10px;font-weight:600;color:#8B93A1;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px}
.info-box-val{font-size:13px;color:#1A1A2E;font-weight:500}
.shift-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.shift-row-title{font-size:12px;font-weight:700;color:#1A1A2E}
.date-input{padding:5px 10px;border:1px solid #E5E8EE;border-radius:8px;font-size:12px;font-family:'Outfit',sans-serif;color:#1A1A2E;outline:none}
.date-input:focus{border-color:#52A878}
.shift-info{font-size:12px;color:#4B5263;line-height:1.6}
.sched-table{width:100%;border-collapse:collapse;font-size:12px}
.sched-table th{font-size:10px;font-weight:600;text-transform:uppercase;color:#8B93A1;padding:7px 10px;background:#F7F8FA;letter-spacing:.3px;border-bottom:1px solid #E5E8EE;text-align:left}
.sched-table td{padding:8px 10px;border-bottom:1px solid #F0F2F5;color:#4B5263}
.sched-table tr:last-child td{border-bottom:none}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;height:320px;gap:10px;color:#8B93A1;text-align:center}
.empty-state svg{width:36px;height:36px;opacity:.25}
.empty-state p{font-size:13px}
#lecturerMovementMap{width:100%;height:300px;border-radius:8px;border:1px solid #E5E8EE}

/* ── Tracker ──────────────────────────────────────────── */
.tracker-grid{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start}
.active-badge{background:#EBF5EF;color:#2D6A4F;border-color:#86EFAC}
</style>
</head>
<body>

<!-- ═══ SIDEBAR ═══════════════════════════════════════════ -->
<div class="sidebar">
  <div class="logo-wrap">
    <div class="logo">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
      </div>
      <div>
        <div class="logo-name">Caleb FSV</div>
        <div class="logo-sub">Admin Portal</div>
      </div>
    </div>
  </div>

  <nav>
    <button class="nav-item active" onclick="switchPanel('dashboard',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </button>
    <button class="nav-item" onclick="switchPanel('live-tracker-panel',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="4 2"/></svg>
      Live Tracker
    </button>
    <button class="nav-item" onclick="switchPanel('lecturers-panel',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Lecturers
    </button>
    <div class="nav-sep"></div>
    <button class="nav-item" onclick="switchPanel('add-hall-panel',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      Add Hall
    </button>
    <button class="nav-item" onclick="switchPanel('schedule-panel',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="10" y1="16" x2="14" y2="16"/></svg>
      Schedule Class
    </button>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar"><?php echo strtoupper(substr($full_name,0,2)); ?></div>
      <div>
        <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
        <div class="user-role">Administrator</div>
      </div>
    </div>
    <a href="logout.php" class="btn-logout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign out
    </a>
  </div>
</div>

<!-- ═══ MAIN ═══════════════════════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar" id="topbarEl">
    <div>
      <div class="page-title" id="topbarTitle">Dashboard overview</div>
      <div class="page-sub">Welcome back, <strong><?php echo htmlspecialchars($full_name); ?></strong></div>
    </div>
    <div class="topbar-chips">
      <div class="chip">🕒 <?php echo date('H:i'); ?></div>
      <div class="chip"><?php echo date('D, d M Y'); ?></div>
    </div>
  </div>

  <div class="content">

    <?php if (!empty($msg)): ?>
    <div class="alert alert-success">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      <?php echo $msg; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($err_msg)): ?>
    <div class="alert alert-danger">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?php echo $err_msg; ?>
    </div>
    <?php endif; ?>

    <!-- ── DASHBOARD ─────────────────────────────────────── -->
    <div id="dashboard" class="panel active">
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Classes today
          </div>
          <div class="stat-val"><?php echo $stats['scheduled']; ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Verified check-ins
          </div>
          <div class="stat-val green"><?php echo $stats['verified']; ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            Out of range
          </div>
          <div class="stat-val red"><?php echo $stats['out_of_range']; ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Flagged anomalies
          </div>
          <div class="stat-val amber"><?php echo $stats['anomalies']; ?></div>
        </div>
      </div>

      <div class="section-head">
        Recent attendance check-ins
        <button class="btn-outline">Export CSV</button>
      </div>
      <div class="card">
        <?php if (count($attendance_logs) === 0): ?>
        <div style="padding:48px;text-align:center;color:#8B93A1;font-size:13px">No attendance submissions yet today.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Timestamp</th><th>Lecturer</th><th>Course</th><th>Venue</th>
              <th>Distance</th><th>Status</th><th>Anomaly</th><th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($attendance_logs as $log): ?>
            <tr>
              <td><?php echo date('H:i:s', strtotime($log['server_timestamp'])); ?></td>
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
                  <span class="badge badge-anomaly">🚩 Flagged</span>
                <?php else: ?>
                  <span class="clear">✓ Clear</span>
                <?php endif; ?>
              </td>
              <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars($log['submission_description']); ?>">
                <?php echo htmlspecialchars($log['submission_description']); ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── LIVE TRACKER ───────────────────────────────────── -->
    <div id="live-tracker-panel" class="panel">
      <div class="tracker-grid">
        <div>
          <div id="tracker-map"></div>
          <p class="map-hint">Lecturer positions refresh automatically every 10 seconds. Only staff signed in within the last 10 minutes appear.</p>
        </div>
        <div>
          <div class="section-head" style="margin-bottom:10px">Active right now</div>
          <div class="card" id="liveList">
            <div style="padding:20px;text-align:center;color:#8B93A1;font-size:12px">Loading…</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── LECTURERS ──────────────────────────────────────── -->
    <div id="lecturers-panel" class="panel">
      <div class="lec-layout">
        <!-- Left: list -->
        <div class="lec-list">
          <div class="lec-list-label">All registered lecturers</div>
          <?php if (count($all_lecturers) === 0): ?>
          <p style="font-size:12px;color:#8B93A1;padding:8px 4px">No lecturers registered yet.</p>
          <?php else: ?>
          <?php foreach ($all_lecturers as $lec): ?>
          <div class="lec-item" onclick="loadLecturerDetails(<?php echo $lec['user_id']; ?>)" id="lec-item-<?php echo $lec['user_id']; ?>">
            <div class="lec-avatar">
              <?php if ($lec['profile_pic']): ?>
                <img src="<?php echo htmlspecialchars($lec['profile_pic']); ?>" alt="">
              <?php else: ?>
                <?php echo strtoupper(substr($lec['full_name'],0,2)); ?>
              <?php endif; ?>
            </div>
            <div>
              <div class="lec-item-name"><?php echo htmlspecialchars($lec['full_name']); ?></div>
              <div class="lec-item-dept"><?php echo htmlspecialchars($lec['department'] ?? ''); ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Right: detail -->
        <div id="lecturerDetailPanel" style="display:none">
          <div class="detail-card">
            <!-- Profile header -->
            <div class="detail-header">
              <div class="detail-header-avatar">
                <img id="ldProfilePic" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:50%">
                <span id="ldInitialAvatar">?</span>
              </div>
              <div>
                <div class="detail-name" id="ldFullName"></div>
                <div class="detail-meta" id="ldFaculty"></div>
                <div class="detail-meta" id="ldDept"></div>
                <div class="detail-meta" style="opacity:.7;font-size:11px;margin-top:3px" id="ldLecNum"></div>
              </div>
            </div>

            <!-- Contact -->
            <div class="info-grid">
              <div class="info-box"><div class="info-box-label">Email</div><div class="info-box-val" id="ldEmail">—</div></div>
              <div class="info-box"><div class="info-box-label">Phone</div><div class="info-box-val" id="ldPhone">—</div></div>
            </div>

            <!-- Shift -->
            <div class="card-pad" style="background:#F7F8FA;border-radius:8px;padding:14px">
              <div class="shift-row">
                <div class="shift-row-title">Daily shift log</div>
                <input type="date" id="ldDatePicker" class="date-input" value="<?php echo date('Y-m-d'); ?>" onchange="reloadLecturerDate()">
              </div>
              <div class="shift-info" id="ldShiftInfo">—</div>
            </div>

            <!-- Timetable -->
            <div>
              <div class="section-head" style="font-size:12px;margin-bottom:8px">Timetable</div>
              <div style="overflow-y:auto;max-height:180px;border:1px solid #E5E8EE;border-radius:8px">
                <table class="sched-table">
                  <thead><tr><th>Date</th><th>Course</th><th>Time</th><th>Venue</th></tr></thead>
                  <tbody id="ldScheduleBody"><tr><td colspan="4" style="text-align:center;color:#8B93A1;padding:20px">Select a lecturer</td></tr></tbody>
                </table>
              </div>
            </div>

            <!-- Movement map -->
            <div>
              <div class="section-head" style="font-size:12px;margin-bottom:6px">Movement map</div>
              <p style="font-size:11px;color:#8B93A1;margin-bottom:8px">Dots and lines show the lecturer's recorded path for the selected date.</p>
              <div id="lecturerMovementMap"></div>
              <div id="ldMovementCount" style="font-size:11px;color:#8B93A1;margin-top:6px"></div>
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div id="lecturerDetailEmpty" class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          <p>Select a lecturer to view their profile</p>
        </div>
      </div>
    </div>

    <!-- ── ADD HALL ───────────────────────────────────────── -->
    <div id="add-hall-panel" class="panel">
      <div class="form-layout">
        <form action="admin_dashboard.php" method="POST" class="form-col">
          <input type="hidden" name="add_hall" value="1">
          <div class="field"><label>Hall name *</label><input type="text" name="hall_name" placeholder="e.g. Science Auditorium Block A" required></div>
          <div class="field"><label>Hall code *</label><input type="text" name="hall_code" placeholder="e.g. AUD-SCI-A" required></div>
          <div class="two-col-field">
            <div class="field"><label>Horizontal radius (m)</label><input type="number" name="tolerance" value="30" required></div>
            <div class="field"><label>Altitude tolerance (m)</label><input type="number" step="0.5" name="altitude_tolerance" value="2.5" required></div>
          </div>
          <div class="two-col-field">
            <div class="field"><label>Latitude *</label><input type="text" id="latitude" name="latitude" readonly required placeholder="Click map to fill"></div>
            <div class="field"><label>Longitude *</label><input type="text" id="longitude" name="longitude" readonly required placeholder="Click map to fill"></div>
          </div>
          <div class="field">
            <label>Floor level *</label>
            <select name="altitude">
              <option value="0.00">Ground floor (0 m)</option>
              <option value="3.00">First floor (+3 m)</option>
              <option value="6.00">Second floor (+6 m)</option>
              <option value="9.00">Third floor (+9 m)</option>
              <option value="12.00">Fourth floor (+12 m)</option>
            </select>
            <small>Restricts check-in to this height — lecturer must be on this floor.</small>
          </div>
          <div class="field"><label>Notes</label><textarea name="description" placeholder="Wing, landmarks, access notes…"></textarea></div>
          <div>
            <button type="submit" class="btn-green">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
              Save hall
            </button>
          </div>
        </form>
        <div>
          <div style="font-size:11px;font-weight:600;color:#8B93A1;text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">Click map to pin location</div>
          <div id="picker-map"></div>
          <p class="map-hint">Drag or zoom to the campus, then click to drop a pin. Coordinates auto-fill in the form.</p>
        </div>
      </div>
    </div>

    <!-- ── SCHEDULE CLASS ─────────────────────────────────── -->
    <div id="schedule-panel" class="panel">
      <div style="max-width:540px">
        <div class="card card-pad">
          <form action="admin_dashboard.php" method="POST" style="display:flex;flex-direction:column;gap:14px">
            <input type="hidden" name="schedule_class" value="1">
            <div class="field">
              <label>Lecturer *</label>
              <select name="lecturer_id" required>
                <option value="">Select a lecturer</option>
                <?php foreach ($lecturers as $lec): ?>
                <option value="<?php echo $lec['user_id']; ?>"><?php echo htmlspecialchars($lec['full_name']); ?> (<?php echo htmlspecialchars($lec['department']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Venue *</label>
              <select name="hall_id" required>
                <option value="">Select a hall</option>
                <?php foreach ($halls as $h): ?>
                <option value="<?php echo $h['hall_id']; ?>"><?php echo htmlspecialchars($h['hall_name']); ?> [<?php echo htmlspecialchars($h['hall_code']); ?>]</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>Course code *</label><input type="text" name="course_code" placeholder="e.g. CSC 401" required></div>
            <div class="field"><label>Course title *</label><input type="text" name="course_title" placeholder="e.g. Distributed Database Architecture" required></div>
            <div class="two-col-field">
              <div class="field"><label>Start time *</label><input type="time" name="start_time" required></div>
              <div class="field"><label>End time *</label><input type="time" name="end_time" required></div>
            </div>
            <div class="field"><label>Date *</label><input type="date" name="scheduled_date" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div style="padding-top:6px">
              <button type="submit" class="btn-green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="10" y1="16" x2="14" y2="16"/></svg>
                Schedule class
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
/* ── Panel switching ─────────────────────────────────────── */
const panelTitles = {
  'dashboard':         ['Dashboard overview',        "Today's attendance at a glance"],
  'live-tracker-panel':['Live staff tracker',        'Real-time positions — refreshes every 10 s'],
  'lecturers-panel':   ['Lecturers',                 'Click a name to view profile and shift data'],
  'add-hall-panel':    ['Add lecture hall',           'Configure 3D geofence for attendance verification'],
  'schedule-panel':    ['Schedule a class',           'Assign a lecturer to a hall and time slot'],
};

function switchPanel(panelId, el) {
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
  document.getElementById(panelId).classList.add('active');
  el.classList.add('active');
  const t = panelTitles[panelId];
  if (t) { document.getElementById('topbarTitle').textContent = t[0]; document.querySelector('.page-sub').innerHTML = t[1]; }
  if (panelId === 'add-hall-panel' && !pickerMap) setTimeout(initPickerMap, 100);
  if (panelId === 'live-tracker-panel' && !trackerMap) setTimeout(initTrackerMap, 100);
}

/* ── Coordinate picker map ───────────────────────────────── */
let pickerMap = null, selectedMarker = null;
function initPickerMap() {
  pickerMap = L.map('picker-map').setView([6.5244, 3.3792], 10);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(pickerMap);
  pickerMap.on('click', function(e) {
    document.getElementById('latitude').value  = e.latlng.lat.toFixed(7);
    document.getElementById('longitude').value = e.latlng.lng.toFixed(7);
    if (selectedMarker) selectedMarker.setLatLng(e.latlng);
    else selectedMarker = L.marker(e.latlng).addTo(pickerMap);
    pickerMap.panTo(e.latlng);
  });
}

/* ── Live tracker map ────────────────────────────────────── */
let trackerMap = null, trackerMarkers = {};
function initTrackerMap() {
  trackerMap = L.map('tracker-map').setView([6.5244, 3.3792], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(trackerMap);
  fetchLivePings();
  setInterval(fetchLivePings, 10000);
}
async function fetchLivePings() {
  if (!trackerMap) return;
  try {
    const data = await (await fetch('admin_dashboard.php?get_live_pings=1')).json();
    const active = data.map(p => p.full_name);
    Object.keys(trackerMarkers).forEach(n => { if (!active.includes(n)) { trackerMarkers[n].removeFrom(trackerMap); delete trackerMarkers[n]; } });
    data.forEach(ping => {
      const lat = parseFloat(ping.latitude), lon = parseFloat(ping.longitude);
      const popup = `<strong>${ping.full_name}</strong><br>${ping.department}<br>Alt: ${parseFloat(ping.altitude).toFixed(1)}m<br>${new Date(ping.last_updated).toLocaleTimeString()}`;
      if (trackerMarkers[ping.full_name]) trackerMarkers[ping.full_name].setLatLng([lat,lon]).setPopupContent(popup);
      else trackerMarkers[ping.full_name] = L.marker([lat,lon]).addTo(trackerMap).bindPopup(popup);
    });
    const list = Object.values(trackerMarkers);
    if (list.length) trackerMap.fitBounds(new L.featureGroup(list).getBounds().pad(0.2));
    // Update sidebar list
    const liveList = document.getElementById('liveList');
    if (data.length === 0) { liveList.innerHTML = '<div style="padding:20px;text-align:center;color:#8B93A1;font-size:12px">No active lecturers right now.</div>'; return; }
    liveList.innerHTML = '<table><thead><tr><th>Name</th><th>Dept</th><th>Last ping</th></tr></thead><tbody>' +
      data.map(p => `<tr><td><strong>${p.full_name}</strong></td><td>${p.department}</td><td style="color:#2D6A4F;font-weight:500">${new Date(p.last_updated).toLocaleTimeString()}</td></tr>`).join('') +
      '</tbody></table>';
  } catch(e) { console.error(e); }
}

/* ── Lecturer detail ─────────────────────────────────────── */
let currentLecturerId = null, movementMap = null, movementPolyline = null, movementMarkers = [];
async function loadLecturerDetails(lecId) {
  currentLecturerId = lecId;
  document.querySelectorAll('.lec-item').forEach(i => i.classList.remove('selected'));
  const el = document.getElementById('lec-item-' + lecId);
  if (el) el.classList.add('selected');
  const date = document.getElementById('ldDatePicker').value;
  document.getElementById('lecturerDetailPanel').style.display = 'block';
  document.getElementById('lecturerDetailEmpty').style.display  = 'none';
  document.getElementById('ldScheduleBody').innerHTML = '<tr><td colspan="4" style="text-align:center;color:#8B93A1;padding:16px">Loading…</td></tr>';
  document.getElementById('ldShiftInfo').textContent = 'Loading…';
  try {
    const data = await (await fetch(`admin_dashboard.php?get_lecturer_details=${lecId}&date=${date}`)).json();
    if (data.error) { alert(data.error); return; }
    const p = data.profile;
    // Header
    if (p.profile_pic) {
      document.getElementById('ldProfilePic').src = p.profile_pic;
      document.getElementById('ldProfilePic').style.display = 'block';
      document.getElementById('ldInitialAvatar').style.display = 'none';
    } else {
      document.getElementById('ldProfilePic').style.display = 'none';
      document.getElementById('ldInitialAvatar').style.display = 'inline';
      document.getElementById('ldInitialAvatar').textContent = (p.full_name||'?').substring(0,2).toUpperCase();
    }
    document.getElementById('ldFullName').textContent = p.full_name || '—';
    document.getElementById('ldFaculty').textContent  = p.faculty || '';
    document.getElementById('ldDept').textContent     = p.department || '';
    document.getElementById('ldLecNum').textContent   = p.lecturer_number ? 'ID: ' + p.lecturer_number : '';
    document.getElementById('ldEmail').textContent    = p.email || '—';
    document.getElementById('ldPhone').textContent    = p.phone || '—';
    // Shift
    const s = data.shift;
    document.getElementById('ldShiftInfo').innerHTML = s
      ? `Sign-in: <strong>${s.sign_in_time||'—'}</strong> &nbsp;|&nbsp; Sign-out: <strong>${s.sign_out_time||'Still active'}</strong>${s.sign_out_method==='auto_geofence'?' (auto-detected)':''}`
      : 'No shift recorded for this date.';
    // Schedule
    const rows = data.schedule.length
      ? data.schedule.map(c => `<tr><td>${c.scheduled_date}</td><td><strong>${c.course_code}</strong> — ${c.course_title}</td><td>${c.scheduled_start_time}–${c.scheduled_end_time}</td><td>${c.hall_name}</td></tr>`).join('')
      : '<tr><td colspan="4" style="text-align:center;color:#8B93A1;padding:14px">No scheduled classes found.</td></tr>';
    document.getElementById('ldScheduleBody').innerHTML = rows;
    // Movement map
    if (!movementMap) {
      movementMap = L.map('lecturerMovementMap').setView([6.6718, 3.4908], 15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(movementMap);
    }
    movementMarkers.forEach(m => m.removeFrom(movementMap));
    movementMarkers = [];
    if (movementPolyline) movementPolyline.removeFrom(movementMap);
    if (data.movement && data.movement.length) {
      const coords = data.movement.map(m => [parseFloat(m.latitude), parseFloat(m.longitude)]);
      movementPolyline = L.polyline(coords, {color:'#2D6A4F', weight:2}).addTo(movementMap);
      coords.forEach((c,i) => {
        const m = L.circleMarker(c, {radius:5, color:'#2D6A4F', fillColor:'#52A878', fillOpacity:1, weight:2}).addTo(movementMap);
        m.bindPopup(`Point ${i+1}<br>${data.movement[i].logged_at}`);
        movementMarkers.push(m);
      });
      movementMap.fitBounds(movementPolyline.getBounds().pad(0.15));
      document.getElementById('ldMovementCount').textContent = `${data.movement.length} location points recorded`;
    } else {
      movementMap.setView([6.6718, 3.4908], 15);
      document.getElementById('ldMovementCount').textContent = 'No movement data for this date.';
    }
  } catch(e) { console.error(e); }
}

function reloadLecturerDate() { if (currentLecturerId) loadLecturerDetails(currentLecturerId); }
</script>
</body>
</html>
