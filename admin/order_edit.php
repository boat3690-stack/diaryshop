<?php
// admin/order_edit.php — แก้ไขสินค้าในออเดอร์ (Group-aware stock)
// PATCHED: แก้เรื่องการ "คืนสต๊อค" ให้ถูกต้องตามสถานะ + กันซ้ำด้วยธง + ล็อคหัวบิลขณะปรับสต๊อค
// - คอลัมน์ "พร้อมขายตอนนี้" คำนวณจาก "สต๊อคของตัวแม่ - ติดจองของกลุ่ม (ออเดอร์อื่น)"
// - ตอนกดบันทึก: ตรวจสต๊อคแบบกลุ่ม (รวมหลายบรรทัดที่อยู่กลุ่มเดียวกัน)
// - ปรับสต๊อคหลังบันทึกด้วยกฎ:
//     * ถ้า status ∈ {cancelled, void, expired}  => คืน (เฉพาะกรณีเคยตัด และยังไม่คืน)
//     * ถ้า status ∈ {paid, processing, shipped, completed} => (ถ้าเคยตัดแล้ว) คืนก่อน แล้วตัดใหม่ตามยอดล่าสุด
//     * อื่น ๆ (เช่น unpaid) => ไม่คืน/ไม่ตัด

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';   // CSRF/flash/helpers + stock helpers
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/notify.php';

require_admin();
if (!function_exists('has_perm') || !has_perm('orders.update')) {
  flash('error','ไม่มีสิทธิ์แก้ไขออเดอร์');
  redirect('orders.php');
}
$ADMIN_ID = function_exists('current_admin_id') ? (int) current_admin_id() : 0;

/* ---------- helpers ---------- */
function setting(PDO $pdo, string $key, $default=null){
  try{
    $st=$pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
    $st->execute([$key]); $v=$st->fetchColumn();
    return ($v===false)?$default:$v;
  }catch(Throwable $e){ return $default; }
}
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try{
      $st=$pdo->prepare("SHOW COLUMNS FROM `".str_replace('`','``',$table)."` LIKE ?");
      $st->execute([$col]);
      return (bool)$st->fetch();
    }catch(Throwable $e){ return false; }
  }
}

// --- สถานะที่ถือว่า "ต้องตัดสต๊อค" และ "ต้องคืนสต๊อค"
if (!function_exists('in_states_to_deduct')) {
  function in_states_to_deduct(string $s): bool {
    $s = strtolower(trim($s));
    return in_array($s, ['paid','processing','shipped','completed'], true);
  }
}
if (!function_exists('in_states_to_return')) {
  function in_states_to_return(string $s): bool {
    $s = strtolower(trim($s));
    return in_array($s, ['cancelled','void','expired'], true);
  }
}

// --- อัปเดตธงใน orders แบบไม่พังถ้าไม่มีคอลัมน์
if (!function_exists('update_order_flags_safe')) {
  function update_order_flags_safe(PDO $pdo, int $orderId, array $flags): void {
    $allow = ['stock_deducted','stock_returned','stock_locked'];
    $sets=[]; $vals=[];
    foreach ($flags as $k=>$v) {
      if (!in_array($k,$allow,true)) continue;
      if (!has_column($pdo,'orders',$k)) continue;
      $sets[] = "`$k`=?"; $vals[] = (int)$v;
    }
    if ($sets) {
      $vals[] = $orderId;
      $sql = 'UPDATE orders SET '.implode(',', $sets).', updated_at=NOW() WHERE id=?';
      $pdo->prepare($sql)->execute($vals);
    }
  }
}

/* --- wrappers เรียกฟังก์ชันสต๊อคแบบปลอดภัย (กัน argument count ไม่ตรง) --- */
if (!function_exists('_safe_ensure_return')) {
  function _safe_ensure_return(PDO $pdo, int $orderId, ?int $adminId=null): bool {
    if (!function_exists('ensure_stock_return')) return false;
    try { ensure_stock_return($pdo, $orderId, $adminId); return true; }
    catch (Throwable $e1) { try { ensure_stock_return($pdo, $orderId); return true; } catch (Throwable $e2) { return false; } }
  }
}
if (!function_exists('_safe_deduct')) {
  function _safe_deduct(PDO $pdo, int $orderId, ?int $adminId=null): bool {
    if (!function_exists('deduct_stock_for_order')) return false;
    try { deduct_stock_for_order($pdo, $orderId, $adminId); return true; }
    catch (Throwable $e1) { try { deduct_stock_for_order($pdo, $orderId); return true; } catch (Throwable $e2) { return false; } }
  }
}

/* ---------- load order ---------- */
$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) { flash('error','ไม่พบเลขที่ออเดอร์'); redirect('orders.php'); }

$st = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) { flash('error','ออเดอร์ไม่พบ'); redirect('orders.php'); }

/* ---------- columns / settings ---------- */
$coverCol = has_column($pdo,'products','allow_cover_print') ? 'allow_cover_print'
         : (has_column($pdo,'products','allow_cover') ? 'allow_cover' : null);

$shipFirst = (float)setting($pdo,'shipping_first_item', setting($pdo,'shipping_first',50));
$shipNext  = (float)setting($pdo,'shipping_next_item',  setting($pdo,'shipping_next',10));

$coverBase = (float)setting($pdo,'cover_base',500);
$coverRate = (float)setting($pdo,'cover_over_rate',2);
$coverTh   = (int)setting($pdo,'cover_threshold_qty',50);

/* ---------- current delivery option ---------- */
$deliveryOpt = $order['delivery_option'] ?? 'pickup'; // pickup|home
$isPickup    = ($deliveryOpt === 'pickup');

/* ---------- ดึงสินค้าในออเดอร์ (รวม stock_group เพื่อ map กลุ่ม) ---------- */
$sqlItems = "
  SELECT oi.product_id pid, oi.qty, oi.unit_price, 
         p.name, p.price AS cur_price, p.stock, p.stock_group,
         ".($coverCol? "COALESCE(p.`$coverCol`,0) AS allow_cover" : "0 AS allow_cover")."
  FROM order_items oi
  JOIN products p ON p.id=oi.product_id
  WHERE oi.order_id=?
  ORDER BY oi.id";
$it = $pdo->prepare($sqlItems);
$it->execute([$orderId]);

$items = []; $oldQtyByPid=[]; $oldPriceByPid=[];
while($r=$it->fetch(PDO::FETCH_ASSOC)){
  $allowFlag = !empty($r['allow_cover']) ? 1 : 0;
  if (!$allowFlag && preg_match('/พิมพ์\W*ปก/u', (string)$r['name'])) $allowFlag = 1;
  $r['allow_cover'] = $allowFlag;
  $items[] = $r;
  $oldQtyByPid[(int)$r['pid']]   = (int)$r['qty'];
  $oldPriceByPid[(int)$r['pid']] = (float)$r['unit_price'];
}

/* ---------- ดึงรายการสินค้าทั้งหมด (ใช้คำนวณกลุ่ม/ตัวแม่/พร้อมขายตอนนี้) ---------- */
$prods = $pdo->query("\n  SELECT id, name, price, stock, is_active,\n         COALESCE(stock_group,'') AS stock_group,\n         COALESCE(is_stock_master,0) AS is_stock_master\n         ".($coverCol ? ", COALESCE(`$coverCol`,0) AS allow_cover" : ", 0 AS allow_cover")."\n  FROM products\n  ORDER BY name\n")->fetchAll(PDO::FETCH_ASSOC);

/* --- สร้างดัชนีสินค้า + หาตัวแม่ของแต่ละกลุ่ม --- */
$prodIndex = [];             // id => row
$groupMasterId = [];         // stock_group => master_id
$masterStockById = [];       // master_id => stock (ของตัวแม่)
foreach($prods as $p){
  $pid = (int)$p['id'];
  $prodIndex[$pid] = $p;
  if (!empty($p['is_stock_master']) && $p['is_stock_master']==1 && $p['stock_group']!==''){
    $groupMasterId[$p['stock_group']] = $pid;
    $masterStockById[$pid] = (int)$p['stock'];
  }
}
// สำหรับสินค้าที่ไม่มีกลุ่ม/ไม่มีตัวแม่ → ให้ถือว่า master = ตัวเอง
foreach($prods as $p){
  $pid=(int)$p['id'];
  if ($p['stock_group']==='' || empty($groupMasterId[$p['stock_group']])) {
    if (!isset($masterStockById[$pid])) $masterStockById[$pid] = (int)$p['stock'];
  }
}

/* --- หา “ติดจองของออเดอร์อื่น” แบบกลุ่ม --- */

// A) reserved ของสินค้าที่ไม่อยู่ในกลุ่ม (ต่อ product_id)
$stA = $pdo->prepare("\n  SELECT oi.product_id AS pid, COALESCE(SUM(oi.qty),0) AS reserved\n  FROM order_items oi\n  JOIN orders o ON o.id=oi.order_id\n  JOIN products p ON p.id=oi.product_id\n  WHERE o.status='unpaid'\n    AND (o.expires_at IS NULL OR o.expires_at > NOW())\n    AND o.id<>?\n    AND (p.stock_group IS NULL OR p.stock_group='')\n  GROUP BY oi.product_id\n");
$stA->execute([$orderId]);
$reservedSingle = []; // pid => reserved
foreach($stA as $row){ $reservedSingle[(int)$row['pid']] = (int)$row['reserved']; }

// B) reserved ของสินค้าที่อยู่ในกลุ่ม (ต่อ group_code)
$stB = $pdo->prepare("\n  SELECT p.stock_group AS g, COALESCE(SUM(oi.qty),0) AS reserved\n  FROM order_items oi\n  JOIN orders o ON o.id=oi.order_id\n  JOIN products p ON p.id=oi.product_id\n  WHERE o.status='unpaid'\n    AND (o.expires_at IS NULL OR o.expires_at > NOW())\n    AND o.id<>?\n    AND (p.stock_group IS NOT NULL AND p.stock_group<>'')\n  GROUP BY p.stock_group\n");
$stB->execute([$orderId]);
$reservedGroup = []; // group_code => reserved
foreach($stB as $row){ $reservedGroup[(string)$row['g']] = (int)$row['reserved']; }

/* --- คำนวณ available แบบกลุ่มสำหรับ “ตัวแม่แต่ละกลุ่ม” --- */
$groupAvailByMaster = []; // master_id => available_now
// 1) กลุ่มที่มีตัวแม่
foreach($groupMasterId as $g => $mid){
  $masterStock = (int)($masterStockById[$mid] ?? 0);
  $resv = (int)($reservedGroup[$g] ?? 0);
  $groupAvailByMaster[$mid] = max(0, $masterStock - $resv);
}
// 2) สินค้านอกกลุ่ม/ไม่มีตัวแม่ → ใช้ของตัวเองเป็น master
foreach($prodIndex as $pid => $p){
  if ($p['stock_group']!=='' && isset($groupMasterId[$p['stock_group']])) continue;
  $masterStock = (int)$masterStockById[$pid];
  $resv = (int)($reservedSingle[$pid] ?? 0);
  $groupAvailByMaster[$pid] = max(0, $masterStock - $resv);
}

/* --- คำนวณ oldQty แบบ “ต่อ master” ของใบนี้ (เพื่อคืนโควต้ากลุ่มตอนคำนวณ) --- */
$oldQtyByMaster = []; // master_id => qty เดิมในบิลนี้
foreach($items as $it){
  $pid=(int)$it['pid'];
  $pinfo = $prodIndex[$pid] ?? null;
  $g = $pinfo ? (string)$pinfo['stock_group'] : '';
  $mid = ($g!=='' && isset($groupMasterId[$g])) ? (int)$groupMasterId[$g] : $pid;
  $oldQtyByMaster[$mid] = ($oldQtyByMaster[$mid] ?? 0) + (int)$it['qty'];
}

/* --- สร้าง availMap สำหรับแสดงในตารางบรรทัดเดิม (ของเก่า) --- */
$availMap = []; // pid => available(group)
foreach($items as $it){
  $pid=(int)$it['pid'];
  $pinfo = $prodIndex[$pid] ?? null;
  if ($pinfo){
    $g=(string)$pinfo['stock_group'];
    $mid = ($g!=='' && isset($groupMasterId[$g])) ? (int)$groupMasterId[$g] : $pid;
    $availMap[$pid] = (int)($groupAvailByMaster[$mid] ?? 0);
  } else {
    $availMap[$pid] = 0;
  }
}

/* ======================= POST: SAVE ======================= */
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='save_items'){
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); redirect("order_edit.php?id=$orderId"); }

  // delivery option (pickup|home)
  $deliveryOpt = ($_POST['delivery_option'] ?? $deliveryOpt);
  $deliveryOpt = in_array($deliveryOpt, ['pickup','home'], true) ? $deliveryOpt : 'pickup';
  $isPickup    = ($deliveryOpt==='pickup');

  // inputs
  $pids = $_POST['prod_id'] ?? [];
  $qtys = $_POST['qty']     ?? [];

  $discount = max(0.0, (float)($_POST['discount'] ?? ($order['discount'] ?? 0)));

  $auto_ship = isset($_POST['auto_ship']) ? 1 : 0;
  $shipping_manual = max(0.0, (float)($_POST['shipping'] ?? ($order['shipping'] ?? 0)));

  $cover_print = (($_POST['cover_print'] ?? 'no') === 'yes');

  // expires
  $expires_post = trim((string)($_POST['expires_at'] ?? ''));
  $expires_sql  = null;
  if ($expires_post !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $expires_post)) {
      $expires_sql = str_replace('T',' ',$expires_post).':00';
    } else {
      $ts=strtotime($expires_post); if($ts!==false) $expires_sql=date('Y-m-d H:i:s',$ts);
    }
  }

  // merge lines (รวมจำนวนสินค้าซ้ำ)
  $want=[]; // pid => qty
  if (is_array($pids) && is_array($qtys)){
    $n=min(count($pids),count($qtys));
    for($i=0;$i<$n;$i++){
      $pid=(int)$pids[$i]; $q=(int)$qtys[$i];
      if($pid>0 && $q>0){ $want[$pid]=($want[$pid]??0)+$q; }
    }
  }
  if(!$want){ flash('error','กรุณาเพิ่มสินค้าอย่างน้อย 1 รายการ'); redirect("order_edit.php?id=$orderId"); }

  // เตรียมข้อมูลสินค้าเฉพาะที่เลือก
  $rows=[]; $errors=[]; $notes=[];
  $subtotal=0.0; $qty_all=0; $qty_cover=0;

  // การจัดสรรแบบกลุ่ม: ใช้เพดาน = (stock ของตัวแม่) - (ติดจองออเดอร์อื่น) + (จำนวนเดิมของบิลนี้ในกลุ่ม)
  $allocByMaster = []; // master_id => จำนวนที่จัดสรรให้บรรทัดใหม่แล้ว (ในรอบนี้)
  foreach($want as $pid=>$qNew){
    $pinfo = $prodIndex[$pid] ?? null;
    if (!$pinfo){ $errors[]='ไม่พบสินค้า ID '.$pid; continue; }

    $gCode = (string)$pinfo['stock_group'];
    $mid = ($gCode!=='' && isset($groupMasterId[$gCode])) ? (int)$groupMasterId[$gCode] : $pid;

    $masterStock = (int)($masterStockById[$mid] ?? 0);
    $reservedOther = 0;
    if ($gCode!==''){
      $reservedOther = (int)($reservedGroup[$gCode] ?? 0);
    } else {
      $reservedOther = (int)($reservedSingle[$pid] ?? 0);
    }
    $oldQtyThisGroup = (int)($oldQtyByMaster[$mid] ?? 0);
    $alreadyAlloc = (int)($allocByMaster[$mid] ?? 0);

    // เพดานสูงสุดของกลุ่มสำหรับบรรทัดนี้ (หักที่จัดสรรไปแล้วในกลุ่มเดียวกัน)
    $groupCap = max(0, $masterStock - $reservedOther + $oldQtyThisGroup - $alreadyAlloc);

    $finalQ = min($qNew, $groupCap);
    if ($finalQ<=0){
      $notes[]='ตัดออก: '.$pinfo['name'].' (สต๊อคกลุ่มไม่พอ)';
      continue;
    }
    if ($finalQ<$qNew){
      $errors[]='ปรับจำนวน '.$pinfo['name'].' '.$qNew.'→'.$finalQ.' (จำกัดตามสต๊อคกลุ่ม)';
    }

    $uprice = array_key_exists($pid,$oldPriceByPid) ? (float)$oldPriceByPid[$pid] : (float)$pinfo['price'];
    $allowCover = !empty($pinfo['allow_cover']) ? 1 : 0;
    if (!$allowCover && preg_match('/พิมพ์\W*ปก/u', (string)$pinfo['name'])) $allowCover = 1;

    $rows[] = ['pid'=>$pid,'qty'=>$finalQ,'price'=>$uprice,'allow_cover'=>$allowCover];
    $allocByMaster[$mid] = $alreadyAlloc + $finalQ;

    $subtotal += $finalQ*$uprice;
    $qty_all  += $finalQ;
    if ($allowCover) $qty_cover += $finalQ;
  }

  if (!$rows){ flash('error','ไม่มีรายการใดสามารถบันทึกได้ (สต๊อคกลุ่มไม่พอ)'); redirect("order_edit.php?id=$orderId"); }

  // shipping
  $ship_calc = ($qty_all>0) ? ($shipFirst + max(0, $qty_all-1)*$shipNext) : 0.0;
  $shipping  = $isPickup ? 0.0 : ($auto_ship ? $ship_calc : $shipping_manual);

  // cover fee
  $cover_fee = 0.0;
  if ($cover_print) {
    $cover_fee = ($qty_cover>0) ? ($coverBase + max(0, $qty_cover - $coverTh)*$coverRate) : 0.0;
  }

  $grand = max(0.0, $subtotal - $discount + $shipping + $cover_fee);

  // note: พิมพ์ปก
  $note_old = (string)($order['note'] ?? '');
  $note_new = preg_replace('/^พิมพ์ปก:.*$/mu', '', $note_old);
  $note_new = trim(preg_replace("/\n{2,}/", "\n", (string)$note_new));
  if ($cover_print) {
    $over = max(0, $qty_cover - $coverTh);
    $line = 'พิมพ์ปก: เปิดบล็อก '.number_format($coverBase,2).' + เกิน '.$over.' เล่ม × '.number_format($coverRate,2)
          .' = '.number_format($cover_fee,2).' บาท';
    $note_new = ($note_new !== '') ? ($note_new."\n".$line) : $line;
  }

  try{
    $pdo->beginTransaction();

    // replace items
    $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$orderId]);
    try{
      $ins = $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES (?,?,?,?)");
      foreach($rows as $r){ $ins->execute([$orderId,$r['pid'],$r['qty'],$r['price']]); }
    }catch(Exception $e){
      $ins2 = $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, unit_price, unit_cost) VALUES (?,?,?,?,?)");
      foreach($rows as $r){ $ins2->execute([$orderId,$r['pid'],$r['qty'],$r['price'],0.0]); }
    }

    // update order totals (+ expires_at) และใส่ delivery_option เฉพาะเมื่อมีคอลัมน์
    $setCols = "grand_total=?, shipping=?, discount=?, subtotal=?, note=?, updated_at=NOW()";
    $paramsU = [$grand, $shipping, $discount, $subtotal, $note_new];

    $hasDeliveryOptCol = has_column($pdo,'orders','delivery_option');
    if ($hasDeliveryOptCol) { $setCols .= ", delivery_option=?"; $paramsU[] = $deliveryOpt; }

    if ($expires_sql !== null){ $setCols .= ", expires_at=?"; $paramsU[] = $expires_sql; }

    $paramsU[] = $orderId;
    $pdo->prepare("UPDATE orders SET $setCols WHERE id=?")->execute($paramsU);

    // cover fee total (ถ้ามีคอลัมน์)
    try{ $pdo->prepare("UPDATE orders SET cover_fee_total=? WHERE id=?")->execute([$cover_fee,$orderId]); }catch(Exception $e){}

    if (function_exists('admin_log')){
      admin_log($pdo,'orders.edit_items','order',$orderId,[
        'subtotal'=>$subtotal,'shipping'=>$shipping,'discount'=>$discount,'cover_fee'=>$cover_fee,
        'grand_total'=>$grand,'qty_all'=>$qty_all,'qty_cover'=>$qty_cover,'delivery_option'=>$deliveryOpt
      ]);
    }

    $pdo->commit();
  }catch(Exception $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    flash('error','บันทึกล้มเหลว: '.$e->getMessage());
    redirect("order_edit.php?id=$orderId"); exit;
  }

  /* ===== ปรับสต๊อคฝั่งเซิร์ฟเวอร์ (ปลอดภัยกว่าเดิม) ===== */
/* ===== ปรับสต๊อคฝั่งเซิร์ฟเวอร์ให้ “ตัดจริงทุกครั้งหลังแก้” (มี fallback) ===== */
try {
  $returned = false; $deducted = false;

  // 1) พยายามใช้ฟังก์ชันเดิมก่อน (idempotent)
  if (function_exists('ensure_stock_return')) {
    try { ensure_stock_return($pdo, $orderId, $ADMIN_ID ?? null); $returned = true; } catch (Throwable $e) {}
  }
  if (!$returned && function_exists('return_stock_for_order')) {
    try { return_stock_for_order($pdo, $orderId); $returned = true; } catch (Throwable $e) {}
  }
  if (function_exists('deduct_stock_for_order')) {
    try { deduct_stock_for_order($pdo, $orderId, $ADMIN_ID ?? null); $deducted = true; } catch (Throwable $e1) {
      try { deduct_stock_for_order($pdo, $orderId); $deducted = true; } catch (Throwable $e2) {}
    }
  }

  /* ---------------- FALLBACK แบบกลุ่ม: ลด/คืนที่ตัวแม่ ----------------
     เงื่อนไข: ถ้าฟังก์ชันเดิมไม่ทำงานตามคาด ให้ทำเองอย่างปลอดภัย
     - ถ้าออเดอร์เดิมเคย lock สต๊อคไว้ (stock_locked=1) แต่ยัง "ไม่คืน" ในรอบนี้ → คืนของเก่า
     - ถ้ายัง "ไม่ตัดใหม่" → ตัดใหม่ตามยอดล่าสุด
  --------------------------------------------------------------------- */
  $wasLockedBefore = !empty($order['stock_locked']); // ค่าก่อนอัปเดต

  if ($wasLockedBefore && !$returned && !empty($oldQtyByMaster)) {
    $stmtR = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
    foreach ($oldQtyByMaster as $mid => $qOld) {
      $qOld = (int)$qOld; if ($qOld > 0) { $stmtR->execute([$qOld, (int)$mid]); }
    }
    $returned = true;
  }

  if (!$deducted && !empty($allocByMaster)) {
    $stmtD = $pdo->prepare("UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?");
    foreach ($allocByMaster as $mid => $qNew) {
      $qNew = (int)$qNew; if ($qNew > 0) { $stmtD->execute([$qNew, (int)$mid]); }
    }
    $deducted = true;
  }

  // 3) ตั้งธงล็อค (ถ้ามีคอลัมน์)
  if (has_column($pdo,'orders','stock_locked')) {
    $pdo->prepare("UPDATE orders SET stock_locked=1, updated_at=NOW() WHERE id=?")->execute([$orderId]);
  }

  if (function_exists('admin_log')){
    admin_log($pdo,'orders.force_deduct_after_edit','order',$orderId,[
      'fallback_used' => (!$returned || !$deducted) ? 1 : 0,
      'returned' => $returned?1:0, 'deducted' => $deducted?1:0
    ]);
  }
} catch (Throwable $e) {
  // ไม่ให้ล้ม flow หลัก แต่แจ้งเตือน
  flash('error','อัปเดตรายการแล้ว แต่มีปัญหาปรับสต๊อค: '.$e->getMessage());
}
  flash('success','บันทึกสำเร็จ');
  redirect("order_edit.php?id=$orderId"); exit;
}

/* ---------- preview values ---------- */
$subtotal0=0.0; $qtyAll0=0; $qtyCover0=0;
foreach($items as $it){
  $subtotal0 += $it['qty']*$it['unit_price'];
  $qtyAll0   += (int)$it['qty'];
  $allowC = !empty($it['allow_cover']) || preg_match('/พิมพ์\W*ปก/u', (string)($it['name'] ?? ''));
  if($allowC) $qtyCover0 += (int)$it['qty'];
}
$shipCalc0 = ($qtyAll0>0)? ($shipFirst + max(0,$qtyAll0-1)*$shipNext) : 0.0;
$shipping0 = $isPickup ? 0.0 : (float)($order['shipping'] ?? $shipCalc0);
$discount0 = (float)($order['discount'] ?? 0);

$cover0 = 0.0;
try{ if(isset($order['cover_fee_total'])) $cover0 = (float)$order['cover_fee_total']; }catch(Throwable $e){}
if($cover0<=0 && preg_match('/^พิมพ์ปก:/mu',(string)($order['note'] ?? ''))){
  $cover0 = ($qtyCover0>0)? ($coverBase + max(0,$qtyCover0-$coverTh)*$coverRate) : 0.0;
}
$grand0 = max(0.0, $subtotal0 - $discount0 + $shipping0 + $cover0);

$expires0 = !empty($order['expires_at']) ? date('Y-m-d\TH:i', strtotime($order['expires_at'])) : '';
$cover_checked = $cover0 > 0;
$auto_ship_checked = (!$isPickup) && (abs($shipping0 - $shipCalc0) < 0.01);

/* ---------- UI ---------- */
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
.btn.outline{ background:#fff; color:#0e1530; border:1px solid #cbd5e1; }
.small{ font-size:.92rem; color:var(--muted); }
hr{ border:none; border-top:1px solid #23234a; margin:.7rem 0; }
#grandVal{ color:#0f172a; font-weight:800; }
.avail{ display:inline-block; min-width:2.5ch; text-align:right; color:#111; }
.card input[type="number"]{ text-align:right; }
.btn.outline.btnDel{ border-color:#e5e7eb; color:#b91c1c; }
.btn.outline.btnDel:hover{ border-color:#ef4444; color:#ef4444; }
</style>

<div class="edit-wrap">
  <div class="edit-head">
    <div>
      <h2>แก้ไขสินค้าในออเดอร์ #<?= (int)$order['id'] ?></h2>
      <div class="small">
        สถานะ: <span class="badge"><?= htmlspecialchars($order['status']) ?></span>
        &nbsp;|&nbsp; ผู้รับ: <?= htmlspecialchars($order['fullname'] ?: '-') ?>
        &nbsp;|&nbsp; อัปเดตล่าสุด: <?= htmlspecialchars($order['updated_at']) ?>
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
          <div class="small">* “พร้อมขายตอนนี้” = สต๊อค(ตัวแม่) − ติดจอง (ออเดอร์อื่นในกลุ่มเดียวกัน)</div>
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
            // optionsHtml (ใส่ข้อมูล group-aware ลง data-* ให้ JS ใช้)
            ob_start();
            echo '<option value="">— เลือกสินค้า —</option>';
            foreach($prods as $p){
              if (empty($p['is_active'])) continue;
              $pid = (int)$p['id'];
              $g   = (string)$p['stock_group'];
              $mid = ($g!=='' && isset($groupMasterId[$g])) ? (int)$groupMasterId[$g] : $pid;
              $groupAvail = (int)($groupAvailByMaster[$mid] ?? 0);
              $allowCover = !empty($p['allow_cover']) ? 1 : 0;
              if (!$allowCover && preg_match('/พิมพ์\W*ปก/u', (string)$p['name'])) $allowCover = 1;

              echo '<option value="'.$pid
                   .'" data-price="'.htmlspecialchars((float)$p['price']).'"'
                   .' data-allow-cover="'.$allowCover.'"'
                   .' data-group="'.htmlspecialchars($g).'"'
                   .' data-master-id="'.$mid.'"'
                   .' data-group-available="'.$groupAvail.'"'
                   .'>'.htmlspecialchars($p['name']).'</option>';
            }
            $optionsHtml = ob_get_clean();

            foreach($items as $it):
              $pid  = (int)$it['pid'];
              $upri = (float)$it['unit_price'];
              $pinfo = $prodIndex[$pid] ?? null;
              $g   = $pinfo ? (string)$pinfo['stock_group'] : '';
              $mid = ($g!=='' && isset($groupMasterId[$g])) ? (int)$groupMasterId[$g] : $pid;
              $avNow= (int)($groupAvailByMaster[$mid] ?? 0);
            ?>
            <tr>
              <td>
                <select name="prod_id[]" class="input selProd" required>
                  <?= $optionsHtml ?>
                </select>
                <script>(function(){const s=document.currentScript.previousElementSibling;s.value=String(<?= $pid ?>);})();</script>
              </td>
              <td>฿<span class="uprice"><?= number_format($upri,2) ?></span></td>
              <td><span class="avail"><?= (int)$avNow ?></span></td>
              <td><input class="input qty" type="number" name="qty[]" min="1" step="1" value="<?= (int)$it['qty'] ?>"></td>
              <td><button type="button" class="btn outline btnDel">ลบ</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr><td colspan="5"><button type="button" class="btn outline" id="btnAdd">+ เพิ่มสินค้า</button></td></tr>
          </tfoot>
        </table>
      </div>

      <div class="card">
        <strong>สรุปยอด</strong>
        <div class="small" style="opacity:.9;margin:.25rem 0 0">
          ค่าส่ง (เล่มแรก <?= number_format($shipFirst,2) ?>, เล่มต่อไป <?= number_format($shipNext,2) ?>) •
          พิมพ์ปก (เปิดบล็อก <?= number_format($coverBase,2) ?> + เกิน <?= (int)$coverTh ?> เล่ม × <?= number_format($coverRate,2) ?>)
        </div>

        <div style="margin-top:.6rem;display:grid;gap:.45rem">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem">
            <label for="deliveryOption">การรับสินค้า</label>
            <select id="deliveryOption" name="delivery_option" class="input" style="width:11rem">
              <option value="pickup" <?= $isPickup?'selected':'' ?>>รับเองที่ร้าน</option>
              <option value="home"   <?= !$isPickup?'selected':'' ?>>จัดส่งถึงบ้าน</option>
            </select>
          </div>

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
                  <?= $auto_ship_checked ? 'checked' : '' ?>>
                คำนวณอัตโนมัติ
              </label>
            </div>
            <input id="shipping" class="input" type="number" name="shipping" step="0.01" min="0" style="width:11rem"
                   value="<?= htmlspecialchars(number_format($shipping0,2,'.','')) ?>">
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
          <div class="small" style="opacity:.8;margin-top:.35rem">* ระบบจะตรวจสต๊อคจริงตามสถานะ (คืนเฉพาะเมื่อควรคืน และตัดเฉพาะเมื่อควรตัด)</div>
        </div>
      </div>
    </div>
  </form>
</div>

<?php
// optionsHtml สำหรับแถวใหม่ (ซ้ำกับด้านบน)
ob_start();
echo '<option value="">— เลือกสินค้า —</option>';
foreach($prods as $p){
  if (empty($p['is_active'])) continue;
  $pid = (int)$p['id'];
  $g   = (string)$p['stock_group'];
  $mid = ($g!=='' && isset($groupMasterId[$g])) ? (int)$groupMasterId[$g] : $pid;
  $groupAvail = (int)($groupAvailByMaster[$mid] ?? 0);
  $allowCover = !empty($p['allow_cover']) ? 1 : 0;
  if (!$allowCover && preg_match('/พิมพ์\W*ปก/u', (string)$p['name'])) $allowCover = 1;

  echo '<option value="'.$pid
       .'" data-price="'.htmlspecialchars((float)$p['price']).'"'
       .' data-allow-cover="'.$allowCover.'"'
       .' data-group="'.htmlspecialchars($g).'"'
       .' data-master-id="'.$mid.'"'
       .' data-group-available="'.$groupAvail.'"'
       .'>'.htmlspecialchars($p['name']).'</option>';
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
function fmt(n){ try{return Number(n).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});}catch(e){return n;} }
function num(v){ const n=parseFloat(v||'0'); return isFinite(n)?n:0; }

const SHIP_FIRST = <?= json_encode((float)$shipFirst) ?>;
const SHIP_NEXT  = <?= json_encode((float)$shipNext) ?>;
const COV_BASE   = <?= json_encode((float)$coverBase) ?>;
const COV_RATE   = <?= json_encode((float)$coverRate) ?>;
const COV_TH     = <?= json_encode((int)$coverTh) ?>;

(function(){
  const tbl   = document.getElementById('lineTable');
  const tbody = tbl.querySelector('tbody');
  const tpl   = document.getElementById('rowTpl');
  const addBtn= document.getElementById('btnAdd');   // ใช้ตัวเดียวทั้งไฟล์

  const subEl   = document.getElementById('subVal');
  const grandEl = document.getElementById('grandVal');
  const disEl   = document.getElementById('discount');
  const shipEl  = document.getElementById('shipping');
  const autoShip= document.getElementById('autoShip');
  const coverChk= document.getElementById('coverPrint');
  const coverEl = document.getElementById('coverVal');
  const delivEl = document.getElementById('deliveryOption');

  function computeTotals(){
    let sub=0, qtyAll=0, qtyCover=0;

    // รวมจำนวนที่เลือกแบบกลุ่ม (key = master-id)
    const groupSelected = {};
    tbody.querySelectorAll('tr').forEach(tr=>{
      const sel = tr.querySelector('.selProd');
      const opt = sel?.options[sel.selectedIndex];
      const mid = opt ? parseInt(opt.getAttribute('data-master-id')||'0',10) : 0;
      const q = parseInt(tr.querySelector('.qty')?.value||'0',10);
      if (mid>0 && q>0) groupSelected[mid] = (groupSelected[mid]||0) + q;
    });

    // รอบแรก: คำนวณยอด/จำนวน
    tbody.querySelectorAll('tr').forEach(tr=>{
      const sel = tr.querySelector('.selProd');
      const opt = sel?.options[sel.selectedIndex];
      const nameText = opt ? (opt.textContent || opt.innerText || '') : '';
      const allowCoverAttr = opt ? opt.getAttribute('data-allow-cover') : null;
      const allowCover = (allowCoverAttr === '1') || /พิมพ์\W*ปก/i.test(nameText);

      const price = num(tr.querySelector('.uprice')?.textContent);
      const q = parseInt(tr.querySelector('.qty')?.value||'0',10);
      if(q>0){ qtyAll += q; if(allowCover) qtyCover += q; }
      if(price>0 && q>0) sub += price*q;
    });

    // shipping
    let isPickup = (delivEl?.value === 'pickup');
    let ship = num(shipEl.value);
    if (isPickup){
      ship = 0;
      shipEl.value = ship.toFixed(2);
      shipEl.readOnly = true;
      if (autoShip) { autoShip.checked = false; }
    }else{
      if (autoShip && autoShip.checked){
        ship = (qtyAll>0) ? (SHIP_FIRST + Math.max(0, qtyAll-1)*SHIP_NEXT) : 0;
        shipEl.value = ship.toFixed(2);
        shipEl.readOnly = true;
      }else{
        shipEl.readOnly = false;
      }
    }

    // cover
    let cover = 0;
    if (coverChk && coverChk.checked){
      cover = (qtyCover>0) ? (COV_BASE + Math.max(0, qtyCover - COV_TH)*COV_RATE) : 0;
    }
    coverEl.textContent = fmt(cover);

    // อัปเดต "พร้อมขายตอนนี้" ต่อแถว
    tbody.querySelectorAll('tr').forEach(tr=>{
      const sel = tr.querySelector('.selProd');
      const opt = sel?.options[sel.selectedIndex];
      const avEl = tr.querySelector('.avail');
      const qtyEl= tr.querySelector('.qty');
      if (!opt || !avEl || !qtyEl) return;

      const mid = parseInt(opt.getAttribute('data-master-id')||'0',10);
      const groupAvail = parseInt(opt.getAttribute('data-group-available')||'0',10);
      const thisQ = parseInt(qtyEl.value||'0',10);

      // ใหม่ (แสดง “เหลือหลังจากคิดรวมทุกแถวแล้ว” -> แถวเดียวก็ลดทันที)
const sumAll = groupSelected[mid] || 0;                  // รวมทั้งกลุ่ม (รวมแถวนี้ด้วย)
const remainingAfterThis = Math.max(0, groupAvail - sumAll);
avEl.textContent = String(remainingAfterThis);

// เพดานสูงสุดของแถวนี้ = เหลือ + จำนวนของแถวนี้ตอนนี้
const hardMax = remainingAfterThis + (thisQ || 0);
if (thisQ > hardMax){ qtyEl.value = String(hardMax); }
qtyEl.setAttribute('max', String(Math.max(1, hardMax)));

      if (parseInt(qtyEl.value||'0',10) < 1) qtyEl.value = '1';
    });

    const grand = Math.max(0, sub - num(disEl.value) + ship + cover);
    subEl.textContent   = fmt(sub);
    grandEl.textContent = fmt(grand);
  }

  function bindRow(tr){
    const sel = tr.querySelector('.selProd');
    const up  = tr.querySelector('.uprice');
    const qty = tr.querySelector('.qty');
    const del = tr.querySelector('.btnDel');

    function refreshFromSelect(){
      const opt = sel.options[sel.selectedIndex];
      const price = opt ? parseFloat(opt.getAttribute('data-price')||'0') : 0;
      if (up) up.textContent = fmt(price);
      computeTotals();
    }

    sel.addEventListener('change', refreshFromSelect);
    qty.addEventListener('input', computeTotals);
    del.addEventListener('click', ()=>{ tr.remove(); computeTotals(); });
    refreshFromSelect();
  }

  addBtn?.addEventListener('click', ()=>{
    const tr = tpl.content.firstElementChild.cloneNode(true);
    tbody.appendChild(tr);
    bindRow(tr);
  });

  tbody.querySelectorAll('tr').forEach(bindRow);
  [disEl, shipEl].forEach(el=> el && el.addEventListener('input', computeTotals));
  coverChk?.addEventListener('change', computeTotals);
  delivEl?.addEventListener('change', computeTotals);
  autoShip?.addEventListener('change', computeTotals);
  computeTotals();
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
