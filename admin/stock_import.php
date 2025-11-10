<?php
// /admin/stock_import.php
require_once __DIR__ . '/../config/config.php';
require_admin();

$mode = $_GET['mode'] ?? 'set'; // set = กำหนดค่าใหม่, add = เพิ่ม/ลดตามตัวเลข
$flash = ['ok'=>[], 'err'=>[]];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); redirect('stock_import.php'); }

  $mode = $_POST['mode'] ?? 'set';
  if (!in_array($mode, ['set','add'], true)) $mode = 'set';

  if (empty($_FILES['csv']['tmp_name'])) {
    flash('error','กรุณาเลือกไฟล์ CSV'); redirect('stock_import.php?mode='.$mode);
  }

  $tmp = $_FILES['csv']['tmp_name'];
  // รองรับไฟล์ใหญ่/บรรทัด CRLF
  @set_time_limit(0);

  $h = fopen($tmp, 'r');
  if (!$h) { flash('error','ไม่สามารถอ่านไฟล์'); redirect('stock_import.php?mode='.$mode); }

  // อ่าน header
  $header = fgetcsv($h);
  if (!$header) { fclose($h); flash('error','ไฟล์ว่างเปล่า'); redirect('stock_import.php?mode='.$mode); }

  // ทำให้ header ไม่สนตัวพิมพ์เล็กใหญ่
  $map = [];
  foreach ($header as $i => $col) {
    $key = strtolower(trim($col));
    $map[$key] = $i;
  }
  // ต้องมีอย่างน้อย id หรือ sku และต้องมี stock
  if (!isset($map['id']) && !isset($map['sku'])) {
    fclose($h); flash('error','ต้องมีคอลัมน์ id หรือ sku อย่างน้อยหนึ่งอย่าง'); redirect('stock_import.php?mode='.$mode);
  }
  if (!isset($map['stock'])) {
    fclose($h); flash('error','ต้องมีคอลัมน์ stock'); redirect('stock_import.php?mode='.$mode);
  }

  $pdo->beginTransaction();
  try {
    $qBySku = $pdo->prepare("SELECT id, stock FROM products WHERE sku=?");
    $qById  = $pdo->prepare("SELECT id, stock FROM products WHERE id=?");
    $uStock = $pdo->prepare("UPDATE products SET stock=?, updated_at=NOW() WHERE id=?");
    $uPrice = $pdo->prepare("UPDATE products SET price=?, updated_at=NOW() WHERE id=?");
    $uActive= $pdo->prepare("UPDATE products SET is_active=?, updated_at=NOW() WHERE id=?");

    while (($row = fgetcsv($h)) !== false) {
      if (count($row) === 1 && trim($row[0])==='') continue; // ข้ามบรรทัดว่าง

      $id  = isset($map['id'])  ? trim($row[$map['id']])  : '';
      $sku = isset($map['sku']) ? trim($row[$map['sku']]) : '';

      // หา product_id
      $pid = null; $curStock = 0;
      if ($id !== '') {
        $qById->execute([(int)$id]);
        if ($x=$qById->fetch(PDO::FETCH_ASSOC)) { $pid=(int)$x['id']; $curStock=(int)$x['stock']; }
      } elseif ($sku !== '') {
        $qBySku->execute([$sku]);
        if ($x=$qBySku->fetch(PDO::FETCH_ASSOC)) { $pid=(int)$x['id']; $curStock=(int)$x['stock']; }
      }

      if (!$pid) {
        $flash['err'][] = "ไม่พบสินค้า (id={$id}, sku={$sku})";
        continue;
      }

      // stock ใหม่
      $val = (int)($row[$map['stock']] ?? 0);
      $new = ($mode==='add') ? ($curStock + $val) : $val;
      if ($new < 0) $new = 0;

      $uStock->execute([$new, $pid]);

      // อัปเดตราคา (ถ้ามีคอลัมน์ price)
      if (isset($map['price'])) {
        $price = (float)($row[$map['price']] ?? 0);
        if ($price >= 0) $uPrice->execute([$price, $pid]);
      }
      // อัปเดตสถานะแสดง (ถ้ามีคอลัมน์ is_active)
      if (isset($map['is_active'])) {
        $ia = (int)($row[$map['is_active']] ?? 1) ? 1 : 0;
        $uActive->execute([$ia, $pid]);
      }

      $flash['ok'][] = "อัปเดตสินค้า #{$pid} เป็นสต๊อก {$new}";
    }

    $pdo->commit();
    fclose($h);
    // สรุปผล
    $ok  = count($flash['ok']);
    $err = count($flash['err']);
    $msg = "นำเข้าสำเร็จ {$ok} รายการ";
    if ($err) $msg .= ", ผิดพลาด {$err} รายการ";
    flash($err? 'error':'success', $msg.( $err? ' (ดูรายละเอียดด้านล่าง)' : '' ));
  } catch (Throwable $e) {
    $pdo->rollBack();
    fclose($h);
    flash('error', 'เกิดข้อผิดพลาด: '.$e->getMessage());
  }

  // แสดงผลลัพธ์ในหน้าเดียวกัน
}
include __DIR__ . '/../partials/header.php';
?>
<h2>Import สต๊อก (CSV)</h2>

<div class="card">
  <p>รูปแบบไฟล์ CSV อย่างน้อยต้องมีคอลัมน์: <code>id</code> หรือ <code>sku</code> และ <code>stock</code><br>
     เสริมได้: <code>price</code>, <code>is_active</code> (1=แสดง, 0=ซ่อน)</p>
  <p>โหมดอัปเดต:
    <strong>ตั้งค่าใหม่ (set)</strong> = กำหนดสต๊อกเท่าค่าในไฟล์,
    <strong>เพิ่ม/ลด (add)</strong> = บวก/ลบจำนวนจากสต๊อกเดิม (ใส่ค่าติดลบได้)
  </p>

  <form method="post" enctype="multipart/form-data" action="stock_import.php">
    <?= csrf_field() ?>
    <label>โหมดอัปเดต</label>
    <select name="mode" class="input" style="max-width:200px">
      <option value="set" <?= ($mode==='set'?'selected':'') ?>>ตั้งค่าใหม่ (set)</option>
      <option value="add" <?= ($mode==='add'?'selected':'') ?>>เพิ่ม/ลด ตามค่า (add)</option>
    </select>

    <label style="margin-top:.5rem">ไฟล์ CSV</label>
    <input class="input" type="file" name="csv" accept=".csv,text/csv,application/vnd.ms-excel" required style="max-width:360px">

    <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <button class="btn" type="submit">อัปโหลด</button>
      <a class="btn outline" href="stock_export.php">ดาวน์โหลดเทมเพลตจากข้อมูลปัจจุบัน</a>
    </div>
  </form>
</div>

<?php if (!empty($flash['ok']) || !empty($flash['err'])): ?>
<div class="card">
  <h3>ผลการนำเข้า</h3>
  <?php if (!empty($flash['ok'])): ?>
    <div class="alert success">สำเร็จ <?= count($flash['ok']) ?> รายการ</div>
    <ul class="small">
      <?php foreach ($flash['ok'] as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (!empty($flash['err'])): ?>
    <div class="alert error">ผิดพลาด <?= count($flash['err']) ?> รายการ</div>
    <ul class="small">
      <?php foreach ($flash['err'] as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
