<?php
define('BASE_PATH', '');
include 'classes/Database.php';
include 'classes/KPI.php';
include 'classes/Auth.php';

// ไม่บังคับ login — ทุกคนดูได้

$kpiId = (int)($_GET['id'] ?? 0);
if (!$kpiId) {
    header('Location: ' . authUrl('index.php'));
    exit;
}

// ── ดึงข้อมูล KPI หลัก ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM kpis WHERE kpi_id = ?");
$stmt->bind_param('i', $kpiId);
$stmt->execute();
$kpi = $stmt->get_result()->fetch_assoc();

if (!$kpi) {
    header('Location: ' . authUrl('index.php'));
    exit;
}

// ── Migrate: เพิ่ม column ใหม่ใน kpi_query_config ถ้ายังไม่มี ──
$_dd_migrations = [
    "ALTER TABLE kpi_query_config ADD COLUMN district_sql        TEXT",
    "ALTER TABLE kpi_query_config ADD COLUMN district_db         ENUM('kpi','hdc') DEFAULT 'kpi'",
    "ALTER TABLE kpi_query_config ADD COLUMN district_label_col  VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN hospital_sql        TEXT",
    "ALTER TABLE kpi_query_config ADD COLUMN hospital_db         ENUM('kpi','hdc') DEFAULT 'kpi'",
    "ALTER TABLE kpi_query_config ADD COLUMN hospital_label_col  VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN value_col           VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN pass_rate_col       VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN bar_x_col           VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN bar_y_cols          TEXT DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN trend_x_col         VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN trend_y_cols        TEXT DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN trend_sql            TEXT",
    "ALTER TABLE kpi_query_config ADD COLUMN trend_db             ENUM('kpi','hdc') DEFAULT 'kpi'",
];
foreach ($_dd_migrations as $_s) {
    try { $conn->query($_s); } catch (mysqli_sql_exception $e) { /* already exists */ }
}
unset($_dd_migrations, $_s);

// ── ดึง Admin Config (SQL + Column Headers) ──────────────────────
$queryConfig   = null;
$columnConfigs = [];
$useCustomSql  = false;
$customRawRows = [];    // raw assoc rows จาก custom SQL
$customColumns = [];    // column keys จาก custom SQL result

// ตรวจว่ามีตาราง config หรือยัง
$tblCheck = $conn->query("SHOW TABLES LIKE 'kpi_query_config'");
if ($tblCheck && $tblCheck->num_rows > 0) {
    $qRes = $conn->query("SELECT * FROM kpi_query_config WHERE kpi_id=$kpiId AND is_active=1 LIMIT 1");
    if ($qRes) $queryConfig = $qRes->fetch_assoc();
}
$tblCheck2 = $conn->query("SHOW TABLES LIKE 'kpi_column_config'");
if ($tblCheck2 && $tblCheck2->num_rows > 0) {
    $cRes = $conn->query("SELECT * FROM kpi_column_config WHERE kpi_id=$kpiId ORDER BY col_order ASC");
    if ($cRes) while ($r = $cRes->fetch_assoc()) $columnConfigs[] = $r;
}

// ── รัน Custom SQL ถ้ามี config ──────────────────────────────────
// ── AJAX: ดึงข้อมูล drilldown (district/hospital) ──────────────
if (($_GET['action'] ?? '') === 'drilldown') {
    header('Content-Type: application/json; charset=utf-8');
    $dtype = $_GET['type'] ?? 'district'; // 'district' | 'hospital'
    if (!$queryConfig) { echo json_encode(['ok'=>false,'error'=>'ไม่มี config']); exit; }
    $sqlKey = $dtype === 'hospital' ? 'hospital_sql' : 'district_sql';
    $dbKey  = $dtype === 'hospital' ? 'hospital_db'  : 'district_db';
    $lblKey = $dtype === 'hospital' ? 'hospital_label_col' : 'district_label_col';
    $rawSql = trim($queryConfig[$sqlKey] ?? '');
    if (!$rawSql) { echo json_encode(['ok'=>false,'error'=>"ไม่มี $sqlKey"]); exit; }
    $rawSql = str_replace(':kpi_id', $kpiId, $rawSql);
    $db = ($queryConfig[$dbKey] ?? 'kpi') === 'hdc' ? ($con ?? $conn) : $conn;
    $res = $db->query($rawSql);
    if (!$res) { echo json_encode(['ok'=>false,'error'=>$db->error]); exit; }
    $rows = []; $cols = [];
    if ($res->num_rows > 0) {
        $first = $res->fetch_assoc(); $cols = array_keys($first);
        $rows[] = $first;
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    echo json_encode([
        'ok'        => true,
        'rows'      => $rows,
        'columns'   => $cols,
        'label_col' => $queryConfig[$lblKey] ?? ($cols[0] ?? ''),
        'type'      => $dtype,
    ]);
    exit;
}

if ($queryConfig) {
    $rawSql  = str_replace(':kpi_id', $kpiId, $queryConfig['query_sql']);
    $db      = ($queryConfig['db_source'] === 'hdc') ? $con : $conn;
    $sqlRes  = $db->query($rawSql);
    if ($sqlRes && $sqlRes->num_rows > 0) {
        $first = $sqlRes->fetch_assoc();
        $customColumns = array_keys($first);
        $customRawRows[] = $first;
        while ($r = $sqlRes->fetch_assoc()) $customRawRows[] = $r;
        $useCustomSql = true;
    } elseif ($sqlRes) {
        // SQL สำเร็จแต่ไม่มีข้อมูล
        $useCustomSql = true;   // ยังคงใช้ custom mode (แสดง empty state)
    }
}

// ── ถ้าไม่มี custom SQL ให้ใช้ default logic ────────────────────
$histRows = [];
$userRows = [];

if (!$useCustomSql) {
    // Default: ดึง kpi_results
    $res = $conn->query("
        SELECT actual_value, last_updated
        FROM kpi_results WHERE kpi_id=$kpiId ORDER BY last_updated ASC
    ");
    if ($res) while ($row = $res->fetch_assoc()) $histRows[] = $row;

    // Default: ดึง kpi_data_rows
    $tc = $conn->query("SHOW TABLES LIKE 'kpi_data_rows'");
    if ($tc && $tc->num_rows > 0) {
        $res2 = $conn->query("
            SELECT dr.period_label, dr.period_date, dr.numerator, dr.denominator,
                   dr.actual_value, dr.note, dr.created_at, u.fullname AS uploaded_by
            FROM kpi_data_rows dr
            INNER JOIN kpi_submissions s ON dr.submission_id = s.id
            LEFT JOIN users u ON dr.user_id = u.id
            WHERE dr.kpi_id = $kpiId
            ORDER BY COALESCE(dr.period_date, dr.created_at) ASC
        ");
        if ($res2) while ($row = $res2->fetch_assoc()) $userRows[] = $row;
    }
}

$hasUserData = !empty($userRows);

// ── สร้าง unified $displayRows ───────────────────────────────────
$displayRows = [];

if ($useCustomSql) {
    // Custom SQL mode: แต่ละ row คือ raw assoc array จาก SQL
    foreach ($customRawRows as $i => $r) {
        $displayRows[] = array_merge(['_no' => $i + 1], $r);
    }
} elseif ($hasUserData) {
    foreach ($userRows as $i => $r) {
        $displayRows[] = [
            '_no'         => $i + 1,
            'period'      => $r['period_label'] ?: ($r['period_date'] ? date('d/m/Y', strtotime($r['period_date'])) : '-'),
            'date'        => $r['period_date'] ? date('d/m/Y', strtotime($r['period_date'])) : '-',
            'numerator'   => $r['numerator'],
            'denominator' => $r['denominator'],
            'actual'      => (float)$r['actual_value'],
            'note'        => $r['note'] ?? '',
            'uploaded_by' => $r['uploaded_by'] ?? '',
        ];
    }
} else {
    foreach ($histRows as $i => $r) {
        $displayRows[] = [
            '_no'         => $i + 1,
            'period'      => date('d/m/Y H:i', strtotime($r['last_updated'])),
            'date'        => date('Y-m-d', strtotime($r['last_updated'])),
            'numerator'   => null,
            'denominator' => null,
            'actual'      => (float)$r['actual_value'],
            'note'        => '',
            'uploaded_by' => '',
        ];
    }
}

// ── ตรวจสอบ drilldown SQL ────────────────────────────────────────
$hasDistrictSql = !empty($queryConfig['district_sql'] ?? '');
$hasHospitalSql = !empty($queryConfig['hospital_sql'] ?? '');
$hasDrilldown   = $hasDistrictSql || $hasHospitalSql;

// ── อ่าน chart config จาก queryConfig ────────────────────────────
$cfgPassRateCol = $queryConfig['pass_rate_col'] ?? '';
$cfgValueCol    = $queryConfig['value_col']     ?? '';
$cfgBarXCol     = $queryConfig['bar_x_col']     ?? '';
$cfgBarYColsRaw = array_filter(array_map('trim', explode(',', $queryConfig['bar_y_cols']   ?? '')));
$cfgTrendXCol     = $queryConfig['trend_x_col']   ?? '';
$cfgTrendYColsRaw = array_filter(array_map('trim', explode(',', $queryConfig['trend_y_cols'] ?? '')));
$cfgTrendSql      = trim($queryConfig['trend_sql']  ?? '');
$cfgTrendDb       = $queryConfig['trend_db']        ?? 'kpi';

// ── รัน Trend SQL แยก (ถ้ามี) ─────────────────────────────────────
$trendRawRows = [];
$trendColumns = [];
$useTrendSql  = false;
if ($cfgTrendSql) {
    $_tSql = str_replace(':kpi_id', $kpiId, $cfgTrendSql);
    $_tDb  = ($cfgTrendDb === 'hdc') ? ($con ?? $conn) : $conn;
    $_tRes = $_tDb->query($_tSql);
    if ($_tRes && $_tRes->num_rows > 0) {
        $_first = $_tRes->fetch_assoc();
        $trendColumns   = array_keys($_first);
        $trendRawRows[] = $_first;
        while ($_r = $_tRes->fetch_assoc()) $trendRawRows[] = $_r;
        $useTrendSql = true;
    }
}

// ── เลือก column สำหรับแต่ละ metric ──────────────────────────────
// pass_rate_col → ใช้คำนวณ passRate, latestVal, latestPass
// bar_y_cols[0] → ใช้คำนวณ max, min, avg, distribution
// fallback ลำดับ: pass_rate_col > value_col > bar_y_col[0] > col_config > actual

function _pickCol($preferred, $fallbacks, $availableCols, $defaultKey) {
    if ($preferred && in_array($preferred, $availableCols)) return $preferred;
    foreach ($fallbacks as $fb) {
        if ($fb && in_array($fb, $availableCols)) return $fb;
    }
    return $defaultKey;
}

$target   = (float)$kpi['target_value'];
$operator = $kpi['target_operator'];
$total    = count($displayRows);

// คอลัมน์จาก column config (เดิม)
$legacyValueCol = null;
foreach ($columnConfigs as $cc) {
    if ($cc['is_value_col'] ?? false) { $legacyValueCol = $cc['col_key']; break; }
}
if (!$legacyValueCol) {
    $legacyValueCol = $useCustomSql ? ($customColumns[1] ?? $customColumns[0] ?? 'actual') : 'actual';
}

// ── หาคอลัมน์ตัวเลขแรกจาก custom SQL (ใช้เป็น fallback) ──────────
$firstNumericCol = 'actual';
if ($useCustomSql && !empty($customRawRows) && !empty($customColumns)) {
    $sampleRow = $customRawRows[0];
    foreach ($customColumns as $ck) {
        if (is_numeric($sampleRow[$ck] ?? '')) { $firstNumericCol = $ck; break; }
    }
}

// ── คอลัมน์สำหรับ Pass Rate (สัดส่วนผ่านเกณฑ์, latestVal, latestPass) ──
$passRateColKey = _pickCol(
    $cfgPassRateCol,
    [$cfgValueCol, $cfgBarYColsRaw[0] ?? '', $legacyValueCol, $firstNumericCol],
    $useCustomSql ? $customColumns : ['actual','period'],
    $firstNumericCol
);

// ── คอลัมน์สำหรับ Bar stats (max, min, avg, distribution) ──
$barStatColKey = _pickCol(
    $cfgBarYColsRaw[0] ?? '',
    [$cfgPassRateCol, $cfgValueCol, $legacyValueCol, $firstNumericCol],
    $useCustomSql ? $customColumns : ['actual','period'],
    $firstNumericCol
);

// ── สรุปสถิติจาก passRateColKey ──────────────────────────────────
$passValues = [];
foreach ($displayRows as $row) {
    if (isset($row[$passRateColKey]) && is_numeric($row[$passRateColKey])) {
        $passValues[] = (float)$row[$passRateColKey];
    } elseif (isset($row['actual']) && is_numeric($row['actual'])) {
        $passValues[] = (float)$row['actual'];
    } else {
        // หาค่าตัวเลขแรกที่พบในแถว
        $found = false;
        foreach ($row as $v) {
            if (is_numeric($v) && $v !== null) { $passValues[] = (float)$v; $found = true; break; }
        }
        if (!$found) $passValues[] = 0;
    }
}
$passCount  = 0;
foreach ($passValues as $v) { if (evaluateKpi($v, $target, $operator)) $passCount++; }
$failCount  = $total - $passCount;
$passRate   = $total > 0 ? round($passCount / $total * 100) : 0;

// ── ผลงานล่าสุด: ดึงจาก kpi_results โดยตรง ──────────────────────
$latestVal  = null;
$_lStmt = $conn->prepare("SELECT actual_value FROM kpi_results WHERE kpi_id = ? ORDER BY last_updated DESC LIMIT 1");
if ($_lStmt) {
    $_lStmt->bind_param('i', $kpiId);
    $_lStmt->execute();
    $_lRow = $_lStmt->get_result()->fetch_assoc();
    if ($_lRow) $latestVal = (float)$_lRow['actual_value'];
}
if ($latestVal === null) $latestVal = $total > 0 ? end($passValues) : null;
$latestPass = $latestVal !== null ? evaluateKpi($latestVal, $target, $operator) : null;

// ── สถิติ max/min/avg จาก barStatColKey ───────────────────────────
$statValues = [];
foreach ($displayRows as $row) {
    if (isset($row[$barStatColKey]) && is_numeric($row[$barStatColKey])) {
        $statValues[] = (float)$row[$barStatColKey];
    } elseif (isset($row['actual']) && is_numeric($row['actual'])) {
        $statValues[] = (float)$row['actual'];
    } else {
        $statValues[] = $passValues[count($statValues)] ?? 0;
    }
}
$avgVal = $total > 0 ? round(array_sum($statValues) / $total, 2) : 0;
$maxVal = $total > 0 ? max($statValues) : 0;
$minVal = $total > 0 ? min($statValues) : 0;

// ── $values ใช้สำหรับ trend regression (ใช้ passValues) ──────────
$values = $passValues;

// Trend: linear regression slope
$trendSlope = 0;
if ($total >= 2) {
    $n   = $total;
    $sumX = $sumY = $sumXY = $sumX2 = 0;
    foreach ($values as $xi => $yi) {
        $sumX  += $xi;
        $sumY  += $yi;
        $sumXY += $xi * $yi;
        $sumX2 += $xi * $xi;
    }
    $denom = ($n * $sumX2 - $sumX * $sumX);
    if ($denom != 0) $trendSlope = round(($n * $sumXY - $sumX * $sumY) / $denom, 4);
}
$trendDir = abs($trendSlope) < 0.5 ? 'stable' : ($trendSlope > 0 ? 'up' : 'down');

// ── Chart data ───────────────────────────────────────────────────

// ── Chart labels แกน X ──────────────────────────────────────────
// Bar Chart ใช้ cfgBarXCol, Trend Chart ใช้ cfgTrendXCol
function _getColValues($rows, $colKey, $fallback='period') {
    if ($colKey && !empty($rows) && array_key_exists($colKey, $rows[0])) {
        return array_column($rows, $colKey);
    }
    return array_column($rows, $fallback);
}

// Bar X labels
if ($useCustomSql && !empty($customRawRows)) {
    $barXKey   = ($cfgBarXCol && in_array($cfgBarXCol, $customColumns))
                    ? $cfgBarXCol : ($customColumns[0] ?? '');
    $barLabels = $barXKey ? array_column($customRawRows, $barXKey) : array_keys($customRawRows);
} else {
    $barLabels = array_column($displayRows, 'period');
}
if (empty($barLabels)) {
    $barLabels = array_map(fn($i) => 'แถว '.($i+1), array_keys($displayRows));
}

// Trend X labels — ใช้ trendRawRows ถ้ามี trend_sql แยก
if ($useTrendSql) {
    $trendXKey   = ($cfgTrendXCol && in_array($cfgTrendXCol, $trendColumns))
                    ? $cfgTrendXCol : ($trendColumns[0] ?? '');
    $trendLabels = $trendXKey ? array_column($trendRawRows, $trendXKey) : array_keys($trendRawRows);
} elseif ($useCustomSql && !empty($customRawRows)) {
    $trendXKey   = ($cfgTrendXCol && in_array($cfgTrendXCol, $customColumns))
                    ? $cfgTrendXCol : ($customColumns[0] ?? '');
    $trendLabels = $trendXKey ? array_column($customRawRows, $trendXKey) : array_keys($customRawRows);
} else {
    $trendLabels = array_column($displayRows, 'period');
}
if (empty($trendLabels)) {
    $trendLabels = $barLabels;
}

// chartLabels = ใช้ bar labels เป็นหลัก (สำหรับ doughnut/distribution)
$chartLabels  = $barLabels ?: $trendLabels ?: array_column($displayRows, 'period');
$chartValues  = $passValues;
$chartTargets = array_fill(0, $total, $target);
$barColors    = array_map(fn($v) => evaluateKpi($v, $target, $operator) ? '#16a34a' : '#dc2626', $passValues);

// ── Bar Chart datasets (ใช้ cfgBarYColsRaw) ──────────────────────
$barDatasets = [];
$palette = ['#2563eb','#16a34a','#d97706','#9333ea','#0891b2','#dc2626','#65a30d','#ea580c'];
if ($useCustomSql && !empty($cfgBarYColsRaw)) {
    foreach ($cfgBarYColsRaw as $ci => $colKey) {
        if (!in_array($colKey, $customColumns)) continue;
        $data = array_map(fn($r) => isset($r[$colKey]) ? (float)$r[$colKey] : null, $customRawRows);
        // ถ้าเป็น passRateCol ให้ใช้สี pass/fail
        $isPassCol = ($colKey === $passRateColKey || $colKey === $cfgPassRateCol);
        $colors = $isPassCol
            ? array_map(fn($v) => evaluateKpi($v ?? 0, $target, $operator) ? '#16a34a' : '#dc2626', $data)
            : array_fill(0, count($data), $palette[$ci % count($palette)]);
        $colLabel = $colKey;
        foreach ($columnConfigs as $cc) {
            if ($cc['col_key'] === $colKey) { $colLabel = $cc['col_label']; break; }
        }
        $barDatasets[] = ['key'=>$colKey,'label'=>$colLabel,'data'=>$data,'colors'=>$colors];
    }
}
if (empty($barDatasets)) {
    $barDatasets[] = ['key'=>'value','label'=>'ผลงานจริง (%)','data'=>$chartValues,'colors'=>$barColors];
}

// ── Trend Chart datasets ─────────────────────────────────────────
// ถ้ามี trend_sql แยก → ใช้ trendRawRows, ไม่งั้น fallback customRawRows
$trendDatasets = [];
$trendPalette  = ['#2563eb','#16a34a','#d97706','#9333ea','#0891b2','#dc2626'];
if ($useTrendSql && !empty($trendRawRows)) {
    $xKey  = ($cfgTrendXCol && in_array($cfgTrendXCol, $trendColumns)) ? $cfgTrendXCol : ($trendColumns[0] ?? '');
    // Y cols: ใช้ที่กำหนดไว้ หรือ auto-detect ตัวเลข
    $yCols = !empty($cfgTrendYColsRaw)
        ? array_values(array_filter($cfgTrendYColsRaw, fn($k) => in_array($k, $trendColumns) && $k !== $xKey))
        : array_values(array_filter($trendColumns, fn($k) => $k !== $xKey && is_numeric($trendRawRows[0][$k] ?? '')));
    foreach ($yCols as $ci => $colKey) {
        $data = array_map(fn($r) => isset($r[$colKey]) ? (float)$r[$colKey] : null, $trendRawRows);
        $colLabel = $colKey;
        foreach ($columnConfigs as $cc) {
            if ($cc['col_key'] === $colKey) { $colLabel = $cc['col_label']; break; }
        }
        $trendDatasets[] = ['key'=>$colKey,'label'=>$colLabel,'data'=>$data,'color'=>$trendPalette[$ci % count($trendPalette)]];
    }
} elseif ($useCustomSql && !empty($cfgTrendYColsRaw)) {
    foreach ($cfgTrendYColsRaw as $ci => $colKey) {
        if (!in_array($colKey, $customColumns)) continue;
        $data = array_map(fn($r) => isset($r[$colKey]) ? (float)$r[$colKey] : null, $customRawRows);
        $colLabel = $colKey;
        foreach ($columnConfigs as $cc) {
            if ($cc['col_key'] === $colKey) { $colLabel = $cc['col_label']; break; }
        }
        $trendDatasets[] = ['key'=>$colKey,'label'=>$colLabel,'data'=>$data,'color'=>$trendPalette[$ci % count($trendPalette)]];
    }
}
if (empty($trendDatasets)) {
    $trendDatasets[] = ['key'=>'value','label'=>'ผลงานจริง','data'=>$chartValues,'color'=>'#2563eb'];
}

// Trend line (linear regression) คำนวณจาก value column เสมอ
$interceptY = $total > 0 ? ($avgVal - $trendSlope * (($total - 1) / 2)) : 0;
$trendLine   = [];
for ($i = 0; $i < $total; $i++) {
    $trendLine[] = round($interceptY + $trendSlope * $i, 2);
}

// ── ดึง KPI list สำหรับ navigation ──────────────────────────────
$allKpis = [];
$navRes = $conn->query("
    SELECT k.kpi_id, k.kpi_code, k.kpi_name,
           r.actual_value,
           k.target_value, k.target_operator
    FROM kpis k
    LEFT JOIN kpi_results r ON k.kpi_id = r.kpi_id
    LEFT JOIN (SELECT kpi_id, MAX(last_updated) mx FROM kpi_results GROUP BY kpi_id) l
        ON r.kpi_id = l.kpi_id AND r.last_updated = l.mx
    GROUP BY k.kpi_id
    ORDER BY k.kpi_code ASC
");
if ($navRes) {
    while ($row = $navRes->fetch_assoc()) $allKpis[] = $row;
}

// Find prev/next
$currentIdx = -1;
foreach ($allKpis as $idx => $k) {
    if ((int)$k['kpi_id'] === $kpiId) { $currentIdx = $idx; break; }
}
$prevKpi = $currentIdx > 0 ? $allKpis[$currentIdx - 1] : null;
$nextKpi = $currentIdx < count($allKpis) - 1 ? $allKpis[$currentIdx + 1] : null;

$activeMenu = 'dashboard';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($kpi['kpi_name']) ?> — KPI Detail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,600;0,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/detail.css">
    <!-- Chart.js loaded here so inline chart code below can use it -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        /* ── Scrollable table with sticky header ── */
        .table-sticky-head thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            box-shadow: 0 1px 0 #e2e8f0;
        }
        .table-sticky-head tfoot td {
            position: sticky;
            bottom: 0;
            z-index: 1;
            background: #f8fafc;
            border-top: 2px solid #e2e8f0;
        }
        /* Keep scroll container border radius clean */
        #dataTableSection .table-responsive {
            border-radius: 0 0 var(--radius-card, 12px) var(--radius-card, 12px);
        }
        /* Make table section fill available width properly */
        #dataTableSection { overflow: hidden; }
    </style>
</head>
<body>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php include 'includes/Navbar.php'; ?>

<div class="container-fluid px-4">

    <!-- ===== BREADCRUMB + NAVIGATION ===== -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item">
                    <a href="<?= authUrl('index.php') ?>" class="text-decoration-none text-muted">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>
                <li class="breadcrumb-item active">รายละเอียด KPI</li>
            </ol>
        </nav>
        <div class="d-flex gap-2">
            <?php if ($prevKpi): ?>
            <a href="kpi_detail.php?id=<?= $prevKpi['kpi_id'] ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-chevron-left"></i> KPI ก่อนหน้า
            </a>
            <?php endif; ?>
            <?php if ($nextKpi): ?>
            <a href="kpi_detail.php?id=<?= $nextKpi['kpi_id'] ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
                KPI ถัดไป <i class="bi bi-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== KPI HEADER CARD ===== -->
    <div class="kpi-header-card mb-4">
        <div class="kpi-header-card__left">
            <div class="kpi-header-badge">
                <span class="kpi-code-lg"><?= str_pad($kpi['kpi_code'], 3, '0', STR_PAD_LEFT) ?></span>
            </div>
            <div>
                <h3 class="kpi-header-title"><?= htmlspecialchars($kpi['kpi_name']) ?></h3>
                <div class="kpi-header-meta">
                    <span class="meta-chip">
                        <i class="bi bi-bullseye me-1"></i>
                        เป้าหมาย <strong><?= htmlspecialchars($operator) ?> <?= $target ?>%</strong>
                    </span>
                    <span class="meta-chip <?= $latestPass === true ? 'meta-chip--pass' : ($latestPass === false ? 'meta-chip--fail' : 'meta-chip--neutral') ?>">
                        <?php if ($latestPass === true): ?>
                            <i class="bi bi-check-circle-fill me-1"></i>บรรลุเป้าหมาย
                        <?php elseif ($latestPass === false): ?>
                            <i class="bi bi-x-circle-fill me-1"></i>ต่ำกว่าเป้าหมาย
                        <?php else: ?>
                            <i class="bi bi-dash-circle me-1"></i>ยังไม่มีข้อมูล
                        <?php endif; ?>
                    </span>
                    <span class="meta-chip">
                        <i class="bi bi-database me-1"></i><?= $total ?> รายการ
                    </span>
                </div>
            </div>
        </div>

        <!-- Admin edit config shortcut -->
        <?php if (Auth::isAdmin()): ?>
        <div class="kpi-header-card__right">
            <a href="<?= authUrl('admin/kpi_data_editor.php') ?>?kpi_id=<?= $kpiId ?>"
               class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-pencil-square me-1"></i>แก้ไข Config
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($total === 0): ?>
    <!-- No data state -->
    <div class="chart-card mb-5">
        <div class="chart-card__body py-5 text-center">
            <i class="bi bi-bar-chart-line" style="font-size:3.5rem;color:#cbd5e1;"></i>
            <h5 class="mt-3 text-muted">ยังไม่มีข้อมูลสำหรับตัวชี้วัดนี้</h5>
            <?php if (Auth::isAdmin()): ?>
            <a href="<?= authUrl('admin/kpi_manage.php') ?>" class="btn btn-primary rounded-pill px-4 mt-2">
                <i class="bi bi-pencil-square me-1"></i>เพิ่มข้อมูล
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>

    <!-- ===== STAT CARDS ===== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--total">
                <div class="stat-icon"><i class="bi bi-calendar3"></i></div>
                <div class="stat-value"><?= $total ?></div>
                <div class="stat-label">จำนวนข้อมูล</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card <?= $latestPass ? 'stat-card--pass' : 'stat-card--fail' ?>">
                <div class="stat-icon"><i class="bi bi-bullseye"></i></div>
                <div class="stat-value"><?= $latestVal !== null ? number_format($latestVal, 2).'%' : '—' ?></div>
                <div class="stat-label">ผลงานล่าสุด<?php if (!empty($cfgPassRateCol)): ?> <small style="font-size:.62rem;opacity:.7">(<?= htmlspecialchars($cfgPassRateCol) ?>)</small><?php endif; ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card--rate">
                <div class="stat-icon"><i class="bi bi-calculator-fill"></i></div>
                <div class="stat-value"><?= number_format($avgVal, 2) ?>%</div>
                <div class="stat-label">ค่าเฉลี่ย<?php if (!empty($cfgBarYColsRaw[0])): ?> <small style="font-size:.62rem;opacity:.7">(<?= htmlspecialchars($cfgBarYColsRaw[0]) ?>)</small><?php endif; ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card <?= $passCount > 0 ? 'stat-card--pass' : 'stat-card--fail' ?>">
                <div class="stat-icon"><i class="bi bi-patch-check-fill"></i></div>
                <div class="stat-value"><?= $passCount ?>/<?= $total ?></div>
                <div class="stat-label">ผ่านเกณฑ์<?php if (!empty($cfgPassRateCol)): ?> <small style="font-size:.62rem;opacity:.7">(<?= htmlspecialchars($cfgPassRateCol) ?>)</small><?php endif; ?></div>
            </div>
        </div>
    </div>

    <!-- ===== MINI STATS ===== -->
    <?php
    // Label แสดงว่า stat แต่ละตัวมาจากคอลัมน์ไหน
    $barStatLabel  = !empty($cfgBarYColsRaw[0]) ? htmlspecialchars($cfgBarYColsRaw[0]) : null;
    $passStatLabel = !empty($cfgPassRateCol) ? htmlspecialchars($cfgPassRateCol) : null;
    ?>
    <!-- ===== MINI STATS ===== -->
    <div class="mini-stats-bar mb-4">

        <div class="mini-stat">
            <i class="bi bi-arrow-up-circle-fill text-success mini-stat__icon"></i>
            <div>
                <span class="mini-stat__label">สูงสุด<?php if ($barStatLabel): ?> <span class="badge bg-light text-secondary" style="font-size:.58rem"><?= $barStatLabel ?></span><?php endif; ?></span>
                <span class="mini-stat__val"><?= number_format($maxVal, 2) ?>%</span>
            </div>
        </div>

        <div class="mini-stat">
            <i class="bi bi-arrow-down-circle-fill text-danger mini-stat__icon"></i>
            <div>
                <span class="mini-stat__label">ต่ำสุด<?php if ($barStatLabel): ?> <span class="badge bg-light text-secondary" style="font-size:.58rem"><?= $barStatLabel ?></span><?php endif; ?></span>
                <span class="mini-stat__val"><?= number_format($minVal, 2) ?>%</span>
            </div>
        </div>

        <div class="mini-stat">
            <i class="bi bi-dash-circle text-muted mini-stat__icon"></i>
            <div>
                <span class="mini-stat__label">ส่วนเบี่ยงเบน<?php if ($passStatLabel): ?> <span class="badge bg-light text-secondary" style="font-size:.58rem"><?= $passStatLabel ?></span><?php endif; ?></span>
                <span class="mini-stat__val <?= ($latestVal !== null && $latestVal >= $target) ? 'text-success' : 'text-danger' ?>">
                    <?= $latestVal !== null ? (($latestVal >= $target) ? '+' : '') . number_format($latestVal - $target, 2) . '%' : '—' ?>
                </span>
            </div>
        </div>

        <div class="mini-stat">
            <i class="bi bi-graph-up text-primary mini-stat__icon"></i>
            <div>
                <span class="mini-stat__label">แนวโน้ม</span>
                <span class="mini-stat__val fw-bold <?= $trendDir === 'up' ? 'text-success' : ($trendDir === 'down' ? 'text-danger' : 'text-muted') ?>">
                    <?php if ($trendDir === 'up'): ?>
                        <i class="bi bi-graph-up-arrow"></i> เพิ่มขึ้น
                    <?php elseif ($trendDir === 'down'): ?>
                        <i class="bi bi-graph-down-arrow"></i> ลดลง
                    <?php else: ?>
                        <i class="bi bi-dash"></i> คงที่
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="mini-stat">
            <i class="bi bi-percent text-purple mini-stat__icon"></i>
            <div>
                <span class="mini-stat__label">อัตราผ่านเกณฑ์<?php if ($passStatLabel): ?> <span class="badge bg-light text-secondary" style="font-size:.58rem"><?= $passStatLabel ?></span><?php endif; ?></span>
                <span class="mini-stat__val fw-bold <?= $passRate >= 70 ? 'text-success' : ($passRate >= 50 ? 'text-warning' : 'text-danger') ?>">
                    <?= $passRate ?>%
                </span>
            </div>
        </div>

    </div>

    <!-- ===== DRILLDOWN FILTER (ถ้ามี SQL รายอำเภอ/รพ.) ===== -->
    <?php if ($hasDrilldown): ?>
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span class="fw-semibold small text-muted"><i class="bi bi-funnel me-1"></i>มุมมองข้อมูล:</span>
        <div class="btn-group btn-group-sm" id="viewModeBtns">
            <button type="button" class="btn btn-primary active" onclick="setViewMode('overview',this)">
                <i class="bi bi-bar-chart me-1"></i>ภาพรวม
            </button>
            <?php if ($hasDistrictSql): ?>
            <button type="button" class="btn btn-outline-primary" onclick="setViewMode('district',this)">
                <i class="bi bi-map me-1"></i>รายอำเภอ
            </button>
            <?php endif; ?>
            <?php if ($hasHospitalSql): ?>
            <button type="button" class="btn btn-outline-primary" onclick="setViewMode('hospital',this)">
                <i class="bi bi-hospital me-1"></i>รายโรงพยาบาล
            </button>
            <?php endif; ?>
        </div>
        <span id="drilldownLoading" class="spinner-border spinner-border-sm text-primary d-none ms-2"></span>
    </div>

    <!-- Drilldown panel (hidden by default) -->
    <div id="drilldownPanel" style="display:none" class="mb-4">
        <div class="chart-card">
            <div class="chart-card__header">
                <h6 class="chart-card__title" id="drilldownTitle">
                    <i class="bi bi-bar-chart-horizontal me-2"></i>ข้อมูลรายละเอียด
                </h6>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill"
                        onclick="setViewMode('overview',document.querySelector('#viewModeBtns .btn-primary'))">
                    <i class="bi bi-x me-1"></i>ปิด
                </button>
            </div>
            <div class="chart-card__body">
                <!-- Table view -->
                <div id="drilldownTableWrap" class="table-responsive" style="max-height:420px;overflow-y:auto"></div>
                <!-- Chart view -->
                <div style="height:360px;margin-top:1rem">
                    <canvas id="drilldownChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== CHARTS ===== -->
    <div class="row g-3 mb-4">

        <!-- BAR CHART -->
        <div class="col-lg-6">
            <div class="chart-card h-100">
                <div class="chart-card__header">
                    <h6 class="chart-card__title">
                        <i class="bi bi-bar-chart-fill me-2 text-primary"></i>ผลงานรายช่วงเวลา (Bar Chart)
                    </h6>
                    <div class="d-flex gap-1 align-items-center flex-wrap">
                        <?php if (!empty($cfgBarXCol)): ?>
                        <span class="badge bg-light text-muted border" style="font-size:.7rem">X: <?= htmlspecialchars($cfgBarXCol) ?></span>
                        <?php endif; ?>
                        <?php foreach ($cfgBarYColsRaw as $yc): ?>
                        <span class="badge bg-primary" style="font-size:.7rem">Y: <?= htmlspecialchars($yc) ?></span>
                        <?php endforeach; ?>
                        <span class="legend-dot legend-dot--pass ms-1"></span><span class="small me-1">บรรลุ</span>
                        <span class="legend-dot legend-dot--fail"></span><span class="small">ต่ำกว่าเป้า</span>
                    </div>
                </div>
                <div class="chart-card__body" style="height:320px;">
                    <canvas id="barChart"></canvas>
                </div>
            </div>
        </div>

        <!-- TREND CHART -->
        <div class="col-lg-6">
            <div class="chart-card h-100">
                <div class="chart-card__header">
                    <h6 class="chart-card__title">
                        <i class="bi bi-graph-up me-2 text-teal"></i>แนวโน้มการเปลี่ยนแปลง (Trend Line)
                    </h6>
                    <div class="d-flex gap-1 align-items-center flex-wrap">
                        <?php if (!empty($cfgTrendXCol)): ?>
                        <span class="badge bg-light text-muted border" style="font-size:.7rem">X: <?= htmlspecialchars($cfgTrendXCol) ?></span>
                        <?php endif; ?>
                        <?php foreach ($cfgTrendYColsRaw as $yc): ?>
                        <span class="badge bg-teal text-white" style="font-size:.7rem">Y: <?= htmlspecialchars($yc) ?></span>
                        <?php endforeach; ?>
                        <span class="trend-badge trend-badge--trend ms-1">Trend</span>
                        <span class="trend-badge trend-badge--target">เป้าหมาย</span>
                    </div>
                </div>
                <div class="chart-card__body" style="height:320px;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- PASS/FAIL DONUT -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="chart-card h-100">
                <div class="chart-card__header">
                    <h6 class="chart-card__title">
                        <i class="bi bi-pie-chart-fill me-2"></i>สัดส่วนผ่าน / ไม่ผ่านเกณฑ์
                    </h6>
                    <?php if (!empty($cfgPassRateCol)): ?>
                    <span class="badge bg-info text-dark" style="font-size:.72rem">
                        <i class="bi bi-columns-gap me-1"></i><?= htmlspecialchars($cfgPassRateCol) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="chart-card__body d-flex align-items-center justify-content-center" style="height:240px;">
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
        <div class="col-lg-8">
            <div class="chart-card h-100">
                <div class="chart-card__header">
                    <h6 class="chart-card__title">
                        <i class="bi bi-bar-chart-steps me-2"></i>การกระจายของผลงาน (Distribution)
                    </h6>
                    <?php if (!empty($cfgBarYColsRaw[0])): ?>
                    <span class="badge bg-success text-white" style="font-size:.72rem">
                        <i class="bi bi-bar-chart-fill me-1"></i><?= htmlspecialchars($cfgBarYColsRaw[0]) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="chart-card__body" style="height:240px;">
                    <canvas id="distChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== DATA TABLE ===== -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="chart-card" id="dataTableSection">
                <div class="chart-card__header flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h6 class="chart-card__title">
                            <i class="bi bi-table me-2"></i>ตารางข้อมูลรายละเอียด
                            <span class="badge bg-secondary ms-1"><?= $total ?> รายการ</span>
                        </h6>
                        <?php if ($useCustomSql): ?>
                        <span class="badge bg-info text-dark" title="ใช้ SQL จาก Admin Config">
                            <i class="bi bi-code-square me-1"></i>Custom SQL
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($columnConfigs)): ?>
                        <span class="badge bg-primary" title="หัวตารางจาก Admin Config">
                            <i class="bi bi-layout-three-columns me-1"></i><?= count($columnConfigs) ?> cols
                        </span>
                        <?php endif; ?>
                        <?php if (Auth::isAdmin()): ?>
                        <a href="<?= authUrl('admin/kpi_data_editor.php') ?>?kpi_id=<?= $kpiId ?>"
                           class="btn btn-xs btn-outline-warning rounded-pill"
                           style="font-size:0.75rem;padding:2px 10px;"
                           title="แก้ไข SQL & หัวตาราง">
                            <i class="bi bi-pencil-square me-1"></i>แก้ไข Config
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <div class="input-group input-group-sm" style="width:200px;">
                            <span class="input-group-text bg-transparent border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" id="tableSearch" class="form-control border-start-0"
                                   placeholder="ค้นหา...">
                        </div>
                        <?php if (Auth::check()): ?>
                        <div class="btn-group btn-group-sm">
                            <a href="export.php?id=<?= $kpiId ?>&type=excel"
                               class="btn btn-outline-success rounded-start-pill">
                                <i class="bi bi-file-earmark-excel-fill me-1"></i>Excel
                            </a>
                            <a href="export.php?id=<?= $kpiId ?>&type=pdf"
                               class="btn btn-outline-danger">
                                <i class="bi bi-file-earmark-pdf-fill me-1"></i>PDF
                            </a>
                            <button class="btn btn-outline-secondary rounded-end-pill" onclick="exportCSV()">
                                <i class="bi bi-filetype-csv me-1"></i>CSV
                            </button>
                        </div>
                        <?php else: ?>
                        <a href="<?= authUrl('login.php') ?>?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                           class="btn btn-sm btn-outline-primary rounded-pill">
                            <i class="bi bi-box-arrow-in-right me-1"></i>เข้าสู่ระบบเพื่อดาวน์โหลด
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive" style="max-height:480px;overflow-y:auto;">
                    <table class="table kpi-table mb-0 table-sticky-head" id="detailTable">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <?php if ($useCustomSql && !empty($columnConfigs)): ?>
                                    <!-- ── Custom column headers ── -->
                                    <?php foreach ($columnConfigs as $cc): if (!$cc['is_visible']) continue; ?>
                                    <th class="text-<?= $cc['col_align'] ?>"
                                        <?= $cc['col_width'] ? 'style="width:'.$cc['col_width'].'"' : '' ?>>
                                        <?= htmlspecialchars($cc['col_label']) ?>
                                        <?php if ($cc['is_value_col']): ?>
                                        <i class="bi bi-star-fill text-warning ms-1" style="font-size:.65rem;" title="คอลัมน์ค่าผลงาน"></i>
                                        <?php endif; ?>
                                    </th>
                                    <?php endforeach; ?>
                                    <!-- Status + diff always added -->
                                    <th class="text-center" style="width:90px;">เทียบเป้า</th>
                                    <th class="text-center" style="width:130px;">สถานะ</th>
                                <?php elseif ($useCustomSql && empty($columnConfigs)): ?>
                                    <!-- ── Custom SQL but no column config: show raw fields ── -->
                                    <?php foreach ($customColumns as $col): ?>
                                    <th><?= htmlspecialchars($col) ?></th>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- ── Default headers ── -->
                                    <th>ช่วงเวลา</th>
                                    <?php if ($hasUserData): ?>
                                    <th class="text-center">วันที่</th>
                                    <th class="text-center">ตัวเศษ</th>
                                    <th class="text-center">ตัวส่วน</th>
                                    <?php endif; ?>
                                    <th class="text-center">ผลงาน (%)</th>
                                    <th class="text-center">เทียบเป้าหมาย</th>
                                    <th class="text-center">สถานะ</th>
                                    <?php if ($hasUserData): ?><th>หมายเหตุ</th><?php endif; ?>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($displayRows as $ri => $row):
                            // หา actual value สำหรับเปรียบ target (ใช้ passRateColKey)
                            $actualVal = isset($row[$passRateColKey])
                                ? (float)$row[$passRateColKey]
                                : (isset($row['actual']) ? (float)$row['actual'] : 0);
                            $isPass = evaluateKpi($actualVal, $target, $operator);
                            $diff   = round($actualVal - $target, 2);
                        ?>
                        <tr class="kpi-row <?= $isPass ? 'kpi-row--pass' : 'kpi-row--fail' ?>">
                            <td class="text-muted small"><?= $ri + 1 ?></td>

                            <?php if ($useCustomSql && !empty($columnConfigs)): ?>
                                <!-- ── Custom columns rendering ── -->
                                <?php foreach ($columnConfigs as $cc):
                                    if (!$cc['is_visible']) continue;
                                    $cellVal = $row[$cc['col_key']] ?? '—';
                                    $align   = 'text-' . $cc['col_align'];
                                ?>
                                <td class="<?= $align ?>">
                                <?php switch ($cc['col_type']):
                                    case 'percent': ?>
                                        <span class="result-val <?= $cc['is_value_col'] ? ($isPass ? 'result-val--pass' : 'result-val--fail') : '' ?> fw-bold">
                                            <?= is_numeric($cellVal) ? number_format((float)$cellVal, 2).'%' : htmlspecialchars($cellVal) ?>
                                        </span>
                                    <?php break;
                                    case 'number': ?>
                                        <?= is_numeric($cellVal) ? number_format((float)$cellVal, 0) : htmlspecialchars($cellVal) ?>
                                    <?php break;
                                    case 'date': ?>
                                        <span class="text-muted small"><?= htmlspecialchars($cellVal) ?></span>
                                    <?php break;
                                    case 'status': ?>
                                        <span class="status-badge <?= $isPass ? 'status-badge--pass' : 'status-badge--fail' ?>">
                                            <?= $isPass ? '<i class="bi bi-check-circle-fill me-1"></i>บรรลุ' : '<i class="bi bi-x-circle-fill me-1"></i>ไม่ผ่าน' ?>
                                        </span>
                                    <?php break;
                                    case 'badge': ?>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($cellVal) ?></span>
                                    <?php break;
                                    default: ?>
                                        <?= htmlspecialchars($cellVal) ?>
                                <?php endswitch; ?>
                                </td>
                                <?php endforeach; ?>
                                <!-- Diff & Status -->
                                <td class="text-center">
                                    <span class="diff-badge <?= $diff >= 0 ? 'diff-badge--pos' : 'diff-badge--neg' ?>">
                                        <?= ($diff >= 0 ? '+' : '') . number_format($diff, 2) ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge <?= $isPass ? 'status-badge--pass' : 'status-badge--fail' ?>">
                                        <?= $isPass
                                            ? '<i class="bi bi-check-circle-fill me-1"></i>บรรลุ'
                                            : '<i class="bi bi-x-circle-fill me-1"></i>ต่ำกว่าเป้า' ?>
                                    </span>
                                </td>

                            <?php elseif ($useCustomSql && empty($columnConfigs)): ?>
                                <!-- ── Raw SQL columns (no config) ── -->
                                <?php foreach ($customColumns as $col): ?>
                                <td><?= htmlspecialchars($row[$col] ?? '') ?></td>
                                <?php endforeach; ?>

                            <?php else: ?>
                                <!-- ── Default rendering ── -->
                                <td class="fw-semibold"><?= htmlspecialchars($row['period'] ?? '') ?></td>
                                <?php if ($hasUserData): ?>
                                <td class="text-center text-muted small"><?= htmlspecialchars($row['date'] ?? '') ?></td>
                                <td class="text-center">
                                    <?= isset($row['numerator']) && $row['numerator'] !== null ? number_format($row['numerator'], 0) : '—' ?>
                                </td>
                                <td class="text-center">
                                    <?= isset($row['denominator']) && $row['denominator'] !== null ? number_format($row['denominator'], 0) : '—' ?>
                                </td>
                                <?php endif; ?>
                                <td class="text-center">
                                    <span class="result-val <?= $isPass ? 'result-val--pass' : 'result-val--fail' ?> fw-bold">
                                        <?= number_format($actualVal, 2) ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="diff-badge <?= $diff >= 0 ? 'diff-badge--pos' : 'diff-badge--neg' ?>">
                                        <?= ($diff >= 0 ? '+' : '') . number_format($diff, 2) ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge <?= $isPass ? 'status-badge--pass' : 'status-badge--fail' ?>">
                                        <?= $isPass
                                            ? '<i class="bi bi-check-circle-fill me-1"></i>บรรลุ'
                                            : '<i class="bi bi-x-circle-fill me-1"></i>ต่ำกว่าเป้า' ?>
                                    </span>
                                </td>
                                <?php if ($hasUserData): ?>
                                <td class="text-muted small"><?= htmlspecialchars($row['note'] ?? '') ?></td>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="2" class="text-end text-muted">ค่าเฉลี่ย →</td>
                                <?php
                                // หาจำนวน visible columns
                                $extraCols = $useCustomSql && !empty($columnConfigs)
                                    ? count(array_filter($columnConfigs, fn($c) => $c['is_visible'] && !$c['is_value_col'])) - 1
                                    : ($hasUserData ? 2 : 0);
                                if ($extraCols > 0): ?>
                                <td colspan="<?= $extraCols ?>"></td>
                                <?php endif; ?>
                                <td class="text-center text-primary fw-bold"><?= number_format($avgVal, 2) ?>%</td>
                                <td class="text-center"></td>
                                <td class="text-center">
                                    <span class="status-badge <?= $passCount >= $failCount ? 'status-badge--pass' : 'status-badge--fail' ?>">
                                        <?= $passCount ?>✓ / <?= $failCount ?>✗
                                    </span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>


    <?php endif; // end if total > 0 ?>


</div><!-- /container -->

<!-- Hidden data for CSV export -->
<script>
const KPI_NAME   = <?= json_encode($kpi['kpi_name']) ?>;
const KPI_CODE   = <?= json_encode(str_pad($kpi['kpi_code'],3,'0',STR_PAD_LEFT)) ?>;
const TARGET     = <?= json_encode($target) ?>;
const OPERATOR   = <?= json_encode($operator) ?>;
const LABELS        = <?= json_encode(array_values($chartLabels)) ?>;
const BAR_LABELS    = <?= json_encode(array_values($barLabels)) ?>;
const TREND_LABELS  = <?= json_encode(array_values($trendLabels)) ?>;
const VALUES        = <?= json_encode($chartValues) ?>;
const TARGETS       = <?= json_encode($chartTargets) ?>;
const COLORS        = <?= json_encode($barColors) ?>;
const TREND_LINE    = <?= json_encode($trendLine) ?>;
const BAR_DATASETS  = <?= json_encode(array_values($barDatasets)) ?>;
const TREND_DATASETS= <?= json_encode(array_values($trendDatasets)) ?>;
const PASS_CNT   = <?= json_encode($passCount) ?>;
const FAIL_CNT   = <?= json_encode($failCount) ?>;
const STAT_COL_LABEL = <?= json_encode(!empty($cfgBarYColsRaw[0]) ? ($cfgBarYColsRaw[0]) : '') ?>;
const PASS_COL_LABEL = <?= json_encode($cfgPassRateCol ?: $passRateColKey) ?>;
const HAS_USER   = <?= json_encode($hasUserData) ?>;
const DISPLAY    = <?= json_encode($displayRows) ?>;

const FONT = 'Sarabun, sans-serif';

// ══════════════════════════════════════════════════
//  BAR CHART
// ══════════════════════════════════════════════════
// Build bar datasets from config
const barPalette = ['#2563eb','#16a34a','#d97706','#9333ea','#0891b2','#dc2626','#65a30d','#ea580c'];
const barDS = BAR_DATASETS.map((ds, i) => ({
    label: ds.label,
    data:  ds.data,
    backgroundColor: ds.colors
        ? ds.colors
        : Array(ds.data.length).fill(barPalette[i % barPalette.length]),
    borderColor: ds.colors
        ? ds.colors.map(c => c)
        : Array(ds.data.length).fill(barPalette[i % barPalette.length]),
    borderWidth: 0,
    borderRadius: 5,
    maxBarThickness: 40,
    order: i + 1,
}));
barDS.push({
    type: 'line',
    label: 'เป้าหมาย',
    data: TARGETS,
    borderColor: 'rgba(100,116,139,0.6)',
    borderWidth: 2,
    borderDash: [6, 4],
    pointRadius: 0,
    fill: false,
    tension: 0,
    order: 0,
});

new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: BAR_LABELS.length ? BAR_LABELS : LABELS,
        datasets: barDS,
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: BAR_DATASETS.length > 1,
                position: 'top', align: 'end',
                labels: { font: { family: FONT, size: 11 }, usePointStyle: true, boxWidth: 8 }
            },
            tooltip: {
                callbacks: {
                    label: ctx => {
                        const v = ctx.parsed.y;
                        if (ctx.dataset.label === 'เป้าหมาย') return ' เป้าหมาย: ' + v + '%';
                        const ds = BAR_DATASETS[ctx.datasetIndex];
                        if (ds && ds.colors) {
                            const pass = ds.colors[ctx.dataIndex] === '#16a34a';
                            return ' ' + ctx.dataset.label + ': ' + (v != null ? v.toFixed(2) : '-') + '%  ' + (pass ? '✓' : '✗');
                        }
                        return ' ' + ctx.dataset.label + ': ' + (v != null ? v.toFixed(2) : '-') + '%';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: false,
                ticks: { font: { family: FONT, size: 11 }, callback: v => v + '%' },
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            x: {
                ticks: {
                    font: { family: FONT, size: 10 },
                    maxRotation: 45,
                    callback: function(val) {
                        const l = this.getLabelForValue(val);
                        return l.length > 12 ? l.substring(0, 12) + '…' : l;
                    }
                },
                grid: { display: false }
            }
        }
    }
});

// ══════════════════════════════════════════════════
//  TREND CHART
// ══════════════════════════════════════════════════
// Build trend datasets from config
const trendPalette = ['#2563eb','#16a34a','#d97706','#9333ea','#0891b2','#dc2626'];
function hexToRgba(hex, a) {
    const r = parseInt(hex.slice(1,3),16),
          g = parseInt(hex.slice(3,5),16),
          b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${a})`;
}
const trendDS = TREND_DATASETS.map((ds, i) => {
    const col = ds.color || trendPalette[i % trendPalette.length];
    return {
        label: ds.label,
        data:  ds.data,
        borderColor: col,
        backgroundColor: hexToRgba(col.startsWith('#') ? col : '#2563eb', 0.07),
        borderWidth: 2.5,
        pointRadius: 3,
        pointHoverRadius: 6,
        fill: i === 0 ? 'origin' : false,
        tension: 0.35,
        order: i + 1,
    };
});
trendDS.push({
    label: 'Trend Line',
    data: TREND_LINE,
    borderColor: '#f59e0b',
    backgroundColor: 'transparent',
    borderWidth: 2,
    borderDash: [4, 3],
    pointRadius: 0,
    fill: false,
    tension: 0,
    order: 99,
});
trendDS.push({
    label: 'เป้าหมาย',
    data: TARGETS,
    borderColor: 'rgba(100,116,139,0.5)',
    backgroundColor: 'transparent',
    borderWidth: 1.5,
    borderDash: [7, 4],
    pointRadius: 0,
    fill: false,
    order: 100,
});

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: TREND_LABELS.length ? TREND_LABELS : LABELS,
        datasets: trendDS,
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'top',
                align: 'end',
                labels: { font: { family: FONT, size: 11 }, usePointStyle: true, boxWidth: 8 }
            },
            tooltip: {
                callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + (ctx.parsed.y != null ? ctx.parsed.y.toFixed(2) : '-') + '%' }
            }
        },
        scales: {
            y: {
                ticks: { font: { family: FONT, size: 11 }, callback: v => v + '%' },
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            x: {
                ticks: {
                    font: { family: FONT, size: 10 },
                    maxRotation: 45,
                    callback: function(val) {
                        const l = this.getLabelForValue(val);
                        return l.length > 12 ? l.substring(0, 12) + '…' : l;
                    }
                },
                grid: { display: false }
            }
        }
    }
});

// ══════════════════════════════════════════════════
//  DOUGHNUT
// ══════════════════════════════════════════════════
new Chart(document.getElementById('doughnutChart'), {
    type: 'doughnut',
    data: {
        labels: ['ผ่านเกณฑ์', 'ต่ำกว่าเป้า'],
        datasets: [{
            data: [PASS_CNT, FAIL_CNT],
            backgroundColor: ['#16a34a', '#dc2626'],
            hoverBackgroundColor: ['#15803d', '#b91c1c'],
            borderWidth: 3,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        plugins: {
            legend: { position: 'bottom', labels: { font: { family: FONT, size: 11 }, usePointStyle: true, padding: 12 } },
            tooltip: {}
        }
    }
});

// ══════════════════════════════════════════════════
//  DISTRIBUTION CHART (scatter-like using bar with small bins)
// ══════════════════════════════════════════════════
(function buildDistribution() {
    // ใช้ข้อมูลจาก BAR_DATASETS Y ตัวแรก (ถ้ามี) ไม่งั้นใช้ VALUES
    const distSrc = (BAR_DATASETS.length && BAR_DATASETS[0].data && BAR_DATASETS[0].data.length)
                    ? BAR_DATASETS[0].data.filter(v => v != null)
                    : VALUES;
    if (!distSrc.length) return;
    const min = Math.floor(Math.min(...distSrc));
    const max = Math.ceil(Math.max(...distSrc));
    const binSize = Math.max(1, Math.ceil((max - min) / 8));
    const bins = {};
    for (let b = min; b <= max; b += binSize) {
        bins[`${b}–${b+binSize}`] = 0;
    }
    distSrc.forEach(v => {
        for (const key of Object.keys(bins)) {
            const [lo, hi] = key.split('–').map(Number);
            if (v >= lo && v < hi + 0.0001) { bins[key]++; break; }
        }
    });
    const binLabels = Object.keys(bins).map(k => k + '%');
    const binData   = Object.values(bins);
    const binColors = Object.keys(bins).map(key => {
        const mid = (Number(key.split('–')[0]) + Number(key.split('–')[1])) / 2;
        return evaluateVal(mid) ? 'rgba(22,163,74,0.75)' : 'rgba(220,38,38,0.75)';
    });
    function evaluateVal(v) {
        switch(OPERATOR) {
            case '>=': return v >= TARGET;
            case '<=': return v <= TARGET;
            case '>':  return v >  TARGET;
            case '<':  return v <  TARGET;
            case '=':  return Math.abs(v - TARGET) < 0.01;
            default:   return v >= TARGET;
        }
    }
    new Chart(document.getElementById('distChart'), {
        type: 'bar',
        data: {
            labels: binLabels,
            datasets: [{
                label: 'จำนวนช่วงเวลา',
                data: binData,
                backgroundColor: binColors,
                borderRadius: 5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: ctx => ` ${ctx.parsed.y} ช่วง` } }
            },
            scales: {
                y: { ticks: { font: { family: FONT, size: 11 }, precision: 0 }, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { ticks: { font: { family: FONT, size: 10 } }, grid: { display: false } }
            }
        }
    });
})();

// ══════════════════════════════════════════════════
//  TABLE SEARCH
// ══════════════════════════════════════════════════
document.getElementById('tableSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#detailTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// ══════════════════════════════════════════════════
//  EXPORT CSV (client-side)
// ══════════════════════════════════════════════════
// ── evalPass: ใช้ OPERATOR จริง ──────────────────────────────
function evalPass(v) {
    switch (OPERATOR) {
        case '>=': return v >= TARGET_VAL;
        case '<=': return v <= TARGET_VAL;
        case '>':  return v >  TARGET_VAL;
        case '<':  return v <  TARGET_VAL;
        case '=':  return Math.abs(v - TARGET_VAL) < 0.01;
        default:   return v >= TARGET_VAL;
    }
}

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ══════════════════════════════════════════════════
//  DRILLDOWN — รายอำเภอ / รายโรงพยาบาล
// ══════════════════════════════════════════════════
const KPI_ID_DD      = <?= (int)$kpiId ?>;
const HAS_DISTRICT   = <?= json_encode($hasDistrictSql ?? false) ?>;
const HAS_HOSPITAL   = <?= json_encode($hasHospitalSql ?? false) ?>;
const DRILL_URL      = 'drilldown.php?id=' + KPI_ID_DD;
const DETAIL_URL     = 'kpi_detail.php?id=' + KPI_ID_DD;
const TARGET_VAL     = TARGET;
const PASS_COL       = <?= json_encode($passRateColKey ?? '') ?>;

let drillChart = null;

async function setViewMode(mode, btn) {
    // Update button states
    document.querySelectorAll('#viewModeBtns .btn').forEach(b => {
        b.classList.remove('btn-primary','active');
        b.classList.add('btn-outline-primary');
    });
    if (btn) { btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary','active'); }

    const panel = document.getElementById('drilldownPanel');
    if (mode === 'overview') {
        panel.style.display = 'none';
        return;
    }

    panel.style.display = '';
    const spin = document.getElementById('drilldownLoading');
    const title= document.getElementById('drilldownTitle');
    spin?.classList.remove('d-none');

    try {
        const resp = await fetch(`${DRILL_URL}&type=${mode}`);
        const text = await resp.text();
        let d;
        try { d = JSON.parse(text); }
        catch(e) {
            spin?.classList.add('d-none');
            const panel2 = document.getElementById('drilldownPanel');
            panel2.querySelector('#drilldownTableWrap').innerHTML =
                `<div class="alert alert-danger m-3"><i class="bi bi-x-circle me-2"></i>Server error — response ไม่ใช่ JSON<pre class="mt-2 small">${text.slice(0,300)}</pre></div>`;
            return;
        }
        spin?.classList.add('d-none');
        if (!d.ok) {
            const panel2 = document.getElementById('drilldownPanel');
            panel2.querySelector('#drilldownTableWrap').innerHTML =
                `<div class="alert alert-warning m-3"><i class="bi bi-exclamation-triangle me-2"></i>${esc(d.error)}</div>`;
            return;
        }

        title.innerHTML = `<i class="bi bi-${mode==='district'?'map':'hospital'} me-2"></i>`
            + (mode==='district' ? 'ข้อมูลรายอำเภอ' : 'ข้อมูลรายโรงพยาบาล')
            + ` <span class="badge bg-secondary ms-2">${d.rows.length} รายการ</span>`;

        renderDrillTable(d);
        renderDrillChart(d);
    } catch(e) {
        spin?.classList.add('d-none');
        alert('Error: ' + e.message);
    }
}

function renderDrillTable(d) {
    const wrap = document.getElementById('drilldownTableWrap');
    if (!wrap) return;
    if (!d.rows.length) {
        wrap.innerHTML = '<p class="text-muted text-center py-3">ไม่มีข้อมูล</p>';
        return;
    }

    const lblCol  = d.label_col;
    const valCol  = d.value_col || d.columns.find(c => c !== lblCol && d.rows.some(r => !isNaN(parseFloat(r[c]))));
    const passCol = d.pass_col  || valCol;  // ใช้ pass_col ถ้ากำหนดไว้ ไม่งั้นใช้ value_col
    const op      = d.operator || OPERATOR;
    const tgt     = d.target   ?? TARGET_VAL;

    function evalRow(val) {
        if (val === null || val === undefined) return null;
        const v = parseFloat(val);
        switch(op) {
            case '>=': return v >= tgt;
            case '<=': return v <= tgt;
            case '>':  return v >  tgt;
            case '<':  return v <  tgt;
            case '=':  return Math.abs(v - tgt) < 0.01;
            default:   return v >= tgt;
        }
    }

    // คำนวณสถิติสรุป
    const vals  = d.rows.map(r => parseFloat(r[valCol])).filter(v => !isNaN(v));
    const pCnt  = vals.filter(v => evalRow(v) === true).length;
    const fCnt  = vals.length - pCnt;
    const avg   = vals.length ? (vals.reduce((a,b)=>a+b,0)/vals.length).toFixed(2) : '-';

    let h = `<div class="d-flex gap-3 mb-3 p-2 bg-light rounded-3 flex-wrap" style="font-size:.82rem">
        <span><strong>${d.rows.length}</strong> รายการ</span>
        <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i><strong>${pCnt}</strong> ผ่าน</span>
        <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i><strong>${fCnt}</strong> ไม่ผ่าน</span>
        <span class="text-muted">เฉลี่ย <strong>${avg}%</strong></span>
        <span class="text-primary">เป้าหมาย <strong>${op} ${tgt}%</strong></span>
    </div>`;

    h += `<table class="table table-sm kpi-table" style="font-size:.82rem">
        <thead><tr><th>#</th>`;
    d.columns.forEach(c => { h += `<th>${esc(c)}</th>`; });
    h += '<th class="text-center">สถานะ</th></tr></thead><tbody>';

    d.rows.forEach((row, i) => {
        const rawVal = passCol ? row[passCol] : (valCol ? row[valCol] : null);
        const pass   = rawVal !== null && rawVal !== undefined && rawVal !== '' ? evalRow(rawVal) : null;
        const cls    = pass === true ? 'kpi-row--pass' : pass === false ? 'kpi-row--fail' : '';
        h += `<tr class="kpi-row ${cls}"><td class="text-muted small">${i+1}</td>`;
        d.columns.forEach(c => {
            const v    = row[c];
            const isN  = v !== null && v !== '' && !isNaN(parseFloat(v));
            const isLbl = c === lblCol;
            const isVal = c === valCol;
            let cell = esc(String(v ?? '—'));
            if (isVal && isN) {
                const pv = parseFloat(v);
                const col = pass === true ? '#16a34a' : '#dc2626';
                cell = `<span style="color:${col};font-weight:700">${pv.toFixed(2)}%</span>`;
            }
            h += `<td class="${isN && !isLbl ? 'text-end' : ''}">${cell}</td>`;
        });
        h += `<td class="text-center">${
            pass === true  ? '<span class="status-badge status-badge--pass">✓ ผ่าน</span>' :
            pass === false ? '<span class="status-badge status-badge--fail">✗ ไม่ผ่าน</span>' : '—'
        }</td>`;
        h += '</tr>';
    });
    h += '</tbody></table>';
    wrap.innerHTML = h;
}

function renderDrillChart(d) {
    if (drillChart) { drillChart.destroy(); drillChart = null; }
    const canvas = document.getElementById('drilldownChart');
    if (!canvas || !d.rows.length) return;

    const lblCol  = d.label_col;
    const op      = d.operator || OPERATOR;
    const tgt     = d.target   ?? TARGET_VAL;
    const valCol  = d.value_col || d.columns.find(c => c !== lblCol && d.rows.some(r => !isNaN(parseFloat(r[c]))));
    const passCol = d.pass_col  || valCol;  // ค่าที่เปรียบกับ target
    if (!valCol) return;

    function evalV(v) {
        switch(op) {
            case '>=': return v >= tgt; case '<=': return v <= tgt;
            case '>':  return v >  tgt; case '<':  return v <  tgt;
            default:   return v >= tgt;
        }
    }

    const labels = d.rows.map(r => String(r[lblCol] || '?'));
    const values = d.rows.map(r => parseFloat(r[valCol])  || 0);
    const passes = d.rows.map(r => parseFloat(r[passCol]) || 0);
    const colors = passes.map(v => evalV(v) ? '#16a34a' : '#dc2626');
    const isHoriz = values.length > 12;   // แนวนอนถ้ามีมาก

    drillChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: valCol,
                    data: values,
                    backgroundColor: colors,
                    borderRadius: 4,
                    maxBarThickness: isHoriz ? 24 : 36,
                    order: 1,
                },
                {
                    type: 'line',
                    label: 'เป้าหมาย (' + op + ' ' + tgt + '%)',
                    data: Array(values.length).fill(tgt),
                    borderColor: 'rgba(100,116,139,0.7)',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    borderDash: [6, 4],
                    pointRadius: 0,
                    fill: false,
                    order: 0,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: isHoriz ? 'y' : 'x',
            plugins: {
                legend: { display: true, position: 'top', align: 'end',
                    labels: { usePointStyle: true, boxWidth: 8 }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const axis = isHoriz ? 'x' : 'y';
                            const v = ctx.parsed[axis];
                            return ' ' + ctx.dataset.label + ': ' + (v != null ? v.toFixed(2) : '-') + '%';
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { font: { family: 'Sarabun, sans-serif', size: 10 }, maxRotation: isHoriz ? 0 : 45 },
                    grid: { display: isHoriz },
                    ...(isHoriz ? { ticks: { callback: v => v + '%' } } : {})
                },
                y: {
                    ticks: { font: { family: 'Sarabun, sans-serif', size: 10 } },
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ...(!isHoriz ? { ticks: { callback: v => v + '%' } } : {})
                }
            }
        }
    });
}


function exportCSV() {
    const colConfigs = <?= json_encode(
        !empty($columnConfigs)
            ? array_values(array_filter($columnConfigs, fn($c) => $c['is_visible']))
            : []
    ) ?>;
    const useCustom  = <?= json_encode($useCustomSql) ?>;
    const passColKey = <?= json_encode($passRateColKey) ?>;

    let headers, getRow;

    if (useCustom && colConfigs.length) {
        headers = ['#', ...colConfigs.map(c => c.col_label), 'สถานะ'];
        getRow  = (r, i) => [
            i + 1,
            ...colConfigs.map(c => r[c.col_key] ?? ''),
            parseFloat(r[passColKey] ?? r['actual'] ?? 0) >= TARGET ? 'บรรลุเป้าหมาย' : 'ต่ำกว่าเป้าหมาย',
        ];
    } else if (useCustom) {
        const keys = Object.keys(DISPLAY[0] || {}).filter(k => k !== '_no');
        headers = ['#', ...keys, 'สถานะ'];
        getRow  = (r, i) => [
            i + 1,
            ...keys.map(k => r[k] ?? ''),
            parseFloat(r[passColKey] ?? r['actual'] ?? 0) >= TARGET ? 'บรรลุเป้าหมาย' : 'ต่ำกว่าเป้าหมาย',
        ];
    } else if (HAS_USER) {
        headers = ['#', 'ช่วงเวลา', 'วันที่', 'ตัวเศษ', 'ตัวส่วน', 'ผลงาน(%)', 'เทียบเป้าหมาย', 'สถานะ', 'หมายเหตุ'];
        getRow  = (r, i) => {
            const v = parseFloat(r.actual ?? 0);
            const diff = (v - TARGET).toFixed(2);
            const pass = (() => { switch(OPERATOR){ case '>=':return v>=TARGET; case '<=':return v<=TARGET; case '>':return v>TARGET; case '<':return v<TARGET; default:return v>=TARGET; } })();
            return [i+1, r.period??'', r.date??'', r.numerator??'', r.denominator??'', v.toFixed(2), (diff>=0?'+':'')+diff+'%', pass?'บรรลุ':'ต่ำกว่าเป้า', r.note??''];
        };
    } else {
        headers = ['#', 'ช่วงเวลา', 'ผลงาน(%)', 'เทียบเป้าหมาย', 'สถานะ'];
        getRow  = (r, i) => {
            const v = parseFloat(r.actual ?? 0);
            const diff = (v - TARGET).toFixed(2);
            const pass = (() => { switch(OPERATOR){ case '>=':return v>=TARGET; case '<=':return v<=TARGET; default:return v>=TARGET; } })();
            return [i+1, r.period??'', v.toFixed(2), (diff>=0?'+':'')+diff+'%', pass?'บรรลุ':'ต่ำกว่าเป้า'];
        };
    }

    const esc  = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
    const csv  = [
        [esc('รายงานข้อมูล KPI — ' + KPI_NAME)].join(','),
        [esc('รหัส: ' + KPI_CODE), esc('เป้าหมาย: ' + OPERATOR + ' ' + TARGET + '%')].join(','),
        '',
        headers.map(esc).join(','),
        ...DISPLAY.map((r, i) => getRow(r, i).map(esc).join(',')),
    ].join('\n');

    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = `KPI_${KPI_CODE}_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
}
</script>

</body>
</html>