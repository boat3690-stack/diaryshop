<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_check($_POST['csrf']??'')){ flash('error','CSRF'); redirect('shipping_settings.php'); }
  $pairs = [
    'ship_origin_name','ship_origin_phone','ship_origin_address','ship_origin_province','ship_origin_postcode',
    'carrier_jt_key','carrier_jt_secret','carrier_flash_key','carrier_flash_secret'
  ];
  foreach($pairs as $k){
    $v = trim($_POST[$k] ?? '');
    $st=$pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $st->execute([$k,$v]);
  }
  flash('success','บันทึกแล้ว'); redirect('shipping_settings.php');
}

include __DIR__ . '/../partials/header.php';
?>
<h2>ตั้งค่าขนส่ง</h2>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid" style="grid-template-columns:1fr 1fr; gap:1rem">
    <div class="card">
      <h3>ต้นทาง</h3>
      <label>ชื่อ/ร้าน</label><input class="input" name="ship_origin_name" value="<?= htmlspecialchars(get_setting($pdo,'ship_origin_name')) ?>">
      <label>โทร</label><input class="input" name="ship_origin_phone" value="<?= htmlspecialchars(get_setting($pdo,'ship_origin_phone')) ?>">
      <label>ที่อยู่</label><textarea class="input" name="ship_origin_address"><?= htmlspecialchars(get_setting($pdo,'ship_origin_address')) ?></textarea>
      <label>จังหวัด</label><input class="input" name="ship_origin_province" value="<?= htmlspecialchars(get_setting($pdo,'ship_origin_province')) ?>">
      <label>รหัสไปรษณีย์</label><input class="input" name="ship_origin_postcode" value="<?= htmlspecialchars(get_setting($pdo,'ship_origin_postcode')) ?>">
    </div>
    <div class="card">
      <h3>API Keys</h3>
      <label>J&T Key</label><input class="input" name="carrier_jt_key" value="<?= htmlspecialchars(get_setting($pdo,'carrier_jt_key')) ?>">
      <label>J&T Secret</label><input class="input" name="carrier_jt_secret" value="<?= htmlspecialchars(get_setting($pdo,'carrier_jt_secret')) ?>">
      <label>Flash Key</label><input class="input" name="carrier_flash_key" value="<?= htmlspecialchars(get_setting($pdo,'carrier_flash_key')) ?>">
      <label>Flash Secret</label><input class="input" name="carrier_flash_secret" value="<?= htmlspecialchars(get_setting($pdo,'carrier_flash_secret')) ?>">
    </div>
  </div>
  <button class="btn" type="submit">บันทึก</button>
</form>
<?php include __DIR__ . '/../partials/footer.php'; ?>
