<?php
/* ===========================
 * includes/functions.php  (DROP-IN)
 * =========================== */

/** ---------- Start session (if not started) ---------- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/** ---------- mbstring polyfills ---------- */
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
}
if (!function_exists('mb_stripos')) {
    function mb_stripos($haystack, $needle, $offset = 0, $encoding = null) {
        return stripos((string)$haystack, (string)$needle, (int)$offset);
    }
}

/** ---------- Small helpers ---------- */
if (!function_exists('jenc')) {
    function jenc($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}

/** ---------- Schema helpers (safe) ---------- */
if (!function_exists('has_table')) {
    function has_table(PDO $pdo, string $table): bool {
        try {
            $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('has_column')) {
    function has_column(PDO $pdo, string $table, string $col): bool {
        try {
            $sql = "SHOW COLUMNS FROM `" . str_replace("`","``",$table) . "` LIKE ?";
            $stmt = $pdo->prepare($sql); $stmt->execute([$col]);
            return (bool)$stmt->fetch();
        } catch (Throwable $e) { return false; }
    }
}

/** ---------- CSRF ---------- */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('csrf_field')) {
    function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.htmlspecialchars(csrf_token()).'">'; }
}
if (!function_exists('csrf_check')) {
    function csrf_check($token): bool {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}

/** ---------- Helpers / Flash / Auth ---------- */
if (!function_exists('redirect')) { function redirect(string $path){ header('Location: ' . $path); exit; } }
if (!function_exists('flash')) {
    function flash(string $key, ?string $value=null){
        if (!isset($_SESSION['flash'])) $_SESSION['flash'] = [];
        if ($value===null){ $v=$_SESSION['flash'][$key]??null; unset($_SESSION['flash'][$key]); return $v; }
        $_SESSION['flash'][$key]=$value;
    }
}
if (!function_exists('current_user'))   { function current_user(){ return $_SESSION['user'] ?? null; } }
if (!function_exists('is_logged_in'))   { function is_logged_in(): bool { return !empty($_SESSION['user']); } }
if (!function_exists('is_admin'))       { function is_admin(): bool { return is_logged_in() && (($_SESSION['user']['role']??'')==='admin'); } }
if (!function_exists('require_login'))  { function require_login(){ if(!is_logged_in()){ flash('error','โปรดเข้าสู่ระบบ'); redirect(BASE_URL.'/login.php'); } } }
if (!function_exists('require_admin'))  { function require_admin(){ if(!is_admin()){ flash('error','เฉพาะผู้ดูแลระบบเท่านั้น'); redirect(BASE_URL.'/login.php'); } } }
if (!function_exists('format_currency')){ function format_currency($n){ return number_format((float)$n,2); } }

if (!function_exists('get_setting')) {
    function get_setting(PDO $pdo,string $key,$default=''){
        try {
            $st=$pdo->prepare('SELECT value FROM settings WHERE `key`=?');
            $st->execute([$key]); $r=$st->fetch();
            return $r?$r['value']:$default;
        } catch (Throwable $e) { return $default; }
    }
}

/** ---------- ชุดสถานะที่ถือว่า “ต้องตัดสต๊อก” ---------- */
if (!function_exists('is_paidish')) {
    function is_paidish(?string $s): bool {
        $s = strtolower((string)$s);
        return in_array($s, ['paid','processing','shipped','completed'], true);
    }
}

/** ---------- Path helpers & Upload ---------- */
if (!function_exists('to_web_path')) {
    function to_web_path(string $path): string {
        $path = str_replace('\\','/',$path);
        $root = str_replace('\\','/', dirname(__DIR__));
        if (strpos($path, $root) === 0) $path = substr($path, strlen($root));
        return ltrim($path, '/');
    }
}
if (!function_exists('handle_upload')) {
    function handle_upload(array $file, string $destDir, array $allow=['jpg','jpeg','png','gif','webp','pdf']): ?string {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                flash('error', 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์: ' . ($file['error'] ?? 'Unknown error'));
            }
            return null;
        }
        $maxSize = 5 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxSize) { flash('error','ไฟล์ใหญ่เกินกำหนด (สูงสุด 5MB)'); return null; }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext,$allow,true)) { flash('error','ไฟล์ไม่ถูกชนิด (อนุญาต: '.implode(', ',$allow).')'); return null; }

        if (!preg_match('~^([A-Za-z]:)?[\\\\/]~',$destDir)) $destDir = dirname(__DIR__).'/'.ltrim($destDir,'/');
        if (!is_dir($destDir) && !@mkdir($destDir,0775,true) && !is_dir($destDir)) {
            flash('error','สร้างโฟลเดอร์ไม่สำเร็จ: '.$destDir); return null;
        }

        $safe = preg_replace('/[^a-zA-Z0-9._-]/','_',basename($file['name']));
        $target = rtrim($destDir,'/').'/'.time().'_'.$safe;
        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            flash('error', 'ไม่สามารถย้ายไฟล์ที่อัปโหลดได้'); return null;
        }
        return to_web_path($target);
    }
}

/** ---------- Polyfill ---------- */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return $n==='' || strncmp($h,$n,strlen($n))===0; }
}

/* ==========================================================
 *        GROUP-AWARE HELPERS (master/child stock groups)
 * ========================================================== */

/** คืน product_id ที่ “ควรนับผลกระทบสต๊อกจริง” (ตัวแม่ถ้ามี, ไม่งั้นตัวเอง) */
if (!function_exists('stock_target_id_of_product')) {
    function stock_target_id_of_product(PDO $pdo, int $product_id): int {
        try{
            $st = $pdo->prepare("SELECT stock_group, COALESCE(is_stock_master,0) m FROM products WHERE id=?");
            $st->execute([$product_id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return $product_id;
            $g = trim((string)($r['stock_group'] ?? ''));
            $m = (int)($r['m'] ?? 0);
            if ($g!=='' && $m!==1) {
                $q = $pdo->prepare("SELECT id FROM products
                                    WHERE stock_group=? AND COALESCE(is_stock_master,0)=1
                                    ORDER BY id LIMIT 1");
                $q->execute([$g]);
                $mid = (int)($q->fetchColumn() ?? 0);
                return $mid>0 ? $mid : $product_id;
            }
            return $product_id;
        }catch(Throwable $e){ return $product_id; }
    }
}

/** สต๊อกที่ “มีผลจริง” ของสินค้า (อิงตัวแม่เสมอถ้ามี) */
if (!function_exists('effective_stock_of')) {
    function effective_stock_of(PDO $pdo, int $product_id): int {
        $hasGroup  = has_column($pdo,'products','stock_group');
        $hasMaster = has_column($pdo,'products','is_stock_master');
        if (!$hasGroup || !$hasMaster) {
            $st = $pdo->prepare("SELECT stock FROM products WHERE id=?");
            $st->execute([$product_id]); return (int)($st->fetchColumn() ?? 0);
        }
        $sql = "SELECT 
                  CASE 
                    WHEN COALESCE(p.stock_group,'')<>'' AND COALESCE(p.is_stock_master,0)<>1
                      THEN COALESCE((SELECT m.stock FROM products m
                                     WHERE m.stock_group=p.stock_group
                                       AND COALESCE(m.is_stock_master,0)=1
                                     ORDER BY m.id LIMIT 1),
                                    p.stock)
                    ELSE p.stock
                  END AS eff_stock
                FROM products p WHERE p.id=?";
        $st = $pdo->prepare($sql); $st->execute([$product_id]);
        return (int)($st->fetchColumn() ?? 0);
    }
}

/* ==========================================================
 *            INVENTORY / RESERVED (GROUP-AWARE)
 * ========================================================== */

/** กันสินค้าที่อยู่ในบิลสถานะ 'unpaid' + ยังไม่หมดอายุ (รวมเข้าที่ “ตัวแม่”) */
if (!function_exists('reserved_qty_map')) {
    function reserved_qty_map(PDO $pdo): array {
        try {
            $sql = "SELECT
                        CASE
                          WHEN COALESCE(p.stock_group,'')<>'' AND COALESCE(p.is_stock_master,0)<>1
                            THEN COALESCE(
                                   (SELECT m.id FROM products m
                                    WHERE m.stock_group=p.stock_group
                                      AND COALESCE(m.is_stock_master,0)=1
                                    ORDER BY m.id LIMIT 1),
                                   p.id)
                          ELSE p.id
                        END AS target_id,
                        SUM(oi.qty) AS reserved
                    FROM order_items oi
                    JOIN orders o  ON o.id = oi.order_id
                    JOIN products p ON p.id = oi.product_id
                    WHERE o.status = 'unpaid'
                      AND (o.expires_at IS NULL OR o.expires_at > NOW())
                    GROUP BY target_id";
            $map = [];
            foreach ($pdo->query($sql) as $r) {
                $map[(int)$r['target_id']] = (int)$r['reserved'];
            }
            return $map;
        } catch (Throwable $e) { return []; }
    }
}

/** ภาพรวมสต๊อก/ติดจอง/พร้อมขาย ต่อ product_id (group-aware) */
if (!function_exists('product_inventory')) {
    function product_inventory(PDO $pdo): array {
        $resMap = reserved_qty_map($pdo);
        $out = [];
        foreach ($pdo->query("SELECT id,name FROM products") as $p) {
            $id = (int)$p['id'];
            $eff = effective_stock_of($pdo, $id);
            $tgt = stock_target_id_of_product($pdo, $id);
            $res = (int)($resMap[$tgt] ?? 0);
            $out[$id] = [
                'id'        => $id,
                'name'      => (string)$p['name'],
                'stock'     => (int)$eff,                 // สต๊อกจริง (อิงตัวแม่)
                'reserved'  => $res,                      // ติดจองรวมที่ตัวแม่
                'available' => max(0, $eff - $res),       // พร้อมขายตอนนี้
            ];
        }
        return $out;
    }
}

/** แจ้งเตือนสินค้าใกล้หมด (ยึด available ของ group-aware) */
if (!function_exists('check_low_stock')) {
    function check_low_stock(PDO $pdo): void {
        $th = (int)get_setting($pdo,'low_stock_threshold',5);
        if ($th <= 0) return;
        $inv = product_inventory($pdo);
        $low = array_filter($inv, fn($x)=> $x['available'] <= $th);
        if (!$low) return;
        $msg = [];
        foreach ($low as $x) $msg[] = $x['name'].' (คงเหลือ '.$x['available'].', ติดจอง '.$x['reserved'].')';
        flash('error','สินค้าใกล้หมด: '.implode(', ', $msg));
    }
}

/* ==========================================================
 *             STOCK EVENTS & IDEMPOTENT APPLY
 * ========================================================== */

if (!function_exists('shipping_event_exists')) {
    function shipping_event_exists(PDO $pdo, int $order_id, string $event): bool {
        if (!has_table($pdo,'shipping_events')) return false;
        try {
            $st=$pdo->prepare("SELECT 1 FROM shipping_events WHERE order_id=? AND event=? LIMIT 1");
            $st->execute([$order_id,$event]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

/** บันทึก event (เฉพาะเมื่อมีตาราง) */
if (!function_exists('log_shipping_event')) {
    function log_shipping_event(PDO $pdo, int $order_id, string $event, $note=''): void {
        if (!has_table($pdo,'shipping_events')) return;
        try{
            $admin_id = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
            if (is_array($note)) { $note = jenc($note); }
            $pdo->prepare("INSERT INTO shipping_events(order_id,admin_id,event,note,created_at)
                           VALUES (?,?,?,?, NOW())")
                ->execute([$order_id, $admin_id, $event, (string)$note]);
        }catch(Throwable $e){ /* ignore */ }
    }
}

/** รวมจำนวน qty จาก events (note เป็น JSON map: pid=>qty) */
if (!function_exists('sum_event_qty')) {
    function sum_event_qty(PDO $pdo, int $order_id, array $event_names): array {
        if (!has_table($pdo,'shipping_events') || !$event_names) return [];
        $in  = implode(',', array_fill(0, count($event_names), '?'));
        $sql = "SELECT note FROM shipping_events WHERE order_id=? AND event IN ($in)";
        $st  = $pdo->prepare($sql);
        $st->execute(array_merge([$order_id], $event_names));
        $sum = [];
        while($r = $st->fetch(PDO::FETCH_ASSOC)){
            $note = (string)($r['note'] ?? '');
            $data = json_decode($note, true);
            if (!is_array($data)) continue;
            foreach ($data as $pid => $qty){
                if (!is_numeric($pid) || !is_numeric($qty)) continue;
                $pid = (int)$pid; $qty = (int)$qty;
                $sum[$pid] = ($sum[$pid] ?? 0) + max(0, $qty);
            }
        }
        return $sum;
    }
}

/** ตัดสต๊อก (idempotent) */
if (!function_exists('deduct_stock_for_order')) {
    function deduct_stock_for_order(PDO $pdo, int $order_id): bool {
        try{
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) $pdo->beginTransaction();

            $targets  = stock_targets_for_order($pdo, $order_id);
            if (!$targets) { if ($ownTx) $pdo->commit(); return true; }

            $prevDed = sum_event_qty($pdo, $order_id, ['stock_deducted']);

            $need = [];
            foreach ($targets as $pid => $qty) {
                $done  = $prevDed[$pid] ?? 0;
                $delta = max(0, $qty - $done);
                if ($delta > 0) $need[$pid] = $delta;
            }

            if ($need) {
                $stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                foreach ($need as $pid => $delta) { $stmt->execute([$delta, $pid]); }
                log_shipping_event($pdo, $order_id, 'stock_deducted', $need);
            } else {
                log_shipping_event($pdo, $order_id, 'stock_deducted_skip', 'already done');
            }

            if (has_column($pdo,'orders','stock_locked')) {
                $pdo->prepare("UPDATE orders SET stock_locked=1, updated_at=NOW() WHERE id=?")->execute([$order_id]);
            }

            if ($ownTx) $pdo->commit();
            return true;
        }catch(Throwable $e){
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
}

/** รวมจำนวนที่จะ “กระทบสต๊อกจริง” ต่อใบ (รวบของรองไปรวมที่ตัวแม่) */
if (!function_exists('stock_targets_for_order')) {
    function stock_targets_for_order(PDO $pdo, int $order_id): array {
        try{
            $sql = "SELECT oi.product_id, oi.qty, p.stock_group
                    FROM order_items oi
                    LEFT JOIN products p ON p.id = oi.product_id
                    WHERE oi.order_id = ?";
            $st = $pdo->prepare($sql); $st->execute([$order_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return [];

            $groups = [];
            foreach ($rows as $r) {
                $g = trim((string)($r['stock_group'] ?? '')); if ($g!=='') $groups[$g]=true;
            }
            $masters = [];
            if ($groups) {
                $in = implode(',', array_fill(0, count($groups), '?'));
                $q2 = $pdo->prepare("SELECT stock_group, id
                                     FROM products
                                     WHERE COALESCE(is_stock_master,0)=1 AND stock_group IN ($in)");
                $q2->execute(array_keys($groups));
                foreach ($q2 as $row) { $masters[trim($row['stock_group'])] = (int)$row['id']; }
            }

            $map = [];
            foreach ($rows as $r) {
                $pid = (int)($r['product_id'] ?? 0);
                $qty = max(0, (int)($r['qty'] ?? 0));
                if ($qty<=0 || $pid<=0) continue;
                $g = trim((string)($r['stock_group'] ?? ''));
                $target = ($g!=='' && isset($masters[$g])) ? (int)$masters[$g] : $pid;
                $map[$target] = ($map[$target] ?? 0) + $qty;
            }
            return $map;
        }catch(Throwable $e){ return []; }
    }
}

/** คืนสต๊อก (idempotent) */
if (!function_exists('return_stock_for_order')) {
    function return_stock_for_order(PDO $pdo, int $order_id): bool {
        try{
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) $pdo->beginTransaction();

            $targets  = stock_targets_for_order($pdo, $order_id);
            if (!$targets) {
                if (has_column($pdo,'orders','stock_locked')) {
                    $pdo->prepare("UPDATE orders SET stock_locked=0, updated_at=NOW() WHERE id=?")->execute([$order_id]);
                }
                if ($ownTx) $pdo->commit();
                return true;
            }

            $prevRet = sum_event_qty($pdo, $order_id, ['stock_returned','stock_returned_fix']);

            $need = [];
            foreach ($targets as $pid => $qty) {
                $done  = $prevRet[$pid] ?? 0;
                $delta = max(0, $qty - $done);
                if ($delta > 0) $need[$pid] = $delta;
            }

            if ($need) {
                $stmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($need as $pid => $delta) { $stmt->execute([$delta, $pid]); }
                log_shipping_event($pdo, $order_id, 'stock_returned', $need);
            } else {
                log_shipping_event($pdo, $order_id, 'stock_returned_skip', 'already returned all');
            }

            if (has_column($pdo,'orders','stock_locked')) {
                $pdo->prepare("UPDATE orders SET stock_locked=0, updated_at=NOW() WHERE id=?")->execute([$order_id]);
            }

            if ($ownTx) $pdo->commit();
            return true;
        }catch(Throwable $e){
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
}

/** คำนวณ “ส่วนที่ยังต้องคืนจริง ๆ” */
if (!function_exists('net_stock_to_return')) {
    function net_stock_to_return(PDO $pdo, int $order_id): array {
        $targets = stock_targets_for_order($pdo, $order_id);
        if (!$targets) return [];
        $ded = sum_event_qty($pdo, $order_id, ['stock_deducted']);
        $ret = sum_event_qty($pdo, $order_id, ['stock_returned','stock_returned_fix']);
        $need = [];
        foreach ($targets as $pid => $qtyShould) {
            $dedone = (int)($ded[$pid] ?? 0);
            $reone  = (int)($ret[$pid] ?? 0);
            $delta  = max(0, min($qtyShould, $dedone) - $reone);
            if ($delta > 0) $need[$pid] = $delta;
        }
        return $need;
    }
}

/** รีคอนไซล์: คืนเฉพาะส่วนที่เคยตัดจริงแต่ยังไม่ได้คืน */
if (!function_exists('reconcile_stock_for_order')) {
    function reconcile_stock_for_order(PDO $pdo, int $order_id): bool {
        try {
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) $pdo->beginTransaction();

            $need = net_stock_to_return($pdo, $order_id);
            if ($need) {
                $stmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($need as $pid => $delta) { $stmt->execute([$delta, $pid]); }
                log_shipping_event($pdo, $order_id, 'stock_returned_fix', $need);
            } else {
                log_shipping_event($pdo, $order_id, 'stock_returned_skip', 'no delta to return');
            }

            if (has_column($pdo,'orders','stock_locked')) {
                $pdo->prepare("UPDATE orders SET stock_locked=0, updated_at=NOW() WHERE id=?")->execute([$order_id]);
            }

            if ($ownTx) $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
}

/** ทำให้ “ผลกระทบสต๊อกจริง” = “จำนวนตามสถานะล่าสุด” */
if (!function_exists('stock_reconcile')) {
    function stock_reconcile(PDO $pdo, int $order_id, ?string $finalStatus = null): bool {
        try {
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) $pdo->beginTransaction();

            if ($finalStatus === null) {
                $st = $pdo->prepare("SELECT status FROM orders WHERE id=?");
                $st->execute([$order_id]);
                $finalStatus = (string)($st->fetchColumn() ?: 'unpaid');
            }

            $should = is_paidish($finalStatus) ? stock_targets_for_order($pdo, $order_id) : [];
            $ded = sum_event_qty($pdo, $order_id, ['stock_deducted']);
            $ret = sum_event_qty($pdo, $order_id, ['stock_returned','stock_returned_fix']);

            $needDed = []; $needRet = [];
            $pids = array_unique(array_merge(array_keys($should), array_keys($ded), array_keys($ret)));
            foreach ($pids as $pid) {
                $target = (int)($should[$pid] ?? 0);
                $netNow = (int)($ded[$pid] ?? 0) - (int)($ret[$pid] ?? 0);
                if ($target > $netNow) $needDed[$pid] = $target - $netNow;
                elseif ($target < $netNow) $needRet[$pid] = $netNow - $target;
            }

            if ($needDed) {
                $u = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                foreach ($needDed as $pid => $d) { if ($d > 0) $u->execute([$d, $pid]); }
                log_shipping_event($pdo, $order_id, 'stock_deducted', $needDed);
            }
            if ($needRet) {
                $u = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($needRet as $pid => $r) { if ($r > 0) $u->execute([$r, $pid]); }
                log_shipping_event($pdo, $order_id, 'stock_returned_fix', $needRet);
            }

            if (has_column($pdo,'orders','stock_locked')) {
                $locked = (is_paidish($finalStatus) && array_sum($should) > 0) ? 1 : 0;
                $pdo->prepare("UPDATE orders SET stock_locked=?, updated_at=NOW() WHERE id=?")
                    ->execute([$locked, $order_id]);
            }

            if ($ownTx) $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
}

/** ตัวช่วย: เรียกคืน + รีคอนไซล์ ในคำสั่งเดียว */
if (!function_exists('ensure_stock_return')) {
    function ensure_stock_return(PDO $pdo, int $order_id): bool {
        $ok1 = return_stock_for_order($pdo, $order_id);
        $ok2 = reconcile_stock_for_order($pdo, $order_id);
        return $ok1 && $ok2;
    }
}

/** Hook กลาง: ใช้ตอนเปลี่ยนสถานะ หรือหลังแก้รายการสินค้า */
if (!function_exists('apply_stock_side_effects')) {
    function apply_stock_side_effects(PDO $pdo, int $order_id, string $oldStatus, string $newStatus, bool $afterItemsEdited=false): void {
        try {
            if ($newStatus === 'cancelled') { ensure_stock_return($pdo, $order_id); return; }

            if ($afterItemsEdited) { stock_reconcile($pdo, $order_id, $newStatus); return; }

            $oldPaid = is_paidish($oldStatus); $newPaid = is_paidish($newStatus);
            if     ($oldPaid && !$newPaid) return_stock_for_order($pdo, $order_id);
            elseif (!$oldPaid && $newPaid) deduct_stock_for_order($pdo, $order_id);
            else                            stock_reconcile($pdo, $order_id, $newStatus);
        } catch (Throwable $e) { /* swallow */ }
    }
}

/** ---------- Back-compat ---------- */
if (!function_exists('checkout_deduct_stock_group_aware')) {
    function checkout_deduct_stock_group_aware(PDO $pdo, int $order_id): bool {
        return deduct_stock_for_order($pdo, $order_id);
    }
}

/* ==========================================================
 *        Utilities for blog/articles (unrelated)
 * ========================================================== */
if (!function_exists('display_flash_messages')) {
    function display_flash_messages() {
        if (isset($_SESSION['flash']) && is_array($_SESSION['flash'])) {
            foreach ($_SESSION['flash'] as $key => $message) {
                $is_success = ($key === 'success');
                $style = 'padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; border: 1px solid transparent;';
                $style .= $is_success
                    ? 'color: #155724; background-color: #d4edda; border-color: #c3e6cb;'
                    : 'color: #721c24; background-color: #f8d7da; border-color: #f5c6cb;';
                echo '<div style="' . $style . '">' . htmlspecialchars($message) . '</div>';
            }
            unset($_SESSION['flash']);
        }
    }
}
if (!function_exists('create_slug')) {
    function create_slug(string $string): string {
        $string = trim(mb_strtolower($string));
        $string = preg_replace('/[^a-z0-9ก-๙\- ]/u', '', $string);
        $string = preg_replace('/[\s-]+/', '-', $string);
        return $string ?: ('post-' . time());
    }
}
if (!function_exists('generate_unique_slug')) {
    function generate_unique_slug(PDO $pdo, string $table, string $slug, int $exclude_id = 0): string {
        $base_slug = $slug; $counter = 2;
        while (true) {
            $sql = "SELECT COUNT(*) FROM `$table` WHERE `slug` = ? AND `id` != ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$slug, $exclude_id]);
            if ($stmt->fetchColumn() == 0) return $slug;
            $slug = $base_slug . '-' . $counter++;
        }
    }
}
