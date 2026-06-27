<?php
/**
 * login.php - Redesigned login screen
 */
require 'config.php';
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin_dashboard.php' : 'lecturer_app.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = sanitizeInput($_POST['identifier'] ?? ($_POST['email'] ?? $_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    if (!empty($identifier) && !empty($password)) {
        try {
            $stmt = $conn->prepare("
                SELECT user_id, full_name, role, email, username, password_hash, password, is_active
                FROM users
                WHERE email = ? OR username = ?
                LIMIT 1
            ");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();
            $stored_password = $user ? getStoredUserPassword($user) : null;
            $password_is_valid = false;
            if ($stored_password !== null) {
                $password_is_valid = password_verify($password, $stored_password) || hash('sha256', $password) === $stored_password;
            }

            if ($user && $user['is_active'] && $password_is_valid) {
                $_SESSION['user_id']   = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role'];
                header('Location: ' . ($user['role'] === 'admin' ? 'admin_dashboard.php' : 'lecturer_app.php'));
                exit;
            } else {
                $error = 'Invalid username/email or password.';
            }
        } catch (PDOException $e) {
            $error = 'A system error occurred. Please try again.';
        }
    } else {
        $error = 'Please enter your username/email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — FSV Caleb</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#F7F8FA;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#1A1A2E}
.login-wrap{width:100%;max-width:400px;padding:20px}
.logo-row{display:flex;align-items:center;gap:10px;margin-bottom:36px;justify-content:center}
.logo-icon{width:36px;height:36px;background:#2D6A4F;border-radius:9px;display:flex;align-items:center;justify-content:center}
.logo-icon svg{width:20px;height:20px}
.logo-name{font-size:17px;font-weight:700;color:#1A1A2E}
.logo-sub{font-size:11px;color:#8B93A1;font-weight:400}
.card{background:#FFFFFF;border:1px solid #E5E8EE;border-radius:14px;padding:32px 28px}
.card-title{font-size:20px;font-weight:700;color:#1A1A2E;margin-bottom:4px}
.card-sub{font-size:13px;color:#8B93A1;margin-bottom:28px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.field label{font-size:11px;font-weight:600;color:#8B93A1;text-transform:uppercase;letter-spacing:.4px}
.field input{padding:10px 13px;border:1px solid #E5E8EE;border-radius:8px;font-size:14px;font-family:'Inter',sans-serif;color:#1A1A2E;background:#FFFFFF;outline:none;transition:border-color .15s}
.field input:focus{border-color:#52A878}
.field input::placeholder{color:#B0B8C8}
.btn-submit{width:100%;padding:12px;background:#2D6A4F;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:background .15s;margin-top:8px}
.btn-submit:hover{background:#3B7A57}
.error-box{background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:11px 14px;font-size:13px;color:#DC2626;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.error-box svg{flex-shrink:0;width:15px;height:15px}
.divider{border:none;border-top:1px solid #F0F2F5;margin:20px 0}
.register-link{text-align:center;font-size:13px;color:#8B93A1}
.register-link a{color:#2D6A4F;font-weight:600;text-decoration:none}
.register-link a:hover{text-decoration:underline}
.footer-note{text-align:center;font-size:11px;color:#B0B8C8;margin-top:24px}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="logo-row">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
    </div>
    <div>
      <div class="logo-name">Popsicle FSV</div>
      <div class="logo-sub">Caleb University · Field Verification</div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Sign in</div>
    <div class="card-sub">Enter your staff credentials to continue</div>

    <?php if ($error): ?>
    <div class="error-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
      <div class="field">
        <label>Username or email</label>
        <input type="text" name="identifier" placeholder="jdoe or you@caleb.edu.ng" value="<?php echo htmlspecialchars($_POST['identifier'] ?? ($_POST['email'] ?? $_POST['username'] ?? '')); ?>" required autofocus>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn-submit">Sign in</button>
    </form>

    <hr class="divider">
    <div class="register-link">
      No account yet? <a href="register.php">Request access</a>
    </div>
  </div>

  <div class="footer-note">Caleb University Attendance Verification System · 2026</div>
</div>
</body>
</html>
