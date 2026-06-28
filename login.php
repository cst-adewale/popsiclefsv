<?php
/**
 * login.php - Flat, minimalistic login screen
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#FAFAF9;
  --card:#FFFFFF;
  --border:#E4E4E2;
  --border-strong:#D6D6D3;
  --text:#16161A;
  --text-muted:#7A7A82;
  --text-faint:#AFAFB2;
  --accent:#1F7A4F;
  --accent-hover:#19663F;
  --accent-soft:#EAF7F0;
  --error:#C22E48;
  --error-soft:#FBEEEF;
  --radius:8px;
}
*{margin:0;padding:0;box-sizing:border-box}
body{
  font-family:'Inter',sans-serif;
  background:var(--bg);
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  color:var(--text);
}
.login-wrap{width:100%;max-width:380px;padding:16px}
.logo-row{display:flex;align-items:center;gap:10px;margin-bottom:32px}
.logo-icon{
  width:32px;height:32px;
  background:var(--accent);
  border-radius:6px;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
}
.logo-icon svg{width:18px;height:18px}
.logo-name{font-size:15px;font-weight:600;color:var(--text);letter-spacing:-0.1px}
.logo-sub{font-size:11px;color:var(--text-faint);font-weight:400;margin-top:1px}
.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:28px 26px;
}
.card-title{font-size:17px;font-weight:600;color:var(--text);margin-bottom:3px}
.card-sub{font-size:13px;color:var(--text-muted);margin-bottom:24px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.field label{
  font-size:11px;font-weight:500;color:var(--text-muted);
  text-transform:uppercase;letter-spacing:.3px;
}
.field input{
  padding:10px 12px;
  border:1px solid var(--border-strong);
  border-radius:6px;
  font-size:14px;
  font-family:'Inter',sans-serif;
  color:var(--text);
  background:var(--card);
  outline:none;
  transition:border-color .12s;
}
.field input:focus{border-color:var(--accent)}
.field input::placeholder{color:var(--text-faint)}
.btn-submit{
  width:100%;padding:11px;
  background:var(--accent);
  color:#fff;
  border:none;
  border-radius:6px;
  font-size:14px;font-weight:600;
  cursor:pointer;
  font-family:'Inter',sans-serif;
  transition:background-color .12s;
  margin-top:6px;
}
.btn-submit:hover{background:var(--accent-hover)}
.error-box{
  background:var(--error-soft);
  border:1px solid var(--error);
  border-radius:6px;
  padding:10px 13px;
  font-size:13px;color:var(--error);
  margin-bottom:16px;
  display:flex;align-items:center;gap:8px;
}
.error-box svg{flex-shrink:0;width:14px;height:14px}
.divider{border:none;border-top:1px solid var(--border);margin:18px 0}
.register-link{text-align:center;font-size:13px;color:var(--text-muted)}
.register-link a{color:var(--accent);font-weight:600;text-decoration:none}
.register-link a:hover{text-decoration:underline}
.footer-note{text-align:center;font-size:11px;color:var(--text-faint);margin-top:16px}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="logo-row">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
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
