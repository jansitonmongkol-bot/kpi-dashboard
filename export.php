<?php
/**
 * export.php — ดาวน์โหลดข้อมูล KPI เป็น Excel / PDF / CSV
 * GET: ?id=<kpi_id>&type=excel|pdf|csv
 * ไม่บังคับ login สำหรับ CSV · Excel/PDF ต้อง login
 */
define('BASE_PATH', '');
include 'classes/Database.php';
include 'classes/KPI.php';
include 'classes/Auth.php';

$kpiId = (int)($_GET['id']   ?? 0);
$type  = strtolower($_GET['type'] ?? 'csv');

if (!$kpiId || !in_array($type, ['excel','pdf','csv'])) {
    http_response_code(400); echo 'Invalid request'; exit;
}

// Excel/PDF ต้อง login
if (in_array($type, ['excel','pdf']) && !Auth::check()) {
    header('Location: ' . authUrl('login.php') . '?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ── ดึง KPI หลัก ──────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM kpis WHERE kpi_id = ?");
$stmt->bind_param('i', $kpiId);
$stmt->execute();
$kpi = $stmt->get_result()->fetch_assoc();
if (!$kpi) { http_response_code(404); echo 'KPI not found'; exit; }

$target   = (float)$kpi['target_value'];
$operator = $kpi['target_operator'];
$kpiName  = $kpi['kpi_name'];
$kpiCode  = str_pad($kpi['kpi_code'], 3, '0', STR_PAD_LEFT);

// ── ดึง Custom SQL Config ─────────────────────────────────────
$queryConfig   = null;
$columnConfigs = [];
$useCustomSql  = false;
$customRows    = [];
$customCols    = [];

$tblQ = $conn->query("SHOW TABLES LIKE 'kpi_query_config'");
if ($tblQ && $tblQ->num_rows > 0) {
    $qr = $conn->query("SELECT * FROM kpi_query_config WHERE kpi_id=$kpiId AND is_active=1 LIMIT 1");
    if ($qr) $queryConfig = $qr->fetch_assoc();
}
$tblC = $conn->query("SHOW TABLES LIKE 'kpi_column_config'");
if ($tblC && $tblC->num_rows > 0) {
    $cr = $conn->query("SELECT * FROM kpi_column_config WHERE kpi_id=$kpiId ORDER BY col_order ASC");
    if ($cr) while ($r = $cr->fetch_assoc()) $columnConfigs[] = $r;
}

if ($queryConfig) {
    $rawSql = str_replace(':kpi_id', $kpiId, $queryConfig['query_sql']);
    $db     = ($queryConfig['db_source'] === 'hdc') ? ($con ?? $conn) : $conn;
    $res    = $db->query($rawSql);
    if ($res && $res->num_rows > 0) {
        $first = $res->fetch_assoc();
        $customCols = array_keys($first);
        $customRows[] = $first;
        while ($r = $res->fetch_assoc()) $customRows[] = $r;
        $useCustomSql = true;
    }
}

// ── ดึงข้อมูล default (ถ้าไม่มี custom SQL) ──────────────────
$histRows = [];
$userRows = [];
if (!$useCustomSql) {
    $res = $conn->query("SELECT actual_value, last_updated FROM kpi_results WHERE kpi_id=$kpiId ORDER BY last_updated ASC");
    if ($res) while ($r = $res->fetch_assoc()) $histRows[] = $r;

    $tc = $conn->query("SHOW TABLES LIKE 'kpi_data_rows'");
    if ($tc && $tc->num_rows > 0) {
        $res2 = $conn->query("
            SELECT dr.period_label, dr.period_date, dr.numerator,
                   dr.denominator, dr.actual_value, dr.note
            FROM kpi_data_rows dr
            INNER JOIN kpi_submissions s ON dr.submission_id = s.id
            WHERE dr.kpi_id = $kpiId
            ORDER BY COALESCE(dr.period_date, dr.created_at) ASC
        ");
        if ($res2) while ($r = $res2->fetch_assoc()) $userRows[] = $r;
    }
}

$hasUserData = !empty($userRows);

// ── หา value column ───────────────────────────────────────────
$passRateCol = $queryConfig['pass_rate_col'] ?? '';
$valueCol    = $queryConfig['value_col']     ?? '';
// fallback: หาคอลัมน์แรกที่เป็นตัวเลข
$numericCol  = '';
if ($useCustomSql && !empty($customRows)) {
    foreach ($customCols as $ck) {
        if (is_numeric($customRows[0][$ck] ?? '')) { $numericCol = $ck; break; }
    }
}
$mainValCol = $passRateCol ?: $valueCol ?: $numericCol;

// ── สร้าง unified rows ────────────────────────────────────────
$rows = [];
if ($useCustomSql) {
    foreach ($customRows as $i => $r) {
        $actual = $mainValCol && isset($r[$mainValCol]) ? (float)$r[$mainValCol] : 0;
        $pass   = evaluateKpi($actual, $target, $operator);
        $rows[] = array_merge(['_no' => $i + 1, '_pass' => $pass, '_actual' => $actual], $r);
    }
} elseif ($hasUserData) {
    foreach ($userRows as $i => $r) {
        $actual = (float)$r['actual_value'];
        $pass   = evaluateKpi($actual, $target, $operator);
        $rows[] = [
            '_no' => $i+1, '_pass' => $pass, '_actual' => $actual,
            'ช่วงเวลา'  => $r['period_label'] ?: ($r['period_date'] ? date('d/m/Y', strtotime($r['period_date'])) : '-'),
            'วันที่'    => $r['period_date'] ? date('d/m/Y', strtotime($r['period_date'])) : '-',
            'ตัวเศษ'   => $r['numerator'] ?? '',
            'ตัวส่วน'  => $r['denominator'] ?? '',
            'ผลงาน(%)'  => number_format($actual, 2),
            'เทียบเป้า' => ($actual-$target>=0?'+':'').number_format($actual-$target,2).'%',
            'หมายเหตุ' => $r['note'] ?? '',
        ];
    }
} else {
    foreach ($histRows as $i => $r) {
        $actual = (float)$r['actual_value'];
        $pass   = evaluateKpi($actual, $target, $operator);
        $rows[] = [
            '_no' => $i+1, '_pass' => $pass, '_actual' => $actual,
            'ช่วงเวลา'  => date('d/m/Y H:i', strtotime($r['last_updated'])),
            'ผลงาน(%)'  => number_format($actual, 2),
            'เทียบเป้า' => ($actual-$target>=0?'+':'').number_format($actual-$target,2).'%',
        ];
    }
}

// ── สถิติสรุป ─────────────────────────────────────────────────
$total     = count($rows);
$values    = array_column($rows, '_actual');
$passCount = count(array_filter($rows, fn($r) => $r['_pass']));
$failCount = $total - $passCount;
$avgVal    = $total > 0 ? round(array_sum($values) / $total, 2) : 0;
$maxVal    = $total > 0 ? max($values) : 0;
$minVal    = $total > 0 ? min($values) : 0;
$passRate  = $total > 0 ? round($passCount / $total * 100) : 0;

$safeKpiName = preg_replace('/[^ก-๙a-zA-Z0-9_\-]/', '_', $kpiName);
$dateStr     = date('Ymd');
$filename    = "KPI_{$kpiCode}_{$safeKpiName}_{$dateStr}";

// ─────────────────────────────────────────────────────────────
//  CSV
// ─────────────────────────────────────────────────────────────
if ($type === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: no-cache, no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM

    fputcsv($out, ['รายงานข้อมูลตัวชี้วัด (KPI)']);
    fputcsv($out, ['รหัส KPI', $kpiCode]);
    fputcsv($out, ['ชื่อตัวชี้วัด', $kpiName]);
    fputcsv($out, ['เป้าหมาย', $operator . ' ' . $target . '%']);
    fputcsv($out, ['วันที่ออกรายงาน', date('d/m/Y H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['สรุปผล']);
    fputcsv($out, ['จำนวนข้อมูลทั้งหมด', $total]);
    fputcsv($out, ['ผ่านเกณฑ์', $passCount]);
    fputcsv($out, ['ไม่ผ่านเกณฑ์', $failCount]);
    fputcsv($out, ['อัตราผ่านเกณฑ์ (%)', $passRate]);
    fputcsv($out, ['ค่าเฉลี่ย (%)', $avgVal]);
    fputcsv($out, ['ค่าสูงสุด (%)', $maxVal]);
    fputcsv($out, ['ค่าต่ำสุด (%)', $minVal]);
    fputcsv($out, []);

    if ($useCustomSql) {
        // หัวตาราง: ใช้ col_label จาก column config ถ้ามี ไม่งั้นใช้ชื่อ SQL column
        $visibleCols = [];
        if (!empty($columnConfigs)) {
            foreach ($columnConfigs as $cc) {
                if ($cc['is_visible']) $visibleCols[] = ['key'=>$cc['col_key'], 'label'=>$cc['col_label']];
            }
        } else {
            foreach ($customCols as $ck) {
                if (strpos($ck,'_')!==0) $visibleCols[] = ['key'=>$ck, 'label'=>$ck];
            }
        }
        $headers = ['ลำดับ'];
        foreach ($visibleCols as $vc) $headers[] = $vc['label'];
        $headers[] = 'สถานะ';
        fputcsv($out, $headers);

        foreach ($rows as $row) {
            $line = [$row['_no']];
            foreach ($visibleCols as $vc) $line[] = $row[$vc['key']] ?? '';
            $line[] = $row['_pass'] ? 'บรรลุเป้าหมาย' : 'ต่ำกว่าเป้าหมาย';
            fputcsv($out, $line);
        }
    } else {
        // default mode
        $headers = ['ลำดับ'];
        $dataKeys = [];
        if (!empty($rows)) {
            foreach (array_keys($rows[0]) as $k) {
                if (strpos($k,'_')!==0) { $headers[] = $k; $dataKeys[] = $k; }
            }
        }
        $headers[] = 'สถานะ';
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            $line = [$row['_no']];
            foreach ($dataKeys as $k) $line[] = $row[$k] ?? '';
            $line[] = $row['_pass'] ? 'บรรลุเป้าหมาย' : 'ต่ำกว่าเป้าหมาย';
            fputcsv($out, $line);
        }
    }
    fclose($out);
    exit;
}

// ─────────────────────────────────────────────────────────────
//  EXCEL & PDF — Python script
// ─────────────────────────────────────────────────────────────
$tmpJson = sys_get_temp_dir() . '/kpi_export_' . $kpiId . '_' . time() . '.json';

// สร้าง export rows ในรูปแบบมาตรฐาน
$exportRows = [];
if ($useCustomSql) {
    $visibleCols = [];
    if (!empty($columnConfigs)) {
        foreach ($columnConfigs as $cc) {
            if ($cc['is_visible']) $visibleCols[] = ['key'=>$cc['col_key'],'label'=>$cc['col_label']];
        }
    } else {
        foreach ($customCols as $ck) {
            if (strpos($ck,'_')!==0) $visibleCols[] = ['key'=>$ck,'label'=>$ck];
        }
    }
    foreach ($rows as $row) {
        $er = ['no'=>$row['_no'],'pass'=>$row['_pass'],'actual'=>$row['_actual']];
        foreach ($visibleCols as $vc) $er[$vc['label']] = $row[$vc['key']] ?? '';
        $exportRows[] = $er;
    }
    $exportHeaders = array_merge(['ลำดับ'], array_column($visibleCols,'label'), ['ผลงาน(%)', 'สถานะ']);
} else {
    foreach ($rows as $row) {
        $er = ['no'=>$row['_no'],'pass'=>$row['_pass'],'actual'=>$row['_actual']];
        foreach ($row as $k => $v) {
            if (strpos($k,'_')!==0) $er[$k] = $v;
        }
        $exportRows[] = $er;
    }
    $exportHeaders = null;
}

$payload = [
    'kpi_code'   => $kpiCode,
    'kpi_name'   => $kpiName,
    'target'     => $target,
    'operator'   => $operator,
    'pass_count' => $passCount,
    'fail_count' => $failCount,
    'avg_val'    => $avgVal,
    'max_val'    => $maxVal,
    'min_val'    => $minVal,
    'pass_rate'  => $passRate,
    'total'      => $total,
    'has_user'   => $hasUserData || $useCustomSql,
    'rows'       => $exportRows,
    'headers'    => $exportHeaders,
    'date'       => date('d/m/Y H:i:s'),
];
file_put_contents($tmpJson, json_encode($payload, JSON_UNESCAPED_UNICODE));

$scriptDir = __DIR__ . '/scripts';
$ext       = $type === 'excel' ? 'xlsx' : 'pdf';
$outPath   = sys_get_temp_dir() . '/' . $filename . '.' . $ext;
$script    = $type === 'excel' ? $scriptDir . '/gen_excel.py' : $scriptDir . '/gen_pdf.py';

// ลอง python3 ก่อน แล้ว python
$pythonBin = 'python3';
$cmd       = escapeshellcmd("$pythonBin $script " . escapeshellarg($tmpJson) . ' ' . escapeshellarg($outPath));
$output    = shell_exec($cmd . ' 2>&1');

if (!file_exists($outPath) || filesize($outPath) === 0) {
    $pythonBin = 'python';
    $cmd       = escapeshellcmd("$pythonBin $script " . escapeshellarg($tmpJson) . ' ' . escapeshellarg($outPath));
    $output    = shell_exec($cmd . ' 2>&1');
}

@unlink($tmpJson);

if (!file_exists($outPath) || filesize($outPath) === 0) {
    // Fallback: Excel/PDF ไม่ได้ → ส่ง CSV แทน
    @unlink($outPath);
    header('Location: export.php?id=' . $kpiId . '&type=csv');
    exit;
}

if ($type === 'excel') {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
} else {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
}
header('Content-Length: ' . filesize($outPath));
header('Cache-Control: no-cache, no-store');
readfile($outPath);
@unlink($outPath);
exit;