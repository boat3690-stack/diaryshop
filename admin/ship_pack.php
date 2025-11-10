<?php
// admin/ship_pack.php — ใบจ่าหน้า + ใบแพ็ค (รวมหน้าเดียว)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

/* safe-escape ป้องกัน null */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* =========================
   POST: บันทึกผู้ส่งเริ่มต้น
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { die('CSRF invalid'); }
  $id  = (int)($_POST['id'] ?? 0);
  $act = (string)($_POST['action'] ?? '');
  if ($act === 'save_sender_default') {
    $block = trim((string)($_POST['sender_block'] ?? ''));
    $st = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('ship_origin_block',?)
                         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $st->execute([$block]);
    header('Location: ship_pack.php?id='.$id);
    exit;
  }
}

/* =========================
   ดึงออเดอร์ + รายการสินค้า
   ========================= */
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT o.*, sm.name AS ship_name
                     FROM orders o
                     LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
                     WHERE o.id=?");
$st->execute([$id]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { echo 'Order not found'; exit; }

$it = $pdo->prepare("SELECT i.*, p.name
                     FROM order_items i
                     JOIN products p ON p.id=i.product_id
                     WHERE i.order_id=?");
$it->execute([$id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   ผู้ส่งเริ่มต้น (หลายบรรทัด)
   ========================= */
$sender_default = trim((string)get_setting($pdo,'ship_origin_block',''));
if ($sender_default === '') {
  $sender_default =
    "RTAF Diary Shop\n".
    "171 หอสมุดกองทัพอากาศ แขวงสนามบิน\n".
    "เขตดอนเมือง กทม. 10210\n".
    "โทร. 02-534-6380";
}
$sender_block = trim((string)($_GET['sender'] ?? $sender_default));

/* =========================
   ผู้รับเริ่มต้น (หลายบรรทัด)
   ========================= */
$is_pickup = ((string)($o['delivery_option'] ?? '') === 'pickup');
$customer_name  = trim((string)($o['fullname'] ?? ''));
$customer_phone = trim((string)($o['phone'] ?? ''));

$receiver_lines = [];
if ($is_pickup) {
  // รับเอง: ขึ้นรายละเอียดรับของ
  $first = $customer_name . ($customer_phone ? ' / '.$customer_phone : '');
  if ($first !== '') $receiver_lines[] = $first;
  $receiver_lines[] = '** รับเองที่สาขา **';
  $receiver_lines[] = 'เลขที่ออเดอร์: #' . (int)$o['id'];
  if (!empty($o['pickup_code'])) $receiver_lines[] = 'รหัสรับของ: ' . $o['pickup_code'];
} else {
  $first = $customer_name . ($customer_phone ? ' / '.$customer_phone : '');
  if ($first !== '') $receiver_lines[] = $first;
  if (!empty($o['address']))  $receiver_lines[] = (string)$o['address'];
  $provzip = trim(((string)($o['province'] ?? '')).' '.((string)($o['zipcode'] ?? '')));
  if ($provzip !== '') $receiver_lines[] = $provzip;
  if (!empty($o['email']))    $receiver_lines[] = 'อีเมล: ' . $o['email'];
}
$receiver_block = implode("\n", array_filter($receiver_lines, fn($s)=>$s!==''));

/* meta อื่น ๆ */
$store = get_setting($pdo,'store_name','RTAF Diary Shop');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= h($store) ?> · ใบจ่าหน้า & ใบแพ็ค #<?= (int)$o['id'] ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{ --border:#111; --muted:#666; }
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;color:#000;background:#fff;margin:0}
.container{max-width:980px;margin:0 auto;padding:12px}

/* ===== แผงควบคุม (ไม่พิมพ์) ===== */
.noprint{margin:12px 0 6px 0}
.noprint .toolbar{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
.ta{width:min(520px,100%);min-height:96px;padding:.6rem .7rem;border:1px solid #c9c9c9;border-radius:.6rem;font-family:inherit}
.btn{display:inline-block;padding:.5rem .8rem;border:1px solid #111;border-radius:.6rem;background:#111;color:#fff;cursor:pointer;text-decoration:none}
.btn.light{background:#fff;color:#111}
.btn.ghost{background:#f7f7f7;color:#111;border-color:#bbb}
.group{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-top:.6rem}
.label{font-weight:700;margin:.25rem 0}

/* ===== หน้าเอกสาร ===== */
.sheet{width:100%;border:2px solid var(--border);border-radius:8px;padding:14px;margin-top:8px}
h2{margin:0 0 10px 0}
.row{display:flex;gap:10px;flex-wrap:wrap}
.col{flex:1 1 300px}
.box{border:1px solid var(--border);border-radius:6px;padding:8px}
.small{font-size:12px}
pre.block{white-space:pre-wrap;margin:6px 0 0 0;font-size:13px;line-height:1.45}

/* ตารางใบแพ็ค */
.table{width:100%;border-collapse:collapse;margin-top:8px}
.table th,.table td{border:1px solid #000;padding:6px 8px;font-size:13px;text-align:left}
.table th{background:#fafafa}

/* คั่นกลางสองเอกสาร */
.hr{height:0;border:none;border-top:2px dashed #bbb;margin:16px 0}

/* พิมพ์สวย ๆ */
@media print{
  @page{size:A4;margin:12mm}
  .noprint{display:none!important}
  .sheet{border-width:1px;page-break-inside:avoid}
}
</style>
</head>
<body>
<div class="container">

  <!-- ============ แผงควบคุม ============ -->
  <div class="noprint">
    <div class="toolbar">
      <strong>ออเดอร์ #<?= (int)$o['id'] ?></strong>
      <span class="small" style="color:#666">สถานะ: <?= h($o['status'] ?? '-') ?></span>
      <span class="small" style="color:#666">Tracking: <?= h($o['tracking_no'] ?? '-') ?></span>
      <span class="small" style="color:#666">ETA ~ <?= (int)($o['shipping_eta_days'] ?? (int)get_setting($pdo,'shipping_eta_default_days',3)) ?> วัน</span>
      <span class="small" style="color:#666">พิมพ์เมื่อ: <?= date('Y-m-d H:i:s') ?></span>
    </div>

    <div class="toolbar" style="margin-top:.5rem">
      <button class="btn" onclick="printAll()">พิมพ์ทั้งหมด</button>
      <button class="btn light" onclick="printSection('labelSection')">พิมพ์เฉพาะใบจ่าหน้า</button>
      <button class="btn light" onclick="printSection('packSection')">พิมพ์เฉพาะใบแพ็ค</button>
    </div>

    <!-- กล่องแก้ไขข้อความหลายบรรทัด -->
    <div class="group">
      <div class="col" style="max-width:520px">
        <div class="label">รายละเอียดผู้ส่ง (แก้ไขได้)</div>
        <textarea id="senderTA" class="ta"><?= h($sender_block) ?></textarea>

        <!-- บันทึกเป็นค่าเริ่มต้น -->
        <form method="post" id="saveSenderForm" style="margin-top:.5rem">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="action" value="save_sender_default">
          <input type="hidden" name="sender_block" id="senderBlockInput">
          <button type="button" class="btn ghost" onclick="saveSenderDefault()">บันทึกเป็นค่าเริ่มต้น</button>
          <button type="button" class="btn light" onclick="resetSender()">เรียกค่าตั้งต้น</button>
        </form>
      </div>

      <div class="col" style="max-width:520px">
        <div class="label">รายละเอียดผู้รับ (แก้ไขได้)</div>
        <textarea id="receiverTA" class="ta"><?= h($receiver_block) ?></textarea>
      </div>
    </div>
  </div>

  <!-- ============ ใบจ่าหน้า ============ -->
  <section id="labelSection" class="sheet">
    <h2><?= h($store) ?></h2>

    <div class="row">
      <div class="col">
        <div class="box">
          <div style="font-weight:700">ผู้ส่ง</div>
          <pre id="senderOut" class="block"><?= h($sender_block) ?></pre>
        </div>
      </div>

      <div class="col">
        <div class="box">
          <div style="font-weight:700">ผู้รับ</div>
          <!-- ลบ “บรรทัดตัวใหญ่/คุมดำ” ออก ให้แสดงเฉพาะบล็อกข้อความ -->
          <pre id="receiverOut" class="block"><?= h($receiver_block) ?></pre>
        </div>
      </div>
    </div>

    <div class="box" style="margin-top:8px">
      <div class="row">
        <div style="font-weight:900">ขอบคุณที่ซื้อสินค้าจาก RTAF Diary Shop _***  กรุณาอย่าโยนสินค้า  ***</div>
      </div>
    </div>
  </section>

  <div class="hr"></div>

  <!-- ============ ใบแพ็ค ============ -->
  <section id="packSection" class="sheet">
    <h2><?= h($store) ?> #<?= (int)$o['id'] ?></h2>

    <!-- ลบ “บรรทัดตัวใหญ่/คุมดำ” ออกเช่นกัน -->
    <div class="small" style="margin-bottom:4px"><b>ผู้รับ</b> (ตามที่แก้ไข):</div>
    <pre id="receiverOutPack" class="block" style="margin-top:0"><?= h($receiver_block) ?></pre>

    <table class="table">
      <tr><th>#</th><th>สินค้า</th><th>จำนวน</th><th>ราคา</th><th>รวม</th></tr>
      <?php
        $i=1; $sum=0.0;
        foreach($items as $it){
          $unit = isset($it['unit_price']) ? (float)$it['unit_price'] : (float)$it['price'];
          $line = ((int)$it['qty']) * $unit;
          $sum += $line;
          echo '<tr>';
          echo '<td>'.($i++).'</td>';
          echo '<td>'.h($it['name']).'</td>';
          echo '<td>'.(int)$it['qty'].'</td>';
          echo '<td>'.number_format($unit,2).'</td>';
          echo '<td>'.number_format($line,2).'</td>';
          echo '</tr>';
        }
        $subtotal = (float)($o['subtotal'] ?? $sum);
        $discount = (float)($o['discount'] ?? 0);
        $shipping = (float)($o['shipping'] ?? 0);
        $grand    = (float)($o['grand_total'] ?? max(0, $subtotal - $discount + $shipping));
      ?>
    </table>

    <div class="small" style="margin-top:8px">
      Subtotal: <?= number_format($subtotal,2) ?> ·
      ส่วนลด: <?= number_format($discount,2) ?> ·
      ค่าส่ง: <?= number_format($shipping,2) ?> ·
      <b>สุทธิ: <?= number_format($grand,2) ?></b>
    </div>
  </section>
</div>

<script>
/* sync live: textarea -> preview ทั้งสองใบ */
(function(){
  const sTA = document.getElementById('senderTA');
  const rTA = document.getElementById('receiverTA');
  const sOut = document.getElementById('senderOut');
  const rOut = document.getElementById('receiverOut');
  const rOutPack = document.getElementById('receiverOutPack');
  function sync(){
    if (sOut) sOut.textContent = sTA.value;
    if (rOut) rOut.textContent = rTA.value;
    if (rOutPack) rOutPack.textContent = rTA.value;
  }
  ['input','change'].forEach(ev=>{
    sTA?.addEventListener(ev, sync);
    rTA?.addEventListener(ev, sync);
  });
})();

/* บันทึกผู้ส่งเริ่มต้น */
function saveSenderDefault(){
  const ta  = document.getElementById('senderTA');
  const hid = document.getElementById('senderBlockInput');
  if (!ta || !hid) return;
  hid.value = ta.value;
  document.getElementById('saveSenderForm').submit();
}
/* รีเซ็ตผู้ส่งกลับค่าตั้งต้นจาก settings */
function resetSender(){
  const url = new URL(location.href);
  url.searchParams.delete('sender');
  location.href = url.toString();
}

/* พิมพ์ */
function printAll(){ window.print(); }
function printSection(id){
  const el = document.getElementById(id);
  if(!el){ alert('ไม่พบส่วนที่ต้องการพิมพ์'); return; }
  const w = window.open('', 'PRINT', 'width=900,height=1000');
  if(!w){ alert('เบราว์เซอร์บล็อกป๊อปอัป'); return; }
  w.document.write('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>พิมพ์</title>');
  w.document.write('<style>@page{size:A4;margin:12mm}body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;color:#000}.sheet{width:100%;border:1px solid #000;border-radius:8px;padding:14px;margin:0}.row{display:flex;gap:10px;flex-wrap:wrap}.col{flex:1 1 300px}.box{border:1px solid #000;border-radius:6px;padding:8px}.small{font-size:12px}.table{width:100%;border-collapse:collapse;margin-top:8px}.table th,.table td{border:1px solid #000;padding:6px 8px;font-size:13px;text-align:left}.table th{background:#fafafa}pre{white-space:pre-wrap;font-size:13px;line-height:1.45;margin:6px 0 0 0}</style>');
  w.document.write('</head><body>');
  w.document.write(el.innerHTML);
  w.document.write('</body></html>');
  w.document.close(); w.focus();
  setTimeout(()=>{ w.print(); w.close(); }, 120);
}
</script>
</body>
</html>
