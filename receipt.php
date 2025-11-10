<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2, '.', ','); }
if (!function_exists('get_setting')) {
  function get_setting(PDO $pdo, string $key, $default=null){
    try{ $st=$pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1'); $st->execute([$key]);
         $v=$st->fetchColumn(); return ($v===false)?$default:$v;
    }catch(Throwable $e){ return $default; }
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

/* ---------- helpers (cover note block) ---------- */
function extract_cover_detail_block(string $txt): string {
  if (preg_match('/รายละเอียด(?:พิมพ์ปก)?\s*:\s*([\s\S]*?)(?=\R\s*\R|$)/u', $txt, $m)) {
    return trim(preg_replace("/\R{3,}/", "\n\n", (string)$m[1]));
  }
  return '';
}
function strip_cover_blocks_from_note(string $txt): string {
  $txt = preg_replace('/(^|\R)\h*พิมพ์ปก\h*:[^\r\n]*(?:\R|$)/u', '$1', $txt);
  $txt = preg_replace('/(^|\R)\h*รายละเอียด(?:พิมพ์ปก)?\h*:\s*[\s\S]*?(?=\R\s*\R|$)/u', '$1', $txt);
  $txt = preg_replace("/\n{3,}/", "\n\n", $txt);
  return trim($txt);
}

/* ---------- params ---------- */
$id      = (int)($_GET['id'] ?? 0);
$code    = trim((string)($_GET['code'] ?? ''));
$phone4  = preg_replace('/\D+/', '', (string)($_GET['phone4'] ?? ''));
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$sig = (string)($_GET['sig'] ?? '');

/* ---------- order (รองรับไม่มี shipping_methods) ---------- */
$joinShip = has_column($pdo,'orders','shipping_method_id') && has_column($pdo,'shipping_methods','name');
if ($joinShip) {
  $sql = "SELECT o.*, sm.name AS ship_name
          FROM orders o LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
          WHERE o.id=?";
} else {
  $sql = "SELECT o.* FROM orders o WHERE o.id=?";
}
$st = $pdo->prepare($sql); $st->execute([$id]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { http_response_code(404); echo 'Order not found'; exit; }

/* ---------- auth ---------- */
$auth_ok = false;
if (function_exists('is_admin') && is_admin()) $auth_ok = true;
$pickup_ok = ($code !== '' && strtoupper($code) === strtoupper((string)($o['pickup_code'] ?? '')));
if ($pickup_ok) $auth_ok = true;
$last4_db = substr(preg_replace('/\D+/', '', (string)($o['phone'] ?? '')), -4);
if ($phone4 !== '' && $last4_db !== '' && substr($phone4, -4) === $last4_db) $auth_ok = true;
if ($sig !== '' && hash_equals($sig, hash_hmac('sha256', 'rcpt:'.$id, session_id()))) { $auth_ok = true; }
if (!$auth_ok) { http_response_code(403); echo 'Forbidden'; exit; }

/* ---------- order_items (พร้อมดึง cover_fee/note ถ้ามี) ---------- */
$items    = [];
$note_raw = (string)($o['note'] ?? '');

try {
  $hasAllow = has_column($pdo,'products','allow_cover_print');
  $allowSel = $hasAllow ? 'p.allow_cover_print AS allow_cover' : '1 AS allow_cover';

  $sql = "SELECT
            p.name,
            oi.product_id AS pid,
            oi.qty,
            COALESCE(NULLIF(oi.unit_price,0), NULLIF(oi.price,0), p.price) AS unit_price,
            {$allowSel}";
  $has_fee=false; $has_meta=false; $has_oi_note=false;
  foreach (['cover_fee'=>'has_fee','meta_json'=>'has_meta','note'=>'has_oi_note'] as $col=>$flag){
    try{ $pdo->query("SELECT {$col} FROM order_items LIMIT 1"); $$flag=true; $sql.=", oi.{$col}"; }catch(Throwable $e){}
  }
  $sql .= " FROM order_items oi
            LEFT JOIN products p ON p.id=oi.product_id
            WHERE oi.order_id=?";
  $its = $pdo->prepare($sql); $its->execute([$id]);

  while($r=$its->fetch(PDO::FETCH_ASSOC)){
    $cover_fee = 0.0;
    if($has_fee && is_numeric($r['cover_fee'])) $cover_fee = (float)$r['cover_fee'];
    elseif($has_meta && trim((string)$r['meta_json'])!==''){
      $mj = json_decode((string)$r['meta_json'], true);
      if(is_array($mj) && isset($mj['cover_fee'])) $cover_fee=(float)$mj['cover_fee'];
    }elseif($has_oi_note && stripos((string)$r['note'],'COVER:')===0){
      $mj = json_decode(trim(substr((string)$r['note'],6)), true);
      if(is_array($mj) && isset($mj['cover_fee'])) $cover_fee=(float)$mj['cover_fee'];
    }

    $items[] = [
      'pid'         => (int)($r['pid'] ?? 0),
      'name'        => (string)$r['name'],
      'qty'         => (int)$r['qty'],
      'unit_price'  => (float)$r['unit_price'],
      'allow_cover' => (int)($r['allow_cover'] ?? 1) ? 1 : 0,
      'cover_fee'   => $cover_fee,
      'cover_note'  => '' // จะเติมทีหลังถ้าจำเป็น
    ];
  }
} catch(Throwable $e){
  $its=$pdo->prepare("
    SELECT p.name, 0 AS pid, oi.qty, COALESCE(NULLIF(oi.unit_price,0), NULLIF(oi.price,0), p.price) AS unit_price
    FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id
    WHERE oi.order_id=?");
  $its->execute([$id]);
  while($r=$its->fetch(PDO::FETCH_ASSOC)){
    $items[]=[
      'pid'=>0,'name'=>(string)$r['name'],'qty'=>(int)$r['qty'],'unit_price'=>(float)$r['unit_price'],
      'allow_cover'=>1,'cover_fee'=>0.0,'cover_note'=>''
    ];
  }
}

/* ---------- ค่าพิมพ์ปก: ใช้ค่าจาก DB (ไม่คำนวณหน้าเว็บ) ---------- */
$cover_detail = extract_cover_detail_block($note_raw); // ข้อความรายละเอียด (ถ้ามีในบันทึก)
$cover_total  = 0.0;

// 1) ใช้ orders.cover_fee_total เป็นหลัก
try{ if(isset($o['cover_fee_total'])) $cover_total = (float)$o['cover_fee_total']; }catch(Throwable $e){}

// 2) ถ้าไม่เจอ ให้ลอง sum จาก order_items (เฉพาะที่ allow_cover=1)
if ($cover_total <= 0) {
  $cover_total = array_sum(array_map(fn($x)=> $x['allow_cover'] ? (float)$x['cover_fee'] : 0.0, $items));
}

// 3) ถ้ายัง 0 ลองแคะจากบล็อก “พิมพ์ปก: ... = xxx บาท” ในหมายเหตุ
if ($cover_total <= 0) {
  if (preg_match_all('/พิมพ์ปก\s*:\s*(.*?)=\s*([\d\.,]+)\s*บาท/u', (string)$o['note'], $m, PREG_SET_ORDER)) {
    foreach($m as $mm){ $cover_total += (float)str_replace(',','',$mm[2]); }
  }
}

// 4) ถ้ามียอด → แทรกบรรทัด “ค่าพิมพ์ปก” (qty=1) และแสดง “รายละเอียดพิมพ์ปก” ใต้รายการ (ถ้ามี)
if ($cover_total > 0) {
  $items[] = [
    'pid'=>0,
    'name'=>'ค่าพิมพ์ปก',
    'qty'=>1,
    'unit_price'=>$cover_total,
    'allow_cover'=>1,
    'cover_fee'=>0.0,
    'cover_note'=> $cover_detail
  ];
}

// ทำความสะอาดหมายเหตุ (ไม่ให้ซ้ำพิมพ์ปก)
$note_clean = strip_cover_blocks_from_note($note_raw);

/* ---------- ข้อมูลร้าน ---------- */
$shop_name  = get_setting($pdo, 'shop_name',  'RTAF Diary Shop');
$shop_addr  = get_setting($pdo, 'shop_address',
  "171 หอสมุดกองทัพอากาศ แขวงสนามบิน\nเขตดอนเมือง กทม. 10210");
$shop_email = get_setting($pdo, 'shop_email', 'diary.rtaf@gmail.com');
$shop_phone = get_setting($pdo, 'shop_phone', '02-534-6283');

/* ---------- ยอดรวม ---------- */
$subtotal = 0.0; foreach ($items as $r) $subtotal += ((float)($r['unit_price'] ?? 0)) * ((int)($r['qty'] ?? 0));
$discount = (float)($o['discount'] ?? 0);

/* ---------- pickup vs ship ---------- */
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
$ship_label = $is_pickup ? 'รับสินค้าที่ร้าน' : ('ค่าจัดส่ง'.(!empty($o['ship_name']) ? ' ('.$o['ship_name'].')' : ''));

$grand = max(0, $subtotal - $discount + $shipping);

/* ---------- สถานะการชำระเงิน ---------- */
$paidStates    = ['paid','processing','shipped','completed'];
$pay_stat_text = in_array(strtolower((string)($o['status'] ?? '')), $paidStates, true)
                 ? 'ชำระเงินเรียบร้อย' : 'ยังไม่ชำระเงิน';
if ($pay_stat_text!=='ชำระเงินเรียบร้อย'){
  try{
    $pstmt = $pdo->prepare("SELECT slip_path, is_verified FROM payments WHERE order_id=? LIMIT 1");
    $pstmt->execute([$id]); $prow=$pstmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $has_slip   = trim((string)($prow['slip_path'] ?? '')) !== '';
    $is_verified= (int)($prow['is_verified'] ?? 0) === 1;
    if ($has_slip && !$is_verified) $pay_stat_text = 'รอตรวจสอบการชำระเงิน';
  }catch(Throwable $e){}
}

/* ---------- ส่วนหัว & หมายเหตุ ---------- */
$issued_at = $o['created_at'] ? date('d/m/Y', strtotime($o['created_at'])) : date('d/m/Y');
$shop_lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$shop_addr))));
if ($shop_email) $shop_lines[] = 'อีเมล '. $shop_email;
if ($shop_phone) $shop_lines[] = 'ติดต่อ '. $shop_phone;

$cust_lines = [];
$cust_lines[] = $o['fullname'] ?: '-';
if (!empty($o['address'])) foreach (preg_split('/\R/', (string)$o['address']) as $ln) { $ln=trim($ln); if ($ln!=='') $cust_lines[]=$ln; }
if (!empty($o['province']) || !empty($o['zipcode'])) $cust_lines[] = trim(($o['province']??'').' '.($o['zipcode']??''));
if (!empty($o['phone']))  $cust_lines[] = 'โทร '. $o['phone'];
if (!empty($o['email']))  $cust_lines[] = 'อีเมล '. $o['email'];

/* ดึง “สังกัด: …” จากหมายเหตุมาไว้กับรายละเอียดลูกค้า */
$aff = null;
if (preg_match('/สังกัด\s*:\s*([^\r\n]+)/u', $note_raw, $mm)) {
  $aff = trim($mm[1]);
  $note_clean = preg_replace('/\s*สังกัด\s*:\s*[^\r\n]+/u', '', $note_clean ?? strip_cover_blocks_from_note($note_raw));
}
if (!isset($note_clean)) $note_clean = strip_cover_blocks_from_note($note_raw);
if ($aff !== null && $aff !== '') $cust_lines[] = 'สังกัด: '.$aff;
$cust_lines[] = 'สถานะการชำระเงิน: '.$pay_stat_text;
$cust_lines[] = 'วันที่เปิดบิล  '.$issued_at;
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>ใบเสร็จรับเงิน #<?= (int)$o['id'] ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{ --ink:#111; --muted:#6b7280; }
  *{ box-sizing:border-box; }
  body{ font-family: system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,'TH Sarabun New',sans-serif; color:var(--ink); background:#fff; margin:0; }
  .sheet{ max-width: 840px; margin: 24px auto; padding: 24px; }
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
  .colgrid{ display:flex; flex-direction:column; gap:6px; }
  .colgrid .line{ line-height:1.65; }
  .colgrid.right{ text-align:right; align-items:flex-end; }
  .note-wrap{ margin-top:12px; }
  .note-label{ font-weight:800; border-top:1px solid #000; padding-top:8px; }
  .note-box{ background:#efefef; min-height:90px; padding:10px; border-radius:4px; }
  .sub{ color:#6b7280; font-size:12px; margin-top:4px; }
  .totals{ width:340px; margin-left:auto; }
  .totals td{ padding:6px 8px; }
  .totals .sum{ font-weight:800; border-top:1px solid #000; }
  .foot{ text-align:center; margin-top:18px; color:var(--muted); }
  @media print{ @page{ size:A4; margin:14mm; } .sheet{ padding:0; margin:0; } .print-btn{ display:none; } }
  .print-btn{ position:fixed; right:16px; top:16px; }
  .print-btn .btn{ padding:.5rem .8rem; border:1px solid #111; border-radius:.5rem; background:#fff; cursor:pointer; }
</style>
</head>
<body>
<div class="print-btn"><button class="btn" onclick="window.print()">พิมพ์</button></div>

<div class="sheet">
  <div class="titlebar">
    <h1>ใบเสร็จรับเงิน</h1>
    <span class="badge">#<?= (int)$o['id'] ?></span>
  </div>

  <div class="row" style="margin-bottom:6px">
    <div class="col">
      <div class="h6"><?= h($shop_name) ?></div>
      <div class="small colgrid">
        <?php foreach($shop_lines as $ln): ?><div class="line"><?= h($ln) ?></div><?php endforeach; ?>
      </div>
    </div>
    <div class="col">
      <div class="h6" style="text-align:right">รายละเอียดลูกค้า</div>
      <div class="small colgrid right">
        <?php foreach($cust_lines as $ln): ?><div class="line"><?= h($ln) ?></div><?php endforeach; ?>
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
      <?php $i=1; foreach($items as $r): $line=((float)($r['unit_price'] ?? 0))*(int)($r['qty'] ?? 0); ?>
      <tr>
        <td class="right"><?= $i++ ?></td>
        <td>
          <?= h($r['name'] ?? '') ?>
          <?php if (!empty($r['cover_note'])): ?>
            <div class="sub">รายละเอียดพิมพ์ปก:<br><?= nl2br(h($r['cover_note'])) ?></div>
          <?php endif; ?>
        </td>
        <td class="right"><?= (int)($r['qty'] ?? 0) ?></td>
        <td class="right"><?= baht($r['unit_price'] ?? 0) ?> บาท</td>
        <td class="right"><?= baht($line) ?> บาท</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="row" style="margin-top:8px">
    <div class="col">
      <div class="note-wrap">
        <div class="note-label">หมายเหตุ</div>
        <div class="note-box small"><?= nl2br(h(trim($note_clean) !== '' ? $note_clean : '-')) ?></div>
      </div>
    </div>
    <div class="col">
      <table class="totals small">
        <tr><td>ทั้งหมด</td><td class="right"><?= baht($subtotal) ?> บาท</td></tr>
        <tr><td>ส่วนลด</td><td class="right">−<?= baht($discount) ?> บาท</td></tr>
        <tr><td><?= h($ship_label) ?></td><td class="right"><?= baht($shipping) ?> บาท</td></tr>
        <tr class="sum"><td><b>รวมราคาสุทธิ</b></td><td class="right"><b><?= baht($grand) ?> บาท</b></td></tr>
      </table>
    </div>
  </div>

  <div class="foot small">ขอบคุณที่ใช้บริการ</div>
</div>
</body>
</html>
