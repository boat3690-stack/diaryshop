<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM orders WHERE id=?"); $st->execute([$id]); $o = $st->fetch();
if(!$o){ echo 'not found'; exit; }

$items = $pdo->prepare("SELECT i.*, p.name FROM order_items i JOIN products p ON p.id=i.product_id WHERE order_id=?");
$items->execute([$id]); $items = $items->fetchAll(PDO::FETCH_ASSOC);

$store = get_setting($pdo,'store_name','My PHP Shop');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>Packing Slip #<?= (int)$o['id'] ?></title>
<style>
@media print { @page { size: A5; margin: 10mm; } body{ margin:0 } .noprint{display:none} }
body{font-family:system-ui,Arial; color:#000}
h2{margin:0 0 6px 0}
.table{width:100%; border-collapse:collapse}
.table th,.table td{border:1px solid #000;padding:6px;font-size:13px;text-align:left}
.small{font-size:12px}
</style>
</head><body>
<button class="noprint" onclick="print()">พิมพ์</button>
<h2><?= htmlspecialchars($store) ?> · ใบแพ็ค #<?= (int)$o['id'] ?></h2>
<div class="small">ลูกค้า: <?= htmlspecialchars($o['fullname']) ?> · โทร <?= htmlspecialchars($o['phone']) ?>
<?php if($o['delivery_option']==='pickup'): ?>
 · รับเองที่สาขา (แสดง QR ในใบจ่าหน้า)
<?php else: ?>
 · ที่อยู่: <?= htmlspecialchars($o['address']) ?>, <?= htmlspecialchars($o['province']) ?> <?= htmlspecialchars($o['zipcode']) ?>
<?php endif; ?>
</div>

<table class="table" style="margin-top:8px">
  <tr><th>#</th><th>สินค้า</th><th>จำนวน</th><th>ราคา</th><th>รวม</th></tr>
  <?php $i=1; $sum=0; foreach($items as $it): $line=$it['qty']*$it['price']; $sum+=$line; ?>
  <tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars($it['name']) ?></td>
    <td><?= (int)$it['qty'] ?></td>
    <td><?= number_format((float)$it['price'],2) ?></td>
    <td><?= number_format($line,2) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<div style="margin-top:8px" class="small">
  Subtotal: <?= number_format($o['subtotal'],2) ?> · ส่วนลด: <?= number_format($o['discount'],2) ?> · ค่าส่ง: <?= number_format($o['shipping'],2) ?> ·
  <b>สุทธิ: <?= number_format($o['grand_total'],2) ?></b>
</div>

<script>fetch('ship_log.php?event=label_printed&id=<?= (int)$o['id'] ?>');</script>
</body></html>
