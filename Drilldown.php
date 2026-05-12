<?php
/**
 * admin/kpi_data_editor.php
 * จัดการ SQL, ค่าผลลัพธ์, และหัวตารางแสดงผลของแต่ละ KPI
 */
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/KPI.php';
include '../classes/Auth.php';

Auth::requireAdmin();

// ── สร้าง / migrate ตาราง ─────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS kpi_query_config (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    kpi_id      INT NOT NULL UNIQUE,
    query_sql   TEXT NOT NULL,
    db_source   ENUM('kpi','hdc') NOT NULL DEFAULT 'kpi',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    description VARCHAR(500),
    value_col   VARCHAR(100) DEFAULT '',
    created_by  INT, updated_by INT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (kpi_id) REFERENCES kpis(kpi_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

foreach ([
    "ALTER TABLE kpi_query_config ADD COLUMN value_col  VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN value_sql  TEXT",
    "ALTER TABLE kpi_query_config ADD COLUMN value_db   ENUM('kpi','hdc') DEFAULT 'kpi'",
    "ALTER TABLE kpi_query_config ADD COLUMN bar_x_col    VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN bar_y_cols   TEXT DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN trend_x_col  VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN trend_y_cols TEXT DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN pass_rate_col VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN label_col         VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN bar_cols           TEXT DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN trend_cols         TEXT DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN district_sql        TEXT",
    "ALTER TABLE kpi_query_config ADD COLUMN district_db         ENUM('kpi','hdc') DEFAULT 'kpi'",
    "ALTER TABLE kpi_query_config ADD COLUMN district_label_col  VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN district_value_col  VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN district_pass_col   VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN hospital_sql        TEXT",
    "ALTER TABLE kpi_query_config ADD COLUMN hospital_db         ENUM('kpi','hdc') DEFAULT 'kpi'",
    "ALTER TABLE kpi_query_config ADD COLUMN hospital_label_col  VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN hospital_value_col  VARCHAR(100) DEFAULT ''",
    "ALTER TABLE kpi_query_config ADD COLUMN hospital_pass_col   VARCHAR(100) DEFAULT ''",
] as $_s) {
    try { $conn->query($_s); } catch (mysqli_sql_exception $e) {}
}

$conn->query("CREATE TABLE IF NOT EXISTS kpi_column_config (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    kpi_id     INT NOT NULL,
    col_order  INT NOT NULL DEFAULT 0,
    col_key    VARCHAR(100) NOT NULL,
    col_label  VARCHAR(200) NOT NULL,
    col_type   ENUM('text','number','percent','date','status','badge') DEFAULT 'text',
    col_width  VARCHAR(20)  DEFAULT '',
    col_align  ENUM('left','center','right') DEFAULT 'left',
    is_visible TINYINT(1)   DEFAULT 1,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kpi_id) REFERENCES kpis(kpi_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── routing vars ──────────────────────────────────────────────
$msg     = '';
$msgType = 'success';
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$kpiId   = (int)($_POST['kpi_id'] ?? $_GET['kpi_id'] ?? 0);

// ════════════════════════════════════════════════════════════
//  AJAX: ทดสอบ SQL — ส่งผ่าน POST แล้ว exit ก่อน HTML
// ════════════════════════════════════════════════════════════
if ($action === 'test_sql') {
    header('Content-Type: application/json; charset=utf-8');

    $rawSql = trim($_POST['sql'] ?? '');
    $dbSrc  = ($_POST['db_source'] ?? '') === 'hdc' ? 'hdc' : 'kpi';
    $testId = max(1, (int)($_POST['kpi_id'] ?? 1));

    if ($rawSql === '') {
        echo json_encode(['ok' => false, 'error' => 'SQL ว่างเปล่า']); exit;
    }

    // ป้องกัน DML / DDL — word boundary ป้องกัน false positive เช่น last_updated
    $blocked = ['INSERT','UPDATE','DELETE','DROP','TRUNCATE','ALTER','CREATE','GRANT','REVOKE'];
    foreach ($blocked as $kw) {
        // (?<![\w_]) = ไม่ติดกับตัวอักษร/ตัวเลข/underscore ด้านหน้า
        if (preg_match('/(?<![a-zA-Z0-9_])' . $kw . '(?![a-zA-Z0-9_])/i', $rawSql)) {
            echo json_encode(['ok' => false, 'error' => "SQL ไม่อนุญาต keyword: $kw"]); exit;
        }
    }
    if (!preg_match('/^\s*SELECT\b/i', $rawSql)) {
        echo json_encode(['ok' => false, 'error' => 'SQL ต้องเริ่มด้วย SELECT']); exit;
    }

    $sql = str_replace(':kpi_id', $testId, $rawSql);
    if (stripos($sql, 'LIMIT') === false) $sql .= ' LIMIT 200';

    $db  = ($dbSrc === 'hdc') ? ($con ?? $conn) : $conn;
    $res = $db->query($sql);
    if ($res === false) {
        echo json_encode(['ok' => false, 'error' => $db->error]); exit;
    }

    $cols = []; $rows = [];
    if ($res->num_rows > 0) {
        $first = $res->fetch_assoc();
        $cols  = array_keys($first);
        $rows[] = array_values($first);
        while ($r = $res->fetch_assoc()) $rows[] = array_values($r);
    }

    echo json_encode([
        'ok'      => true,
        'columns' => $cols,
        'rows'    => $rows,
        'count'   => count($rows),
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════
//  AJAX: บันทึกค่าผลลัพธ์ลง kpi_results
// ════════════════════════════════════════════════════════════
if ($action === 'push_to_results') {
    header('Content-Type: application/json; charset=utf-8');
    $pushKpiId = (int)($_POST['kpi_id'] ?? 0);
    if (!$pushKpiId) { echo json_encode(['ok'=>false,'error'=>'ไม่พบ KPI ID']); exit; }

    $mode    = $_POST['push_mode'] ?? 'col';   // 'col' หรือ 'sql'
    $value   = null;

    if ($mode === 'col') {
        // ── วิธีที่ 1: รัน SQL หลัก แล้วดึงค่าจากคอลัมน์ที่เลือก ──
        $mainSql  = trim($_POST['main_sql']  ?? '');
        $colKey   = trim($_POST['value_col'] ?? '');
        $dbSrc    = ($_POST['db_source'] ?? '') === 'hdc' ? 'hdc' : 'kpi';

        if (!$mainSql || !$colKey) {
            echo json_encode(['ok'=>false,'error'=>'กรุณาระบุ SQL และคอลัมน์ก่อน']); exit;
        }
        $execSql = str_replace(':kpi_id', $pushKpiId, $mainSql);
        // ดึงแถวสุดท้าย
        if (stripos($execSql,'ORDER') === false) $execSql .= ' ORDER BY 1 DESC';
        $execSql .= ' LIMIT 1';
        $db  = ($dbSrc === 'hdc') ? ($con ?? $conn) : $conn;
        $res = $db->query($execSql);
        if (!$res) { echo json_encode(['ok'=>false,'error'=>$db->error]); exit; }
        $row = $res->fetch_assoc();
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'SQL ไม่คืนข้อมูล']); exit; }
        if (!array_key_exists($colKey, $row)) {
            echo json_encode(['ok'=>false,'error'=>"ไม่พบคอลัมน์ '$colKey' ใน SQL result"]); exit;
        }
        $value = (float)$row[$colKey];

    } elseif ($mode === 'sql') {
        // ── วิธีที่ 2: รัน SQL แยกที่ user เขียน — ต้องคืน 1 row, 1 col ──
        $rawSql = trim($_POST['result_sql'] ?? '');
        $dbSrc  = ($_POST['result_db'] ?? '') === 'hdc' ? 'hdc' : 'kpi';
        if (!$rawSql) { echo json_encode(['ok'=>false,'error'=>'กรุณาระบุ SQL']); exit; }
        $up = strtoupper(preg_replace('/\s+/',' ',trim($rawSql)));
        if (strpos($up,'SELECT') !== 0) {
            echo json_encode(['ok'=>false,'error'=>'SQL ต้องเริ่มด้วย SELECT']); exit;
        }
        $execSql = str_replace(':kpi_id', $pushKpiId, $rawSql);
        $db  = ($dbSrc === 'hdc') ? ($con ?? $conn) : $conn;
        $res = $db->query($execSql);
        if (!$res) { echo json_encode(['ok'=>false,'error'=>$db->error]); exit; }
        $row = $res->fetch_assoc();
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'SQL ไม่คืนข้อมูล']); exit; }
        $value = (float)reset($row);

    } else {
        echo json_encode(['ok'=>false,'error'=>'mode ไม่ถูกต้อง']); exit;
    }

    // ── บันทึกลง kpi_results ──
    $stmt = $conn->prepare("
        INSERT INTO kpi_results (kpi_id, actual_value, last_updated)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE actual_value=VALUES(actual_value), last_updated=NOW()
    ");
    $stmt->bind_param('id', $pushKpiId, $value);
    if ($stmt->execute()) {
        echo json_encode(['ok'=>true, 'value'=>$value,
            'msg'=>"บันทึก $value ลง kpi_results สำเร็จ"]);
    } else {
        echo json_encode(['ok'=>false,'error'=>$conn->error]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
//  SAVE: SQL + value_col
// ════════════════════════════════════════════════════════════
if ($action === 'save_query' && $kpiId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sql       = trim($_POST['query_sql']  ?? '');
    $dbSrc     = ($_POST['db_source'] ?? '') === 'hdc' ? 'hdc' : 'kpi';
    $desc      = trim($_POST['description'] ?? '');
    $isActive  = isset($_POST['is_active']) ? 1 : 0;
    $valueCol    = trim($_POST['value_col']    ?? '');
    $passRateCol = trim($_POST['pass_rate_col'] ?? '');
    $barXCol        = trim($_POST['bar_x_col']       ?? '');
    $barYCols       = trim($_POST['bar_y_cols']      ?? '');
    $trendXCol      = trim($_POST['trend_x_col']     ?? '');
    $trendYCols     = trim($_POST['trend_y_cols']    ?? '');
    $districtSql    = trim($_POST['district_sql']      ?? '');
    $districtDb     = ($_POST['district_db'] ?? '') === 'hdc' ? 'hdc' : 'kpi';
    $districtLblCol = trim($_POST['district_label_col']  ?? '');
    $districtValCol = trim($_POST['district_value_col']  ?? '');
    $districtPasCol = trim($_POST['district_pass_col']   ?? '');
    $hospitalSql    = trim($_POST['hospital_sql']      ?? '');
    $hospitalDb     = ($_POST['hospital_db'] ?? '') === 'hdc' ? 'hdc' : 'kpi';
    $hospitalLblCol = trim($_POST['hospital_label_col']  ?? '');
    $hospitalValCol = trim($_POST['hospital_value_col']  ?? '');
    $hospitalPasCol = trim($_POST['hospital_pass_col']   ?? '');
    $userId         = (int)($_SESSION['user_id'] ?? 0);

    $up = strtoupper(preg_replace('/\s+/', ' ', trim($sql)));
    if (empty($sql) || strpos($up, 'SELECT') !== 0) {
        $msg = 'SQL ต้องเริ่มด้วย SELECT';
        $msgType = 'danger';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO kpi_query_config
                (kpi_id, query_sql, db_source, description, is_active,
                 value_col, pass_rate_col,
                 bar_x_col, bar_y_cols, trend_x_col, trend_y_cols,
                 district_sql, district_db, district_label_col, district_value_col, district_pass_col,
                 hospital_sql, hospital_db, hospital_label_col, hospital_value_col, hospital_pass_col,
                 created_by, updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                query_sql=VALUES(query_sql), db_source=VALUES(db_source),
                description=VALUES(description), is_active=VALUES(is_active),
                value_col=VALUES(value_col), pass_rate_col=VALUES(pass_rate_col),
                bar_x_col=VALUES(bar_x_col), bar_y_cols=VALUES(bar_y_cols),
                trend_x_col=VALUES(trend_x_col), trend_y_cols=VALUES(trend_y_cols),
                district_sql=VALUES(district_sql), district_db=VALUES(district_db),
                district_label_col=VALUES(district_label_col),
                district_value_col=VALUES(district_value_col), district_pass_col=VALUES(district_pass_col),
                hospital_sql=VALUES(hospital_sql), hospital_db=VALUES(hospital_db),
                hospital_label_col=VALUES(hospital_label_col),
                hospital_value_col=VALUES(hospital_value_col), hospital_pass_col=VALUES(hospital_pass_col),
                updated_by=VALUES(updated_by), updated_at=NOW()
        ");
        // i s s s i s s s s s s s s s s s s i i = 19 params
        // i s s s i  s s  s s s s  s s s s s  s s s s s  i i = 23 params
        $stmt->bind_param('isssissssssssssssssssii',
            $kpiId, $sql, $dbSrc, $desc, $isActive,
            $valueCol, $passRateCol,
            $barXCol, $barYCols, $trendXCol, $trendYCols,
            $districtSql, $districtDb, $districtLblCol, $districtValCol, $districtPasCol,
            $hospitalSql, $hospitalDb, $hospitalLblCol, $hospitalValCol, $hospitalPasCol,
            $userId, $userId);
        if ($stmt->execute()) {
            $msg = 'บันทึกสำเร็จ';
        } else {
            $msg = 'Error: ' . $conn->error;
            $msgType = 'danger';
        }
    }
}

// ════════════════════════════════════════════════════════════
//  SAVE: Column headers
// ════════════════════════════════════════════════════════════
if ($action === 'save_columns' && $kpiId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->query("DELETE FROM kpi_column_config WHERE kpi_id = $kpiId");

    $keys   = $_POST['col_key']   ?? [];
    $labels = $_POST['col_label'] ?? [];
    $types  = $_POST['col_type']  ?? [];
    $aligns = $_POST['col_align'] ?? [];
    $widths = $_POST['col_width'] ?? [];

    $stmt = $conn->prepare("
        INSERT INTO kpi_column_config
            (kpi_id, col_order, col_key, col_label, col_type, col_align, col_width, is_visible)
        VALUES (?,?,?,?,?,?,?,1)
    ");
    $saved = 0;
    foreach ($keys as $i => $key) {
        $key   = trim($key);
        $label = trim($labels[$i] ?? $key);
        if (!$key) continue;
        if (!$label) $label = $key;
        $type  = in_array($types[$i]??'', ['text','number','percent','date','status','badge'])
                    ? $types[$i] : 'text';
        $align = in_array($aligns[$i]??'', ['left','center','right'])
                    ? $aligns[$i] : 'left';
        $width = trim($widths[$i] ?? '');
        $stmt->bind_param('iisssss', $kpiId, $i, $key, $label, $type, $align, $width);
        $stmt->execute();
        $saved++;
    }
    $msg = "บันทึกหัวตาราง $saved คอลัมน์สำเร็จ";
}

// ════════════════════════════════════════════════════════════
//  DELETE config
// ════════════════════════════════════════════════════════════
if ($action === 'delete_config' && $kpiId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->query("DELETE FROM kpi_query_config  WHERE kpi_id = $kpiId");
    $conn->query("DELETE FROM kpi_column_config WHERE kpi_id = $kpiId");
    $msg = 'รีเซ็ต Config สำเร็จ';
}

// ── ดึงข้อมูล ─────────────────────────────────────────────────
$allKpis = [];
$r = $conn->query("SELECT kpi_id, kpi_code, kpi_name FROM kpis ORDER BY kpi_code ASC");
if ($r) while ($row = $r->fetch_assoc()) $allKpis[] = $row;

$currentKpi    = null;
$queryConfig   = null;
$columnConfigs = [];

if ($kpiId) {
    foreach ($allKpis as $k) {
        if ((int)$k['kpi_id'] === $kpiId) { $currentKpi = $k; break; }
    }
    $r = $conn->query("SELECT * FROM kpi_query_config WHERE kpi_id = $kpiId LIMIT 1");
    if ($r) $queryConfig = $r->fetch_assoc();
    $r = $conn->query("SELECT * FROM kpi_column_config WHERE kpi_id = $kpiId ORDER BY col_order ASC");
    if ($r) while ($row = $r->fetch_assoc()) $columnConfigs[] = $row;
}

$activeMenu = 'editor';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>จัดการ SQL & หัวตาราง KPI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/admin.css">
<style>
/* ── CodeMirror ── */
.CodeMirror {
    font-family: 'JetBrains Mono','Fira Code','Consolas', monospace;
    font-size: 13px;
    line-height: 1.6;
    height: 260px;
    border-radius: 0 0 10px 10px;
    border: 1.5px solid #334155;
    border-top: none;
}
.sql-topbar {
    background: #282a36;
    border-radius: 10px 10px 0 0;
    padding: .45rem 1rem;
    display: flex;
    align-items: center;
    gap: .6rem;
}
.sql-topbar .dots span {
    display: inline-block;
    width: 11px; height: 11px;
    border-radius: 50%;
    margin-right: 3px;
}
.dot-r{background:#ff5f57} .dot-y{background:#febc2e} .dot-g{background:#28c840}

/* ── SQL result box ── */
.result-box {
    background: #1e293b;
    border-radius: 0 0 10px 10px;
    border: 1.5px solid #334155;
    border-top: none;
    overflow-x: auto;
    max-height: 320px;
    overflow-y: auto;
}
.result-box table {
    width: 100%;
    font-size: .78rem;
    color: #e2e8f0;
    border-collapse: collapse;
    white-space: nowrap;
}
.result-box th {
    background: #334155;
    color: #94a3b8;
    font-size: .69rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 6px 10px;
    position: sticky;
    top: 0;
    z-index: 1;
    white-space: nowrap;
}
.result-box td {
    padding: 5px 10px;
    border-bottom: 1px solid #1e3a5f;
    font-family: 'Consolas', monospace;
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.result-box tr:last-child td { border-bottom: none; }
.result-box tr:hover td { background: rgba(255,255,255,.04); }
.result-status {
    background: #0f172a;
    border-radius: 10px 10px 0 0;
    padding: .4rem 1rem;
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .78rem;
    color: #94a3b8;
    border: 1.5px solid #334155;
    border-bottom: none;
}
.result-empty {
    color: #475569;
    text-align: center;
    padding: 2rem;
    font-style: italic;
    font-size: .82rem;
}
.result-error {
    color: #f87171;
    background: rgba(239,68,68,.1);
    border-radius: 8px;
    padding: .65rem 1rem;
    font-size: .82rem;
    margin: .5rem;
}

/* ── KPI sidebar ── */
.kpi-sidebar { max-height: calc(100vh - 180px); overflow-y: auto; }
.kpi-item {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .42rem .7rem;
    border-radius: 8px;
    text-decoration: none;
    color: #334155;
    font-size: .81rem;
    line-height: 1.35;
    border: 1.5px solid transparent;
    transition: all .12s;
    margin-bottom: 2px;
}
.kpi-item:hover  { background: #f0fdf4; border-color: #0d9488; color: #0f766e; }
.kpi-item.active { background: #ccfbf1; border-color: #0d9488; color: #0f766e; font-weight: 700; }
.kpi-item .cfg-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #0d9488;
    flex-shrink: 0;
    margin-left: auto;
}

/* ── Tabs ── */
.editor-nav .nav-link {
    font-size: .85rem;
    font-weight: 700;
    color: #64748b;
    border: none;
    border-bottom: 2.5px solid transparent;
    border-radius: 0;
    padding: .58rem 1.2rem;
    margin-bottom: -2px;
    transition: all .12s;
}
.editor-nav .nav-link.active { color: #0f766e; border-bottom-color: #0d9488; background: transparent; }
.editor-nav .nav-link:hover:not(.active) { color: #334155; background: #f8fafc; }

/* ── Table header editor ── */
.col-hdr-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 100px 80px 70px;
    gap: 6px;
    align-items: center;
}
.col-hdr-row {
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    padding: .4rem .6rem;
    margin-bottom: 4px;
    transition: border-color .12s;
}
.col-hdr-row:hover { border-color: #0d9488; }

/* ── Value col picker ── */
.vcol-option {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .65rem 1rem;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
    cursor: pointer;
    transition: all .15s;
    margin-bottom: .75rem;
}
.vcol-option:has(input:checked) { border-color: #0d9488; background: #f0fdf4; }
.vcol-option input[type=radio] { accent-color: #0d9488; width: 18px; height: 18px; flex-shrink: 0; }

/* ── Chart col option pills ── */
.chart-col-option {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .35rem .75rem;
    border-radius: 20px;
    border: 1.5px solid #cbd5e1;
    background: #f8fafc;
    cursor: pointer;
    font-size: .8rem;
    font-weight: 600;
    color: #475569;
    transition: all .14s;
    user-select: none;
}
.chart-col-option:hover { border-color: #0d9488; color: #0f766e; background: #f0fdf4; }
.chart-col-option.active,
.chart-col-option.cco-active { border-color: #0d9488; background: #ccfbf1; color: #0f766e; }
.chart-col-option input { display: none; }
.chart-col-option .col-name { font-family: 'Consolas', monospace; font-size: .78rem; }

/* ── Chart config card ── */
.chart-config-card {
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}
.chart-config-header {
    background: color-mix(in srgb, var(--accent, #0d9488) 8%, #fff);
    border-bottom: 2px solid var(--accent, #0d9488);
    padding: .55rem 1rem;
    font-weight: 700;
    font-size: .85rem;
    color: var(--accent, #0d9488);
    display: flex;
    align-items: center;
}
.chart-config-body { padding: 1rem; }

/* ── misc ── */
.sec-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px; height: 24px;
    border-radius: 50%;
    background: linear-gradient(135deg,#0f766e,#0891b2);
    color: #fff;
    font-weight: 700;
    font-size: .78rem;
    flex-shrink: 0;
}
.help-note { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:.65rem .9rem; font-size:.8rem; }
.help-note code { background:#d1fae5; border-radius:3px; padding:1px 5px; font-size:.76rem; color:#065f46; }
</style>
</head>
<body>
<?php include '../includes/Navbar.php'; ?>

<div class="container-fluid px-3 px-md-4">

    <!-- Page header -->
    <div class="page-header mb-3">
        <div>
            <h4 class="page-title">
                <i class="bi bi-code-square me-2 text-teal"></i>จัดการ SQL & หัวตาราง KPI
            </h4>
            <p class="page-subtitle">กำหนด SQL Query, คอลัมน์ค่าผลลัพธ์ และหัวตารางแสดงผลสำหรับหน้า Detail</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= authUrl('admin/kpi_manage.php') ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
            <?php if ($kpiId): ?>
            <a href="<?= authUrl('kpi_detail.php') ?>?id=<?= $kpiId ?>" target="_blank"
               class="btn btn-outline-primary btn-sm rounded-pill">
                <i class="bi bi-eye me-1"></i>ดูหน้า Detail
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show mb-3">
        <i class="bi bi-<?= $msgType==='success'?'check-circle-fill':'exclamation-triangle-fill' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-3">

        <!-- ══ Sidebar KPI list ══════════════════════════════ -->
        <div class="col-lg-3 col-md-4">
            <div class="chart-card h-100">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-list-ul me-2"></i>เลือก KPI</h6>
                    <span class="badge bg-secondary"><?= count($allKpis) ?></span>
                </div>
                <div class="chart-card__body p-2">
                    <input type="text" id="kpiSearch"
                           class="form-control form-control-sm rounded-pill mb-2"
                           placeholder="🔍 ค้นหา...">
                    <div class="kpi-sidebar">
                        <?php foreach ($allKpis as $k):
                            $hasCfg = false;
                            $cq = $conn->query("SELECT id FROM kpi_query_config WHERE kpi_id={$k['kpi_id']} LIMIT 1");
                            if ($cq && $cq->num_rows > 0) $hasCfg = true;
                            $isActive = (int)$k['kpi_id'] === $kpiId;
                        ?>
                        <a href="?kpi_id=<?= $k['kpi_id'] ?>"
                           class="kpi-item <?= $isActive?'active':'' ?>"
                           data-name="<?= htmlspecialchars(strtolower($k['kpi_name'])) ?>"
                           data-code="<?= htmlspecialchars(strtolower($k['kpi_code'])) ?>">
                            <span class="kpi-code"><?= str_pad($k['kpi_code'],3,'0',STR_PAD_LEFT) ?></span>
                            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?= htmlspecialchars(mb_substr($k['kpi_name'],0,46)) ?><?= mb_strlen($k['kpi_name'])>46?'…':'' ?>
                            </span>
                            <?php if ($hasCfg): ?><span class="cfg-dot" title="มี Config"></span><?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ Editor ══════════════════════════════════════ -->
        <div class="col-lg-9 col-md-8">
        <?php if (!$kpiId): ?>
            <div class="chart-card">
                <div class="chart-card__body text-center py-5 text-muted">
                    <i class="bi bi-code-square" style="font-size:3.5rem;opacity:.18;"></i>
                    <h5 class="mt-3">เลือก KPI จากรายการทางซ้าย</h5>
                    <p class="small">จากนั้นกำหนด SQL Query, คอลัมน์ค่าผลลัพธ์ และหัวตารางแสดงผล</p>
                </div>
            </div>
        <?php else: ?>

            <!-- KPI title bar -->
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="kpi-code fs-6"><?= str_pad($currentKpi['kpi_code']??'',3,'0',STR_PAD_LEFT) ?></span>
                <h5 class="mb-0 fw-bold"><?= htmlspecialchars($currentKpi['kpi_name']??'') ?></h5>
                <?php if ($queryConfig): ?>
                    <span class="badge bg-success rounded-pill"><i class="bi bi-check2-circle me-1"></i>มี Config</span>
                    <span class="badge <?= ($queryConfig['is_active']??0)?'bg-success':'bg-secondary' ?> rounded-pill">
                        <?= ($queryConfig['is_active']??0)?'Active':'Inactive' ?>
                    </span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark rounded-pill">ยังไม่มี Config</span>
                <?php endif; ?>
                <?php if ($queryConfig): ?>
                <form method="post" class="ms-auto mb-0"
                      onsubmit="return confirm('รีเซ็ต Config ทั้งหมดของ KPI นี้?')">
                    <input type="hidden" name="kpi_id" value="<?= $kpiId ?>">
                    <input type="hidden" name="action"  value="delete_config">
                    <button class="btn btn-outline-danger btn-sm rounded-pill">
                        <i class="bi bi-trash3 me-1"></i>รีเซ็ต
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="chart-card">
                <div class="chart-card__body p-0">

                    <!-- Tab nav -->
                    <ul class="nav editor-nav border-bottom px-3 pt-2" id="editorTabs">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabSQL">
                                <i class="bi bi-database-gear me-1"></i>1. SQL ข้อมูล
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabValue">
                                <i class="bi bi-bullseye me-1"></i>2. ค่าผลลัพธ์
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabChart">
                                <i class="bi bi-bar-chart-line me-1"></i>3. Chart Config
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDrilldown">
                                <i class="bi bi-diagram-3 me-1"></i>4. รายอำเภอ/รพ.
                                <?php if (!empty($queryConfig['district_sql']) || !empty($queryConfig['hospital_sql'])): ?>
                                <span class="badge bg-success ms-1" style="font-size:.62rem">✓</span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabHeaders">
                                <i class="bi bi-table me-1"></i>5. หัวตาราง
                                <?php if (!empty($columnConfigs)): ?>
                                <span class="badge bg-teal ms-1"><?= count($columnConfigs) ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content p-3 p-md-4">

<!-- ══════════════════════════════════════════════════════════
     TAB 1 — SQL ข้อมูล
══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tabSQL">

    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="sec-badge">1</span>
        <div>
            <div class="fw-bold">เขียน SQL สำหรับดึงข้อมูล KPI</div>
            <div class="text-muted small">ใช้ <code>:kpi_id</code> แทน KPI ID · อนุญาตเฉพาะ SELECT</div>
        </div>
    </div>

    <!-- SQL Topbar + CodeMirror -->
    <div class="sql-topbar">
        <div class="dots">
            <span class="dot-r"></span><span class="dot-y"></span><span class="dot-g"></span>
        </div>
        <span class="text-white-50 small me-auto">SQL Editor</span>
        <select id="dbSourceSel" class="form-select form-select-sm"
                style="width:auto;background:#334155;color:#f8f8f2;border:1px solid #475569;font-size:.76rem">
            <option value="kpi" <?= ($queryConfig['db_source']??'kpi')==='kpi'?'selected':'' ?>>DB: kpi25</option>
            <option value="hdc" <?= ($queryConfig['db_source']??'kpi')==='hdc'?'selected':'' ?>>DB: hdc</option>
        </select>
        <button type="button" id="btnRunSQL"
                class="btn btn-sm px-3"
                style="background:#6366f1;color:#fff;border:none;border-radius:6px;font-size:.78rem">
            <span id="sqlSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
            <i class="bi bi-play-fill" id="sqlPlayIcon"></i> ทดสอบ
            <kbd class="ms-1" style="font-size:.65rem;opacity:.7;background:rgba(255,255,255,.15);border:none">Ctrl+↵</kbd>
        </button>
    </div>
    <textarea id="sqlEditorTA" name="query_sql"><?= htmlspecialchars($queryConfig['query_sql'] ??
"SELECT\n    period_label  AS ช่วงเวลา,\n    actual_value  AS ผลงาน,\n    numerator     AS ตัวเศษ,\n    denominator   AS ตัวส่วน\nFROM kpi_data_rows\nWHERE kpi_id = :kpi_id\nORDER BY created_at ASC") ?></textarea>

    <!-- Result area -->
    <div id="resultStatus" class="result-status" style="display:none">
        <span id="resultStatusText"></span>
    </div>
    <div id="resultBox" class="result-box" style="display:none">
        <div id="resultInner"></div>
    </div>

    <!-- Save form -->
    <form method="post" id="formSQL" class="mt-4">
        <input type="hidden" name="action"    value="save_query">
        <input type="hidden" name="kpi_id"    value="<?= $kpiId ?>">
        <input type="hidden" name="db_source" id="hidDb"    value="<?= htmlspecialchars($queryConfig['db_source']??'kpi') ?>">
        <input type="hidden" name="query_sql" id="hidSQL"   value="">
        <input type="hidden" name="value_col" id="hidVCol"  value="<?= htmlspecialchars($queryConfig['value_col']??'') ?>">

        <div class="row g-2">
            <div class="col-md-8">
                <label class="form-label small fw-semibold">คำอธิบาย (optional)</label>
                <input type="text" name="description" class="form-control"
                       placeholder="เช่น: ดึงจาก kpi_data_rows เรียงตามเดือน"
                       value="<?= htmlspecialchars($queryConfig['description']??'') ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end pb-1">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                           <?= ($queryConfig['is_active']??1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isActive">เปิดใช้งาน</label>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-3 justify-content-end">
            <button type="button" id="btnFillHeaders"
                    class="btn btn-outline-secondary rounded-pill btn-sm">
                <i class="bi bi-magic me-1"></i>ดึงหัวตารางอัตโนมัติ
            </button>
            <button type="submit" class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-floppy-fill me-1"></i>บันทึก SQL
            </button>
        </div>
    </form>
</div><!-- /tabSQL -->

<!-- ══════════════════════════════════════════════════════════
     TAB 2 — ค่าผลลัพธ์ → kpi_results
══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabValue">

    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="sec-badge" style="background:linear-gradient(135deg,#d97706,#f59e0b)">2</span>
        <div>
            <div class="fw-bold">จัดการค่าผลลัพธ์ → kpi_results</div>
            <div class="text-muted small">เลือกคอลัมน์จาก SQL หรือเขียน SQL แยก แล้วกด "บันทึกลง kpi_results"</div>
        </div>
    </div>

    <?php if (!$queryConfig): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        กรุณากำหนดและบันทึก SQL ในแท็บ 1 ก่อน
    </div>
    <?php else: ?>

    <?php
    $kpiInfo = $conn->query("SELECT target_operator,target_value FROM kpis WHERE kpi_id=$kpiId LIMIT 1")->fetch_assoc();
    // ค่า kpi_results ล่าสุด
    $lastResult = $conn->query("SELECT actual_value, last_updated FROM kpi_results WHERE kpi_id=$kpiId ORDER BY last_updated DESC LIMIT 1")->fetch_assoc();
    ?>

    <!-- ── Current value in kpi_results ── -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="bi bi-bullseye me-1"></i>เป้าหมาย</div>
                    <div class="fw-bold fs-5">
                        <?= htmlspecialchars($kpiInfo['target_operator']??'>=') ?> <?= $kpiInfo['target_value']??0 ?>%
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="bi bi-database me-1"></i>ค่าปัจจุบันใน kpi_results</div>
                    <?php if ($lastResult): ?>
                    <div class="fw-bold fs-5 <?= (float)$lastResult['actual_value'] >= (float)($kpiInfo['target_value']??0) ? 'text-success' : 'text-danger' ?>">
                        <?= number_format((float)$lastResult['actual_value'],2) ?>%
                    </div>
                    <div class="text-muted" style="font-size:.75rem"><?= $lastResult['last_updated'] ?></div>
                    <?php else: ?>
                    <div class="text-muted">— ยังไม่มีข้อมูล —</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="bi bi-arrow-repeat me-1"></i>วิธีอัปเดต</div>
                    <div class="fw-semibold small">เลือกวิธีด้านล่าง</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Mode tabs ── -->
    <ul class="nav nav-pills mb-3" id="valueTabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#valModeCol">
                <i class="bi bi-columns-gap me-1"></i>เลือกคอลัมน์จาก SQL
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#valModeSql">
                <i class="bi bi-code-slash me-1"></i>เขียน SQL แยก
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ──────────────────────────────────────────
             MODE A: เลือกคอลัมน์จาก SQL หลัก
        ────────────────────────────────────────── -->
        <div class="tab-pane fade show active" id="valModeCol">
            <div class="help-note mb-3">
                <i class="bi bi-info-circle me-2"></i>
                กด <strong>"โหลดคอลัมน์"</strong> เพื่อรัน SQL แล้วเลือกคอลัมน์ที่เป็นค่าผลลัพธ์
                จากนั้นกด <strong>"บันทึกลง kpi_results"</strong>
            </div>

            <div class="d-flex gap-2 align-items-center mb-3">
                <button type="button" id="btnLoadColsVal"
                        class="btn btn-outline-primary btn-sm rounded-pill">
                    <span id="loadValSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    <i class="bi bi-arrow-repeat me-1"></i>โหลดคอลัมน์
                </button>
                <span id="valColStatus" class="text-muted small"></span>
            </div>

            <!-- Column picker -->
            <div id="valColPicker" class="d-flex flex-wrap gap-2 mb-3">
                <?php if (!empty($queryConfig['value_col'])): ?>
                <div class="alert alert-success py-2 w-100 mb-0">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    คอลัมน์ที่เลือกไว้: <strong><code><?= htmlspecialchars($queryConfig['value_col']) ?></code></strong>
                    — กด "โหลดคอลัมน์" เพื่อเปลี่ยน
                </div>
                <?php else: ?>
                <p class="text-muted small mb-0">กด "โหลดคอลัมน์" เพื่อเริ่ม</p>
                <?php endif; ?>
            </div>

            <!-- Preview value -->
            <div id="valColPreview" class="mb-3" style="display:none">
                <div class="card border-success">
                    <div class="card-body py-2 d-flex align-items-center gap-3">
                        <div>
                            <div class="text-muted small">ค่าล่าสุดที่จะบันทึก</div>
                            <div class="fw-bold fs-4 text-success" id="valColPreviewNum">—</div>
                        </div>
                        <div class="text-muted small ms-auto" id="valColPreviewDetail"></div>
                    </div>
                </div>
            </div>

            <!-- Hidden fields -->
            <input type="hidden" id="selValCol"  value="<?= htmlspecialchars($queryConfig['value_col']??'') ?>">
            <input type="hidden" id="selValNum"  value="">

            <div class="d-flex gap-2">
                <!-- Save value_col selection to queryConfig -->
                <form method="post" id="formSaveValCol" class="mb-0">
                    <input type="hidden" name="action"     value="save_query">
                    <input type="hidden" name="kpi_id"     value="<?= $kpiId ?>">
                    <input type="hidden" name="query_sql"  value="<?= htmlspecialchars($queryConfig['query_sql']??'') ?>">
                    <input type="hidden" name="db_source"  value="<?= htmlspecialchars($queryConfig['db_source']??'kpi') ?>">
                    <input type="hidden" name="description" value="<?= htmlspecialchars($queryConfig['description']??'') ?>">
                    <input type="hidden" name="is_active"  value="<?= ($queryConfig['is_active']??1) ?>">
                    <input type="hidden" name="value_col"  id="saveValColHid" value="<?= htmlspecialchars($queryConfig['value_col']??'') ?>">
                    <button type="submit" class="btn btn-outline-warning btn-sm rounded-pill">
                        <i class="bi bi-bookmark-fill me-1"></i>บันทึกการเลือกคอลัมน์
                    </button>
                </form>
                <!-- Push to kpi_results -->
                <button type="button" id="btnPushCol"
                        class="btn btn-success rounded-pill px-4" disabled>
                    <span id="pushColSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    <i class="bi bi-database-fill-up me-1"></i>บันทึกลง kpi_results
                </button>
            </div>

            <!-- Result feedback -->
            <div id="pushColResult" class="mt-3"></div>
        </div>

        <!-- ──────────────────────────────────────────
             MODE B: เขียน SQL แยก
        ────────────────────────────────────────── -->
        <div class="tab-pane fade" id="valModeSql">
            <div class="help-note mb-3">
                <i class="bi bi-info-circle me-2"></i>
                SQL ต้องคืน <strong>1 row, 1 column</strong> (ค่าตัวเลข) · ใช้ <code>:kpi_id</code> แทน KPI ID
            </div>

            <!-- SQL editor -->
            <div class="sql-topbar">
                <div class="dots">
                    <span class="dot-r"></span><span class="dot-y"></span><span class="dot-g"></span>
                </div>
                <span class="text-white-50 small me-auto">Result SQL</span>
                <select id="resSqlDb"
                        class="form-select form-select-sm"
                        style="width:auto;background:#334155;color:#f8f8f2;border:1px solid #475569;font-size:.76rem">
                    <option value="kpi">DB: kpi25</option>
                    <option value="hdc">DB: hdc</option>
                </select>
                <button type="button" id="btnTestResSql"
                        class="btn btn-sm px-3"
                        style="background:#6366f1;color:#fff;border:none;border-radius:6px;font-size:.78rem">
                    <span id="resSqlSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    <i class="bi bi-play-fill"></i> ทดสอบ
                </button>
            </div>
            <textarea id="resSqlEd"
                      placeholder="SELECT actual_value FROM kpi_data_rows WHERE kpi_id = :kpi_id ORDER BY created_at DESC LIMIT 1"></textarea>

            <!-- Result preview -->
            <div id="resSqlPreview" class="result-box mt-0" style="display:none">
                <div id="resSqlPreviewInner"></div>
            </div>
            <div id="resSqlStatus" class="result-status" style="display:none">
                <span id="resSqlStatusText"></span>
            </div>

            <!-- Value preview before push -->
            <div id="valSqlPreview" class="mt-3 mb-3" style="display:none">
                <div class="card border-success">
                    <div class="card-body py-2 d-flex align-items-center gap-3">
                        <div>
                            <div class="text-muted small">ค่าที่จะบันทึก</div>
                            <div class="fw-bold fs-4 text-success" id="valSqlNum">—</div>
                        </div>
                        <div class="text-muted small ms-auto" id="valSqlDetail"></div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="button" id="btnPushSql"
                        class="btn btn-success rounded-pill px-4" disabled>
                    <span id="pushSqlSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    <i class="bi bi-database-fill-up me-1"></i>บันทึกลง kpi_results
                </button>
            </div>
            <div id="pushSqlResult" class="mt-3"></div>
        </div>

    </div><!-- /tab-content -->
    <?php endif; ?>
</div><!-- /tabValue -->

<!-- ══════════════════════════════════════════════════════════
     TAB 3 — หัวตาราง
══════════════════════════════════════════════════════════ -->
<!-- ══════════════════════════════════════════════════════════
     TAB 3 — Chart Config
══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabChart">

    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="sec-badge" style="background:linear-gradient(135deg,#0284c7,#0891b2)">3</span>
        <div>
            <div class="fw-bold">กำหนดคอลัมน์สำหรับ Chart</div>
            <div class="text-muted small">Bar Chart และ Trend Chart มีแกน X แยกกัน · เลือกได้จากคอลัมน์ที่ SQL คืนมา</div>
        </div>
        <button type="button" id="btnLoadColsChart"
                class="btn btn-outline-primary btn-sm rounded-pill ms-auto">
            <span id="loadChartSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
            <i class="bi bi-arrow-repeat me-1"></i>โหลดคอลัมน์จาก SQL
        </button>
    </div>

    <?php if (!$queryConfig): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        กรุณาบันทึก SQL ในแท็บ 1 ก่อน
    </div>
    <?php else: ?>

    <?php
    $qc = $queryConfig;
    $savedBarX    = $qc['bar_x_col']    ?? '';
    $savedBarY    = array_filter(array_map('trim', explode(',', $qc['bar_y_cols']   ?? '')));
    $savedTrendX  = $qc['trend_x_col']  ?? '';
    $savedTrendY  = array_filter(array_map('trim', explode(',', $qc['trend_y_cols'] ?? '')));
    $savedPassRate= $qc['pass_rate_col'] ?? '';
    ?>

    <div id="chartColStatus" class="help-note mb-3" style="display:none">
        <i class="bi bi-check-circle-fill text-success me-2"></i>
        <span id="chartColStatusText"></span>
    </div>

    <form method="post" id="formChart">
        <input type="hidden" name="action"       value="save_query">
        <input type="hidden" name="kpi_id"       value="<?= $kpiId ?>">
        <input type="hidden" name="query_sql"    value="<?= htmlspecialchars($qc['query_sql']??'') ?>">
        <input type="hidden" name="db_source"    value="<?= htmlspecialchars($qc['db_source']??'kpi') ?>">
        <input type="hidden" name="description"  value="<?= htmlspecialchars($qc['description']??'') ?>">
        <input type="hidden" name="is_active"    value="<?= ($qc['is_active']??1) ?>">
        <input type="hidden" name="value_col"    value="<?= htmlspecialchars($qc['value_col']??'') ?>">

        <input type="hidden" name="pass_rate_col" id="hidPassRateCol" value="<?= htmlspecialchars($savedPassRate) ?>">
        <input type="hidden" name="bar_x_col"     id="hidBarXCol"     value="<?= htmlspecialchars($savedBarX) ?>">
        <input type="hidden" name="bar_y_cols"    id="hidBarYCols"    value="<?= htmlspecialchars(implode(',', $savedBarY)) ?>">
        <input type="hidden" name="trend_x_col"   id="hidTrendXCol"   value="<?= htmlspecialchars($savedTrendX) ?>">
        <input type="hidden" name="trend_y_cols"  id="hidTrendYCols"  value="<?= htmlspecialchars(implode(',', $savedTrendY)) ?>">

        <!-- ══ สัดส่วนผ่านเกณฑ์ ══════════════════════════════ -->
        <div class="chart-config-card mb-4">
            <div class="chart-config-header" style="--accent:#dc2626">
                <i class="bi bi-bullseye me-2"></i>สัดส่วนผ่านเกณฑ์ (Pass Rate)
                <span class="ms-2 small fw-normal opacity-75">— ค่านี้ใช้เปรียบกับ Target</span>
            </div>
            <div class="chart-config-body">
                <div class="small text-muted mb-2">เลือก 1 คอลัมน์ที่เป็นค่าตัวเลขสัดส่วนผ่านเกณฑ์:</div>
                <div id="passRatePicker" class="d-flex flex-wrap gap-2">
                    <?php if ($savedPassRate): ?>
                    <span class="chart-col-option active">
                        <i class="bi bi-check2 me-1"></i><span class="col-name"><?= htmlspecialchars($savedPassRate) ?></span>
                    </span>
                    <span class="text-muted small align-self-center">กด "โหลดคอลัมน์" เพื่อเปลี่ยน</span>
                    <?php else: ?>
                    <span class="text-muted small">กด "โหลดคอลัมน์จาก SQL" เพื่อเลือก</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ══ Bar Chart ══════════════════════════════════════ -->
        <div class="chart-config-card mb-4">
            <div class="chart-config-header" style="--accent:#16a34a">
                <i class="bi bi-bar-chart-fill me-2"></i>Bar Chart
            </div>
            <div class="chart-config-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="small fw-semibold mb-2">
                            <i class="bi bi-arrows-horizontal me-1 text-success"></i>แกน X — Label (radio เลือก 1)
                        </div>
                        <div id="barXPicker" class="d-flex flex-wrap gap-2">
                            <?php if ($savedBarX): ?>
                            <span class="chart-col-option active">
                                <i class="bi bi-check2 me-1"></i><span class="col-name"><?= htmlspecialchars($savedBarX) ?></span>
                            </span>
                            <span class="text-muted small align-self-center">กด "โหลดคอลัมน์" เพื่อเปลี่ยน</span>
                            <?php else: ?>
                            <span class="text-muted small">กด "โหลดคอลัมน์จาก SQL"</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="small fw-semibold mb-2">
                            <i class="bi bi-arrows-vertical me-1 text-success"></i>แกน Y — ค่าข้อมูล (checkbox หลายตัว)
                        </div>
                        <div id="barYPicker" class="d-flex flex-wrap gap-2">
                            <?php if (!empty($savedBarY)): ?>
                            <?php foreach ($savedBarY as $c): ?>
                            <span class="chart-col-option active"><span class="col-name"><?= htmlspecialchars($c) ?></span></span>
                            <?php endforeach; ?>
                            <span class="text-muted small align-self-center">กด "โหลดคอลัมน์" เพื่อเปลี่ยน</span>
                            <?php else: ?>
                            <span class="text-muted small">กด "โหลดคอลัมน์จาก SQL"</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ Trend Chart ════════════════════════════════════ -->
        <div class="chart-config-card mb-4">
            <div class="chart-config-header" style="--accent:#7c3aed">
                <i class="bi bi-graph-up-arrow me-2"></i>Trend Chart
            </div>
            <div class="chart-config-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="small fw-semibold mb-2">
                            <i class="bi bi-arrows-horizontal me-1 text-purple"></i>แกน X — Label (radio เลือก 1)
                        </div>
                        <div id="trendXPicker" class="d-flex flex-wrap gap-2">
                            <?php if ($savedTrendX): ?>
                            <span class="chart-col-option active">
                                <i class="bi bi-check2 me-1"></i><span class="col-name"><?= htmlspecialchars($savedTrendX) ?></span>
                            </span>
                            <span class="text-muted small align-self-center">กด "โหลดคอลัมน์" เพื่อเปลี่ยน</span>
                            <?php else: ?>
                            <span class="text-muted small">กด "โหลดคอลัมน์จาก SQL"</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="small fw-semibold mb-2">
                            <i class="bi bi-arrows-vertical me-1 text-purple"></i>แกน Y — ค่าข้อมูล (checkbox หลายตัว)
                        </div>
                        <div id="trendYPicker" class="d-flex flex-wrap gap-2">
                            <?php if (!empty($savedTrendY)): ?>
                            <?php foreach ($savedTrendY as $c): ?>
                            <span class="chart-col-option active"><span class="col-name"><?= htmlspecialchars($c) ?></span></span>
                            <?php endforeach; ?>
                            <span class="text-muted small align-self-center">กด "โหลดคอลัมน์" เพื่อเปลี่ยน</span>
                            <?php else: ?>
                            <span class="text-muted small">กด "โหลดคอลัมน์จาก SQL"</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-floppy-fill me-1"></i>บันทึก Chart Config
            </button>
        </div>
    </form>

    <?php endif; ?>
</div><!-- /tabChart -->


<!-- ══════════════════════════════════════════════════════════
     TAB 4 — SQL รายอำเภอ / รายโรงพยาบาล (optional)
══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabDrilldown">

    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="sec-badge" style="background:linear-gradient(135deg,#0369a1,#0284c7)">4</span>
        <div>
            <div class="fw-bold">SQL รายอำเภอ / รายโรงพยาบาล (optional)</div>
            <div class="text-muted small">
                กำหนด SQL + chart config เพื่อให้หน้า Detail แสดงตัวกรองรายอำเภอ/รายโรงพยาบาล
                · ใช้ <code>:kpi_id</code> เป็น placeholder
            </div>
        </div>
    </div>

    <?php if (!$queryConfig): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>กรุณาบันทึก SQL หลักในแท็บ 1 ก่อน
    </div>
    <?php else: ?>

    <?php
    // helper: render a column picker (radio or checkbox)
    function renderColPicker(string $id, string $saved, string $radioName, bool $isCheckbox = false): string {
        $active = $saved ? "cco-active" : "";
        if ($saved) {
            return "<span class=\"chart-col-option {$active}\">
                        <span class=\"col-name\">" . htmlspecialchars($saved) . "</span>
                    </span>
                    <span class=\"text-muted small align-self-center\">กด \"ทดสอบ\" เพื่อเปลี่ยน</span>";
        }
        return "<p class=\"text-muted small mb-0\">กด \"ทดสอบ\" เพื่อเลือกคอลัมน์</p>";
    }
    ?>

    <!-- ════════════ รายอำเภอ ════════════ -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header fw-bold py-2" style="background:#eff6ff;border-left:4px solid #2563eb">
            <i class="bi bi-map me-2 text-primary"></i>SQL รายอำเภอ
            <?php if (!empty($queryConfig['district_sql'])): ?>
            <span class="badge bg-success ms-2">มี SQL</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-3">
            <!-- SQL Editor -->
            <div class="sql-topbar">
                <div class="dots"><span class="dot-r"></span><span class="dot-y"></span><span class="dot-g"></span></div>
                <span class="text-white-50 small me-auto">District SQL</span>
                <select id="districtDbSel" class="form-select form-select-sm"
                        style="width:auto;background:#334155;color:#f8f8f2;border:1px solid #475569;font-size:.76rem">
                    <option value="kpi" <?= ($queryConfig['district_db']??'kpi')==='kpi'?'selected':'' ?>>DB: kpi25</option>
                    <option value="hdc" <?= ($queryConfig['district_db']??'kpi')==='hdc'?'selected':'' ?>>DB: hdc</option>
                </select>
                <button type="button" id="btnTestDistrict"
                        class="btn btn-sm px-3" style="background:#6366f1;color:#fff;border:none;border-radius:6px;font-size:.78rem">
                    <span id="districtSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    <i class="bi bi-play-fill"></i> ทดสอบ
                </button>
            </div>
            <textarea id="districtSqlEd"><?= htmlspecialchars($queryConfig['district_sql']??'') ?></textarea>
            <div id="districtResult" class="result-box" style="display:none"><div id="districtResultInner"></div></div>
            <div id="districtStatus" class="result-status" style="display:none"><span id="districtStatusText"></span></div>

            <!-- Chart Config -->
            <div class="mt-3 p-3 bg-light rounded-3">
                <div class="fw-semibold small mb-2">
                    <i class="bi bi-bar-chart-line me-1 text-primary"></i>Chart Config — เลือกคอลัมน์หลังกด "ทดสอบ"
                </div>
                <div class="row g-3">
                    <!-- Label Col -->
                    <div class="col-md-4">
                        <div class="small fw-semibold text-muted mb-1">
                            <span class="badge" style="background:#e0e7ff;color:#3730a3">🏷 Label (ชื่ออำเภอ)</span>
                        </div>
                        <div id="districtLabelPicker" class="d-flex flex-wrap gap-1">
                            <?= renderColPicker('districtLabelPicker', $queryConfig['district_label_col']??'', 'dLbl') ?>
                        </div>
                        <input type="hidden" id="hidDistrictLabelCol" value="<?= htmlspecialchars($queryConfig['district_label_col']??'') ?>">
                    </div>
                    <!-- Value Col -->
                    <div class="col-md-4">
                        <div class="small fw-semibold text-muted mb-1">
                            <span class="badge" style="background:#dcfce7;color:#166534">📊 ค่าแสดงใน Chart</span>
                        </div>
                        <div id="districtValuePicker" class="d-flex flex-wrap gap-1">
                            <?= renderColPicker('districtValuePicker', $queryConfig['district_value_col']??'', 'dVal') ?>
                        </div>
                        <input type="hidden" id="hidDistrictValueCol" value="<?= htmlspecialchars($queryConfig['district_value_col']??'') ?>">
                    </div>
                    <!-- Pass Rate Col -->
                    <div class="col-md-4">
                        <div class="small fw-semibold text-muted mb-1">
                            <span class="badge" style="background:#fee2e2;color:#991b1b">🎯 ค่าเปรียบ Target</span>
                        </div>
                        <div id="districtPassPicker" class="d-flex flex-wrap gap-1">
                            <?= renderColPicker('districtPassPicker', $queryConfig['district_pass_col']??'', 'dPas') ?>
                        </div>
                        <input type="hidden" id="hidDistrictPassCol" value="<?= htmlspecialchars($queryConfig['district_pass_col']??'') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════ รายโรงพยาบาล ════════════ -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header fw-bold py-2" style="background:#f0fdf4;border-left:4px solid #16a34a">
            <i class="bi bi-hospital me-2 text-success"></i>SQL รายโรงพยาบาล
            <?php if (!empty($queryConfig['hospital_sql'])): ?>
            <span class="badge bg-success ms-2">มี SQL</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-3">
            <!-- SQL Editor -->
            <div class="sql-topbar">
                <div class="dots"><span class="dot-r"></span><span class="dot-y"></span><span class="dot-g"></span></div>
                <span class="text-white-50 small me-auto">Hospital SQL</span>
                <select id="hospitalDbSel" class="form-select form-select-sm"
                        style="width:auto;background:#334155;color:#f8f8f2;border:1px solid #475569;font-size:.76rem">
                    <option value="kpi" <?= ($queryConfig['hospital_db']??'kpi')==='kpi'?'selected':'' ?>>DB: kpi25</option>
                    <option value="hdc" <?= ($queryConfig['hospital_db']??'kpi')==='hdc'?'selected':'' ?>>DB: hdc</option>
                </select>
                <button type="button" id="btnTestHospital"
                        class="btn btn-sm px-3" style="background:#6366f1;color:#fff;border:none;border-radius:6px;font-size:.78rem">
                    <span id="hospitalSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    <i class="bi bi-play-fill"></i> ทดสอบ
                </button>
            </div>
            <textarea id="hospitalSqlEd"><?= htmlspecialchars($queryConfig['hospital_sql']??'') ?></textarea>
            <div id="hospitalResult" class="result-box" style="display:none"><div id="hospitalResultInner"></div></div>
            <div id="hospitalStatus" class="result-status" style="display:none"><span id="hospitalStatusText"></span></div>

            <!-- Chart Config -->
            <div class="mt-3 p-3 bg-light rounded-3">
                <div class="fw-semibold small mb-2">
                    <i class="bi bi-bar-chart-line me-1 text-success"></i>Chart Config — เลือกคอลัมน์หลังกด "ทดสอบ"
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small fw-semibold text-muted mb-1">
                            <span class="badge" style="background:#e0e7ff;color:#3730a3">🏷 Label (ชื่อ รพ.)</span>
                        </div>
                        <div id="hospitalLabelPicker" class="d-flex flex-wrap gap-1">
                            <?= renderColPicker('hospitalLabelPicker', $queryConfig['hospital_label_col']??'', 'hLbl') ?>
                        </div>
                        <input type="hidden" id="hidHospitalLabelCol" value="<?= htmlspecialchars($queryConfig['hospital_label_col']??'') ?>">
                    </div>
                    <div class="col-md-4">
                        <div class="small fw-semibold text-muted mb-1">
                            <span class="badge" style="background:#dcfce7;color:#166534">📊 ค่าแสดงใน Chart</span>
                        </div>
                        <div id="hospitalValuePicker" class="d-flex flex-wrap gap-1">
                            <?= renderColPicker('hospitalValuePicker', $queryConfig['hospital_value_col']??'', 'hVal') ?>
                        </div>
                        <input type="hidden" id="hidHospitalValueCol" value="<?= htmlspecialchars($queryConfig['hospital_value_col']??'') ?>">
                    </div>
                    <div class="col-md-4">
                        <div class="small fw-semibold text-muted mb-1">
                            <span class="badge" style="background:#fee2e2;color:#991b1b">🎯 ค่าเปรียบ Target</span>
                        </div>
                        <div id="hospitalPassPicker" class="d-flex flex-wrap gap-1">
                            <?= renderColPicker('hospitalPassPicker', $queryConfig['hospital_pass_col']??'', 'hPas') ?>
                        </div>
                        <input type="hidden" id="hidHospitalPassCol" value="<?= htmlspecialchars($queryConfig['hospital_pass_col']??'') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end">
        <button type="button" id="btnSaveDrilldown" class="btn btn-primary rounded-pill px-4">
            <i class="bi bi-floppy-fill me-1"></i>บันทึก SQL รายอำเภอ/โรงพยาบาล
        </button>
    </div>

    <?php endif; ?>
</div><!-- /tabDrilldown -->

<div class="tab-pane fade" id="tabHeaders">

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span class="sec-badge" style="background:linear-gradient(135deg,#7c3aed,#a855f7)">3</span>
        <div>
            <div class="fw-bold">กำหนดหัวตารางแสดงผล</div>
            <div class="text-muted small">ตั้งชื่อหัวตาราง ประเภท และการจัดวาง — กด "ดึงจาก SQL" เพื่อเติมอัตโนมัติ</div>
        </div>
        <button type="button" id="btnFillHeaders2"
                class="btn btn-outline-primary btn-sm rounded-pill ms-auto">
            <i class="bi bi-magic me-1"></i>ดึงจาก SQL
        </button>
    </div>

    <form method="post" id="formHeaders">
        <input type="hidden" name="action" value="save_columns">
        <input type="hidden" name="kpi_id" value="<?= $kpiId ?>">

        <!-- Column type legend -->
        <div class="help-note mb-3">
            <strong><i class="bi bi-info-circle me-1"></i>ประเภท:</strong>
            <code>text</code> ข้อความ &nbsp;
            <code>number</code> ตัวเลข (,) &nbsp;
            <code>percent</code> ร้อยละ (%) &nbsp;
            <code>date</code> วันที่ &nbsp;
            <code>status</code> Pass/Fail badge &nbsp;
            <code>badge</code> Badge สี
        </div>

        <!-- Header row labels -->
        <div class="col-hdr-grid px-2 mb-1">
            <div class="small fw-bold text-muted text-uppercase" style="font-size:.68rem;letter-spacing:.04em">
                Field (จาก SQL)
            </div>
            <div class="small fw-bold text-muted text-uppercase" style="font-size:.68rem;letter-spacing:.04em">
                หัวตาราง (ภาษาไทย)
            </div>
            <div class="small fw-bold text-muted text-uppercase text-center" style="font-size:.68rem;letter-spacing:.04em">
                ประเภท
            </div>
            <div class="small fw-bold text-muted text-uppercase text-center" style="font-size:.68rem;letter-spacing:.04em">
                Align
            </div>
            <div class="small fw-bold text-muted text-uppercase text-center" style="font-size:.68rem;letter-spacing:.04em">
                กว้าง
            </div>
        </div>

        <div id="headerRowsWrap">
            <?php if (!empty($columnConfigs)): ?>
                <?php foreach ($columnConfigs as $i => $col): ?>
                <div class="col-hdr-row col-hdr-grid" data-idx="<?= $i ?>">
                    <input type="text" name="col_key[]" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($col['col_key']) ?>"
                           placeholder="field_name">
                    <input type="text" name="col_label[]" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($col['col_label']) ?>"
                           placeholder="หัวตาราง">
                    <select name="col_type[]" class="form-select form-select-sm">
                        <?php foreach (['text','number','percent','date','status','badge'] as $t): ?>
                        <option value="<?= $t ?>" <?= $col['col_type']===$t?'selected':'' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="col_align[]" class="form-select form-select-sm">
                        <option value="left"   <?= $col['col_align']==='left'  ?'selected':'' ?>>Left</option>
                        <option value="center" <?= $col['col_align']==='center'?'selected':'' ?>>Center</option>
                        <option value="right"  <?= $col['col_align']==='right' ?'selected':'' ?>>Right</option>
                    </select>
                    <div class="d-flex gap-1 align-items-center">
                        <input type="text" name="col_width[]" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($col['col_width']) ?>"
                               placeholder="px">
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-circle p-0 flex-shrink-0"
                                style="width:26px;height:26px"
                                onclick="this.closest('.col-hdr-row').remove()">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <button type="button" id="btnAddRow"
                class="btn btn-sm mt-2 w-100"
                style="border:1.5px dashed #0d9488;color:#0d9488;background:transparent;border-radius:8px;font-family:'Sarabun',sans-serif">
            <i class="bi bi-plus-lg me-1"></i>เพิ่มแถว
        </button>

        <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-success rounded-pill px-4">
                <i class="bi bi-floppy-fill me-1"></i>บันทึกหัวตาราง
            </button>
        </div>
    </form>
</div><!-- /tabHeaders -->

                    </div><!-- /tab-content -->
                </div><!-- /chart-card__body -->
            </div><!-- /chart-card -->

        <?php endif; ?>
        </div><!-- /col-lg-9 -->
    </div><!-- /row -->
</div><!-- /container -->

<!-- ══════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js"></script>
<script>
const KPI_ID   = <?= (int)$kpiId ?>;
const BASE_URL = location.href.split('?')[0];

// ── CodeMirror ────────────────────────────────────────────────
let cm = null;
const ta = document.getElementById('sqlEditorTA');
if (ta) {
    cm = CodeMirror.fromTextArea(ta, {
        mode        : 'text/x-mysql',
        theme       : 'dracula',
        lineNumbers : true,
        lineWrapping: true,
        matchBrackets     : true,
        autoCloseBrackets : true,
        extraKeys: {
            'Ctrl-Enter' : () => runSQL(),
            'Cmd-Enter'  : () => runSQL(),
        }
    });
}

// ── KPI search ────────────────────────────────────────────────
document.getElementById('kpiSearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.kpi-item').forEach(el => {
        const show = el.dataset.name.includes(q) || el.dataset.code.includes(q);
        el.style.display = show ? '' : 'none';
    });
});

// ── Helpers ───────────────────────────────────────────────────
function esc(s) {
    return String(s ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showSpinner(on) {
    document.getElementById('sqlSpinner')?.classList.toggle('d-none', !on);
    document.getElementById('sqlPlayIcon')?.classList.toggle('d-none', on);
}

// ── Shared SQL runner ─────────────────────────────────────────
let lastCols = [], lastRows = [];

async function runSQL() {
    if (!cm || !KPI_ID) return;
    const sql = cm.getValue().trim();
    if (!sql) return;
    const db  = document.getElementById('dbSourceSel')?.value || 'kpi';

    showSpinner(true);
    document.getElementById('resultStatus').style.display = 'none';
    document.getElementById('resultBox').style.display    = 'none';

    try {
        const fd = new FormData();
        fd.append('action',    'test_sql');
        fd.append('sql',       sql);
        fd.append('db_source', db);
        fd.append('kpi_id',    KPI_ID);

        const resp = await fetch(BASE_URL, { method: 'POST', body: fd });

        // ตรวจสอบว่า response เป็น JSON หรือไม่
        const text = await resp.text();
        let d;
        try {
            d = JSON.parse(text);
        } catch (e) {
            showStatus('error', 'Server error — response ไม่ใช่ JSON');
            console.error('Non-JSON response:', text.slice(0, 400));
            return;
        }

        if (!d.ok) {
            showStatus('error', d.error || 'เกิดข้อผิดพลาด');
            return;
        }

        lastCols = d.columns;
        lastRows = d.rows;
        renderTable(d.columns, d.rows, d.count);

    } catch (e) {
        showStatus('error', e.message);
    } finally {
        showSpinner(false);
    }
}

function showStatus(type, text) {
    const el = document.getElementById('resultStatus');
    const box= document.getElementById('resultBox');
    el.style.display = '';
    if (type === 'error') {
        el.style.background = '#450a0a';
        el.style.borderColor= '#991b1b';
        document.getElementById('resultStatusText').innerHTML =
            `<span style="color:#f87171"><i class="bi bi-x-circle me-2"></i>${esc(text)}</span>`;
        box.style.display = 'none';
    } else {
        el.style.background  = '#0f172a';
        el.style.borderColor = '#334155';
        document.getElementById('resultStatusText').innerHTML = text;
        box.style.display = '';
    }
}

function renderTable(cols, rows, count) {
    if (!cols.length) {
        showStatus('ok', `<span style="color:#fbbf24"><i class="bi bi-info-circle me-2"></i>0 rows</span>`);
        document.getElementById('resultBox').style.display = 'none';
        return;
    }

    const colBadge = `<span style="color:#6ee7b7;font-weight:600">${count} rows</span>
                      <span style="color:#475569;margin:0 .4rem">·</span>
                      <span style="color:#94a3b8">${cols.length} columns</span>`;
    showStatus('ok', colBadge);

    let h = '<table><thead><tr>';
    cols.forEach(c => { h += `<th>${esc(c)}</th>`; });
    h += '</tr></thead><tbody>';
    rows.slice(0, 50).forEach(row => {
        h += '<tr>';
        row.forEach(v => { h += `<td title="${esc(String(v ?? ''))}">${esc(String(v ?? ''))}</td>`; });
        h += '</tr>';
    });
    h += '</tbody></table>';
    if (count > 50) h += `<p style="color:#475569;text-align:center;padding:.5rem;font-size:.75rem">แสดง 50 จาก ${count} rows</p>`;
    document.getElementById('resultInner').innerHTML = h;
    document.getElementById('resultBox').style.display = '';

    // อัปเดต column picker ด้วย
    updateColPicker(cols);
}

document.getElementById('btnRunSQL')?.addEventListener('click', runSQL);

// ── Sync hidden inputs before formSQL submit ──────────────────
document.getElementById('formSQL')?.addEventListener('submit', e => {
    document.getElementById('hidSQL').value = cm ? cm.getValue() : '';
    document.getElementById('hidDb').value  = document.getElementById('dbSourceSel')?.value || 'kpi';
    // sync value_col จาก tab 2 ถ้ามี
    const vCol = document.getElementById('selectedValueCol');
    if (vCol) document.getElementById('hidVCol').value = vCol.value;
});

// ── updateColPicker: sync lastCols to chart tab pickers ─────────
function updateColPicker(cols) {
    // Tab2 col picker is handled by renderValColPicker
    // This just keeps lastCols in sync for chart pickers
    lastCols = cols;
}

// ── Tab2 Value Mode: โหลดคอลัมน์จาก SQL (Mode A) ──────────────
document.getElementById('btnLoadColsVal')?.addEventListener('click', async () => {
    if (!cm || !KPI_ID) return;
    const sql = cm.getValue().trim();
    if (!sql) { alert('กรุณาเขียน SQL ในแท็บ 1 ก่อน'); return; }
    const db  = document.getElementById('dbSourceSel')?.value || 'kpi';
    const sp  = document.getElementById('loadValSpinner');
    const st  = document.getElementById('valColStatus');
    sp?.classList.remove('d-none');
    st.textContent = 'กำลังโหลด...';
    try {
        const fd = new FormData();
        fd.append('action','test_sql'); fd.append('sql',sql);
        fd.append('db_source',db); fd.append('kpi_id',KPI_ID);
        const text = await (await fetch(BASE_URL,{method:'POST',body:fd})).text();
        let d; try { d=JSON.parse(text); } catch(e) { st.textContent='Server error'; return; }
        sp?.classList.add('d-none');
        if (!d.ok) { st.textContent = d.error; return; }
        lastCols = d.columns; lastRows = d.rows;
        st.innerHTML = `<span class="text-success">${d.columns.length} คอลัมน์ · ${d.count} rows</span>`;
        renderValColPicker(d.columns, d.rows);
        // also update the Tab1 auto-fill
        updateColPicker(d.columns);
    } catch(e) { sp?.classList.add('d-none'); st.textContent=e.message; }
});

function renderValColPicker(cols, rows) {
    const wrap   = document.getElementById('valColPicker');
    const saved  = document.getElementById('selValCol')?.value || '';
    if (!wrap) return;

    let html = `<div class="w-100 small fw-semibold text-muted mb-1">เลือกคอลัมน์ที่เป็นค่าผลลัพธ์:</div>`;
    cols.forEach(col => {
        html += `<label class="chart-col-option ${col===saved?'cco-active':''}">
            <input type="radio" name="valColRadio" value="${esc(col)}" ${col===saved?'checked':''}
                   onchange="onValColSelect(this,'${esc(col)}','${esc(JSON.stringify(rows).replace(/'/g,''))}')">
            <span class="col-name">${esc(col)}</span>
        </label>`;
    });
    wrap.innerHTML = html;

    // auto-preview if already has a saved value
    if (saved && rows.length) {
        const ci = cols.indexOf(saved);
        if (ci !== -1) previewValCol(saved, rows, ci);
    }
}

function onValColSelect(inp, colName, _rowsJson) {
    // update hidden inputs
    document.getElementById('selValCol').value = colName;
    document.getElementById('saveValColHid').value = colName;
    // update active class
    document.querySelectorAll('#valColPicker .chart-col-option').forEach(el => {
        el.classList.toggle('cco-active', el.querySelector('input')?.checked);
    });
    // show preview from lastRows
    if (lastRows.length) {
        const ci = lastCols.indexOf(colName);
        previewValCol(colName, lastRows, ci);
    }
}

function previewValCol(colName, rows, colIdx) {
    // latest row = last row in array
    const lastRow = rows[rows.length - 1];
    const val     = colIdx !== -1 ? lastRow[colIdx] : null;
    const preview = document.getElementById('valColPreview');
    const numEl   = document.getElementById('valColPreviewNum');
    const detEl   = document.getElementById('valColPreviewDetail');
    if (val !== null && val !== undefined) {
        numEl.textContent  = parseFloat(val).toFixed(2) + '%';
        detEl.textContent  = `จาก ${rows.length} rows · คอลัมน์: ${colName}`;
        preview.style.display = '';
        document.getElementById('selValNum').value = val;
        document.getElementById('btnPushCol').disabled = false;
    }
}

// ── Push Mode A (col) to kpi_results ─────────────────────────
document.getElementById('btnPushCol')?.addEventListener('click', async () => {
    const colKey = document.getElementById('selValCol')?.value;
    if (!colKey) { alert('กรุณาเลือกคอลัมน์ก่อน'); return; }
    if (!cm || !KPI_ID) return;
    const sql = cm.getValue().trim();
    const db  = document.getElementById('dbSourceSel')?.value || 'kpi';
    const sp  = document.getElementById('pushColSpinner');
    const res = document.getElementById('pushColResult');
    sp?.classList.remove('d-none');
    document.getElementById('btnPushCol').disabled = true;
    try {
        const fd = new FormData();
        fd.append('action','push_to_results'); fd.append('push_mode','col');
        fd.append('kpi_id',KPI_ID); fd.append('main_sql',sql);
        fd.append('value_col',colKey); fd.append('db_source',db);
        const text = await (await fetch(BASE_URL,{method:'POST',body:fd})).text();
        let d; try { d=JSON.parse(text); } catch(e) { res.innerHTML=errBox('Server error'); return; }
        if (d.ok) {
            res.innerHTML = `<div class="alert alert-success py-2">
                <i class="bi bi-check-circle-fill me-2"></i>${esc(d.msg)}
                — ค่า: <strong>${parseFloat(d.value).toFixed(2)}%</strong>
            </div>`;
        } else {
            res.innerHTML = errBox(d.error);
        }
    } catch(e) { res.innerHTML = errBox(e.message); }
    finally { sp?.classList.add('d-none'); document.getElementById('btnPushCol').disabled=false; }
});

// ── Tab2 Mode B: SQL แยก ──────────────────────────────────────
let resCm = null;
const resTa = document.getElementById('resSqlEd');
if (resTa) {
    resCm = CodeMirror.fromTextArea(resTa, {
        mode:'text/x-mysql', theme:'dracula', lineNumbers:true, lineWrapping:true,
        extraKeys:{ 'Ctrl-Enter':()=>testResSql(), 'Cmd-Enter':()=>testResSql() }
    });
    resCm.setSize(null, 120);
}

async function testResSql() {
    if (!resCm || !KPI_ID) return;
    const sql = resCm.getValue().trim(); if (!sql) return;
    const db  = document.getElementById('resSqlDb')?.value || 'kpi';
    const sp  = document.getElementById('resSqlSpinner');
    const box = document.getElementById('resSqlPreview');
    const st  = document.getElementById('resSqlStatus');
    sp?.classList.remove('d-none');
    box.style.display='none'; st.style.display='none';
    document.getElementById('valSqlPreview').style.display='none';
    document.getElementById('btnPushSql').disabled=true;
    try {
        const fd = new FormData();
        fd.append('action','test_sql'); fd.append('sql',sql);
        fd.append('db_source',db); fd.append('kpi_id',KPI_ID);
        const text = await (await fetch(BASE_URL,{method:'POST',body:fd})).text();
        let d; try { d=JSON.parse(text); } catch(e) { showResSt('error','Server error'); return; }
        sp?.classList.add('d-none');
        if (!d.ok) { showResSt('error',d.error); return; }
        if (!d.count) { showResSt('warn','SQL ไม่คืนข้อมูล'); return; }
        // show table
        let h='<table><thead><tr>'+d.columns.map(c=>`<th>${esc(c)}</th>`).join('')+'</tr></thead><tbody>';
        d.rows.slice(0,10).forEach(r=>{ h+='<tr>'+r.map(c=>`<td>${esc(String(c??''))}</td>`).join('')+'</tr>'; });
        h+='</tbody></table>';
        document.getElementById('resSqlPreviewInner').innerHTML = h;
        box.style.display='';
        // extract first cell of first row as value
        const val = d.rows[0][0];
        showResSt('ok',`${d.count} rows · ค่าที่จะบันทึก: <strong>${parseFloat(val).toFixed(2)}</strong>`);
        document.getElementById('valSqlNum').textContent    = parseFloat(val).toFixed(2)+'%';
        document.getElementById('valSqlDetail').textContent = `จาก ${d.count} rows · คอลัมน์: ${d.columns[0]}`;
        document.getElementById('valSqlPreview').style.display = '';
        document.getElementById('btnPushSql').disabled = false;
    } catch(e) { sp?.classList.add('d-none'); showResSt('error',e.message); }
}

function showResSt(type, text) {
    const st  = document.getElementById('resSqlStatus');
    const stx = document.getElementById('resSqlStatusText');
    st.style.display = '';
    if (type==='error') {
        st.style.background='#450a0a'; st.style.borderColor='#991b1b';
        stx.innerHTML=`<span style="color:#f87171"><i class="bi bi-x-circle me-1"></i>${text}</span>`;
    } else if (type==='warn') {
        st.style.background='#451a03'; st.style.borderColor='#b45309';
        stx.innerHTML=`<span style="color:#fbbf24">${text}</span>`;
    } else {
        st.style.background='#0f172a'; st.style.borderColor='#334155';
        stx.innerHTML=`<span style="color:#6ee7b7">${text}</span>`;
    }
}

document.getElementById('btnTestResSql')?.addEventListener('click', testResSql);

// ── Push Mode B (sql) to kpi_results ─────────────────────────
document.getElementById('btnPushSql')?.addEventListener('click', async () => {
    if (!resCm || !KPI_ID) return;
    const sql = resCm.getValue().trim(); if (!sql) return;
    const db  = document.getElementById('resSqlDb')?.value || 'kpi';
    const sp  = document.getElementById('pushSqlSpinner');
    const res = document.getElementById('pushSqlResult');
    sp?.classList.remove('d-none');
    document.getElementById('btnPushSql').disabled=true;
    try {
        const fd = new FormData();
        fd.append('action','push_to_results'); fd.append('push_mode','sql');
        fd.append('kpi_id',KPI_ID); fd.append('result_sql',sql); fd.append('result_db',db);
        const text = await (await fetch(BASE_URL,{method:'POST',body:fd})).text();
        let d; try { d=JSON.parse(text); } catch(e) { res.innerHTML=errBox('Server error'); return; }
        if (d.ok) {
            res.innerHTML = `<div class="alert alert-success py-2">
                <i class="bi bi-check-circle-fill me-2"></i>${esc(d.msg)}
                — ค่า: <strong>${parseFloat(d.value).toFixed(2)}%</strong>
            </div>`;
        } else {
            res.innerHTML = errBox(d.error);
        }
    } catch(e) { res.innerHTML=errBox(e.message); }
    finally { sp?.classList.add('d-none'); document.getElementById('btnPushSql').disabled=false; }
});

function errBox(msg) {
    return `<div class="alert alert-danger py-2"><i class="bi bi-x-circle me-2"></i>${esc(msg)}</div>`;
}

// ── Auto-fill header rows from SQL ───────────────────────────
// ── Auto-fill header rows from SQL ───────────────────────────
function doFillHeaders() {
    if (!lastCols.length) {
        alert('กรุณาทดสอบ SQL ก่อน (กด "ทดสอบ" ในแท็บ 1)');
        return;
    }
    const wrap = document.getElementById('headerRowsWrap');
    wrap.innerHTML = '';
    lastCols.forEach((col, i) => {
        wrap.appendChild(makeHeaderRow(col, col, i));
    });
    // switch to tab 3
    bootstrap.Tab.getOrCreateInstance(
        document.querySelector('[data-bs-target="#tabHeaders"]')
    ).show();
}
document.getElementById('btnFillHeaders')?.addEventListener('click',  doFillHeaders);
document.getElementById('btnFillHeaders2')?.addEventListener('click', doFillHeaders);

// ── Header row factory ────────────────────────────────────────
let rowCtr = <?= max(count($columnConfigs), 0) ?>;

function guessType(n) {
    n = (n || '').toLowerCase();
    if (/value|percent|rate|actual/.test(n)) return 'percent';
    if (/date|period|month|year/.test(n))    return 'date';
    if (/num|denom|count|total/.test(n))     return 'number';
    return 'text';
}

function makeHeaderRow(field = '', label = '', idx = null) {
    const i  = idx !== null ? idx : rowCtr++;
    const t  = guessType(field);
    const ac = (t === 'percent' || t === 'number' || t === 'date') ? 'center' : 'left';
    const div = document.createElement('div');
    div.className = 'col-hdr-row col-hdr-grid';
    div.dataset.idx = i;
    div.innerHTML = `
        <input type="text" name="col_key[]"   class="form-control form-control-sm"
               value="${esc(field)}" placeholder="field_name">
        <input type="text" name="col_label[]" class="form-control form-control-sm"
               value="${esc(label)}" placeholder="หัวตาราง">
        <select name="col_type[]" class="form-select form-select-sm">
            ${['text','number','percent','date','status','badge']
                .map(x => `<option value="${x}"${x===t?' selected':''}>${x}</option>`)
                .join('')}
        </select>
        <select name="col_align[]" class="form-select form-select-sm">
            <option value="left"${ac==='left'?' selected':''}>Left</option>
            <option value="center"${ac==='center'?' selected':''}>Center</option>
            <option value="right">Right</option>
        </select>
        <div class="d-flex gap-1 align-items-center">
            <input type="text" name="col_width[]" class="form-control form-control-sm" placeholder="px">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-circle p-0 flex-shrink-0"
                    style="width:26px;height:26px"
                    onclick="this.closest('.col-hdr-row').remove()">
                <i class="bi bi-x"></i>
            </button>
        </div>
    `;
    return div;
}

document.getElementById('btnAddRow')?.addEventListener('click', () => {
    document.getElementById('headerRowsWrap').appendChild(makeHeaderRow());
});

// ── Init: ถ้ามี config อยู่แล้ว ให้โหลด cols เพื่อ tab2 picker ──

// ── Chart Col Picker ──────────────────────────────────────────
const initBarX    = <?= json_encode($queryConfig['bar_x_col']    ?? '') ?>;
const initBarY    = <?= json_encode(array_filter(array_map('trim', explode(',', $queryConfig['bar_y_cols']   ?? '')))) ?>;
const initTrendX  = <?= json_encode($queryConfig['trend_x_col']  ?? '') ?>;
const initTrendY  = <?= json_encode(array_filter(array_map('trim', explode(',', $queryConfig['trend_y_cols'] ?? '')))) ?>;
const initPassRate= <?= json_encode($queryConfig['pass_rate_col'] ?? '') ?>;

async function loadChartCols() {
    if (!cm || !KPI_ID) return;
    const sql = cm.getValue().trim();
    if (!sql) { alert('กรุณาเขียน SQL ในแท็บ 1 ก่อน'); return; }
    const db = document.getElementById('dbSourceSel')?.value || 'kpi';
    const sp = document.getElementById('loadChartSpinner');
    sp?.classList.remove('d-none');
    try {
        const fd = new FormData();
        fd.append('action','test_sql'); fd.append('sql',sql);
        fd.append('db_source',db); fd.append('kpi_id',KPI_ID);
        const text = await (await fetch(BASE_URL,{method:'POST',body:fd})).text();
        let d; try { d = JSON.parse(text); } catch(e) { alert('Server error'); return; }
        sp?.classList.add('d-none');
        if (!d.ok) { alert('SQL Error: '+d.error); return; }
        lastCols = d.columns; lastRows = d.rows;

        const st = document.getElementById('chartColStatus');
        document.getElementById('chartColStatusText').textContent =
            `โหลด ${d.columns.length} คอลัมน์ · ${d.count} rows`;
        if (st) st.style.display = '';

        renderPickerRadio('passRatePicker', 'hidPassRateCol', d.columns, 'pr', initPassRate);
        renderPickerRadio('barXPicker',     'hidBarXCol',     d.columns, 'bx', initBarX);
        renderPickerCheck('barYPicker',     'hidBarYCols',    d.columns, 'by', initBarY);
        renderPickerRadio('trendXPicker',   'hidTrendXCol',   d.columns, 'tx', initTrendX);
        renderPickerCheck('trendYPicker',   'hidTrendYCols',  d.columns, 'ty', initTrendY);
    } catch(e) { sp?.classList.add('d-none'); alert(e.message); }
}
document.getElementById('btnLoadColsChart')?.addEventListener('click', loadChartCols);

// ── Generic radio picker ──────────────────────────────────────
function renderPickerRadio(wrapId, hidId, cols, radioName, savedVal) {
    const wrap = document.getElementById(wrapId);
    const hid  = document.getElementById(hidId);
    if (!wrap) return;
    const cur = hid?.value || savedVal || '';

    let html = `<label class="chart-col-option ${cur===''?'cco-active':''}">
        <input type="radio" name="${radioName}" value="" ${cur===''?'checked':''}
               onchange="pickerRadioChange(this,'${hidId}','${wrapId}')">
        <span class="col-name">— ไม่เลือก —</span>
    </label>`;
    cols.forEach(col => {
        html += `<label class="chart-col-option ${col===cur?'cco-active':''}">
            <input type="radio" name="${radioName}" value="${esc(col)}" ${col===cur?'checked':''}
                   onchange="pickerRadioChange(this,'${hidId}','${wrapId}')">
            <span class="col-name">${esc(col)}</span>
        </label>`;
    });
    wrap.innerHTML = html;
}

function pickerRadioChange(inp, hidId, wrapId) {
    document.getElementById(hidId).value = inp.value;
    document.querySelectorAll(`#${wrapId} .chart-col-option`).forEach(el => {
        el.classList.toggle('cco-active', el.querySelector('input')?.checked);
    });
}

// ── Generic checkbox picker ───────────────────────────────────
function renderPickerCheck(wrapId, hidId, cols, cbName, savedArr) {
    const wrap = document.getElementById(wrapId);
    const hid  = document.getElementById(hidId);
    if (!wrap) return;
    const curStr = hid?.value || '';
    const cur = curStr ? curStr.split(',').map(s=>s.trim()).filter(Boolean) : [...savedArr];

    let html = '';
    cols.forEach(col => {
        const chk = cur.includes(col);
        html += `<label class="chart-col-option chart-col-check ${chk?'cco-active':''}">
            <input type="checkbox" name="${cbName}" value="${esc(col)}" ${chk?'checked':''}
                   onchange="pickerCheckChange(this,'${hidId}','${wrapId}')">
            <span class="col-name">${esc(col)}</span>
        </label>`;
    });
    wrap.innerHTML = html || '<span class="text-muted small">ไม่มีคอลัมน์</span>';
}

function pickerCheckChange(inp, hidId, wrapId) {
    const checks = document.querySelectorAll(`#${wrapId} input[type=checkbox]:checked`);
    document.getElementById(hidId).value = Array.from(checks).map(c=>c.value).join(',');
    inp.closest('.chart-col-option')?.classList.toggle('cco-active', inp.checked);
}

// ── Auto-load on page ready ───────────────────────────────────
<?php if ($queryConfig && !empty($queryConfig['query_sql'])): ?>
(async () => {
    const fd = new FormData();
    fd.append('action',    'test_sql');
    fd.append('sql',       <?= json_encode($queryConfig['query_sql']) ?>);
    fd.append('db_source', <?= json_encode($queryConfig['db_source'] ?? 'kpi') ?>);
    fd.append('kpi_id',    KPI_ID);
    try {
        const text = await (await fetch(BASE_URL,{method:'POST',body:fd})).text();
        const d = JSON.parse(text);
        if (d.ok && d.columns.length) {
            lastCols = d.columns; lastRows = d.rows;
            updateColPicker(d.columns);
            renderPickerRadio('passRatePicker','hidPassRateCol',d.columns,'pr',initPassRate);
            renderPickerRadio('barXPicker',    'hidBarXCol',    d.columns,'bx',initBarX);
            renderPickerCheck('barYPicker',    'hidBarYCols',   d.columns,'by',initBarY);
            renderPickerRadio('trendXPicker',  'hidTrendXCol',  d.columns,'tx',initTrendX);
            renderPickerCheck('trendYPicker',  'hidTrendYCols', d.columns,'ty',initTrendY);
        }
    } catch(e) {}
})();
<?php endif; ?>

// ── District / Hospital SQL editors ──────────────────────────
let districtCm = null, hospitalCm = null;
const districtTa = document.getElementById('districtSqlEd');
const hospitalTa = document.getElementById('hospitalSqlEd');
if (districtTa) {
    districtCm = CodeMirror.fromTextArea(districtTa, {
        mode:'text/x-mysql', theme:'dracula', lineNumbers:true, lineWrapping:true,
        extraKeys:{'Ctrl-Enter':()=>testDrilldownSql('district'),'Cmd-Enter':()=>testDrilldownSql('district')}
    });
    districtCm.setSize(null, 160);
}
if (hospitalTa) {
    hospitalCm = CodeMirror.fromTextArea(hospitalTa, {
        mode:'text/x-mysql', theme:'dracula', lineNumbers:true, lineWrapping:true,
        extraKeys:{'Ctrl-Enter':()=>testDrilldownSql('hospital'),'Cmd-Enter':()=>testDrilldownSql('hospital')}
    });
    hospitalCm.setSize(null, 160);
}

async function testDrilldownSql(type) {
    const isD    = type === 'district';
    const cm     = isD ? districtCm : hospitalCm;
    const db     = document.getElementById(isD ? 'districtDbSel' : 'hospitalDbSel')?.value || 'kpi';
    const sp     = document.getElementById(isD ? 'districtSpinner'  : 'hospitalSpinner');
    const resBox = document.getElementById(isD ? 'districtResult'   : 'hospitalResult');
    const resTxt = document.getElementById(isD ? 'districtResultInner' : 'hospitalResultInner');
    const stBox  = document.getElementById(isD ? 'districtStatus'   : 'hospitalStatus');
    const stTxt  = document.getElementById(isD ? 'districtStatusText'  : 'hospitalStatusText');

    if (!cm || !KPI_ID) return;
    const sql = cm.getValue().trim();
    if (!sql) return;

    sp?.classList.remove('d-none');
    resBox.style.display = 'none'; stBox.style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('action','test_sql'); fd.append('sql',sql);
        fd.append('db_source',db); fd.append('kpi_id',KPI_ID);
        const text = await (await fetch(BASE_URL,{method:'POST',body:fd})).text();
        let d; try { d=JSON.parse(text); }
        catch(e) { stBox.style.display=''; stTxt.innerHTML='<span style="color:#f87171">Server error</span>'; sp?.classList.add('d-none'); return; }
        sp?.classList.add('d-none');

        if (!d.ok) {
            stBox.style.display='';
            stTxt.innerHTML=`<span style="color:#f87171"><i class="bi bi-x-circle me-1"></i>${esc(d.error)}</span>`;
            return;
        }

        // Show result table
        let h='<table><thead><tr>'+d.columns.map(c=>`<th>${esc(c)}</th>`).join('')+'</tr></thead><tbody>';
        d.rows.slice(0,10).forEach(r=>{h+='<tr>'+r.map(c=>`<td>${esc(String(c??''))}</td>`).join('')+'</tr>';});
        h+='</tbody></table>';
        resTxt.innerHTML = h;
        resBox.style.display = '';
        stBox.style.display = '';
        stTxt.innerHTML = `<span style="color:#6ee7b7">${d.count} rows · ${d.columns.length} cols</span>`;

        // Render 3 pickers for this type
        renderDrillPicker(type, 'label', d.columns, isD ? 'hidDistrictLabelCol' : 'hidHospitalLabelCol', 'dLbl_r hLbl_r'.split(' ')[isD?0:1]);
        renderDrillPicker(type, 'value', d.columns, isD ? 'hidDistrictValueCol' : 'hidHospitalValueCol', 'dVal_r hVal_r'.split(' ')[isD?0:1]);
        renderDrillPicker(type, 'pass',  d.columns, isD ? 'hidDistrictPassCol'  : 'hidHospitalPassCol',  'dPas_r hPas_r'.split(' ')[isD?0:1]);

    } catch(e) { sp?.classList.add('d-none'); stBox.style.display=''; stTxt.textContent=e.message; }
}

// Render a column picker (radio) for drilldown chart config
function renderDrillPicker(type, role, cols, hidId, radioName) {
    const prefix = type === 'district' ? 'district' : 'hospital';
    const capRole = role.charAt(0).toUpperCase() + role.slice(1);
    const wrapId = prefix + capRole + 'Picker';
    const wrap   = document.getElementById(wrapId);
    const hid    = document.getElementById(hidId);
    if (!wrap || !hid) return;
    const cur = hid.value || '';

    const labels = { label:'🏷 Label (ชื่อ)', value:'📊 ค่าแสดงใน Chart', pass:'🎯 ค่าเปรียบ Target' };
    let html = `<div class="w-100 small text-muted mb-1">${labels[role]}:</div>`;

    html += `<label class="chart-col-option ${cur===''?'cco-active':''}">
        <input type="radio" name="${radioName}" value=""
               onchange="document.getElementById('${hidId}').value='';syncDrillPicker('${wrapId}')">
        <span class="col-name">— ไม่เลือก —</span>
    </label>`;

    cols.forEach(col => {
        html += `<label class="chart-col-option ${col===cur?'cco-active':''}">
            <input type="radio" name="${radioName}" value="${esc(col)}" ${col===cur?'checked':''}
                   onchange="document.getElementById('${hidId}').value=this.value;syncDrillPicker('${wrapId}')">
            <span class="col-name">${esc(col)}</span>
        </label>`;
    });
    wrap.innerHTML = html;
}

function syncDrillPicker(wrapId) {
    document.querySelectorAll(`#${wrapId} .chart-col-option`).forEach(el => {
        el.classList.toggle('cco-active', el.querySelector('input')?.checked ?? false);
    });
}
document.getElementById('btnTestDistrict')?.addEventListener('click', ()=>testDrilldownSql('district'));
document.getElementById('btnTestHospital')?.addEventListener('click', ()=>testDrilldownSql('hospital'));

// ── Save drilldown SQL via formSQL ───────────────────────────
document.getElementById('btnSaveDrilldown')?.addEventListener('click', () => {
    // sync values into formSQL hidden fields
    const form = document.getElementById('formSQL');
    if (!form) return;

    const setHid = (name, val) => {
        let inp = form.querySelector(`input[name="${name}"]`);
        if (!inp) { inp = document.createElement('input'); inp.type='hidden'; inp.name=name; form.appendChild(inp); }
        inp.value = val;
    };

    if (cm)         setHid('query_sql', cm.getValue());
    if (districtCm) setHid('district_sql',        districtCm.getValue());
    setHid('district_db',         document.getElementById('districtDbSel')?.value       || 'kpi');
    setHid('district_label_col',  document.getElementById('hidDistrictLabelCol')?.value  || '');
    setHid('district_value_col',  document.getElementById('hidDistrictValueCol')?.value  || '');
    setHid('district_pass_col',   document.getElementById('hidDistrictPassCol')?.value   || '');
    if (hospitalCm) setHid('hospital_sql',        hospitalCm.getValue());
    setHid('hospital_db',         document.getElementById('hospitalDbSel')?.value        || 'kpi');
    setHid('hospital_label_col',  document.getElementById('hidHospitalLabelCol')?.value  || '');
    setHid('hospital_value_col',  document.getElementById('hidHospitalValueCol')?.value  || '');
    setHid('hospital_pass_col',   document.getElementById('hidHospitalPassCol')?.value   || '');
    setHid('db_source',           document.getElementById('dbSourceSel')?.value          || 'kpi');

    form.submit();
});

</script>
</body>
</html>