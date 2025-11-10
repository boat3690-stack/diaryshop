<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

/* ---------------- Helpers ---------------- */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
if (!function_exists('format_currency')) {
  function format_currency($n){ return number_format((float)$n, 2, '.', ','); }
}
if (!function_exists('get_setting')) {
  function get_setting(PDO $pdo, string $key, $default=null){
    try{ $st=$pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1');
         $st->execute([$key]); $v=$st->fetchColumn(); return ($v===false)?$default:$v;
    }catch(Throwable $e){ return $default; }
  }
}
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try{
      $sql = "SHOW COLUMNS FROM `" . str_replace("`","``",$table) . "` LIKE ?"; 
      $st  = $pdo->prepare($sql); $st->execute([$col]); return (bool)$st->fetch();
    }catch(Throwable $e){ return false; }
  }
}

/* ---------- ป้ายสถานะ ---------- */
$status_labels = [
  'unpaid'     => 'ยังไม่ชำระเงิน',
  'paid'       => 'ชำระเงินแล้ว รอตรวจสอบ',
  'processing' => 'ตรวจสอบแล้ว เตรียมส่งสินค้า',
  'shipped'    => 'กำลังจัดส่ง',
  'completed'  => 'จัดส่งสำเร็จ',
  'cancelled'  => 'ยกเลิก',
];
function status_th(?string $s): string {
  global $status_labels; $s=strtolower((string)($s??'')); return $status_labels[$s] ?? ($s ?: 'ไม่ทราบสถานะ');
}
function status_badge(?string $status): string {
  $key=strtolower(trim((string)$status));
  $classes=[
    'unpaid'=>'status-unpaid','paid'=>'status-paid','processing'=>'status-processing',
    'shipped'=>'status-shipped','completed'=>'status-completed','cancelled'=>'status-cancelled'
  ];
  $cls=$classes[$key] ?? 'status-unknown';
  return '<span class="badge '.$cls.'">'.h(status_th($key)).'</span>';
}

/* ---------- ตัวช่วย “รายละเอียดพิมพ์ปก” ---------- */
function extract_cover_detail_block(string $txt): string {
  if (preg_match('/รายละเอียด(?:พิมพ์ปก)?\s*:\s*([\s\S]*?)(?=\R\s*\R|$)/u', $txt, $m)) {
    return trim(preg_replace("/\R{3,}/", "\n\n", (string)$m[1]));
  }
  return '';
}

/* ---------- รับ query ---------- */
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
if ($q === '') {
  if (isset($_GET['order']) && $_GET['order'] !== '') $q = (string)$_GET['order'];
  elseif (isset($_GET['phone']) && $_GET['phone'] !== '') $q = (string)$_GET['phone'];
}

/* ---------- ส่งข้อความถึงร้าน (ออปชัน) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'chat') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF'); header('Location: track.php'); exit; }
  $oid=(int)($_POST['order'] ?? 0); $msg=trim((string)($_POST['message'] ?? '')); $qback=trim((string)($_POST['q'] ?? ''));
  if ($oid && $msg!=='') { try{ $pdo->prepare("INSERT INTO order_messages (order_id, sender, message) VALUES (?,?,?)")->execute([$oid,'customer',$msg]); }catch(Throwable $e){} }
  header('Location: track.php?'.($qback!=='' ? 'q='.urlencode($qback) : 'q='.$oid)); exit;
}

/* ---------- ค้นหาออเดอร์ ---------- */
$mode = 'none'; // none | single | multi
$order = null; $items = []; $orders = []; $itemsByOrder = [];

if ($q !== '') {
  $norm = ltrim($q, "# \t\n\r\0\x0B");
  if ($norm !== '' && ctype_digit($norm)) {
    $st = $pdo->prepare("SELECT * FROM orders WHERE id=?"); $st->execute([(int)$norm]); $order=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($order) $mode='single';
  }

  if ($mode !== 'single') {
    $normPhone = preg_replace('/\D+/', '', $q);
    if ($normPhone !== '') {
      $st = $pdo->prepare("
        SELECT * FROM orders
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,'-',''),' ',''),'+',''),'.','') LIKE ?
        ORDER BY id DESC
      ");
      $st->execute(['%'.$normPhone.'%']); $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if ($orders) {
        if (count($orders)===1) { $order=$orders[0]; $mode='single'; }
        else {
          $mode='multi';
          $ids = array_map(fn($r)=>(int)$r['id'], $orders);
          if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $has_iname = has_column($pdo,'order_items','item_name');
            $sql = "SELECT oi.order_id, oi.product_id, p.name AS p_name".($has_iname?", oi.item_name AS i_name":"").", oi.qty
                    FROM order_items oi
                    LEFT JOIN products p ON p.id=oi.product_id
                    WHERE oi.order_id IN ($in)
                    ORDER BY oi.order_id, oi.id";
            $sti = $pdo->prepare($sql); $sti->execute($ids);
            while ($r = $sti->fetch(PDO::FETCH_ASSOC)) {
              $name = trim((string)($r['p_name'] ?? ''));
              if ($name==='') $name = trim((string)($r['i_name'] ?? ''));
              if ($name==='') $name = 'สินค้า #'.(int)($r['product_id'] ?? 0);
              $itemsByOrder[(int)$r['order_id']][] = ['name'=>$name,'qty'=>(int)$r['qty']];
            }
          }
        }
      }
    }
  }

  // ===== ดึงสินค้า (โหมด single) + เติมบรรทัด "ค่าพิมพ์ปก" (ไม่คำนวณใหม่) =====
  if ($mode === 'single' && $order) {
    $cols = [
      'oi.product_id AS pid',
      'p.name AS p_name',
      'oi.qty AS qty',
      'COALESCE(NULLIF(oi.unit_price,0), NULLIF(oi.price,0), p.price) AS price'
    ];
    $has_iname     = has_column($pdo,'order_items','item_name');
    $has_cfee      = has_column($pdo,'order_items','cover_fee');
    $has_cnote     = has_column($pdo,'order_items','cover_note');
    $has_inote     = has_column($pdo,'order_items','item_note');
    $has_meta      = has_column($pdo,'order_items','meta_json');
    $has_oi_note   = has_column($pdo,'order_items','note');

    if ($has_iname)   $cols[] = 'oi.item_name AS i_name';
    if ($has_cfee)    $cols[] = 'oi.cover_fee';
    if ($has_cnote)   $cols[] = 'oi.cover_note';
    if ($has_inote)   $cols[] = 'oi.item_note';
    if ($has_meta)    $cols[] = 'oi.meta_json';
    if ($has_oi_note) $cols[] = 'oi.note AS oi_note';

    $sql = "SELECT ".implode(',', $cols)."
            FROM order_items oi
            LEFT JOIN products p ON p.id=oi.product_id
            WHERE oi.order_id=?";
    $st = $pdo->prepare($sql); $st->execute([(int)$order['id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $items = []; $has_any_cover_line=false;

    foreach ($rows as $r) {
      // ชื่อสินค้าแบบ fallback
      $name = trim((string)($r['p_name'] ?? ''));
      if ($name==='') $name = trim((string)($r['i_name'] ?? ''));
      if ($name==='') $name = 'สินค้า #'.(int)($r['pid'] ?? 0);

      $qty   = (int)$r['qty'];
      $price = (float)$r['price'];
      $items[] = ['name'=>$name,'qty'=>$qty,'price'=>$price];

      // ค่าพิมพ์ปกต่อบรรทัด (ถ้ามีเก็บใน items)
      $cover_fee  = 0.0;
      $cover_note = '';

      if ($has_cfee && isset($r['cover_fee']) && is_numeric($r['cover_fee'])) $cover_fee=(float)$r['cover_fee'];
      if ($cover_note==='' && $has_cnote && isset($r['cover_note']) && trim((string)$r['cover_note'])!=='') {
        $cover_note = trim((string)$r['cover_note']);
      }
      if (($cover_fee<=0 || $cover_note==='') && $has_inote && !empty($r['item_note'])) {
        // เก็บรายละเอียดไว้ที่ item_note บาง schema
        if ($cover_note==='') $cover_note = trim((string)$r['item_note']);
      }
      if (($cover_fee<=0 || $cover_note==='') && $has_meta && !empty($r['meta_json'])) {
        $mj = json_decode((string)$r['meta_json'], true);
        if (is_array($mj)) {
          if ($cover_fee<=0 && isset($mj['cover_fee'])) $cover_fee=(float)$mj['cover_fee'];
          if ($cover_note==='' && !empty($mj['cover_note'])) $cover_note=(string)$mj['cover_note'];
        }
      }
      if (($cover_fee<=0 || $cover_note==='') && $has_oi_note && !empty($r['oi_note']) && stripos((string)$r['oi_note'],'COVER:')===0) {
        $mj = json_decode(trim(substr((string)$r['oi_note'],6)), true);
        if (is_array($mj)) {
          if ($cover_fee<=0 && isset($mj['cover_fee'])) $cover_fee=(float)$mj['cover_fee'];
          if ($cover_note==='' && !empty($mj['cover_note'])) $cover_note=(string)$mj['cover_note'];
        }
      }

      if ($cover_fee > 0) {
        $has_any_cover_line = true;
        $label = 'ค่าพิมพ์ปก' . ($cover_note ? ' — รายละเอียด: '.$cover_note : '');
        $items[] = ['name'=>$label, 'qty'=>1, 'price'=>$cover_fee];
      }
    }

    // ถ้ายังไม่มีบรรทัดค่าพิมพ์ปกเลย → fallback: orders.cover_fee_total / note
    if (!$has_any_cover_line) {
      $cover_total = 0.0;
      if (isset($order['cover_fee_total'])) $cover_total = (float)$order['cover_fee_total'];
      if ($cover_total <= 0) {
        // ลองพาร์สจาก note รูปแบบ "พิมพ์ปก: ... = XXX บาท"
        $note_raw = (string)($order['note'] ?? '');
        if (preg_match_all('/พิมพ์ปก\s*:\s*(.*?)=\s*([\d\.,]+)\s*บาท/u', $note_raw, $mm, PREG_SET_ORDER)) {
          foreach ($mm as $m) $cover_total += (float)str_replace(',','',$m[2]);
        }
      }
      if ($cover_total > 0) {
        $detail = extract_cover_detail_block((string)($order['note'] ?? ''));
        $items[] = [
          'name'  => 'ค่าพิมพ์ปก'.($detail ? ' — รายละเอียด: '.$detail : ''),
          'qty'   => 1,
          'price' => $cover_total
        ];
      }
    }
  }
}

include __DIR__ . '/partials/header.php';
?>
<style>
.track-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-top:.6rem; }
.qr-wrap{ width:min(80%, 420px); max-width:100%; margin-left:auto; margin-right:auto; }
.qr-card, .barcode-card{ border:0; background:transparent; padding:0; box-shadow:none; }
.qr-box { display: none; } /* ซ่อน QR บนเว็บ */
.barcode-box{ width:100%; height:120px; overflow:hidden; }
.barcode-box img{ width:100% !important; height:100% !important; display:block; object-fit:contain !important; }
.table-wrap{ overflow-x:auto; }
.badge{ display:inline-flex;align-items:center;justify-content:center;padding:.2rem .55rem;border-radius:999px;font-size:.82rem;font-weight:600;border:1px solid transparent;line-height:1; }
.badge.status-unpaid{     color:#991b1b;background:#fef2f2;border-color:#fecaca; }
.badge.status-paid{       color:#065f46;background:#ecfdf5;border-color:#a7f3d0; }
.badge.status-processing{ color:#3730a3;background:#eef2ff;border-color:#c7d2fe; }
.badge.status-shipped{    color:#075985;background:#e0f2fe;border-color:#bae6fd; }
.badge.status-completed{  color:#166534;background:#dcfce7;border-color:#bbf7d0; }
.badge.status-cancelled{  color:#6b7280;background:#f3f4f6;border-color:#e5e7eb; }
.badge.status-unknown{    color:#44403c;background:#f5f5f4;border-color:#e7e5e4; }
.small{ font-size:.9rem; opacity:.85; }

/* ใบเสร็จในหน้า (สำหรับปริ้นท์ popup) */
.rcpt * { box-sizing:border-box; }
.rcpt { font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,'TH Sarabun New',sans-serif; color:#000; }
.rcpt .head { text-align:center; margin-bottom:10px; }
.rcpt .head .title { font-size:18px; font-weight:700; }
.rcpt .meta { font-size:12px; margin:6px 0 12px; display:flex; justify-content:space-between; gap:10px; }
.rcpt .meta div { white-space:pre-wrap; }
.rcpt table { width:100%; border-collapse:collapse; }
.rcpt th, .rcpt td { border:1px solid #ccc; padding:6px 8px; font-size:12px; }
.rcpt th { background:#f2f2f2; }
.rcpt .right { text-align:right; }
.rcpt .totals { margin-top:10px; width:100%; }
.rcpt .totals .row { display:flex; justify-content:flex-end; gap:10px; font-size:13px; }
.rcpt .totals .row > div { min-width:180px; }
.rcpt .footer { margin-top:12px; font-size:11px; text-align:center; }

/* Responsive */
@media (max-width: 900px) { .track-grid{ grid-template-columns:1fr; } }
@media (max-width: 768px) {
  .qr-wrap{ width:100%; max-width:100%; }
  .barcode-box{ height:100px !important; }
  .qr-box { display: none !important; }
  .table tr:first-child { display:none; }
  .table tbody tr{ display:block; border:1px solid #e5e7eb; border-radius:.75rem; padding:.75rem; margin-bottom:1rem; }
  .table td{ display:flex; justify-content:space-between; align-items:center; padding:.5rem 0; border:none; border-bottom:1px dashed #e5e7eb; text-align:right; }
  .table tr td:last-child{ border-bottom:none; }
  .table td::before{ content:''; font-weight:600; text-align:left; margin-right:1rem; opacity:.9; }
  .table td:nth-child(1)::before{ content:"#ออเดอร์"; }
  .table td:nth-child(2)::before{ content:"วันที่"; }
  .table td:nth-child(3)::before{ content:"สถานะ"; }
  .table td:nth-child(4)::before{ content:"ยอดสุทธิ"; }
  .table td:nth-child(5)::before{ content:"เลขพัสดุ"; }
  .table td:nth-child(6)::before{ content:"สินค้า"; }
  .table td:nth-child(7)::before{ content:"จัดการ"; }
  .table td:nth-child(6){ align-items:flex-start; }

  /* ตารางรายการสินค้าในหน้า detail ให้คงรูปแบบเดิม */
  .track-grid .table tr:first-child{ display:table-row; }
  .track-grid .table tbody tr{ display:table-row; border:none; padding:0; margin:0; }
  .track-grid .table td{ display:table-cell; border-bottom:1px solid #23234a; padding:.6rem; text-align:left; }
  .track-grid .table td::before{ display:none; }
}
</style>

<h2>เช็กสถานะคำสั่งซื้อ</h2>

<?php if($mode==='none'): ?>
  <div class="card" style="max-width:520px">
    <form method="get">
      <label>เลขที่ออเดอร์หรือเบอร์โทร</label>
      <input class="input" type="text" name="q" placeholder="#1234 หรือ 0812345678" required value="<?= h($q) ?>">
      <button class="btn" type="submit">ค้นหา</button>
    </form>
    <div class="small" style="opacity:.8;margin-top:.5rem">กรอก “เลขที่ใบสั่งซื้อ” หรือ “เบอร์โทร”</div>
  </div>

<?php elseif($mode==='multi'): ?>
  <div class="card">
    <h3 style="margin-top:0">ผลการค้นหาเบอร์: <span class="badge"><?= h($q) ?></span></h3>
    <div class="table-wrap">
      <table class="table">
        <tr>
          <th>#ออเดอร์</th><th>วันที่</th><th>สถานะ</th><th>ยอดสุทธิ</th>
          <th>เลขพัสดุ</th><th>สินค้า</th><th>ดู</th>
        </tr>
        <?php foreach($orders as $row): ?>
          <?php
            $oid = (int)$row['id']; $prods=[];
            if (!empty($itemsByOrder[$oid])){
              foreach($itemsByOrder[$oid] as $it){ $prods[] = $it['name'].' × '.$it['qty']; }
            }
            $prodTxt = $prods ? implode(', ', $prods) : '-';
          ?>
          <tr>
            <td>#<?= $oid ?></td>
            <td class="small"><?= h($row['created_at'] ?? '') ?></td>
            <td><?= status_badge($row['status'] ?? '') ?></td>
            <td>฿<?= format_currency((float)($row['grand_total'] ?? 0)) ?></td>
            <td class="small"><?= h($row['tracking_no'] ?? '-') ?></td>
            <td class="small" style="min-width:280px;white-space:normal"><?= h($prodTxt) ?></td>
            <td><a class="btn outline" href="track.php?q=%23<?= $oid ?>">รายละเอียด</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

<?php elseif($mode==='single' && $order): ?>
  <?php
    // รหัสรับของ (สร้างถ้ายังไม่มี)
    $code = $order['pickup_code'] ?: strtoupper(substr(md5($order['id'].'-'.time()),0,8));
    if (empty($order['pickup_code'])) { try{ $pdo->prepare("UPDATE orders SET pickup_code=? WHERE id=?")->execute([$code,$order['id']]); }catch(Throwable $e){} }
    $qrText = "ORDER#{$order['id']}|PICKUP:{$code}";
    $qr1='https://api.qrserver.com/v1/create-qr-code/?'.http_build_query(['size'=>'360x360','data'=>$qrText,'margin'=>0,'format'=>'png']);
    $qr2='https://api.qrserver.com/v1/create-qr-code/?'.http_build_query(['size'=>'720x720','data'=>$qrText,'margin'=>0,'format'=>'png']);
    $qrFb1='https://chart.googleapis.com/chart?chs=360x360&cht=qr&chld=M|0&choe=UTF-8&chl='.rawurlencode($qrText);
    $qrFb2='https://chart.googleapis.com/chart?chs=720x720&cht=qr&chld=M|0&choe=UTF-8&chl='.rawurlencode($qrText);
    $bc1='https://bwipjs-api.metafloor.com/?'.http_build_query(['bcid'=>'code128','text'=>$code,'scale'=>2,'height'=>9,'includetext'=>'y','textsize'=>12,'paddingwidth'=>4,'paddingheight'=>2,'background'=>'ffffff']);
    $bc2='https://bwipjs-api.metafloor.com/?'.http_build_query(['bcid'=>'code128','text'=>$code,'scale'=>3,'height'=>9,'includetext'=>'y','textsize'=>12,'paddingwidth'=>4,'paddingheight'=>2,'background'=>'ffffff']);
    $bcFb1='https://barcode.tec-it.com/barcode.ashx?'.http_build_query(['data'=>$code,'code'=>'Code128','dpi'=>120]);
    $bcFb2='https://barcode.tec-it.com/barcode.ashx?'.http_build_query(['data'=>$code,'code'=>'Code128','dpi'=>160]);

    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $digits=preg_replace('/\D+/', '', (string)($order['phone'] ?? '')); $last4=substr($digits,-4) ?: '';
    $receipt_url = 'receipt.php?id='.(int)$order['id'];
    if ($last4!=='') $receipt_url.='&phone4='.$last4;
    elseif ($code!=='') $receipt_url.='&code='.urlencode($code);
    else $receipt_url.='&sig='.hash_hmac('sha256','rcpt:'.$order['id'],session_id());
  ?>
  <div class="card">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;justify-content:space-between">
      <h3 style="margin:0">ออเดอร์ #<?= (int)$order['id'] ?> — สถานะ: <?= status_badge($order['status'] ?? '') ?></h3>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn outline" target="_blank" href="<?= h($receipt_url) ?>">เปิดใบเสร็จหน้าเต็ม</a>
      </div>
    </div>

    <div class="track-grid">
      <div>
        <h4>ข้อมูลจัดส่ง</h4>
        <div class="small">
          <?= h($order['fullname'] ?? '-') ?><br>
          <?= nl2br(h($order['address'] ?? '')) ?><br>
          <?= h($order['province'] ?? '') ?> <?= h($order['zipcode'] ?? '') ?><br>
          โทร: <?= h($order['phone'] ?? '-') ?><?= !empty($order['email'])?'<br>อีเมล: '.h($order['email']):'' ?>
        </div>
        <h4 style="margin-top:.6rem">ยอดชำระ</h4>
        <div>ยอดสุทธิ: ฿<?= format_currency((float)($order['grand_total'] ?? 0)) ?></div>
        <?php if(!empty($order['tracking_no'])): ?><div>เลขพัสดุ: <strong><?= h($order['tracking_no']) ?></strong></div><?php endif; ?>
        <?php if(!empty($order['expires_at']) && ($order['status'] ?? '')==='unpaid'): ?>
          <div class="small" style="opacity:.8">กำหนดชำระก่อน: <?= h($order['expires_at']) ?></div>
        <?php endif; ?>

        <?php if (($order['status'] ?? '') === 'unpaid'): ?>
          <form method="post" action="<?= BASE_URL ?>/checkout.php" enctype="multipart/form-data" style="margin-top:.8rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_slip">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <label class="small" style="display:block;margin-bottom:.25rem;font-weight:bold;">TTB 057-2-92050-2</label>
            <label class="small" style="display:block;margin-bottom:.25rem;font-weight:bold;">ชื่อบัญชี: จัดจำหน่ายบันทึกประจำวัน ทอ.</label>
            <label class="small" style="display:block;margin-bottom:.25rem">อัปโหลดสลิปโอนเงิน (รูปภาพหรือ PDF)</label>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
              <input class="input" type="file" name="slip" accept="image/*,.pdf" required style="max-width:320px">
              <button class="btn" type="submit">อัปโหลดสลิป</button>
            </div>
            <div class="small" style="opacity:.8;margin-top:.3rem">หลังอัปโหลด ระบบจะบันทึกไฟล์และอัปเดตสถานะออเดอร์ให้</div>
          </form>
        <?php endif; ?>
      </div>

      <div>
        <h4>รหัสรับของ / บาร์โค้ด</h4>
        <div style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap">
          <div class="qr-wrap">
            <div class="qr-card"><div class="qr-box">
              <img alt="QR รับของ" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                   src="<?= h($qr1) ?>" srcset="<?= h($qr1) ?> 1x, <?= h($qr2) ?> 2x"
                   data-f1="<?= h($qrFb1) ?>" data-f2="<?= h($qrFb2) ?>"
                   onerror="
                     var f1=this.getAttribute('data-f1'), f2=this.getAttribute('data-f2');
                     if(f1){ this.removeAttribute('data-f1'); this.removeAttribute('data-f2');
                             this.src=f1; this.srcset=f1+' 1x, '+(f2||f1)+' 2x'; }
                     else { this.style.display='none'; document.getElementById('qrFallback').style.display='block'; }
                   ">
            </div></div>
            <div class="barcode-card" style="margin-top:10px">
              <div class="barcode-box">
                <img alt="Barcode (Code128)" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                     src="<?= h($bc1) ?>" srcset="<?= h($bc1) ?> 1x, <?= h($bc2) ?> 2x"
                     data-f1="<?= h($bcFb1) ?>" data-f2="<?= h($bcFb2) ?>"
                     onerror="
                       var f1=this.getAttribute('data-f1'), f2=this.getAttribute('data-f2');
                       if(f1){ this.removeAttribute('data-f1'); this.removeAttribute('data-f2');
                               this.src=f1; this.srcset=f1+' 1x, '+(f2||f1)+' 2x'; }
                       else { this.style.display='none'; document.getElementById('bcFallback').style.display='block'; }
                     ">
              </div>
              <div class="small" style="text-align:center;opacity:.75;margin-top:.35rem"><?= h($code) ?></div>
            </div>
          </div>
          <div>
            <div class="small" style="opacity:.8">แสดงบาร์โค้ดนี้หน้าเคาน์เตอร์</div>
            <div>รหัสรับของ: <strong style="font-size:1.1rem"><?= h($code) ?></strong></div>
            <div id="qrFallback" class="small" style="display:none;margin-top:.35rem;color:#f7caca">ไม่สามารถแสดง QR — ใช้รหัสด้านบนแทนได้</div>
            <div id="bcFallback" class="small" style="display:none;margin-top:.35rem;color:#f7caca">ไม่สามารถแสดงบาร์โค้ด — ใช้รหัสด้านบนแทนได้</div>
          </div>
        </div>

        <h4 style="margin-top:.8rem">รายการสินค้า</h4>
        <table class="table">
          <tr><th>สินค้า</th><th>ราคา</th><th>จำนวน</th><th>รวม</th></tr>
          <?php $calc_subtotal = 0.0; foreach($items as $it): $price=(float)$it['price']; $qty=(int)$it['qty']; $calc_subtotal += $price*$qty; ?>
            <tr>
              <td><?= h($it['name']) ?></td>
              <td>฿<?= format_currency($price) ?></td>
              <td><?= $qty ?></td>
              <td>฿<?= format_currency($price*$qty) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

  <?php
    $shop_name   = get_setting($pdo, 'shop_name',   'ร้านค้า');
    $shop_addr   = get_setting($pdo, 'shop_address','');
    $shop_phone  = get_setting($pdo, 'shop_phone',  '');
    $shop_tax_id = get_setting($pdo, 'shop_tax_id', '');
    $discount    = (float)($order['discount'] ?? 0);
    $shipping    = (float)($order['shipping'] ?? 0);
    $grand_total = (float)($order['grand_total'] ?? max(0, ($calc_subtotal ?? 0) - $discount + $shipping));
    $created_at  = $order['created_at'] ?? '';
  ?>
  <div id="printableReceipt" style="display:none">
    <div class="rcpt">
      <div class="head">
        <div class="title"><?= h($shop_name) ?></div>
        <div style="white-space:pre-wrap"><?= h($shop_addr) ?></div>
        <?php if($shop_phone): ?><div>โทร: <?= h($shop_phone) ?></div><?php endif; ?>
        <?php if($shop_tax_id): ?><div>เลขประจำตัวผู้เสียภาษี: <?= h($shop_tax_id) ?></div><?php endif; ?>
        <div style="margin-top:6px;font-weight:600">ใบเสร็จรับเงิน / Receipt</div>
      </div>

      <div class="meta">
        <div>
          เลขที่: #<?= (int)$order['id'] . "\n" ?>
          วันที่: <?= h($created_at) . "\n" ?>
          สถานะ: <?= h(status_th($order['status'] ?? '')) . "\n" ?>
          เลขพัสดุ: <?= h($order['tracking_no'] ?? '-') ?>
        </div>
        <div>
          ผู้รับ: <?= h($order['fullname'] ?? '') . "\n" ?>
          ที่อยู่: <?= h($order['address'] ?? '') . "\n" ?>
          โทร: <?= h($order['phone'] ?? '') ?><?= !empty($order['email']) ? ("\nอีเมล: ".h($order['email'])) : '' ?>
        </div>
      </div>

      <table>
        <tr><th>สินค้า</th><th class="right">ราคา/หน่วย</th><th class="right">จำนวน</th><th class="right">รวม</th></tr>
        <?php foreach ($items as $it): $p=(float)$it['price']; $q=(int)$it['qty']; ?>
          <tr><td><?= h($it['name']) ?></td><td class="right"><?= format_currency($p) ?></td><td class="right"><?= $q ?></td><td class="right"><?= format_currency($p*$q) ?></td></tr>
        <?php endforeach; ?>
      </table>

      <div class="totals">
        <div class="row"><div class="right">ยอดรวมสินค้า: ฿<?= format_currency(($calc_subtotal ?? 0)) ?></div></div>
        <div class="row"><div class="right">ส่วนลด: −฿<?= format_currency($discount) ?></div></div>
        <div class="row"><div class="right">ค่าส่ง: ฿<?= format_currency($shipping) ?></div></div>
        <div class="row" style="font-weight:700"><div class="right">ยอดสุทธิ: ฿<?= format_currency($grand_total) ?></div></div>
      </div>

      <div class="footer">ขอบคุณที่อุดหนุน 🙏 — เอกสารนี้สร้างจากระบบ <?= h(parse_url(BASE_URL, PHP_URL_HOST) ?: 'ร้านค้าออนไลน์') ?></div>
    </div>
  </div>
<?php endif; ?>

<script>
function printReceipt(){
  const src = document.getElementById('printableReceipt');
  if (!src){ alert('ไม่พบข้อมูลใบเสร็จ'); return; }
  const w = window.open('', 'PRINT', 'width=860,height=1000');
  if (!w){ alert('เบราว์เซอร์บล็อกป๊อปอัป'); return; }
  w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>ใบเสร็จ</title></head><body>');
  w.document.write(src.innerHTML);
  w.document.write('</body></html>');
  w.document.close(); w.focus(); setTimeout(()=>{ w.print(); w.close(); }, 150);
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
