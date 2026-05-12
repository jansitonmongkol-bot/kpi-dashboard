<?php
/**
 * Auth.php — จัดการระบบ Authentication
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * คำนวณ base URL ของโปรเจกต์อัตโนมัติ
 * รองรับทั้ง localhost/ และ localhost/pidash/ หรือ subfolder อื่นๆ
 *
 * วิธีใช้: define BASE_PATH ใน index.php ของแต่ละ level
 *   root files (Login.php, index.php):  define('BASE_PATH', '');
 *   admin files (admin/xxx.php):         define('BASE_PATH', '..');
 * จากนั้น Auth จะใช้ BASE_PATH เพื่อสร้าง redirect URL ที่ถูกต้อง
 *
 * ถ้าไม่ได้ define BASE_PATH ระบบจะ detect จาก SCRIPT_NAME แทน
 */
function authBasePath(): string
{
    if (defined('BASE_PATH')) {
        // ถ้า define ไว้ด้วย string path เช่น '' หรือ '..'
        // แปลงเป็น URL path จาก document root
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir    = rtrim(dirname($script), '/\\');
        $base   = defined('BASE_PATH') && BASE_PATH === '..'
                    ? dirname($dir)   // admin subfolder → ขึ้นไป 1 ระดับ
                    : $dir;           // root level
        return rtrim($base, '/');
    }

    // fallback: ใช้ dirname ของ SCRIPT_NAME
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    return rtrim(dirname($script), '/\\');
}

function authUrl(string $file): string
{
    return authBasePath() . '/' . ltrim($file, '/');
}

class Auth
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    /**
     * สมัครสมาชิกใหม่ — role เริ่มต้นเป็น 'user' เสมอ
     */
    public function register(string $username, string $email, string $password, string $fullname): array
    {
        // ตรวจ username/email ซ้ำ
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            return ['success' => false, 'message' => 'Username หรือ Email นี้ถูกใช้แล้ว'];
        }
        $stmt->close();

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $role = 'user'; // ค่าเริ่มต้นเสมอ

        $stmt = $this->conn->prepare(
            "INSERT INTO users (username, email, password, fullname, role, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('sssss', $username, $email, $hash, $fullname, $role);

        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ'];
        }
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
    }

    /**
     * เข้าสู่ระบบ
     */
    public function login(string $username, string $password): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, username, email, fullname, role, password FROM users WHERE username = ? AND is_active = 1"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
        }

        $user = $result->fetch_assoc();

        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
        }

        // บันทึก session
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['fullname']  = $user['fullname'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['logged_in'] = true;

        // อัปเดต last_login
        $this->conn->query("UPDATE users SET last_login = NOW() WHERE id = {$user['id']}");

        return ['success' => true, 'role' => $user['role']];
    }

    /**
     * ออกจากระบบ
     */
    public static function logout(): void
    {
        session_destroy();
        header('Location: ' . authUrl('Login.php'));
        exit;
    }

    /**
     * ตรวจว่า login แล้วหรือยัง
     */
    public static function check(): bool
    {
        return !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    /**
     * ตรวจว่าเป็น admin หรือไม่
     */
    public static function isAdmin(): bool
    {
        return self::check() && $_SESSION['role'] === 'admin';
    }

    /**
     * บังคับ login ถ้ายังไม่ได้เข้าสู่ระบบ
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . authUrl('Login.php') . '?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
    }

    /**
     * บังคับ admin ถ้าไม่ใช่จะ redirect
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: ' . authUrl('index.php') . '?error=unauthorized');
            exit;
        }
    }
}

/**
 * สร้างตาราง users ถ้ายังไม่มี (รันครั้งแรก)
 */
function createUsersTableIfNotExists($conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            username    VARCHAR(50)  NOT NULL UNIQUE,
            email       VARCHAR(100) NOT NULL UNIQUE,
            password    VARCHAR(255) NOT NULL,
            fullname    VARCHAR(100) NOT NULL,
            role        ENUM('user','admin') NOT NULL DEFAULT 'user',
            is_active   TINYINT(1) NOT NULL DEFAULT 1,
            last_login  DATETIME,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // สร้าง admin เริ่มต้น (password: Admin@1234)
    $adminExists = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
    if ($adminExists && $adminExists->num_rows === 0) {
        $hash = password_hash('Admin@1234', PASSWORD_BCRYPT);
        $conn->query("
            INSERT IGNORE INTO users (username, email, password, fullname, role)
            VALUES ('admin', 'admin@pho.go.th', '$hash', 'ผู้ดูแลระบบ', 'admin')
        ");
    }
}
