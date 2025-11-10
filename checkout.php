<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/coupon_helpers.php';
require_once __DIR__ . '/includes/send_receipt_mail.php';

// (แนะนำ) เปิด output buffering กัน header already sent แบบเผื่อไว้
if (!ob_get_level()) { ob_start(); }

/* ------- safe get_setting ------- */
if (!function_exists('get_setting')) {
  function get_setting(PDO $pdo, string $key, $default=null){
    try{ $st=$pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1'); $st->execute([$key]);
         $v=$st->fetchColumn(); return ($v===false)?$default:$v;
    }catch(Throwable $e){ return $default; }
  }
}

/* ------- schema helpers ------- */
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try{
      $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
      $st->execute([$table,$col]);
      return (bool)$st->fetchColumn();
    }catch(Throwable $e){ return false; }
  }
}
function money($n){ return number_format((float)$n,2,'.',','); }

/* ====== ฟังก์ชันตัดสต๊อคแบบ “สต๊อกร่วม” ======
   - ถ้ามี stock_group: หักสต๊อคที่ "ตัวแม่" (is_stock_master=1) ของกลุ่มนั้น
   - ถ้าไม่มี/หาไม่เจอ: หักที่สินค้าตัวเอง
   - ใช้ภายใน transaction ก่อน commit
*/
if (!function_exists('checkout_deduct_stock_group_aware')) {
  function checkout_deduct_stock_group_aware(PDO $pdo, int $orderId): bool {
    $hasGroup  = has_column($pdo,'products','stock_group');
    $hasMaster = has_column($pdo,'products','is_stock_master');

    // ดึงรายการสินค้าในออเดอร์ + group (ถ้ามีคอลัมน์)
    if ($hasGroup) {
      $st = $pdo->prepare("
        SELECT oi.product_id, oi.qty, p.stock_group
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
      ");
    } else {
      $st = $pdo->prepare("
        SELECT oi.product_id, oi.qty, NULL AS stock_group
        FROM order_items oi
        WHERE oi.order_id = ?
      ");
    }
    $st->execute([$orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return true;

    $masterCache = []; // key = normalized group, val = master product id
    $deduct = [];      // [target_product_id => total_qty]

    foreach ($rows as $r) {
      $pid = (int)$r['product_id'];
      $qty = (int)$r['qty'];
      $g   = $hasGroup ? trim((string)$r['stock_group']) : '';

      if ($g !== '' && $hasMaster) {
        $gKey = mb_strtolower($g);
        if (!isset($masterCache[$gKey])) {
          // หา "ตัวแม่" ของกลุ่ม (เทียบแบบ TRIM+LOWER)
          $q = $pdo->prepare("
            SELECT id
            FROM products
            WHERE NULLIF(TRIM(LOWER(stock_group)),'') = NULLIF(TRIM(LOWER(?)),'')
              AND COALESCE(is_stock_master,0) = 1
            ORDER BY id LIMIT 1
          ");
          $q->execute([$g]);
          $mid = (int)($q->fetchColumn() ?: 0);
          // ถ้าไม่เจอแม่ ให้ fallback ไปหักที่ตัวเอง
          $masterCache[$gKey] = $mid ?: $pid;
        }
        $target = $masterCache[$gKey];
      } else {
        $target = $pid;
      }

      $deduct[$target] = ($deduct[$target] ?? 0) + $qty;
    }

    // ยิง UPDATE ลดสต๊อค (กันติดลบด้วย GREATEST)
    $u = $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?");
    foreach ($deduct as $tid => $q) {
      $u->execute([(int)$q, (int)$tid]);
      if ($u->rowCount() < 1) {
        // ถ้าไม่มีแถวถูกอัปเดต ให้ถือว่าล้มเหลว เพื่อ rollback
        return false;
      }
    }
    return true;
  }
}

/* ------- QR helpers ------- */
function map_qr_src(PDO $pdo): string {
  $val = trim(get_setting($pdo,'map_qr',''));
  if ($val==='') return 'https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=' . urlencode('https://g.co/kgs/avFkHKe');
  if (preg_match('#^https?://#i',$val)) return $val;
  return BASE_URL . '/' . ltrim($val,'/');
}
function bank_qr_src(PDO $pdo): string {
  $val = trim(get_setting($pdo,'bank_qr',''));
  if ($val==='') return '';
  if (preg_match('#^https?://#i',$val)) return $val;
  return BASE_URL . '/' . ltrim($val,'/');
}

/* -------------- helpers: stock master + reserved -------------- */
function fetch_cart_products(PDO $pdo, array $ids): array {
  if (!$ids) return [];
  $in = implode(',', array_fill(0, count($ids), '?'));
  $want = "id,name,price,stock,image,is_active";
  if (has_column($pdo,'products','allow_cover_print'))  $want .= ",allow_cover_print AS allow_cover";
  if (has_column($pdo,'products','stock_group'))        $want .= ",stock_group";
  if (has_column($pdo,'products','is_stock_master'))    $want .= ",is_stock_master";
  $st=$pdo->prepare("SELECT $want FROM products WHERE id IN ($in) AND is_active=1");
  $st->execute($ids);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function get_group_master(PDO $pdo, string $group): ?array {
  try{
    $st=$pdo->prepare("SELECT id,stock FROM products WHERE stock_group=? AND is_stock_master=1 ORDER BY id LIMIT 1");
    $st->execute([$group]); $m=$st->fetch(PDO::FETCH_ASSOC);
    return $m ?: null;
  }catch(Throwable $e){ return null; }
}
function reserved_by_groups(PDO $pdo, array $groups): array {
  if (!$groups) return [];
  $in = implode(',', array_fill(0,count($groups),'?'));
  $sql = "SELECT p.stock_group AS g, COALESCE(SUM(oi.qty),0) AS r
          FROM order_items oi
          JOIN orders o ON o.id=oi.order_id
          JOIN products p ON p.id=oi.product_id
          WHERE p.stock_group IN ($in)
            AND o.status='unpaid'
            AND (o.expires_at IS NULL OR o.expires_at>NOW())
          GROUP BY p.stock_group";
  $st=$pdo->prepare($sql); $st->execute($groups);
  $out=[]; foreach($st as $row){ $out[(string)$row['g']]=(int)$row['r']; }
  return $out;
}
function reserved_by_pids(PDO $pdo, array $pids): array {
  if (!$pids) return [];
  $in = implode(',', array_fill(0,count($pids),'?'));
  $sql = "SELECT oi.product_id AS pid, COALESCE(SUM(oi.qty),0) AS r
          FROM order_items oi
          JOIN orders o ON o.id=oi.order_id
          WHERE oi.product_id IN ($in)
            AND o.status='unpaid'
            AND (o.expires_at IS NULL OR o.expires_at>NOW())
          GROUP BY oi.product_id";
  $st=$pdo->prepare($sql); $st->execute($pids);
  $out=[]; foreach($st as $row){ $out[(int)$row['pid']]=(int)$row['r']; }
  return $out;
}

/* ------- cart session ------- */
if (!isset($_SESSION['cart']))       $_SESSION['cart'] = [];
if (!isset($_SESSION['cover_opts'])) $_SESSION['cover_opts'] = []; // pid => ['on'=>0/1,'note'=>'']

/* migrate จากรุ่นเก่า (cover_lines/cover_note) -> cover_opts */
if (empty($_SESSION['cover_opts']) && !empty($_SESSION['cover_lines'])) {
  $mNote = $_SESSION['cover_note'] ?? '';
  foreach ((array)$_SESSION['cover_lines'] as $legacyPid) {
    $_SESSION['cover_opts'][(int)$legacyPid] = ['on'=>1,'note'=>$mNote];
  }
}

$cart  = &$_SESSION['cart'];
$cover = &$_SESSION['cover_opts'];

/* ------- load cart + resolve stock master + clamp by reserved ------- */
$ids = array_keys($cart);
$items = []; $subtotal = 0.0; $total_qty = 0;

if ($ids) {
  $rows = fetch_cart_products($pdo, $ids);

  // กลุ่ม/เดี่ยว
  $groups = []; $byGroup = []; $soloPids = []; $masters = [];
  foreach ($rows as $i=>$p) {
    $sg = trim((string)($p['stock_group'] ?? ''));
    if ($sg !== '') { $groups[$sg]=true; $byGroup[$sg][]=$i; } else { $soloPids[]=(int)$p['id']; }
  }
  foreach (array_keys($groups) as $g) {
    $m = get_group_master($pdo, $g);
    if ($m) $masters[$g] = ['id'=>(int)$m['id'], 'stock'=>(int)$m['stock']];
    else { $i0 = $byGroup[$g][0]; $masters[$g] = ['id'=>(int)$rows[$i0]['id'], 'stock'=>(int)$rows[$i0]['stock']]; }
  }
  $reservedG = $groups   ? reserved_by_groups($pdo, array_keys($groups)) : [];
  $reservedP = $soloPids ? reserved_by_pids($pdo, $soloPids)             : [];

  // จัดสรรให้ไม่เกินพร้อมขาย
  foreach ($byGroup as $g=>$idxs) {
    $avail = max(0, (int)$masters[$g]['stock'] - (int)($reservedG[$g] ?? 0));
    foreach ($idxs as $i) {
      $pid = (int)$rows[$i]['id'];
      $req = max(0, (int)($cart[$pid] ?? 0));
      $give = min($req, $avail);
      $cart[$pid] = $give;
      $avail -= $give;
    }
  }
  foreach ($soloPids as $pid) {
    $p = null; foreach($rows as $r){ if ((int)$r['id']===$pid){ $p=$r; break; } }
    if (!$p) continue;
    $avail = max(0, (int)$p['stock'] - (int)($reservedP[$pid] ?? 0));
    $cart[$pid] = min(max(0,(int)$cart[$pid]), $avail);
  }

  // ทำ items
  foreach ($rows as $p) {
    $pid = (int)$p['id'];
    $qty = max(0, (int)($cart[$pid] ?? 0));
    if ($qty <= 0) { unset($cart[$pid]); continue; }

    $sg = trim((string)($p['stock_group'] ?? ''));
    if ($sg !== '') { $stockTarget = (int)$masters[$sg]['id']; $effStock=(int)$masters[$sg]['stock']; }
    else            { $stockTarget = $pid;                     $effStock=(int)$p['stock']; }

    $img  = $p['image'] ? BASE_URL.'/'.ltrim($p['image'],'/') : BASE_URL.'/assets/images/noimg.png';
    $line = (float)$p['price'] * $qty;
    $subtotal  += $line;
    $total_qty += $qty;

    $allow = isset($p['allow_cover']) ? (int)$p['allow_cover'] : 1;
    $opt   = $cover[$pid] ?? ['on'=>0,'note'=>''];
    $items[] = [
      'id'=>$pid,'name'=>$p['name'],'price'=>(float)$p['price'],
      'stock'=>$effStock,'qty'=>$qty,'img'=>$img,
      'allow_cover'=>$allow,'cover_on'=>(int)$opt['on'],'cover_note'=>$opt['note'],
      'stock_target'=>$stockTarget
    ];
  }
}

/* ------- cover fee (per-line) ------- */
$cover_base = (float) get_setting($pdo, 'cover_base', 500);
$cover_over = (float) get_setting($pdo, 'cover_over_rate', 2);
$cover_th   = (int)   get_setting($pdo, 'cover_threshold_qty', 50);
function cover_fee_line_calc($qty,$base,$over,$threshold){
  $qty=(int)$qty; return $qty<=0?0.0:($base+max(0,$qty-$threshold)*$over);
}
$cover_fee_total=0.0;
foreach($items as $it){
  if(!empty($it['allow_cover']) && !empty($it['cover_on'])){
    $cover_fee_total += cover_fee_line_calc((int)$it['qty'],$cover_base,$cover_over,$cover_th);
  }
}

/* ------- coupon preview ------- */
$preview_coupon   = trim($_POST['coupon'] ?? $_GET['coupon'] ?? '');
$preview_discount = 0.0; 
$coupon_preview_err = null;
if ($preview_coupon !== '' && function_exists('calc_coupon_discount')) {
  [$preview_discount, $coupon_preview_err] = calc_coupon_discount(
    $pdo, $preview_coupon, $subtotal, $_SESSION['user']['id'] ?? null, $items
  );
}

/* ------- place / upload / confirm ------- */
$ok      = isset($_GET['ok']) ? 1 : 0;
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors  = [];

/* =========== PLACE ORDER (ตัดสต๊อคที่ตัวหลัก) =========== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'place') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $errors[]='CSRF invalid, โปรดลองใหม่'; }
  if (!$items) { $errors[]='ตะกร้าของคุณว่าง'; }

  $ship_method = (($_POST['ship_method'] ?? 'delivery') === 'pickup') ? 'pickup' : 'delivery';
  $delivery_option = ($ship_method==='pickup') ? 'pickup' : 'home';

  $fullname    = trim($_POST['fullname'] ?? '');
  $phone       = trim($_POST['phone'] ?? '');
  $email       = trim($_POST['email'] ?? '');
  $coupon_code = trim($_POST['coupon'] ?? '');
  $address     = trim($_POST['address'] ?? '');
  $note        = trim($_POST['note'] ?? '');
  $affiliation = trim($_POST['affiliation'] ?? '');
  $note_db     = trim($note . ($affiliation!==''? "\nสังกัด: ".$affiliation : ''));

  // แนบสรุปพิมพ์ปกลง note
  foreach($items as $it){
    if(!empty($it['allow_cover']) && !empty($it['cover_on'])){
      $over = max(0,(int)$it['qty'] - $cover_th);
      $fee  = cover_fee_line_calc((int)$it['qty'],$cover_base,$cover_over,$cover_th);
      $note_db .= ($note_db!==''? "\n" : '').
                  'พิมพ์ปก: PID#'.$it['id'].' × '.$it['qty'].
                  ' | เปิดบล็อก '.money($cover_base).
                  ' + เกิน '.$over.' เล่ม × '.money($cover_over).
                  ' = '.money($fee).' บาท'.
                  (!empty($it['cover_note'])? ' | รายละเอียด: '.$it['cover_note']:'');
    }
  }

  if ($fullname==='') $errors[]='กรุณากรอกชื่อผู้รับ';
  if ($phone==='')    $errors[]='กรุณากรอกเบอร์โทร';
  if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) $errors[]='อีเมลไม่ถูกต้อง';
  if ($ship_method==='delivery' && $address==='') $errors[]='กรุณากรอกที่อยู่จัดส่ง';

  // ส่วนลดคูปองจริง
  $discount = 0.0;
  if (function_exists('calc_coupon_discount')) {
    [$discount, $coupon_err] = calc_coupon_discount(
      $pdo, $coupon_code, $subtotal, $_SESSION['user']['id'] ?? null, $items
    );
    if ($coupon_code!=='' && $coupon_err) { $discount=0.0; $errors[]="คูปองใช้งานไม่ได้: $coupon_err"; }
  }

  // ค่าส่ง
  $first_rate = (float) get_setting($pdo,'shipping_first',50);
  $next_rate  = (float) get_setting($pdo,'shipping_next',10);
  $shipping   = ($ship_method==='delivery')
                  ? ($total_qty>0 ? ($first_rate + max(0,$total_qty-1)*$next_rate) : 0.0)
                  : 0.0;

  $grand = max(0.0, $subtotal - $discount + $shipping + $cover_fee_total);

  if (!$errors) {
    try{
      $pdo->beginTransaction();

      $ttl_hours   = (int)get_setting($pdo,'order_ttl_hours',48);
      $expires     = date('Y-m-d H:i:s', time()+max(1,$ttl_hours)*3600);
      $pickup_code = ($ship_method==='pickup') ? strtoupper(substr(bin2hex(random_bytes(4)),0,8)) : null;
      $uid         = $_SESSION['user']['id'] ?? null;

      // insert orders (with fallbacks)
      try{
        $sql="INSERT INTO orders
              (user_id,fullname,phone,email,address,note,
               shipping,coupon_code,grand_total,status,expires_at,
               pickup_code,delivery_option,ship_method,created_at,updated_at)
              VALUES (?,?,?,?,?, ?, ?, ?, ?, 'unpaid', ?, ?, ?, ?, NOW(), NOW())";
        $pdo->prepare($sql)->execute([
          $uid,$fullname,$phone,$email,$address,$note_db,
          $shipping,$coupon_code,$grand,$expires,
          $pickup_code,$delivery_option,$ship_method
        ]);
      }catch(Throwable $e1){
        try{
          $sql="INSERT INTO orders
                (user_id,fullname,phone,email,address,note,
                 shipping,coupon_code,grand_total,status,expires_at,
                 pickup_code,delivery_option,created_at,updated_at)
                VALUES (?,?,?,?,?, ?, ?, ?, ?, 'unpaid', ?, ?, ?, NOW(), NOW())";
          $pdo->prepare($sql)->execute([
            $uid,$fullname,$phone,$email,$address,$note_db,
            $shipping,$coupon_code,$grand,$expires,
            $pickup_code,$delivery_option
          ]);
        }catch(Throwable $e2){
          $sql="INSERT INTO orders
                (user_id,fullname,phone,email,address,note,
                 shipping,coupon_code,grand_total,status,expires_at,
                 pickup_code,created_at,updated_at)
                VALUES (?,?,?,?,?, ?, ?, ?, ?, 'unpaid', ?, ?, NOW(), NOW())";
          $pdo->prepare($sql)->execute([
            $uid,$fullname,$phone,$email,$address,$note_db,
            $shipping,$coupon_code,$grand,$expires,$pickup_code
          ]);
        }
      }

      $oid = (int)$pdo->lastInsertId();

      // ===== order_items (+ cover meta) — สร้างคอลัมน์ตาม schema จริง =====
      $has_on    = has_column($pdo,'order_items','cover_on');
      $has_note  = has_column($pdo,'order_items','cover_note');
      $has_fee   = has_column($pdo,'order_items','cover_fee');
      $has_meta  = has_column($pdo,'order_items','meta_json');
      $has_oi_nt = has_column($pdo,'order_items','note');

      $has_unit  = has_column($pdo,'order_items','unit_price');  // บางฐานมี unit_price
      $has_price = has_column($pdo,'order_items','price');       // บางฐานใช้ price แทน

      foreach($items as $it){
        $line_fee = (!empty($it['allow_cover']) && !empty($it['cover_on']))
                    ? cover_fee_line_calc((int)$it['qty'],$cover_base,$cover_over,$cover_th)
                    : 0.0;

        $cols = ['order_id','product_id','qty'];
        $vals = ['?','?','?'];
        $prm  = [$oid, $it['id'], (int)$it['qty']];

        if ($has_unit) { $cols[]='unit_price'; $vals[]='?'; $prm[]=(float)$it['price']; }
        elseif ($has_price){ $cols[]='price'; $vals[]='?'; $prm[]=(float)$it['price']; }

        if ($has_on)   { $cols[]='cover_on';   $vals[]='?'; $prm[]=(int)$it['cover_on']; }
        if ($has_note) { $cols[]='cover_note'; $vals[]='?'; $prm[]=(string)($it['cover_note'] ?? ''); }
        if ($has_fee)  { $cols[]='cover_fee';  $vals[]='?'; $prm[]=(float)$line_fee; }

        if (!$has_on || !$has_note || !$has_fee) {
          $pack = [];
          if (!$has_on)   $pack['cover_on']   = (int)$it['cover_on'];
          if (!$has_note) $pack['cover_note'] = (string)($it['cover_note'] ?? '');
          if (!$has_fee)  $pack['cover_fee']  = (float)$line_fee;

          if (!empty($pack)) {
            if ($has_meta) {
              $cols[]='meta_json'; $vals[]='?'; $prm[]=json_encode($pack, JSON_UNESCAPED_UNICODE);
            } elseif ($has_oi_nt) {
              $cols[]='note'; $vals[]='?'; $prm[]='COVER: '.json_encode($pack, JSON_UNESCAPED_UNICODE);
            }
          }
        }

        $sql = "INSERT INTO order_items (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
        $pdo->prepare($sql)->execute($prm);
      }

      /* ===== ตัดสต๊อคแบบ “สต๊อกร่วม” ในทรานแซกชันเดียวกัน ===== */
      if (!checkout_deduct_stock_group_aware($pdo, (int)$oid)) {
        throw new Exception('Stock deduction failed');
      }

      $pdo->commit();

      // ล้างตะกร้า + redirect (ส่งเมลเฉพาะตอนอัปโหลดสลิป)
      $_SESSION['cart'] = [];
      $_SESSION['cover_opts'] = [];
      $_SESSION['printable_orders'] = $_SESSION['printable_orders'] ?? [];
      if (!in_array($oid, $_SESSION['printable_orders'], true)) {
      $_SESSION['printable_orders'][] = $oid;
      }
      $qs = 'ok=1&id=' . $oid;
      if (isset($pickup_code)) $qs .= '&pc=' . $pickup_code;
      header('Location: ' . BASE_URL . '/checkout.php?' . $qs);
      exit;
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $errors[]='สั่งซื้อไม่สำเร็จ: '.$e->getMessage();
    }
  }
}

/* =========== UPLOAD SLIP =========== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'upload_slip') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); header('Location: '.BASE_URL.'/index.php'); exit; }
  $oid = (int)($_POST['order_id'] ?? 0);
  if ($oid<=0){ flash('error','ไม่พบออเดอร์'); header('Location: '.BASE_URL.'/index.php'); exit; }

  $slip = handle_upload($_FILES['slip'] ?? [], 'uploads/slips', ['jpg','jpeg','png','gif','webp','pdf']);
  if (!$slip){ flash('error','อัปโหลดสลิปล้มเหลว'); header('Location: '.BASE_URL.'/checkout.php?ok=1&id='.$oid); exit; }

  try{
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO payments (order_id, method, slip_path, is_verified)
                   VALUES (?, "bank_transfer", ?, 0)
                   ON DUPLICATE KEY UPDATE slip_path=VALUES(slip_path)')
        ->execute([$oid,$slip]);

    $r=$pdo->prepare('SELECT status, pickup_code FROM orders WHERE id=?');
    $r->execute([$oid]); $ord=$r->fetch(PDO::FETCH_ASSOC); $pc=$ord['pickup_code'] ?? null;

    if (!in_array(($ord['status'] ?? 'unpaid'), ['paid','processing','shipped','completed'], true)) {
      $pdo->prepare('UPDATE orders SET status="paid", updated_at=NOW() WHERE id=?')->execute([$oid]);
    }

    $pdo->commit();
    try{ send_receipt_mail($pdo,(int)$oid); }catch(Throwable $e){ error_log('send_receipt_mail (after slip): '.$e->getMessage()); }
    flash('success','อัปโหลดสลิปแล้ว กรุณากดปุ่ม "ยืนยันคำสั่งซื้อ" ที่ด้านล่างเพื่อยืนยันการสั่งซื้อค่ะ');
    header('Location: '.BASE_URL.'/checkout.php?ok=1&id='.$oid.($pc?('&pc='.$pc):'')); exit;
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    flash('error','บันทึกสลิปล้มเหลว: '.$e->getMessage());
    header('Location: '.BASE_URL.'/checkout.php?ok=1&id='.$oid); exit;
  }
}

/* =========== CONFIRM ORDER =========== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'confirm_order') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); header('Location: '.BASE_URL.'/index.php'); exit; }
  $oid = (int)($_POST['order_id'] ?? 0);
  if ($oid<=0){ flash('error','ไม่พบออเดอร์'); header('Location: '.BASE_URL.'/index.php'); exit; }

  $st=$pdo->prepare('SELECT slip_path FROM payments WHERE order_id=? LIMIT 1');
  $st->execute([$oid]); $slip_path=trim((string)($st->fetchColumn() ?? ''));
  if ($slip_path===''){ flash('error','กรุณาอัปโหลดสลิปก่อนกดยืนยัน'); header('Location: '.BASE_URL.'/checkout.php?ok=1&id='.$oid); exit; }

  try{ $pdo->prepare('UPDATE orders SET customer_confirmed_at=NOW(), updated_at=NOW() WHERE id=?')->execute([$oid]); }catch(Throwable $e){}
  $or=$pdo->prepare('SELECT email,phone FROM orders WHERE id=?'); $or->execute([$oid]); $or=$or->fetch(PDO::FETCH_ASSOC);
  flash('success','ยืนยันคำสั่งซื้อเรียบร้อย ขอบคุณค่ะ');
  $q='order='.$oid; if(!empty($or['email'])) $q.='&email='.urlencode($or['email']); if(!empty($or['phone'])) $q.='&phone='.urlencode($or['phone']);
  header('Location: '.BASE_URL.'/track.php?'.$q); exit;
}

/* ------- UI ------- */
?>

<?php
require_once __DIR__ . '/partials/header.php';
?>

<style>
body .container, .container { max-width: 100% !important; width: 100% !important; padding-left: 1rem; padding-right: 1rem; }
.checkout-grid{display:grid;grid-template-columns:2fr 1fr;gap:1rem}
@media(max-width: 900px){ .checkout-grid{grid-template-columns:1fr} }
.card{border:1px solid #23234a;border-radius:1rem;padding:1rem}
.field{display:flex;flex-direction:column;margin:.5rem 0}
.row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.summary-row{display:flex;justify-content:space-between;margin:.25rem 0}
.badge.ok{background:#263b2a;color:#d5ffd7;padding:.1rem .4rem;border-radius:.5rem}
.qr{display:block;width:min(220px,60vw)!important;height:auto;;aspect-ratio:1/1;object-fit:contain;background:#0f1026;padding:.35rem;border:1px solid #2a2a40;border-radius:.6rem}
.payqr{display:block;width:min(240px,70vw)!important;height:auto;;aspect-ratio:1/1;object-fit:contain;background:#0f1026;padding:.5rem;border:1px solid #2a2a40;border-radius:.8rem}
.small{font-size:.85rem;opacity:.85}
</style>

<?php if ($ok && $orderId): ?>
  <?php
    $_SESSION['printable_orders'] = $_SESSION['printable_orders'] ?? [];
    if (!in_array($orderId, $_SESSION['printable_orders'], true)) {
      $_SESSION['printable_orders'][] = $orderId;
    }
    $o = $pdo->prepare("SELECT id, status, grand_total, pickup_code FROM orders WHERE id=?");
    $o->execute([$orderId]); $o=$o->fetch(PDO::FETCH_ASSOC);
    $bank_qr  = bank_qr_src($pdo);
    $bank_acc = get_setting($pdo,'bank_account','');

    $ps = $pdo->prepare('SELECT slip_path FROM payments WHERE order_id=? LIMIT 1');
$ps->execute([$orderId]);
$slip_path = trim((string)($ps->fetchColumn() ?? ''));
$has_slip  = ($slip_path !== '');
$slip_url  = $has_slip
  ? (preg_match('#^https?://#i', $slip_path) ? $slip_path : BASE_URL . '/' . ltrim($slip_path, '/'))
  : '';

// ใช้ส่วนขยายไฟล์เพื่อเลือกวิธีแสดงในป๊อปอัพ
$slip_ext_path = parse_url($slip_path, PHP_URL_PATH) ?? '';
$slip_ext = strtolower(pathinfo($slip_ext_path, PATHINFO_EXTENSION));
$is_img   = in_array($slip_ext, ['jpg','jpeg','png','gif','webp'], true);
$is_pdf   = ($slip_ext === 'pdf');
  ?>
  <div class="card">
    <h1>ยืนยันคำสั่งซื้อ — กรุณาชำระเงินและอัปโหลดสลิป</h1>
    <h2>หมายเลขคำสั่งซื้อของคุณ: <strong>#<?= (int)$orderId ?></strong></h2>

    <h3 style="margin-top:.75rem">ชำระเงิน</h3>
    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
      <?php if ($bank_qr!==''): ?>
        <img class="payqr" src="<?= htmlspecialchars($bank_qr) ?>" alt="QR สำหรับโอนเงิน">
      <?php else: ?>
        <div class="alert">ยังไม่ได้ตั้งค่ารูป QR โอนเงิน (ไปที่ Settings เพื่ออัปโหลด)</div>
      <?php endif; ?>
      <div>
        <div class="small" style="opacity:.9">ยอดที่ต้องชำระ</div>
        <div style="font-size:1.15rem;font-weight:700">฿<?= money($o['grand_total'] ?? 0) ?></div>
        <?php if ($bank_acc!==''): ?>
          <div class="small" style="opacity:.85;margin-top:.35rem">บัญชีรับโอน: <?= nl2br(htmlspecialchars($bank_acc)) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$has_slip): ?>
  <form method="post" action="<?= BASE_URL ?>/checkout.php" enctype="multipart/form-data" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_slip">
    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
    <h3>ชื่อบัญชี : จัดจำหน่ายบันทึกประจำวัน ทอ.</h3>
    <label>อัปโหลดสลิปโอนเงิน</label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <input class="input" type="file" name="slip" accept="image/*,.pdf" required style="max-width:320px">
      <button class="btn" type="submit">อัปโหลดสลิป</button>
    </div>
    <div class="small" style="opacity:.8;margin-top:.25rem">
      เมื่ออัปโหลดสลิปแล้ว ให้กด “ยืนยันคำสั่งซื้อ” เพื่อยืนยันคำสั่งซื้อ
    </div>
  </form>
<?php else: ?>
  <div class="small" style="opacity:.95;margin-top:1rem">
    อัปโหลดสลิปแล้ว ✅
  </div>
  <div style="margin-top:.5rem">
    <button class="btn outline" type="button" id="viewSlipBtn">เปิดดูสลิป</button>
  </div>

  <!-- Popup Styles -->
  <style>
    .modal-overlay{
      position:fixed; inset:0; background:rgba(255, 255, 255, 0.75);
      display:none; align-items:center; justify-content:center; z-index:9999;
      padding:1rem;
    }
    .modal-dialog{
      background:#90b0b6; border:1px solid #8686f1ff; border-radius:1rem;
      max-width:min(920px, 96vw); width:100%; max-height:90vh; overflow:hidden;
      box-shadow:0 10px 30px rgba(255, 255, 255, 0.5);
    }
    .modal-head{
      display:flex; align-items:center; justify-content:space-between;
      padding:.8rem 1rem; border-bottom:1px solid #9696f8ff;
    }
    .modal-title{ font-weight:700 }
    .modal-close{
      appearance:none; border:0; background:transparent; color:#ffffff;
      font-size:1.6rem; line-height:1; cursor:pointer;
    }
    .modal-body{
      background:#90b0b6; padding:0; display:flex; justify-content:center; align-items:center;
      overflow:auto; max-height:calc(90vh - 56px);
    }
    .modal-body img{
      max-width:95vw; max-height:calc(90vh - 56px); object-fit:contain; display:block;
    }
    .modal-body iframe, .modal-body embed, .modal-body object{
      width:min(900px, 95vw); height:calc(90vh - 56px); border:0; display:block;
      background:#90b0b6;
    }
    body.modal-open{ overflow:hidden; }
  </style>

  <!-- Popup Markup -->
  <div class="modal-overlay" id="slipModal" role="dialog" aria-modal="true" aria-labelledby="slipTitle">
    <div class="modal-dialog">
      <div class="modal-head">
        <div class="modal-title" id="slipTitle">สลิปการโอนเงิน</div>
        <button class="modal-close" type="button" aria-label="ปิด" id="closeSlipBtn">&times;</button>
      </div>
      <div class="modal-body">
        <?php if ($is_img): ?>
          <img src="<?= htmlspecialchars($slip_url) ?>" alt="สลิปการโอนเงิน">
        <?php elseif ($is_pdf): ?>
          <iframe src="<?= htmlspecialchars($slip_url) ?>#zoom=page-fit" title="สลิปการโอนเงิน (PDF)"></iframe>
        <?php else: ?>
          <!-- fallback แสดงใน iframe ไว้ก่อน และมีลิงก์ดาวน์โหลด -->
          <iframe src="<?= htmlspecialchars($slip_url) ?>" title="สลิปการโอนเงิน"></iframe>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Popup Script -->
  <script>
    (function(){
      const modal = document.getElementById('slipModal');
      const openBtn = document.getElementById('viewSlipBtn');
      const closeBtn = document.getElementById('closeSlipBtn');

      function openModal(){
        if (!modal) return;
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
        // โฟกัสปุ่มปิดเพื่อให้กด Enter/Esc ต่อได้ง่าย
        closeBtn?.focus();
      }
      function closeModal(){
        if (!modal) return;
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
        openBtn?.focus();
      }

      openBtn?.addEventListener('click', openModal);
      closeBtn?.addEventListener('click', closeModal);

      // ปิดเมื่อคลิกพื้นหลังมืด
      modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
      });

      // ปิดด้วย Escape
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal?.style.display === 'flex') {
          closeModal();
        }
      });
    })();
  </script>
<?php endif; ?>

    <?php if (!empty($_GET['pc'])): ?>
      <hr style="border:none;border-top:1px solid #23234a;margin:1rem 0">
      <p>รหัสรับของของคุณ: <span class="badge ok"><?= htmlspecialchars($_GET['pc']) ?></span></p>
      <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <!--    <img class="qr" src="<?= htmlspecialchars(map_qr_src($pdo)) ?>" alt="แผนที่ร้าน (QR)"> 
        <a class="btn outline" target="_blank" href="https://g.co/kgs/avFkHKe">เปิดแผนที่</a>  -->
      </div>
    <?php endif; ?>

    <form method="post" action="<?= BASE_URL ?>/checkout.php" onsubmit="return confirm('ยืนยันการสั่งซื้อเรียบร้อย?')">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="confirm_order">
  <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">

  <?php if(!$has_slip): ?>
    <div class="small" style="margin-top:.5rem;color:#e51c23">
      ※ กรุณาอัปโหลดสลิปก่อน ปุ่ม “ยืนยันคำสั่งซื้อ” จะปรากฏอัตโนมัติหลังอัปโหลดสำเร็จ
    </div>
  <?php else: ?>
    <br>
    <button class="btn" type="submit">ยืนยันคำสั่งซื้อ</button>
  <?php endif; ?>
</form>
    <br>
    <a class="btn outline" href="<?= BASE_URL ?>/index.php">กลับไปเลือกซื้อ</a>
  </div>
  <?php require_once __DIR__ . '/partials/footer.php'; exit; ?>
<?php endif; ?>

<h2>ชำระเงิน</h2>

<?php if (!$items): ?>
  <div class="alert">ยังไม่มีสินค้าในตะกร้า <a class="btn outline" href="<?= BASE_URL ?>/index.php" style="margin-left:.5rem">ไปช้อปต่อ</a></div>
  <?php require_once __DIR__ . '/partials/footer.php'; exit; ?>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert error"><?php foreach($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php
  // shipping preview
  $first_rate = (float) get_setting($pdo,'shipping_first',50);
  $next_rate  = (float) get_setting($pdo,'shipping_next',10);
  $chosen     = $_POST['ship_method'] ?? 'delivery';
  $ship_preview = ($chosen==='delivery')
    ? ($total_qty>0 ? ($first_rate + max(0,$total_qty-1)*$next_rate) : 0.0)
    : 0.0;

  $disc_preview = ($preview_coupon !== '') ? $preview_discount : 0.0;
  $grand_preview = max(0.0, $subtotal + $ship_preview + $cover_fee_total - $disc_preview);

  $shipMeta = [
    'qty'=> (int)$total_qty,
    'first'=> (float)$first_rate,
    'next'=> (float)$next_rate,
    'subtotal'=> (float)$subtotal,
    'coverfee'=> (float)$cover_fee_total,
    'discount'=> (float)$disc_preview
  ];
?>

<div class="checkout-grid">
  <div class="card">
    <h3>ข้อมูลผู้รับ & วิธีจัดส่ง</h3>
<form method="post" action="<?= BASE_URL ?>/checkout.php" id="checkoutForm">
  <?= csrf_field() ?>
  <input type="hidden" id="actField" name="action" value="">

  <div class="row">
    <div class="field">
      <label>ชื่อ-นามสกุล *</label>
      <input class="input" name="fullname" required placeholder="เช่น น.ต.สมชาย ใจดี"
             value="<?= htmlspecialchars($_POST['fullname'] ?? ($_SESSION['user']['name'] ?? '')) ?>">
    </div>
    <div class="field">
      <label>เบอร์โทร *</label>
      <input class="input" name="phone" required placeholder="เช่น 0812345678"
             value="<?= htmlspecialchars($_POST['phone'] ?? ($_SESSION['user']['phone'] ?? '')) ?>">
    </div>
  </div>

  <div class="row">
    <div class="field">
      <label>อีเมล</label>
      <input class="input" type="email" name="email" placeholder="เช่น you@example.com"
             value="<?= htmlspecialchars($_POST['email'] ?? ($_SESSION['user']['email'] ?? '')) ?>">
    </div>
    <div class="field">
      <label>สังกัด (เช่น ยศ.ทอ.)</label>
      <input class="input" name="affiliation" placeholder="ยศ.ทอ. (ถ้าไม่มีใส่ -)"
             value="<?= htmlspecialchars($_POST['affiliation'] ?? '') ?>">
    </div>
  </div>

  <div class="field">
    <label>วิธีรับสินค้า</label>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <label><input type="radio" name="ship_method" value="delivery" <?= $chosen==='delivery'?'checked':'' ?>> จัดส่งถึงบ้าน</label>
      <label><input type="radio" name="ship_method" value="pickup"   <?= $chosen==='pickup'?'checked':'' ?>> มารับเองที่ร้าน (ฟรี)</label>
    </div>
  </div>

  <div id="addrBox" class="field" style="<?= ($chosen==='delivery')?'':'display:none' ?>">
    <label>ที่อยู่จัดส่ง *</label>
    <textarea class="input" name="address" rows="3" placeholder="บ้านเลขที่, ถนน, แขวง/ตำบล, เขต/อำเภอ, จังหวัด, รหัสไปรษณีย์"><?= htmlspecialchars($_POST['address'] ?? ($_SESSION['user']['address'] ?? '')) ?></textarea>
  </div>

  <div id="pickupBox" class="field" style="<?= ($chosen==='pickup')?'':'display:none' ?>">
    <label>มารับเองที่ร้าน</label>
    <div class="small" style="opacity:.9">แสดงรหัสรับของหลังสั่งซื้อ พร้อมสแกนแผนที่นี้เพื่อเดินทาง:</div>
    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-top:.4rem">
      <img class="qr" src="<?= htmlspecialchars(map_qr_src($pdo)) ?>" alt="แผนที่ร้าน (QR)">
      <a class="btn outline" target="_blank" href="https://g.co/kgs/avFkHKe">เปิดแผนที่</a>
    </div>
  </div>

  <div class="field">
    <label>หมายเหตุ (ถ้ามี)</label>
    <textarea class="input" name="note" rows="2" placeholder="ตัวอย่าง: สะดวกส่งช่วงเย็น / ใบกำกับภาษี ฯลฯ"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
  </div>

  <div class="field">
    <label>คูปองส่วนลด</label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <input class="input" name="coupon" placeholder="ระบบคูปองไม่เปิดให้บริการ"
             value="<?= htmlspecialchars($preview_coupon) ?>">
      <button class="btn outline" type="submit" formnovalidate
              onclick="document.getElementById('actField').value=''">
        ตรวจสอบคูปอง
      </button>
    </div>
    <?php if ($coupon_preview_err): ?>
      <div class="small" style="color:#b91c1c;margin-top:.35rem">
        คูปองใช้ไม่ได้: <?= htmlspecialchars($coupon_preview_err) ?>
      </div>
    <?php elseif ($preview_coupon !== '' && $preview_discount > 0): ?>
      <div class="small" style="color:#166534;margin-top:.35rem">
        ใช้คูปอง “<?= htmlspecialchars($preview_coupon) ?>” ลด ฿<?= money($preview_discount) ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="margin-top:.5rem">
    <button class="btn" type="submit"
        onclick="document.getElementById('actField').value='place'">
      สั่งซื้อ
    </button>
  </div>
</form>
</div>

<div class="card summary">
  <h3>สรุปคำสั่งซื้อ</h3>
  <?php foreach($items as $it): ?>
    <div style="display:flex;gap:.6rem;align-items:center;margin:.4rem 0">
      <img src="<?= htmlspecialchars($it['img']) ?>" style="width:58px;height:58px;border-radius:.5rem;border:1px solid #2a2a40;object-fit:cover" alt="">
      <div style="flex:1">
        <div style="font-weight:600"><?= htmlspecialchars($it['name']) ?></div>
        <div class="small" style="opacity:.8">฿<?= money($it['price']) ?> × <?= (int)$it['qty'] ?>
          <?php if(!empty($it['allow_cover']) && !empty($it['cover_on'])): ?>
            <span class="badge ok" style="margin-left:.35rem">พิมพ์ปก</span>
          <?php endif; ?>
        </div>
        <?php if(!empty($it['cover_note'])): ?>
          <div class="small" style="opacity:.8">หมายเหตุปก: <?= htmlspecialchars($it['cover_note']) ?></div>
        <?php endif; ?>
      </div>
      <div>฿<?= money($it['price']*$it['qty']) ?></div>
    </div>
  <?php endforeach; ?>
  <hr style="border:none;border-top:1px solid #23234a;margin:.6rem 0">

  <div class="summary-row"><div>ยอดสินค้า</div><div>฿<span id="subtotalVal"><?= money($subtotal) ?></span></div></div><br>
  <div class="summary-row"><div>ค่าพิมพ์ปก</div><div id="coverCost">฿<?= money($cover_fee_total) ?></div></div><br>
  <div class="summary-row"><div>ส่วนลดคูปอง</div><div id="discCost">−฿<?= money($disc_preview) ?></div></div><br>
  <div class="summary-row"><div>ค่าส่ง</div><div id="shipCost">฿<?= money($ship_preview) ?></div></div><br>
  <div class="summary-row" style="font-weight:700;font-size:1.05rem">
    <div>ยอดสุทธิ</div><div id="grandCost">฿<?= money($grand_preview) ?></div>
  </div>
  <div class="small" style="opacity:.8;margin-top:.35rem">* ยอดสุทธิด้านบนเป็นประมาณการ *</div>
</div>
</div>

<script>
(function(){
  const radios  = document.querySelectorAll('input[name="ship_method"]');
  const addrBox = document.getElementById('addrBox');
  const addrTA  = addrBox?.querySelector('textarea[name="address"]');
  const pick    = document.getElementById('pickupBox');

  const shipEl  = document.getElementById('shipCost');
  const coverEl = document.getElementById('coverCost');
  const discEl  = document.getElementById('discCost');
  const grandEl = document.getElementById('grandCost');
  const subEl   = document.getElementById('subtotalVal');

  document.getElementById('checkoutForm')?.addEventListener('submit', function(){
    const act = document.getElementById('actField');
    if (act && !act.value) act.value = 'place';
  });

  const meta = <?= json_encode($shipMeta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  function calcShipping(isDelivery){ if(!isDelivery) return 0; const q=meta.qty; return q<=0?0:(meta.first+Math.max(0,q-1)*meta.next); }
  function fmt(n){ return Number(n).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  function refresh(){
    const v = document.querySelector('input[name="ship_method"]:checked')?.value || 'delivery';
    const isDelivery = (v==='delivery');
    if (addrBox) addrBox.style.display = isDelivery ? 'block' : 'none';
    if (pick)    pick.style.display    = isDelivery ? 'none'  : 'block';
    if (addrTA)  addrTA.required       = isDelivery;

    const ship = calcShipping(isDelivery);
    if (shipEl)  shipEl.textContent  = '฿' + fmt(ship);
    if (coverEl) coverEl.textContent = '฿' + fmt(meta.coverfee);
    if (discEl)  discEl.textContent  = '−฿' + fmt(meta.discount);
    if (subEl)   subEl.textContent   = fmt(meta.subtotal);
    const grand = Math.max(0, meta.subtotal + ship + meta.coverfee - meta.discount);
    if (grandEl) grandEl.textContent = '฿' + fmt(grand);
  }
  radios.forEach(r => r.addEventListener('change', refresh));
  refresh();
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
