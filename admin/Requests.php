<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/Auth.php';

Auth::requireAdmin();

$message = '';
$msgType = 'success';

// ---- ENSURE TABLE ----
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

// ---- HANDLE ACTIONS ----
$action = $_POST['action'] ?? '';

if ($action === 'approve') {
    $rid       = (int)$_POST['request_id'];
    $adminNote = trim($_POST['admin_note'] ?? '');
    $adminId   = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        UPDATE kpi_requests SET status='approved', admin_note=?, reviewed_by=?, reviewed_at=NOW()
        WHERE id=?
    ");
    $stmt->bind_param('sii', $adminNote, $adminId, $rid);
    $stmt->execute();
    $message = 'อนุมัติคำขอสำเร็จ User สามารถ Upload ข้อมูลได้แล้ว';
}

if ($action === 'reject') {
    $rid       = (int)$_POST['request_id'];
    $adminNote = trim($_POST['admin_note'] ?? '');
    $adminId   = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        UPDATE kpi_requests SET status='rejected', admin_note=?, reviewed_by=?, reviewed_at=NOW()
        WHERE id=?
    ");
    $stmt->bind_param('sii', $adminNote, $adminId, $rid);
    $stmt->execute();
    $message = 'ปฏิเสธคำขอแล้ว';
    $msgType = 'warning';
}

// ---- FETCH ALL REQUESTS ----
$filter = $_GET['filter'] ?? 'pending';
$validFilters = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $validFilters)) $filter = 'pending';

$where = $filter !== 'all' ? "WHERE r.status = '$filter'" : '';

$requests = [];
$res = $conn->query("
    SELECT r.*, k.kpi_code, k.kpi_name, k.target_value, k.target_operator,
           u.fullname AS user_fullname, u.username,
           adm.fullname AS admin_fullname,
           (SELECT COUNT(*) FROM kpi_data_rows dr
            INNER JOIN kpi_submissions s ON dr.submission_id = s.id
            WHERE s.request_id = r.id) AS data_count
    FROM kpi_requests r
    INNER JOIN kpis k ON r.kpi_id = k.kpi_id
    INNER JOIN users u ON r.user_id = u.id
    LEFT JOIN users adm ON r.reviewed_by = adm.id
    $where
    ORDER BY r.created_at DESC
");
if ($res) { while ($row = $res->fetch_assoc()) { $requests[] = $row; } }

// Count by status
$counts = ['pending'=>0, 'approved'=>0, 'rejected'=>0];
$cRes = $conn->query("SELECT status, COUNT(*) AS cnt FROM kpi_requests GROUP BY status");
if ($cRes) { while ($row = $cRes->fetch_assoc()) { $counts[$row['status']] = (int)$row['cnt']; } }

$activeMenu = 'requests';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการคำขอ KPI — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/user.css">
</head>
<body>
<?php include '../includes/Navbar.php'; ?>

<div class="container-fluid px-4">

    <div class="page-header mb-4">
        <div>
            <h4 class="page-title">
                <i class="bi bi-file-earmark-check-fill me-2 text-warning"></i>
                จัดการคำขอเพิ่มข้อมูล KPI
            </h4>
            <p class="page-subtitle">อนุมัติหรือปฏิเสธคำขอจาก User เพื่อให้สิทธิ์นำเข้าข้อมูล</p>
        </div>
        <?php if ($counts['pending'] > 0): ?>
        <span class="badge bg-danger fs-6 px-3 py-2">
            <i class="bi bi-bell-fill me-1"></i><?= $counts['pending'] ?> รอการอนุมัติ
        </span>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
        <i class="bi bi-<?= $msgType==='success'?'check-circle-fill':'exclamation-triangle-fill' ?> me-2"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <div class="d-flex gap-2 mb-4 flex-wrap">
        <?php
        $tabs = [
            'pending'  => ['label'=>'รอการอนุมัติ', 'badge'=>'bg-warning text-dark', 'icon'=>'bi-hourglass-split'],
            'approved' => ['label'=>'อนุมัติแล้ว',  'badge'=>'bg-success',           'icon'=>'bi-check-circle-fill'],
            'rejected' => ['label'=>'ปฏิเสธ',        'badge'=>'bg-danger',            'icon'=>'bi-x-circle-fill'],
            'all'      => ['label'=>'ทั้งหมด',       'badge'=>'bg-secondary',         'icon'=>'bi-list'],
        ];
        foreach ($tabs as $key => $tab):
            $cnt = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0);
        ?>
        <a href="?filter=<?= $key ?>"
           class="btn rounded-pill px-4 <?= $filter === $key ? 'btn-dark' : 'btn-outline-secondary' ?>">
            <i class="bi <?= $tab['icon'] ?> me-1"></i>
            <?= $tab['label'] ?>
            <span class="badge <?= $tab['badge'] ?> ms-1"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Requests Table -->
    <div class="chart-card mb-5">
        <div class="chart-card__header">
            <h6 class="chart-card__title">คำขอทั้งหมด (<?= count($requests) ?> รายการ)</h6>
            <div class="input-group input-group-sm" style="width:220px;">
                <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="reqSearch" class="form-control border-start-0" placeholder="ค้นหา...">
            </div>
        </div>

        <?php if (empty($requests)): ?>
        <div class="chart-card__body">
            <div class="empty-state py-4">
                <i class="bi bi-inbox" style="font-size:2.5rem;color:#94a3b8;"></i>
                <p class="mt-2 text-muted">ไม่มีคำขอในหมวดนี้</p>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table kpi-table mb-0" id="reqTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>ผู้ขอ</th>
                        <th>ตัวชี้วัด</th>
                        <th>เหตุผล</th>
                        <th class="text-center" style="width:120px;">สถานะ</th>
                        <th class="text-center" style="width:80px;">ข้อมูล</th>
                        <th style="width:130px;">วันที่ส่ง</th>
                        <th class="text-center" style="width:160px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $i => $req):
                    $sc = [
                        'pending'  => ['class'=>'status-pending',  'icon'=>'bi-hourglass-split', 'text'=>'รอการอนุมัติ'],
                        'approved' => ['class'=>'status-approved', 'icon'=>'bi-check-circle-fill','text'=>'อนุมัติแล้ว'],
                        'rejected' => ['class'=>'status-rejected', 'icon'=>'bi-x-circle-fill',   'text'=>'ปฏิเสธ'],
                    ][$req['status']];
                ?>
                <tr>
                    <td class="text-muted small"><?= $i+1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="user-avatar-sm"><?= mb_substr($req['user_fullname'],0,1) ?></span>
                            <div>
                                <div class="fw-semibold small"><?= htmlspecialchars($req['user_fullname']) ?></div>
                                <div class="text-muted" style="font-size:.75rem;">@<?= htmlspecialchars($req['username']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="kpi-code me-1"><?= str_pad($req['kpi_code'],3,'0',STR_PAD_LEFT) ?></span>
                        <span class="small"><?= htmlspecialchars(mb_substr($req['kpi_name'],0,35)) ?></span>
                        <div class="text-muted" style="font-size:.75rem;">
                            เป้าหมาย <?= htmlspecialchars($req['target_operator']) ?> <?= $req['target_value'] ?>%
                        </div>
                    </td>
                    <td class="text-muted small" style="max-width:200px;">
                        <?= htmlspecialchars(mb_substr($req['request_note'] ?? '—', 0, 80)) ?>
                        <?php if ($req['admin_note']): ?>
                        <div class="mt-1 text-info" style="font-size:.72rem;">
                            <i class="bi bi-reply me-1"></i>Admin: <?= htmlspecialchars($req['admin_note']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="request-status <?= $sc['class'] ?>">
                            <i class="bi <?= $sc['icon'] ?> me-1"></i><?= $sc['text'] ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <?php if ($req['data_count'] > 0): ?>
                        <span class="badge bg-info text-dark"><?= $req['data_count'] ?> แถว</span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td>
                    <td class="text-center">
                        <?php if ($req['status'] === 'pending'): ?>
                        <button class="btn btn-sm btn-success rounded-pill me-1"
                                onclick="openReviewModal(<?= $req['id'] ?>, 'approve', '<?= htmlspecialchars(addslashes($req['user_fullname'])) ?>', '<?= htmlspecialchars(addslashes($req['kpi_name'])) ?>')">
                            <i class="bi bi-check-lg"></i> อนุมัติ
                        </button>
                        <button class="btn btn-sm btn-outline-danger rounded-pill"
                                onclick="openReviewModal(<?= $req['id'] ?>, 'reject', '<?= htmlspecialchars(addslashes($req['user_fullname'])) ?>', '<?= htmlspecialchars(addslashes($req['kpi_name'])) ?>')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <?php else: ?>
                        <span class="text-muted small">ดำเนินการแล้ว</span>
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

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="reviewModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="reviewInfo" class="mb-3 p-3 bg-light rounded-3 small"></div>
                <form method="POST" id="reviewForm">
                    <input type="hidden" name="action" id="reviewAction">
                    <input type="hidden" name="request_id" id="reviewRequestId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">หมายเหตุ / เหตุผล (ถึง User)</label>
                        <textarea name="admin_note" class="form-control" rows="3"
                                  placeholder="ระบุหมายเหตุสำหรับ User..."></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light rounded-pill flex-fill" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn rounded-pill flex-fill fw-bold" id="reviewSubmitBtn">ยืนยัน</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openReviewModal(id, action, userName, kpiName) {
    const isApprove = action === 'approve';
    document.getElementById('reviewModalTitle').innerHTML =
        `<i class="bi bi-${isApprove ? 'check-circle-fill text-success' : 'x-circle-fill text-danger'} me-2"></i>`
        + (isApprove ? 'อนุมัติคำขอ' : 'ปฏิเสธคำขอ');
    document.getElementById('reviewInfo').innerHTML =
        `<strong>ผู้ขอ:</strong> ${userName}<br><strong>ตัวชี้วัด:</strong> ${kpiName}`;
    document.getElementById('reviewAction').value    = action;
    document.getElementById('reviewRequestId').value = id;
    const btn = document.getElementById('reviewSubmitBtn');
    btn.className = `btn rounded-pill flex-fill fw-bold btn-${isApprove ? 'success' : 'danger'}`;
    btn.textContent = isApprove ? '✓ อนุมัติ' : '✗ ปฏิเสธ';
    new bootstrap.Modal(document.getElementById('reviewModal')).show();
}
document.getElementById('reqSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#reqTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>