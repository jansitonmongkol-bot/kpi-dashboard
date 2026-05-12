<?php
/**
 * KPI.php — ดึงข้อมูล KPI และคำนวณสถานะ
 */

function getKpiDataFromDB($conn): array
{
    $sql = "
        SELECT
            k.kpi_id,
            k.kpi_code,
            k.kpi_name,
            k.target_value,
            k.target_operator,
            r.actual_value,
            r.last_updated
        FROM kpis k
        INNER JOIN kpi_results r ON k.kpi_id = r.kpi_id
        INNER JOIN (
            SELECT kpi_id, MAX(last_updated) AS max_updated
            FROM kpi_results
            GROUP BY kpi_id
        ) latest ON r.kpi_id = latest.kpi_id AND r.last_updated = latest.max_updated
        ORDER BY k.kpi_code ASC
    ";

    $result = $conn->query($sql);
    $data = ['kpi_db_id' => [], 'kpicode' => [], 'kpiname' => [], 'targets' => [], 'results' => [], 'operator' => []];

    if (!$result) {
        error_log('KPI query error: ' . $conn->error);
        return $data;
    }

    while ($row = $result->fetch_assoc()) {
        $data['kpi_db_id'][] = $row['kpi_id'];
        $data['kpicode'][]   = $row['kpi_code'];
        $data['kpiname'][]   = $row['kpi_name'];
        $data['targets'][]   = (float) $row['target_value'];
        $data['results'][]   = (float) $row['actual_value'];
        $data['operator'][]  = $row['target_operator'];
    }

    return $data;
}

function countKpiStatus(array $data): array
{
    $count = ['pass' => 0, 'fail' => 0];
    foreach ($data['results'] as $index => $result) {
        evaluateKpiStatus($result, $data['targets'][$index], $data['operator'][$index])
            ? $count['pass']++ : $count['fail']++;
    }
    return $count;
}

function evaluateKpiStatus(float $result, float $target, string $operator): bool
{
    switch ($operator) {
        case '>=': return $result >= $target;
        case '<=': return $result <= $target;
        case '>':  return $result >  $target;
        case '<':  return $result <  $target;
        case '=':  return abs($result - $target) < 0.0001;
        default:   return $result >= $target;
    }
}

// Alias สั้นสำหรับใช้ใน view
function evaluateKpi($result, $target, $operator): bool
{
    return evaluateKpiStatus((float)$result, (float)$target, (string)$operator);
}

/**
 * ดึงค่า highlight config จาก DB (ถ้ามีตาราง kpi_highlight_config)
 * ถ้าไม่มีตาราง ให้ใช้ค่า default
 */
function getHighlightConfig($conn): array
{
    $default = [
        ['kpi_index' => 8,  'icon' => 'bi-activity',     'color' => 'teal'],
        ['kpi_index' => 13, 'icon' => 'bi-droplet-half',  'color' => 'blue'],
        ['kpi_index' => 0,  'icon' => 'bi-search-heart',  'color' => 'orange'],
    ];

    $tableCheck = $conn->query("SHOW TABLES LIKE 'kpi_highlight_config'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return $default;
    }

    $result = $conn->query("SELECT * FROM kpi_highlight_config ORDER BY sort_order ASC LIMIT 3");
    if (!$result || $result->num_rows === 0) return $default;

    $config = [];
    while ($row = $result->fetch_assoc()) {
        $config[] = [
            'kpi_index' => (int)$row['kpi_index'],
            'icon'      => $row['icon'],
            'color'     => $row['color'],
        ];
    }
    return $config;
}