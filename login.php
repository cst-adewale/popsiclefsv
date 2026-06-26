<?php
/**
 * School Attendance Verification System
 * login.php - Secure authentication form
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? sanitizeInput($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (!empty($username) && !empty($password)) {
        // Hash password using SHA-256 to match the seed data
        $hashed_password = hash('sha256', $password);
        
        // PDO prepared statement (works with Supabase PostgreSQL)
        $stmt = $conn->prepare("SELECT user_id, username, role, full_name, is_active FROM users WHERE username = ? AND password = ?");
        $stmt->execute([$username, $hashed_password]);
        $user = $stmt->fetch();
        
        if ($user) {
            if ($user['is_active']) {
                // Set session details
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                
                // Log authentication event
                logAuditTrail($user['user_id'], 'USER_LOGIN', 'users', $user['user_id']);
                
                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header('Location: admin_dashboard.php');
                } else {
                    header('Location: lecturer_app.php');
                }
                exit;
            } else {
                $error_message = 'This account has been deactivated. Please contact ICT admin.';
            }
        } else {
            $error_message = 'Invalid username or password.';
        }
    } else {
        $error_message = 'Please fill in all credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Attendance System - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #3a7bd5 0%, #3a6073 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 420px;
            padding: 40px 30px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-area {
            margin-bottom: 30px;
        }

        .logo-icon {
            font-size: 48px;
            margin-bottom: 10px;
            display: inline-block;
        }

        .logo-area h1 {
            font-size: 24px;
            color: #2c3e50;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .logo-area p {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 5px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #34495e;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #dcdde1;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background-color: #f8f9fa;
        }

        input:focus {
            outline: none;
            border-color: #3a7bd5;
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(58, 123, 213, 0.15);
        }

        .error-message {
            background-color: #fab1a0;
            border-left: 4px solid #d63031;
            color: #2d3436;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            text-align: left;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #3a7bd5 0%, #00d2ff 100%);
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(58, 123, 213, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(58, 123, 213, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .credentials-hint {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #f1f2f6;
            font-size: 11px;
            color: #7f8c8d;
            text-align: left;
            line-height: 1.5;
        }

        .credentials-hint strong {
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-area">
            <span class="logo-icon">🏫</span>
            <h1>Attendance System</h1>
            <p>Verification Portal</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                ⚠️ <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="e.g. lecturer1" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-login">SIGN IN</button>
        </form>

        <div style="margin-top: 20px; font-size: 14px; color: #7f8c8d;">
            New lecturer? <a href="register.php" style="color: #3a7bd5; text-decoration: none; font-weight: 600;">Register here</a>
        </div>

        <div class="credentials-hint">
            💡 <strong>Demo Credentials:</strong><br>
            • Lecturer: <code>lecturer1</code> / <code>lecturer123</code><br>
            • Admin: <code>admin</code> / <code>admin123</code>
        </div>
    </div>
</body>
</html>
