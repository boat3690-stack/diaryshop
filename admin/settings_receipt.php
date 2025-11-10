<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

function sv($k,$d=''){ global $pdo; return get_setting($pdo,$k,$d); }
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); header('Location: settings_receipt.php'); exit; }

  // อัปโหลดโลโก้
  if (!empty($_FILES['receipt_logo']['name'])) {
    $path = handle_upload($_FILES['receipt_logo'], 'uploads/brand', ['png','jpg','jpeg','gif','webp','svg']);
    if ($path) {
      $st=$pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('receipt_logo',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
      $st->execute([$path]);
    } else {
      flash('error', flash('error') ?: 'อัปโหลดโลโก้ไม่สำเร็จ');
    }
  }

  // บันทึกค่าข้อความ/ตัวเลข
  $pairs = [
    'receipt_prefix','receipt_digits','receipt_next_number',
    'receipt_show_tax','receipt_tax_rate',
    'receipt_header_html','receipt_footer_html',
    'store_name','store_address','store_tax_id','store_phone','store_email','store_website'
  ];
  foreach ($pairs as $k) {
    $v = $_POST[$k] ?? '';
    $st=$pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $st->execute([$k, $v]);
  }
  flash('success','บันทึกการตั้งค่าแล้ว');
  header('Location: settings_receipt.php'); exit;
}

$logo = sv('receipt_logo','');
include __DIR__ . '/../partials/header.php';
?>
<h2>ตั้งค่าใบเสร็จ/หัวบิล</h2>

<div class="card">
  <form method="post" enctype="multipart/form-data" class="grid" style="grid-template-columns:1fr 1fr; gap:1rem">
    <?= csrf_field() ?>

    <div>
      <h3>ข้อมูลร้าน</h3>
      <label>ชื่อร้าน</label><input class="input" name="store_name" value="<?= h(sv('store_name')) ?>">
      <label>ที่อยู่ (แสดงในบิล)</label><textarea class="input" name="store_address" rows="3"><?= h(sv('store_address')) ?></textarea>
      <label>เลขผู้เสียภาษี</label><input class="input" name="store_tax_id" value="<?= h(sv('store_tax_id')) ?>">
      <label>โทรศัพท์</label><input class="input" name="store_phone" value="<?= h(sv('store_phone')) ?>">
      <label>อีเมล</label><input class="input" name="store_email" value="<?= h(sv('store_email')) ?>">
      <label>เว็บไซต์</label><input class="input" name="store_website" value="<?= h(sv('store_website')) ?>">
      <label>โลโก้ (อัปโหลดใหม่เพื่อเปลี่ยน)</label>
      <input class="input" type="file" name="receipt_logo" accept="image/*">
      <?php if ($logo): ?>
        <div style="margin-top:.5rem">
          <img src="../<?= h($logo) ?>" style="height:50px"> (ปัจจุบัน)
        </div>
      <?php endif; ?>
    </div>

    <div>
      <h3>รูปแบบเลขที่ใบเสร็จ</h3>
      <label>คำนำหน้า (Prefix)</label><input class="input" name="receipt_prefix" value="<?= h(sv('receipt_prefix','RC-')) ?>">
      <label>จำนวนหลัก (padding)</label><input class="input" type="number" name="receipt_digits" min="1" value="<?= h(sv('receipt_digits','5')) ?>">
      <label>เลขถัดไป (Next Number)</label><input class="input" type="number" name="receipt_next_number" min="1" value="<?= h(sv('receipt_next_number','1')) ?>">

      <h3 style="margin-top:1rem">ภาษีมูลค่าเพิ่ม</h3>
      <label>แสดง VAT บนใบเสร็จ</label>
      <select class="input" name="receipt_show_tax">
        <option value="0" <?= sv('receipt_show_tax','0')==='0'?'selected':'' ?>>ไม่แสดง</option>
        <option value="1" <?= sv('receipt_show_tax','0')==='1'?'selected':'' ?>>แสดง VAT</option>
      </select>
      <label>อัตรา VAT (%)</label><input class="input" type="number" step="0.01" name="receipt_tax_rate" value="<?= h(sv('receipt_tax_rate','7')) ?>">
    </div>

    <div style="grid-column:1/3">
      <h3>หัว/ท้ายบิล (รองรับ HTML)</h3>
      <label>ส่วนหัว (เหนือรายละเอียด)</label>
      <textarea class="input" name="receipt_header_html" rows="4" placeholder="เช่น ใบเสร็จรับเงิน/ใบกำกับภาษี"><?= h(sv('receipt_header_html')) ?></textarea>
      <label>ส่วนท้าย (เช่น เงื่อนไข/ขอบคุณ)</label>
      <textarea class="input" name="receipt_footer_html" rows="4"><?= h(sv('receipt_footer_html')) ?></textarea>
    </div>

    <div style="grid-column:1/3">
      <button class="btn" type="submit">บันทึก</button>
      <a class="btn outline" href="receipt.php?id=1" target="_blank">ตัวอย่าง (ลองเปิดด้วยออเดอร์ที่มีจริง)</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
