<?php
// /admin/permissions.php  — fixed: checkbox post keys & hardening
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';

require_admin();
require_perm('settings.manage'); // super หรือคนที่มีสิทธิ์ตั้งค่าเท่านั้น

/* ---------- Helpers ---------- */
function ensure_perm_table(PDO $pdo): void {
  try {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'user_permissions'")->fetchColumn();
    if (!$has) {
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_permissions (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          perm VARCHAR(100) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY user_perm_unique (user_id, perm),
          KEY user_idx(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");
    }
  } catch (Throwable $e) {
    // ถ้ามีปัญหา ให้เด้งเตือนชัด ๆ
    flash('error', 'สร้างตารางสิทธิ์ไม่สำเร็จ: '.$e->getMessage());
    header('Location: permissions.php'); exit;
  }
}

if (!function_exists('get_admins')) {
  function get_admins(PDO $pdo): array {
    try {
      $st = $pdo->query("SELECT id, name, email FROM users WHERE role='admin' ORDER BY name");
      return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) { return []; }
  }
}

/* ---------- Permission Catalog ---------- */
$PERMS = [
  'admin.super'      => 'สิทธิ์สูงสุด (เห็น/ทำได้ทุกอย่าง)',
  'orders.view'      => 'ดูคำสั่งซื้อ',
  'orders.update'    => 'แก้ไขสถานะคำสั่งซื้อ',
  'orders.assign'    => 'กำหนดผู้รับผิดชอบออเดอร์',
  'payments.verify'  => 'ทำเครื่องหมายตรวจสลิป',
  'inventory.manage' => 'จัดการสินค้า/สต็อก',
  'coupons.manage'   => 'จัดการคูปอง',
  'reports.view'     => 'ดูรายงาน',
  'settings.manage'  => 'ตั้งค่าระบบ/ใบเสร็จ/แจ้งเตือน',
];

/* ---------- Handle Submit ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); header('Location: permissions.php'); exit; }

  $uid = (int)($_POST['user_id'] ?? 0);
  if ($uid<=0) { flash('error','ไม่พบผู้ใช้'); header('Location: permissions.php'); exit; }

  // อ่าน perms ที่ถูกติ๊ก: ใช้ name="perms[]" เพื่อหลีกเลี่ยงปัญหา dot-key
  $picked = $_POST['perms'] ?? [];
  if (!is_array($picked)) $picked = [];

  // กรองให้เหลือเฉพาะโค้ดที่ระบบรู้จัก
  $allowCodes = array_keys($PERMS);
  $picked = array_values(array_intersect($allowCodes, array_map('strval', $picked)));

  ensure_perm_table($pdo);

  try {
    $pdo->beginTransaction();

    // ล้างเก่า
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$uid]);

    // เพิ่มใหม่ (ถ้ามี)
    if ($picked) {
      $ins = $pdo->prepare("INSERT INTO user_permissions (user_id, perm) VALUES (?, ?)");
      foreach ($picked as $code) { $ins->execute([$uid, $code]); }
    }

    $pdo->commit();
    flash('success','บันทึกสิทธิ์ของผู้ใช้แล้ว');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error','บันทึกสิทธิ์ล้มเหลว: '.$e->getMessage());
  }
  header('Location: permissions.php?user_id='.$uid); exit;
}

/* ---------- Load UI Data ---------- */
ensure_perm_table($pdo);
$admins = get_admins($pdo);
$sel_id = (int)($_GET['user_id'] ?? ($admins[0]['id'] ?? 0));

$cur = [];
if ($sel_id) {
  $st=$pdo->prepare("SELECT perm FROM user_permissions WHERE user_id=?");
  $st->execute([$sel_id]);
  $cur = array_column($st->fetchAll(PDO::FETCH_ASSOC),'perm');
}

/* ---------- View ---------- */
include __DIR__ . '/../partials/header.php';
?>
<h2>กำหนดสิทธิ์แอดมิน</h2>

<form method="get" style="margin:.5rem 0">
  <label>เลือกผู้ใช้:</label>
  <select class="input" name="user_id" onchange="this.form.submit()">
    <?php foreach($admins as $a): ?>
      <option value="<?= (int)$a['id'] ?>" <?= $sel_id===(int)$a['id']?'selected':'' ?>>
        <?= htmlspecialchars($a['name'].' <'.$a['email'].'>') ?>
      </option>
    <?php endforeach; ?>
  </select>
</form>

<?php if ($sel_id): ?>
<div class="card" style="max-width:820px">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="user_id" value="<?= (int)$sel_id ?>">
    <table class="table">
      <tr><th>สิทธิ์</th><th>รายละเอียด</th><th style="width:90px">เปิด</th></tr>
      <?php foreach($PERMS as $code=>$label): ?>
        <tr>
          <td><code><?= htmlspecialchars($code) ?></code></td>
          <td><?= htmlspecialchars($label) ?></td>
          <td style="text-align:center">
            <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($code) ?>"
                   <?= in_array($code,$cur,true)?'checked':'' ?>>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <div style="margin-top:.6rem;display:flex;gap:.5rem;align-items:center">
      <button class="btn" type="submit">บันทึกสิทธิ์</button>
      <label class="small"><input type="checkbox" id="chkAll"> เลือกทั้งหมด</label>
      <label class="small"><input type="checkbox" id="chkNone"> ไม่เลือกทั้งหมด</label>
    </div>
  </form>
</div>

<script>
  // เลือก/ไม่เลือกทั้งหมด ช่วยให้ทำงานไวขึ้น
  (function(){
    const all = document.getElementById('chkAll');
    const none = document.getElementById('chkNone');
    function setAll(v){
      document.querySelectorAll('input[type="checkbox"][name="perms[]"]').forEach(cb => cb.checked = v);
    }
    all?.addEventListener('change', e => { if (e.target.checked){ setAll(true); none.checked=false; }});
    none?.addEventListener('change', e => { if (e.target.checked){ setAll(false); all.checked=false; }});
  })();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
