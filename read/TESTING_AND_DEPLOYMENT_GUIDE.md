# Field Staff Activity Verification System
## Testing, Deployment & Defense Guide

---

## PART 1: LOCAL SETUP & TESTING

### Prerequisites
- **XAMPP** or **WAMP** (Apache + MySQL + PHP)
- **Text Editor** (VS Code recommended)
- **Web Browser** (Chrome, Firefox, Edge)
- **Basic SQL knowledge**

---

## Step 1: Install XAMPP

1. Download XAMPP from [apachefriends.org](https://www.apachefriends.org)
2. Install and start Apache & MySQL services
3. Navigate to `http://localhost/phpmyadmin`

---

## Step 2: Create Database

1. Open phpMyAdmin
2. Click "New" to create a new database
3. Name it: `field_staff_system`
4. Copy the entire SQL schema from `database_schema.sql`
5. Paste into the "SQL" tab and execute

**Verify:** You should see 8 tables created:
- users
- work_locations
- activities
- activity_submissions
- audit_logs
- system_settings
- attendance_reports
- attendance_reports_view

---

## Step 3: Set Up Project Files

### Folder Structure

```
htdocs/
└── field_staff_system/
    ├── config.php
    ├── api_submit_activity.php
    ├── submit_activity.html
    ├── admin_dashboard.php (optional)
    ├── index.php (optional)
    └── README.md
```

### File Locations

1. Copy `config.php` to your htdocs folder
2. Copy `api_submit_activity.php` to your htdocs folder
3. Copy `submit_activity.html` to your htdocs folder
4. Create `index.php` (redirect/login page - optional)

---

## Step 4: Test Database Connectivity

Create a test file: `test_connection.php`

```php
<?php
require 'config.php';

echo "Database Connected Successfully!<br>";

// Test query
$result = $conn->query("SELECT * FROM work_locations");
echo "Work Locations: " . $result->num_rows . " records<br>";

// Test users
$result = $conn->query("SELECT * FROM users");
echo "Users: " . $result->num_rows . " records<br>";

?>
```

Access: `http://localhost/field_staff_system/test_connection.php`

---

## PART 2: TESTING SCENARIOS

### Test Case 1: Valid Submission (Within Range & Time)

**Scenario:** Staff member checks in at Lagos Office during scheduled time

**Test Steps:**
1. Open `http://localhost/field_staff_system/submit_activity.html`
2. Select "Client Meeting - Lagos Office (09:00-10:30)"
3. Click "Get My Location" → Use real GPS or simulated location
4. Enter description: "Completed client meeting successfully"
5. Click "Submit Activity"

**Expected Result:**
```
✓ VERIFIED
Location: ✓ PASS (distance < 100m)
Time: ✓ PASS (within 09:00-10:30)
Timestamp: Server-generated (immutable)
```

**Database Check:**
```sql
SELECT * FROM activity_submissions 
WHERE verification_status = 'VERIFIED' 
ORDER BY created_at DESC LIMIT 1;
```

---

### Test Case 2: Invalid Submission (Out of Range)

**Scenario:** Staff member submits from wrong location

**Test Steps:**
1. Select "Client Meeting - Lagos Office"
2. Manually edit latitude to: `6.6000` (far from 6.5244)
3. Click "Submit Activity"

**Expected Result:**
```
✗ OUT_OF_RANGE
Location: ✗ FAIL (distance > 100m, e.g., 8.5 km away)
Time: ✓ PASS (still within hours)
Status: INVALID
```

---

### Test Case 3: Invalid Submission (Outside Work Hours)

**Scenario:** Staff submits before scheduled time

**Test Steps:**
1. Select "Equipment Installation - Ikeja (14:00-16:00)"
2. Note current time
3. If before 14:00, submit anyway
4. Or modify system time for testing

**Expected Result:**
```
✗ INVALID_TIME
Location: ✓ PASS (correct location)
Time: ✗ FAIL (too early, activity starts at 14:00)
```

---

### Test Case 4: Velocity Anomaly Detection

**Scenario:** Simulate impossible travel (spoofing detection)

**Test Steps:**
1. Submit activity at Lagos (6.5244, 3.3792)
2. Immediately try to submit at Abuja (9.0765, 7.3986)
   - Distance: ~450 km
   - Time: 2 minutes
   - Implied speed: 13,500 km/h ❌

**Expected Result:**
```
Anomaly Detected: Impossible travel
Distance: 450000m in 2 minutes
Implied speed: 13500 km/h (exceeds max 100 km/h)
Status: FLAGGED FOR REVIEW
```

---

## PART 3: DATABASE VERIFICATION

### Check Submissions

```sql
SELECT 
    submission_id,
    submitted_latitude,
    submitted_longitude,
    distance_from_assigned_location,
    verification_status,
    server_timestamp,
    is_anomalous
FROM activity_submissions
ORDER BY created_at DESC;
```

### Check Immutability

```sql
-- Try to update a submission (this should FAIL)
UPDATE activity_submissions 
SET verification_status = 'VERIFIED' 
WHERE submission_id = 1;

-- Error: "Activity submissions are immutable and cannot be updated"
```

### Check Audit Trail

```sql
SELECT 
    user_id,
    action,
    record_id,
    created_at,
    ip_address
FROM audit_logs
ORDER BY created_at DESC
LIMIT 10;
```

---

## PART 4: HAVERSINE FORMULA VERIFICATION

### Manual Distance Calculation Test

```php
<?php
// Test Haversine Formula
function testHaversine() {
    // Lagos Office: 6.5244, 3.3792
    // Ikeja: 6.5521, 3.3521
    // Expected distance: ~3.1 km
    
    $distance = calculateHaversineDistance(6.5244, 3.3792, 6.5521, 3.3521);
    echo "Distance between Lagos & Ikeja: " . $distance . " meters<br>";
    
    // Should output: approximately 3100-3200 meters
}
?>
```

---

## PART 5: ADMIN DASHBOARD (Optional)

Create `admin_dashboard.php`:

```php
<?php
session_start();
require 'config.php';

// Check admin role
if ($_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Get all submissions
$query = "SELECT 
            s.submission_id,
            u.username,
            wl.location_name,
            s.distance_from_assigned_location,
            s.verification_status,
            s.server_timestamp,
            s.is_anomalous
          FROM activity_submissions s
          JOIN users u ON s.user_id = u.user_id
          JOIN activities a ON s.activity_id = a.activity_id
          JOIN work_locations wl ON a.location_id = wl.location_id
          ORDER BY s.server_timestamp DESC";

$result = $conn->query($query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #667eea; color: white; }
        .verified { background: #d4edda; }
        .invalid { background: #f8d7da; }
        .anomaly { background: #fff3cd; }
    </style>
</head>
<body>
    <h1>Field Staff Activity Dashboard</h1>
    
    <table>
        <tr>
            <th>ID</th>
            <th>Staff</th>
            <th>Location</th>
            <th>Distance (m)</th>
            <th>Status</th>
            <th>Timestamp</th>
            <th>Anomaly</th>
        </tr>
        
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr class="<?php echo strtolower($row['verification_status']); ?>">
            <td><?php echo $row['submission_id']; ?></td>
            <td><?php echo $row['username']; ?></td>
            <td><?php echo $row['location_name']; ?></td>
            <td><?php echo $row['distance_from_assigned_location']; ?></td>
            <td><strong><?php echo $row['verification_status']; ?></strong></td>
            <td><?php echo $row['server_timestamp']; ?></td>
            <td><?php echo $row['is_anomalous'] ? '🚨 YES' : '✓ No'; ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
</body>
</html>
```

---

## PART 6: THESIS DEFENSE PREPARATION

### Key Points to Emphasize

1. **GPS Agnostic Architecture**
   - "The system works with ANY location source"
   - "Mock GPS for testing; real GPS for deployment"
   - "No hardware dependencies"

2. **Security Features**
   - Server-side timestamp (immutable)
   - Database triggers prevent data modification
   - Audit trail for compliance
   - Haversine validation prevents spoofing

3. **Academic Approach**
   - "Using simulated data is standard practice"
   - "System proves the concept effectively"
   - "Scalable to real GPS without code changes"

4. **Nigerian Context**
   - Low bandwidth optimized
   - No app installation required
   - Works on any smartphone
   - Affordable for SMEs

### Live Demo Script

```
1. Open submit_activity.html
2. Select "Client Meeting - Lagos Office"
3. Click "Get Location" (show coordinates)
4. Enter task description
5. Click "Submit"
6. Show verification result (✓ VERIFIED)
7. Open admin dashboard
8. Show database record
9. Demonstrate immutability (try to update - fails)
10. Show audit log
```

---

## PART 7: TROUBLESHOOTING

### Issue: "Connection Refused"
```
Solution: 
1. Check XAMPP is running (Apache + MySQL)
2. Verify database credentials in config.php
3. Ensure database exists: field_staff_system
```

### Issue: "Table doesn't exist"
```
Solution:
1. Re-import database_schema.sql
2. Check all tables were created:
   SELECT * FROM information_schema.tables 
   WHERE table_schema = 'field_staff_system';
```

### Issue: "Immutable trigger error"
```
Solution: This is EXPECTED!
It proves the security works.
The database is correctly rejecting updates.
```

### Issue: "GPS not working"
```
Solution:
1. HTTPS required for real GPS (use simulator)
2. Enable location services in browser
3. Use simulated locations in test mode
```

---

## PART 8: DEPLOYMENT CHECKLIST

Before final submission:

- [ ] Database created and populated
- [ ] All PHP files in htdocs folder
- [ ] config.php with correct credentials
- [ ] Test all 4 scenarios above
- [ ] Verify immutability (try to update record)
- [ ] Check audit logs are created
- [ ] Test Haversine calculation manually
- [ ] Document test results
- [ ] Prepare demo video (optional)
- [ ] Create README file
- [ ] Package for submission

---

## PART 9: DOCUMENTATION TEMPLATE

### README.md Example

```markdown
# Field Staff Activity Verification System

## Overview
Web-based system for verifying field staff activities using GPS coordinates 
and server-side timestamps.

## Features
- ✓ GPS-based location verification
- ✓ Server-side timestamp validation
- ✓ Immutable audit trail
- ✓ Anomaly detection
- ✓ Haversine distance calculation
- ✓ Role-based access control

## Tech Stack
- Frontend: HTML5, CSS3, JavaScript
- Backend: PHP 7.4+
- Database: MySQL 5.7+
- APIs: HTML5 Geolocation API

## Installation

1. Create database:
   ```sql
   mysql> source database_schema.sql;
   ```

2. Copy files to htdocs folder

3. Update config.php with credentials

4. Access at: http://localhost/field_staff_system/

## Testing
See TESTING.md for comprehensive test cases

## Security Features
- Immutable activity logs (database triggers)
- Server-side timestamp generation
- Impossible travel detection
- Role-based authorization
- SQL injection prevention (prepared statements)

## Author
[Your Name]
Student ID: 23/12848
Caleb University, 2026
```

---

## FINAL SUBMISSION PACKAGE

```
submission/
├── database_schema.sql          (Database structure)
├── config.php                   (Configuration)
├── api_submit_activity.php      (Verification API)
├── submit_activity.html         (Frontend)
├── admin_dashboard.php          (Dashboard)
├── database_backups/            (Export screenshots)
│   └── sample_data.sql
├── test_results/                (Documentation)
│   ├── test_case_1.md
│   ├── test_case_2.md
│   ├── test_case_3.md
│   └── test_case_4.md
├── screenshots/                 (UI screenshots)
│   ├── login.png
│   ├── submission.png
│   ├── verification.png
│   └── dashboard.png
├── README.md                    (Project overview)
├── TESTING.md                   (This file)
├── DEPLOYMENT.md                (Setup guide)
└── thesis_defense_slides.pdf    (Presentation)
```

---

## SUCCESS CRITERIA FOR THESIS

✓ System architecture documented  
✓ Database design normalized  
✓ Verification algorithm implemented  
✓ Frontend functional  
✓ Backend verification working  
✓ All test cases passed  
✓ Security features working  
✓ Deployment instructions clear  
✓ Code documented  
✓ Live demo successful  

---

**Good luck with your thesis defense!** 🎓

Any questions? Reference your thesis document Chapter 3 (Methodology) 
and the literature review for theoretical backing.
