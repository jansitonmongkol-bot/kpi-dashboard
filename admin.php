<?php
// Database config
$host = 'localhost';
$port = 3307;
$user = 'root';
$pass = 'Admin1234!';
$db   = 'kpi_database';

$conn = new mysqli($host, $user, $pass, $db, $port);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    die('<div style="padding:2rem;color:red;">เชื่อมต่อฐานข้อมูลไม่ได้: ' . $conn->connect_error . '</div>');
}

$msg = '';
$msgType = '';

// ===== HANDLE POST =====

// เพิ่ม/แก้ไข พนักงาน
if (isset($_POST['save_employee'])) {
    $id       = intval($_POST['emp_id'] ?? 0);
    $name     = $conn->real_escape_string(trim($_POST['full_name']));
    $position = $conn->real_escape_string(trim($_POST['position']));
    $depart   = intval($_POST['depart_id']);
    $uname    = $conn->real_escape_string(trim($_POST['user_name']));
    $pwd      = trim($_POST['password']);

    if ($id > 0) {
        $conn->query("UPDATE employees SET full_name='$name', position='$position', depart_id=$depart WHERE id=$id");
        if ($uname) {
            if ($pwd) {
                $hashed = password_hash($pwd, PASSWORD_BCRYPT);
                $conn->query("UPDATE login SET user_name='$uname', password='$hashed' WHERE employee_id=$id");
            } else {
                $conn->query("UPDATE login SET user_name='$uname' WHERE employee_id=$id");
            }
        }
        $msg = 'แก้ไขข้อมูลพนักงานสำเร็จ'; $msgType = 'success';
    } else {
        $conn->query("INSERT INTO employees (full_name, position, depart_id) VALUES ('$name','$position',$depart)");
        $empId = $conn->insert_id;
        if ($uname && $pwd) {
            $hashed = password_hash($pwd, PASSWORD_BCRYPT);
            $conn->query("INSERT INTO login (user_name, password, employee_id) VALUES ('$uname','$hashed',$empId)");
        }
        $msg = 'เพิ่มพนักงานสำเร็จ'; $msgType = 'success';
    }
}

// ลบพนักงาน
if (isset($_POST['delete_employee'])) {
    $id = intval($_POST['del_emp_id']);
    $conn->query("DELETE FROM login WHERE employee_id=$id");
    $conn->query("DELETE FROM kpi_assignment WHERE employees_id=$id");
    $conn->query("DELETE FROM employees WHERE id=$id");
    $msg = 'ลบพนักงานสำเร็จ'; $msgType = 'success';
}

// เพิ่ม/แก้ไข KPI
if (isset($_POST['save_kpi'])) {
    $id       = intval($_POST['kpi_id'] ?? 0);
    $name     = $conn->real_escape_string(trim($_POST['kpi_name']));
    $dec      = $conn->real_escape_string(trim($_POST['kpi_decition']));
    $target   = floatval($_POST['kpi_target_value']);
    $freq     = $conn->real_escape_string(trim($_POST['kpi_frequency']));
    $dividend = $conn->real_escape_string(trim($_POST['kpi_dividend']));
    $divisor  = $conn->real_escape_string(trim($_POST['kpi_divisor']));

    if ($id > 0) {
        $conn->query("UPDATE kpi SET kpi_name='$name', kpi_decition='$dec', kpi_target_value=$target, kpi_frequency='$freq', kpi_dividend='$dividend', kpi_divisor='$divisor' WHERE id=$id");
        $msg = 'แก้ไข KPI สำเร็จ'; $msgType = 'success';
    } else {
        $conn->query("INSERT INTO kpi (kpi_name, kpi_decition, kpi_target_value, kpi_frequency, kpi_dividend, kpi_divisor) VALUES ('$name','$dec',$target,'$freq','$dividend','$divisor')");
        $msg = 'เพิ่ม KPI สำเร็จ'; $msgType = 'success';
    }
}

// ลบ KPI
if (isset($_POST['delete_kpi'])) {
    $id = intval($_POST['del_kpi_id']);
    $conn->query("DELETE FROM kpi_assignment WHERE kpi_id=$id");
    $conn->query("DELETE FROM kpi WHERE id=$id");
    $msg = 'ลบ KPI สำเร็จ'; $msgType = 'success';
}

// กำหนด KPI ให้พนักงาน
if (isset($_POST['save_assign'])) {
    $empId = intval($_POST['assign_emp_id']);
    $kpiId = intval($_POST['assign_kpi_id']);
    $check = $conn->query("SELECT id FROM kpi_assignment WHERE employees_id=$empId AND kpi_id=$kpiId");
    if ($check->num_rows === 0) {
        $conn->query("INSERT INTO kpi_assignment (employees_id, kpi_id) VALUES ($empId, $kpiId)");
        $msg = 'กำหนด KPI สำเร็จ'; $msgType = 'success';
    } else {
        $msg = 'พนักงานคนนี้มี KPI นี้อยู่แล้ว'; $msgType = 'error';
    }
}

// ลบ Assignment
if (isset($_POST['delete_assign'])) {
    $id = intval($_POST['del_assign_id']);
    $conn->query("DELETE FROM kpi_assignment WHERE id=$id");
    $msg = 'ลบการกำหนด KPI สำเร็จ'; $msgType = 'success';
}

// ===== FETCH DATA =====
$departments = $conn->query("SELECT * FROM department ORDER BY depart_name");
$employees   = $conn->query("SELECT e.*, d.depart_name, l.user_name FROM employees e LEFT JOIN department d ON e.depart_id=d.id LEFT JOIN login l ON l.employee_id=e.id ORDER BY e.full_name");
$kpis        = $conn->query("SELECT * FROM kpi ORDER BY kpi_name");
$assignments = $conn->query("SELECT a.id, e.full_name, k.kpi_name FROM kpi_assignment a JOIN employees e ON a.employees_id=e.id JOIN kpi k ON a.kpi_id=k.id ORDER BY e.full_name");
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - ระบบตัวชี้วัด PART 4</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0f1117;--surface:#1a1d27;--surface2:#22263a;--border:#2e3347;
  --primary:#4f8ef7;--primary-dark:#3a72d8;--success:#2ecc71;--danger:#e74c3c;--warning:#f39c12;
  --text:#e8eaf0;--text2:#9ba3c0;--text3:#5a6080;
  --radius:10px;--radius-lg:16px;
}
body{font-family:'Sarabun',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:15px}
a{color:var(--primary);text-decoration:none}

/* Layout */
.header{background:var(--surface);border-bottom:1px solid var(--border);padding:1rem 2rem;display:flex;align-items:center;gap:1rem;position:sticky;top:0;z-index:100}
.header h1{font-size:1.2rem;font-weight:700;color:var(--text)}
.badge{background:var(--primary);color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:600}
.container{max-width:1200px;margin:0 auto;padding:2rem}
.grid{display:grid;gap:1.5rem}

/* Tabs */
.tabs{display:flex;gap:4px;background:var(--surface);padding:6px;border-radius:var(--radius-lg);margin-bottom:1.5rem;border:1px solid var(--border)}
.tab{flex:1;padding:.6rem 1rem;border:none;background:transparent;color:var(--text2);font-family:'Sarabun',sans-serif;font-size:14px;font-weight:500;border-radius:8px;cursor:pointer;transition:all .2s;white-space:nowrap;text-align:center}
.tab:hover{color:var(--text);background:var(--surface2)}
.tab.active{background:var(--primary);color:#fff}

/* Cards */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden}
.card-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:1rem;font-weight:600;color:var(--text)}
.card-body{padding:1.5rem}

/* Forms */
.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1rem}
.form-group{display:flex;flex-direction:column;gap:6px}
label{font-size:13px;font-weight:500;color:var(--text2)}
input,select,textarea{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:.6rem .9rem;color:var(--text);font-family:'Sarabun',sans-serif;font-size:14px;transition:border-color .2s;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary)}
select option{background:var(--surface2)}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:.55rem 1.2rem;border:none;border-radius:var(--radius);font-family:'Sarabun',sans-serif;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;white-space:nowrap}
.btn-primary{background:var(--primary);color:#fff}.btn-primary:hover{background:var(--primary-dark)}
.btn-success{background:var(--success);color:#fff}.btn-success:hover{filter:brightness(1.1)}
.btn-danger{background:var(--danger);color:#fff;padding:.4rem .8rem;font-size:13px}.btn-danger:hover{filter:brightness(1.1)}
.btn-warning{background:var(--warning);color:#fff;padding:.4rem .8rem;font-size:13px}.btn-warning:hover{filter:brightness(1.1)}
.btn-sm{padding:.35rem .7rem;font-size:12px}

/* Table */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14px}
th{background:var(--surface2);color:var(--text2);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.5px;padding:.8rem 1rem;text-align:left;border-bottom:1px solid var(--border)}
td{padding:.75rem 1rem;border-bottom:1px solid var(--border);color:var(--text);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--surface2)}
.actions{display:flex;gap:6px}

/* Alert */
.alert{padding:.85rem 1.2rem;border-radius:var(--radius);margin-bottom:1.5rem;font-weight:500;font-size:14px;display:flex;align-items:center;gap:8px}
.alert-success{background:#1a3a2a;border:1px solid #2ecc71;color:#2ecc71}
.alert-error{background:#3a1a1a;border:1px solid #e74c3c;color:#e74c3c}

/* Empty */
.empty{text-align:center;padding:2rem;color:var(--text3);font-size:14px}

/* Tab panels */
.tab-panel{display:none}.tab-panel.active{display:block}

/* Pill */
.pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600}
.pill-blue{background:#1a2a4a;color:#4f8ef7}
.pill-green{background:#1a3a2a;color:#2ecc71}
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>⚙️ ระบบจัดการข้อมูล KPI</h1>
  </div>
  <span class="badge">Admin</span>
  <a href="index.html" style="margin-left:auto;color:var(--text2);font-size:13px">← กลับหน้าหลัก</a>
</div>

<div class="container">

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>">
  <?= $msgType === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
  <button class="tab active" onclick="switchTab('employees',this)">👤 พนักงาน</button>
  <button class="tab" onclick="switchTab('kpi',this)">📊 ตัวชี้วัด KPI</button>
  <button class="tab" onclick="switchTab('assign',this)">🔗 กำหนด KPI</button>
</div>

<!-- ===== TAB: EMPLOYEES ===== -->
<div id="tab-employees" class="tab-panel active">
  <div class="grid" style="grid-template-columns:1fr 1fr;gap:1.5rem">

    <!-- Form เพิ่ม/แก้ไขพนักงาน -->
    <div class="card">
      <div class="card-header">
        <span class="card-title" id="emp-form-title">➕ เพิ่มพนักงาน</span>
        <button class="btn btn-sm" onclick="resetEmpForm()" style="background:var(--surface2);color:var(--text2)">รีเซ็ต</button>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="emp_id" id="emp_id" value="0">
          <div class="form-row">
            <div class="form-group">
              <label>ชื่อ-นามสกุล *</label>
              <input type="text" name="full_name" id="emp_full_name" required placeholder="กรอกชื่อ-นามสกุล">
            </div>
            <div class="form-group">
              <label>ตำแหน่ง</label>
              <input type="text" name="position" id="emp_position" placeholder="เช่น พยาบาลวิชาชีพ">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>แผนก *</label>
              <select name="depart_id" id="emp_depart" required>
                <option value="">-- เลือกแผนก --</option>
                <?php $departments->data_seek(0); while($d=$departments->fetch_assoc()): ?>
                <option value="<?=$d['id']?>"><?=htmlspecialchars($d['depart_name'])?></option>
                <?php endwhile; ?>
              </select>
            </div>
          </div>
          <div style="border-top:1px solid var(--border);margin:1rem 0;padding-top:1rem">
            <p style="font-size:13px;color:var(--text2);margin-bottom:.75rem">🔐 ข้อมูล Login</p>
            <div class="form-row">
              <div class="form-group">
                <label>Username</label>
                <input type="text" name="user_name" id="emp_username" placeholder="ชื่อผู้ใช้งาน">
              </div>
              <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" id="emp_password" placeholder="รหัสผ่าน (ถ้าแก้ไขเว้นว่างได้)">
              </div>
            </div>
          </div>
          <button type="submit" name="save_employee" class="btn btn-success" style="width:100%">💾 บันทึกข้อมูล</button>
        </form>
      </div>
    </div>

    <!-- ตารางพนักงาน -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">📋 รายชื่อพนักงาน</span>
        <span style="font-size:13px;color:var(--text2)"><?=$employees->num_rows?> คน</span>
      </div>
      <div class="card-body" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead><tr><th>ชื่อ</th><th>ตำแหน่ง</th><th>แผนก</th><th>Username</th><th></th></tr></thead>
            <tbody>
            <?php $employees->data_seek(0); while($e=$employees->fetch_assoc()): ?>
            <tr>
              <td><?=htmlspecialchars($e['full_name'])?></td>
              <td><span style="color:var(--text2);font-size:13px"><?=htmlspecialchars($e['position']??'-')?></span></td>
              <td><span class="pill pill-blue"><?=htmlspecialchars($e['depart_name']??'-')?></span></td>
              <td><span style="font-size:13px;color:var(--text2)"><?=htmlspecialchars($e['user_name']??'-')?></span></td>
              <td>
                <div class="actions">
                  <button class="btn btn-warning btn-sm" onclick="editEmp(<?=$e['id']?>,'<?=addslashes($e['full_name'])?>','<?=addslashes($e['position']??'')?>',<?=$e['depart_id']?>,'<?=addslashes($e['user_name']??'')?>')">✏️</button>
                  <form method="POST" onsubmit="return confirm('ลบพนักงานคนนี้?')">
                    <input type="hidden" name="del_emp_id" value="<?=$e['id']?>">
                    <button type="submit" name="delete_employee" class="btn btn-danger btn-sm">🗑️</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if($employees->num_rows===0): ?><tr><td colspan="5" class="empty">ยังไม่มีข้อมูลพนักงาน</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ===== TAB: KPI ===== -->
<div id="tab-kpi" class="tab-panel">
  <div class="grid" style="grid-template-columns:1fr 1fr;gap:1.5rem">

    <!-- Form เพิ่ม/แก้ไข KPI -->
    <div class="card">
      <div class="card-header">
        <span class="card-title" id="kpi-form-title">➕ เพิ่มตัวชี้วัด KPI</span>
        <button class="btn btn-sm" onclick="resetKpiForm()" style="background:var(--surface2);color:var(--text2)">รีเซ็ต</button>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="kpi_id" id="kpi_id" value="0">
          <div class="form-group" style="margin-bottom:1rem">
            <label>ชื่อตัวชี้วัด *</label>
            <input type="text" name="kpi_name" id="kpi_name" required placeholder="เช่น อัตราการติดเชื้อในโรงพยาบาล">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>ตัวตั้ง (Dividend)</label>
              <input type="text" name="kpi_dividend" id="kpi_dividend" placeholder="เช่น จำนวนผู้ป่วยติดเชื้อ">
            </div>
            <div class="form-group">
              <label>ตัวหาร (Divisor)</label>
              <input type="text" name="kpi_divisor" id="kpi_divisor" placeholder="เช่น จำนวนผู้ป่วยทั้งหมด">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>เงื่อนไขเป้าหมาย</label>
              <select name="kpi_decition" id="kpi_decition">
                <option value="<">น้อยกว่า (&lt;)</option>
                <option value="<=">น้อยกว่าหรือเท่ากับ (&lt;=)</option>
                <option value=">">มากกว่า (&gt;)</option>
                <option value=">=">มากกว่าหรือเท่ากับ (&gt;=)</option>
                <option value="=">เท่ากับ (=)</option>
              </select>
            </div>
            <div class="form-group">
              <label>ค่าเป้าหมาย *</label>
              <input type="number" name="kpi_target_value" id="kpi_target_value" step="0.01" required placeholder="เช่น 5.00">
            </div>
          </div>
          <div class="form-group" style="margin-bottom:1rem">
            <label>ความถี่</label>
            <select name="kpi_frequency" id="kpi_frequency">
              <option value="รายเดือน">รายเดือน</option>
              <option value="รายไตรมาส">รายไตรมาส</option>
              <option value="รายปี">รายปี</option>
            </select>
          </div>
          <button type="submit" name="save_kpi" class="btn btn-success" style="width:100%">💾 บันทึก KPI</button>
        </form>
      </div>
    </div>

    <!-- ตาราง KPI -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">📊 รายการ KPI</span>
        <span style="font-size:13px;color:var(--text2)"><?=$kpis->num_rows?> รายการ</span>
      </div>
      <div class="card-body" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead><tr><th>ชื่อ KPI</th><th>เป้าหมาย</th><th>ความถี่</th><th></th></tr></thead>
            <tbody>
            <?php $kpis->data_seek(0); while($k=$kpis->fetch_assoc()): ?>
            <tr>
              <td><?=htmlspecialchars($k['kpi_name'])?></td>
              <td><span class="pill pill-green"><?=htmlspecialchars($k['kpi_decition'])?> <?=$k['kpi_target_value']?></span></td>
              <td><span style="font-size:13px;color:var(--text2)"><?=htmlspecialchars($k['kpi_frequency']??'-')?></span></td>
              <td>
                <div class="actions">
                  <button class="btn btn-warning btn-sm" onclick="editKpi(<?=$k['id']?>,'<?=addslashes($k['kpi_name'])?>','<?=addslashes($k['kpi_decition'])?>',<?=$k['kpi_target_value']?>,'<?=addslashes($k['kpi_frequency']??'')?>','<?=addslashes($k['kpi_dividend']??'')?>','<?=addslashes($k['kpi_divisor']??'')?>')">✏️</button>
                  <form method="POST" onsubmit="return confirm('ลบ KPI นี้?')">
                    <input type="hidden" name="del_kpi_id" value="<?=$k['id']?>">
                    <button type="submit" name="delete_kpi" class="btn btn-danger btn-sm">🗑️</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if($kpis->num_rows===0): ?><tr><td colspan="4" class="empty">ยังไม่มี KPI</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ===== TAB: ASSIGN ===== -->
<div id="tab-assign" class="tab-panel">
  <div class="grid" style="grid-template-columns:380px 1fr;gap:1.5rem">

    <!-- Form กำหนด KPI -->
    <div class="card">
      <div class="card-header"><span class="card-title">🔗 กำหนด KPI ให้พนักงาน</span></div>
      <div class="card-body">
        <form method="POST">
          <div class="form-group" style="margin-bottom:1rem">
            <label>พนักงาน *</label>
            <select name="assign_emp_id" required>
              <option value="">-- เลือกพนักงาน --</option>
              <?php $employees->data_seek(0); while($e=$employees->fetch_assoc()): ?>
              <option value="<?=$e['id']?>"><?=htmlspecialchars($e['full_name'])?> (<?=htmlspecialchars($e['depart_name']??'')?>)</option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:1.5rem">
            <label>ตัวชี้วัด KPI *</label>
            <select name="assign_kpi_id" required>
              <option value="">-- เลือก KPI --</option>
              <?php $kpis->data_seek(0); while($k=$kpis->fetch_assoc()): ?>
              <option value="<?=$k['id']?>"><?=htmlspecialchars($k['kpi_name'])?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <button type="submit" name="save_assign" class="btn btn-primary" style="width:100%">✅ กำหนด KPI</button>
        </form>
      </div>
    </div>

    <!-- ตาราง Assignment -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">📋 รายการกำหนด KPI</span>
        <span style="font-size:13px;color:var(--text2)"><?=$assignments->num_rows?> รายการ</span>
      </div>
      <div class="card-body" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead><tr><th>พนักงาน</th><th>ตัวชี้วัด KPI</th><th></th></tr></thead>
            <tbody>
            <?php while($a=$assignments->fetch_assoc()): ?>
            <tr>
              <td><?=htmlspecialchars($a['full_name'])?></td>
              <td><span class="pill pill-blue"><?=htmlspecialchars($a['kpi_name'])?></span></td>
              <td>
                <form method="POST" onsubmit="return confirm('ลบการกำหนดนี้?')">
                  <input type="hidden" name="del_assign_id" value="<?=$a['id']?>">
                  <button type="submit" name="delete_assign" class="btn btn-danger btn-sm">🗑️ ลบ</button>
                </form>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if($assignments->num_rows===0): ?><tr><td colspan="3" class="empty">ยังไม่มีการกำหนด KPI</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

</div><!-- end container -->

<script>
function switchTab(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}

function editEmp(id, name, pos, depart, uname) {
  document.getElementById('emp_id').value = id;
  document.getElementById('emp_full_name').value = name;
  document.getElementById('emp_position').value = pos;
  document.getElementById('emp_depart').value = depart;
  document.getElementById('emp_username').value = uname;
  document.getElementById('emp_password').value = '';
  document.getElementById('emp-form-title').textContent = '✏️ แก้ไขพนักงาน';
  document.getElementById('emp_full_name').focus();
}

function resetEmpForm() {
  document.getElementById('emp_id').value = '0';
  document.getElementById('emp_full_name').value = '';
  document.getElementById('emp_position').value = '';
  document.getElementById('emp_depart').value = '';
  document.getElementById('emp_username').value = '';
  document.getElementById('emp_password').value = '';
  document.getElementById('emp-form-title').textContent = '➕ เพิ่มพนักงาน';
}

function editKpi(id, name, dec, target, freq, dividend, divisor) {
  document.getElementById('kpi_id').value = id;
  document.getElementById('kpi_name').value = name;
  document.getElementById('kpi_decition').value = dec;
  document.getElementById('kpi_target_value').value = target;
  document.getElementById('kpi_frequency').value = freq;
  document.getElementById('kpi_dividend').value = dividend;
  document.getElementById('kpi_divisor').value = divisor;
  document.getElementById('kpi-form-title').textContent = '✏️ แก้ไข KPI';
  document.getElementById('kpi_name').focus();
  // switch to kpi tab
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-kpi').classList.add('active');
  document.querySelectorAll('.tab')[1].classList.add('active');
}

function resetKpiForm() {
  document.getElementById('kpi_id').value = '0';
  ['kpi_name','kpi_dividend','kpi_divisor','kpi_target_value'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('kpi-form-title').textContent = '➕ เพิ่มตัวชี้วัด KPI';
}
</script>
</body>
</html>
