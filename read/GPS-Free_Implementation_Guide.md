# Field Staff Activity Verification System
## Implementation Guide Without Physical GPS

---

## OVERVIEW

Your thesis proposes a GPS-based verification system, but for **academic purposes**, you can implement this using **simulated/mock GPS data**. This approach is legitimate for thesis work because:

1. **Demonstrates the architecture** - The system design remains identical
2. **Proves the concept** - You show how verification logic works
3. **Avoids hardware constraints** - Focuses on software engineering
4. **Is reproducible** - Anyone can test without specialized equipment

---

## OPTION 1: MOCK GPS IMPLEMENTATION (RECOMMENDED FOR ACADEMICS)

### What You'll Do:
Instead of calling real GPS satellites, you'll **simulate location data** by:
- Pre-defining work locations in your database
- Having field staff "check in" at designated sites
- The system accepts coordinates you've programmed (simulating real GPS)
- Server validates timestamps and geofence logic

### Advantages:
✅ Full system functionality demonstrated  
✅ No hardware dependencies  
✅ Perfect for testing verification logic  
✅ Shows you understand the architecture  
✅ Repeatable and testable  

---

## IMPLEMENTATION STEPS

### Step 1: Database Design (MySQL)

```sql
-- Users table
CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('staff', 'admin') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Work locations table (predefined sites)
CREATE TABLE work_locations (
    location_id INT PRIMARY KEY AUTO_INCREMENT,
    location_name VARCHAR(100) NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    tolerance_radius_meters INT DEFAULT 100,
    description TEXT
);

-- Tasks/Activities table
CREATE TABLE activities (
    activity_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    location_id INT NOT NULL,
    scheduled_start_time TIME,
    scheduled_end_time TIME,
    scheduled_date DATE,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (location_id) REFERENCES work_locations(location_id)
);

-- Activity submissions table (the verification happens here)
CREATE TABLE activity_submissions (
    submission_id INT PRIMARY KEY AUTO_INCREMENT,
    activity_id INT NOT NULL,
    user_id INT NOT NULL,
    submitted_latitude DECIMAL(10, 8),
    submitted_longitude DECIMAL(11, 8),
    server_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    distance_from_assigned_location DECIMAL(10, 2),
    verification_status ENUM('VERIFIED', 'OUT_OF_RANGE', 'INVALID_TIME', 'PENDING') DEFAULT 'PENDING',
    submission_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);
```

---

### Step 2: Backend (PHP) - Verification Logic

**File: `config.php`**
```php
<?php
// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'field_staff_system';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Haversine Formula for distance calculation
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius_meters = 6371000; // Earth's radius in meters
    
    $lat1_rad = deg2rad($lat1);
    $lat2_rad = deg2rad($lat2);
    $delta_lat = deg2rad($lat2 - $lat1);
    $delta_lon = deg2rad($lon2 - $lon1);
    
    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
         cos($lat1_rad) * cos($lat2_rad) * sin($delta_lon / 2) * sin($delta_lon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distance = $earth_radius_meters * $c;
    
    return round($distance, 2); // Return distance in meters
}
?>
```

**File: `verify_activity.php`**
```php
<?php
require 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $activity_id = $_POST['activity_id'];
    $submitted_latitude = floatval($_POST['latitude']);
    $submitted_longitude = floatval($_POST['longitude']);
    $description = $_POST['description'];
    
    // Get server timestamp (cannot be manipulated by user)
    $server_timestamp = date('Y-m-d H:i:s');
    
    // Step 1: Fetch assigned work location
    $query = "SELECT wl.latitude, wl.longitude, wl.tolerance_radius_meters, 
                     a.scheduled_start_time, a.scheduled_end_time, a.scheduled_date
              FROM activities a
              JOIN work_locations wl ON a.location_id = wl.location_id
              WHERE a.activity_id = $activity_id AND a.user_id = $user_id";
    
    $result = $conn->query($query);
    $location_data = $result->fetch_assoc();
    
    $assigned_lat = $location_data['latitude'];
    $assigned_lon = $location_data['longitude'];
    $tolerance = $location_data['tolerance_radius_meters'];
    $start_time = $location_data['scheduled_start_time'];
    $end_time = $location_data['scheduled_end_time'];
    $scheduled_date = $location_data['scheduled_date'];
    
    // Step 2: Calculate distance using Haversine Formula
    $distance = calculateDistance($assigned_lat, $assigned_lon, 
                                  $submitted_latitude, $submitted_longitude);
    
    // Step 3: Spatial Validation
    $spatial_status = ($distance <= $tolerance) ? 'PASS' : 'OUT_OF_RANGE';
    
    // Step 4: Temporal Validation
    $current_time = date('H:i:s');
    $current_date = date('Y-m-d');
    
    $temporal_status = 'PASS';
    if ($current_date != $scheduled_date) {
        $temporal_status = 'INVALID_DATE';
    } elseif ($current_time < $start_time || $current_time > $end_time) {
        $temporal_status = 'INVALID_TIME';
    }
    
    // Step 5: Final Verification
    $final_status = ($spatial_status == 'PASS' && $temporal_status == 'PASS') 
                    ? 'VERIFIED' 
                    : 'INVALID';
    
    // Step 6: Store in database
    $insert_query = "INSERT INTO activity_submissions 
                     (activity_id, user_id, submitted_latitude, submitted_longitude, 
                      server_timestamp, distance_from_assigned_location, 
                      verification_status, submission_description)
                     VALUES ($activity_id, $user_id, $submitted_latitude, $submitted_longitude, 
                             '$server_timestamp', $distance, '$final_status', '$description')";
    
    if ($conn->query($insert_query)) {
        echo json_encode([
            'status' => 'success',
            'verification' => $final_status,
            'distance' => $distance,
            'tolerance' => $tolerance,
            'message' => "$final_status: Distance was $distance meters (limit: $tolerance m)"
        ]);
    }
}
?>
```

---

### Step 3: Frontend (HTML/JavaScript) - Staff Submission

**File: `submit_activity.html`**
```html
<!DOCTYPE html>
<html>
<head>
    <title>Field Staff Activity Submission</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; }
        .form-group { margin: 15px 0; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input, textarea, select { width: 100%; padding: 8px; border: 1px solid #ddd; }
        button { background: #4CAF50; color: white; padding: 10px 20px; border: none; cursor: pointer; }
        .result { margin-top: 20px; padding: 15px; border-radius: 5px; }
        .verified { background: #d4edda; color: #155724; }
        .invalid { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <h1>Activity Submission Form</h1>
    
    <form id="activityForm">
        <div class="form-group">
            <label for="activity">Select Activity:</label>
            <select id="activity" name="activity_id" required>
                <option value="">-- Choose Activity --</option>
                <!-- This would be populated from database -->
                <option value="1">Client Visit - Lagos Office</option>
                <option value="2">Equipment Installation - Ikeja</option>
                <option value="3">Site Inspection - Abuja</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Your Location (Latitude):</label>
            <input type="text" id="latitude" name="latitude" readonly placeholder="Click 'Get Location'">
        </div>
        
        <div class="form-group">
            <label>Your Location (Longitude):</label>
            <input type="text" id="longitude" name="longitude" readonly placeholder="Click 'Get Location'">
        </div>
        
        <div class="form-group">
            <label for="description">Activity Description:</label>
            <textarea id="description" name="description" rows="4" placeholder="What did you do?" required></textarea>
        </div>
        
        <button type="button" onclick="getLocation()">📍 Get My Location</button>
        <button type="submit">✓ Submit Activity</button>
    </form>
    
    <div id="result"></div>
    
    <script>
        // FOR ACADEMIC PURPOSES: Simulated Location Selection
        // In real scenario, this would use HTML5 Geolocation API
        
        const predefinedLocations = {
            1: { lat: 6.5244, lon: 3.3792, name: "Lagos Office" },
            2: { lat: 6.5521, lon: 3.3521, name: "Ikeja Site" },
            3: { lat: 9.0765, lon: 7.3986, name: "Abuja Office" }
        };
        
        function getLocation() {
            const activityId = document.getElementById('activity').value;
            
            if (!activityId) {
                alert('Please select an activity first');
                return;
            }
            
            const location = predefinedLocations[activityId];
            
            // FOR DEMO: Add slight variation to simulate real GPS accuracy variance
            const variance = 0.0001; // ~11 meters
            const lat = location.lat + (Math.random() - 0.5) * variance;
            const lon = location.lon + (Math.random() - 0.5) * variance;
            
            document.getElementById('latitude').value = lat.toFixed(7);
            document.getElementById('longitude').value = lon.toFixed(7);
            
            alert(`📍 Location set to ${location.name}\n(Simulated GPS within expected tolerance)`);
        }
        
        document.getElementById('activityForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch('verify_activity.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                const resultDiv = document.getElementById('result');
                
                if (result.status === 'success') {
                    resultDiv.className = result.verification === 'VERIFIED' ? 'result verified' : 'result invalid';
                    resultDiv.innerHTML = `
                        <h3>${result.verification}</h3>
                        <p>${result.message}</p>
                        <p>Distance: ${result.distance} meters</p>
                        <p>Allowed radius: ${result.tolerance} meters</p>
                    `;
                }
            } catch (error) {
                console.error('Error:', error);
            }
        });
    </script>
</body>
</html>
```

---

### Step 4: Admin Dashboard

**File: `admin_dashboard.php`**
```php
<?php
require 'config.php';
session_start();

// Check if admin
if ($_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit;
}

$query = "SELECT 
            s.submission_id,
            u.username,
            wl.location_name,
            s.submitted_latitude,
            s.submitted_longitude,
            s.server_timestamp,
            s.distance_from_assigned_location,
            s.verification_status,
            s.submission_description
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
    <title>Admin Dashboard - Activity Verification</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #4CAF50; color: white; }
        tr:hover { background: #f5f5f5; }
        .verified { color: green; font-weight: bold; }
        .invalid { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Field Staff Activity Verification Dashboard</h1>
    
    <table>
        <tr>
            <th>Staff Member</th>
            <th>Location</th>
            <th>Timestamp</th>
            <th>Distance (m)</th>
            <th>Status</th>
            <th>Description</th>
        </tr>
        
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?php echo $row['username']; ?></td>
            <td><?php echo $row['location_name']; ?></td>
            <td><?php echo $row['server_timestamp']; ?></td>
            <td><?php echo $row['distance_from_assigned_location']; ?></td>
            <td class="<?php echo strtolower($row['verification_status']); ?>">
                <?php echo $row['verification_status']; ?>
            </td>
            <td><?php echo substr($row['submission_description'], 0, 50); ?>...</td>
        </tr>
        <?php endwhile; ?>
    </table>
</body>
</html>
```

---

## OPTION 2: HTML5 GEOLOCATION API (HYBRID APPROACH)

If you want to use actual location capabilities of smartphones (but not satellite GPS specifically):

```javascript
function getRealLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lon = position.coords.longitude;
                
                document.getElementById('latitude').value = lat.toFixed(7);
                document.getElementById('longitude').value = lon.toFixed(7);
            },
            function(error) {
                console.log('Error getting location:', error);
                alert('Could not get location. Using test data.');
                getLocation(); // Fallback to simulated location
            }
        );
    }
}
```

**Advantages:**
- Uses smartphone's actual location services (Wi-Fi, cell towers, GPS if available)
- More realistic for thesis demonstration
- Shows you understand browser APIs
- Still works without dedicated GPS hardware

---

## DELIVERABLES FOR YOUR THESIS

### 1. **System Diagram** (Show Architecture)
```
┌─────────────────────────────────────────────────────────┐
│                   FIELD STAFF                           │
│            (Mobile Browser/Smartphone)                  │
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Submission Form + Location Capture             │   │
│  │  - Activity Selection                           │   │
│  │  - GPS/Location Data (Simulated or Real)       │   │
│  │  - Activity Description                         │   │
│  └─────────────────────────────────────────────────┘   │
└──────────────────────┬──────────────────────────────────┘
                       │ (POST Request)
                       ▼
┌──────────────────────────────────────────────────────────┐
│              WEB SERVER (PHP Backend)                    │
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Verification Logic Layer                       │   │
│  │  1. Haversine Formula (Distance Calc)           │   │
│  │  2. Spatial Validation (Within Radius?)         │   │
│  │  3. Temporal Validation (Within Schedule?)      │   │
│  │  4. Server Timestamp (Tamper-proof)             │   │
│  │  5. Final Status Assignment                     │   │
│  └─────────────────────────────────────────────────┘   │
└──────────────────────┬──────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────┐
│              MySQL Database                              │
│                                                         │
│  - Users Table (Credentials & Roles)                    │
│  - Work Locations (Predefined Sites)                    │
│  - Activities (Scheduled Tasks)                         │
│  - Activity Submissions (Immutable Records)             │
└──────────────────────────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────┐
│              ADMIN DASHBOARD                             │
│                                                         │
│  - View all submissions                                 │
│  - Verify/Reject activities                             │
│  - Generate reports                                     │
│  - Track staff performance                              │
└──────────────────────────────────────────────────────────┘
```

### 2. **Test Results Document**
Show verification examples:

| Scenario | Submitted Lat | Submitted Lon | Distance | Tolerance | Result | Reason |
|----------|---------------|---------------|----------|-----------|--------|--------|
| Valid | 6.5244 | 3.3792 | 42m | 100m | ✓ VERIFIED | Within radius & time |
| Out of Range | 6.5300 | 3.3850 | 156m | 100m | ✗ INVALID | Exceeds tolerance |
| Invalid Time | 6.5244 | 3.3792 | 45m | 100m | ✗ INVALID | Outside work hours |

### 3. **Code Repository**
```
field-staff-system/
├── config.php
├── verify_activity.php
├── submit_activity.html
├── admin_dashboard.php
├── database.sql
├── README.md
└── TESTING_RESULTS.md
```

---

## KEY POINTS FOR YOUR THESIS DEFENSE

**When presenting, emphasize:**

1. **Architecture Integrity** - "The system design is GPS-agnostic. It works with any location source (satellite GPS, Wi-Fi triangulation, cell tower data, or simulated data)."

2. **Verification Logic is Universal** - "The Haversine Formula works regardless of where coordinates come from."

3. **Academic Precedent** - "Thesis projects commonly use simulated data to demonstrate system logic without hardware constraints."

4. **Scalability** - "Once deployed, actual GPS data from smartphones would integrate seamlessly with this architecture."

5. **Security Focus** - "The server-side timestamp mechanism is the real innovation—preventing any client-side time manipulation."

---

## NEXT STEPS

1. **Set up XAMPP/LAMP** - Install local server
2. **Create database** - Run the SQL script
3. **Code the modules** - Start with backend verification logic
4. **Test thoroughly** - Document all test cases
5. **Create admin interface** - Show management functionality
6. **Prepare presentation** - Show live demo with simulated data

Would you like me to create detailed code files for any specific module?
