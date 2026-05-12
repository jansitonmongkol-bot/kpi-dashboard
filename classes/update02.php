<?php
/**
 * sync_moph.php — Script ดึงข้อมูลจาก API เข้า Database
 */
require_once 'Database.php'; // ดึงการเชื่อมต่อ $conn จากไฟล์เดิมของคุณ

// ตั้งค่า API
$province_code = "25";
$fiscal_year = "2569";
$api_url = "https://opendata.moph.go.th/api/report_data/s_kpi_childdev4/$fiscal_year/$province_code";

echo "--- Starting Sync: " . date('Y-m-d H:i:s') . " ---\n";

// 1. ดึงข้อมูลจาก API
$json_data = file_get_contents($api_url);
if ($json_data === FALSE) {
    die("❌ Error: ไม่สามารถเข้าถึง API ได้\n");
}

$data = json_decode($json_data, true);
if (empty($data)) {
    die("⚠️ Warning: ไม่มีข้อมูลจาก API\n");
}

// 2. เตรียมคำสั่ง SQL (ใช้ REPLACE INTO เพื่อจัดการข้อมูลซ้ำโดยอ้างอิงจาก Primary Key 'id')
$sql = "REPLACE INTO child_dev_report 
        (id, hospcode, areacode, date_com, b_year, 
         target1, result1, target2, result2, 
         target3, result3, target4, result4) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

$count = 0;
foreach ($data as $row) {
    // ผูกค่าจาก JSON เข้ากับ SQL (ตรวจสอบชื่อ Key จาก API ให้ตรงกับ Database)
    $stmt->bind_param("sssssiiiiiiii", 
        $row['id'], $row['hospcode'], $row['areacode'], $row['date_com'], $row['b_year'],
        $row['target1'], $row['result1'], $row['target2'], $row['result2'],
        $row['target3'], $row['result3'], $row['target4'], $row['result4']
    );
    
    if ($stmt->execute()) {
        $count++;
    }
}

echo "✅ Sync สำเร็จ! บันทึกข้อมูลได้ทั้งหมด $count แถว\n";
$stmt->close();
$conn->close();