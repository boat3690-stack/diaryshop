<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth_user.php';

$err = '';
// รับ next จาก GET/POST เพื่อส่งต่อหลังล็อกอิน
$next = trim((string)($_GET['next'] ?? $_POST['next'] ?? ''));

// ฟังก์ชันตรวจความปลอดภัยของปลายทาง (กัน open redirect)
function is_safe_next(string $url): bool {
  if ($url === '') return false;
  // ไม่อนุญาตโปรโตคอลภายนอก และกัน \r \n
  if (preg_match('#^\s*(?:https?:)?//#i', $url)) return false;
  if (strpos($url, "\r") !== false || strpos($url, "\n") !== false) return false;
  // อนุญาตเฉพาะ path ภายในเว็บเท่านั้น
  return ($url[0] === '/');
}

// helper starts_with (รองรับ PHP ทุกเวอร์ชันสมัยใหม่)
function starts_with(string $s, string $needle): bool {
  return substr($s, 0, strlen($needle)) === $needle;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $err = 'โทเคนไม่ถูกต้อง'; }

  $id = trim($_POST['id'] ?? ''); // email หรือ เบอร์
  $pw = (string)($_POST['password'] ?? '');

  if (!$err && $id === '') $err = 'กรุณากรอกอีเมลหรือเบอร์โทร';
  if (!$err && $pw === '') $err = 'กรุณากรอกรหัสผ่าน';

  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  $key = strtolower($id);
  if (!$err && !throttle_check($pdo, $ip, $key)) $err = 'พยายามมากเกินไป โปรดลองใหม่ภายหลัง';

  if (!$err) {
    $u  = find_user_by_email_or_phone($pdo, $id);
    $ok = $u && !empty($u['password_hash']) && password_verify($pw, $u['password_hash']);
    throttle_log($pdo, $ip, $key, $ok);

    if ($ok) {
      set_login_session($u);
      // ตัดสินใจปลายทางหลังล็อกอิน
      $base = rtrim(BASE_PATH, '/');

      // ถ้ามี next และปลอดภัย → วิ่งตามนั้น
      if ($next !== '' && is_safe_next($next)) {
        // เล็กน้อย: ถ้าคุณเข้าจาก /admin/ ก็จะย้อนกลับไป /admin/ แล้วไฟล์ admin/index.php จะพาไป dashboard.php อีกที
        header('Location: ' . $next);
        exit;
      }

      // ถ้าไม่มี next: แอดมินไปแดชบอร์ด, ผู้ใช้ทั่วไปไปหน้าแรก
      $role = strtolower((string)($u['role'] ?? ''));
      $is_admin = (int)($u['is_admin'] ?? 0);
      $is_staff = (int)($u['is_staff'] ?? 0);
      $isAdminLike = ($role === 'admin' || $is_admin === 1 || $is_staff === 1);

      $dest = $isAdminLike ? ($base . '/admin/dashboard.php') : ($base . '/index.php');

      flash('success', 'เข้าสู่ระบบสำเร็จ');
      header('Location: ' . $dest);
      exit;
    } else {
      $err = 'ข้อมูลเข้าสู่ระบบไม่ถูกต้อง';
    }
  }
}

include __DIR__ . '/partials/header.php';
?>
<h2>เข้าสู่ระบบ</h2>
<?php if($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <!-- ส่งต่อ next กลับมาใน POST ด้วย -->
  <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
  <label>อีเมลหรือเบอร์โทร</label>
  <input class="input" name="id" value="<?= htmlspecialchars($_POST['id'] ?? '') ?>">
  <label>รหัสผ่าน</label>
  <input class="input" type="password" name="password">
  <div style="margin:.5rem 0">
<!--    <a class="btn outline" href="<?= BASE_PATH ?>/register.php">สร้างบัญชีใหม่</a>  -->
<!--    <a class="btn outline" href="<?= BASE_PATH ?>/forgot.php">ลืมรหัสผ่าน?</a>  -->
  </div>
  <button class="btn" type="submit">เข้าสู่ระบบ</button>
</form>
<?php include __DIR__ . '/partials/footer.php'; ?>
