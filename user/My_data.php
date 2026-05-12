<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/KPI.php';
include '../classes/Auth.php';

Auth::requireLogin();
if (Auth::isAdmin()) { header('Location: ' . authUrl('admin/index.php')); exit; }

$userId = $_SESSION['user_id'];

// ดึงคำขอที่ approved + มีข้อมูล
$myKpis = [];
$res = $conn->query("
    SELECT r.id AS request_id, r.kpi_id, r.created_at AS requested_at,
           k.kpi_code, k.kpi_name, k.target_value, k.target_operator,
           COUNT(dr.id) AS data_count,
           AVG(dr.actual_value) AS avg_value,
           MAX(dr.actual_value) AS max_value,
           MIN(dr.actual_value) AS min_value,
           (SELECT dr2.actual_value FROM kpi_data_rows dr2
            WHERE dr2.kpi_id = r.kpi_id AND dr2.user_id = $userId
            ORDER BY COALESCE(dr2.period_date, dr2.created_at) DESC LIMIT 1) AS latest_value,
           MAX(s.uploaded_at) AS last_uploaded
    FROM kpi_requests r
    INNER JOIN kpis k ON r.kpi_id = k.kpi_id
    LEFT JOIN kpi_submissions s ON s.request_id = r.id
    LEFT JOIN kpi_data_rows dr ON dr.submission_id = s.id AND dr.user_id = $userId
    WHERE r.user_id = $userId AND r.status = 'approved'
    GROUP BY r.id
    ORDER BY r.created_at DESC
");
if ($res) { while ($row = $res->fetch_assoc()) { $myKpis[] = $row; } }

$activeMenu = 'mydata';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูล KPI ของฉัน</title>
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
            <h4 class="page-title"><i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>ข้อมูล KPI ของฉัน</h4>
            <p class="page-subtitle">ภาพรวมตัวชี้วัดที่ได้รับอนุมัติให้นำเข้าข้อมูล</p>
        </div>
        <a href="<?= authUrl('user/request_kpi.php') ?>" class="btn btn-primary rounded-pill px-4">
            <i class="bi bi-plus-lg me-1"></i>ขอเพิ่มตัวชี้วัดใหม่
        </a>
    </div>

    <?php if (empty($myKpis)): ?>
    <div class="chart-card">
        <div class="chart-card__body">
            <div class="empty-state py-5">
                <i class="bi bi-inbox" style="font-size:3rem;color:#94a3b8;"></i>
                <h5 class="mt-3">ยังไม่มีตัวชี้วัดที่ได้รับอนุมัติ</h5>
                <p class="text-muted">ส่งคำขอเพื่อขอสิทธิ์นำเข้าข้อมูล KPI</p>
                <a href="<?= authUrl('user/request_kpi.php') ?>" class="btn btn-primary rounded-pill px-4 mt-2">
                    <i class="bi bi-file-earmark-plus me-2"></i>ส่งคำขอ
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>

    <div class="row g-3 mb-5">
        <?php foreach ($myKpis as $kpi):
            $latest  = (float)($kpi['latest_value'] ?? 0);
            $target  = (float)$kpi['target_value'];
            $op      = $kpi['target_operator'];
            $isPass  = $kpi['data_count'] > 0 ? evaluateKpi($latest, $target, $op) : null;
            $hasData = $kpi['data_count'] > 0;
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="my-kpi-card <?= $isPass === true ? 'my-kpi-card--pass' : ($isPass === false ? 'my-kpi-card--fail' : '') ?>">
                <!-- Header -->
                <div class="my-kpi-card__header">
                    <div class="d-flex justify-content-between align-items-start">
                        <span class="kpi-code"><?= str_pad($kpi['kpi_code'],3,'0',STR_PAD_LEFT) ?></span>
                        <?php if ($isPass !== null): ?>
                        <span class="status-badge <?= $isPass ? 'status-badge--pass' : 'status-badge--fail' ?>">
                            <?= $isPass ? '<i class="bi bi-check-circle-fill me-1"></i>บรรลุ' : '<i class="bi bi-x-circle-fill me-1"></i>ต่ำกว่าเป้า' ?>
                        </span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">ยังไม่มีข้อมูล</span>
                        <?php endif; ?>
                    </div>
                    <h6 class="my-kpi-card__name mt-2"><?= htmlspecialchars($kpi['kpi_name']) ?></h6>
                </div>

                <!-- Stats -->
                <div class="my-kpi-card__stats">
                    <div class="my-kpi-stat">
                        <div class="my-kpi-stat__label">ผลงานล่าสุด</div>
                        <div class="my-kpi-stat__val <?= $isPass === true ? 'text-success' : ($isPass === false ? 'text-danger' : 'text-muted') ?>">
                            <?= $hasData ? number_format($latest, 2).'%' : '—' ?>
                        </div>
                    </div>
                    <div class="my-kpi-stat">
                        <div class="my-kpi-stat__label">เป้าหมาย</div>
                        <div class="my-kpi-stat__val text-primary">
                            <?= htmlspecialchars($op) ?> <?= $target ?>%
                        </div>
                    </div>
                    <div class="my-kpi-stat">
                        <div class="my-kpi-stat__label">ค่าเฉลี่ย</div>
                        <div class="my-kpi-stat__val text-muted">
                            <?= $hasData ? number_format($kpi['avg_value'], 2).'%' : '—' ?>
                        </div>
                    </div>
                    <div class="my-kpi-stat">
                        <div class="my-kpi-stat__label">จำนวนข้อมูล</div>
                        <div class="my-kpi-stat__val text-muted">
                            <?= $kpi['data_count'] ?> รายการ
                        </div>
                    </div>
                </div>

                <!-- Progress bar -->
                <?php if ($hasData): ?>
                <div class="my-kpi-card__progress">
                    <?php
                    $pct = min(100, ($op === '<=' || $op === '<')
                        ? ($isPass ? 100 : max(0, 100 - ($latest - $target)))
                        : min(100, $target > 0 ? $latest / $target * 100 : 0));
                    ?>
                    <div class="progress-bar-wrap">
                        <div class="progress-bar-fill <?= $isPass ? 'progress-bar-fill--pass' : 'progress-bar-fill--fail' ?>"
                             style="width:<?= $pct ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1" style="font-size:.75rem;color:#94a3b8;">
                        <span>0%</span>
                        <span><?= $target ?>% (เป้า)</span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Footer -->
                <div class="my-kpi-card__footer">
                    <span class="text-muted small">
                        <i class="bi bi-clock me-1"></i>
                        <?= $kpi['last_uploaded'] ? 'Upload: '.date('d/m/Y', strtotime($kpi['last_uploaded'])) : 'ยังไม่มีข้อมูล' ?>
                    </span>
                    <div class="d-flex gap-2">
                        <a href="<?= authUrl('user/upload_data.php') ?>?request_id=<?= $kpi['request_id'] ?>"
                           class="btn btn-sm btn-outline-success rounded-pill" title="Upload ข้อมูล">
                            <i class="bi bi-upload"></i>
                        </a>
                        <?php if ($hasData): ?>
                        <a href="<?= authUrl('user/dashboard.php') ?>?request_id=<?= $kpi['request_id'] ?>"
                           class="btn btn-sm btn-primary rounded-pill px-3">
                            <i class="bi bi-bar-chart-fill me-1"></i>Dashboard
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>