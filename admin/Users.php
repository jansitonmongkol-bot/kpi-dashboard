<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/Auth.php';

Auth::requireAdmin();

$message = '';
$msgType = 'success';

$action = $_POST['action'] ?? '';

// Toggle role
if ($action === 'toggle_role') {
    $uid     = (int)$_POST['user_id'];
    $newRole = $_POST['new_role'] === 'admin' ? 'admin' : 'user';
    // ป้องกันการ downgrade ตัวเอง
    if ($uid === (int)$_SESSION['user_id']) {
        $message = 'ไม่สามารถเปลี่ยนสิทธิ์ของตัวเองได้';
        $msgType = 'warning';
    } else {
        $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=?");
        $stmt->bind_param('si', $newRole, $uid);
        $stmt->execute();
        $message = "เปลี่ยนสิทธิ์เป็น $newRole สำเร็จ";
    }
}

// Toggle active status
if ($action === 'toggle_active') {
    $uid    = (int)$_POST['user_id'];
    $active = (int)$_POST['is_active'];
    if ($uid === (int)$_SESSION['user_id']) {
        $message = 'ไม่สามารถระงับบัญชีของตัวเองได้';
        $msgType = 'warning';
    } else {
        $conn->query("UPDATE users SET is_active=$active WHERE id=$uid");
        $message = $active ? 'เปิดใช้งานบัญชีสำเร็จ' : 'ระงับบัญชีสำเร็จ';
    }
}

// Delete user
if ($action === 'delete_user') {
    $uid = (int)$_POST['user_id'];
    if ($uid === (int)$_SESSION['user_id']) {
        $message = 'ไม่สามารถลบบัญชีของตัวเองได้';
        $msgType = 'warning';
    } else {
        $conn->query("DELETE FROM users WHERE id=$uid");
        $message = 'ลบผู้ใช้งานสำเร็จ';
    }
}

// Reset password
if ($action === 'reset_password') {
    $uid     = (int)$_POST['user_id'];
    $newPw   = trim($_POST['new_password']);
    if (strlen($newPw) < 8) {
        $message = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
        $msgType = 'warning';
    } else {
        $hash = password_hash($newPw, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si', $hash, $uid);
        $stmt->execute();
        $message = 'รีเซ็ตรหัสผ่านสำเร็จ';
    }
}

// Fetch all users
$users = [];
$res = $conn->query("SELECT id, username, email, fullname, role, is_active, last_login, created_at FROM users ORDER BY created_at DESC");
if ($res) { while ($row = $res->fetch_assoc()) { $users[] = $row; } }

$activeMenu = 'users';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้ใช้งาน — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<?php include '../includes/Navbar.php'; ?>

<div class="container-fluid px-4">

    <div class="page-header mb-4">
        <div>
            <h4 class="page-title"><i class="bi bi-people-fill me-2 text-warning"></i>จัดการผู้ใช้งาน</h4>
            <p class="page-subtitle">ควบคุมสิทธิ์การเข้าถึง เปลี่ยนบทบาท และจัดการบัญชีผู้ใช้</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-primary fs-6 px-3 py-2">
                <i class="bi bi-people me-1"></i><?= count($users) ?> บัญชี
            </span>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
        <i class="bi bi-<?= $msgType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="chart-card mb-5">
        <div class="chart-card__header">
            <h6 class="chart-card__title">รายชื่อผู้ใช้งานทั้งหมด</h6>
            <div class="input-group input-group-sm" style="width:220px;">
                <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="userSearch" class="form-control border-start-0" placeholder="ค้นหาผู้ใช้...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table kpi-table mb-0" id="userTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>ชื่อ / Username</th>
                        <th>Email</th>
                        <th class="text-center" style="width:100px;">บทบาท</th>
                        <th class="text-center" style="width:100px;">สถานะ</th>
                        <th class="text-center" style="width:150px;">เข้าใช้ล่าสุด</th>
                        <th class="text-center" style="width:140px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $i => $user):
                    $isSelf = $user['id'] === (int)$_SESSION['user_id'];
                ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="user-avatar-sm">
                                <?= mb_substr($user['fullname'], 0, 1) ?>
                            </span>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($user['fullname']) ?></div>
                                <div class="text-muted small">@<?= htmlspecialchars($user['username']) ?></div>
                            </div>
                            <?php if ($isSelf): ?>
                            <span class="badge bg-info text-dark small">คุณ</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($user['email']) ?></td>
                    <td class="text-center">
                        <?php if ($isSelf): ?>
                            <span class="badge bg-warning text-dark">Admin</span>
                        <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_role">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <input type="hidden" name="new_role" value="<?= $user['role'] === 'admin' ? 'user' : 'admin' ?>">
                            <button type="submit" class="badge border-0 <?= $user['role'] === 'admin' ? 'bg-warning text-dark' : 'bg-secondary' ?>"
                                    style="cursor:pointer;font-size:0.8rem;"
                                    title="คลิกเพื่อเปลี่ยนบทบาท">
                                <?= $user['role'] === 'admin' ? 'Admin' : 'User' ?>
                                <i class="bi bi-arrow-repeat ms-1"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($isSelf): ?>
                            <span class="badge bg-success">ใช้งาน</span>
                        <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= $user['is_active'] ? '0' : '1' ?>">
                            <button type="submit" class="badge border-0 <?= $user['is_active'] ? 'bg-success' : 'bg-danger' ?>"
                                    style="cursor:pointer;font-size:0.8rem;"
                                    title="คลิกเพื่อเปลี่ยนสถานะ">
                                <?= $user['is_active'] ? 'ใช้งาน' : 'ระงับ' ?>
                                <i class="bi bi-toggle-<?= $user['is_active'] ? 'on' : 'off' ?> ms-1"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td class="text-center text-muted small">
                        <?= $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : 'ยังไม่เคยเข้า' ?>
                    </td>
                    <td class="text-center">
                        <?php if (!$isSelf): ?>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill me-1"
                                onclick="openResetModal(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['fullname'])) ?>')"
                                title="รีเซ็ตรหัสผ่าน">
                            <i class="bi bi-key-fill"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger rounded-pill"
                                onclick="confirmDeleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['fullname'])) ?>')"
                                title="ลบ">
                            <i class="bi bi-trash3-fill"></i>
                        </button>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-key-fill me-2 text-warning"></i>รีเซ็ตรหัสผ่าน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3" id="resetUserName"></p>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="reset_uid">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">รหัสผ่านใหม่</label>
                        <input type="password" name="new_password" class="form-control"
                               placeholder="อย่างน้อย 8 ตัว" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100 rounded-pill fw-bold">
                        <i class="bi bi-key me-1"></i>รีเซ็ตรหัสผ่าน
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteUserForm">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="delete_uid">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openResetModal(uid, name) {
    document.getElementById('reset_uid').value = uid;
    document.getElementById('resetUserName').textContent = 'ผู้ใช้: ' + name;
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}
function confirmDeleteUser(uid, name) {
    if (confirm(`ยืนยันการลบบัญชี:\n"${name}"\n\nการกระทำนี้ไม่สามารถย้อนกลับได้`)) {
        document.getElementById('delete_uid').value = uid;
        document.getElementById('deleteUserForm').submit();
    }
}
document.getElementById('userSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#userTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>