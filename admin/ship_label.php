<?php
// admin/ship_label.php
require_once __DIR__ . '/../config/config.php';
require_admin();

// -------- handle save default (POST) --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { die('CSRF invalid'); }
  $id = (int)($_POST['id'] ?? 0);
  $new = trim($_POST['origin_name'] ?? '');
  // บันทึกลง settings เป็นค่าเริ่มต้น
  $st = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('ship_origin_name',?)
                       ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  $st->execute([$new]);
  // กลับมาหน้าเดิมพร้อมใช้ค่านี้กับใบปัจจุบัน
  header('Location: ship_label.php?id='.$id.'&origin_name='.urlencode($new));
  exit;
}

// -------- load order --------
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT o.*, sm.name AS ship_name
                     FROM orders o
                     LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
                     WHERE o.id=?");
$st->execute([$id]);
$o = $st->fetch();
if(!$o){ echo 'Order not found'; exit; }

// ค่าเริ่มต้นผู้ส่งจาก settings + อนุญาต override ด้วย ?origin_name=
$origin_name_default = get_setting($pdo,'ship_origin_name','');
$origin = [
  'name'    => isset($_GET['origin_name']) ? trim($_GET['origin_name']) : $origin_name_default,
  'phone'   => get_setting($pdo,'ship_origin_phone',''),
  'address' => get_setting($pdo,'ship_origin_address',''),
  'prov'    => get_setting($pdo,'ship_origin_province',''),
  'zip'     => get_setting($pdo,'ship_origin_postcode',''),
];

$store = get_setting($pdo,'store_name','My PHP Shop');
$pickup_url = 'https://g.co/kgs/avFkHKe';
$qr_url = 'https://chart.googleapis.com/chart?chs=260x260&cht=qr&chl='.urlencode($pickup_url);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Shipping Label #<?= (int)$o['id'] ?></title>
<style>
@media print { @page { size: 100mm 150mm; margin: 6mm; } body{ margin:0; } .noprint{display:none} }
body{font-family:system-ui,Arial; background:#fff; color:#000}
.label{width:360px;border:2px solid #000;padding:10px}
h2{margin:0 0 6px 0}
.box{border:1px solid #000;margin:6px 0;padding:6px}
.row{display:flex;gap:8px}
.small{font-size:12px}
.input{padding:.35rem .5rem;border:1px solid #999;border-radius:.4rem}
.btn{display:inline-block;padding:.4rem .7rem;border:1px solid #333;border-radius:.5rem;background:#eee;cursor:pointer;text-decoration:none;color:#000}
.btn.primary{background:#000;color:#fff;border-color:#000}
.noprint .wrap{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:.5rem 0}
</style>
</head>
<body>

<!-- ส่วนแก้ไขชื่อผู้ส่ง (เฉพาะหน้าจอ, ไม่พิมพ์) -->
<div class="noprint">
  <div class="wrap">
    <form method="get" style="display:flex;gap:.5rem;align-items:center">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <label>ชื่อผู้ส่ง (เฉพาะใบนี้):
        <input class="input" name="origin_name" value="<?= htmlspecialchars($origin['name']) ?>" placeholder="เช่น ร้าน A">
      </label>
      <button class="btn" type="submit">ใช้กับใบนี้</button>
      <a class="btn" href="ship_label.php?id=<?= (int)$id ?>">คืนค่าเดิม</a>
    </form>

    <form method="post" style="display:flex;gap:.5rem;align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <label>บันทึกเป็นค่าเริ่มต้น:
        <input class="input" name="origin_name" value="<?= htmlspecialchars($origin['name']) ?>" placeholder="เช่น ร้าน A">
      </label>
      <button class="btn primary" type="submit">บันทึกถาวร</button>
    </form>

    <button class="btn" onclick="print()">พิมพ์</button>
  </div>
</div>

<div class="label">
  <h2><?= htmlspecialchars($store) ?> · ใบจ่าหน้า</h2>

  <div class="row">
    <div class="box" style="flex:1">
      <div><b>ผู้ส่ง</b></div>
      <div class="small"><?= htmlspecialchars($origin['name']) ?><?= $origin['phone']?' / '.htmlspecialchars($origin['phone']):'' ?></div>
      <?php if($origin['address']): ?><div class="small"><?= nl2br(htmlspecialchars($origin['address'])) ?></div><?php endif; ?>
      <?php if($origin['prov'] || $origin['zip']): ?><div class="small"><?= htmlspecialchars($origin['prov']) ?> <?= htmlspecialchars($origin['zip']) ?></div><?php endif; ?>
    </div>

    <div class="box" style="flex:1">
      <div><b>ผู้รับ</b></div>
      <?php if($o['delivery_option']==='pickup'): ?>
        <div class="small">** รับเองที่สาขา **</div>
        <img src="<?= $qr_url ?>" alt="QR" style="width:120px;height:120px;margin-top:4px">
        <div class="small"><?= htmlspecialchars($pickup_url) ?></div>
      <?php else: ?>
        <div class="small"><?= htmlspecialchars($o['fullname']) ?> / <?= htmlspecialchars($o['phone']) ?></div>
        <div class="small"><?= nl2br(htmlspecialchars($o['address'])) ?></div>
        <div class="small"><?= htmlspecialchars($o['province']) ?> <?= htmlspecialchars($o['zipcode']) ?></div>
        <?php if(!empty($o['email'])): ?><div class="small">อีเมล: <?= htmlspecialchars($o['email']) ?></div><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="box">
    <div class="row">
      <div style="flex:1">เลขบิล: #<?= (int)$o['id'] ?></div>
      <div style="flex:1">วิธีส่ง: <?= htmlspecialchars($o['ship_name'] ?: '-') ?></div>
    </div>
    <div class="row">
      <div style="flex:1">Tracking: <b><?= htmlspecialchars($o['tracking_no'] ?: '-') ?></b></div>
      <div style="flex:1">ETA ~ <?= (int)($o['shipping_eta_days'] ?: get_setting($pdo,'shipping_eta_default_days',3)) ?> วัน</div>
    </div>
    <div class="small">พิมพ์เมื่อ: <?= date('Y-m-d H:i:s') ?></div>
  </div>
</div>

<script>
// log ว่ามีการพิมพ์ (optional)
fetch('ship_log.php?event=label_printed&id=<?= (int)$o['id'] ?>').catch(()=>{});
</script>
</body>
</html>
