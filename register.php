<?php
/**
 * School Attendance Verification System
 * register.php - Lecturer registration page
 */

require 'config.php';
session_start();

// Redirect already logged-in users
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: lecturer_app.php');
    }
    exit;
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? sanitizeInput($_POST['username']) : '';
    $email = isset($_POST['email']) ? sanitizeInput($_POST['email']) : '';
    $full_name = isset($_POST['full_name']) ? sanitizeInput($_POST['full_name']) : '';
    $phone = isset($_POST['phone']) ? sanitizeInput($_POST['phone']) : '';
    $faculty = isset($_POST['faculty']) ? sanitizeInput($_POST['faculty']) : '';
    $department = isset($_POST['department']) ? sanitizeInput($_POST['department']) : '';
    $lecturer_number = isset($_POST['lecturer_number']) ? sanitizeInput($_POST['lecturer_number']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $device_token = sanitizeInput($_POST['device_token'] ?? '');
    
    // Optional profile picture processing
    $profile_pic = null;
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['profile_pic']['tmp_name'];
        $file_size = $_FILES['profile_pic']['size'];
        // Limit to 2MB to prevent huge db entries
        if ($file_size > 2 * 1024 * 1024) {
            $error_message = 'Profile picture must be smaller than 2MB.';
        } else {
            $file_type = $_FILES['profile_pic']['type'];
            $data = file_get_contents($file_tmp);
            $profile_pic = 'data:' . $file_type . ';base64,' . base64_encode($data);
        }
    }
    
    if (empty($error_message)) {
        if (!empty($username) && !empty($email) && !empty($full_name) && !empty($password) && !empty($confirm_password) && !empty($department) && !empty($faculty) && !empty($lecturer_number)) {
            if ($password !== $confirm_password) {
                $error_message = 'Passwords do not match.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = 'Please enter a valid email address.';
            } else {
                try {
                    // Check if username already exists
                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $error_message = 'Username is already taken.';
                    } else {
                        // Check if email already exists
                        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            $error_message = 'Email is already registered.';
                        } else {
                            // Hash password using SHA-256 for backward compatibility with seed data
                            $hashed_password = hash('sha256', $password);
                            $password_column = getUserPasswordColumn();

                            // Insert new lecturer using whichever password column exists in the database
                            if ($password_column === 'password_hash') {
                                $stmt = $conn->prepare("
                                    INSERT INTO users (username, password_hash, email, full_name, phone, role, faculty, department, lecturer_number, profile_pic, is_active)
                                    VALUES (?, ?, ?, ?, ?, 'lecturer', ?, ?, ?, ?, TRUE)
                                ");
                            } else {
                                $stmt = $conn->prepare("
                                    INSERT INTO users (username, password, email, full_name, phone, role, faculty, department, lecturer_number, profile_pic, is_active)
                                    VALUES (?, ?, ?, ?, ?, 'lecturer', ?, ?, ?, ?, TRUE)
                                ");
                            }
                            $stmt->execute([$username, $hashed_password, $email, $full_name, $phone, $faculty, $department, $lecturer_number, $profile_pic]);

                            if ($stmt->rowCount() < 1) {
                                throw new PDOException('Registration did not create a database row.');
                            }
                            
                            // Get the newly created user ID
                            $new_user_id = $conn->lastInsertId();
                            if (!$new_user_id) {
                                // PostgreSQL fallback if lastInsertId is empty
                                $stmt_id = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                                $stmt_id->execute([$username]);
                                $user_row = $stmt_id->fetch();
                                $new_user_id = $user_row['user_id'] ?? null;
                            }
                            
                            // Log audit trail
                            if ($new_user_id) {
                                logAuditTrail($new_user_id, 'USER_SIGNUP', 'users', $new_user_id);
                                if (!empty($device_token)) {
                                    bindDeviceToUser($new_user_id, $device_token, $_SERVER['HTTP_USER_AGENT'] ?? '');
                                }
                                createNotification($new_user_id, 'account', 'Account created', 'Your lecturer account has been created successfully.', 'users', $new_user_id);
                            }
                            
                            $success_message = 'Registration successful! You can now log in.';
                        }
                    }
                } catch (PDOException $e) {
                    $error_message = 'Database error: ' . $e->getMessage();
                }
            }
        } else {
            $error_message = 'Please fill in all required fields.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Caleb FSV</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background: #f8f9fa;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 16px;
            color: #1a1a2e;
        }

        .register-card {
            background: #ffffff;
            border: 1px solid #dde1e7;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 440px;
            padding: 32px 28px;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 24px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dde1e7;
            overflow: hidden;
            background: #ffffff;
            margin-bottom: 12px;
        }

        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logo-area h1 {
            font-size: 20px;
            color: #1a1a2e;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .logo-area p {
            color: #8a95a3;
            font-size: 12px;
            margin-top: 4px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        @media (max-width: 480px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }

        .form-group {
            margin-bottom: 12px;
            text-align: left;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        @media (max-width: 480px) {
            .form-group.full-width {
                grid-column: span 1;
            }
        }

        label {
            display: block;
            color: #888888;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        input, select {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #dde1e7;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.15s;
            background-color: #ffffff;
            color: #1a1a2e;
            outline: none;
        }

        input:focus, select:focus {
            border-color: #8B5CF6;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.15);
        }

        .error-message {
            background-color: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.3);
            color: #ef4444;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 13px;
            text-align: left;
            margin-bottom: 18px;
            font-weight: 500;
        }

        .success-message {
            background-color: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.3);
            color: #10b981;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 13px;
            text-align: left;
            margin-bottom: 18px;
            font-weight: 500;
        }

        .btn-register {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 12px;
            background: #8B5CF6;
            color: white;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 4px;
        }

        .btn-register:hover {
            background: #7C3AED;
        }

        .login-link {
            margin-top: 20px;
            font-size: 13px;
            color: #666666;
            border-top: 1px solid #dde1e7;
            padding-top: 18px;
        }

        .login-link a {
            color: #8B5CF6;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="logo-area">
            <div class="logo-icon">
                <img src="icons/logo.jpg" alt="Caleb FSV Logo">
            </div>
            <h1>Lecturer Portal</h1>
            <p>Create your attendance verification account</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                ⚠️ <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="success-message">
                ✓ <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="device_token" id="device_token">
            <div class="form-grid">
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" placeholder="e.g. jdoe" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" placeholder="e.g. user@school.edu.ng" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="full_name">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" placeholder="e.g. Dr. John Doe" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="e.g. 08012345678" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="lecturer_number">Lecturer Number *</label>
                    <input type="text" id="lecturer_number" name="lecturer_number" placeholder="e.g. CUL/2026/0123" required value="<?php echo isset($_POST['lecturer_number']) ? htmlspecialchars($_POST['lecturer_number']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="profile_pic">Profile Picture (Optional)</label>
                    <input type="file" id="profile_pic" name="profile_pic" accept="image/*">
                </div>

                <div class="form-group">
                    <label for="faculty">Faculty / College *</label>
                    <select id="faculty" name="faculty" required onchange="updateDepartments()">
                        <option value="" disabled selected>-- Select Faculty --</option>
                        <option value="COCIM" <?php echo (isset($_POST['faculty']) && $_POST['faculty'] === 'COCIM') ? 'selected' : ''; ?>>College of Computing & Info Management (COCIM)</option>
                        <option value="COPAS" <?php echo (isset($_POST['faculty']) && $_POST['faculty'] === 'COPAS') ? 'selected' : ''; ?>>College of Pure & Applied Sciences (COPAS)</option>
                        <option value="CASMAS" <?php echo (isset($_POST['faculty']) && $_POST['faculty'] === 'CASMAS') ? 'selected' : ''; ?>>College of Arts, Social & Mgmt Sciences (CASMAS)</option>
                        <option value="COLENSMA" <?php echo (isset($_POST['faculty']) && $_POST['faculty'] === 'COLENSMA') ? 'selected' : ''; ?>>College of Environmental Sciences (COLENSMA)</option>
                        <option value="COLAW" <?php echo (isset($_POST['faculty']) && $_POST['faculty'] === 'COLAW') ? 'selected' : ''; ?>>College of Law (COLAW)</option>
                        <option value="COLED" <?php echo (isset($_POST['faculty']) && $_POST['faculty'] === 'COLED') ? 'selected' : ''; ?>>College of Education (COLED)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="department">Department *</label>
                    <select id="department" name="department" required>
                        <option value="" disabled selected>-- Select Faculty First --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="btn-register">REGISTER ACCOUNT</button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Sign In here</a>
        </div>
    </div>

    <script>
        const facultyDeps = {
            "COCIM": ["Computer Science", "Cybersecurity", "Software Engineering", "Information Systems"],
            "COPAS": ["Microbiology", "Industrial Chemistry", "Biochemistry", "Physics with Electronics", "Mathematics", "Statistics"],
            "CASMAS": ["Mass Communication", "Accounting", "Business Administration", "Economics", "Criminology and Security Studies"],
            "COLENSMA": ["Architecture", "Building", "Quantity Surveying", "Estate Management"],
            "COLAW": ["Law"],
            "COLED": ["Educational Management", "Guidance & Counseling"]
        };

        function updateDepartments() {
            const facultySel = document.getElementById('faculty');
            const deptSel = document.getElementById('department');
            const selectedFaculty = facultySel.value;

            // Clear previous options
            deptSel.innerHTML = '<option value="" disabled selected>-- Select Department --</option>';

            if (selectedFaculty && facultyDeps[selectedFaculty]) {
                facultyDeps[selectedFaculty].forEach(dept => {
                    const opt = document.createElement('option');
                    opt.value = dept;
                    opt.textContent = dept;
                    if (dept === "<?php echo isset($_POST['department']) ? $_POST['department'] : ''; ?>") {
                        opt.selected = true;
                    }
                    deptSel.appendChild(opt);
                });
            }
        }

        // Trigger on load if validation failed and values were posted back
        window.addEventListener('load', () => {
            if (document.getElementById('faculty').value) {
                updateDepartments();
            }
        });
    </script>
</body>
<script>
(function() {
    const key = 'caleb_fsv_device';
    let token = localStorage.getItem(key);
    if (!token) {
        token = (crypto && crypto.randomUUID) ? crypto.randomUUID() : 'dev-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        localStorage.setItem(key, token);
    }
    document.cookie = key + '=' + encodeURIComponent(token) + '; path=/; max-age=31536000; samesite=lax';
    const input = document.getElementById('device_token');
    if (input) input.value = token;
})();
</script>
</html>
