<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/KPI.php';
include '../classes/Auth.php';

Auth::requireLogin();
if (Auth::isAdmin()) {
    header('Location: ' . authUrl('admin/index.php'));
    exit;
}

$userId  = $_SESSION['user_id'];
$message = '';
$msgType = 'success';

// ---- สร้างตารางถ้ายังไม่มี ----
$conn->query("
    CREATE TABLE IF NOT EXISTS kpi_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        kpi_id INT NOT NULL,
        request_note TEXT,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        admin_note TEXT,
        reviewed_by INT,
        reviewed_at DATETIME,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_kpi (user_id, kpi_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ---- HANDLE SUBMIT ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kpi_id = (int)($_POST['kpi_id'] ?? 0);
    $note   = trim($_POST['request_note'] ?? '');

    if (!$kpi_id) {
        $message = 'กรุณาเลือกตัวชี้วัดที่ต้องการขอเพิ่มข้อมูล';
        $msgType = 'warning';
    } else {
        // ตรวจว่าเคยขอแล้วหรือยัง
        $check = $conn->prepare("SELECT id, status FROM kpi_requests WHERE user_id=? AND kpi_id=?");
        $check->bind_param('ii', $userId, $kpi_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();

        if ($existing) {
            $statusTH = ['pending'=>'รอการอนุมัติ','approved'=>'อนุมัติแล้ว','rejected'=>'ถูกปฏิเสธ'];
            $message = 'คุณเคยส่งคำขอนี้แล้ว สถานะ: ' . ($statusTH[$existing['status']] ?? $existing['status']);
            $msgType = 'info';
        } else {
            $stmt = $conn->prepare("INSERT INTO kpi_requests (user_id, kpi_id, request_note) VALUES (?,?,?)");
            $stmt->bind_param('iis', $userId, $kpi_id, $note);
            if ($stmt->execute()) {
                $message = 'ส่งคำขอสำเร็จ! กรุณารอ Admin อนุมัติ';
                $msgType = 'success';
            } else {
                $message = 'เกิดข้อผิดพลาด: ' . $conn->error;
                $msgType = 'danger';
            }
        }
    }
}

// ---- ดึง KPI ที่ยังไม่มีข้อมูล (ยังไม่มี kpi_results) ----
$availableKpis = [];
$res = $conn->query("
    SELECT k.kpi_id, k.kpi_code, k.kpi_name, k.target_value, k.target_operator
    FROM kpis k
    LEFT JOIN kpi_results r ON k.kpi_id = r.kpi_id
    WHERE r.kpi_id IS NULL
    ORDER BY k.kpi_code ASC
");
if ($res) { while ($row = $res->fetch_assoc()) { $availableKpis[] = $row; } }

// ---- ดึงคำขอของ user นี้ทั้งหมด ----
$myRequests = [];
$res2 = $conn->query("
    SELECT r.*, k.kpi_code, k.kpi_name, k.target_value, k.target_operator,
           u.fullname AS reviewed_by_name
    FROM kpi_requests r
    INNER JOIN kpis k ON r.kpi_id = k.kpi_id
    LEFT JOIN users u ON r.reviewed_by = u.id
    WHERE r.user_id = $userId
    ORDER BY r.created_at DESC
");
if ($res2) { while ($row = $res2->fetch_assoc()) { $myRequests[] = $row; } }

$activeMenu = 'request';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ขอเพิ่มข้อมูล KPI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/user.css">
</head>
<body>
<?php include '../includes/Navbar.php'; ?>

<div class="container-fluid px-4">

    <div class="page-header mb-4">
        <div>
            <h4 class="page-title"><i class="bi bi-file-earmark-plus-fill me-2 text-primary"></i>ขอเพิ่มข้อมูลตัวชี้วัด</h4>
            <p class="page-subtitle">ส่งคำขอไปยัง Admin เพื่อขอสิทธิ์นำเข้าข้อมูล KPI ที่ยังไม่มีข้อมูล</p>
        </div>
        <a href="<?= authUrl('user/my_data.php') ?>" class="btn btn-outline-primary rounded-pill px-4">
            <i class="bi bi-grid-3x3-gap-fill me-1"></i>ข้อมูลของฉัน
        </a>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
        <i class="bi bi-<?= $msgType === 'success' ? 'check-circle-fill' : ($msgType === 'warning' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-2"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-5">

        <!-- ---- FORM ---- -->
        <div class="col-lg-5">
            <div class="chart-card h-100">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>ส่งคำขอใหม่</h6>
                </div>
                <div class="chart-card__body">
                    <?php if (empty($availableKpis)): ?>
                    <div class="empty-state">
                        <i class="bi bi-check2-circle"></i>
                        <p>ตัวชี้วัดทุกตัวมีข้อมูลครบแล้ว</p>
                        <small class="text-muted">ไม่มีตัวชี้วัดที่รอการนำเข้าข้อมูล</small>
                    </div>
                    <?php else: ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">เลือกตัวชี้วัดที่ต้องการ <span class="text-danger">*</span></label>
                            <select name="kpi_id" class="form-select" required id="kpiSelect">
                                <option value="">— กรุณาเลือก —</option>
                                <?php foreach ($availableKpis as $k): ?>
                                <option value="<?= $k['kpi_id'] ?>"
                                        data-target="<?= $k['target_value'] ?>"
                                        data-op="<?= htmlspecialchars($k['target_operator']) ?>">
                                    <?= str_pad($k['kpi_code'],3,'0',STR_PAD_LEFT) ?> — <?= htmlspecialchars($k['kpi_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Preview KPI info -->
                        <div id="kpiPreview" class="kpi-preview-box d-none mb-3">
                            <div class="kpi-preview-label">เป้าหมาย</div>
                            <div class="kpi-preview-value" id="kpiPreviewVal">—</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">เหตุผล / รายละเอียดเพิ่มเติม</label>
                            <textarea name="request_note" class="form-control" rows="4"
                                      placeholder="ระบุเหตุผลที่ต้องการนำเข้าข้อมูลตัวชี้วัดนี้..."></textarea>
                        </div>

                        <div class="request-info-box mb-4">
                            <i class="bi bi-info-circle-fill text-primary me-2"></i>
                            <div>
                                <strong>ขั้นตอน:</strong> ส่งคำขอ → Admin อนุมัติ → Upload Excel → ดู Dashboard
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold py-2">
                            <i class="bi bi-send-fill me-2"></i>ส่งคำขอ
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ---- MY REQUESTS ---- -->
        <div class="col-lg-7">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-clock-history me-2"></i>คำขอของฉัน</h6>
                    <span class="badge bg-secondary"><?= count($myRequests) ?> รายการ</span>
                </div>
                <?php if (empty($myRequests)): ?>
                <div class="chart-card__body">
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>ยังไม่มีคำขอ</p>
                        <small class="text-muted">ส่งคำขอครั้งแรกจากฟอร์มด้านซ้าย</small>
                    </div>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table kpi-table mb-0">
                        <thead>
                            <tr>
                                <th>ตัวชี้วัด</th>
                                <th class="text-center" style="width:110px;">สถานะ</th>
                                <th style="width:130px;">วันที่ส่ง</th>
                                <th class="text-center" style="width:100px;">ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($myRequests as $req):
                            $statusConfig = [
                                'pending'  => ['class'=>'status-pending',  'icon'=>'bi-hourglass-split', 'text'=>'รอการอนุมัติ'],
                                'approved' => ['class'=>'status-approved', 'icon'=>'bi-check-circle-fill','text'=>'อนุมัติแล้ว'],
                                'rejected' => ['class'=>'status-rejected', 'icon'=>'bi-x-circle-fill',   'text'=>'ปฏิเสธ'],
                            ];
                            $sc = $statusConfig[$req['status']] ?? $statusConfig['pending'];
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars($req['kpi_name']) ?></div>
                                <div class="text-muted" style="font-size:.78rem;">
                                    เป้าหมาย <?= htmlspecialchars($req['target_operator']) ?> <?= $req['target_value'] ?>%
                                </div>
                                <?php if ($req['admin_note']): ?>
                                <div class="text-info mt-1" style="font-size:.75rem;">
                                    <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($req['admin_note']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="request-status <?= $sc['class'] ?>">
                                    <i class="bi <?= $sc['icon'] ?> me-1"></i><?= $sc['text'] ?>
                                </span>
                            </td>
                            <td class="text-muted small">
                                <?= date('d/m/Y H:i', strtotime($req['created_at'])) ?>
                            </td>
                            <td class="text-center">
                                <?php if ($req['status'] === 'approved'): ?>
                                <a href="<?= authUrl('user/upload_data.php') ?>?request_id=<?= $req['id'] ?>"
                                   class="btn btn-sm btn-success rounded-pill px-3">
                                    <i class="bi bi-upload me-1"></i>Upload
                                </a>
                                <?php elseif ($req['status'] === 'rejected'): ?>
                                <span class="text-muted small">—</span>
                                <?php else: ?>
                                <span class="text-muted small">รอ...</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('kpiSelect')?.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    const preview = document.getElementById('kpiPreview');
    const val     = document.getElementById('kpiPreviewVal');
    if (this.value) {
        val.textContent = opt.dataset.op + ' ' + opt.dataset.target + '%';
        preview.classList.remove('d-none');
    } else {
        preview.classList.add('d-none');
    }
});
</script>
</body>
</html>