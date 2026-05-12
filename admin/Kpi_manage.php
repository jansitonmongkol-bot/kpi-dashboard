<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/KPI.php';
include '../classes/Auth.php';

Auth::requireAdmin();

$message = '';
$msgType = 'success';

// ===== HANDLE ACTIONS =====
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// UPDATE KPI target & operator
if ($action === 'update_target' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $kpi_id   = (int)$_POST['kpi_id'];
    $target   = (float)$_POST['target_value'];
    $operator = in_array($_POST['operator'], ['>=','<=','>','<','=']) ? $_POST['operator'] : '>=';
    $kpi_name = trim($_POST['kpi_name']);

    $stmt = $conn->prepare("UPDATE kpis SET target_value=?, target_operator=?, kpi_name=? WHERE kpi_id=?");
    $stmt->bind_param('dssi', $target, $operator, $kpi_name, $kpi_id);
    if ($stmt->execute()) {
        $message = 'อัปเดตข้อมูล KPI สำเร็จ';
    } else {
        $message = 'เกิดข้อผิดพลาด: ' . $conn->error;
        $msgType = 'danger';
    }
}

// UPDATE actual result
if ($action === 'update_result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $kpi_id      = (int)$_POST['kpi_id'];
    $actual_value = (float)$_POST['actual_value'];

    // Upsert: ถ้ามีอยู่แล้วให้ update, ถ้าไม่มีให้ insert
    $stmt = $conn->prepare("
        INSERT INTO kpi_results (kpi_id, actual_value, last_updated)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE actual_value = VALUES(actual_value), last_updated = NOW()
    ");
    $stmt->bind_param('id', $kpi_id, $actual_value);
    if ($stmt->execute()) {
        $message = 'อัปเดตผลงานจริงสำเร็จ';
    } else {
        $message = 'เกิดข้อผิดพลาด: ' . $conn->error;
        $msgType = 'danger';
    }
}

// ADD new KPI
if ($action === 'add_kpi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $kpi_code = trim($_POST['kpi_code']);
    $kpi_name = trim($_POST['kpi_name']);
    $target   = (float)$_POST['target_value'];
    $operator = in_array($_POST['operator'], ['>=','<=','>','<','=']) ? $_POST['operator'] : '>=';

    $stmt = $conn->prepare("INSERT INTO kpis (kpi_code, kpi_name, target_value, target_operator) VALUES (?,?,?,?)");
    $stmt->bind_param('ssds', $kpi_code, $kpi_name, $target, $operator);
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        // เพิ่ม result เริ่มต้น = 0
        $conn->query("INSERT INTO kpi_results (kpi_id, actual_value, last_updated) VALUES ($newId, 0, NOW())");
        $message = 'เพิ่มตัวชี้วัดใหม่สำเร็จ';
    } else {
        $message = 'เกิดข้อผิดพลาด: ' . $conn->error;
        $msgType = 'danger';
    }
}

// DELETE KPI
if ($action === 'delete_kpi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $kpi_id = (int)$_POST['kpi_id'];
    $conn->query("DELETE FROM kpi_results WHERE kpi_id = $kpi_id");
    $conn->query("DELETE FROM kpis WHERE kpi_id = $kpi_id");
    $message = 'ลบตัวชี้วัดสำเร็จ';
}

// ดึงข้อมูล KPI ทั้งหมด (full join)
$kpiRows = [];
$res = $conn->query("
    SELECT k.kpi_id, k.kpi_code, k.kpi_name, k.target_value, k.target_operator,
           COALESCE(r.actual_value, 0) as actual_value,
           r.last_updated
    FROM kpis k
    LEFT JOIN kpi_results r ON k.kpi_id = r.kpi_id
    LEFT JOIN (
        SELECT kpi_id, MAX(last_updated) AS mx FROM kpi_results GROUP BY kpi_id
    ) latest ON r.kpi_id = latest.kpi_id AND r.last_updated = latest.mx
    GROUP BY k.kpi_id
    ORDER BY k.kpi_code ASC
");
if ($res) { while ($row = $res->fetch_assoc()) { $kpiRows[] = $row; } }

$activeMenu = 'kpi';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูล KPI — Admin</title>
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
            <h4 class="page-title"><i class="bi bi-clipboard2-data-fill me-2 text-primary"></i>จัดการข้อมูล KPI</h4>
            <p class="page-subtitle">เพิ่ม แก้ไข หรือลบตัวชี้วัด และอัปเดตผลงานจริง</p>
        </div>
        <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addKpiModal">
            <i class="bi bi-plus-lg me-1"></i>เพิ่มตัวชี้วัดใหม่
        </button>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
        <i class="bi bi-<?= $msgType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Table -->
    <div class="chart-card mb-5">
        <div class="chart-card__header">
            <h6 class="chart-card__title">ตัวชี้วัดทั้งหมด (<?= count($kpiRows) ?> รายการ)</h6>
            <div class="input-group input-group-sm" style="width:220px;">
                <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="adminSearch" class="form-control border-start-0" placeholder="ค้นหา...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table kpi-table mb-0" id="adminTable">
                <thead>
                    <tr>
                        <th style="width:70px;">รหัส</th>
                        <th>ชื่อตัวชี้วัด</th>
                        <th class="text-center" style="width:110px;">เงื่อนไข</th>
                        <th class="text-center" style="width:110px;">เป้าหมาย%</th>
                        <th class="text-center" style="width:110px;">ผลงานจริง%</th>
                        <th class="text-center" style="width:100px;">สถานะ</th>
                        <th class="text-center" style="width:130px;">อัปเดตล่าสุด</th>
                        <th class="text-center" style="width:120px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($kpiRows as $row):
                    $isPass = evaluateKpi($row['actual_value'], $row['target_value'], $row['target_operator']);
                ?>
                <tr class="kpi-row <?= $isPass ? 'kpi-row--pass' : 'kpi-row--fail' ?>">
                    <td><span class="kpi-code"><?= str_pad($row['kpi_code'], 3, '0', STR_PAD_LEFT) ?></span></td>
                    <td class="kpi-name"><?= htmlspecialchars($row['kpi_name']) ?></td>
                    <td class="text-center fw-bold"><?= htmlspecialchars($row['target_operator']) ?></td>
                    <td class="text-center"><strong><?= $row['target_value'] ?>%</strong></td>
                    <td class="text-center">
                        <span class="result-val <?= $isPass ? 'result-val--pass' : 'result-val--fail' ?>">
                            <?= number_format($row['actual_value'], 2) ?>%
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="status-badge <?= $isPass ? 'status-badge--pass' : 'status-badge--fail' ?>">
                            <?= $isPass ? '✓ บรรลุ' : '✗ ต่ำกว่าเป้า' ?>
                        </span>
                    </td>
                    <td class="text-center text-muted small">
                        <?= $row['last_updated'] ? date('d/m/Y H:i', strtotime($row['last_updated'])) : '-' ?>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary rounded-pill me-1"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode($row)) ?>)"
                                title="แก้ไข">
                            <i class="bi bi-pencil-fill"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger rounded-pill"
                                onclick="confirmDelete(<?= $row['kpi_id'] ?>, '<?= htmlspecialchars(addslashes($row['kpi_name'])) ?>')"
                                title="ลบ">
                            <i class="bi bi-trash3-fill"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===== EDIT MODAL ===== -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>แก้ไขตัวชี้วัด</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Tab: Target -->
                <ul class="nav nav-pills mb-3" id="editTabs">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tabTarget">
                            <i class="bi bi-bullseye me-1"></i>แก้ไขเป้าหมาย
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabResult">
                            <i class="bi bi-graph-up me-1"></i>อัปเดตผลงาน
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Tab: Edit Target -->
                    <div class="tab-pane fade show active" id="tabTarget">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_target">
                            <input type="hidden" name="kpi_id" id="edit_kpi_id">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">ชื่อตัวชี้วัด</label>
                                <input type="text" name="kpi_name" id="edit_kpi_name" class="form-control" required>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">เงื่อนไข (Operator)</label>
                                    <select name="operator" id="edit_operator" class="form-select">
                                        <option value=">=">≥ มากกว่าหรือเท่ากับ</option>
                                        <option value="<=">≤ น้อยกว่าหรือเท่ากับ</option>
                                        <option value=">"> > มากกว่า</option>
                                        <option value="<"> < น้อยกว่า</option>
                                        <option value="=">=  เท่ากับ</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">ค่าเป้าหมาย (%)</label>
                                    <input type="number" name="target_value" id="edit_target" class="form-control"
                                           min="0" max="100" step="0.01" required>
                                </div>
                            </div>
                            <div class="mt-3 text-end">
                                <button type="submit" class="btn btn-primary rounded-pill px-4">
                                    <i class="bi bi-save me-1"></i>บันทึกเป้าหมาย
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Tab: Update Result -->
                    <div class="tab-pane fade" id="tabResult">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_result">
                            <input type="hidden" name="kpi_id" id="result_kpi_id">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">ตัวชี้วัด</label>
                                <input type="text" id="result_kpi_name" class="form-control" readonly
                                       style="background:#f8fafc;">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">ผลงานจริง (%)</label>
                                <input type="number" name="actual_value" id="result_actual" class="form-control"
                                       min="0" max="999" step="0.01" required>
                                <div class="form-text">ระบบจะบันทึกเวลาล่าสุดอัตโนมัติ</div>
                            </div>
                            <div class="mt-3 text-end">
                                <button type="submit" class="btn btn-success rounded-pill px-4">
                                    <i class="bi bi-graph-up me-1"></i>อัปเดตผลงาน
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== ADD KPI MODAL ===== -->
<div class="modal fade" id="addKpiModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2 text-success"></i>เพิ่มตัวชี้วัดใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_kpi">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">รหัส KPI</label>
                        <input type="text" name="kpi_code" class="form-control" placeholder="เช่น 01, 15" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อตัวชี้วัด</label>
                        <input type="text" name="kpi_name" class="form-control" placeholder="ชื่อตัวชี้วัด" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">เงื่อนไข</label>
                            <select name="operator" class="form-select">
                                <option value=">=">≥ มากกว่าหรือเท่ากับ</option>
                                <option value="<=">≤ น้อยกว่าหรือเท่ากับ</option>
                                <option value=">">  > มากกว่า</option>
                                <option value="<">  < น้อยกว่า</option>
                                <option value="=">=  เท่ากับ</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">เป้าหมาย (%)</label>
                            <input type="number" name="target_value" class="form-control"
                                   min="0" max="100" step="0.01" placeholder="เช่น 70" required>
                        </div>
                    </div>
                    <div class="mt-3 text-end">
                        <button type="button" class="btn btn-light rounded-pill px-4 me-2" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-success rounded-pill px-4">
                            <i class="bi bi-plus-lg me-1"></i>เพิ่มตัวชี้วัด
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- DELETE FORM (hidden) -->
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete_kpi">
    <input type="hidden" name="kpi_id" id="delete_kpi_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEditModal(row) {
    document.getElementById('edit_kpi_id').value    = row.kpi_id;
    document.getElementById('edit_kpi_name').value  = row.kpi_name;
    document.getElementById('edit_target').value    = row.target_value;
    document.getElementById('edit_operator').value  = row.target_operator;
    document.getElementById('result_kpi_id').value  = row.kpi_id;
    document.getElementById('result_kpi_name').value = row.kpi_name;
    document.getElementById('result_actual').value  = row.actual_value;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function confirmDelete(id, name) {
    if (confirm(`ยืนยันการลบตัวชี้วัด:\n"${name}"\n\nการกระทำนี้ไม่สามารถย้อนกลับได้`)) {
        document.getElementById('delete_kpi_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// Search
document.getElementById('adminSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#adminTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>