<?php
define('BASE_PATH', '..');
include '../classes/Database.php';
include '../classes/KPI.php';
include '../classes/Auth.php';

Auth::requireLogin();
if (Auth::isAdmin()) { header('Location: ' . authUrl('admin/index.php')); exit; }

$userId    = $_SESSION['user_id'];
$requestId = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$message   = '';
$msgType   = 'success';

// ตรวจว่า request นี้ approved และเป็นของ user นี้จริง
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
        <h3>⚠️ ไม่พบคำขอหรือยังไม่ได้รับการอนุมัติ</h3>
        <a href="' . authUrl('user/request_kpi.php') . '">← กลับหน้าคำขอ</a>
    </div>';
    exit;
}

// ---- ENSURE TABLES EXIST ----
$conn->query("
    CREATE TABLE IF NOT EXISTS kpi_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        user_id INT NOT NULL,
        kpi_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        row_count INT DEFAULT 0,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('processing','done','error') NOT NULL DEFAULT 'processing'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$conn->query("
    CREATE TABLE IF NOT EXISTS kpi_data_rows (
        id INT AUTO_INCREMENT PRIMARY KEY,
        submission_id INT NOT NULL,
        kpi_id INT NOT NULL,
        user_id INT NOT NULL,
        period_label VARCHAR(100),
        period_date DATE,
        numerator DECIMAL(15,2),
        denominator DECIMAL(15,2),
        actual_value DECIMAL(10,4),
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ---- HANDLE UPLOAD ----
$importedRows = 0;
$previewData  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file    = $_FILES['excel_file'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['xlsx','xls','csv'];

    if (!in_array($ext, $allowed)) {
        $message = 'รองรับเฉพาะไฟล์ .xlsx, .xls, .csv เท่านั้น';
        $msgType = 'danger';
    } elseif ($file['size'] > 10 * 1024 * 1024) {
        $message = 'ไฟล์ขนาดใหญ่เกิน 10MB';
        $msgType = 'danger';
    } else {
        $uploadDir  = '../uploads/kpi_data/';
        $safeFile   = time() . '_u' . $userId . '_kpi' . $reqRow['kid'] . '.' . $ext;
        $uploadPath = $uploadDir . $safeFile;

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            // Parse ไฟล์
            $rows = parseExcelFile($uploadPath, $ext);

            if (empty($rows)) {
                $message = 'ไม่พบข้อมูลในไฟล์ หรือรูปแบบไฟล์ไม่ถูกต้อง';
                $msgType = 'warning';
            } else {
                // บันทึก submission
                $stmt = $conn->prepare("
                    INSERT INTO kpi_submissions (request_id, user_id, kpi_id, file_name, file_path, row_count, status)
                    VALUES (?,?,?,?,?,?,'processing')
                ");
                $rowCnt = count($rows);
                $stmt->bind_param('iiissi', $requestId, $userId, $reqRow['kid'], $file['name'], $safeFile, $rowCnt);
                $stmt->execute();
                $submissionId = $conn->insert_id;

                // บันทึก rows
                $insertedOk = 0;
                $stmt2 = $conn->prepare("
                    INSERT INTO kpi_data_rows
                        (submission_id, kpi_id, user_id, period_label, period_date, numerator, denominator, actual_value, note)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");

                foreach ($rows as $row) {
                    $pLabel  = $row['period_label'] ?? '';
                    $pDate   = !empty($row['period_date']) ? $row['period_date'] : null;
                    $num     = isset($row['numerator'])   ? (float)$row['numerator']   : null;
                    $den     = isset($row['denominator']) ? (float)$row['denominator'] : null;
                    $actual  = isset($row['actual_value'])? (float)$row['actual_value']: null;

                    // คำนวณ actual_value จาก num/den ถ้าไม่มี
                    if ($actual === null && $num !== null && $den > 0) {
                        $actual = round($num / $den * 100, 4);
                    }
                    $note = $row['note'] ?? '';

                    $stmt2->bind_param('iiissddds',
                        $submissionId, $reqRow['kid'], $userId,
                        $pLabel, $pDate, $num, $den, $actual, $note
                    );
                    if ($stmt2->execute()) $insertedOk++;
                }

                // อัปเดต status
                $conn->query("UPDATE kpi_submissions SET status='done', row_count=$insertedOk WHERE id=$submissionId");

                $importedRows = $insertedOk;
                $message = "นำเข้าข้อมูลสำเร็จ $insertedOk รายการ! กรุณาตรวจสอบข้อมูลด้านล่าง";
                $msgType = 'success';

                // ดึง preview
                $previewData = $rows;
            }
        } else {
            $message = 'ไม่สามารถอัปโหลดไฟล์ได้ กรุณาตรวจสอบ permission ของโฟลเดอร์ uploads/';
            $msgType = 'danger';
        }
    }
}

// ---- ดึง submissions เก่าของ request นี้ ----
$submissions = [];
$res = $conn->query("
    SELECT * FROM kpi_submissions WHERE request_id=$requestId AND user_id=$userId
    ORDER BY uploaded_at DESC
");
if ($res) { while ($row = $res->fetch_assoc()) { $submissions[] = $row; } }

// ---- ฟังก์ชัน parse Excel/CSV ----
function parseExcelFile(string $path, string $ext): array
{
    $rows = [];

    if ($ext === 'csv') {
        // Parse CSV
        $handle = fopen($path, 'r');
        if (!$handle) return [];
        $headers = null;
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if (!$headers) { $headers = array_map('trim', $data); continue; }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[strtolower(str_replace(' ','_',$h))] = $data[$i] ?? '';
            }
            if (!empty(array_filter($row))) $rows[] = $row;
        }
        fclose($handle);
    } else {
        // Parse XLSX/XLS ด้วย PHP native (ไม่ต้องลง library)
        // ใช้ ZipArchive อ่าน xlsx
        if ($ext === 'xlsx') {
            $rows = parseXlsx($path);
        } else {
            // .xls fallback - ลอง read เป็น text
            $rows = parseXlsSimple($path);
        }
    }

    return $rows;
}

function parseXlsx(string $path): array
{
    $rows = [];
    try {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return [];

        // อ่าน shared strings
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ssDoc = new SimpleXMLElement($ssXml);
            foreach ($ssDoc->si as $si) {
                $text = '';
                foreach ($si->r as $r) { $text .= (string)$r->t; }
                if ($text === '') $text = (string)$si->t;
                $sharedStrings[] = $text;
            }
        }

        // อ่าน worksheet
        $wsXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!$wsXml) return [];

        $ws  = new SimpleXMLElement($wsXml);
        $all = [];
        foreach ($ws->sheetData->row as $rowEl) {
            $rowData = [];
            foreach ($rowEl->c as $cell) {
                $t   = (string)($cell['t'] ?? '');
                $val = (string)$cell->v;
                if ($t === 's') $val = $sharedStrings[(int)$val] ?? '';
                $rowData[] = $val;
            }
            $all[] = $rowData;
        }

        if (count($all) < 2) return [];

        // แถวแรกคือ header
        $headers = array_map(function($h) {
            return strtolower(trim(str_replace([' ','-'], '_', $h)));
        }, $all[0]);

        // Column mapping ยืดหยุ่น
        $colMap = [];
        $validCols = ['period_label','period_date','numerator','denominator','actual_value','note',
                      'เดือน','วันที่','ตัวเศษ','ตัวส่วน','ผลงาน','หมายเหตุ',
                      'month','date','num','den','value','result'];
        foreach ($headers as $i => $h) {
            $colMap[$h] = $i;
        }

        $colPeriodLabel  = findCol($colMap, ['period_label','เดือน','ช่วงเวลา','month','period']);
        $colPeriodDate   = findCol($colMap, ['period_date','date','วันที่','วัน']);
        $colNumerator    = findCol($colMap, ['numerator','num','ตัวเศษ','จำนวน','นับ','count']);
        $colDenominator  = findCol($colMap, ['denominator','den','ตัวส่วน','ประชากร','total']);
        $colActual       = findCol($colMap, ['actual_value','value','result','ผลงาน','ร้อยละ','%','percent']);
        $colNote         = findCol($colMap, ['note','หมายเหตุ','remark','comment']);

        for ($i = 1; $i < count($all); $i++) {
            $r = $all[$i];
            if (empty(array_filter($r))) continue;

            $rows[] = [
                'period_label' => getCol($r, $colPeriodLabel, ''),
                'period_date'  => parseDate(getCol($r, $colPeriodDate, '')),
                'numerator'    => getCol($r, $colNumerator, null),
                'denominator'  => getCol($r, $colDenominator, null),
                'actual_value' => getCol($r, $colActual, null),
                'note'         => getCol($r, $colNote, ''),
            ];
        }
    } catch (Exception $e) {
        error_log('parseXlsx error: ' . $e->getMessage());
    }
    return $rows;
}

function parseXlsSimple(string $path): array { return []; } // .xls ต้องใช้ library

function findCol(array $map, array $names): ?int {
    foreach ($names as $n) {
        if (isset($map[$n])) return $map[$n];
    }
    return null;
}

function getCol(array $row, ?int $idx, $default) {
    return ($idx !== null && isset($row[$idx]) && $row[$idx] !== '') ? $row[$idx] : $default;
}

function parseDate(string $val): ?string {
    if (empty($val)) return null;
    // Excel serial date
    if (is_numeric($val)) {
        $ts = ($val - 25569) * 86400;
        return date('Y-m-d', $ts);
    }
    // Try strtotime
    $ts = strtotime($val);
    return $ts ? date('Y-m-d', $ts) : null;
}

$activeMenu = 'request';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload ข้อมูล KPI — <?= htmlspecialchars($reqRow['kpi_name']) ?></title>
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
            <h4 class="page-title">
                <i class="bi bi-file-earmark-excel-fill me-2 text-success"></i>
                Upload ข้อมูล KPI
            </h4>
            <p class="page-subtitle">
                <span class="kpi-code me-2"><?= str_pad($reqRow['kpi_code'],3,'0',STR_PAD_LEFT) ?></span>
                <?= htmlspecialchars($reqRow['kpi_name']) ?>
                &nbsp;|&nbsp; เป้าหมาย <strong><?= htmlspecialchars($reqRow['target_operator']) ?> <?= $reqRow['target_value'] ?>%</strong>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= authUrl('user/request_kpi.php') ?>" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
            <?php if (!empty($submissions)): ?>
            <a href="<?= authUrl('user/dashboard.php') ?>?request_id=<?= $requestId ?>"
               class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-bar-chart-fill me-1"></i>ดู Dashboard
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
        <i class="bi bi-<?= $msgType==='success'?'check-circle-fill':'exclamation-triangle-fill' ?> me-2"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">

        <!-- UPLOAD FORM -->
        <div class="col-lg-5">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-cloud-upload-fill me-2 text-success"></i>Upload ไฟล์ Excel</h6>
                </div>
                <div class="chart-card__body">

                    <!-- Template download hint -->
                    <div class="template-hint mb-3">
                        <i class="bi bi-file-earmark-excel me-2 text-success"></i>
                        <div>
                            <strong>รูปแบบไฟล์ที่รองรับ:</strong> .xlsx, .csv<br>
                            <small class="text-muted">
                                คอลัมน์ที่รองรับ: <code>period_label</code>, <code>period_date</code>,
                                <code>numerator</code>, <code>denominator</code>, <code>actual_value</code>, <code>note</code>
                            </small>
                        </div>
                    </div>

                    <!-- Template table -->
                    <div class="table-responsive mb-3" style="font-size:.78rem;">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="table-success">
                                <tr>
                                    <th>period_label</th>
                                    <th>period_date</th>
                                    <th>numerator</th>
                                    <th>denominator</th>
                                    <th>actual_value</th>
                                    <th>note</th>
                                </tr>
                            </thead>
                            <tbody class="text-muted">
                                <tr>
                                    <td>ต.ค. 67</td>
                                    <td>2024-10-01</td>
                                    <td>120</td>
                                    <td>200</td>
                                    <td>60.00</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td>พ.ย. 67</td>
                                    <td>2024-11-01</td>
                                    <td>150</td>
                                    <td>200</td>
                                    <td>75.00</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted small mb-3">
                        💡 ถ้ามีแค่ <code>numerator</code> และ <code>denominator</code> ระบบจะคำนวณ % ให้อัตโนมัติ
                    </p>

                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <input type="hidden" name="request_id" value="<?= $requestId ?>">
                        <div class="upload-dropzone mb-3" id="dropzone">
                            <input type="file" name="excel_file" id="fileInput"
                                   accept=".xlsx,.xls,.csv" class="d-none" required>
                            <i class="bi bi-cloud-arrow-up-fill upload-icon"></i>
                            <p class="upload-text">ลากไฟล์มาวางที่นี่ หรือ</p>
                            <button type="button" class="btn btn-outline-success rounded-pill px-4"
                                    onclick="document.getElementById('fileInput').click()">
                                เลือกไฟล์
                            </button>
                            <p id="fileName" class="upload-filename mt-2 d-none"></p>
                        </div>
                        <button type="submit" class="btn btn-success w-100 rounded-pill fw-bold py-2" id="submitBtn" disabled>
                            <i class="bi bi-upload me-2"></i>นำเข้าข้อมูล
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- HISTORY -->
        <div class="col-lg-7">
            <div class="chart-card">
                <div class="chart-card__header">
                    <h6 class="chart-card__title"><i class="bi bi-clock-history me-2"></i>ประวัติการ Upload</h6>
                    <span class="badge bg-secondary"><?= count($submissions) ?> ครั้ง</span>
                </div>
                <?php if (empty($submissions)): ?>
                <div class="chart-card__body">
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>ยังไม่เคย Upload ข้อมูล</p>
                    </div>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table kpi-table mb-0">
                        <thead>
                            <tr>
                                <th>ชื่อไฟล์</th>
                                <th class="text-center">แถวข้อมูล</th>
                                <th class="text-center">สถานะ</th>
                                <th>วันที่</th>
                                <th class="text-center">ดู Dashboard</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($submissions as $sub): ?>
                        <tr>
                            <td>
                                <i class="bi bi-file-earmark-excel text-success me-1"></i>
                                <span class="small"><?= htmlspecialchars($sub['file_name']) ?></span>
                            </td>
                            <td class="text-center fw-bold"><?= $sub['row_count'] ?></td>
                            <td class="text-center">
                                <span class="badge <?= $sub['status']==='done'?'bg-success':($sub['status']==='error'?'bg-danger':'bg-warning text-dark') ?>">
                                    <?= $sub['status'] ?>
                                </span>
                            </td>
                            <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($sub['uploaded_at'])) ?></td>
                            <td class="text-center">
                                <a href="<?= authUrl('user/dashboard.php') ?>?request_id=<?= $requestId ?>"
                                   class="btn btn-sm btn-outline-primary rounded-pill">
                                    <i class="bi bi-bar-chart-fill"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- PREVIEW imported data -->
    <?php if (!empty($previewData)): ?>
    <div class="chart-card mb-5">
        <div class="chart-card__header">
            <h6 class="chart-card__title"><i class="bi bi-table me-2 text-success"></i>ข้อมูลที่นำเข้า (<?= $importedRows ?> รายการ)</h6>
            <a href="<?= authUrl('user/dashboard.php') ?>?request_id=<?= $requestId ?>"
               class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-bar-chart-fill me-1"></i>ดู Dashboard
            </a>
        </div>
        <div class="table-responsive">
            <table class="table kpi-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ช่วงเวลา</th>
                        <th class="text-center">ตัวเศษ</th>
                        <th class="text-center">ตัวส่วน</th>
                        <th class="text-center">ผลงาน (%)</th>
                        <th>หมายเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($previewData as $i => $row): ?>
                <tr>
                    <td class="text-muted small"><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($row['period_label'] ?? '') ?></td>
                    <td class="text-center"><?= number_format($row['numerator'] ?? 0, 0) ?></td>
                    <td class="text-center"><?= number_format($row['denominator'] ?? 0, 0) ?></td>
                    <td class="text-center fw-bold text-primary">
                        <?php
                        $av = $row['actual_value'] ?? null;
                        if ($av === null && ($row['denominator'] ?? 0) > 0) {
                            $av = round($row['numerator'] / $row['denominator'] * 100, 2);
                        }
                        echo $av !== null ? number_format($av, 2).'%' : '—';
                        ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($row['note'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const fileInput  = document.getElementById('fileInput');
const submitBtn  = document.getElementById('submitBtn');
const fileNameEl = document.getElementById('fileName');
const dropzone   = document.getElementById('dropzone');

fileInput.addEventListener('change', function () {
    if (this.files.length > 0) {
        fileNameEl.textContent = '📄 ' + this.files[0].name;
        fileNameEl.classList.remove('d-none');
        submitBtn.disabled = false;
    }
});

// Drag & Drop
dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
dropzone.addEventListener('dragleave', ()=> dropzone.classList.remove('drag-over'));
dropzone.addEventListener('drop', e => {
    e.preventDefault();
    dropzone.classList.remove('drag-over');
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        fileNameEl.textContent = '📄 ' + files[0].name;
        fileNameEl.classList.remove('d-none');
        submitBtn.disabled = false;
    }
});

// Loading on submit
document.getElementById('uploadForm').addEventListener('submit', function() {
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังนำเข้า...';
    submitBtn.disabled = true;
});
</script>
</body>
</html>