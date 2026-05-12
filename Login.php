<?php
include 'classes/Database.php';
include 'classes/Auth.php';

createUsersTableIfNotExists($conn);

// ถ้า login แล้วให้ redirect
define('BASE_PATH', '');
if (Auth::check()) {
    header('Location: ' . (Auth::isAdmin() ? authUrl('admin/index.php') : authUrl('index.php')));
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $auth   = new Auth($conn);
        $result = $auth->login($username, $password);

        if ($result['success']) {
            $redirect = $_GET['redirect'] ?? '';
            if ($result['role'] === 'admin') {
                header('Location: ' . authUrl('admin/index.php'));
            } else {
                header('Location: ' . ($redirect ?: authUrl('index.php')));
            }
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — PHO KPI Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --nav-from: #0f766e;
            --nav-to:   #065f46;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #0f766e 0%, #065f46 40%, #1e3a5f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-wrapper {
            width: 100%;
            max-width: 440px;
            padding: 1.5rem;
        }
        .auth-logo {
            text-align: center;
            margin-bottom: 2rem;
            color: white;
        }
        .auth-logo i { font-size: 3rem; opacity: 0.9; }
        .auth-logo h1 { font-size: 1.5rem; font-weight: 700; margin: 0.5rem 0 0; }
        .auth-logo p  { font-size: 0.85rem; opacity: 0.75; margin: 0; }
        .auth-card {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .auth-card h2 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        .auth-card .subtitle {
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 1.75rem;
        }
        .form-label { font-weight: 600; font-size: 0.875rem; color: #374151; }
        .form-control {
            border-radius: 10px;
            padding: 0.65rem 1rem;
            border: 1.5px solid #e2e8f0;
            font-size: 0.95rem;
            font-family: 'Sarabun', sans-serif;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13,148,136,0.15);
        }
        .input-group .form-control { border-right: none; }
        .input-group .btn-toggle-pw {
            border: 1.5px solid #e2e8f0;
            border-left: none;
            border-radius: 0 10px 10px 0;
            background: white;
            color: #94a3b8;
            transition: color 0.2s;
        }
        .input-group .btn-toggle-pw:hover { color: #0d9488; }
        .btn-login {
            background: linear-gradient(135deg, #0d9488, #0f766e);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            padding: 0.7rem;
            width: 100%;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-login:hover  { opacity: 0.92; color: white; transform: translateY(-1px); }
        .btn-login:active { transform: translateY(0); }
        .auth-footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.875rem;
            color: #64748b;
        }
        .auth-footer a { color: #0d9488; font-weight: 600; text-decoration: none; }
        .auth-footer a:hover { text-decoration: underline; }
        .alert { border-radius: 10px; font-size: 0.875rem; }
        .default-creds {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            color: #166534;
            margin-bottom: 1.25rem;
        }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-logo">
        <i class="bi bi-heart-pulse-fill"></i>
        <h1>PHO KPI Dashboard</h1>
        <p>ระบบติดตามตัวชี้วัดสุขภาพ ปีงบประมาณ 2568</p>
    </div>

    <div class="auth-card">
        <h2>เข้าสู่ระบบ</h2>
        <p class="subtitle">กรุณาลงชื่อเข้าใช้เพื่อดู Dashboard</p>

        <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill"></i>
            <span>สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ</span>
        </div>
        <?php endif; ?>

        <div class="default-creds">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Admin เริ่มต้น:</strong> username <code>admin</code> / password <code>Admin@1234</code>
        </div>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">ชื่อผู้ใช้</label>
                <input type="text" name="username" class="form-control"
                       placeholder="กรอกชื่อผู้ใช้"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label">รหัสผ่าน</label>
                <div class="input-group">
                    <input type="password" name="password" id="passwordInput"
                           class="form-control" placeholder="กรอกรหัสผ่าน"
                           autocomplete="current-password" required>
                    <button type="button" class="btn-toggle-pw" onclick="togglePw()">
                        <i class="bi bi-eye" id="pwIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right me-2"></i>เข้าสู่ระบบ
            </button>
        </form>

        <div class="auth-footer">
            ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a>
        </div>
    </div>
</div>

<script>
function togglePw() {
    const input = document.getElementById('passwordInput');
    const icon  = document.getElementById('pwIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>