<?php
define('BASE_PATH', '');
include 'classes/Database.php';
include 'classes/KPI.php';
include 'classes/Auth.php';

// ไม่บังคับ login — ทุกคนดูได้ บัญชีต้องล็อกอินก่อนจะแก้ไขได้
$kpiData  = getKpiDataFromDB($conn);
$summary  = countKpiStatus($kpiData);
$totalKpi = count($kpiData['kpiname']);
$passRate = $totalKpi > 0 ? round(($summary['pass'] / $totalKpi) * 100) : 0;
$highlightConfig = getHighlightConfig($conn);

$activeMenu = 'dashboard';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health KPI Dashboard 2568</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include 'includes/Navbar.php'; ?>

<div class="container-fluid px-4">

    <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        คุณไม่มีสิทธิ์เข้าถึงหน้านั้น
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (!Auth::check()): ?>
    <!-- Guest notice banner -->
    <div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-3 mb-3" role="alert">
        <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
        <div class="flex-1">
            <strong>กำลังดูในโหมดสาธารณะ</strong>
            — คุณสามารถดูข้อมูล KPI ได้ทั้งหมด หากต้องการส่งข้อมูล ดาวน์โหลด หรือจัดการ KPI กรุณา
            <a href="<?= authUrl('login.php') ?>" class="alert-link fw-bold">เข้าสู่ระบบ</a>
            หรือ
            <a href="<?= authUrl('register.php') ?>" class="alert-link fw-bold">สมัครใช้งาน</a>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- SUMMARY STAT CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--total">
                <div class="stat-icon"><i class="bi bi-clipboard2-pulse"></i></div>
                <div class="stat-value"><?= $totalKpi ?></div>
                <div class="stat-label">ตัวชี้วัดทั้งหมด</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--pass">
                <div class="stat-icon"><i class="bi bi-patch-check-fill"></i></div>
                <div class="stat-value"><?= $summary['pass'] ?></div>
                <div class="stat-label">บรรลุเป้าหมาย</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--fail">
                <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="stat-value"><?= $summary['fail'] ?></div>
                <div class="stat-label">ต่ำกว่าเป้าหมาย</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--rate">
                <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="stat-value"><?= $passRate ?>%</div>
                <div class="stat-label">อัตราผ่านเกณฑ์</div>
            </div>
        </div>
    </div>

    <!-- HIGHLIGHT CARDS -->
    <div class="row g-3 mb-4">
        <?php foreach ($highlightConfig as $h):
            $i = $h['kpi_index'];
            if (!isset($kpiData['kpiname'][$i])) continue;
            $res  = $kpiData['results'][$i];
            $tgt  = $kpiData['targets'][$i];
            $op   = $kpiData['operator'][$i];
            $pass = evaluateKpi($res, $tgt, $op);
        ?>
        <div class="col-md-4">
            <div class="highlight-card highlight-card--<?= htmlspecialchars($h['color']) ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="highlight-label"><?= htmlspecialchars($kpiData['kpiname'][$i]) ?></p>
                        <h2 class="highlight-value"><?= number_format($res, 2) ?><span class="highlight-unit">%</span></h2>
                    </div>
                    <i class="bi <?= htmlspecialchars($h['icon']) ?> highlight-icon"></i>
                </div>
                <div class="highlight-footer">
                    <span>เป้าหมาย <?= htmlspecialchars($op) ?> <?= $tgt ?>%</span>
                    <span class="badge <?= $pass ? 'badge-pass' : 'badge-fail' ?>">
                        <?= $pass ? '✓ บรรลุ' : '✗ ต่ำกว่าเป้า' ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- CHARTS -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-bar-chart-fill me-2"></i>ผลงานจริงเทียบเป้าหมายทุกตัวชี้วัด</h6>
                    <div class="legend-badges">
                        <span class="legend-dot legend-dot--pass"></span><span class="small">บรรลุเป้า</span>
                        <span class="legend-dot legend-dot--fail ms-3"></span><span class="small">ต่ำกว่าเป้า</span>
                    </div>
                </div>
                <div class="chart-card__body" style="height:340px;"><canvas id="barChart"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card h-100">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-pie-chart-fill me-2"></i>สัดส่วนความสำเร็จ</h6>
                </div>
                <div class="chart-card__body d-flex flex-column align-items-center justify-content-center" style="height:340px;">
                    <div style="width:220px;height:220px;position:relative;">
                        <canvas id="doughnutChart"></canvas>
                        <div class="doughnut-center-text">
                            <span class="doughnut-pct"><?= $passRate ?>%</span>
                            <span class="doughnut-sub">ผ่านเกณฑ์</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-table me-2"></i>ตารางสรุปตัวชี้วัดปีงบประมาณ 2568</h6>
                    <div class="input-group input-group-sm" style="width:220px;">
                        <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="tableSearch" class="form-control border-start-0" placeholder="ค้นหาตัวชี้วัด...">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table kpi-table mb-0" id="kpiTable">
                        <thead>
                            <tr>
                                <th style="width:80px;">รหัส</th>
                                <th>ชื่อตัวชี้วัด</th>
                                <th class="text-center" style="width:130px;">เป้าหมาย</th>
                                <th class="text-center" style="width:120px;">ผลงานจริง</th>
                                <th class="text-center" style="width:150px;">สถานะ</th>
                                <th class="text-center" style="width:120px;">ความคืบหน้า</th>
                                <th class="text-center" style="width:80px;">รายละเอียด</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($kpiData['kpiname'] as $index => $name):
                            $kpi_id   = $kpiData['kpi_db_id'][$index];
                            $kpi_code = $kpiData['kpicode'][$index];
                            $result   = $kpiData['results'][$index];
                            $target   = $kpiData['targets'][$index];
                            $operator = $kpiData['operator'][$index];
                            $isPass   = evaluateKpi($result, $target, $operator);
                            $progressVal = min(100, $target > 0 ? ($result / $target * 100) : 0);
                            if ($operator === '<=' || $operator === '<') {
                                $progressVal = $isPass ? 100 : max(0, 100 - ($result - $target));
                            }
                        ?>
                        <tr class="kpi-row <?= $isPass ? 'kpi-row--pass' : 'kpi-row--fail' ?>"
                            style="cursor:pointer;"
                            onclick="window.location='kpi_detail.php?id=<?= $kpi_id ?>'">
                            <td><span class="kpi-code"><?= str_pad($kpi_code, 3, '0', STR_PAD_LEFT) ?></span></td>
                            <td class="kpi-name"><?= htmlspecialchars($name) ?></td>
                            <td class="text-center">
                                <span class="text-muted small"><?= htmlspecialchars($operator) ?></span>
                                <strong><?= $target ?>%</strong>
                            </td>
                            <td class="text-center">
                                <span class="result-val <?= $isPass ? 'result-val--pass' : 'result-val--fail' ?>">
                                    <?= number_format($result, 2) ?>%
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="status-badge <?= $isPass ? 'status-badge--pass' : 'status-badge--fail' ?>">
                                    <?= $isPass
                                        ? '<i class="bi bi-check-circle-fill me-1"></i>บรรลุเป้าหมาย'
                                        : '<i class="bi bi-x-circle-fill me-1"></i>ต่ำกว่าเป้าหมาย' ?>
                                </span>
                            </td>
                            <td class="text-center" style="vertical-align:middle;">
                                <div class="progress-bar-wrap">
                                    <div class="progress-bar-fill <?= $isPass ? 'progress-bar-fill--pass' : 'progress-bar-fill--fail' ?>"
                                         style="width:<?= min(100,$progressVal) ?>%"></div>
                                </div>
                            </td>
                            <td class="text-center" onclick="event.stopPropagation()">
                                <a href="kpi_detail.php?id=<?= $kpi_id ?>"
                                   class="btn btn-sm btn-outline-primary rounded-pill px-2"
                                   title="ดูรายละเอียด">
                                    <i class="bi bi-bar-chart-line-fill"></i>
                                </a>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="js/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    createStatusKpiChart('barChart',
        <?= json_encode($kpiData['kpiname']) ?>,
        <?= json_encode($kpiData['results']) ?>,
        <?= json_encode($kpiData['targets']) ?>,
        <?= json_encode($kpiData['operator']) ?>
    );
    createDoughnutChart('doughnutChart',
        ['ผ่านเกณฑ์', 'ต่ำกว่าเป้าหมาย'],
        [<?= $summary['pass'] ?>, <?= $summary['fail'] ?>]
    );
    const searchInput = document.getElementById('tableSearch');
    const rows = document.querySelectorAll('#kpiTable tbody tr');
    searchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        rows.forEach(row => { row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none'; });
    });
});
</script>
</body>
</html>