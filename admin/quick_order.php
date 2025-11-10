<?php
// admin/quick_order.php — ออเดอร์ด่วน
// - คิดค่าพิมพ์ปกแบบรวมทั้งบิล (เปิดบล็อก + เกิน threshold × rate) เฉพาะยอดที่ "ติ๊กพิมพ์ปก"
// - ย้ายช่อง "พิมพ์ปก + รายละเอียด" มาไว้ใต้สินค้าต่อบรรทัด
// - เอาปุ่ม/ตรรกะ "อนุญาตขายเกินสต๊อค" ออก (ไม่ให้ขายเกินสต๊อค)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';

if (!function_exists('require_admin')) { function require_admin(){} }
if (!function_exists('csrf_check'))   { function csrf_check($t){ return true; } }
if (!function_exists('csrf_field'))   { function csrf_field(){ return '<input type="hidden" name="csrf" value="noop">'; } }
if (!function_exists('flash'))        { function flash($k,$m){} }
if (!defined('BASE_URL'))             { define('BASE_URL', ''); }

require_admin();

/* ---------- helpers ---------- */
if (!function_exists('h'))     { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, '.', ','); } }
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try { $pdo->query("SELECT `$col` FROM `$table` LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
  }
}
if (!function_exists('get_setting')) {
  function get_setting(PDO $pdo, string $key, $default=null){
    try{
      $st=$pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1');
      $st->execute([$key]);
      $v=$st->fetchColumn();
      return ($v===false)?$default:$v;
    }catch(Throwable $e){ return $default; }
  }
}
function log_admin_action(PDO $pdo, $admin_id, string $action, string $entity_type, int $entity_id, array $meta = []) {
  try {
    $sql = "INSERT INTO admin_logs (admin_id, action, entity_type, entity_id, meta, ip, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $pdo->prepare($sql)->execute([
      $admin_id, $action, $entity_type, $entity_id,
      json_encode($meta, JSON_UNESCAPED_UNICODE),
      $_SERVER['REMOTE_ADDR'] ?? null,
      $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
  } catch (Throwable $e) { /* noop */ }
}

/* ---------- statuses ---------- */
$STATUSES = ['unpaid','paid','processing','shipped','completed','cancelled'];
function is_paidish(string $s): bool { return in_array($s, ['paid','processing','shipped','completed'], true); }
$status_labels = [
  'unpaid' => 'ยังไม่ชำระเงิน',
  'paid' => 'ชำระเงินแล้ว รอการตรวจสอบ',
  'processing' => 'ตรวจสอบเรียบร้อย กำลังเตรียมสินค้า',
  'shipped' => 'กำลังจัดส่ง',
  'completed' => 'จัดส่งสำเร็จ',
  'cancelled' => 'ยกเลิก',
];
function status_th(?string $s): string { global $status_labels; $k=strtolower((string)($s??'')); return $status_labels[$k] ?? $k; }

/* ---------- โหลดสินค้า (สต๊อคร่วม + ธงอนุญาตพิมพ์ปก) ---------- */
$allProducts = []; $productMap  = [];
try{
  $cols = "id, name, price, stock, stock_group, is_stock_master, allow_cover_print AS allow_cover";
  $rows = $pdo->query("SELECT $cols FROM products WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

  $byGroup=[];
  foreach($rows as $r){
    $g = trim((string)($r['stock_group'] ?? '')) ?: '__NO_GROUP__';
    $byGroup[$g][] = $r;
  }
  foreach($byGroup as $g => $list){
    $target = null;
    foreach($list as $r) if(!empty($r['is_stock_master'])) { $target=$r; break; }
    if(!$target){
      $target=$list[0];
      foreach($list as $r) if((int)$r['stock']>(int)$target['stock']) $target=$r;
    }
    $eff_stock    = (int)$target['stock'];
    $stock_target = (int)$target['id'];

    foreach($list as $r){
      $row = [
        'id'=>(int)$r['id'], 'name'=>(string)$r['name'], 'price'=>(float)$r['price'],
        'stock'=>(int)$r['stock'], 'eff_stock'=>$eff_stock, 'stock_target'=>$stock_target,
        'stock_group'=>($g==='__NO_GROUP__'?'':$g), 'allow_cover' => !empty($r['allow_cover'])
      ];
      $allProducts[] = $row;
      $productMap[$row['id']] = $row;
    }
  }
}catch(Throwable $e){}

/* ---------- messages ---------- */
$errors   = []; $warnings = [];
$admin_id = $_SESSION['user']['id'] ?? null;

/* ===================== สร้างออเดอร์ด่วน ===================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'create_quick') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $errors[]='CSRF invalid'; }

  $fullname = trim($_POST['fullname'] ?? '');
  $phone    = trim($_POST['phone'] ?? '');
  $address  = trim($_POST['address'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $note     = trim($_POST['note'] ?? '');
  $coupon_code = trim($_POST['coupon_code'] ?? '');

  $cust_type  = $_POST['cust_type'] ?? 'store'; // store|online
  $pay_method = trim($_POST['pay_method'] ?? ''); // ต้องเลือก
  if (!in_array($pay_method, ['cash','bank_transfer'], true)) {
    $errors[] = 'กรุณาเลือกวิธีชำระเงิน';
  }

  // กำหนดสถานะฝั่งเซิร์ฟเวอร์แบบกันพลาด
  $status_in = strtolower(trim((string)($_POST['status_init'] ?? '')));
  if (!in_array($status_in, $STATUSES, true)) { $status_in = 'unpaid'; }
  $status = ($pay_method === 'cash') ? 'paid' : $status_in; // cash -> paid เสมอ
  $will_deduct = ($pay_method === 'cash') || is_paidish($status);

  // รับ arrays จากฟอร์ม
  $pids = $_POST['prod_id'] ?? []; 
  $qtys = $_POST['qty'] ?? [];
  $cover_on_arr   = $_POST['cover_on']   ?? [];    // ค่าจาก hidden ต่อแถว (0/1)
  $cover_note_arr = $_POST['cover_note'] ?? [];

  // ประมวลผลแถวสินค้า
  $lines=[];
  if (is_array($pids)) {
    $n = min(count($pids), count($qtys));
    for($i=0;$i<$n;$i++){
      $pid=(int)($pids[$i]??0); $q=(int)($qtys[$i]??0);
      $on = (int)($cover_on_arr[$i] ?? 0) === 1;
      $cn = trim((string)($cover_note_arr[$i] ?? ''));
      if($pid>0 && $q>0){ $lines[]=['id'=>$pid,'qty'=>$q,'cover_on'=>$on,'cover_note'=>$cn]; }
    }
  }

  if ($fullname === '') $errors[]='กรอกชื่อผู้รับ';
  if ($phone === '')    $errors[]='กรอกเบอร์โทร';
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[]='อีเมลไม่ถูกต้อง';
  if ($cust_type === 'online' && $address === '') $errors[]='กรอกที่อยู่จัดส่ง (ลูกค้าออนไลน์)';
  if (!$lines) $errors[]='กรุณาเลือกสินค้าอย่างน้อย 1 รายการ';

  // เตรียมสินค้า + ตรวจสต๊อค (ไม่อนุญาตขายเกิน)
  $subtotal=0.0; $total_qty=0; $itemsToInsert=[]; $stockTargets=[]; $groups=[];
  foreach($lines as $ln){
    $pid=$ln['id']; if (!isset($productMap[$pid])) continue;
    $p = $productMap[$pid];
    $itemsToInsert[] = ['data'=>$p, 'req'=>(int)$ln['qty'], 'cover_on'=>$ln['cover_on'], 'cover_note'=>$ln['cover_note']];
    $g = $p['stock_group']; if($g!=='') $groups[$g]=true;
  }
  // คงเหลือแบบกลุ่ม (กันออเดอร์ค้างชำระ)
  $reserved = []; if($groups) {
    $inG = implode(',', array_fill(0, count($groups), '?'));
    $stR = $pdo->prepare("SELECT p.stock_group,SUM(oi.qty) r
                          FROM order_items oi
                          JOIN orders o ON o.id=oi.order_id
                          JOIN products p ON p.id=oi.product_id
                          WHERE p.stock_group IN($inG) AND o.status='unpaid' AND (o.expires_at IS NULL OR o.expires_at>NOW())
                          GROUP BY p.stock_group");
    $stR->execute(array_keys($groups));
    foreach($stR as $r) $reserved[$r['stock_group']]=(int)$r['r'];
  }
  foreach($itemsToInsert as $it){
    $p = $it['data']; $req = $it['req']; $g = $p['stock_group'];
    $avail = ($g!=='') ? max(0, (int)$p['eff_stock'] - (int)($reserved[$g]??0)) : max(0, (int)$p['eff_stock']);
    if ($req > $avail) $errors[] = "สต๊อกไม่พอ (ขายได้ {$avail} ชิ้น): " . $p['name'];
  }

  // คำนวณยอด + ค่าพิมพ์ปก (คิดแบบรวมทั้งบิล เฉพาะแถวที่ติ๊กพิมพ์ปก และสินค้า allow_cover เท่านั้น)
  $cover_base=(float)get_setting($pdo,'cover_base',500);
  $cover_rate=(float)get_setting($pdo,'cover_over_rate',2);
  $cover_th  =(int)get_setting($pdo,'cover_threshold_qty',50);
  $cover_fee_total = 0.0;
  $qty_cover_total = 0;

  foreach($itemsToInsert as $idx => $item){
    $p = $item['data']; $qty = $item['req'];
    $subtotal += (float)$p['price'] * $qty; $total_qty += $qty;
    $stockTargets[$p['stock_target']] = ($stockTargets[$p['stock_target']] ?? 0) + $qty;

    if ($item['cover_on'] && $p['allow_cover']) {
      $qty_cover_total += $qty;
    }
  }
  if ($qty_cover_total > 0) {
    $cover_fee_total = $cover_base + max(0, $qty_cover_total - $cover_th) * $cover_rate;
  }

  $discount = 0.0;
  if ($coupon_code !== '') { /* โค้ดคูปอง (ถ้ามี) */ }

  $shipping = 0.0;
  if ($cust_type === 'online') {
    $shipping = (float)get_setting($pdo,'shipping_first',50) + max(0,$total_qty-1)*(float)get_setting($pdo,'shipping_next',10);
  }

  $grand_total = max(0.0, $subtotal - $discount + $shipping + $cover_fee_total);

  if (!$errors){
    try{
      $pdo->beginTransaction();

      $expires = ($status==='unpaid') ? date('Y-m-d H:i:s',time()+(int)get_setting($pdo,'order_ttl_hours',48)*3600) : null;
      $pickup_code = ($cust_type==='store') ? strtoupper(substr(bin2hex(random_bytes(4)),0,8)) : null;

      // ตรวจ schema สำหรับ order_items
      $has_fee    = has_column($pdo,'order_items','cover_fee');
      $has_print  = has_column($pdo,'order_items','cover_print');
      $has_inote  = has_column($pdo,'order_items','item_note');
      $has_iname  = has_column($pdo,'order_items','item_name');
      $has_onote  = has_column($pdo,'order_items','note'); // legacy

      // ถ้าไม่มีคอลัมน์สำหรับเก็บ note รายการเลย ให้ fallback ไปไว้ orders.note
      $fallback_cover_notes = [];

      // INSERT orders
      $sql = "INSERT INTO orders (user_id,created_by_admin,fullname,phone,email,address,note,status,
                                  subtotal,discount,shipping,cover_fee_total,grand_total,
                                  coupon_code,expires_at,pickup_code,delivery_option,created_at,updated_at)
              VALUES (?,?,?,?,?,?,?,?, ?,?,?,?, ?, ?,?,?,?, NOW(),NOW())";
      $pdo->prepare($sql)->execute([
        null, $admin_id, $fullname, $phone, $email, $address, $note, $status,
        $subtotal, $discount, $shipping, $cover_fee_total, $grand_total,
        $coupon_code?:null, $expires, $pickup_code, ($cust_type==='store'?'pickup':'home')
      ]);
      $oid = (int)$pdo->lastInsertId();

      // แจก cover_fee_total ใส่แถวแรกที่ติ๊กพิมพ์ปกเท่านั้น (เพื่อให้ใบเสร็จขึ้น “ค่าพิมพ์ปก” เพียงบรรทัดเดียว)
      $cover_fee_to_assign = $cover_fee_total;

      // INSERT items
      foreach($itemsToInsert as $item){
        $p = $item['data'];
        $base_name = $p['name'];
        $note_txt  = trim((string)($item['cover_note'] ?? '')); // รายละเอียดพิมพ์ปก
        $is_cover_on = ($item['cover_on'] && $p['allow_cover']) ? 1 : 0;

        // เฉพาะแถวแรกที่มี $is_cover_on ให้ใส่ยอด cover_fee_total ลง cover_fee
        $line_fee = 0.0;
        if ($is_cover_on && $cover_fee_to_assign > 0) {
          $line_fee = $cover_fee_to_assign;
          $cover_fee_to_assign = 0.0;
        }

        $cols = ['order_id','product_id','qty','unit_price'];
        $vals = ['?','?','?','?'];
        $prm  = [$oid,$p['id'],$item['req'],$p['price']];

        if ($has_iname) { $cols[]='item_name'; $vals[]='?'; $prm[]=$base_name; }
        if ($has_print){ $cols[]='cover_print'; $vals[]='?'; $prm[] = $is_cover_on; }
        if ($has_fee)  { $cols[]='cover_fee';   $vals[]='?'; $prm[] = $line_fee; }
                // ----- NOTE / META (ปรับใหม่) -----
        // 1) ถ้ามี item_note ก็เก็บเฉพาะข้อความ (ถ้ามี)
        if ($has_inote && $note_txt !== '') {
          $cols[]='item_note'; $vals[]='?'; $prm[] = $note_txt;
        }

        // 2) ฝั่ง legacy: เขียนลง order_items.note เป็น JSON "COVER:" เสมอ
        //    เพื่อให้ receipt.php อ่าน cover_fee ได้ แม้ไม่มีคอลัมน์ cover_fee
        if ($has_onote && ($line_fee > 0 || $note_txt !== '')) {
          $cols[]='note'; $vals[]='?';
          $prm[] = 'COVER:' . json_encode(
            ['cover_note'=>$note_txt, 'cover_fee'=>$line_fee],
            JSON_UNESCAPED_UNICODE
          );
        }

        // 3) ถ้าไม่มีทั้ง item_note และ note แต่มีข้อความ -> เก็บไว้ไปพ่วง orders.note ทีหลัง
        if (!$has_inote && !$has_onote && $note_txt !== '') {
          $fallback_cover_notes[] = $note_txt;
        }

        $sql_items = "INSERT INTO order_items (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
        $pdo->prepare($sql_items)->execute($prm);

        // เก็บ target สำหรับตัดสต๊อค
        $stockTargets[$p['stock_target']] = ($stockTargets[$p['stock_target']] ?? 0) + 0; // already added above; keep key presence
      }

      // ถ้าจำเป็น ผนวกข้อความรายละเอียดพิมพ์ปกลง orders.note
      if ($fallback_cover_notes) {
        $append = 'รายละเอียด: '.implode(' | ', $fallback_cover_notes);
        $note_final = trim($note!=='' ? ($note."\n".$append) : $append);
        $pdo->prepare("UPDATE orders SET note=?, updated_at=NOW() WHERE id=?")->execute([$note_final, $oid]);
      }

      if ($will_deduct) {
        $upd = $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?");
        foreach ($stockTargets as $tid => $q) {
          // รวม qty target อัปเดตครั้งเดียว
        }
        // คำนวณยอดที่จะตัดแบบรวบ
        $toDeduct = [];
        foreach($itemsToInsert as $it){
          $t = $it['data']['stock_target'];
          $toDeduct[$t] = ($toDeduct[$t] ?? 0) + $it['req'];
        }
        foreach($toDeduct as $tid=>$q){ $upd->execute([$q, $tid]); }
      }

      $pdo->prepare('INSERT INTO payments (order_id, method, is_verified)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE method=VALUES(method), is_verified=VALUES(is_verified)')
          ->execute([$oid, $pay_method, $will_deduct ? 1 : 0]);

      $pdo->commit();
      log_admin_action($pdo, $admin_id, 'orders.quick_create', 'order', $oid, ['status'=>$status,'total'=>$grand_total,'by'=>$admin_id]);
      flash('success','สร้างออเดอร์ด่วน #'.$oid.' สำเร็จ (สถานะ: '.status_th($status).')');
      header('Location: '.BASE_URL.'/admin/orders.php'); exit;
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $errors[]='สร้างออเดอร์ไม่สำเร็จ: '.$e->getMessage();
    }
  }
}

/* ---------- VIEW ---------- */
include __DIR__ . '/../partials/header.php';
?>
<style>
body .container, .container { max-width:100% !important; width:100% !important; padding:0 1rem; }
.card{ border:1px solid #23234a; border-radius:1rem; padding:1rem; margin:.6rem 0; background:#fff; }
.row{ display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
@media (max-width:900px){ .row{ grid-template-columns:1fr; } }
.input{ width:100%; border:1px solid #c9cfdd; border-radius:.75rem; padding:.5rem .75rem; background:#fff; color:#0e1530; }
select.input{ height:44px; }
.btn{ display:inline-flex; align-items:center; gap:.4rem; padding:.5rem .9rem; border:1px solid #23234a; border-radius:.75rem; background:#fff; color:#0e1530; cursor:pointer; }
.btn.outline{ background:transparent; }
.table{ width:100%; border-collapse:separate; border-spacing:0; }
.table th,.table td{ padding:.5rem; border-bottom:1px solid #e5e7eb; vertical-align:top; }
.small{ font-size:.9rem; opacity:.85; }
.muted{ opacity:.75; }
.summary{ display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:.6rem; margin-top:1rem; }
.summary .box{ border:1px solid #23234a; border-radius:.75rem; padding:.75rem; }
.cover-ui{ margin-top:.45rem; padding:.5rem; border:1px dashed #cbd5e1; border-radius:.5rem; background:#f8fafc; }
.cover-ui .row-inline{ display:flex; align-items:center; gap:.5rem; margin-bottom:.4rem; }
.cover-ui textarea{ width:100%; }
</style>

<h2>สั่งออเดอร์ด่วน (สำหรับแอดมิน)</h2>

<?php if ($errors): ?> <div class="alert error" style="border:1px solid #7f1d1d;background:#fff6f6;border-radius:.75rem;padding:.7rem .9rem;margin:.6rem 0;"><?php foreach($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?></div> <?php endif; ?>

<form method="post" action="quick_order.php" id="qcForm" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="create_quick">

  <div class="row">
    <div>
      <label>ประเภทลูกค้า</label>
      <select class="input" name="cust_type" id="custType">
        <option value="store">หน้าร้าน (รับเอง)</option>
        <option value="online">ออนไลน์ (จัดส่ง)</option>
      </select>
      <div class="small muted">หน้าร้านจะถูกบันทึกเป็น “รับเองที่ร้าน” และสร้างรหัสรับของอัตโนมัติ</div>
    </div>
    <div>
      <label>วิธีชำระเงิน *</label>
      <select class="input" name="pay_method" id="payMethod" required>
        <option value="">— เลือกวิธีชำระเงิน —</option>
        <option value="cash">เงินสด</option>
        <option value="bank_transfer">โอนเงิน</option>
      </select>
      <div class="small muted">เลือก “เงินสด” ระบบจะตั้งสถานะเป็น “ชำระเงินแล้ว” อัตโนมัติ</div>
    </div>
  </div>

  <div class="row" style="margin-top:.5rem">
    <div><label>ชื่อ-นามสกุล *</label><input class="input" name="fullname" required placeholder="เช่น น.ต.สมชาย ใจดี"></div>
    <div><label>เบอร์โทร *</label><input class="input" name="phone" required placeholder="เช่น 0812345678"></div>
  </div>

  <div id="addrWrap" class="row" style="margin-top:.5rem; display:none">
    <div style="grid-column:1 / -1">
      <label>ที่อยู่จัดส่ง (เฉพาะลูกค้าออนไลน์)</label>
      <textarea class="input" name="address" rows="2" placeholder="บ้านเลขที่, ถนน, แขวง/ตำบล, เขต/อำเภอ, จังหวัด, รหัสไปรษณีย์"></textarea>
    </div>
  </div>

  <div class="row" style="margin-top:.5rem">
    <div><label>อีเมล (ถ้ามี)</label><input class="input" type="email" name="email" placeholder="you@example.com"></div>
    <div><label>โค้ดส่วนลด (ถ้ามี)</label><input class="input" name="coupon_code" placeholder="กรอกโค้ดส่วนลด"></div>
  </div>

  <div style="margin-top:1rem">
    <strong>สินค้าในออเดอร์</strong>
    <table class="table" id="itemsTable">
      <thead>
        <tr><th style="width:45%">สินค้า</th><th style="width:15%">ราคา</th><th style="width:15%">คงเหลือ</th><th style="width:15%">จำนวน</th><th style="width:10%">ลบ</th></tr>
      </thead>
      <tbody></tbody>
      <tfoot><tr><td colspan="5"><button type="button" class="btn outline" id="btnAdd">+ เพิ่มแถว</button></td></tr></tfoot>
    </table>
    <template id="tplSelect">
      <select class="input selProd" name="prod_id[]" required>
        <option value="">— เลือกสินค้า —</option>
        <?php foreach($allProducts as $pp): ?>
          <option value="<?= (int)$pp['id'] ?>"
                  data-price="<?= h($pp['price']) ?>"
                  data-eff="<?= (int)$pp['eff_stock'] ?>"
                  data-target="<?= (int)$pp['stock_target'] ?>"
                  data-allow-cover="<?= $pp['allow_cover'] ? '1' : '0' ?>">
            <?= h($pp['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </template>
  </div>

  <div class="row" style="margin-top:.5rem">
    <div style="grid-column:1 / -1"><label>หมายเหตุ (ถ้ามี)</label><textarea class="input" name="note" rows="2" placeholder="บันทึกเพิ่มเติม / เงื่อนไข ฯลฯ"></textarea></div>
  </div>

  <div class="summary">
    <div class="box"><div class="small muted">ยอดสินค้า</div><div><b>฿ <span id="sumSub">0.00</span></b></div></div>
    <div class="box"><div class="small muted">ค่าส่ง</div><div><b>฿ <span id="sumShip">0.00</span></b> <span class="small muted" id="shipRule"></span></div></div>
    <div class="box">
      <div class="small muted">ค่าพิมพ์ปก</div>
      <div><b>฿ <span id="sumCover">0.00</span></b></div>
      <div class="small muted">กติกา: เปิดบล็อก + เกิน (มากกว่า <span id="ruleTh">0</span> เล่ม) × อัตรา</div>
    </div>
    <div class="box"><div class="small muted">ยอดสุทธิ</div><div style="font-size:1.2rem"><b>฿ <span id="sumGrand">0.00</span></b></div></div>
  </div>

  <div style="margin-top:1rem">
    <button class="btn" type="submit">สร้างออเดอร์</button>
    <a class="btn outline" href="orders.php">กลับหน้ารายการ</a>
  </div>
</form>

<script>
(function(){
  const custType     = document.getElementById('custType');
  const addrWrap     = document.getElementById('addrWrap');
  const tblBody      = document.querySelector('#itemsTable tbody');
  const btnAdd       = document.getElementById('btnAdd');
  const tplSel       = document.getElementById('tplSelect');
  const sumSub   = document.getElementById('sumSub');
  const sumShip  = document.getElementById('sumShip');
  const sumCover = document.getElementById('sumCover');
  const sumGrand = document.getElementById('sumGrand');
  const shipRule = document.getElementById('shipRule');
  const ruleTh   = document.getElementById('ruleTh');
  const payMethod = document.getElementById('payMethod');
  const statusInit= document.getElementById('statusInit');
  const qcForm    = document.getElementById('qcForm');

  const SHIP_FIRST = <?= json_encode((float)get_setting($pdo,'shipping_first',50)) ?>;
  const SHIP_NEXT  = <?= json_encode((float)get_setting($pdo,'shipping_next',10)) ?>;
  const COV_BASE   = <?= json_encode((float)get_setting($pdo,'cover_base',500)) ?>;
  const COV_RATE   = <?= json_encode((float)get_setting($pdo,'cover_over_rate',2)) ?>;
  const COV_TH     = <?= json_encode((int)get_setting($pdo,'cover_threshold_qty',50)) ?>;

  ruleTh.textContent = COV_TH.toString();

  const fmt = (n)=>Number(n||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});

  // ตั้งสถานะตามวิธีจ่าย: cash -> paid, โอน -> unpaid
  payMethod.addEventListener('change', () => {
    if (payMethod.value === 'cash') statusInit.value = 'paid';
    else if (payMethod.value === 'bank_transfer') statusInit.value = 'unpaid';
  });

  // กันลืมเลือกวิธีจ่าย
  qcForm.addEventListener('submit', (e)=>{
    if (!payMethod.value) {
      e.preventDefault();
      alert('กรุณาเลือกวิธีชำระเงิน');
      payMethod.focus();
    }
  });

  function refreshAddr(){
    const online = (custType.value==='online');
    addrWrap.style.display = online ? 'grid' : 'none';
    const ta = addrWrap.querySelector('textarea[name="address"]');
    if (ta) ta.required = online;
    shipRule.textContent = online ? `(ชิ้นแรก ${SHIP_FIRST}, ชิ้นถัดไป ${SHIP_NEXT})` : '(รับเอง ไม่มีค่าส่ง)';
    computeTotals();
  }

  function addRow(){
    const tr = document.createElement('tr');
    tr.dataset.allowCover = '0';

    const tdSel = document.createElement('td');
    const selFrag = tplSel.content.cloneNode(true);
    const sel = selFrag.querySelector('select');
    tdSel.appendChild(sel);

    // cover UI (ซ่อน/แสดงตามสินค้า)
    const coverWrap = document.createElement('div');
    coverWrap.className = 'cover-ui'; coverWrap.style.display = 'none';

    const rowInline = document.createElement('div');
    rowInline.className = 'row-inline';
    const hiddenOn = document.createElement('input'); // ส่งค่า 0/1 แน่ๆ ต่อแถว
    hiddenOn.type = 'hidden'; hiddenOn.name = 'cover_on[]'; hiddenOn.value = '0';
    const cb = document.createElement('input');
    cb.type = 'checkbox'; cb.id = 'cb_'+Math.random().toString(36).slice(2);
    const lb = document.createElement('label');
    lb.setAttribute('for', cb.id);
    lb.textContent = 'พิมพ์ปก';

    rowInline.appendChild(cb);
    rowInline.appendChild(lb);
    coverWrap.appendChild(rowInline);

    const ta = document.createElement('textarea');
    ta.rows = 2; ta.className='input'; ta.placeholder='กรอกรายละเอียดที่จะพิมพ์บนปก'; ta.name='cover_note[]';
    coverWrap.appendChild(ta);

    tdSel.appendChild(hiddenOn);
    tdSel.appendChild(coverWrap);

    const tdP = document.createElement('td'); const spP=document.createElement('span'); spP.textContent='—'; tdP.appendChild(spP);
    const tdS = document.createElement('td'); const spS=document.createElement('span'); spS.textContent='—'; tdS.appendChild(spS);

    const tdQ = document.createElement('td');
    const q = document.createElement('input'); q.type='number'; q.min='1'; q.step='1'; q.name='qty[]'; q.className='input'; q.placeholder='จำนวน'; q.required = true; tdQ.appendChild(q);

    const tdDel = document.createElement('td'); const btn=document.createElement('button'); btn.type='button'; btn.className='btn outline'; btn.textContent='ลบ'; tdDel.appendChild(btn);

    tr.appendChild(tdSel); tr.appendChild(tdP); tr.appendChild(tdS); tr.appendChild(tdQ); tr.appendChild(tdDel);
    tblBody.appendChild(tr);

    function onSel(){
      const opt = sel.options[sel.selectedIndex];
      const price = opt ? parseFloat(opt.getAttribute('data-price')||'0') : 0;
      const eff   = opt ? parseInt(opt.getAttribute('data-eff')||'0',10) : 0;
      const allow = opt ? (opt.getAttribute('data-allow-cover')==='1') : false;
      tr.dataset.allowCover = allow ? '1' : '0';
      spP.textContent = price ? '฿'+fmt(price) : '—';
      spS.textContent = Number.isFinite(eff) ? eff : '—';
      if (!q.value || parseInt(q.value,10)<=0) q.value = '1';
      if (eff>0) q.max = String(eff); else q.removeAttribute('max');
      if (eff > 0 && parseInt(q.value, 10) > eff) q.value = String(eff);

      // toggle cover-ui
      if (allow) {
        coverWrap.style.display = '';
      } else {
        coverWrap.style.display = 'none';
        cb.checked = false;
        hiddenOn.value = '0';
        ta.value = '';
      }
      computeTotals();
    }
    function onChangeQty(){
      const max = parseInt(q.max||'0',10);
      let v = parseInt(q.value||'0',10);
      if (max>0 && v>max) q.value = String(max);
      computeTotals();
    }
    function onCoverToggle(){
      hiddenOn.value = cb.checked ? '1' : '0';
      computeTotals();
    }
    sel.addEventListener('change', onSel);
    q.addEventListener('input', onChangeQty);
    cb.addEventListener('change', onCoverToggle);
    btn.addEventListener('click', ()=>{ tr.remove(); computeTotals(); });
    onSel();
  }

  function computeTotals(){
    let subtotal=0, qty=0, coverQtyTotal=0;

    tblBody.querySelectorAll('tr').forEach(tr=>{
      const sel=tr.querySelector('select.selProd');
      const qEl=tr.querySelector('input[name="qty[]"]');
      const q  = parseInt(qEl?.value||'0',10);
      const price = parseFloat(sel?.options[sel.selectedIndex]?.getAttribute('data-price') || '0');
      if(sel && sel.value && Number.isFinite(price) && q>0){
        subtotal += price*q; qty += q;
        const allow = (tr.dataset.allowCover === '1');
        const on = tr.querySelector('input[type="hidden"][name="cover_on[]"]')?.value === '1';
        if(allow && on){ coverQtyTotal += q; }
      }
    });

    const online = (custType.value==='online');
    const shipping = online ? (qty>0 ? (SHIP_FIRST + Math.max(0,qty-1)*SHIP_NEXT) : 0) : 0;

    const cover_fee = (coverQtyTotal>0) ? (COV_BASE + Math.max(0, coverQtyTotal - COV_TH)*COV_RATE) : 0;

    sumSub.textContent   = fmt(subtotal);
    sumShip.textContent  = fmt(shipping);
    sumCover.textContent = fmt(cover_fee);
    sumGrand.textContent = fmt(Math.max(0, subtotal + shipping + cover_fee));
  }

  btnAdd?.addEventListener('click', addRow);
  custType.addEventListener('change', refreshAddr);

  // init
  addRow();
  refreshAddr();
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
