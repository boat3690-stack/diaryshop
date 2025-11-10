<?php
require_once __DIR__ . '/config/config.php';

$found = null; $items = []; $pay = null; $err = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check($_POST['csrf'] ?? '')) {
  $id  = (int)($_POST['order_id'] ?? 0);
  $key = trim($_POST['key'] ?? '');
  if ($id>0 && $key!=='') {
    $st = $pdo->prepare("SELECT * FROM orders WHERE id=? AND (phone=? OR email=?)");
    $st->execute([$id,$key,$key]);
    $found = $st->fetch();
    if (!$found) $err = 'ไม่พบออเดอร์ตามข้อมูลที่ให้ไว้';
  }
}

if (isset($_GET['upload']) && $_SERVER['REQUEST_METHOD']==='POST' && csrf_check($_POST['csrf'] ?? '')) {
  $oid = (int)($_POST['oid'] ?? 0);
  $slip = handle_upload($_FILES['slip'] ?? [], 'uploads/slips', ['jpg','jpeg','png','gif','webp']);
  if ($slip) {
    $pdo->prepare('INSERT INTO payments (order_id, method, slip_path)
      VALUES (?, "bank_transfer", ?) ON DUPLICATE KEY UPDATE slip_path=VALUES(slip_path)')
      ->execute([$oid,$slip]);
    flash('success','อัปโหลดสลิปเรียบร้อย'); redirect('order_track.php?oid='.$oid);
  } else {
    flash('error','อัปโหลดสลิปไม่สำเร็จ');
  }
}

if (isset($_GET['oid'])) {
  $oid = (int)$_GET['oid'];
  $st = $pdo->prepare("SELECT * FROM orders WHERE id=?"); $st->execute([$oid]);
  $found = $st->fetch();
}

include __DIR__.'/partials/header.php';
?>
<h2>ติดตามคำสั่งซื้อ</h2>

<?php if(!$found): ?>
  <?php if($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post" class="card" style="max-width:520px">
    <?= csrf_field() ?>
    <label>เลขที่คำสั่งซื้อ</label>
    <input class="input" name="order_id" type="number" required>
    <label>เบอร์โทร หรือ อีเมลที่ใช้สั่งซื้อ</label>
    <input class="input" name="key" required>
    <button class="btn" type="submit">ค้นหา</button>
  </form>
<?php else: ?>
  <div class="card">
    <h3>#<?= (int)$found['id'] ?> — สถานะ: <span class="badge"><?= htmlspecialchars($found['status']) ?></span></h3>
    <div>วันที่: <?= htmlspecialchars($found['created_at']) ?></div>
    <div>ชื่อ: <?= htmlspecialchars($found['fullname']) ?> | โทร: <?= htmlspecialchars($found['phone']) ?></div>
    <div>ยอดสุทธิ: ฿<?= format_currency($found['grand_total']) ?></div>
    <?php
      $st=$pdo->prepare("SELECT i.*, p.name FROM order_items i JOIN products p ON p.id=i.product_id WHERE i.order_id=?");
      $st->execute([$found['id']]); $items=$st->fetchAll();
      $pay = $pdo->prepare("SELECT * FROM payments WHERE order_id=?"); $pay->execute([$found['id']]); $pay=$pay->fetch();
      $bank = get_setting($pdo,'bank_account','โอนเข้าบัญชี ...');
      $bank_qr = get_setting($pdo,'bank_qr','');
    ?>
    <table class="table" style="margin-top:.6rem">
      <tr><th>สินค้า</th><th>จำนวน</th><th>ราคา</th></tr>
      <?php foreach($items as $it): ?>
        <tr><td><?= htmlspecialchars($it['name']) ?></td><td><?= (int)$it['qty'] ?></td><td>฿<?= format_currency($it['price']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <div class="flex" style="gap:1rem;align-items:flex-start">
      <div class="card" style="min-width:240px">
        <h4>ชำระเงิน</h4>
        <div>บัญชี: <?= nl2br(htmlspecialchars($bank)) ?></div>
        <?php if($bank_qr): ?><img src="<?= htmlspecialchars($bank_qr) ?>" style="max-width:180px;border-radius:.5rem;margin-top:.5rem"><?php endif; ?>
      </div>

      <div class="card" style="min-width:240px">
        <h4>หลักฐานโอน</h4>
        <?php if(!empty($pay['slip_path'])): ?>
          <a class="btn outline" target="_blank" href="<?= BASE_URL.'/'.htmlspecialchars($pay['slip_path']) ?>">ดูสลิป</a>
        <?php else: ?>
          <span class="badge">ยังไม่อัปโหลด</span>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="order_track.php?upload=1">
          <?= csrf_field() ?>
          <input type="hidden" name="oid" value="<?= (int)$found['id'] ?>">
          <input class="input" type="file" name="slip" accept="image/*">
          <button class="btn" type="submit">อัปโหลด/แทนที่</button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__.'/partials/footer.php'; ?>
