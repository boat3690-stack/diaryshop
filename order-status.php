<?php require_once __DIR__ . '/partials/header.php'; ?>
<?php require_login(); ?>
<h2>คำสั่งซื้อของฉัน</h2>
<?php
$stmt = $pdo->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC');
$stmt->execute([ current_user()['id'] ]);
$orders = $stmt->fetchAll();
?>
<table class="table">
  <tr><th>#</th><th>วันที่</th><th>ยอดสุทธิ</th><th>สถานะ</th><th>สลิป</th></tr>
  <?php foreach($orders as $o):
    $pay = $pdo->prepare('SELECT * FROM payments WHERE order_id=?'); $pay->execute([$o['id']]); $pm = $pay->fetch();
  ?>
  <tr>
    <td>#<?= (int)$o['id'] ?></td>
    <td><?= htmlspecialchars($o['created_at']) ?></td>
    <td>฿<?= format_currency($o['grand_total']) ?></td>
    <td><span class="badge"><?= htmlspecialchars($o['status']) ?></span></td>
    <td>
      <?php if(!empty($pm['slip_path'])): ?>
        <a class="btn outline" target="_blank" href="<?= htmlspecialchars($pm['slip_path']) ?>">ดูสลิป</a>
      <?php else: ?>-<?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php require_once __DIR__ . '/partials/footer.php'; ?>