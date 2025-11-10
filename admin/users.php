<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ========= helpers ========= */
function count_admins(PDO $pdo): int {
  return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
}
function users_password_col(PDO $pdo): string {
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN, 0);
    return in_array('password_hash', $cols, true) ? 'password_hash' : 'password';
  } catch (Throwable $e) { return 'password_hash'; }
}
function ensure_perm_table(PDO $pdo): void {
  try {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'user_permissions'")->fetchColumn();
    if (!$has) {
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_permissions(
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          perm VARCHAR(100) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY user_perm_unique (user_id, perm),
          KEY user_idx(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");
    }
  } catch (Throwable $e) { /* no-op */ }
}
function add_perm(PDO $pdo, int $uid, string $perm): void {
  ensure_perm_table($pdo);
  $st = $pdo->prepare("SELECT 1 FROM user_permissions WHERE user_id=? AND perm=? LIMIT 1");
  $st->execute([$uid, $perm]);
  if (!$st->fetch()) {
    $pdo->prepare("INSERT INTO user_permissions(user_id,perm) VALUES(?,?)")->execute([$uid,$perm]);
  }
}
function remove_perm(PDO $pdo, int $uid, string $perm): void {
  ensure_perm_table($pdo);
  $pdo->prepare("DELETE FROM user_permissions WHERE user_id=? AND perm=?")->execute([$uid,$perm]);
}
function remove_all_perms(PDO $pdo, int $uid): void {
  ensure_perm_table($pdo);
  $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$uid]);
}

/* ========= actions ========= */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); header('Location: users.php'); exit; }

  $pwCol = users_password_col($pdo);

  // สร้างผู้ใช้ใหม่
  if ($action==='create') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = isset($_POST['is_admin']) ? 'admin' : 'customer';
    $pw = (string)($_POST['password'] ?? '');

    if ($name==='' || $email==='' || $pw==='') {
      flash('error','กรอกชื่อ อีเมล และรหัสผ่าน'); header('Location: users.php'); exit;
    }

    try {
      $sql = "INSERT INTO users(name,email,phone,role,{$pwCol}) VALUES (?,?,?,?,?)";
      $pdo->prepare($sql)->execute([$name,$email,$phone,$role,password_hash($pw,PASSWORD_DEFAULT)]);
      $uid = (int)$pdo->lastInsertId();

      // ถ้าเป็นแอดมินใหม่ ให้สิทธิ์สูงสุด
      if ($role==='admin') add_perm($pdo, $uid, 'admin.super');

      flash('success','สร้างผู้ใช้เรียบร้อย'); header('Location: users.php'); exit;
    } catch (PDOException $e) {
      if (stripos($e->getMessage(),'Duplicate')!==false) flash('error','อีเมลนี้ถูกใช้แล้ว');
      else flash('error','เกิดข้อผิดพลาด: '.$e->getMessage());
      header('Location: users.php'); exit;
    }
  }

  // เลื่อนเป็นแอดมิน
  if ($action==='promote') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$id]);
    add_perm($pdo, $id, 'admin.super'); // ให้สิทธิ์เหมือนแอดมินสูงสุด
    flash('success','เลื่อนเป็นแอดมินแล้ว และให้สิทธิ์เต็มเรียบร้อย'); header('Location: users.php'); exit;
  }

  // ถอดเป็นลูกค้า (กันไม่ให้เหลือแอดมิน 0 คน)
  if ($action==='demote') {
    $id = (int)($_POST['id'] ?? 0);
    if (count_admins($pdo) <= 1) {
      flash('error','ถอดไม่ได้: ต้องมีแอดมินอย่างน้อย 1 คน'); header('Location: users.php'); exit;
    }
    $pdo->prepare("UPDATE users SET role='customer' WHERE id=?")->execute([$id]);
    remove_all_perms($pdo, $id); // เอาสิทธิ์แอดมินออกทั้งหมด
    flash('success','ถอดแอดมินแล้ว'); header('Location: users.php'); exit;
  }

  // รีเซ็ตรหัสผ่าน
  if ($action==='reset_pw') {
    $id = (int)($_POST['id'] ?? 0);
    $pw = (string)($_POST['password'] ?? '');
    if ($pw===''){ flash('error','กรอกรหัสผ่านใหม่'); header('Location: users.php'); exit; }
    $pdo->prepare("UPDATE users SET {$pwCol}=? WHERE id=?")->execute([password_hash($pw,PASSWORD_DEFAULT), $id]);
    flash('success','อัปเดตรหัสผ่านแล้ว'); header('Location: users.php'); exit;
  }

  // ลบผู้ใช้
  if ($action==='delete') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $pdo->prepare("SELECT role FROM users WHERE id=?"); $row->execute([$id]); $r=$row->fetch();
    if ($r && $r['role']==='admin' && count_admins($pdo) <= 1) {
      flash('error','ลบไม่ได้: ต้องเหลือแอดมินอย่างน้อย 1 คน'); header('Location: users.php'); exit;
    }
    remove_all_perms($pdo, $id); // เคลียร์สิทธิ์ก่อน
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    flash('success','ลบผู้ใช้แล้ว'); header('Location: users.php'); exit;
  }
}

/* ========= list / filter ========= */
$q = trim($_GET['q'] ?? '');
$roleF = $_GET['role'] ?? '';
$where=[]; $p=[];
if ($q!==''){ $where[]="(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)"; $p=["%$q%","%$q%","%$q%"]; }
if ($roleF==='admin'){ $where[]="u.role='admin'"; }
if ($roleF==='customer'){ $where[]="u.role='customer'"; }
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$rows = $pdo->prepare("SELECT u.* FROM users u $whereSql ORDER BY u.created_at DESC");
$rows->execute($p);

include __DIR__ . '/../partials/header.php';
?>
<h2>ผู้ใช้ระบบ</h2>

<form method="get" class="filterbar" style="display:flex;gap:.5rem;align-items:center;margin:.75rem 0">
  <input class="input" type="text" name="q" placeholder="ค้นหาชื่อ/อีเมล/โทร" value="<?= htmlspecialchars($q) ?>">
  <select class="input" name="role">
    <option value="">ทุกบทบาท</option>
    <option value="admin" <?= $roleF==='admin'?'selected':'' ?>>admin</option>
    <option value="customer" <?= $roleF==='customer'?'selected':'' ?>>customer</option>
  </select>
  <button class="btn" type="submit">ค้นหา</button>
  <a class="btn outline" href="users.php">รีเซ็ต</a>
</form>

<div class="card">
  <h3>เพิ่มผู้ใช้</h3>
  <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div>
      <label>ชื่อ</label><input class="input" name="name" required>
      <label>อีเมล</label><input class="input" type="email" name="email" required>
      <label>โทรศัพท์</label><input class="input" name="phone">
    </div>
    <div>
      <label>รหัสผ่าน</label><input class="input" type="password" name="password" required>
      <label><input type="checkbox" name="is_admin"> สร้างเป็นแอดมิน</label>
      <div style="margin-top:1.8rem"><button class="btn" type="submit">บันทึกผู้ใช้</button></div>
    </div>
  </form>
</div>

<div class="card">
  <h3>รายการผู้ใช้</h3>
  <table class="table">
    <tr><th>#</th><th>ชื่อ</th><th>อีเมล</th><th>โทร</th><th>บทบาท</th><th>สร้างเมื่อ</th><th>จัดการ</th></tr>
    <?php foreach($rows as $u): ?>
    <tr>
      <td><?= (int)$u['id'] ?></td>
      <td class="left"><?= htmlspecialchars($u['name']) ?></td>
      <td class="left"><?= htmlspecialchars($u['email']) ?></td>
      <td><?= htmlspecialchars($u['phone'] ?: '-') ?></td>
      <td><span class="badge"><?= htmlspecialchars($u['role']) ?></span></td>
      <td class="small"><?= htmlspecialchars($u['created_at']) ?></td>
      <td class="left" style="display:flex;gap:.4rem;flex-wrap:wrap">
        <?php if ($u['role']!=='admin'): ?>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="promote">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn" type="submit">เลื่อนเป็นแอดมิน</button>
          </form>
        <?php else: ?>
          <form method="post" onsubmit="return confirm('ถอดแอดมิน?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="demote">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn outline" type="submit">ถอดเป็นลูกค้า</button>
          </form>
        <?php endif; ?>

        <details>
          <summary class="btn outline">รีเซ็ตรหัส</summary>
          <form method="post" class="card" style="padding:.5rem;margin-top:.4rem">
            <?= csrf_field() ?><input type="hidden" name="action" value="reset_pw">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <label>รหัสผ่านใหม่</label>
            <input class="input" type="password" name="password" required>
            <button class="btn" type="submit">อัปเดต</button>
          </form>
        </details>

        <form method="post" onsubmit="return confirm('ลบผู้ใช้นี้?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <button class="btn" type="submit">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<style>
body .container, .container { max-width: 100% !important; width: 100% !important; padding-left: 1rem; padding-right: 1rem; }
</style>
<?php include __DIR__ . '/../partials/footer.php'; ?>
