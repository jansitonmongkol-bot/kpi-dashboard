<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/KPI.php';
include '../classes/Auth.php';

Auth::requireAdmin();

// สร้างตาราง kpi_highlight_config ถ้ายังไม่มี
$conn->query("
    CREATE TABLE IF NOT EXISTS kpi_highlight_config (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        kpi_index  INT NOT NULL DEFAULT 0,
        icon       VARCHAR(60) NOT NULL DEFAULT 'bi-activity',
        color      VARCHAR(20) NOT NULL DEFAULT 'teal',
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$message = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->query("DELETE FROM kpi_highlight_config");
    $stmt = $conn->prepare("INSERT INTO kpi_highlight_config (kpi_index, icon, color, sort_order) VALUES (?,?,?,?)");

    $icons  = $_POST['icon']      ?? [];
    $colors = $_POST['color']     ?? [];
    $indices = $_POST['kpi_index'] ?? [];

    foreach ($indices as $order => $kpi_index) {
        $icon  = $icons[$order]  ?? 'bi-activity';
        $color = $colors[$order] ?? 'teal';
        $idx   = (int)$kpi_index;
        $sortOrder = $order + 1;
        $stmt->bind_param('issi', $idx, $icon, $color, $sortOrder);
        $stmt->execute();
    }
    $message = 'บันทึกการตั้งค่า Highlight Cards สำเร็จ';
}

// ดึงข้อมูล KPI list
$kpiData = getKpiDataFromDB($conn);
$highlightConfig = getHighlightConfig($conn);

// Icon options
$iconOptions = [
    'bi-activity'          => 'Activity',
    'bi-heart-pulse-fill'  => 'Heart Pulse',
    'bi-droplet-half'      => 'Droplet',
    'bi-search-heart'      => 'Search Heart',
    'bi-lungs-fill'        => 'Lungs',
    'bi-person-heart'      => 'Person Heart',
    'bi-clipboard2-pulse'  => 'Clipboard Pulse',
    'bi-graph-up-arrow'    => 'Graph Up',
    'bi-graph-down-arrow'  => 'Graph Down',
    'bi-thermometer-half'  => 'Thermometer',
    'bi-hospital'          => 'Hospital',
    'bi-capsule'           => 'Capsule',
    'bi-bandaid-fill'      => 'Bandaid',
    'bi-shield-plus'       => 'Shield Plus',
    'bi-people-fill'       => 'People',
];

$colorOptions = [
    'teal'   => 'Teal (เขียวน้ำทะเล)',
    'blue'   => 'Blue (น้ำเงิน)',
    'orange' => 'Orange (ส้ม)',
    'purple' => 'Purple (ม่วง)',
    'red'    => 'Red (แดง)',
    'green'  => 'Green (เขียว)',
];

$activeMenu = 'highlight';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่า Highlight Cards — Admin</title>
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
            <h4 class="page-title"><i class="bi bi-stars me-2 text-warning"></i>ตั้งค่า Highlight Cards</h4>
            <p class="page-subtitle">เลือกตัวชี้วัด 3 รายการที่ต้องการแสดงโดดเด่นบนหน้า Dashboard</p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Preview -->
    <div class="chart-card mb-4">
        <div class="chart-card__header">
            <h6 class="chart-card__title"><i class="bi bi-eye me-2"></i>ตัวอย่างการแสดงผล (Preview)</h6>
        </div>
        <div class="chart-card__body">
            <div class="row g-3" id="previewArea">
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
                            <span>เป้าหมาย <?= $op ?> <?= $tgt ?>%</span>
                            <span class="badge <?= $pass ? 'badge-pass' : 'badge-fail' ?>">
                                <?= $pass ? '✓ บรรลุ' : '✗ ต่ำกว่าเป้า' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Configuration Form -->
    <div class="chart-card mb-5">
        <div class="chart-card__header">
            <h6 class="chart-card__title"><i class="bi bi-gear-fill me-2"></i>ตั้งค่า Highlight Card ทั้ง 3 ช่อง</h6>
        </div>
        <div class="chart-card__body">
            <form method="POST">
                <div class="row g-4">
                <?php
define('BASE_PATH', '..');
                $cardLabels = ['Card 1 (ซ้าย)', 'Card 2 (กลาง)', 'Card 3 (ขวา)'];
                for ($c = 0; $c < 3; $c++):
                    $cur = $highlightConfig[$c] ?? ['kpi_index' => $c, 'icon' => 'bi-activity', 'color' => 'teal'];
                ?>
                <div class="col-md-4">
                    <div class="highlight-config-card">
                        <div class="highlight-config-label"><?= $cardLabels[$c] ?></div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">ตัวชี้วัด (KPI)</label>
                            <select name="kpi_index[]" class="form-select form-select-sm">
                                <?php foreach ($kpiData['kpiname'] as $idx => $name): ?>
                                <option value="<?= $idx ?>" <?= $cur['kpi_index'] === $idx ? 'selected' : '' ?>>
                                    <?= str_pad($kpiData['kpicode'][$idx], 3, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars(mb_substr($name, 0, 40)) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">ไอคอน</label>
                            <select name="icon[]" class="form-select form-select-sm icon-select">
                                <?php foreach ($iconOptions as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $cur['icon'] === $val ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-0">
                            <label class="form-label fw-semibold small">สีธีม</label>
                            <select name="color[]" class="form-select form-select-sm">
                                <?php foreach ($colorOptions as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $cur['color'] === $val ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
                </div>

                <div class="mt-4 text-end">
                    <a href="../index.php" class="btn btn-light rounded-pill px-4 me-2">
                        <i class="bi bi-eye me-1"></i>ดูหน้า Dashboard
                    </a>
                    <button type="submit" class="btn btn-primary rounded-pill px-5">
                        <i class="bi bi-save me-1"></i>บันทึกการตั้งค่า
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>