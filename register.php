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
            background: #f7f8fa;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 16px;
            color: #1a1a2e;
        }

        .register-card {
            background: #fff;
            border: 1px solid #e5e8ee;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(26, 26, 46, 0.04);
            width: 100%;
            max-width: 420px;
            padding: 24px 20px;
            text-align: center;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-area {
            margin-bottom: 20px;
        }

        .logo-icon {
            font-size: 40px;
            margin-bottom: 5px;
            display: inline-block;
        }

        .logo-area h1 {
            font-size: 22px;
            color: #1a1a2e;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .logo-area p {
            color: #8b93a1;
            font-size: 13px;
            margin-top: 5px;
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
            margin-bottom: 10px;
            text-align: left;
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
            margin-bottom: 6px;
            color: #34495e;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input, select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #dcdde1;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.2s;
            background-color: #f8f9fb;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #52a878;
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(82, 168, 120, 0.12);
        }

        .error-message {
            background-color: #fab1a0;
            border-left: 4px solid #d63031;
            color: #2d3436;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            text-align: left;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .success-message {
            background-color: #55efc4;
            border-left: 4px solid #00b894;
            color: #2d3436;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            text-align: left;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .btn-register {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: #214f3b;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 15px rgba(33, 79, 59, 0.18);
            margin-top: 6px;
        }

        .btn-register:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(33, 79, 59, 0.22);
        }

        .btn-register:active {
            transform: translateY(0);
        }

        .login-link {
            margin-top: 20px;
            font-size: 13px;
            color: #8b93a1;
        }

        .login-link a {
            color: #214f3b;
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
            <span class="logo-icon">🏫</span>
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
</html>
