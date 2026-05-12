<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/KPI.php';
include '../classes/Auth.php';

Auth::requireLogin();
if (Auth::isAdmin()) { header('Location: ' . authUrl('admin/index.php')); exit; }

$userId    = $_SESSION['user_id'];
$requestId = (int)($_GET['request_id'] ?? 0);

// ดึง request + KPI info
$reqRow = null;
if ($requestId) {
    $stmt = $conn->prepare("
        SELECT r.*, k.kpi_code, k.kpi_name, k.target_value, k.target_operator, k.kpi_id AS kid
        FROM kpi_requests r
        INNER JOIN kpis k ON r.kpi_id = k.kpi_id
        WHERE r.id=? AND r.user_id=? AND r.status='approved'
    ");
    $stmt->bind_param('ii', $requestId, $userId);
    $stmt->execute();
    $reqRow = $stmt->get_result()->fetch_assoc();
}

if (!$reqRow) {
    echo '<div style="font-family:sans-serif;padding:40px;">
        <h3>⚠️ ไม่พบข้อมูล</h3>
        <a href="' . authUrl('user/request_kpi.php') . '">← กลับ</a>
    </div>';
    exit;
}

// ดึงข้อมูล rows ทั้งหมดของ KPI นี้ของ user นี้
$dataRows = [];
$res = $conn->query("
    SELECT dr.*
    FROM kpi_data_rows dr
    INNER JOIN kpi_submissions s ON dr.submission_id = s.id
    WHERE dr.kpi_id = {$reqRow['kid']} AND dr.user_id = $userId
    ORDER BY COALESCE(dr.period_date, dr.created_at) ASC
");
if ($res) { while ($r = $res->fetch_assoc()) { $dataRows[] = $r; } }

// ---- คำนวณ Summary ----
$totalRows   = count($dataRows);
$avgActual   = 0;
$maxActual   = null;
$minActual   = null;
$passCount   = 0;
$latestVal   = null;
$trendDir    = 'stable';

if ($totalRows > 0) {
    $values = array_filter(array_column($dataRows, 'actual_value'), fn($v) => $v !== null);
    if (!empty($values)) {
        $avgActual = round(array_sum($values) / count($values), 2);
        $maxActual = max($values);
        $minActual = min($values);
        $latestVal = end($values);

        foreach ($values as $v) {
            if (evaluateKpi($v, $reqRow['target_value'], $reqRow['target_operator'])) $passCount++;
        }

        // Trend: เปรียบเทียบครึ่งหลังกับครึ่งแรก
        $half = (int)(count($values) / 2);
        if ($half > 0) {
            $firstHalf  = array_slice(array_values($values), 0, $half);
            $secondHalf = array_slice(array_values($values), $half);
            $avgFirst   = array_sum($firstHalf) / count($firstHalf);
            $avgSecond  = array_sum($secondHalf) / count($secondHalf);
            $diff = $avgSecond - $avgFirst;
            if (abs($diff) < 1) $trendDir = 'stable';
            elseif ($diff > 0)  $trendDir = 'up';
            else                $trendDir = 'down';
        }
    }
}

$target   = (float)$reqRow['target_value'];
$operator = $reqRow['target_operator'];
$passRate = $totalRows > 0 ? round($passCount / $totalRows * 100) : 0;
$latestPass = $latestVal !== null ? evaluateKpi($latestVal, $target, $operator) : null;

// JSON สำหรับ Chart.js
$chartLabels  = array_map(fn($r) => $r['period_label'] ?: date('d/m/Y', strtotime($r['created_at'])), $dataRows);
$chartValues  = array_map(fn($r) => round((float)$r['actual_value'], 2), $dataRows);
$chartNums    = array_map(fn($r) => (float)$r['numerator'], $dataRows);
$chartDens    = array_map(fn($r) => (float)$r['denominator'], $dataRows);
$chartTargets = array_fill(0, $totalRows, $target);
$barColors    = array_map(fn($v) => evaluateKpi($v, $target, $operator) ? '#16a34a' : '#dc2626', $chartValues);

$activeMenu = 'request';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= htmlspecialchars($reqRow['kpi_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/user.css">
</head>
<body>
<?php include '../includes/Navbar.php'; ?>

<div class="container-fluid px-4">

    <!-- Header -->
    <div class="page-header mb-4">
        <div>
            <h4 class="page-title">
                <i class="bi bi-bar-chart-line-fill me-2 text-primary"></i>
                Dashboard ข้อมูล KPI
            </h4>
            <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                <span class="kpi-code"><?= str_pad($reqRow['kpi_code'],3,'0',STR_PAD_LEFT) ?></span>
                <span class="page-subtitle mb-0"><?= htmlspecialchars($reqRow['kpi_name']) ?></span>
                <span class="badge bg-primary">เป้าหมาย <?= htmlspecialchars($operator) ?> <?= $target ?>%</span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= authUrl('user/upload_data.php') ?>?request_id=<?= $requestId ?>"
               class="btn btn-outline-success rounded-pill px-3">
                <i class="bi bi-upload me-1"></i>Upload ข้อมูลเพิ่ม
            </a>
            <a href="<?= authUrl('user/my_data.php') ?>" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="bi bi-grid-3x3-gap me-1"></i>ข้อมูลทั้งหมด
            </a>
        </div>
    </div>

    <?php if ($totalRows === 0): ?>
    <div class="chart-card mb-5">
        <div class="chart-card__body">
            <div class="empty-state py-5">
                <i class="bi bi-bar-chart-line" style="font-size:3rem;color:#94a3b8;"></i>
                <h5 class="mt-3">ยังไม่มีข้อมูล</h5>
                <p class="text-muted">กรุณา Upload ไฟล์ Excel ก่อน</p>
                <a href="<?= authUrl('user/upload_data.php') ?>?request_id=<?= $requestId ?>"
                   class="btn btn-success rounded-pill px-4 mt-2">
                    <i class="bi bi-upload me-2"></i>Upload ข้อมูล
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- ===== SUMMARY STAT CARDS ===== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--total">
                <div class="stat-icon"><i class="bi bi-calendar3"></i></div>
                <div class="stat-value"><?= $totalRows ?></div>
                <div class="stat-label">ช่วงเวลาทั้งหมด</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card <?= $latestPass ? 'stat-card--pass' : 'stat-card--fail' ?>">
                <div class="stat-icon"><i class="bi bi-bullseye"></i></div>
                <div class="stat-value"><?= $latestVal !== null ? number_format($latestVal, 1).'%' : '—' ?></div>
                <div class="stat-label">ผลงานล่าสุด</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--rate">
                <div class="stat-icon"><i class="bi bi-calculator"></i></div>
                <div class="stat-value"><?= number_format($avgActual, 1) ?>%</div>
                <div class="stat-label">ค่าเฉลี่ยรวม</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card <?= $passCount > 0 ? 'stat-card--pass' : 'stat-card--fail' ?>">
                <div class="stat-icon"><i class="bi bi-patch-check-fill"></i></div>
                <div class="stat-value"><?= $passCount ?>/<?= $totalRows ?></div>
                <div class="stat-label">ช่วงที่บรรลุเป้า</div>
            </div>
        </div>
    </div>

    <!-- ===== SUMMARY INFO BAR ===== -->
    <div class="summary-bar mb-4">
        <div class="summary-bar__item">
            <span class="summary-bar__label">สูงสุด</span>
            <span class="summary-bar__val text-success fw-bold"><?= number_format($maxActual, 2) ?>%</span>
        </div>
        <div class="summary-bar__divider"></div>
        <div class="summary-bar__item">
            <span class="summary-bar__label">ต่ำสุด</span>
            <span class="summary-bar__val text-danger fw-bold"><?= number_format($minActual, 2) ?>%</span>
        </div>
        <div class="summary-bar__divider"></div>
        <div class="summary-bar__item">
            <span class="summary-bar__label">เป้าหมาย</span>
            <span class="summary-bar__val text-primary fw-bold"><?= htmlspecialchars($operator) ?> <?= $target ?>%</span>
        </div>
        <div class="summary-bar__divider"></div>
        <div class="summary-bar__item">
            <span class="summary-bar__label">อัตราผ่านเกณฑ์</span>
            <span class="summary-bar__val <?= $passRate >= 50 ? 'text-success' : 'text-danger' ?> fw-bold"><?= $passRate ?>%</span>
        </div>
        <div class="summary-bar__divider"></div>
        <div class="summary-bar__item">
            <span class="summary-bar__label">แนวโน้ม</span>
            <?php if ($trendDir === 'up'): ?>
            <span class="summary-bar__val text-success fw-bold"><i class="bi bi-graph-up-arrow"></i> เพิ่มขึ้น</span>
            <?php elseif ($trendDir === 'down'): ?>
            <span class="summary-bar__val text-danger fw-bold"><i class="bi bi-graph-down-arrow"></i> ลดลง</span>
            <?php else: ?>
            <span class="summary-bar__val text-muted fw-bold"><i class="bi bi-dash"></i> คงที่</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== CHARTS ===== -->
    <div class="row g-3 mb-4">
        <!-- Line Chart -->
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title">
                        <i class="bi bi-graph-up me-2"></i>แนวโน้มผลงานรายช่วงเวลา
                    </h6>
                    <div class="legend-badges">
                        <span class="legend-dot legend-dot--pass"></span><span class="small">บรรลุเป้า</span>
                        <span class="legend-dot legend-dot--fail ms-3"></span><span class="small">ต่ำกว่าเป้า</span>
                    </div>
                </div>
                <div class="chart-card__body" style="height:320px;">
                    <canvas id="lineChart"></canvas>
                </div>
            </div>
        </div>
        <!-- Donut: pass rate -->
        <div class="col-lg-4">
            <div class="chart-card h-100">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-pie-chart-fill me-2"></i>สัดส่วนผ่านเกณฑ์</h6>
                </div>
                <div class="chart-card__body d-flex align-items-center justify-content-center" style="height:320px;">
                    <div style="width:200px;height:200px;position:relative;">
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

    <!-- Bar Chart -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title">
                        <i class="bi bi-bar-chart-fill me-2"></i>ผลงานรายช่วงเวลาเทียบเป้าหมาย
                    </h6>
                </div>
                <div class="chart-card__body" style="height:280px;">
                    <canvas id="barChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== DATA TABLE ===== -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-table me-2"></i>ตารางข้อมูลทั้งหมด</h6>
                    <div class="d-flex gap-2">
                        <div class="input-group input-group-sm" style="width:180px;">
                            <span class="input-group-text bg-transparent border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" id="tableSearch" class="form-control border-start-0" placeholder="ค้นหา...">
                        </div>
                        <button class="btn btn-sm btn-outline-success rounded-pill px-3" onclick="exportCSV()">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table kpi-table mb-0" id="dataTable">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>ช่วงเวลา</th>
                                <th class="text-center">วันที่</th>
                                <th class="text-center">ตัวเศษ</th>
                                <th class="text-center">ตัวส่วน</th>
                                <th class="text-center">ผลงาน (%)</th>
                                <th class="text-center">สถานะ</th>
                                <th>หมายเหตุ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dataRows as $i => $row):
                            $av     = (float)$row['actual_value'];
                            $isPass = evaluateKpi($av, $target, $operator);
                        ?>
                        <tr class="kpi-row <?= $isPass ? 'kpi-row--pass' : 'kpi-row--fail' ?>">
                            <td class="text-muted small"><?= $i+1 ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($row['period_label'] ?: '—') ?></td>
                            <td class="text-center text-muted small">
                                <?= $row['period_date'] ? date('d/m/Y', strtotime($row['period_date'])) : '—' ?>
                            </td>
                            <td class="text-center"><?= $row['numerator'] !== null ? number_format($row['numerator'], 0) : '—' ?></td>
                            <td class="text-center"><?= $row['denominator'] !== null ? number_format($row['denominator'], 0) : '—' ?></td>
                            <td class="text-center">
                                <span class="result-val <?= $isPass ? 'result-val--pass' : 'result-val--fail' ?>">
                                    <?= number_format($av, 2) ?>%
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="status-badge <?= $isPass ? 'status-badge--pass' : 'status-badge--fail' ?>">
                                    <?= $isPass
                                        ? '<i class="bi bi-check-circle-fill me-1"></i>บรรลุ'
                                        : '<i class="bi bi-x-circle-fill me-1"></i>ต่ำกว่าเป้า' ?>
                                </span>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars($row['note'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="5" class="text-end">รวม / เฉลี่ย</td>
                                <td class="text-center text-primary"><?= number_format($avgActual, 2) ?>%</td>
                                <td class="text-center">
                                    <?= $passCount ?>/<?= $totalRows ?> ผ่าน
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels  = <?= json_encode($chartLabels) ?>;
const values  = <?= json_encode($chartValues) ?>;
const targets = <?= json_encode($chartTargets) ?>;
const colors  = <?= json_encode($barColors) ?>;
const nums    = <?= json_encode($chartNums) ?>;
const dens    = <?= json_encode($chartDens) ?>;
const target  = <?= json_encode($target) ?>;
const passCount = <?= json_encode($passCount) ?>;
const failCount = <?= json_encode($totalRows - $passCount) ?>;
const fontFam = 'Sarabun, sans-serif';

// ---- LINE CHART ----
new Chart(document.getElementById('lineChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [
            {
                label: 'ผลงานจริง (%)',
                data: values,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,0.08)',
                borderWidth: 2.5,
                pointBackgroundColor: colors,
                pointRadius: 5,
                pointHoverRadius: 7,
                fill: true,
                tension: 0.35,
            },
            {
                label: 'เป้าหมาย',
                data: targets,
                borderColor: 'rgba(100,116,139,0.5)',
                borderWidth: 1.5,
                borderDash: [6, 4],
                pointRadius: 0,
                fill: false,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', align: 'end', labels: { font: { family: fontFam, size: 12 } } },
            tooltip: {
                titleFont: { family: fontFam },
                bodyFont:  { family: fontFam },
                callbacks: {
                    label: ctx => {
                        if (ctx.datasetIndex === 0) {
                            const i = ctx.dataIndex;
                            const pass = values[i] >= target;
                            return ` ผลงาน: ${ctx.parsed.y.toFixed(2)}%`;
                        }
                        return ` เป้าหมาย: ${ctx.parsed.y}%`;
                    },
                    afterBody: ctx => {
                        const i = ctx[0].dataIndex;
                        const n = nums[i], d = dens[i];
                        if (n || d) return [`ตัวเศษ: ${n}`, `ตัวส่วน: ${d}`];
                        return [];
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: false,
                ticks: { font: { family: fontFam, size: 11 }, callback: v => v + '%' },
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            x: {
                ticks: { font: { family: fontFam, size: 11 }, maxRotation: 45 },
                grid: { display: false }
            }
        }
    }
});

// ---- BAR CHART ----
new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [
            {
                label: 'ผลงานจริง (%)',
                data: values,
                backgroundColor: colors,
                borderRadius: 6,
                barThickness: 28,
                order: 1,
            },
            {
                type: 'line',
                label: 'เป้าหมาย',
                data: targets,
                borderColor: 'rgba(100,116,139,0.6)',
                borderWidth: 1.5,
                borderDash: [5, 4],
                pointRadius: 0,
                fill: false,
                order: 0,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                titleFont: { family: fontFam },
                bodyFont:  { family: fontFam },
                callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)}%` }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                max: Math.min(100, Math.max(...values, target) + 15),
                ticks: { font: { family: fontFam, size: 11 }, callback: v => v + '%' },
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            x: {
                ticks: {
                    font: { family: fontFam, size: 10 },
                    maxRotation: 45,
                    callback: function(val) {
                        const l = this.getLabelForValue(val);
                        return l.length > 12 ? l.substr(0, 12) + '…' : l;
                    }
                },
                grid: { display: false }
            }
        }
    }
});

// ---- DOUGHNUT ----
new Chart(document.getElementById('doughnutChart'), {
    type: 'doughnut',
    data: {
        labels: ['ผ่านเกณฑ์', 'ต่ำกว่าเป้า'],
        datasets: [{
            data: [passCount, failCount],
            backgroundColor: ['#16a34a', '#dc2626'],
            borderWidth: 3,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { font: { family: fontFam, size: 11 }, usePointStyle: true, padding: 12 }
            },
            tooltip: {
                titleFont: { family: fontFam },
                bodyFont:  { family: fontFam },
                callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed} ช่วง` }
            }
        }
    }
});

// ---- SEARCH ----
document.getElementById('tableSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#dataTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// ---- EXPORT CSV ----
function exportCSV() {
    const rows = [['#','ช่วงเวลา','วันที่','ตัวเศษ','ตัวส่วน','ผลงาน(%)','สถานะ','หมายเหตุ']];
    document.querySelectorAll('#dataTable tbody tr').forEach((tr, i) => {
        const cells = tr.querySelectorAll('td');
        rows.push([...cells].map(td => '"' + td.innerText.trim().replace(/"/g,'""') + '"'));
    });
    const csv  = rows.map(r => r.join(',')).join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = 'kpi_<?= $reqRow['kpi_code'] ?>_data.csv';
    a.click();
}
</script>
</body>
</html>
