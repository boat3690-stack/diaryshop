<?php
// admin/receipt.php — แสดงใบเสร็จแอดมิน (เวอร์ชันใช้ยอดค่าพิมพ์ปกจาก DB ตรงๆ + ฟอร์มแก้รายละเอียดพิมพ์ปก)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

/* ---------- helpers ---------- */
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }

if (!function_exists('get_setting')) {
  function get_setting(PDO $pdo, string $key, $default=null){
    try{ $st=$pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1');
         $st->execute([$key]); $v=$st->fetchColumn(); return ($v===false)?$default:$v;
    }catch(Throwable $e){ return $default; }
  }
}
if (!function_exists('set_setting')) {
  function set_setting(PDO $pdo, string $key, string $value): void {
    $st=$pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $st->execute([$key,$value]);
  }
}
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try{
      $sql = "SHOW COLUMNS FROM `" . str_replace("`","``",$table) . "` LIKE ?"; $st=$pdo->prepare($sql);
      $st->execute([$col]); return (bool)$st->fetch();
    }catch(Throwable $e){ return false; }
  }
}

/* บล็อก “รายละเอียดพิมพ์ปก:” ใน orders.note */
function extract_cover_detail_from_note(string $txt): string {
  if (preg_match('/รายละเอียด(?:พิมพ์ปก)?\s*:\s*([\s\S]*?)(?=\R\s*\R|$)/u', $txt, $m)) {
    $det = trim(preg_replace("/\R{3,}/", "\n\n", (string)$m[1]));
    return $det;
  }
  return '';
}
function set_cover_detail_in_note(string $txt, string $detail): string {
  // ลบของเดิม
  $txt = preg_replace('/(^|\R)\h*รายละเอียด(?:พิมพ์ปก)?\s*:\s*[\s\S]*?(?=\R\s*\R|$)/u', '$1', $txt);
  $txt = trim(preg_replace("/\n{3,}/", "\n\n", $txt));
  if ($detail === '') return $txt;
  $block = "รายละเอียดพิมพ์ปก:\n".$detail;
  return $txt !== '' ? ($txt."\n\n".$block) : $block;
}
/* เอา “พิมพ์ปก:” และ “รายละเอียดพิมพ์ปก:” ออก (ใช้กับช่องหมายเหตุ) */
function strip_cover_from_note_display(string $txt): string {
  $txt = preg_replace('/(^|\R)\h*พิมพ์ปก\h*:[^\r\n]*(?:\R|$)/u', '$1', $txt);
  $txt = preg_replace('/(^|\R)\h*รายละเอียด(?:พิมพ์ปก)?\s*:\s*[\s\S]*?(?=\R\s*\R|$)/u', '$1', $txt);
  $txt = preg_replace("/\n{3,}/", "\n\n", $txt);
  return trim($txt);
}

/* ---------- params ---------- */
$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ http_response_code(400); echo 'Bad request'; exit; }

/* ---------- order ---------- */
$st = $pdo->prepare("SELECT o.*, sm.name AS ship_name
                     FROM orders o
                     LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
                     WHERE o.id=?");
$st->execute([$id]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o){ http_response_code(404); echo 'Order not found'; exit; }

/* ---------- handle POST: save shop / customer / cover detail ---------- */
$msg=null; $err=null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err='CSRF invalid';
  } else {
    $act = (string)($_POST['action'] ?? '');
    try{
      if ($act === 'save_shop') {
        set_setting($pdo,'shop_name',     trim((string)($_POST['shop_name'] ?? '')));
        set_setting($pdo,'shop_address',  trim((string)($_POST['shop_address'] ?? '')));
        set_setting($pdo,'shop_email',    trim((string)($_POST['shop_email'] ?? '')));
        set_setting($pdo,'shop_phone',    trim((string)($_POST['shop_phone'] ?? '')));
        set_setting($pdo,'shop_tax_id',   trim((string)($_POST['shop_tax_id'] ?? '')));
        $msg='บันทึกข้อมูล “ร้านค้า/แอดมิน” แล้ว';

      } elseif ($act === 'save_customer') {
        $cols = ['fullname','phone','email','address','province','zipcode'];
        $set  = []; $vals=[];
        foreach($cols as $c){ $set[]="$c=?"; $vals[] = trim((string)($_POST[$c] ?? '')); }
        $set[]='updated_at=NOW()'; $vals[] = $id;
        $pdo->prepare("UPDATE orders SET ".implode(',', $set)." WHERE id=?")->execute($vals);
        // sync local for render
        foreach(['fullname','phone','email','address','province','zipcode'] as $c){ $o[$c] = trim((string)($_POST[$c] ?? '')); }
        $msg='บันทึกข้อมูลลูกค้าแล้ว';

      } elseif ($act === 'save_cover_detail') {
        $detail = trim((string)($_POST['cover_detail'] ?? ''));
        $note_new = set_cover_detail_in_note((string)($o['note'] ?? ''), $detail);
        $pdo->prepare("UPDATE orders SET note=?, updated_at=NOW() WHERE id=?")->execute([$note_new,$id]);
        $o['note']=$note_new; // update local
        $msg='บันทึกรายละเอียดพิมพ์ปกแล้ว';
      }
    }catch(Throwable $e){
      $err='บันทึกล้มเหลว: '.$e->getMessage();
    }
  }
}

/* ---------- order_items (อ่านโน้ต/ฟีค่าพิมพ์ปกจากแถวได้ ถ้ามี) ---------- */
$items = [];
try{
  $hasAllow = has_column($pdo,'products','allow_cover_print');
  $allowSel = $hasAllow ? 'p.allow_cover_print AS allow_cover' : '1 AS allow_cover';
  $sql = "SELECT p.name, oi.product_id AS pid, oi.qty,
                 COALESCE(NULLIF(oi.unit_price,0), NULLIF(oi.price,0), p.price, 0) AS unit_price,
                 {$allowSel}";
  $has_note=$has_fee=$has_meta=$has_oi_note=false;
  foreach (['cover_note'=>'has_note','cover_fee'=>'has_fee','meta_json'=>'has_meta','note'=>'has_oi_note'] as $col=>$flag){
    try{ $pdo->query("SELECT $col FROM order_items LIMIT 1"); $$flag=true; $sql.=", oi.$col"; }catch(Throwable $e){}
  }
  $sql .= " FROM order_items oi
            LEFT JOIN products p ON p.id=oi.product_id
            WHERE oi.order_id=?";
  $its = $pdo->prepare($sql); $its->execute([$id]);

  while($r=$its->fetch(PDO::FETCH_ASSOC)){
    $extra_note=''; $cover_fee=0.0;
    if ($has_note && trim((string)$r['cover_note'])!=='') {
      $extra_note = trim((string)$r['cover_note']);
    } elseif ($has_meta && trim((string)$r['meta_json'])!=='') {
      $mj = json_decode((string)$r['meta_json'], true);
      if (is_array($mj) && !empty($mj['cover_note'])) $extra_note = (string)$mj['cover_note'];
    } elseif ($has_oi_note && stripos((string)$r['note'],'COVER:')===0) {
      $mj = json_decode(trim(substr((string)$r['note'],6)), true);
      if (is_array($mj) && !empty($mj['cover_note'])) $extra_note = (string)$mj['cover_note'];
      if (is_array($mj) && isset($mj['cover_fee']))  $cover_fee  = (float)$mj['cover_fee'];
    }
    if ($has_fee && is_numeric($r['cover_fee'])) { $cover_fee = (float)$r['cover_fee']; }

    $items[] = [
      'pid'        => (int)($r['pid'] ?? 0),
      'allow_cover'=> (int)($r['allow_cover'] ?? 1) ? 1 : 0,
      'name'       => (string)$r['name'],
      'qty'        => (int)$r['qty'],
      'unit_price' => (float)$r['unit_price'],
      'cover_note' => $extra_note,
      'cover_fee'  => $cover_fee,
    ];
  }
}catch(Throwable $e){
  // fallback basic
  $its = $pdo->prepare("
    SELECT p.name, 0 AS pid, oi.qty,
           COALESCE(NULLIF(oi.unit_price,0), NULLIF(oi.price,0), p.price, 0) AS unit_price
    FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id
    WHERE oi.order_id=?");
  $its->execute([$id]);
  while($r=$its->fetch(PDO::FETCH_ASSOC)){
    $items[] = [
      'pid'=>0,'allow_cover'=>1,
      'name'=>(string)$r['name'], 'qty'=>(int)$r['qty'],
      'unit_price'=>(float)$r['unit_price'],
      'cover_note'=>'', 'cover_fee'=>0.0
    ];
  }
}

/* ===== ค่าพิมพ์ปก: ดึงจาก DB โดยตรง (ไม่คำนวณหน้าใบเสร็จ) ===== */
$note_raw = (string)($o['note'] ?? '');
$cover_detail_from_items = ''; // เก็บข้อความไว้แปะใต้บรรทัด "ค่าพิมพ์ปก"
foreach($items as $it){ if(trim($it['cover_note']??'')!==''){ $cover_detail_from_items = trim((string)$it['cover_note']); break; } }
// ถ้าใน items ไม่มี ให้ลองจากบล็อกใน orders.note
if ($cover_detail_from_items==='') $cover_detail_from_items = extract_cover_detail_from_note($note_raw);

// 1) ใช้ orders.cover_fee_total เป็นหลัก
$cover_total = 0.0;
try{ if(isset($o['cover_fee_total'])) $cover_total = (float)$o['cover_fee_total']; }catch(Throwable $e){}

// 2) ถ้าเป็น 0 ให้ลอง sum จากแถว (รองรับ quick_order ที่เขียน COVER ใน note/cover_fee)
if ($cover_total <= 0) {
  $cover_total = array_sum(array_map(fn($x)=> (float)$x['cover_fee'], $items));
}

// 3) ถ้ามียอด ให้เพิ่มเป็น 1 ไอเท็ม (ไม่โชว์สูตร)
if ($cover_total > 0) {
  $items[] = [
    'pid'=>0,'allow_cover'=>1,
    'name'=>'ค่าพิมพ์ปก',
    'qty'=>1,
    'unit_price'=>$cover_total,
    'cover_note'=>$cover_detail_from_items, // โชว์เฉพาะรายละเอียดข้อความ
    'cover_fee'=>0.0
  ];
}

// ทำความสะอาดหมายเหตุ (ไม่ให้ซ้ำ)
$note_clean = strip_cover_from_note_display($note_raw);

/* ---------- shop info ---------- */
$shop_name  = get_setting($pdo, 'shop_name',  'RTAF Diary Shop');
$shop_addr  = get_setting($pdo, 'shop_address',
  "171 หอสมุดกองทัพอากาศ แขวงสนามบิน\nเขตดอนเมือง กทม. 10210");
$shop_email = get_setting($pdo, 'shop_email', 'diary.rtaf@gmail.com');
$shop_phone = get_setting($pdo, 'shop_phone', '02-534-6283');
$shop_tax   = get_setting($pdo, 'shop_tax_id', '');

/* ---------- totals ---------- */
$subtotal = 0.0; foreach ($items as $r) $subtotal += ((float)$r['unit_price']) * ((int)$r['qty']);
$discount = (float)($o['discount'] ?? 0);

$opt           = strtolower(trim((string)($o['delivery_option'] ?? '')));
$ship_name_lc  = strtolower(trim((string)($o['ship_name'] ?? '')));
$shipping_fee  = (float)($o['shipping'] ?? 0);
$has_tracking  = trim((string)($o['tracking_no'] ?? '')) !== '';
$address_txt   = trim((string)($o['address'] ?? ''));

$is_pickup = ($opt === 'pickup') || preg_match('/pickup|รับเอง|หน้าร้าน/i', $ship_name_lc);
if (!$is_pickup && $ship_name_lc === '' && !$has_tracking && $shipping_fee <= 0) {
  if ($address_txt === '' || preg_match('/รับเอง|หน้าร้าน/i', $address_txt)) $is_pickup = true;
}
if ($has_tracking || $shipping_fee > 0 || preg_match('/ems|flash|j.?t|kerry|dhl|ไปรษณีย์|ขนส่ง/i', $ship_name_lc)) {
  $is_pickup = false;
}
$shipping   = $is_pickup ? 0.0 : $shipping_fee;
$ship_label = $is_pickup ? 'รับสินค้าที่ร้าน' : ('ค่าจัดส่ง (ขนส่ง'.(!empty($o['ship_name']) ? ': '.$o['ship_name'] : '').')');

$grand = max(0, $subtotal - $discount + $shipping);

/* ---------- ย้าย "สังกัด: ..." ขึ้นไปที่รายละเอียดลูกค้า ---------- */
$aff = null;
if ($note_raw !== '' && preg_match('/สังกัด\s*:\s*([^\r\n]+)/u', $note_raw, $mm)) {
  $aff = trim($mm[1]);
  // อย่าไปแก้ note จริง ตัดเฉพาะที่โชว์
  $note_clean = trim(preg_replace('/\s*สังกัด\s*:\s*[^\r\n]+/u', '', $note_clean));
}

/* ---------- payment status text ---------- */
$paidStates    = ['paid','processing','shipped','completed'];
$pay_stat_text = in_array(strtolower((string)$o['status']), $paidStates, true)
                 ? 'ชำระเงินเรียบร้อย' : 'ยังไม่ชำระเงิน';
$payment_meta  = '';
try{
  $pstmt=$pdo->prepare("SELECT method, slip_path, is_verified FROM payments WHERE order_id=? LIMIT 1");
  $pstmt->execute([$id]); $prow=$pstmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $has_slip    = trim((string)($prow['slip_path'] ?? '')) !== '';
  $is_verified = (int)($prow['is_verified'] ?? 0) === 1;
  if (!$is_verified && $has_slip && $pay_stat_text!=='ชำระเงินเรียบร้อย') $pay_stat_text = 'รอตรวจสอบการชำระเงิน';
  if ($prow){ $payment_meta = 'ช่องทาง: '.($prow['method'] ?: 'ไม่ระบุ'); }
}catch(Throwable $e){}

/* ---------- view model ---------- */
$issued_at  = $o['created_at'] ? date('d/m/Y', strtotime($o['created_at'])) : date('d/m/Y');
$shop_lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$shop_addr))));
if ($shop_email) $shop_lines[] = 'อีเมล '. $shop_email;
if ($shop_phone) $shop_lines[] = 'ติดต่อ '. $shop_phone;
if ($shop_tax)   $shop_lines[] = 'เลขผู้เสียภาษี '. $shop_tax;

$cust_lines = [];
$cust_lines[] = $o['fullname'] ?: '-';
if (!empty($o['address'])) foreach (preg_split('/\R/', (string)$o['address']) as $ln) { $ln=trim($ln); if ($ln!=='') $cust_lines[]=$ln; }
if (!empty($o['province']) || !empty($o['zipcode'])) $cust_lines[] = trim(($o['province']??'').' '.($o['zipcode']??''));
if (!empty($o['phone']))  $cust_lines[] = 'โทร '. $o['phone'];
if (!empty($o['email']))  $cust_lines[] = 'อีเมล '. $o['email'];
if ($aff !== null && $aff !== '') $cust_lines[] = 'สังกัด: '.$aff;
$cust_lines[] = 'สถานะการชำระเงิน: '.$pay_stat_text . ($payment_meta? ' · '.$payment_meta : '');
$cust_lines[] = 'วันที่เปิดบิล  '.$issued_at;

/* ---------- signatures data ---------- */
$admin_name  = $_SESSION['user']['name'] ?? 'ผู้ดูแลระบบ';
$receiver_name = $o['fullname'] ?: '-';
$print_date  = date('d/m/Y');
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>ใบเสร็จ (ADMIN) #<?= (int)$o['id'] ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{ --ink:#111; --muted:#6b7280; }
  *{ box-sizing:border-box; }
  body{ font-family: system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,'TH Sarabun New',sans-serif; color:var(--ink); background:#fff; margin:0; }
  .sheet{ max-width: 900px; margin: 24px auto; padding: 24px; }
  .titlebar{ display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:8px; }
  .titlebar h1{ font-size:24px; font-weight:800; margin:0; }
  .badge{ border:1px solid #000; background:#fff; color:#000; padding:2px 10px; border-radius:8px; font-weight:800; }
  hr.bold{ border:none; border-top:1px solid #000; margin:14px 0; }
  .row{ display:flex; gap:24px; }
  .col{ flex:1; }
  .h6{ font-size:16px; font-weight:800; margin:0 0 6px 0; }
  .small{ font-size:13px; line-height:1.6; }
  table{ width:100%; border-collapse:collapse; }
  th, td{ font-size:14px; padding:8px 10px; vertical-align:top; }
  thead th{ border-bottom:1px solid #000; }
  tbody td{ border-bottom:1px solid #e5e7eb; }
  .right{ text-align:right; }

  /* toolbar (ไม่ถูกพิมพ์) */
  .noprint{ max-width: 960px; margin: 18px auto 0; padding: 0 16px 0; }
  .card{ border:1px solid #e5e7eb; border-radius:12px; padding:14px; margin:10px 0; }
  .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
  @media(max-width:900px){ .grid{ grid-template-columns:1fr; } }
  .input, textarea{ width:100%; padding:.5rem .6rem; border:1px solid #d1d5db; border-radius:.5rem; font-family:inherit; }
  textarea{ min-height:86px; }
  .btn{ display:inline-block; padding:.5rem .8rem; border:1px solid #111; border-radius:.5rem; background:#111; color:#fff; text-decoration:none; cursor:pointer; }
  .btn.ghost{ background:#fff; color:#111; }
  .muted{ color:#6b7280; }
  .alert{ padding:.65rem .8rem; border:1px solid #e5e7eb; border-radius:.5rem; }
  .ok{ background:#f0fdf4; border-color:#bbf7d0; }
  .err{ background:#fef2f2; border-color:#fecaca; }
  @media print{
    @page{ size:A4; margin:14mm; }
    .noprint{ display:none !important; }
    .sheet{ padding:0; margin:0; }
  }

  /* signatures */
  .sign-wrap{ display:flex; gap:28px; margin-top:28px; }
  .sign-col{ flex:1; text-align:center; }
  .sign-line{ border-bottom:1px solid #000; height:56px; margin:0 12% 6px; }
  .sign-label{ font-weight:700; }
  .sign-meta{ color:var(--muted); margin-top:4px; font-size:13px; }
  @media print{ .sign-line{ margin:0 15% 6px; } }
</style>
</head>
<body>

<!-- ===== toolbar edit + ปุ่มพิมพ์ ===== -->
<div class="noprint">
  <?php if($msg): ?><div class="alert ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>

  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem">
    <strong>ออเดอร์ #<?= (int)$o['id'] ?></strong>
    <a class="btn" href="javascript:window.print()">พิมพ์ใบเสร็จ</a>
    <a class="btn ghost" href="../admin/orders.php?id=<?= (int)$o['id'] ?>">ไปหน้าออเดอร์</a>
  </div>

  <div class="grid">
    <div class="card">
      <h3 style="margin:0 0 8px 0">แก้ไขข้อมูลร้าน/แอดมิน (Settings)</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_shop">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label>ชื่อร้าน</label>
            <input class="input" name="shop_name" value="<?= h($shop_name) ?>">
          </div>
          <div>
            <label>เลขผู้เสียภาษี</label>
            <input class="input" name="shop_tax_id" value="<?= h($shop_tax) ?>">
          </div>
        </div>
        <div style="margin-top:8px">
          <label>ที่อยู่ร้าน (หลายบรรทัด)</label>
          <textarea name="shop_address" class="input"><?= h($shop_addr) ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px">
          <div>
            <label>อีเมลร้าน</label>
            <input class="input" name="shop_email" value="<?= h($shop_email) ?>">
          </div>
          <div>
            <label>เบอร์โทรร้าน</label>
            <input class="input" name="shop_phone" value="<?= h($shop_phone) ?>">
          </div>
        </div>
        <div style="margin-top:10px">
          <button class="btn" type="submit">บันทึกข้อมูลร้าน</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h3 style="margin:0 0 8px 0">แก้ไขข้อมูลลูกค้าในออเดอร์ #<?= (int)$o['id'] ?></h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_customer">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label>ชื่อ-นามสกุล</label>
            <input class="input" name="fullname" value="<?= h($o['fullname'] ?? '') ?>">
          </div>
          <div>
            <label>เบอร์โทร</label>
            <input class="input" name="phone" value="<?= h($o['phone'] ?? '') ?>">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px">
          <div>
            <label>อีเมล</label>
            <input class="input" name="email" value="<?= h($o['email'] ?? '') ?>">
          </div>
          <div>
            <label>จังหวัด / รหัสไปรษณีย์</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <input class="input" name="province" value="<?= h($o['province'] ?? '') ?>" placeholder="จังหวัด">
              <input class="input" name="zipcode"  value="<?= h($o['zipcode'] ?? '') ?>" placeholder="รหัสไปรษณีย์">
            </div>
          </div>
        </div>
        <div style="margin-top:8px">
          <label>ที่อยู่ (หลายบรรทัด)</label>
          <textarea name="address" class="input"><?= h($o['address'] ?? '') ?></textarea>
        </div>
        <div style="margin-top:10px">
          <button class="btn" type="submit">บันทึกข้อมูลลูกค้า</button>
        </div>
      </form>

      <hr style="margin:12px 0">

      <!-- ฟอร์มแก้ “รายละเอียดพิมพ์ปก” -->
      <h3 style="margin:0 0 8px 0">รายละเอียดพิมพ์ปก</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_cover_detail">
        <textarea class="input" name="cover_detail" placeholder="ข้อความที่จะโชว์ใต้บรรทัด “ค่าพิมพ์ปก” (ไม่ใช่สูตรคำนวณ)">
<?= h($cover_detail_from_items) ?></textarea>
        <div class="muted" style="margin:6px 0 10px">
          * บันทึกแล้วข้อความจะแสดงใต้บรรทัด “ค่าพิมพ์ปก” ทางขวา และจะถูกซ่อนจากช่อง “หมายเหตุ”
        </div>
        <button class="btn" type="submit">บันทึกรายละเอียดพิมพ์ปก</button>
      </form>
    </div>
  </div>
</div>
<!-- ===== /toolbar ===== -->

<div class="sheet">
  <div class="titlebar">
    <h1>ใบเสร็จรับเงิน</h1>
    <span class="badge">#<?= (int)$o['id'] ?></span>
  </div>

  <div class="row" style="margin-bottom:6px">
    <div class="col">
      <div class="h6"><?= h($shop_name) ?></div>
      <div class="small">
        <?php foreach($shop_lines as $ln): ?><div><?= h($ln) ?></div><?php endforeach; ?>
      </div>
    </div>
    <div class="col">
      <div class="h6" style="text-align:right">รายละเอียดลูกค้า</div>
      <div class="small" style="text-align:right">
        <?php foreach($cust_lines as $ln): ?><div><?= h($ln) ?></div><?php endforeach; ?>
      </div>
    </div>
  </div>

  <hr class="bold">

  <table>
    <thead>
      <tr>
        <th style="width:42px">#</th>
        <th>รายการสินค้า</th>
        <th class="right" style="width:80px">จำนวน</th>
        <th class="right" style="width:120px">ราคาต่อหน่วย</th>
        <th class="right" style="width:140px">จำนวนเงิน</th>
      </tr>
    </thead>
    <tbody>
      <?php $i=1; foreach($items as $r): $line=((float)$r['unit_price'])*(int)$r['qty']; ?>
      <tr>
        <td class="right"><?= $i++ ?></td>
        <td>
          <?= h($r['name']) ?>
          <?php if (!empty($r['cover_note'])): ?>
            <div class="small" style="color:#6b7280;margin-top:4px">
              รายละเอียดพิมพ์ปก:<br><?= nl2br(h($r['cover_note'])) ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="right"><?= (int)$r['qty'] ?></td>
        <td class="right"><?= baht($r['unit_price']) ?> บาท</td>
        <td class="right"><?= baht($line) ?> บาท</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="row" style="margin-top:8px">
    <div class="col">
      <div class="h6" style="margin-bottom:6px">หมายเหตุ</div>
      <div class="small" style="background:#f3f4f6;border-radius:8px;padding:10px;min-height:80px">
        <?= nl2br(h($note_clean !== '' ? $note_clean : '-')) ?>
      </div>
    </div>
    <div class="col">
      <table style="width:360px; margin-left:auto" class="small">
        <tr><td>ทั้งหมด</td><td class="right"><?= baht($subtotal) ?> บาท</td></tr>
        <tr><td>ส่วนลด</td><td class="right">−<?= baht($discount) ?> บาท</td></tr>
        <tr><td><?= h($ship_label) ?></td><td class="right"><?= baht($shipping) ?> บาท</td></tr>
        <tr><td colspan="2" style="border-top:1px solid #000"></td></tr>
        <tr><td><b>รวมราคาสุทธิ</b></td><td class="right"><b><?= baht($grand) ?> บาท</b></td></tr>
      </table>
    </div>
  </div>

  <!-- ===== ช่องลายเซ็น ===== -->
  <div class="sign-wrap">
    <div class="sign-col">
      <div class="sign-line"></div>
      <div class="sign-label">ลงชื่อ (ผู้รับเงิน)</div>
      <div class="sign-meta">ชื่อ: <?= h($admin_name) ?> — วันที่: <?= h($print_date) ?></div>
    </div>
    <div class="sign-col">
      <div class="sign-line"></div>
      <div class="sign-label">ลงชื่อ (ผู้รับสินค้า)</div>
      <div class="sign-meta">ชื่อ: <?= h($receiver_name) ?> — วันที่: <?= h($print_date) ?></div>
    </div>
  </div>

  <div class="small" style="text-align:center;margin-top:16px;color:#6b7280">
    เอกสารนี้พิมพ์โดยผู้ดูแลระบบ — ขอบคุณค่ะ
  </div>
</div>
</body>
</html>
