<?php
// admin/orders.php — รวมโค้ดเก่า + ปรับปรุง พร้อมใช้งาน (รองรับสลิปหลายไฟล์ + คืนสต๊อกอัตโนมัติ)
// การเปลี่ยนแปลงสำคัญ:
// 1) คืนสต๊อกทันทีเมื่อบิลหมดอายุ (auto-expire) โดยใช้ ensure_stock_return() ก่อนอัปเดตสถานะเป็น cancelled
// 2) คืน/ตัดสต๊อกอย่างถูกต้องเวลาเปลี่ยนสถานะ (เดี่ยว/กลุ่ม) แบบ idempotent (ไม่ซ้ำซ้อน)
// 3) อัปโหลดสลิปได้หลายไฟล์ เก็บของเดิมไว้ใน payment_files และคงค่า payments.slip_path ชี้ไฟล์ล่าสุดเพื่อเข้ากับรายงานเดิม

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';      // ต้องมี: is_paidish, ensure_stock_return, return_stock_for_order, deduct_stock_for_order, handle_upload, csrf_*
require_once __DIR__ . '/../includes/coupon_helpers.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/authz.php';

require_admin();
$ADMIN_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------- safe get_setting (กันกรณีบางโปรเจกต์ไม่มี) ------- */
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

/* ------- helper: แปลง $_FILES เป็นอาร์เรย์รายการ (รองรับ multiple) ------- */
if (!function_exists('rearray_files')) {
  function rearray_files($file_input): array {
    $result = [];
    if (!$file_input || !isset($file_input['name'])) return $result;
    if (is_array($file_input['name'])) {
      foreach ($file_input['name'] as $i=>$name) {
        if ($name==='') continue;
        $result[] = [
          'name'     => $file_input['name'][$i],
          'type'     => $file_input['type'][$i],
          'tmp_name' => $file_input['tmp_name'][$i],
          'error'    => $file_input['error'][$i],
          'size'     => $file_input['size'][$i],
        ];
      }
    } else {
      $result[] = $file_input;
    }
    return $result;
  }
}

/* =========================================================
   Utilities (schema-safe helpers)
========================================================= */
if (!function_exists('has_column')) { // หากมีแล้วใน functions.php จะไม่ประกาศซ้ำ
  function has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $stmt = $pdo->prepare('SHOW COLUMNS FROM `'.str_replace('`','``',$table).'` LIKE ?');
      $stmt->execute([$column]);
      return (bool)$stmt->fetch();
    } catch (Throwable $e) { return false; }
  }
}

// CASE expression ใช้กรอง pickup/delivery ตาม schema ที่มีจริง
function ship_case_sql(PDO $pdo, string $alias='o'): string {
  $hasDeliveryOption = has_column($pdo, 'orders', 'delivery_option');
  if ($hasDeliveryOption) {
    return "(CASE
      WHEN COALESCE($alias.delivery_option,'')='pickup' THEN 'pickup'
      WHEN COALESCE($alias.delivery_option,'')='home'   THEN 'delivery'
      WHEN COALESCE($alias.pickup_code,'') <> '' THEN 'pickup'
      WHEN COALESCE($alias.tracking_no,'') <> '' OR COALESCE($alias.shipping,0) > 0 THEN 'delivery'
      WHEN ($alias.address LIKE '%รับเอง%' OR $alias.address LIKE '%หน้าร้าน%') THEN 'pickup'
      ELSE 'delivery'
    END)";
  }
  return "(CASE
    WHEN COALESCE($alias.pickup_code,'') <> '' THEN 'pickup'
    WHEN COALESCE($alias.tracking_no,'') <> '' OR COALESCE($alias.shipping,0) > 0 THEN 'delivery'
    WHEN ($alias.address LIKE '%รับเอง%' OR $alias.address LIKE '%หน้าร้าน%') THEN 'pickup'
    ELSE 'delivery'
  END)";
}

function detect_ship_method(PDO $pdo, array $row): string {
  if (isset($row['delivery_option'])) {
    return ($row['delivery_option'] === 'pickup') ? 'pickup' : 'delivery';
  }
  if (!empty($row['pickup_code'])) return 'pickup';
  if (!empty($row['tracking_no']) || (float)($row['shipping'] ?? 0) > 0) return 'delivery';
  $addr = (string)($row['address'] ?? '');
  if (mb_stripos($addr, 'รับเอง') !== false || mb_stripos($addr, 'หน้าร้าน') !== false) return 'pickup';
  return 'delivery';
}

// คำนวณยอดสุทธิสำหรับแสดงผล ถ้า grand_total ยังเป็นศูนย์จะคำนวณจากรายการสินค้าแทน
function grand_display(PDO $pdo, array $o): float {
  $gt = (float)($o['grand_total'] ?? 0);
  if ($gt > 0) return $gt;

  // คำนวณ fallback: subtotal - discount + shipping + (cover_fee_total ถ้ามี)
  $st = $pdo->prepare("SELECT COALESCE(SUM(qty*unit_price),0) FROM order_items WHERE order_id=?");
  $st->execute([(int)($o['id'] ?? 0)]);
  $subtotal = (float)$st->fetchColumn();

  $discount = (float)($o['discount'] ?? 0);
  $shipping = (float)($o['shipping'] ?? 0);
  $coverFee = 0.0;
  if (function_exists('has_column') && has_column($pdo,'orders','cover_fee_total')) {
    $coverFee = (float)($o['cover_fee_total'] ?? 0);
  }
  $calc = $subtotal - $discount + $shipping + $coverFee;
  return $calc > 0 ? $calc : 0.0;
}

/* =========================================================
   Labels
========================================================= */
$status_labels = [
  'unpaid'     => 'ยังไม่ชำระเงิน',
  'paid'       => 'ชำระเงินแล้ว รอการตรวจสอบ',
  'processing' => 'ตรวจสอบเรียบร้อย กำลังเตรียมสินค้า',
  'shipped'    => 'กำลังจัดส่ง',
  'completed'  => 'จัดส่งสำเร็จ',
  'cancelled'  => 'ยกเลิก',
];

/* =========================================================
   Perms + Housekeeping (คืนสต๊อกใบหมดอายุ)
========================================================= */
$canUpdate = has_perm('orders.update');
$canCreate = has_perm('orders.create') || $canUpdate;
$canDelete = has_perm('orders.delete');
$canAssign = has_perm('orders.assign');
$canVerify = has_perm('payments.verify');

// ยกเลิกบิลหมดอายุ + คืนสต๊อกก่อนเสมอ
try {
  $expired_orders_stmt = $pdo->query("SELECT id FROM orders WHERE status='unpaid' AND expires_at IS NOT NULL AND expires_at < NOW()");
  $expired_ids = $expired_orders_stmt->fetchAll(PDO::FETCH_COLUMN);
  if ($expired_ids) {
    $pdo->beginTransaction();
    foreach ($expired_ids as $order_id) {
      try {
        if (function_exists('ensure_stock_return')) ensure_stock_return($pdo, (int)$order_id);
        else if (function_exists('return_stock_for_order')) return_stock_for_order($pdo, (int)$order_id);
      } catch (Throwable $e) { /* ignore per-row */ }
    }
    $in_clause = implode(',', array_fill(0, count($expired_ids), '?'));
    $pdo->prepare("UPDATE orders SET status='cancelled', updated_at=NOW() WHERE id IN ($in_clause)")
        ->execute($expired_ids);
    $pdo->commit();
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
}

/* =========================================================
   Filters / paging
========================================================= */
$q         = trim($_GET['q'] ?? '');
$statusF   = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));

$shipF_raw = $_GET['ship'] ?? '';
$shipF     = strtolower(trim($shipF_raw));
if (!in_array($shipF, ['pickup','delivery'], true)) $shipF = '';

$per_key     = strtolower(trim($_GET['per_page'] ?? ''));
$per_options = ['20'=>20,'25'=>25,'50'=>50,'100'=>100,'all'=>'all'];
$per_page    = 20;
if (isset($per_options[$per_key])) $per_page = ($per_key==='all') ? null : (int)$per_options[$per_key];

// ค่าขนส่ง/ปก (ใช้กับ quick-create ในระบบบางแห่ง)
$ship_first      = (float)get_setting($pdo,'shipping_first', 50);
$ship_next       = (float)get_setting($pdo,'shipping_next', 10);
$cover_open      = (float)get_setting($pdo,'cover_open_block', 500);
$cover_extra     = (float)get_setting($pdo,'cover_over_rate', 2);
$cover_threshold = (int)  get_setting($pdo,'cover_first_n', 50);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* =========================================================
   POST actions
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); redirect('orders.php'); }

  /* ---------- Update single order ---------- */
  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash('error','ไม่พบเลขที่ออเดอร์'); redirect('orders.php?'.http_build_query($_GET)); }

    $hasEta       = has_column($pdo, 'orders', 'shipping_eta_days');
    $hasShippedAt = has_column($pdo, 'orders', 'shipped_at');

    $selCols = ['status','tracking_no'];
    if ($hasEta)       $selCols[] = 'shipping_eta_days';
    if ($hasShippedAt) $selCols[] = 'shipped_at';

    $stOld = $pdo->prepare('SELECT '.implode(',', $selCols).' FROM orders WHERE id=?');
    $stOld->execute([$id]);
    $old = $stOld->fetch(PDO::FETCH_ASSOC);
    if (!$old) { flash('error','ออเดอร์ไม่พบ'); redirect('orders.php?'.http_build_query($_GET)); }

    $old_status   = (string)($old['status'] ?? 'unpaid');
    $old_tracking = (string)($old['tracking_no'] ?? '');
    $old_eta      = $hasEta ? (int)($old['shipping_eta_days'] ?? 0) : 0;

    // สถานะการตรวจสลิปเดิม
    $stV = $pdo->prepare('SELECT is_verified FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1');
    $stV->execute([$id]);
    $old_verified = (int)($stV->fetchColumn() ?? 0);

    // ค่าที่ส่งมา
    $status    = $_POST['status'] ?? $old_status;
    $tracking  = trim($_POST['tracking_no'] ?? $old_tracking);
    $eta_post  = $hasEta ? max(0, min(60, (int)($_POST['shipping_eta_days'] ?? 0))) : 0;
    $verified  = isset($_POST['verified']) ? 1 : 0;
    $paid_cash = isset($_POST['cash']) ? 1 : 0;

    $allowed = ['unpaid','paid','processing','shipped','completed','cancelled'];
    if (!in_array($status,$allowed,true)) $status = $old_status;

    if (!$canUpdate) { $status = $old_status; $tracking = $old_tracking; $eta_post = $old_eta; }
    $doVerifyUpdate = $canVerify;

    $set = ['updated_at=NOW()']; $params = [];
    $status_changed   = ($status !== $old_status);
    $tracking_changed = ($tracking !== $old_tracking);
    $eta_changed      = ($hasEta && $eta_post > 0 && $eta_post !== $old_eta);

// --- STOCK TRANSITION HANDLING (centralized) ---
if ($status_changed) {
  if (function_exists('apply_stock_side_effects')) {
    // จัดการตัด/คืน/รีคอนไซล์แบบ idempotent ตาม old→new
    try { apply_stock_side_effects($pdo, $id, $old_status, $status, /*afterItemsEdited*/ false); } catch (Throwable $e) {}
  } else {
    // Fallback เดิม (ถ้าไม่มีฟังก์ชันใหม่)
    $oldPaid = is_paidish($old_status);
    $newPaid = is_paidish($status);
    if ($status === 'cancelled') {
      try {
        if (function_exists('ensure_stock_return')) ensure_stock_return($pdo, $id);
        else if (function_exists('return_stock_for_order')) return_stock_for_order($pdo, $id);
      } catch (Throwable $e) {}
    } elseif ($oldPaid && !$newPaid) {
      try { if (function_exists('return_stock_for_order')) return_stock_for_order($pdo, $id); } catch (Throwable $e) {}
    } elseif (!$oldPaid && $newPaid) {
      try { if (function_exists('deduct_stock_for_order'))  deduct_stock_for_order($pdo, $id); } catch (Throwable $e) {}
    }
  }
}
// ถ้ากรอกเลขพัสดุใหม่
    // ถ้ากรอกเลขพัสดุใหม่ → log + ดันเป็น shipped หากยัง
    if ($tracking_changed && $tracking !== '') {
      try {
        $pdo->prepare("INSERT INTO shipping_events(order_id,admin_id,event,note) VALUES (?,?, 'tracking_set', ?)")
            ->execute([$id, $ADMIN_ID, 'tracking: '.$tracking]);
      } catch (Throwable $ignore) {}
      if (function_exists('notify_tracking_assigned')) {
        notify_tracking_assigned($pdo, $id, $ADMIN_ID);
      }
      if (!in_array($status, ['shipped','completed','cancelled'], true)) {
        if ($hasShippedAt) {
          $pdo->prepare("UPDATE orders SET status='shipped', shipped_at=IFNULL(shipped_at,NOW()), updated_at=NOW() WHERE id=?")
              ->execute([$id]);
        } else {
          $pdo->prepare("UPDATE orders SET status='shipped', updated_at=NOW() WHERE id=?")
              ->execute([$id]);
        }
        try {
          $pdo->prepare("INSERT INTO shipping_events(order_id,admin_id,event,note) VALUES (?,?, 'status_changed', ?)")
              ->execute([$id, $ADMIN_ID, $status.'->shipped']);
        } catch (Throwable $ignore) {}
        if (function_exists('notify_order_status_change')) {
          notify_order_status_change($pdo, $id, $status, 'shipped', $ADMIN_ID);
        }
        $status = 'shipped';
      }
    }

    // verified: 0 -> 1 ดันเข้า processing หากยัง
    if ($doVerifyUpdate && ($verified || $paid_cash)) {
  if (!in_array($status, ['shipped','completed','cancelled'], true) && $status!=='processing') {
    $pdo->prepare("UPDATE orders SET status='processing', updated_at=NOW() WHERE id=?")->execute([$id]);
        // ทำสต๊อกให้ตรงกับการดันสถานะ (status -> processing)
    if (function_exists('apply_stock_side_effects')) {
      try { apply_stock_side_effects($pdo, $id, $status, 'processing', false); } catch (Throwable $e) {}
    }
    try {
      $pdo->prepare("INSERT INTO shipping_events(order_id,admin_id,event,note) VALUES (?,?, 'status_changed', ?)")
          ->execute([$id, $ADMIN_ID, $status.'->processing']);
    } catch (Throwable $ignore) {}
    if (function_exists('notify_order_status_change')) {
      notify_order_status_change($pdo, $id, $status, 'processing', $ADMIN_ID);
    }
    $status = 'processing';
  }
}
    flash('success','อัปเดตคำสั่งซื้อแล้ว');
    redirect('orders.php?'.http_build_query($_GET));
  }

  // ===== APPLY FIELD UPDATES (status / tracking / ETA) =====
$sets = ['updated_at=NOW()']; 
$vals = [];

if ($status_changed)        { $sets[] = 'status=?';            $vals[] = $status; }
if ($tracking_changed)      { $sets[] = 'tracking_no=?';       $vals[] = $tracking; }
if ($eta_changed)           { $sets[] = 'shipping_eta_days=?'; $vals[] = $eta_post; }

if (count($sets) > 1) {
  $vals[] = $id;
  $pdo->prepare("UPDATE orders SET ".implode(',', $sets)." WHERE id=?")->execute($vals);
}

// ===== UPSERT PAYMENTS (ตรวจสลิป / เงินสด) =====
if ($doVerifyUpdate) {
  $hasPayMethod  = has_column($pdo,'payments','method');
  $hasIsVerified = has_column($pdo,'payments','is_verified');

  $pay = $pdo->prepare("SELECT id FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1");
  $pay->execute([$id]);
  $pid = $pay->fetchColumn();

  $method = $paid_cash ? 'cash' : 'bank_transfer';

  if ($pid) {
    $pieces = []; $pvals = [];
    if ($hasPayMethod)  { $pieces[] = 'method=?';      $pvals[] = $method; }
    if ($hasIsVerified) { $pieces[] = 'is_verified=?'; $pvals[] = (int)$verified; }
    if ($pieces) {
      $pvals[] = $id;
      $pdo->prepare("UPDATE payments SET ".implode(',', $pieces)." WHERE order_id=?")->execute($pvals);
    }
  } else {
    $cols = ['order_id']; $qms = ['?']; $pvals = [$id];
    if ($hasPayMethod)  { $cols[]='method';      $qms[]='?'; $pvals[]=$method; }
    if ($hasIsVerified) { $cols[]='is_verified'; $qms[]='?'; $pvals[]=(int)$verified; }
    $pdo->prepare("INSERT INTO payments (".implode(',',$cols).") VALUES (".implode(',',$qms).")")->execute($pvals);
  }
}
  /* ---------- Upload/replace slip (หลายไฟล์ + เก็บของเดิม) ---------- */
  if ($action === 'upload_slip') {
    if (!$canVerify) { flash('error','ไม่มีสิทธิ์อัปโหลด/จัดการสลิป'); redirect('orders.php?'.http_build_query($_GET)); }
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) { flash('error','ไม่พบเลขที่ออเดอร์'); redirect('orders.php?'.http_build_query($_GET)); }

    $stPrev = $pdo->prepare('SELECT slip_path FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1');
    $stPrev->execute([$id]);
    $prevSlip = (string)($stPrev->fetchColumn() ?? '');

    $files = rearray_files($_FILES['slip'] ?? null);
    $paths = [];
    foreach ($files as $f) {
      $p = handle_upload($f, 'uploads/slips', ['jpg','jpeg','png','gif','webp','pdf']);
      if ($p) $paths[] = $p;
    }

    if ($paths) {
      try {
        $pdo->beginTransaction();

        if ($prevSlip !== '') {
          $chk = $pdo->prepare('SELECT 1 FROM payment_files WHERE order_id=? AND path=? LIMIT 1');
          $chk->execute([$id, $prevSlip]);
          if (!$chk->fetchColumn()) {
            $pdo->prepare('INSERT INTO payment_files (order_id, path, admin_id) VALUES (?,?,?)')
                ->execute([$id, $prevSlip, $ADMIN_ID]);
          }
        }

        $ins = $pdo->prepare('INSERT INTO payment_files (order_id, path, admin_id) VALUES (?,?,?)');
        foreach ($paths as $pp) { $ins->execute([$id, $pp, $ADMIN_ID]); }

        $last = end($paths);
        $stU = $pdo->prepare('UPDATE payments SET slip_path=? WHERE order_id=?');
        $stU->execute([$last, $id]);
        if ($stU->rowCount() === 0) {
          $pdo->prepare("INSERT INTO payments (order_id, method, slip_path) VALUES (?, 'bank_transfer', ?)")
              ->execute([$id, $last]);
        }

        $pdo->commit();
        flash('success','อัปโหลดสลิปเรียบร้อย (เพิ่ม '.count($paths).' ไฟล์ และเก็บของเก่าไว้แล้ว)');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error','อัปโหลดไม่สำเร็จ: '.$e->getMessage());
      }
    } else {
      flash('error','อัปโหลดสลิปไม่สำเร็จ');
    }
    redirect('orders.php?'.http_build_query($_GET));
  }

  /* ---------- Delete order ---------- */
  if ($action === 'delete') {
    if (!$canDelete) { flash('error','ไม่มีสิทธิ์ลบออเดอร์'); redirect('orders.php?'.http_build_query($_GET)); }
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0){ flash('error','ไม่พบเลขที่ออเดอร์'); redirect('orders.php?'.http_build_query($_GET)); }

    $st = $pdo->prepare("SELECT status FROM orders WHERE id=?");
    $st->execute([$id]);
    $stat = $st->fetchColumn();
    if (!$stat){ flash('error','ออเดอร์ไม่พบ'); redirect('orders.php?'.http_build_query($_GET)); }
    if (!in_array($stat,['unpaid','cancelled'], true)) {
      flash('error','อนุญาตลบเฉพาะออเดอร์สถานะ unpaid หรือ cancelled เท่านั้น');
      redirect('orders.php?'.http_build_query($_GET));
    }

    try{
      $pdo->beginTransaction();

      // คืนสต๊อกก่อนลบ หากเคยกัน/ตัดไว้
      if (in_array($stat, ['unpaid','paid','processing'], true)) {
        try {
          if (function_exists('ensure_stock_return')) ensure_stock_return($pdo, $id);
          else if (function_exists('return_stock_for_order')) return_stock_for_order($pdo, $id);
        } catch (Throwable $e) {}
      }

      $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM payments    WHERE order_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM shipping_events WHERE order_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$id]);

      $pdo->commit();
      flash('success','ลบออเดอร์ #'.$id.' แล้ว');
    }catch(Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash('error','ลบไม่สำเร็จ: '.$e->getMessage());
    }
    redirect('orders.php?'.http_build_query($_GET));
  }

  /* ---------- Bulk update ---------- */
  if ($action === 'bulk_update') {
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_filter(array_map('intval', $ids)));

    $new_status   = $_POST['new_status'] ?? '';
    $set_verified = isset($_POST['bulk_verified']) ? 1 : null;

    if (!$ids) { flash('error','ยังไม่ได้เลือกออเดอร์'); redirect('orders.php?'.http_build_query($_GET)); }

    $allowed = ['unpaid','paid','processing','shipped','completed','cancelled'];
    if (!$canUpdate) $new_status = '';
    if (!$canVerify) $set_verified = null;
    if ($new_status==='' && $set_verified===null) {
      flash('error','คุณไม่มีสิทธิ์ทำรายการแบบกลุ่มตามที่เลือก');
      redirect('orders.php?'.http_build_query($_GET));
    }

    $stGet  = $pdo->prepare("SELECT status FROM orders WHERE id=?");
    $stUpd1 = ($new_status && in_array($new_status,$allowed,true))
                ? $pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?")
                : null;

    $hasPayMethod  = has_column($pdo,'payments','method');
    $hasIsVerified = has_column($pdo,'payments','is_verified');

    $stPayUpd = $stPayIns = null;
    if ($set_verified !== null) {
      $up  = "UPDATE payments SET ";
      $pieces = [];
      if ($hasPayMethod)  $pieces[] = 'method=?';
      if ($hasIsVerified) $pieces[] = 'is_verified=?';
      $up .= implode(',', $pieces);
      $up .= ' WHERE order_id=?';
      $stPayUpd = $pdo->prepare($up);

      $cols = ['order_id'];
      $vals = ['?'];
      if ($hasPayMethod)  { $cols[] = 'method';     $vals[] = '?'; }
      if ($hasIsVerified) { $cols[] = 'is_verified'; $vals[] = '?'; }
      $stPayIns = $pdo->prepare('INSERT INTO payments ('.implode(',',$cols).') VALUES ('.implode(',',$vals).')');
    }

    $errors = [];

    try {
      $pdo->beginTransaction();

      foreach ($ids as $oid) {
        try {
          // 1) อัปเดตสถานะ (ถ้ามี) — จัดการคืน/ตัดก่อน แล้วค่อย UPDATE
          if ($stUpd1) {
            $stGet->execute([$oid]);
            $old = $stGet->fetchColumn();

            if ($old !== false && $old !== $new_status) {
              $oldPaid = is_paidish($old);
              $newPaid = is_paidish($new_status);

              if ($new_status === 'cancelled') {
                try {
                  if (function_exists('ensure_stock_return')) ensure_stock_return($pdo, $oid);
                  else if (function_exists('return_stock_for_order')) return_stock_for_order($pdo, $oid);
                } catch (Throwable $e) {}
              } elseif ($oldPaid && !$newPaid) {
                try { if (function_exists('return_stock_for_order')) return_stock_for_order($pdo, $oid); } catch (Throwable $e) {}
              } elseif (!$oldPaid && $newPaid) {
                if ($old === 'cancelled' && function_exists('deduct_stock_for_order')) {
                  try { deduct_stock_for_order($pdo, $oid); } catch (Throwable $e) {}
                }
              }

              $stUpd1->execute([$new_status, $oid]);

              try {
                $pdo->prepare("INSERT INTO shipping_events(order_id,admin_id,event,note) VALUES (?,?, 'status_changed', ?)")
                    ->execute([$oid, $ADMIN_ID, $old.'->'.$new_status]);
              } catch (Throwable $ignore) {}
              if (function_exists('notify_order_status_change')) {
                notify_order_status_change($pdo, $oid, $old, $new_status, $ADMIN_ID);
              }
            }
          }

          // 2) ทำ "ตรวจสลิปแล้ว" (ถ้ามีเลือก)
          if ($set_verified !== null) {
            $updParams = [];
            if ($hasPayMethod)  $updParams[] = 'bank_transfer';
            if ($hasIsVerified) $updParams[] = (int)$set_verified;
            $updParams[] = $oid;
            $stPayUpd->execute($updParams);

            if ($stPayUpd->rowCount() === 0) {
              $insParams = [$oid];
              if ($hasPayMethod)  $insParams[] = 'bank_transfer';
              if ($hasIsVerified) $insParams[] = (int)$set_verified;
              $stPayIns->execute($insParams);
            }

            if ($set_verified===1) {
              $cur = $pdo->prepare('SELECT status FROM orders WHERE id=?');
              $cur->execute([$oid]);
              $curStatus = (string)$cur->fetchColumn();
              if ($curStatus && !in_array($curStatus, ['processing','shipped','completed','cancelled'], true)) {
                $pdo->prepare("UPDATE orders SET status='processing', updated_at=NOW() WHERE id=?")->execute([$oid]);
                try {
                  $pdo->prepare("INSERT INTO shipping_events(order_id,admin_id,event,note) VALUES (?,?, 'status_changed', ?)")
                      ->execute([$oid, $ADMIN_ID, $curStatus.'->processing']);
                } catch (Throwable $ignore) {}
                if (function_exists('notify_order_status_change')) {
                  notify_order_status_change($pdo, $oid, $curStatus, 'processing', $ADMIN_ID);
                }
              }
            }
          }

        } catch (Throwable $e) {
          $errors[] = "#{$oid}: ".$e->getMessage();
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'TXN: '.$e->getMessage();
    }

    if ($errors) {
      flash('error', 'อัปเดตแบบกลุ่มมีบางรายการล้มเหลว:\n'.implode("\n", $errors));
    } else {
      flash('success','อัปเดตแบบกลุ่มเรียบร้อย');
    }
    redirect('orders.php?'.http_build_query($_GET));
  }
} // END POST

/* =========================================================
   Export CSV
========================================================= */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  $where=[]; $p=[];
  if ($q!==''){
    $where[]='(o.id LIKE ? OR o.pickup_code LIKE ? OR o.tracking_no LIKE ? OR o.fullname LIKE ? OR o.phone LIKE ? OR o.address LIKE ?)';
    $p=["%$q%","%$q%","%$q%","%$q%","%$q%","%$q%"]; 
  }
  if ($statusF!==''){ $where[]='o.status=?'; $p[]=$statusF; }
  if ($date_from!==''){ $where[]='DATE(o.created_at)>=?'; $p[]=$date_from; }
  if ($date_to!==''){ $where[]='DATE(o.created_at)<=?'; $p[]=$date_to; }

  $shipCase = ship_case_sql($pdo, 'o');
  if ($shipF==='pickup')   $where[] = "$shipCase = 'pickup'";
  if ($shipF==='delivery') $where[] = "$shipCase = 'delivery'";

  $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

  try { $pdo->exec('SET SESSION group_concat_max_len = 8192'); } catch(Throwable $e){}

    $sql = "SELECT 
          o.*,
          u.name AS uname,
          p.slip_path, p.is_verified, p.method AS pay_method,
          pf.slip_files, pf.slip_count,
          it.items_text, it.items_count, it.items_price,
          CASE
            WHEN o.grand_total IS NOT NULL AND o.grand_total > 0 THEN o.grand_total
            WHEN o.subtotal    IS NOT NULL AND o.subtotal    > 0 THEN (o.subtotal - o.discount + o.shipping)
            ELSE COALESCE(it.items_price,0) - COALESCE(o.discount,0) + COALESCE(o.shipping,0)
          END AS grand_display
        FROM orders o
        LEFT JOIN users u ON u.id=o.user_id
        LEFT JOIN (
          SELECT order_id,
                 GROUP_CONCAT(path ORDER BY id DESC SEPARATOR ',') AS slip_files,
                 COUNT(*) AS slip_count
          FROM payment_files
          GROUP BY order_id
        ) pf ON pf.order_id = o.id
        LEFT JOIN (
          SELECT x.* FROM payments x
          JOIN (SELECT order_id, MAX(id) AS last_id FROM payments GROUP BY order_id) t
               ON t.last_id = x.id
        ) p ON p.order_id = o.id
        LEFT JOIN (
          SELECT 
            oi.order_id,
            GROUP_CONCAT(CONCAT(COALESCE(pr.name, CONCAT('PID#', oi.product_id)), ' × ', oi.qty)
                         ORDER BY oi.id SEPARATOR ', ') AS items_text,
            SUM(oi.qty) AS items_count,
            SUM(oi.qty * COALESCE(NULLIF(oi.unit_price,0), NULLIF(oi.price,0), pr.price, 0)) AS items_price
          FROM order_items oi
          LEFT JOIN products pr ON pr.id = oi.product_id
          GROUP BY oi.order_id
        ) it ON it.order_id = o.id
        $whereSql
        ORDER BY o.id DESC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($p);

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename='.'"orders_export_'.date('Ymd_His').'.csv"');
  header('Pragma: no-cache'); header('Expires: 0');

  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8

  fputcsv($out, ['รหัสบิล','วันที่','วันหมดอายุ','ลูกค้า','ยอดสุทธิ','สถานะ','เลขพัสดุ','ETA(วัน)','มีสลิป','ตรวจแล้ว']);

  while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
    $status_th = $status_labels[$r['status']] ?? $r['status'];
    $hasSlip = (!empty($r['slip_path']) || (int)($r['slip_count'] ?? 0) > 0) ? 'Y' : '';
    fputcsv($out, [
  $r['id'],
  $r['created_at'],
  $r['expires_at'],
  $r['uname'] ?: $r['fullname'],
  $r['grand_display'],    // <<-- ใช้ตัวนี้
  $status_th,
  $r['tracking_no'],
  $r['shipping_eta_days'],
  $hasSlip,
  (int)$r['is_verified']
]);
  }
  fclose($out);
  exit;
}

/* =========================================================
   Summary numbers + list
========================================================= */
$counts = ['total'=>0,'unpaid'=>0,'paid'=>0,'processing'=>0,'shipped'=>0,'completed'=>0,'cancelled'=>0,'overdue'=>0];
$counts['total'] = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$stmt = $pdo->query('SELECT status, COUNT(*) c FROM orders GROUP BY status');
foreach($stmt as $r){ $counts[$r['status']] = (int)$r['c']; }
$counts['overdue'] = (int)$pdo->query("SELECT COUNT(*) FROM orders o WHERE o.status='unpaid' AND o.expires_at IS NOT NULL AND o.expires_at < NOW()")->fetchColumn();

$where=[]; $params=[];
if ($q!==''){
  $where[]='(o.id LIKE ? OR o.pickup_code LIKE ? OR o.tracking_no LIKE ? OR o.fullname LIKE ? OR o.phone LIKE ? OR o.address LIKE ?)';
  $params=["%$q%","%$q%","%$q%","%$q%","%$q%","%$q%"];
}
if ($statusF!==''){ $where[]='o.status=?'; $params[]=$statusF; }
if ($date_from!==''){ $where[]='DATE(o.created_at) >= ?'; $params[]=$date_from; }
if ($date_to!==''){ $where[]='DATE(o.created_at) <= ?'; $params[]=$date_to; }
$shipCase = ship_case_sql($pdo, 'o');
if ($shipF==='pickup')   $where[] = "$shipCase = 'pickup'";
if ($shipF==='delivery') $where[] = "$shipCase = 'delivery'";
$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

try { $pdo->exec('SET SESSION group_concat_max_len = 8192'); } catch(Throwable $e){}

$total_rows = $pdo->prepare("SELECT COUNT(*) FROM orders o $whereSql");
$total_rows->execute($params);
$total_rows = (int)$total_rows->fetchColumn();

if ($per_page) {
  $offset   = ($page-1)*$per_page;
  $limitSql = " LIMIT $per_page OFFSET $offset";
} else {
  $page     = 1; $offset   = 0; $limitSql = "";
}

$sql = "SELECT 
          o.*,
          u.name AS uname,
          p.slip_path, p.is_verified, p.method AS pay_method,
          pf.slip_files, pf.slip_count,
          it.items_text, it.items_count, it.items_price,
          CASE
            WHEN o.grand_total IS NOT NULL AND o.grand_total > 0 THEN o.grand_total
            WHEN o.subtotal    IS NOT NULL AND o.subtotal    > 0 THEN (o.subtotal - o.discount + o.shipping)
            ELSE COALESCE(it.items_price,0) - COALESCE(o.discount,0) + COALESCE(o.shipping,0)
          END AS grand_display
        FROM orders o
        LEFT JOIN users u   ON u.id=o.user_id
        LEFT JOIN (
          SELECT order_id,
                 GROUP_CONCAT(path ORDER BY id DESC SEPARATOR ',') AS slip_files,
                 COUNT(*) AS slip_count
          FROM payment_files
          GROUP BY order_id
        ) pf ON pf.order_id = o.id
        LEFT JOIN (
          SELECT x.* FROM payments x
          JOIN (SELECT order_id, MAX(id) AS last_id FROM payments GROUP BY order_id) t
               ON t.last_id = x.id
        ) p ON p.order_id = o.id
        LEFT JOIN (
          SELECT 
            oi.order_id,
            GROUP_CONCAT(CONCAT(COALESCE(pr.name, CONCAT('PID#', oi.product_id)), ' × ', oi.qty)
                         ORDER BY oi.id SEPARATOR ', ') AS items_text,
            SUM(oi.qty) AS items_count,
            /* --- ใช้สูตรเดียวกับใบเสร็จ --- */
            SUM(oi.qty * COALESCE(NULLIF(oi.unit_price,0), NULLIF(oi.price,0), pr.price, 0)) AS items_price
          FROM order_items oi
          LEFT JOIN products pr ON pr.id = oi.product_id
          GROUP BY oi.order_id
        ) it ON it.order_id = o.id
        $whereSql
        ORDER BY o.id DESC
        $limitSql";
$rows = $pdo->prepare($sql);
$rows->execute($params);

// โหลดสินค้า (บางระบบใช้ quick-create)
$prodStmt = $pdo->query("SELECT id, name, price, stock FROM products WHERE is_active=1 ORDER BY name");
$allProducts = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/header.php';
?>
<style>
body .container, .container{max-width:100%!important;width:100%!important;padding:0 1rem}
.input, select.input, button, .btn, option { font-family:system-ui,-apple-system,"Segoe UI",Tahoma,"Noto Sans Thai","Sarabun","Kanit",sans-serif!important; }
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.6rem;margin:1rem 0}
.summary .card{display:flex;align-items:center;justify-content:center;gap:.25rem;min-height:68px;border:1px solid #23234a;border-radius:.8rem;background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02))}
.summary .num{font-weight:700;font-size:1.1rem}
.summary .small{opacity:.85}
.toolbar2{display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-start;margin:.75rem 0}
.toolbar2 .filterbar{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}
.toolbar2 .input{height:32px;padding:.25rem .5rem;font-size:14px}
.toolbar2 .grow{flex:1 1 340px;min-width:280px}
.toolbar2 .sm{width:150px}
.toolbar2 .md{width:170px}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th, .table td{vertical-align:top;padding:.5rem .6rem;text-align:left}
.table th{font-weight:600;background:#001e5aff;border-bottom:2px solid #e5e7eb;text-align:left}
.table td{border-bottom:1px solid #e5e7eb}
.badge{display:inline-block;padding:.15rem .45rem;border-radius:.5rem;background:#23234a;color:#fff;font-size:.85rem}
.badge.ok{background:#1e6f3a}
.badge.warn{background:#8a6d1d}
.amount{margin-top:.35rem;font-weight:700;font-size:1.05rem}
.muted{opacity:.75}
.btn{white-space:nowrap}
.btn.xs{padding:.15rem .45rem;min-height:auto;font-size:.85rem}
.btn.danger{border-color:#c53030;color:#c53030;background:#fff}.btn.danger:hover{background:#ffecec}
.slip-stack{display:flex;flex-direction:column;gap:.4rem;align-items:flex-start}
.manage-cell{min-width:500px}
.row-actions{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem}
.row-update{display:grid;grid-template-columns:1fr auto;grid-template-rows:auto auto;gap:.45rem;align-items:center}
.row-update select[name="status"]{grid-row:1;grid-column:1}
.row-update input[name="tracking_no"]{grid-row:2;grid-column:1}
.row-update label{grid-row:1 / span 2;grid-column:2;align-self:center}
.row-bottom{margin-top:.45rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
@media (max-width:900px){
  .table th, .table td { padding:.4rem .3rem; font-size:14px }
  .table th:nth-child(3), .table td:nth-child(3){display:none}
  .manage-cell{min-width:300px}
  .row-actions, .row-update, .row-bottom{display:flex;flex-direction:column;align-items:stretch;gap:.5rem;width:100%}
  .row-update > *{grid-row:auto;grid-column:auto}
  .row-actions{margin-bottom:.8rem}
}
/* Quick-Create Panel (ถ้ามีใช้งาน) */
details.qc{border:1px solid #cfd7ee;border-radius:14px;background:linear-gradient(#f8fbff,#fff)}
details.qc > summary{cursor:pointer;list-style:none;user-select:none;font-weight:700;font-size:16px;padding:.8rem 1rem!important;border-bottom:1px solid transparent}
details.qc[open] > summary{border-bottom-color:#e7ecfb}
details.qc .card{border:0;background:transparent;padding:1rem!important}
</style>

<div class="summary">
  <div class="card"><div class="num"><?= (int)$counts['total'] ?></div><div class="small">ทั้งหมด</div></div>
  <div class="card"><div class="num"><?= (int)$counts['unpaid'] ?></div><div class="small">ยังไม่จ่าย</div></div>
  <div class="card"><div class="num"><?= (int)$counts['paid'] ?></div><div class="small">โอนแล้ว</div></div>
  <div class="card"><div class="num"><?= (int)$counts['processing'] ?></div><div class="small">เตรียมส่ง</div></div>
  <div class="card"><div class="num"><?= (int)$counts['shipped'] ?></div><div class="small">ส่งแล้ว</div></div>
  <div class="card"><div class="num"><?= (int)$counts['completed'] ?></div><div class="small">สำเร็จ</div></div>
  <div class="card"><div class="num"><?= (int)$counts['cancelled'] ?></div><div class="small">ยกเลิก</div></div>
  <div class="card"><div class="num"><?= (int)$counts['overdue'] ?></div><div class="small">หมดอายุ</div></div>
</div>

<div class="toolbar2">
  <form method="get" class="filterbar">
    <input class="input grow" type="text" name="q" placeholder="ค้นหาเลขบิล/รหัสรับของ/เลขพัสดุ/ชื่อ/ที่อยู่/โทร..." value="<?= htmlspecialchars($q) ?>">
    <input class="input sm" type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    <input class="input sm" type="date" name="date_to"   value="<?= htmlspecialchars($date_to) ?>">
    <select class="input md" name="status">
      <option value="">ทุกสถานะ</option>
      <?php foreach (['unpaid','paid','processing','shipped','completed','cancelled'] as $val): ?>
        <option value="<?= $val ?>" <?= $statusF===$val?'selected':'' ?>><?= htmlspecialchars($status_labels[$val] ?? $val) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input md" name="ship">
      <option value="">รับ/ส่ง ทั้งหมด</option>
      <option value="pickup"   <?= $shipF==='pickup'?'selected':'' ?>>มารับเอง</option>
      <option value="delivery" <?= $shipF==='delivery'?'selected':'' ?>>จัดส่งพัสดุ</option>
    </select>
    <label style="display:flex;align-items:center;gap:.35rem">
      <span class="muted">แสดงต่อหน้า:</span>
      <select class="input sm" name="per_page" onchange="this.form.page && (this.form.page.value=1); this.form.submit()">
        <option value="20"  <?= ($per_key===''||$per_key==='20') ?'selected':'' ?>>20 (ปกติ)</option>
        <option value="25"  <?= $per_key==='25'  ?'selected':'' ?>>25</option>
        <option value="50"  <?= $per_key==='50'  ?'selected':'' ?>>50</option>
        <option value="100" <?= $per_key==='100' ?'selected':'' ?>>100</option>
        <option value="all" <?= $per_key==='all' ?'selected':'' ?>>ทั้งหมด</option>
      </select>
    </label>
    <input type="hidden" name="page" value="<?= (int)$page ?>">
    <button class="btn" type="submit">ค้นหา</button>
    <a class="btn outline" href="orders.php">รีเซ็ต</a>
    <a class="btn outline" href="orders.php?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>">ดาวน์โหลด CSV</a>
  </form>

  <span class="break"></span>

  <form id="bulkForm" method="post" action="orders.php" class="filterbar">
    <?= csrf_field() ?><input type="hidden" name="action" value="bulk_update">
    <select class="input md" name="new_status" <?= $canUpdate?'':'disabled' ?>>
      <option value="">— เปลี่ยนสถานะ —</option>
      <?php foreach (['unpaid','paid','processing','shipped','completed','cancelled'] as $s): ?>
        <option value="<?= $s ?>"><?= htmlspecialchars($status_labels[$s] ?? $s) ?></option>
      <?php endforeach; ?>
    </select>
    <label style="display:flex;align-items:center;gap:.35rem">
      <input type="checkbox" name="bulk_verified" <?= $canVerify?'':'disabled' ?>> ทำเครื่องหมาย “ตรวจสลิปแล้ว”
    </label>
    <button class="btn" type="submit" <?= ($canUpdate||$canVerify)?'':'disabled' ?>>ใช้กับที่เลือก</button>
  </form>
</div>

<!-- รายการคำสั่งซื้อ -->
<h2>รายการคำสั่งซื้อ</h2>
<div class="card" style="margin-bottom:1rem;background:#f9fafb">
  <h4><i class="icon-download"></i> ส่งออกรายงาน (Export Reports)</h4>
  <div style="display:flex;flex-wrap:wrap;gap:.5rem">
    <a href="export_handler.php?report=booking_summary" target="_blank" class="btn" style="background-color:#4f46e5;color:white"><i class="icon-star"></i> สรุปยอดจอง</a>
    <a href="export_handler.php?report=shipping" target="_blank" class="btn outline"><i class="icon-truck"></i> รายการจัดส่ง</a>
    <a href="export_handler.php?report=pickup" target="_blank" class="btn outline"><i class="icon-store"></i> รายการรับเอง</a>
    <a href="export_handler.php?report=inventory" target="_blank" class="btn outline"><i class="icon-box"></i> สรุปสต๊อกสินค้า</a>
    <a href="export_handler.php?report=paid" target="_blank" class="btn outline" style="border-color:#16a34a;color:#15803d"><i class="icon-check"></i> ออเดอร์ที่จ่ายแล้ว</a>
    <a href="export_handler.php?report=unpaid" target="_blank" class="btn outline" style="border-color:#fbbf24;color:#b45309"><i class="icon-clock"></i> ออเดอร์ที่ยังไม่จ่าย</a>
    <a href="/admin/reports/sales.php" target="_blank" class="btn outline" style="border-color:#5b0707;color:#f10404"><i class="icon-cancel"></i> รายงานยอดขาย</a>
  </div>
  <div class="small" style="opacity:.8;margin-top:.5rem">*คลิกเพื่อดาวน์โหลดรายงานที่คุณต้องการในรูปแบบไฟล์ CSV (เปิดใน Excel ได้)</div>
</div>

<div class="table-wrap">
  <table class="table">
    <tr>
      <th><input type="checkbox" id="checkAll"></th>
      <th>#</th><th>วันที่</th><th>ลูกค้า</th>
      <th>วิธีรับ</th>
      <th>สินค้า (ชิ้น)</th>
      <th>สลิป</th><th>จัดการ</th>
    </tr>
    <?php foreach ($rows as $row): ?>
    <tr>
      <td><input type="checkbox" name="ids[]" form="bulkForm" value="<?= (int)$row['id'] ?>"></td>
      <td>#<?= (int)$row['id'] ?></td>
      <td class="left small">
        <?= htmlspecialchars($row['created_at']) ?>
        <?php if(!empty($row['expires_at'])): ?><div>หมดอายุ: <?= htmlspecialchars($row['expires_at']) ?></div><?php endif; ?>
      </td>
      <td class="left">
        <?= htmlspecialchars($row['uname'] ?: $row['fullname']) ?>
        <?php if (!empty($row['phone'])): $tel = preg_replace('/\D+/', '', $row['phone']); ?>
          <div class="muted" style="margin-top:.25rem">
            โทร: <a href="tel:<?= htmlspecialchars($tel) ?>"><?= htmlspecialchars($row['phone']) ?></a>
            <button type="button" class="btn outline xs js-copy" data-copy="<?= htmlspecialchars($row['phone']) ?>">คัดลอก</button>
          </div>
        <?php endif; ?>
      </td>
      <td>
        <?php $shipMethod = detect_ship_method($pdo, $row); ?>
        <?php if ($shipMethod === 'pickup'): ?>
          <span class="badge ok">มารับเอง</span>
        <?php else: ?>
          <span class="badge warn">ส่งพัสดุ</span>
        <?php endif; ?>
      </td>
      <td class="left small">
        <?php if (!empty($row['items_text'])): ?>
          <div><?= htmlspecialchars($row['items_text']) ?></div>
          <div class="muted">รวม <?= (int)$row['items_count'] ?> ชิ้น</div>
        <?php else: ?><div class="muted">—</div><?php endif; ?>
        <div class="amount">
  ยอดสุทธิ: ฿<?= number_format(
      (float)($row['grand_display'] ?? 0) > 0
        ? (float)$row['grand_display']
        : ( (float)($row['items_price'] ?? 0) - (float)($row['discount'] ?? 0) + (float)($row['shipping'] ?? 0) )
    , 2) ?>
</div>
      </td>
      <td>
        <div class="slip-stack">
          <?php
            $files = [];
            if (!empty($row['slip_files'])) {
              $files = array_filter(array_map('trim', explode(',', (string)$row['slip_files'])));
            }
            if (!empty($row['slip_path']) && !in_array($row['slip_path'], $files, true)) {
              $files[] = $row['slip_path'];
            }
          ?>
          <?php if ($files): ?>
            <?php foreach($files as $i => $f): ?>
              <button type="button" class="btn outline js-slip" data-src="../<?= htmlspecialchars($f) ?>">
                ดูสลิป<?= count($files)>1 ? ' (#'.($i+1).')' : '' ?>
              </button>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="badge">ไม่มีสลิป</span>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" action="orders.php?<?= http_build_query($_GET) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="upload_slip">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <input class="input" type="file" name="slip[]" multiple accept="image/*,application/pdf" style="max-width:240px" <?= $canVerify?'':'disabled' ?>><br><br>
            <button class="btn" type="submit" <?= $canVerify?'':'disabled' ?>>อัปโหลดเพิ่ม</button>
            <?php if(!empty($row['pay_method']) && $row['pay_method']==='cash'): ?>
              <span class="badge ok">เงินสด</span>
            <?php endif; ?>
          </form>
        </div>
      </td>
      <td class="left manage-cell">
        <div class="row-actions">
          <a class="btn outline" target="_blank" href="receipt.php?id=<?= (int)$row['id'] ?>">ใบเสร็จ</a>
          <a class="btn outline" target="_blank" href="ship_pack.php?id=<?= (int)$row['id'] ?>">ใบแพ็ค/ใบจ่าหน้า</a>
          <a class="btn outline" href="order_edit.php?id=<?= (int)$row['id'] ?>">แก้ไขออเดอร์</a>
        </div>

        <form id="upd-<?= (int)$row['id'] ?>" method="post" action="orders.php?<?= http_build_query($_GET) ?>" class="row-update">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <select name="status" class="input" <?= $canUpdate?'':'disabled' ?>>
            <?php foreach (['unpaid','paid','processing','shipped','completed','cancelled'] as $s): ?>
              <option value="<?= $s ?>" <?= $row['status']===$s?'selected':'' ?>><?= htmlspecialchars($status_labels[$s] ?? $s) ?></option>
            <?php endforeach; ?>
          </select>
          <input class="input" name="tracking_no" placeholder="เลขพัสดุ" value="<?= htmlspecialchars($row['tracking_no'] ?? '') ?>" <?= $canUpdate?'':'disabled' ?>>
          <label style="display:flex;align-items:center;gap:.4rem;white-space:nowrap">
            <input type="checkbox" name="verified" <?= !empty($row['is_verified'])?'checked':'' ?> <?= $canVerify?'':'disabled' ?>> ตรวจสลิป
            <input type="checkbox" name="cash" <?= (isset($row['pay_method']) && $row['pay_method']==='cash') ? 'checked' : '' ?> <?= $canVerify ? '' : 'disabled' ?>> เงินสด
          </label>
        </form>

        <div class="row-bottom">
          <button class="btn" type="submit" form="upd-<?= (int)$row['id'] ?>" <?= ($canUpdate||$canVerify)?'':'disabled' ?>>บันทึก</button>
          <?php if ($canDelete): ?>
            <form method="post" action="orders.php?<?= http_build_query($_GET) ?>" onsubmit="return confirm('ลบออเดอร์ #<?= (int)$row['id'] ?> ?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <button class="btn danger" type="submit">ลบออเดอร์</button>
            </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php
$total_pages = $per_page ? max(1, (int)ceil($total_rows / $per_page)) : 1;
if ($per_page && $total_pages > 1): ?>
  <div class="pagination" style="margin-top:1rem;display:flex;gap:.4rem;flex-wrap:wrap">
    <?php for($i=1;$i<=$total_pages;$i++):
      $qs = $_GET; $qs['page']=$i; ?>
      <a class="btn <?= $i===$page?'':'outline' ?>" href="orders.php?<?= http_build_query($qs) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
<?php endif; ?>

<!-- Slip Modal -->
<style>
#slipModal{position:fixed;inset:0;background:rgba(0,0,0,.8);display:none;align-items:center;justify-content:center;z-index:9999}
#slipModal.open{display:flex}
#slipModal .box{position:relative;background:#141432;border:1px solid #23234a;padding:.5rem;border-radius:.8rem;max-width:92vw;max-height:92vh}
#slipModal img{max-width:90vw;max-height:85vh;display:block;border-radius:.5rem}
#slipModal .close{position:absolute;top:.25rem;right:.25rem;background:#0008;border:1px solid #fff3;color:#fff;border-radius:.5rem;font-size:18px;padding:.2rem .45rem;cursor:pointer}
</style>
<div id="slipModal" aria-hidden="true">
  <div class="box" role="dialog" aria-modal="true" aria-label="สลิปโอนเงิน">
    <button class="close" type="button" aria-label="ปิด">&times;</button>
    <img id="slipImg" src="" alt="สลิปโอนเงิน">
  </div>
</div>

<script>
// Select all
const checkAll = document.getElementById('checkAll');
if (checkAll){
  checkAll.addEventListener('change', (e)=>{
    document.querySelectorAll('input[name="ids[]"]').forEach((ch)=>{ ch.checked = e.target.checked; });
  });
}

// Copy phone
document.querySelectorAll('.js-copy').forEach((btn)=>{
  btn.addEventListener('click', async ()=>{
    try{
      await navigator.clipboard.writeText(btn.dataset.copy||'');
      btn.textContent = 'คัดลอกแล้ว';
      setTimeout(()=> btn.textContent='คัดลอก', 1200);
    }catch(e){ alert('คัดลอกไม่สำเร็จ'); }
  });
});

// Slip modal + PDF handler
(function(){
  const modal = document.getElementById('slipModal');
  const img   = document.getElementById('slipImg');
  const closeBtn = modal ? modal.querySelector('.close') : null;

  function openImgModal(src){
    if(!modal || !img || !src) return;
    img.onerror = ()=> alert('ไม่พบรูปสลิป');
    img.src = src;
    modal.classList.add('open');
  }
  function closeModal(){
    if(!modal || !img) return;
    img.onerror = null;
    img.removeAttribute('src');
    modal.classList.remove('open');
  }

  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('.js-slip');
    if (!btn) return;
    e.preventDefault();
    const src = btn.getAttribute('data-src') || btn.getAttribute('href') || '';
    if (!src) return;
    if (src.toLowerCase().endsWith('.pdf')) {
      window.open(src, '_blank');
    } else {
      openImgModal(src);
    }
  });

  closeBtn && closeBtn.addEventListener('click', closeModal);
  modal && modal.addEventListener('click', (e)=>{ if(e.target===modal) closeModal(); });
  window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeModal(); });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
