<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/KPI.php';
include '../classes/Auth.php';

Auth::requireAdmin();

$kpiData  = getKpiDataFromDB($conn);
$summary  = countKpiStatus($kpiData);
$totalKpi = count($kpiData['kpiname']);
$passRate = $totalKpi > 0 ? round(($summary['pass'] / $totalKpi) * 100) : 0;

// Count users
$userStats = ['total' => 0, 'admin' => 0, 'user' => 0];
$res = $conn->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $userStats[$row['role']] = (int)$row['cnt'];
        $userStats['total'] += (int)$row['cnt'];
    }
}

$activeMenu = 'admin';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — PHO KPI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<?php include '../includes/Navbar.php'; ?>

<div class="container-fluid px-4">

    <!-- Page Header -->
    <div class="page-header mb-4">
        <div>
            <h4 class="page-title"><i class="bi bi-shield-lock-fill me-2 text-warning"></i>Admin Dashboard</h4>
            <p class="page-subtitle">ภาพรวมและการจัดการระบบ PHO KPI</p>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--total">
                <div class="stat-icon"><i class="bi bi-clipboard2-data"></i></div>
                <div class="stat-value"><?= $totalKpi ?></div>
                <div class="stat-label">ตัวชี้วัดทั้งหมด</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--pass">
                <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-value"><?= $summary['pass'] ?></div>
                <div class="stat-label">บรรลุเป้าหมาย</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--fail">
                <div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div>
                <div class="stat-value"><?= $summary['fail'] ?></div>
                <div class="stat-label">ต่ำกว่าเป้าหมาย</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--rate">
                <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                <div class="stat-value"><?= $userStats['total'] ?></div>
                <div class="stat-label">ผู้ใช้งานทั้งหมด</div>
            </div>
        </div>
    </div>

    <!-- Admin Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="kpi_manage.php" class="admin-action-card">
                <div class="admin-action-icon" style="background:linear-gradient(135deg,#2563eb,#7c3aed)">
                    <i class="bi bi-clipboard2-data-fill"></i>
                </div>
                <div>
                    <div class="admin-action-title">จัดการข้อมูล KPI</div>
                    <div class="admin-action-desc">แก้ไขเป้าหมาย, ผลงานจริง, เงื่อนไขการผ่านเกณฑ์</div>
                </div>
                <i class="bi bi-chevron-right admin-action-arrow"></i>
            </a>
        </div>
        <div class="col-md-4">
            <a href="highlight.php" class="admin-action-card">
                <div class="admin-action-icon" style="background:linear-gradient(135deg,#0d9488,#0284c7)">
                    <i class="bi bi-stars"></i>
                </div>
                <div>
                    <div class="admin-action-title">ตั้งค่า Highlight Cards</div>
                    <div class="admin-action-desc">เลือก KPI ที่ต้องการแสดงบนหน้า Dashboard</div>
                </div>
                <i class="bi bi-chevron-right admin-action-arrow"></i>
            </a>
        </div>
        <div class="col-md-4">
            <a href="users.php" class="admin-action-card">
                <div class="admin-action-icon" style="background:linear-gradient(135deg,#ea580c,#d97706)">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <div class="admin-action-title">จัดการผู้ใช้งาน</div>
                    <div class="admin-action-desc">
                        Admin <?= $userStats['admin'] ?> คน &nbsp;|&nbsp; User <?= $userStats['user'] ?> คน
                    </div>
                </div>
                <i class="bi bi-chevron-right admin-action-arrow"></i>
            </a>
        </div>
    </div>

    <!-- KPI Summary Table (read-only preview) -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-table me-2"></i>ภาพรวม KPI ทั้งหมด</h6>
                    <a href="kpi_manage.php" class="btn btn-sm btn-primary rounded-pill px-3">
                        <i class="bi bi-pencil-square me-1"></i>แก้ไขข้อมูล
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table kpi-table mb-0">
                        <thead>
                            <tr>
                                <th style="width:80px;">รหัส</th>
                                <th>ชื่อตัวชี้วัด</th>
                                <th class="text-center" style="width:130px;">เป้าหมาย</th>
                                <th class="text-center" style="width:130px;">ผลงานจริง</th>
                                <th class="text-center" style="width:150px;">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($kpiData['kpiname'] as $index => $name):
                            $result   = $kpiData['results'][$index];
                            $target   = $kpiData['targets'][$index];
                            $operator = $kpiData['operator'][$index];
                            $isPass   = evaluateKpi($result, $target, $operator);
                        ?>
                        <tr class="kpi-row <?= $isPass ? 'kpi-row--pass' : 'kpi-row--fail' ?>">
                            <td><span class="kpi-code"><?= str_pad($kpiData['kpicode'][$index], 3, '0', STR_PAD_LEFT) ?></span></td>
                            <td class="kpi-name"><?= htmlspecialchars($name) ?></td>
                            <td class="text-center"><span class="text-muted small"><?= $operator ?></span> <strong><?= $target ?>%</strong></td>
                            <td class="text-center">
                                <span class="result-val <?= $isPass ? 'result-val--pass' : 'result-val--fail' ?>">
                                    <?= number_format($result, 2) ?>%
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="status-badge <?= $isPass ? 'status-badge--pass' : 'status-badge--fail' ?>">
                                    <?= $isPass ? '<i class="bi bi-check-circle-fill me-1"></i>บรรลุ' : '<i class="bi bi-x-circle-fill me-1"></i>ต่ำกว่าเป้า' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>