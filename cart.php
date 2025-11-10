<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/partials/header.php';

/* ================= Helpers ================= */
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try{
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME = ? AND COLUMN_NAME = ?
                           LIMIT 1");
      $st->execute([$table,$col]);
      return (bool)$st->fetchColumn();
    } catch(Throwable $e){ return false; }
  }
}
function money($n){ return number_format((float)$n,2,'.',','); }



/** ดึงข้อมูลสินค้า + คำนวณสต๊อคใช้งานจริง (eff_stock) จากตัวหลักของกลุ่ม */
function fetch_product_with_eff_stock(PDO $pdo, int $pid): ?array {
  $hasGroup  = has_column($pdo,'products','stock_group');
  $hasMaster = has_column($pdo,'products','is_stock_master');

  if ($hasGroup && $hasMaster) {
    $sql = "SELECT p.id,p.name,p.price,p.image,p.stock,p.is_active,
                   p.stock_group,p.is_stock_master,
                   p.allow_cover_print AS allow_cover,
                   m.stock AS master_stock
            FROM products p
            LEFT JOIN products m
              ON m.stock_group = p.stock_group
             AND COALESCE(m.is_stock_master,0)=1
            WHERE p.id=? LIMIT 1";
  } else {
    $sql = "SELECT p.id,p.name,p.price,p.image,p.stock,p.is_active,
                   NULL AS stock_group,NULL AS is_stock_master,
                   p.allow_cover_print AS allow_cover,
                   NULL AS master_stock
            FROM products p
            WHERE p.id=? LIMIT 1";
  }
  $st = $pdo->prepare($sql); $st->execute([$pid]); $p = $st->fetch(PDO::FETCH_ASSOC);
  if (!$p) return null;

  $eff = (int)$p['stock'];
  if ($hasGroup && $hasMaster && !empty($p['stock_group']) && empty($p['is_stock_master'])) {
    $eff = (int)($p['master_stock'] ?? $p['stock']); // ใช้สต๊อคตัวหลัก
  }
  $p['eff_stock']   = $eff;
  $p['allow_cover'] = isset($p['allow_cover']) ? (int)$p['allow_cover'] : 1;
  return $p;
}

/** คำนวณจำนวนที่ “จองไว้” (unpaid) ทั้งกลุ่ม ถ้ามี stock_group, ไม่งั้นดูตาม product_id */
function compute_reserved(PDO $pdo, array $p): int {
  $group = trim((string)($p['stock_group'] ?? ''));
  if ($group !== '' && has_column($pdo,'products','stock_group')) {
    $st = $pdo->prepare("
      SELECT COALESCE(SUM(oi.qty),0)
      FROM order_items oi
      JOIN orders o   ON o.id = oi.order_id
      JOIN products p2 ON p2.id = oi.product_id
      WHERE p2.stock_group = ?
        AND o.status='unpaid'
        AND (o.expires_at IS NULL OR o.expires_at > NOW())
    ");
    $st->execute([$group]); return (int)$st->fetchColumn();
  }
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(oi.qty),0)
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.product_id = ?
      AND o.status='unpaid'
      AND (o.expires_at IS NULL OR o.expires_at > NOW())
  ");
  $st->execute([(int)$p['id']]); return (int)$st->fetchColumn();
}

/* ================= Session ================= */
if (!isset($_SESSION['cart']))        $_SESSION['cart'] = [];                  // [pid=>qty]
if (!isset($_SESSION['cover_opts']))  $_SESSION['cover_opts'] = [];            // [pid=>['on'=>0/1,'note'=>'']]

// MIGRATE: จากรุ่นเก่า cover_lines/cover_note -> cover_opts (ครั้งเดียวพอ)
if (empty($_SESSION['cover_opts']) && !empty($_SESSION['cover_lines'])) {
  $mNote = $_SESSION['cover_note'] ?? '';
  foreach ((array)$_SESSION['cover_lines'] as $legacyPid) {
    $_SESSION['cover_opts'][(int)$legacyPid] = ['on'=>1,'note'=>$mNote];
  }
}
$cart       = &$_SESSION['cart'];
$cover_opts = &$_SESSION['cover_opts'];

/* ===== ค่าพิมพ์ปก (ตั้งค่าใน settings ได้) ===== */
$cover_base      = (float) get_setting($pdo, 'cover_base', 500);
$cover_over_rate = (float) get_setting($pdo, 'cover_over_rate', 2);
$cover_threshold = (int)   get_setting($pdo, 'cover_threshold_qty', 50);

/* ================= Actions ================= */
$act = $_POST['action'] ?? $_GET['action'] ?? '';

/* --- ADD TO CART (จาก product.php) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'add') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    flash('error','หมดเวลา กรุณาลองใหม่'); header('Location: '.BASE_URL.'/index.php'); exit;
  }
  $pid = (int)($_POST['id'] ?? 0);
  $qty = max(1, (int)($_POST['qty'] ?? 1));

  $p = fetch_product_with_eff_stock($pdo, $pid);
  if (!$p || (int)$p['is_active'] !== 1) {
    flash('error','ไม่พบสินค้าหรือปิดการขายแล้ว');
    header('Location: '.BASE_URL.'/index.php'); exit;
  }

  // พร้อมขายจริง = eff_stock - reserved(ทั้งกลุ่มถ้ามี)
  $reserved  = compute_reserved($pdo, $p);
  $available = max(0, (int)$p['eff_stock'] - $reserved);

  // กันกรณีมีของในตะกร้าอยู่แล้ว (รวมตัวรองในกลุ่มเดียวกันด้วย)
  $inCartGroup = 0;
  if (!empty($p['stock_group'])) {
    if ($cart) {
      $ids = array_keys($cart);
      $in  = implode(',', array_fill(0,count($ids),'?'));
      $st  = $pdo->prepare("SELECT id,stock_group FROM products WHERE id IN ($in)");
      $st->execute($ids);
      foreach ($st as $row) {
        if (trim((string)$row['stock_group']) === trim((string)$p['stock_group'])) {
          $inCartGroup += (int)$cart[(int)$row['id']];
        }
      }
    }
  } else {
    $inCartGroup = (int)($cart[$pid] ?? 0);
  }

  $newQty = min($inCartGroup + $qty, $available);
  if ($newQty <= $inCartGroup) {
    flash('error','สินค้าหมดชั่วคราว'); header('Location: '.BASE_URL.'/product.php?id='.$pid); exit;
  }

  // เซ็ตจำนวนใหม่ให้ product ปัจจุบัน
  $cart[$pid] = ($cart[$pid] ?? 0) + ($newQty - $inCartGroup);
  flash('success','เพิ่มสินค้าลงตะกร้าแล้ว');
  header('Location: '.BASE_URL.'/cart.php'); exit;
}

/* --- เปลี่ยนจำนวน (AJAX) --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && $act==='update_qty') {
  if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF'); }
  $pid = (int)($_POST['pid'] ?? 0);
  $req = max(0, (int)($_POST['qty'] ?? 0));
  if ($pid > 0) {
    $p = fetch_product_with_eff_stock($pdo, $pid);
    if ($p) {
      $qty = min($req, max(0,(int)$p['eff_stock'])); // clamp ตามสต๊อคตัวหลัก
      if ($qty <= 0) { unset($cart[$pid]); } else { $cart[$pid] = $qty; }
    } else {
      unset($cart[$pid]);
    }
  }
  header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
}

/* --- ติ๊ก/ยกเลิก “พิมพ์ปก” (AJAX) --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && $act==='toggle_cover') {
  if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF'); }
  $pid  = (int)($_POST['pid'] ?? 0);
  $val  = (int)($_POST['val'] ?? 0);
  $note = trim($_POST['note'] ?? '');
  if ($pid>0) {
    if ($val) { $cover_opts[$pid] = ['on'=>1,'note'=>$note]; }
    else { unset($cover_opts[$pid]); }
  }
  $_SESSION['cover_print'] = !empty($cover_opts) ? 'yes' : 'no';
  header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
}

/* --- ล้างตะกร้า --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && $act==='clear_cart') {
  if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF'); }
  $_SESSION['cart']        = [];
  $_SESSION['cover_opts']  = [];
  $_SESSION['cover_print'] = 'no';
  header('Location: '.BASE_URL.'/cart.php'); exit;
}

/* ================== สร้างรายการในตะกร้า (ใช้ eff_stock) ================== */
$ids = array_keys($cart);
$items = []; $subtotal=0.0;

if ($ids) {
  foreach ($ids as $pid) {
    $p = fetch_product_with_eff_stock($pdo, (int)$pid);
    if (!$p || (int)$p['is_active'] !== 1) { unset($cart[$pid]); continue; }

    $qty = max(0, min((int)$cart[$pid], (int)$p['eff_stock']));
    $cart[$pid] = $qty;
    if ($qty <= 0) { unset($cart[$pid]); continue; }

    $img   = $p['image'] ? BASE_URL.'/'.ltrim($p['image'],'/') : BASE_URL.'/assets/images/noimg.png';
    $line  = (float)$p['price'] * $qty;
    $subtotal += $line;

    $co = $cover_opts[(int)$p['id']] ?? ['on'=>0,'note'=>''];

    $items[] = [
      'id'          => (int)$p['id'],
      'name'        => $p['name'],
      'price'       => (float)$p['price'],
      'img'         => $img,
      'qty'         => $qty,
      'eff_stock'   => (int)$p['eff_stock'],
      'allow_cover' => (int)$p['allow_cover'],
      'cover_on'    => (int)($co['on'] ?? 0),
      'cover_note'  => (string)($co['note'] ?? ''),
    ];
  }
}

/* ===== ค่าพิมพ์ปกรวม (ตามรายการที่ติ๊ก) ===== */
$cover_selected_qty = 0;
foreach ($items as $it) if (!empty($it['cover_on'])) $cover_selected_qty += $it['qty'];
$cover_fee = 0.0;
if ($cover_selected_qty > 0) {
  $over = max(0, $cover_selected_qty - $cover_threshold);
  $cover_fee = $cover_base + $over * $cover_over_rate;
}
$_SESSION['cover_print'] = ($cover_selected_qty > 0) ? 'yes' : 'no';

/* ================== UI ================== */
?>

<style>
  /* =================================
     == สไตล์สำหรับจอคอมพิวเตอร์ (Default) ==
     ================================= */
  body .container,
  .container {
    max-width: 100% !important;
    width: 100% !important;
    padding-left: 1rem;
    padding-right: 1rem;
  }

  .table-wrap {
    overflow-x: auto;
  }

  .table {
    width: 100%;
    table-layout: auto;
  }

  .table th,
  .table td {
    padding: .6rem;
    border-bottom: 1px solid #23234a;
    vertical-align: middle;
  }

  .card {
    border: 1px solid #23234a;
    border-radius: 1rem;
    padding: 1rem;
    margin: .8rem 0;
  }

  .small {
    opacity: .85;
  }

  .qtybox {
    display: inline-grid;
    grid-template-columns: 26px 72px 26px;
    gap: .25rem;
    align-items: center;
  }

  .qtybox .btn {
    min-width: auto;
    padding: .2rem .45rem;
  }

  .qtybox input {
    height: 36px;
    text-align: center;
  }

  .badge {
    display: inline-block;
    padding: .15rem .45rem;
    border-radius: .5rem;
    background: #23234a;
    color: #fff;
  }
  
  /* รูปและข้อมูลสินค้า */
  .prod-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .prod-img {
    flex-shrink: 0;
    width: 96px;
    aspect-ratio: 4/3;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #dfe3f0;
    background: #fff;
  }

  .prod-info {
    min-width: 0;
  }

  .prod-title {
    font-weight: 700;
    line-height: 1.25;
  }

  .prod-title span {
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    display: -webkit-box;
    overflow: hidden;
  }

  .prod-meta {
    font-size: .85rem;
    opacity: .8;
    margin-top: .2rem;
  }

  /* =================================
     == สไตล์สำหรับจอมือถือ (Responsive) ==
     ================================= */
  @media (max-width: 768px) {
    /* ซ่อนหัวตาราง */
    .table thead {
      display: none;
    }

    /* ทำให้แต่ละแถวเป็นเหมือนการ์ด */
    .table tbody tr {
      display: block;
      border: 1px solid #23234a;
      border-radius: 1rem;
      padding: .8rem;
      margin-bottom: 1rem;
    }

    /* ทำให้แต่ละช่องข้อมูลเรียงแนวตั้ง */
    .table td {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .6rem .2rem;
      border: none;
      border-bottom: 1px dashed rgba(255, 255, 255, 0.1);
      text-align: right;
    }

    .table tr td:last-child {
      border-bottom: none;
    }

    /* สร้าง Label ด้วย ::before และ nth-child() */
    .table td::before {
      content: '';
      font-weight: bold;
      text-align: left;
      margin-right: 1rem;
    }

    .table td:nth-child(2)::before { content: "ราคา/ชิ้น"; }
    .table td:nth-child(3)::before { content: "จำนวน"; }
    .table td:nth-child(4)::before { content: "พิมพ์ปก"; }
    .table td:nth-child(5)::before { content: "ยอดรวม"; }
    .table td:nth-child(6)::before { content: "จัดการ"; }

    /* จัดการคอลัมน์แรก (สินค้า) เป็นกรณีพิเศษ */
    .table td:nth-child(1) {
      display: block;
      text-align: left;
      margin-bottom: .5rem;
    }

    .table td:nth-child(1)::before {
      display: none;
    }
    
    /* ปรับขนาดรูปให้เล็กลงในมือถือ */
    .prod-img {
      width: 64px;
      aspect-ratio: 1/1;
    }
    
    /* ปรับปุ่มนำออกให้เต็มความกว้าง */
    .table td:nth-child(6) .js-remove {
      width: 100%;
    }

    /* ปรับกล่องจำนวนให้ยืดหยุ่น */
    .qtybox {
      grid-template-columns: 28px 1fr 28px;
      width: 120px;
    }

    /* ปุ่มบนสุดให้เต็มความกว้าง */
    div > .btn.outline,
    div > .btn {
      flex-grow: 1;
      text-align: center;
    }
  }
  /* เพิ่มส่วนนี้เข้าไปใน <style> ของคุณ */
.table td img {
  max-width: 100%; /* ทำให้รูปภาพไม่เกินความกว้างของช่องตาราง */
  height: auto;    /* รักษาสัดส่วนของรูปภาพไว้ */
  object-fit: contain; /* ปรับรูปให้พอดีช่อง โดยไม่ตัดส่วนใดออก */
}

/* ถ้าต้องการจำกัดความสูงด้วย (อาจจะช่วยให้ไม่ยาวเกินไป) */
.table td .prod-img { /* เลือกเฉพาะรูปสินค้าปกติ */
  max-height: 120px; /* กำหนดความสูงสูงสุด (ปรับได้ตามต้องการ) */
  width: auto;       /* ให้ความกว้างปรับตามสัดส่วน */
}

/* หากรูปยาวๆ นั้นเป็น banner หรือ promotion */
/* ให้ลองตรวจสอบ class ของรูปนั้น แล้วกำหนด max-width, max-height ให้เหมาะสม */
/* ตัวอย่าง: ถ้าเป็น class .promo-banner */
.promo-banner {
  max-width: 100%;
  height: auto;
  max-height: 100px; /* หรือความสูงที่เหมาะสมสำหรับ banner */
}
</style>

<h2>ตะกร้าสินค้า</h2>

<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.6rem">
  <a class="btn outline" href="<?= BASE_URL ?>/index.php">เลือกซื้อสินค้า</a>
  <form method="post" onsubmit="return confirm('ล้างตะกร้าทั้งหมด?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_cart">
    <button class="btn outline" type="submit">ล้างตะกร้า</button>
  </form>
</div>

<?php if (!$items): ?>
  <div class="alert">ยังไม่มีสินค้าในตะกร้า</div>
  <?php require_once __DIR__ . '/partials/footer.php'; exit; ?>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
    <table class="table" id="cartTable">
      <thead>
        <tr>
          <th>สินค้า</th>
          <th style="width:120px">ราคา/ชิ้น</th>
          <th style="width:150px">จำนวน</th>
          <th style="width:220px">พิมพ์ปก</th>
          <th style="width:160px">ยอดรวม</th>
          <th style="width:90px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($items as $it): ?>
        <?php $checked = !empty($it['cover_on']); $noteVal = $it['cover_note'] ?? ''; ?>
        <tr data-pid="<?= (int)$it['id'] ?>"
            data-price="<?= htmlspecialchars($it['price']) ?>"
            data-qty="<?= (int)$it['qty'] ?>"
            data-allow-cover="<?= (int)$it['allow_cover'] ?>">
          <td>
            <div class="prod-wrap">
              <img class="prod-img" src="<?= htmlspecialchars($it['img']) ?>" alt="">
              <div class="prod-info">
                <div class="prod-title"><span><?= htmlspecialchars($it['name']) ?></span></div>
           <!--     <div class="prod-meta">คงเหลือ: <?= (int)$it['eff_stock'] ?></div>  -->
              </div>
            </div>
          </td>
          <td>฿<?= money($it['price']) ?></td>
          <td>
            <div class="qtybox">
              <button class="btn outline js-minus" type="button">−</button>
              <input class="input js-qty" type="number" min="0" step="1" value="<?= (int)$it['qty'] ?>">
              <button class="btn outline js-plus" type="button">＋</button>
            </div>
          </td>
          <td>
            <?php if ($it['allow_cover']): ?>
              <label style="display:flex;gap:.4rem;align-items:center">
                <input type="checkbox" class="js-cover" <?= $checked?'checked':'' ?>> พิมพ์ปก
              </label>
              <textarea class="input js-note" rows="2"
                        placeholder="รายละเอียดพิมพ์ปก (ถ้ามี)"
                        style="<?= $checked?'display:block':'display:none' ?>"><?= htmlspecialchars($noteVal) ?></textarea>
            <?php else: ?>
              <span class="badge">ไม่รองรับพิมพ์ปก</span>
            <?php endif; ?>
          </td>
          <td><div class="line-total">฿<span class="js-lineT">0.00</span></div></td>
          <td><button class="btn outline js-remove" type="button">นำออก</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $grand = max(0, $subtotal + $cover_fee); ?>
<div class="card summary">
  <div class="summary-row" style="display:flex;justify-content:space-between;margin:.25rem 0">
    <div>ยอดสินค้า</div><div>฿<span id="sumProducts"><?= money($subtotal) ?></span></div>
  </div>
  <div class="summary-row" style="display:flex;justify-content:space-between;margin:.25rem 0">
    <div>ค่าพิมพ์ปก</div><div>฿<span id="sumCover"><?= money($cover_fee) ?></span></div>
  </div>
  <hr style="border:none;border-top:1px solid #23234a;margin:.6rem 0">
  <div class="summary-row" style="display:flex;justify-content:space-between;margin:.25rem 0;font-weight:700">
    <div>ยอดสุทธิ</div><div>฿<span id="sumGrand"><?= money($grand) ?></span></div>
  </div>

  <div class="small" style="opacity:.85;margin-top:.35rem">
    * ค่าพิมพ์ปก = เปิดบล็อก <?= money($cover_base) ?> + (เกิน <?= (int)$cover_threshold ?> เล่ม + เล่มละ <?= money($cover_over_rate) ?> บาท)
  </div>

  <div style="margin-top:.6rem;display:flex;gap:.5rem;flex-wrap:wrap">
    <a class="btn" href="<?= BASE_URL ?>/checkout.php">ชำระเงิน</a>
    <a class="btn outline" href="<?= BASE_URL ?>/index.php">เลือกซื้อสินค้าต่อ</a>
  </div>
</div>

<!-- meta สำหรับ JS -->
<div id="meta"
     data-cover-base="<?= htmlspecialchars($cover_base) ?>"
     data-cover-rate="<?= htmlspecialchars($cover_over_rate) ?>"
     data-cover-th="<?= htmlspecialchars($cover_threshold) ?>"
     style="display:none"></div>
<div id="csrfBox" style="display:none"><?= csrf_field() ?></div>

<script>
(function(){
  const $ = (s,p=document)=>p.querySelector(s);
  const $$ = (s,p=document)=>Array.from(p.querySelectorAll(s));
  const meta = $('#meta');
  const COVER_BASE = parseFloat(meta.dataset.coverBase || '500');
  const COVER_RATE = parseFloat(meta.dataset.coverRate || '2');
  const COVER_TH   = parseInt  (meta.dataset.coverTh   || '50', 10);

  const csrf = ($('#csrfBox input[name="csrf"]')||{}).value || '';
  const fmt = n => Number(n||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});
  const num = v => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
  const post = fd => fetch('<?= BASE_URL ?>/cart.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).catch(()=>{});

  function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }
  const sendQty   = debounce((pid,qty)=>{ const fd=new FormData(); fd.append('csrf',csrf); fd.append('action','update_qty');   fd.append('pid',pid); fd.append('qty',qty); post(fd); },200);
  const sendCover = debounce((pid,val,note)=>{ const fd=new FormData(); fd.append('csrf',csrf); fd.append('action','toggle_cover'); fd.append('pid',pid); fd.append('val',val?1:0); fd.append('note',note||''); post(fd); },200);

  function compute(){
    const rows = $$('#cartTable tbody tr');
    let sub=0, coverQty=0;

    rows.forEach(tr=>{
      const price = num(tr.dataset.price);
      const qty   = Math.max(0, parseInt(tr.querySelector('.js-qty')?.value||'0',10));
      const allow = tr.dataset.allowCover === '1';
      const checked = allow && tr.querySelector('.js-cover')?.checked;
      sub += price*qty;
      if (checked) coverQty += qty;
    });

    let cover=0;
    if (coverQty>0){
      const over = Math.max(0, coverQty - COVER_TH);
      cover = COVER_BASE + over*COVER_RATE;
    }

    // ใส่ค่าปกลงในแถวแรกที่ติ๊ก
    let injected=false;
    rows.forEach(tr=>{
      const price = num(tr.dataset.price);
      const qty   = Math.max(0, parseInt(tr.querySelector('.js-qty')?.value||'0',10));
      const allow = tr.dataset.allowCover === '1';
      const checked = allow && tr.querySelector('.js-cover')?.checked;
      let line = price*qty;
      if (checked && !injected && cover>0){ line += cover; injected=true; }
      tr.querySelector('.js-lineT').textContent = fmt(line);
    });

    $('#sumProducts').textContent = fmt(sub);
    $('#sumCover').textContent    = fmt(cover);
    $('#sumGrand').textContent    = fmt(sub + cover);
  }

  const table = $('#cartTable');
  table.addEventListener('click', (e)=>{
    const plus  = e.target.closest('.js-plus');
    const minus = e.target.closest('.js-minus');
    const rm    = e.target.closest('.js-remove');
    if (!plus && !minus && !rm) return;
    e.preventDefault();

    const tr   = e.target.closest('tr');
    const pid  = parseInt(tr.dataset.pid,10);
    const qtyEl= tr.querySelector('.js-qty');

    if (plus){ let q = Math.max(0, parseInt(qtyEl.value||'0',10))+1; qtyEl.value=q; sendQty(pid,q); compute(); }
    if (minus){ let q = Math.max(0, parseInt(qtyEl.value||'0',10)-1); qtyEl.value=q; sendQty(pid,q); compute(); }
    if (rm){ qtyEl.value=0; sendQty(pid,0); tr.remove(); compute(); }
  });

  table.addEventListener('input', (e)=>{
    if (!e.target.classList.contains('js-qty')) return;
    const tr = e.target.closest('tr'); const pid = parseInt(tr.dataset.pid,10);
    let q = Math.max(0, parseInt(e.target.value||'0',10)); e.target.value=q; sendQty(pid,q); compute();
  });

  table.addEventListener('change', (e)=>{
    if (!e.target.classList.contains('js-cover')) return;
    const tr = e.target.closest('tr'); const pid = parseInt(tr.dataset.pid,10);
    const note = tr.querySelector('.js-note');
    if (note) note.style.display = e.target.checked ? 'block' : 'none';
    sendCover(pid, e.target.checked, note?note.value:''); compute();
  });

  table.addEventListener('input', (e)=>{
    if (!e.target.classList.contains('js-note')) return;
    const tr = e.target.closest('tr'); const pid = parseInt(tr.dataset.pid,10);
    const cover = tr.querySelector('.js-cover'); sendCover(pid, !!(cover&&cover.checked), e.target.value);
  });

  compute();
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
