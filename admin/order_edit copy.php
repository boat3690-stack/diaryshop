<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/notify.php';

require_admin();
if (!has_perm('orders.update')) { flash('error','ไม่มีสิทธิ์แก้ไขออเดอร์'); redirect('orders.php'); }

/* ---------- helpers (ไม่พึ่ง get_setting เดิม เพื่อกัน cache/ซ้ำ) ---------- */
function setting(PDO $pdo, string $key, $default=null){
  try{
    $st = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v===false)?$default:$v;
  }catch(Throwable $e){
    return $default;
  }
}

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) { flash('error','ไม่พบเลขที่ออเดอร์'); redirect('orders.php'); }

/* ===== โหลดหัวบิล ===== */
$st = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) { flash('error','ออเดอร์ไม่พบ'); redirect('orders.php'); }

/* ===== ตรวจว่าออเดอร์นี้ "มารับเอง" หรือไม่ (ตามสคีมาจริง: delivery_option) ===== */
$isPickup = (isset($order['delivery_option']) && $order['delivery_option'] === 'pickup');

/* ===== โหลดรายการสินค้าในออเดอร์ (เดิม) ===== */
$items = [];
$oldQty  = [];   // [pid => qty]
$oldPrice= [];   // [pid => unit_price]
$it = $pdo->prepare("SELECT oi.product_id pid, oi.qty, oi.unit_price, p.name, p.price AS cur_price, p.stock
                     FROM order_items oi
                     JOIN products p ON p.id=oi.product_id
                     WHERE oi.order_id=?
                     ORDER BY oi.id");
$it->execute([$orderId]);
while($r = $it->fetch(PDO::FETCH_ASSOC)){
  $items[] = $r;
  $oldQty[(int)$r['pid']]   = (int)$r['qty'];
  $oldPrice[(int)$r['pid']] = (float)$r['unit_price'];
}

/* ===== สินค้าทั้งหมดสำหรับ dropdown พร้อม available (ตัดจองออเดอร์อื่น) ===== */
$prods = $pdo->query("
  SELECT p.id, p.name, p.price, p.stock,
         COALESCE((
           SELECT SUM(oi.qty) FROM order_items oi
           JOIN orders o ON o.id=oi.order_id
           WHERE oi.product_id=p.id
             AND o.status IN ('unpaid','paid','processing')
             AND o.id <> {$orderId}
         ),0) AS reserved_other
  FROM products p
  WHERE p.is_active=1
  ORDER BY p.name
")->fetchAll(PDO::FETCH_ASSOC);

/* map available */
$availMap = []; // [pid => available_now]
foreach($prods as $pr){
  $availMap[(int)$pr['id']] = max(0, (int)$pr['stock'] - (int)$pr['reserved_other']);
}

/* ===== ค่าตั้งต้นจาก Settings (อ่านตรงจาก DB) ===== */
$shipFirst = (float)(setting($pdo,'shipping_first_item', 50));
$shipNext  = (float)(setting($pdo,'shipping_next_item', 10)); // เล่มถัดไป 10 บาท

/* ======================= POST: บันทึกรายการ ======================= */
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='save_items'){
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); redirect("order_edit.php?id=$orderId"); }

  // input arrays
  $pids = $_POST['prod_id'] ?? [];
  $qtys = $_POST['qty']     ?? [];

  // ส่วนลด
  $discount = (float)($_POST['discount'] ?? ($order['discount'] ?? 0));
  if ($discount < 0) $discount = 0;

  // ค่าส่ง: checkbox ถ้าไม่ได้ติ๊ก key จะไม่มา -> 0
  $auto_ship = isset($_POST['auto_ship']) ? 1 : 0;
  $shipping_manual = (float)($_POST['shipping'] ?? ($order['shipping'] ?? 0));
  if ($shipping_manual < 0) $shipping_manual = 0;

  // ตัวเลือกพิมพ์ปก
  $cover_print = ($_POST['cover_print'] ?? 'no') === 'yes';

  // วันหมดอายุบิล
  $expires_post = trim((string)($_POST['expires_at'] ?? ''));
  $expires_sql  = null;
  if ($expires_post !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $expires_post)) {
      $expires_sql = str_replace('T',' ',$expires_post).':00';
    } else {
      $ts = strtotime($expires_post);
      if ($ts !== false) $expires_sql = date('Y-m-d H:i:s', $ts);
    }
  }

  // รวมรายการตาม pid (กัน duplicate แถว)
  $want = []; // pid => qty
  if (is_array($pids) && is_array($qtys)){
    $n = min(count($pids), count($qtys));
    for($i=0;$i<$n;$i++){
      $pid = (int)$pids[$i];
      $q   = (int)$qtys[$i];
      if ($pid>0 && $q>0){
        if (!isset($want[$pid])) $want[$pid]=0;
        $want[$pid] += $q;
      }
    }
  }
  if (!$want){ flash('error','กรุณาเพิ่มสินค้าอย่างน้อย 1 รายการ'); redirect("order_edit.php?id=$orderId"); }

  // ดึงสินค้าเฉพาะที่เกี่ยวข้อง
  $ids = array_keys($want);
  $in  = implode(',', array_fill(0,count($ids),'?'));

  $stP = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE id IN ($in)");
  $stP->execute($ids);
  $pMap = [];
  while($r=$stP->fetch(PDO::FETCH_ASSOC)){
    $pMap[(int)$r['id']] = ['name'=>$r['name'],'price'=>(float)$r['price'],'stock'=>(int)$r['stock']];
  }
  // validate all exist
  $missing = array_diff($ids, array_keys($pMap));
  if ($missing){ flash('error','มีสินค้าที่ไม่พบในระบบ'); redirect("order_edit.php?id=$orderId"); }

  // reserved_by_others สำหรับเฉพาะ pid เหล่านี้
  $stR = $pdo->prepare("
    SELECT oi.product_id pid, COALESCE(SUM(oi.qty),0) reserved
    FROM order_items oi
    JOIN orders o ON o.id=oi.order_id
    WHERE oi.product_id IN ($in)
      AND o.status IN ('unpaid','paid','processing')
      AND o.id <> ?
    GROUP BY oi.product_id
  ");
  $params = $ids; $params[] = $orderId;
  $stR->execute($params);
  $reservedOther = []; // pid => reserved
  foreach($stR as $r){ $reservedOther[(int)$r['pid']] = (int)$r['reserved']; }

  // ตรวจ-คำนวณจริง
  $rows = []; // to insert: [pid, qty, unit_price]
  $errors = []; $notes = [];

  $subtotal = 0.0;
  $total_qty = 0;

  foreach($want as $pid=>$qNew){
    $p = $pMap[$pid];
    $oldQ = (int)($oldQty[$pid] ?? 0);
    $reserved = (int)($reservedOther[$pid] ?? 0);
    $maxAllow = max(0, $p['stock'] - $reserved + $oldQ); // อนุญาตคงเดิม + เพิ่มได้เท่าของที่เหลือจริง

    $finalQ = min($qNew, $maxAllow);
    if ($finalQ <= 0){
      $notes[] = 'ตัดรายการออก: '.$p['name'].' (สต๊อคไม่พอ)';
      continue;
    }
    if ($finalQ < $qNew){
      $errors[] = 'ปรับจำนวนของ '.$p['name'].' จาก '.$qNew.' → '.$finalQ.' (จำกัดตามสต๊อคที่เหลือ)';
    }

    // ราคา: ถ้ามีอยู่เดิม ใช้ราคาเดิม, ถ้าเป็นรายการใหม่ ใช้ราคาปัจจุบัน
    $uprice = array_key_exists($pid, $oldPrice) ? (float)$oldPrice[$pid] : (float)$p['price'];

    $rows[] = ['pid'=>$pid,'qty'=>$finalQ,'price'=>$uprice];
    $subtotal += $finalQ * $uprice;
    $total_qty += $finalQ;
  }

  if (!$rows){
    flash('error','ไม่มีรายการใดสามารถบันทึกได้ (สต๊อคไม่พอ)'); redirect("order_edit.php?id=$orderId");
  }

  // ===== คำนวณค่าส่ง (อัตโนมัติถ้าเลือก) =====
  $shipping_calc = ($total_qty > 0) ? ($shipFirst + max(0, $total_qty - 1) * $shipNext) : 0.0;
  $shipping = $isPickup ? 0.0 : ($auto_ship ? $shipping_calc : $shipping_manual);

  // ===== คำนวณค่าพิมพ์ปก =====
  $cover_fee = 0.0;
  if ($cover_print) {
    $cover_fee = ($total_qty > 0) ? (500 + max(0, $total_qty - 50) * 2) : 0.0;
  }

  // ===== ยอดสุทธิ =====
  $grand = max(0.0, $subtotal - $discount + $shipping + $cover_fee);

  // ===== อัปเดต note เกี่ยวกับพิมพ์ปก =====
  $note_old = (string)($order['note'] ?? '');
  $note_new = preg_replace('/^พิมพ์ปก:.*$/mu', '', $note_old);
  $note_new = trim(preg_replace("/\n{2,}/", "\n", (string)$note_new));
  if ($cover_print) {
    $over = max(0, $total_qty - 50);
    $line = 'พิมพ์ปก: เปิดบล็อก 500 + เกิน '.$over.' เล่ม × 2 = '.number_format($cover_fee,2).' บาท';
    $note_new = ($note_new !== '') ? ($note_new."\n".$line) : $line;
  }

  try{
    $pdo->beginTransaction();

    // ลบของเก่าทั้งหมด แล้วใส่ใหม่
    $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$orderId]);

    // ใส่ใหม่ (ให้เรียบง่ายพึ่ง default ของ price/line_total)
    try{
      $ins = $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES (?,?,?,?)");
      foreach($rows as $r){ $ins->execute([$orderId,$r['pid'],$r['qty'],$r['price']]); }
    }catch(Exception $e){
      // สคีมาบางที่ต้องการ unit_cost
      $ins2 = $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, unit_price, unit_cost) VALUES (?,?,?,?,?)");
      foreach($rows as $r){ $ins2->execute([$orderId,$r['pid'],$r['qty'],$r['price'],0.0]); }
    }

    // อัปเดตยอดหลัก + note + วันหมดอายุ (ถ้ามี)
    $sql = "UPDATE orders SET grand_total=?, shipping=?, discount=?, subtotal=?, note=?, updated_at=NOW()";
    $params = [$grand, $shipping, $discount, $subtotal, $note_new];
    if ($expires_sql !== null) { $sql .= ", expires_at=?"; $params[] = $expires_sql; }
    $sql .= " WHERE id=?";
    $params[] = $orderId;
    $pdo->prepare($sql)->execute($params);

    // อัปเดตค่าพิมพ์ปก (คอลัมน์จริงตามสคีมา: cover_fee_total)
    try { $pdo->prepare("UPDATE orders SET cover_fee_total=? WHERE id=?")->execute([$cover_fee,$orderId]); } catch(Exception $e){}

    // log
    if (function_exists('admin_log')){
      admin_log($pdo,'orders.edit_items','order',$orderId,[
        'subtotal'=>$subtotal,'shipping'=>$shipping,'discount'=>$discount,'cover_fee'=>$cover_fee,
        'grand_total'=>$grand,'qty_total'=>$total_qty,'auto_ship'=>$auto_ship,'is_pickup'=>$isPickup
      ]);
    }

    $pdo->commit();

    $msg = 'บันทึกสำเร็จ';
    if ($errors) $msg .= ' • '.implode(' / ', $errors);
    if ($notes)  $msg .= ' • '.implode(' / ', $notes);
    flash('success', $msg);
  }catch(Exception $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    flash('error','บันทึกล้มเหลว: '.$e->getMessage());
  }

  redirect("order_edit.php?id=$orderId");
  exit;
}

/* === ค่าพรีวิวเริ่มต้น === */
$subtotal0 = 0.0; $qty0 = 0;
foreach($items as $it){ $subtotal0 += $it['qty'] * $it['unit_price']; $qty0 += (int)$it['qty']; }
$shipping0_calc = ($qty0>0? ($shipFirst + max(0,$qty0-1)*$shipNext) : 0.0);
$shipping0 = $isPickup ? 0.0 : (float)($order['shipping'] ?? $shipping0_calc);
$discount0 = (float)($order['discount'] ?? 0);
$cover0    = 0.0;
// เดา cover จาก note (กรณีบิลเดิม)
if (preg_match('/^พิมพ์ปก:/mu', (string)($order['note'] ?? ''))) {
  $cover0 = ($qty0>0 ? (500 + max(0,$qty0-50)*2) : 0.0);
}
try { if (isset($order['cover_fee_total'])) $cover0 = (float)$order['cover_fee_total']; } catch(Throwable $e){}
$grand0    = max(0.0, $subtotal0 - $discount0 + $shipping0 + $cover0);

$expires0  = !empty($order['expires_at']) ? date('Y-m-d\TH:i', strtotime($order['expires_at'])) : '';
$cover_checked = $cover0 > 0;

// ติ๊ก auto เฉพาะกรณี: ไม่ใช่ pickup และค่าส่งเดิมเท่ากับที่คำนวณได้จริง
$auto_ship_checked = (!$isPickup) && (abs($shipping0 - $shipping0_calc) < 0.01);

/* ===== UI ===== */
include __DIR__ . '/../partials/header.php';
?>
<style>
:root{
  --page-bg:#ffffff; --ink:#111111; --muted:#555555; --panel:#ffffff; --panel-2:#f7f8fa;
  --stroke:#e5e7eb; --accent:#2563eb; --accent-dark:#1e40af; --danger:#dc2626; --success:#16a34a;
}
body .container, .container { max-width:100% !important; width:100% !important; }
body{ background:var(--page-bg); color:var(--ink); }
.edit-wrap{ margin-left:calc(50% - 50vw); margin-right:calc(50% - 50vw); padding:1rem 1.25rem; background:var(--page-bg); }
.edit-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.badge{ display:inline-block; padding:.2rem .6rem; border-radius:.5rem; background:#eef2ff; color:#1e3a8a; border:1px solid #c7d2fe; }
.card{ border:1px solid var(--stroke); border-radius:12px; padding:1rem; background:var(--panel); box-shadow:0 1px 2px rgba(0,0,0,.04); }
.grid-2{display:grid;grid-template-columns:2fr 1fr;gap:1rem}
@media (max-width:1000px){ .grid-2{grid-template-columns:1fr} }
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:.65rem;border-bottom:1px solid var(--stroke);text-align:left}
.table thead th{ background:var(--panel-2); border-bottom:1px solid var(--stroke); color:var(--ink); font-weight:700; }
.table tfoot td{font-weight:700;border-top:1px solid var(--stroke)}
#lineTable tbody tr:hover{ background:#fafafa; }
.input{ width:100%; background:#ffffff; border:1px solid #cbd5e1; color:var(--ink); border-radius:10px; padding:.55rem .7rem; min-height:42px; transition:border-color .15s, box-shadow .15s, background .15s; }
.input:focus{ outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,.2); background:#ffffff; }
.btn{ background:var(--accent); border:1px solid var(--accent); color:#fff; font-weight:700; border-radius:10px; padding:.6rem .9rem; min-height:42px; cursor:pointer; transition:background .12s, border-color .12s, transform .06s; }
.btn:hover{ background:var(--accent-dark); border-color:var(--accent-dark); }
.btn:active{ transform:translateY(1px); }
.btn[disabled]{ opacity:.6; cursor:not-allowed; }
.btn.outline{ background:#fff; color:var(--ink); border:1px solid #cbd5e1; }
.btn.outline:hover{ border-color:var(--accent); color:var(--accent-dark); }
.small{ font-size:.92rem; color:var(--muted); }
hr{ border:none; border-top:1px solid var(--stroke); margin:.7rem 0; }
#grandVal{ color:#0f172a; font-weight:800; }
.avail{ display:inline-block; min-width:2.5ch; text-align:right; color:#111; }
.card input[type="number"]{ text-align:right; }
a{ color:var(--accent-dark); }
a:hover{ color:#0b3aa9; }
.btn.outline.btnDel{ border-color:#e5e7eb; color:#b91c1c; }
.btn.outline.btnDel:hover{ border-color:#ef4444; color:#ef4444; }
@media (prefers-contrast: more){ .input:focus{ box-shadow:0 0 0 3px rgba(0,0,0,.4); } }
</style>

<div class="edit-wrap">
  <div class="edit-head">
    <div>
      <h2>แก้ไขสินค้าในออเดอร์ #<?= (int)$order['id'] ?></h2>
      <div class="small">
        สถานะ: <span class="badge"><?= htmlspecialchars($order['status']) ?></span>
        &nbsp;|&nbsp; ผู้รับ: <?= htmlspecialchars($order['fullname'] ?: '-') ?>
        &nbsp;|&nbsp; อัปเดตล่าสุด: <?= htmlspecialchars($order['updated_at']) ?>
        <?php if ($isPickup): ?>
          &nbsp;|&nbsp; <span class="small" style="color:#16a34a">มารับเอง — ไม่มีค่าส่ง</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="actions">
      <a class="btn outline" href="orders.php">← กลับรายการออเดอร์</a>
    </div>
  </div>

  <form method="post" action="order_edit.php?id=<?= (int)$order['id'] ?>" id="editForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_items">

    <div class="grid-2" style="margin-top:.75rem">
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.5rem">
          <strong>รายการสินค้า</strong>
          <div class="small">* “พร้อมขายตอนนี้” = สต๊อค − ติดจอง (ออเดอร์อื่น)</div>
        </div>
        <table class="table" id="lineTable">
          <thead>
            <tr>
              <th style="width:44%">สินค้า</th>
              <th style="width:14%">ราคา/ชิ้น</th>
              <th style="width:14%">พร้อมขายตอนนี้</th>
              <th style="width:14%">จำนวน</th>
              <th style="width:14%">ลบ</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // สร้าง options หนึ่งครั้ง
            ob_start();
            echo '<option value="">— เลือกสินค้า —</option>';
            foreach($prods as $p){
              $av = max(0, (int)$p['stock'] - (int)$p['reserved_other']);
              echo '<option value="'.(int)$p['id'].'" data-price="'.htmlspecialchars((float)$p['price']).'" data-available="'.(int)$av.'">'
                  .htmlspecialchars($p['name']).'</option>';
            }
            $optionsHtml = ob_get_clean();

            foreach($items as $it):
              $pid = (int)$it['pid'];
              $uprice = (float)$it['unit_price']; // ยึดตามของเดิม
              $availNow = (int)($availMap[$pid] ?? 0); // พร้อมขาย (ออเดอร์อื่น)
            ?>
            <tr>
              <td>
                <select name="prod_id[]" class="input selProd" required>
                  <?= $optionsHtml ?>
                </select>
                <script>
                  (function(){
                    const sel = document.currentScript.previousElementSibling;
                    sel.value = String(<?= $pid ?>);
                  })();
                </script>
              </td>
              <td>฿<span class="uprice"><?= number_format($uprice,2) ?></span></td>
              <td><span class="avail"><?= (int)$availNow ?></span></td>
              <td><input class="input qty" type="number" name="qty[]" min="1" step="1" value="<?= (int)$it['qty'] ?>"></td>
              <td><button type="button" class="btn outline btnDel">ลบ</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5"><button type="button" class="btn outline" id="btnAdd">+ เพิ่มสินค้า</button></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="card">
        <strong>สรุปยอด</strong>
        <div class="small" style="opacity:.9;margin:.25rem 0 0">
          ค่าส่ง (เล่มแรก <?= number_format($shipFirst,2) ?> บาท, เล่มต่อไปเล่มละ <?= number_format($shipNext,2) ?> บาท) •
          พิมพ์ปก (เปิดบล็อก 500 + เกิน 50 เล่ม × 2)
        </div>

        <div style="margin-top:.6rem;display:grid;gap:.45rem">
          <div style="display:flex;justify-content:space-between">
            <div>ยอดสินค้า</div>
            <div>฿<span id="subVal"><?= number_format($subtotal0,2) ?></span></div>
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between">
            <label for="discount">ส่วนลด</label>
            <input id="discount" class="input" type="number" name="discount" step="0.01" min="0" style="width:11rem"
                   value="<?= htmlspecialchars(number_format($discount0,2,'.','')) ?>">
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem">
            <div style="display:flex;align-items:center;gap:.4rem">
              <label for="shipping" style="margin:0">ค่าส่ง</label>
              <label class="small" style="display:flex;align-items:center;gap:.25rem">
                <input type="checkbox" id="autoShip" name="auto_ship" value="1"
                       <?= $auto_ship_checked ? 'checked' : '' ?> <?= $isPickup ? 'disabled' : '' ?>>
                คำนวณอัตโนมัติ
              </label>
            </div>
            <input id="shipping" class="input" type="number" name="shipping" step="0.01" min="0" style="width:11rem"
                   value="<?= htmlspecialchars(number_format($shipping0,2,'.','')) ?>"
                   <?= ($auto_ship_checked || $isPickup) ? 'readonly' : '' ?>>
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem">
            <label class="small" style="display:flex;align-items:center;gap:.45rem">
              <input type="checkbox" id="coverPrint" name="cover_print" value="yes" <?= $cover_checked?'checked':'' ?>> พิมพ์ปก
            </label>
            <div>฿<span id="coverVal"><?= number_format($cover0,2) ?></span></div>
          </div>

          <hr style="border:none;border-top:1px solid #23234a;margin:.6rem 0">
          <div style="display:flex;justify-content:space-between;font-weight:700;font-size:1.05rem">
            <div>ยอดสุทธิ</div>
            <div>฿<span id="grandVal"><?= number_format($grand0,2) ?></span></div>
          </div>

          <div style="margin-top:.6rem">
            <label class="small">วันหมดอายุบิล (expires_at)</label>
            <input class="input" type="datetime-local" name="expires_at" value="<?= htmlspecialchars($expires0) ?>">
          </div>

          <button class="btn" type="submit" style="margin-top:.7rem">บันทึกการเปลี่ยนแปลง</button>
          <div class="small" style="opacity:.8;margin-top:.35rem">* ระบบจะตรวจสต๊อคจริงอีกครั้งตอนกดบันทึก</div>
        </div>
      </div>
    </div>
  </form>
</div>

<?php
// เตรียม options สำหรับ row template
ob_start();
echo '<option value="">— เลือกสินค้า —</option>';
foreach($prods as $p){
  $av = max(0, (int)$p['stock'] - (int)$p['reserved_other']);
  echo '<option value="'.(int)$p['id'].'" data-price="'.htmlspecialchars((float)$p['price']).'" data-available="'.(int)$av.'">'
      .htmlspecialchars($p['name']).'</option>';
}
$optionsHtml = ob_get_clean();
?>
<template id="rowTpl">
  <tr>
    <td>
      <select name="prod_id[]" class="input selProd" required>
        <?= $optionsHtml ?>
      </select>
    </td>
    <td>฿<span class="uprice">0.00</span></td>
    <td><span class="avail">0</span></td>
    <td><input class="input qty" type="number" name="qty[]" min="1" step="1" value="1"></td>
    <td><button type="button" class="btn outline btnDel">ลบ</button></td>
  </tr>
</template>

<script>
function fmt(n){ try{ return Number(n).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}); }catch(e){ return n; } }
function num(v){ const n=parseFloat(v||'0'); return isFinite(n)?n:0; }

const IS_PICKUP  = <?= $isPickup ? 'true' : 'false' ?>;
const SHIP_FIRST = <?= json_encode((float)$shipFirst) ?>;
const SHIP_NEXT  = <?= json_encode((float)$shipNext) ?>; // 10 บาทตาม settings

(function(){
  const tbl   = document.getElementById('lineTable');
  const tbody = tbl.querySelector('tbody');
  const tpl   = document.getElementById('rowTpl');
  const btnAdd= document.getElementById('btnAdd');
  const subEl = document.getElementById('subVal');
  const grandEl = document.getElementById('grandVal');
  const disEl = document.getElementById('discount');
  const shipEl= document.getElementById('shipping');
  const autoShip = document.getElementById('autoShip');
  const coverChk = document.getElementById('coverPrint');
  const coverEl  = document.getElementById('coverVal');

  function bindRow(tr){
    const sel = tr.querySelector('.selProd');
    const up  = tr.querySelector('.uprice');
    const av  = tr.querySelector('.avail');
    const qty = tr.querySelector('.qty');
    const del = tr.querySelector('.btnDel');

    function refreshFromSelect(){
      const opt = sel.options[sel.selectedIndex];
      const price = opt ? parseFloat(opt.getAttribute('data-price')||'0') : 0;
      const avail = opt ? parseInt(opt.getAttribute('data-available')||'0',10) : 0;
      if (up) up.textContent = fmt(price);
      if (av) av.textContent = String(avail);
      if (qty && (!qty.value || parseInt(qty.value,10)<=0)) qty.value = avail>0 ? 1 : 0;
      computeTotals();
    }

    sel.addEventListener('change', refreshFromSelect);
    qty.addEventListener('input', computeTotals);
    del.addEventListener('click', ()=>{ tr.remove(); computeTotals(); });
    refreshFromSelect();
  }

  function computeTotals(){
    let sub = 0;
    let qtySum = 0;
    tbody.querySelectorAll('tr').forEach(tr=>{
      const up  = num(tr.querySelector('.uprice')?.textContent);
      const qty = parseInt(tr.querySelector('.qty')?.value || '0',10);
      if (qty>0) qtySum += qty;
      if (up>0 && qty>0) sub += up*qty;
    });

    let ship = num(shipEl.value);

    if (IS_PICKUP) {
      ship = 0;
      shipEl.value = ship.toFixed(2);
      shipEl.readOnly = true;
      if (autoShip){ autoShip.checked = false; autoShip.disabled = true; }
    } else {
      if (autoShip && autoShip.checked) {
        ship = (qtySum>0) ? (SHIP_FIRST + Math.max(0, qtySum-1)*SHIP_NEXT) : 0;
        shipEl.value = ship.toFixed(2);
        shipEl.readOnly = true;
      } else {
        shipEl.readOnly = false;
      }
    }

    let cover = 0;
    if (coverChk && coverChk.checked) {
      cover = (qtySum>0) ? (500 + Math.max(0, qtySum-50)*2) : 0;
    }
    coverEl.textContent = fmt(cover);

    const grand = Math.max(0, sub - num(disEl.value) + ship + cover);
    subEl.textContent   = fmt(sub);
    grandEl.textContent = fmt(grand);
  }

  if (btnAdd){
    btnAdd.addEventListener('click', ()=>{
      const tr = tpl.content.firstElementChild.cloneNode(true);
      tbody.appendChild(tr);
      bindRow(tr);
    });
  }

  tbody.querySelectorAll('tr').forEach(bindRow);
  [disEl, shipEl, coverChk].forEach(el=> el && el.addEventListener('input', computeTotals));
  if (document.getElementById('autoShip')) document.getElementById('autoShip').addEventListener('change', computeTotals);
  computeTotals();
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
