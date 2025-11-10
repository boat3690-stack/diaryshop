<?php
// includes/authz.php
// ชุด helper ฝั่งแอดมิน — ป้องกันประกาศซ้ำด้วย function_exists()

if (!function_exists('require_admin')) {
  function require_admin(): void {
    // ใช้ของเดิม: ผู้ใช้ต้อง login และเป็น admin
    if (!is_login() || !is_admin()) {
      $login = rtrim(BASE_PATH, '/').'/login.php';
      $next  = $_SERVER['REQUEST_URI'] ?? (rtrim(BASE_PATH,'/').'/');
      header('Location: '.$login.'?next='.urlencode($next));
      exit;
    }
  }
}

if (!function_exists('current_admin_id')) {
  function current_admin_id(): ?int {
    if (!is_admin()) return null;
    $id = (int)($_SESSION['user']['id'] ?? 0);
    return $id > 0 ? $id : null;
  }
}

if (!function_exists('has_perm')) {
  function has_perm(string $perm): bool {
    if (!is_admin()) return false;
    // super override
    if (_perm_check('admin.super')) return true;
    return _perm_check($perm);
  }
}

if (!function_exists('require_perm')) {
  function require_perm(string $perm) {
    if (!has_perm($perm)) {
      flash('error','ไม่มีสิทธิ์เข้าถึงส่วนนี้');
      redirect(BASE_URL.'/index.php');
    }
  }
}

if (!function_exists('_perm_check')) {
  function _perm_check(string $perm): bool {
    static $cache = null;
    global $pdo; // สำคัญ: ต้องมี global
    $uid = current_admin_id();
    if (!$uid) return false;

    if ($cache === null) {
      $st = $pdo->prepare("SELECT perm FROM user_permissions WHERE user_id=?");
      $st->execute([$uid]);
      $cache = array_fill_keys(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'perm'), true);
    }
    return isset($cache[$perm]);
  }
}

if (!function_exists('admin_log')) {
  function admin_log(PDO $pdo, string $action, string $entity_type, ?int $entity_id, array $meta = []) {
    $uid  = current_admin_id() ?? 0;
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
    // 7 คอลัมน์ = 7 placeholder
    $sql  = "INSERT INTO admin_logs(admin_id, action, entity_type, entity_id, meta, ip, user_agent)
             VALUES (?,?,?,?,?,?,?)";
    $st   = $pdo->prepare($sql);
    $st->execute([$uid, $action, $entity_type, $entity_id, $json, $ip, $ua]);
  }
}

if (!function_exists('get_admins')) {
  function get_admins(PDO $pdo): array {
    $st = $pdo->query("SELECT id,name,email FROM users WHERE role='admin' ORDER BY name");
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
