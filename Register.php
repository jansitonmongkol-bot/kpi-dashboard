<?php
include 'classes/Database.php';
include 'classes/Auth.php';

createUsersTableIfNotExists($conn);

define('BASE_PATH', '');
if (Auth::check()) {
    header('Location: ' . authUrl('index.php'));
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($email) || empty($fullname) || empty($password)) {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'รูปแบบ Email ไม่ถูกต้อง';
    } elseif (strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านไม่ตรงกัน';
    } else {
        $auth   = new Auth($conn);
        $result = $auth->register($username, $email, $password, $fullname);

        if ($result['success']) {
            header('Location: ' . authUrl('login.php') . '?registered=1');
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
    <title>สมัครสมาชิก — PHO KPI Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #0f766e 0%, #065f46 40%, #1e3a5f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-wrapper { width: 100%; max-width: 480px; padding: 1.5rem; }
        .auth-logo { text-align: center; margin-bottom: 1.5rem; color: white; }
        .auth-logo i  { font-size: 2.5rem; opacity: 0.9; }
        .auth-logo h1 { font-size: 1.4rem; font-weight: 700; margin: 0.4rem 0 0; }
        .auth-logo p  { font-size: 0.82rem; opacity: 0.75; margin: 0; }
        .auth-card {
            background: white;
            border-radius: 20px;
            padding: 2.25rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .auth-card h2 { font-size: 1.3rem; font-weight: 700; color: #1e293b; margin-bottom: 0.2rem; }
        .auth-card .subtitle { color: #64748b; font-size: 0.875rem; margin-bottom: 1.5rem; }
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
        .btn-register {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            padding: 0.7rem;
            width: 100%;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-register:hover  { opacity: 0.9; color: white; transform: translateY(-1px); }
        .auth-footer { text-align: center; margin-top: 1.25rem; font-size: 0.875rem; color: #64748b; }
        .auth-footer a { color: #0d9488; font-weight: 600; text-decoration: none; }
        .alert { border-radius: 10px; font-size: 0.875rem; }
        .role-badge {
            background: #f0fdf4;
            border: 1px dashed #86efac;
            border-radius: 10px;
            padding: 0.6rem 1rem;
            font-size: 0.8rem;
            color: #166534;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .pw-strength { height: 4px; border-radius: 2px; margin-top: 6px; transition: width 0.3s, background 0.3s; }
        .strength-label { font-size: 0.75rem; margin-top: 3px; }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-logo">
        <i class="bi bi-heart-pulse-fill"></i>
        <h1>PHO KPI Dashboard</h1>
        <p>สมัครสมาชิกเพื่อเข้าใช้งานระบบ</p>
    </div>

    <div class="auth-card">
        <h2>สมัครสมาชิก</h2>
        <p class="subtitle">สร้างบัญชีผู้ใช้งานใหม่</p>

        <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <div class="role-badge">
            <i class="bi bi-person-check-fill"></i>
            <span>บัญชีใหม่จะได้รับสิทธิ์ <strong>User</strong> — Admin สามารถยกระดับสิทธิ์ได้ในภายหลัง</span>
        </div>

        <form method="POST" id="regForm">
            <div class="mb-3">
                <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                <input type="text" name="fullname" class="form-control"
                       placeholder="เช่น นายสมชาย ใจดี"
                       value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">ชื่อผู้ใช้ (Username) <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control"
                       placeholder="ตัวอักษรภาษาอังกฤษและตัวเลข"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       pattern="[a-zA-Z0-9_]{3,50}" title="ภาษาอังกฤษ ตัวเลข หรือ _ อย่างน้อย 3 ตัว"
                       required>
            </div>
            <div class="mb-3">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control"
                       placeholder="example@pho.go.th"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">รหัสผ่าน <span class="text-danger">*</span></label>
                <input type="password" name="password" id="pw1" class="form-control"
                       placeholder="อย่างน้อย 8 ตัวอักษร" minlength="8"
                       oninput="checkStrength(this.value)" required>
                <div id="pwStrengthBar" class="pw-strength" style="width:0;background:#e2e8f0;"></div>
                <div id="pwStrengthLabel" class="strength-label text-muted"></div>
            </div>
            <div class="mb-4">
                <label class="form-label">ยืนยันรหัสผ่าน <span class="text-danger">*</span></label>
                <input type="password" name="confirm_password" id="pw2" class="form-control"
                       placeholder="กรอกรหัสผ่านอีกครั้ง" required>
                <div id="pwMatchMsg" class="strength-label"></div>
            </div>
            <button type="submit" class="btn-register">
                <i class="bi bi-person-plus-fill me-2"></i>สมัครสมาชิก
            </button>
        </form>

        <div class="auth-footer">
            มีบัญชีอยู่แล้ว? <a href="login.php">เข้าสู่ระบบ</a>
        </div>
    </div>
</div>
<script>
function checkStrength(val) {
    const bar   = document.getElementById('pwStrengthBar');
    const label = document.getElementById('pwStrengthLabel');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { w: '25%', bg: '#ef4444', text: 'อ่อนมาก',    cls: 'text-danger'  },
        { w: '50%', bg: '#f97316', text: 'อ่อน',        cls: 'text-warning' },
        { w: '75%', bg: '#eab308', text: 'ปานกลาง',     cls: 'text-warning' },
        { w: '100%',bg: '#22c55e', text: 'แข็งแรง',     cls: 'text-success' },
    ];
    const l = levels[Math.max(0, score - 1)] || levels[0];
    bar.style.width      = l.w;
    bar.style.background = l.bg;
    label.textContent    = val.length ? 'ความปลอดภัย: ' + l.text : '';
    label.className      = 'strength-label ' + l.cls;
}

document.getElementById('pw2').addEventListener('input', function() {
    const msg = document.getElementById('pwMatchMsg');
    if (this.value === document.getElementById('pw1').value) {
        msg.textContent = '✓ รหัสผ่านตรงกัน';
        msg.className = 'strength-label text-success';
    } else {
        msg.textContent = '✗ รหัสผ่านไม่ตรงกัน';
        msg.className = 'strength-label text-danger';
    }
});
</script>
</body>
</html>