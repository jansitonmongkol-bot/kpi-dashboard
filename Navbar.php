<?php
/**
 * includes/navbar.php — Shared Navbar (รองรับ Guest / User / Admin)
 *
 * $activeMenu = 'dashboard' | 'admin' | 'users' | 'kpi' | 'highlight' | 'request' | 'mydata' | 'editor'
 * BASE_PATH ต้อง define ก่อน include:
 *   root files  → define('BASE_PATH', '');
 *   admin files → define('BASE_PATH', '..');
 *   user files  → define('BASE_PATH', '..');
 */
if (!isset($activeMenu)) $activeMenu = '';

$isLoggedIn = Auth::check();
$isAdmin    = Auth::isAdmin();

$urlDash      = authUrl('index.php');
$urlLogin     = authUrl('login.php');
$urlRegister  = authUrl('register.php');
$urlLogout    = authUrl('logout.php');
$urlKpi       = authUrl('admin/kpi_manage.php');
$urlHighlight = authUrl('admin/highlight.php');
$urlUsers     = authUrl('admin/users.php');
$urlRequests  = authUrl('admin/requests.php');
$urlEditor    = authUrl('admin/kpi_data_editor.php');
$urlMyData    = authUrl('user/my_data.php');
$urlReqKpi    = authUrl('user/request_kpi.php');

// นับคำขอรอ admin
$pendingCount = 0;
if ($isAdmin) {
    $pc = $conn->query("SELECT COUNT(*) AS c FROM kpi_requests WHERE status='pending'");
    if ($pc) $pendingCount = (int)$pc->fetch_assoc()['c'];
}
?>
<nav class="navbar navbar-expand-lg navbar-dark shadow-sm mb-4">
    <div class="container-fluid px-4">

        <!-- Brand -->
        <a class="navbar-brand fw-bold fs-5" href="<?= $urlDash ?>">
            <i class="bi bi-heart-pulse-fill me-2"></i>PHO KPI Dashboard
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">

            <!-- Left menu -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $activeMenu==='dashboard'?'active':'' ?>" href="<?= $urlDash ?>">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>

                <?php if ($isLoggedIn && !$isAdmin): ?>
                <!-- User menu -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($activeMenu,['request','mydata'])?'active':'' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-lines-fill me-1"></i>ข้อมูลของฉัน
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item <?= $activeMenu==='mydata'?'active':'' ?>"
                               href="<?= $urlMyData ?>">
                                <i class="bi bi-grid-3x3-gap-fill me-2"></i>ภาพรวม KPI ของฉัน
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $activeMenu==='request'?'active':'' ?>"
                               href="<?= $urlReqKpi ?>">
                                <i class="bi bi-file-earmark-plus me-2"></i>ขอเพิ่มข้อมูล KPI
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                <!-- Admin menu -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($activeMenu,['admin','kpi','users','highlight','requests','editor'])?'active':'' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-shield-lock-fill me-1"></i>Admin
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item <?= $activeMenu==='requests'?'active':'' ?>"
                               href="<?= $urlRequests ?>">
                                <i class="bi bi-file-earmark-check me-2"></i>คำขอเพิ่มข้อมูล
                                <?php if ($pendingCount>0): ?>
                                <span class="badge bg-danger ms-1"><?= $pendingCount ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item <?= $activeMenu==='kpi'?'active':'' ?>"
                               href="<?= $urlKpi ?>">
                                <i class="bi bi-clipboard2-data me-2"></i>จัดการข้อมูล KPI
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $activeMenu==='editor'?'active':'' ?>"
                               href="<?= $urlEditor ?>">
                                <i class="bi bi-code-square me-2"></i>แก้ไข SQL & หัวตาราง
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $activeMenu==='highlight'?'active':'' ?>"
                               href="<?= $urlHighlight ?>">
                                <i class="bi bi-stars me-2"></i>ตั้งค่า Highlight Cards
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item <?= $activeMenu==='users'?'active':'' ?>"
                               href="<?= $urlUsers ?>">
                                <i class="bi bi-people-fill me-2"></i>จัดการผู้ใช้งาน
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>

            <!-- Right: ปีงบประมาณ + User/Guest -->
            <ul class="navbar-nav ms-auto align-items-center gap-2">

                <li class="nav-item">
                    <span class="nav-link text-white-50 small">
                        <i class="bi bi-calendar3 me-1"></i>ปีงบประมาณ 2569
                    </span>
                </li>

                <?php if ($isLoggedIn): ?>
                <!-- Logged-in user dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2"
                       href="#" data-bs-toggle="dropdown">
                        <span class="user-avatar">
                            <?= mb_substr($_SESSION['fullname'] ?? 'U', 0, 1) ?>
                        </span>
                        <span class="text-white small d-none d-md-inline">
                            <?= htmlspecialchars($_SESSION['fullname'] ?? '') ?>
                        </span>
                        <?php if ($isAdmin): ?>
                        <span class="badge bg-warning text-dark small">Admin</span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                        <li class="dropdown-header text-white-50 small px-3 py-2">
                            <i class="bi bi-person-circle me-1"></i>
                            <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
                            <br>
                            <span class="badge <?= $isAdmin?'bg-warning text-dark':'bg-secondary' ?> mt-1">
                                <?= $isAdmin?'Admin':'User' ?>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= $urlLogout ?>">
                                <i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ
                            </a>
                        </li>
                    </ul>
                </li>

                <?php else: ?>
                <!-- Guest — แสดงปุ่ม Login -->
                <li class="nav-item">
                    <a href="<?= $urlLogin ?>?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
                       class="btn btn-sm btn-outline-light rounded-pill px-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i>เข้าสู่ระบบ
                    </a>
                </li>
                <li class="nav-item d-none d-md-block">
                    <a href="<?= $urlRegister ?>"
                       class="btn btn-sm btn-light text-teal rounded-pill px-3 fw-semibold">
                        <i class="bi bi-person-plus me-1"></i>สมัครใช้งาน
                    </a>
                </li>
                <?php endif; ?>

            </ul>
        </div>
    </div>
</nav>