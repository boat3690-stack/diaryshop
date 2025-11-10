<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/coupon_helpers.php';
require_admin();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); redirect('settings_order.php'); }
  $hours = max(1, (int)($_POST['order_ttl_hours'] ?? 48));
  $st = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('order_ttl_hours',?)
                       ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  $st->execute([$hours]);
  flash('success','บันทึกแล้ว'); redirect('settings_order.php');
}

$ttl = order_ttl_hours($pdo);
include __DIR__ . '/../partials/header.php';
?>
<h2>ตั้งค่าออเดอร์</h2>
<div class="card" style="max-width:560px">
  <form method="post">
    <?= csrf_field() ?>
    <label>อายุบิล (ชั่วโมง)</label>
    <input class="input" type="number" name="order_ttl_hours" min="1" value="<?= (int)$ttl ?>">
    <p class="small" style="opacity:.8">หลังจากครบเวลานี้ ออเดอร์สถานะ “unpaid” จะถูกยกเลิกอัตโนมัติ</p>
    <button class="btn" type="submit">บันทึก</button>
  </form>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
