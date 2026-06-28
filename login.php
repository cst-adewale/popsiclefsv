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
    $device_token = sanitizeInput($_POST['device_token'] ?? '');
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!empty($identifier) && !empty($password)) {
        try {
            $stmt = $conn->prepare("
                SELECT user_id, full_name, role, email, username, password_hash, is_active
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
                if (!empty($device_token)) {
                    bindDeviceToUser($user['user_id'], $device_token, $user_agent);
                }
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
<title>Sign in — Caleb FSV</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Outfit',sans-serif;background:#f8f9fa;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#1a1a2e}
.login-wrap{width:100%;max-width:390px;padding:16px}
.logo-row{display:flex;align-items:center;gap:12px;margin-bottom:36px;justify-content:center}
.logo-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;border:1px solid #dde1e7;overflow:hidden;background:#ffffff;flex-shrink:0}
.logo-icon img{width:100%;height:100%;object-fit:cover}
.logo-name{font-size:18px;font-weight:700;color:#1a1a2e;letter-spacing:-0.2px}
.logo-sub{font-size:11px;color:#8a95a3;font-weight:400}
.card{background:#ffffff;border:1px solid #dde1e7;border-radius:18px;padding:32px 28px;box-shadow:0 8px 30px rgba(0,0,0,0.05)}
.card-title{font-size:20px;font-weight:700;color:#1a1a2e;margin-bottom:4px}
.card-sub{font-size:13px;color:#8a95a3;margin-bottom:28px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.field label{font-size:11px;font-weight:600;color:#888888;text-transform:uppercase;letter-spacing:.4px}
.field input{padding:11px 13px;border:1px solid #dde1e7;border-radius:12px;font-size:14px;font-family:'Outfit',sans-serif;color:#1a1a2e;background:#ffffff;outline:none;transition:all .15s}
.field input:focus{border-color:#8B5CF6;box-shadow:0 0 0 3px rgba(139,92,246,0.15)}
.field input::placeholder{color:#b0b8c8}
.btn-submit{width:100%;padding:12px;background:#8B5CF6;color:#ffffff;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Outfit',sans-serif;transition:all .2s;margin-top:4px}
.btn-submit:hover{background:#7C3AED}
.error-box{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:11px 14px;font-size:13px;color:#f87171;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.error-box svg{flex-shrink:0;width:15px;height:15px}
.divider{border:none;border-top:1px solid #dde1e7;margin:20px 0}
.register-link{text-align:center;font-size:13px;color:#666666}
.register-link a{color:#8B5CF6;font-weight:600;text-decoration:none}
.register-link a:hover{text-decoration:underline}
.footer-note{text-align:center;font-size:11px;color:#8a95a3;margin-top:18px}
</style>

</head>
<body>
<div class="login-wrap">
  <div class="logo-row">
    <div class="logo-icon">
      <img src="icons/logo.jpg" alt="Caleb FSV Logo">
    </div>
    <div>
      <div class="logo-name">Caleb FSV</div>
      <div class="logo-sub">Caleb University · Field Verification</div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Sign in</div>
    <div class="card-sub">Use your staff username or email</div>

    <?php if ($error): ?>
    <div class="error-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
      <input type="hidden" name="device_token" id="device_token">
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

  <div class="footer-note">Caleb FSV · 2026</div>
</div>
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
</body>
</html>
