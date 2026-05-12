<?php
/**
 * Database.php — เชื่อมต่อฐานข้อมูล
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dbConfig = [
    'host'     => getenv('DB_HOST')     ?: '',
    'user'     => getenv('DB_USER')     ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
    'db_kpi'   => getenv('DB_KPI')      ?: '',
];

ini_set('max_execution_time', 500);

$conn = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['password'], $dbConfig['db_kpi']);

if ($conn->connect_error) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;padding:40px;color:#dc2626;">
        <h3>⚠️ ไม่สามารถเชื่อมต่อฐานข้อมูลได้</h3>
        <p>กรุณาติดต่อผู้ดูแลระบบ</p>
    </div>');
}

if ($con->connect_error) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;padding:40px;color:#dc2626;">
        <h3>⚠️ ไม่สามารถเชื่อมต่อฐานข้อมูล HDC ได้</h3>
        <p>กรุณาติดต่อผู้ดูแลระบบ</p>
    </div>');
}

$conn->set_charset('utf8mb4');