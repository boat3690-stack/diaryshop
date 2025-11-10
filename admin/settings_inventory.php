<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check($_POST['csrf']??'')) {
  $t = max(0,(int)($_POST['low_stock_threshold']??5));
  $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('low_stock_threshold',?)
                 ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$t]);
  flash('success','บันทึกค่าแจ้งเตือนเรียบร้อย'); header('Location: settings_inventory.php'); exit;
}
$val = (int)get_setting($pdo,'low_stock_threshold',5);

include __DIR__.'/../partials/header.php';
?>
<h2>ตั้งค่าสินค้า/สต็อก</h2>
<form method="post" class="card" style="max-width:480px">
  <?= csrf_field() ?>
  <label>เตือนเมื่อ “คงเหลือ” ≤</label>
  <input class="input" type="number" name="low_stock_threshold" value="<?= $val ?>" min="0" style="width:8rem">
  <div class="small" style="opacity:.8;margin-top:.25rem">ค่า 0 = ปิดการแจ้งเตือน</div>
  <button class="btn" type="submit" style="margin-top:.75rem">บันทึก</button>
</form>
<?php include __DIR__.'/../partials/footer.php'; ?>
