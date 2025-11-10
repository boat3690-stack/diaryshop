<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../partials/header.php';
if (function_exists('require_admin')) require_admin();

/* ===================== helpers (schema-safe) ===================== */
function has_table(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function has_column(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table,$col]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function money($n){ return number_format((float)$n,2,'.',','); }

/* ===================== actions ===================== */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$errors = [];

/* --- AJAX: toggle allow_cover_print --- */
if ($action === 'toggle_cover' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF'); }
  $id  = (int)($_POST['id'] ?? 0);
  $val = (int)($_POST['val'] ?? 0) ? 1 : 0;
  try {
    $pdo->prepare("UPDATE products SET allow_cover_print=? WHERE id=?")->execute([$val,$id]);
  } catch(Throwable $e) { /* ignore */ }
  header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
}

/* --- AJAX: toggle is_active (แสดงบนเว็บ) --- */
if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF'); }
  $id  = (int)($_POST['id'] ?? 0);
  $val = (int)($_POST['val'] ?? 0) ? 1 : 0;
  try {
    $pdo->prepare("UPDATE products SET is_active=? WHERE id=?")->execute([$val,$id]);
  } catch(Throwable $e) { http_response_code(500); exit('DB error'); }
  header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
}

/* --- POST: set primary image (gallery) --- */
if ($action === 'img_primary' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); header('Location: '.BASE_URL.'/admin/products.php?edit='.(int)$_POST['pid']); exit; }
  $pid = (int)($_POST['pid'] ?? 0);
  $iid = (int)($_POST['img_id'] ?? 0);
  if ($pid>0 && $iid>0 && has_table($pdo,'product_images')) {
    try{
      $pdo->beginTransaction();
      if (has_column($pdo,'product_images','is_primary')) {
        $pdo->prepare("UPDATE product_images SET is_primary=0 WHERE product_id=?")->execute([$pid]);
        $pdo->prepare("UPDATE product_images SET is_primary=1 WHERE id=? AND product_id=?")->execute([$iid,$pid]);
      }
      $col = has_column($pdo,'product_images','path') ? 'path'
           : (has_column($pdo,'product_images','image_path') ? 'image_path' : null);
      if ($col) {
        $st = $pdo->prepare("SELECT $col FROM product_images WHERE id=? AND product_id=?");
        $st->execute([$iid,$pid]); $p = $st->fetchColumn();
        if ($p !== false) $pdo->prepare("UPDATE products SET image=? WHERE id=?")->execute([$p,$pid]);
      }
      $pdo->commit();
      flash('success','ตั้งรูปหลักแล้ว');
    }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); flash('error','ตั้งรูปหลักไม่สำเร็จ'); }
  }
  header('Location: '.BASE_URL.'/admin/products.php?edit='.$pid); exit;
}

/* --- POST: delete image (gallery) --- */
if ($action === 'img_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); header('Location: '.BASE_URL.'/admin/products.php?edit='.(int)$_POST['pid']); exit; }
  $pid = (int)($_POST['pid'] ?? 0);
  $iid = (int)($_POST['img_id'] ?? 0);
  if ($pid>0 && $iid>0 && has_table($pdo,'product_images')) {
    try{
      $col = has_column($pdo,'product_images','path') ? 'path'
           : (has_column($pdo,'product_images','image_path') ? 'image_path' : null);
      $pdo->beginTransaction();
      $path = null;
      if ($col){
        $st = $pdo->prepare("SELECT $col FROM product_images WHERE id=? AND product_id=?");
        $st->execute([$iid,$pid]); $path = $st->fetchColumn();
      }
      $pdo->prepare("DELETE FROM product_images WHERE id=? AND product_id=?")->execute([$iid,$pid]);

      if ($path) {
        $cur = $pdo->prepare("SELECT image FROM products WHERE id=?");
        $cur->execute([$pid]); $main = $cur->fetchColumn();
        if ($main && $main === $path) {
          if ($col) {
            $st2 = $pdo->prepare("SELECT $col FROM product_images WHERE product_id=? ORDER BY id ASC LIMIT 1");
            $st2->execute([$pid]); $new = $st2->fetchColumn();
            $pdo->prepare("UPDATE products SET image=? WHERE id=?")->execute([$new ?: null, $pid]);
          } else {
            $pdo->prepare("UPDATE products SET image=NULL WHERE id=?")->execute([$pid]);
          }
        }
      }
      $pdo->commit();
      flash('success','ลบรูปแล้ว');
    }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); flash('error','ลบรูปไม่สำเร็จ'); }
  }
  header('Location: '.BASE_URL.'/admin/products.php?edit='.$pid); exit;
}

/* --- Save (edit form) --- */
if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $errors[]='CSRF invalid'; }
  $id    = (int)($_POST['id'] ?? 0);
  $name  = trim($_POST['name'] ?? '');

  // ราคาอนุญาต 0 ขึ้นไป
  $price_raw = trim($_POST['price'] ?? '');
  if ($price_raw === '' || !is_numeric($price_raw)) {
    $errors[] = 'กรอกราคาให้ถูกต้อง';
    $price = 0.0;
  } else {
    $price = max(0.0, (float)$price_raw);
  }

  $stock = (int)($_POST['stock'] ?? 0);
  $sku   = trim($_POST['sku'] ?? '');
  $desc  = trim($_POST['description'] ?? '');
  $allow = isset($_POST['allow_cover_print']) ? 1 : 0;
  $active= isset($_POST['is_active']) ? 1 : 0;

  // สต๊อกร่วม (ถ้ามี)
  $stock_group    = trim($_POST['stock_group'] ?? '');
  $is_stock_master= isset($_POST['is_stock_master']) ? 1 : 0;

  // ===== NEW: ตรวจคอลัมน์สต๊อกเริ่มต้น และอ่านค่าจากฟอร์ม =====
  $hasInit1 = has_column($pdo,'products','initial_stock');
  $hasInit2 = has_column($pdo,'products','stock_initial');
  $initCol  = $hasInit1 ? 'initial_stock' : ($hasInit2 ? 'stock_initial' : null);
  $initial_stock = null;
  if ($initCol !== null) {
    $is_raw = trim($_POST['initial_stock'] ?? '');
    if ($is_raw === '' || !is_numeric($is_raw)) {
      $errors[] = 'กรอกสต๊อกเริ่มต้นให้ถูกต้อง';
      $initial_stock = 0;
    } else {
      $initial_stock = max(0, (int)$is_raw);
    }
  }

  if ($name==='') $errors[]='กรอกชื่อสินค้า';
  if ($stock<0)   $errors[]='สต๊อกห้ามติดลบ';

  // อัปโหลด "รูปหลัก" (แทนที่)
  $imageSet = null;
  if (!empty($_FILES['image']['name'])) {
    $path = handle_upload($_FILES['image'], 'uploads/products', ['jpg','jpeg','png','webp','gif']);
    if ($path) $imageSet = $path; else $errors[]='อัปโหลดรูปหลักไม่สำเร็จ';
  }

  // อัปโหลด "หลายรูป" เพิ่มในแกลเลอรี
  $moreImages = [];
  if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
    $f = $_FILES['images'];
    for ($i=0; $i<count($f['name']); $i++){
      if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
      $one = [
        'name'=>$f['name'][$i], 'type'=>$f['type'][$i],
        'tmp_name'=>$f['tmp_name'][$i], 'error'=>$f['error'][$i], 'size'=>$f['size'][$i]
      ];
      $p = handle_upload($one, 'uploads/products', ['jpg','jpeg','png','webp','gif']);
      if ($p) $moreImages[] = $p;
    }
  }

  if (!$errors) {
    // UPDATE ตาม schema
    $cols  = ['name=?','price=?','stock=?','sku=?','description=?','is_active=?'];
    $param = [$name,$price,$stock,$sku,$desc,$active];

    if (has_column($pdo,'products','allow_cover_print')) { $cols[]='allow_cover_print=?'; $param[]=$allow; }
    if (has_column($pdo,'products','stock_group'))      { $cols[]='stock_group=?';       $param[]=$stock_group ?: null; }
    if (has_column($pdo,'products','is_stock_master'))  { $cols[]='is_stock_master=?';   $param[]=$is_stock_master; }

    // ===== NEW: บันทึกสต๊อกเริ่มต้นถ้ามีคอลัมน์
    if ($initCol !== null) { $cols[] = $initCol.'=?'; $param[] = $initial_stock; }

    if ($imageSet){ $cols[]='image=?'; $param[]=$imageSet; }

    $param[] = $id;
    $sql = "UPDATE products SET ".implode(',', $cols)." WHERE id=?";
    $pdo->prepare($sql)->execute($param);

    // เพิ่มรูปแกลเลอรี
    if ($moreImages && has_table($pdo,'product_images')) {
      $colImg = has_column($pdo,'product_images','path') ? 'path'
               : (has_column($pdo,'product_images','image_path') ? 'image_path' : null);
      if ($colImg){
        $hasPrimary = has_column($pdo,'product_images','is_primary');
        $colSort    = has_column($pdo,'product_images','sort_order') ? 'sort_order'
                     : (has_column($pdo,'product_images','position') ? 'position' : null);
        foreach($moreImages as $i => $p){
          $colsI = ['product_id', $colImg];
          $valsI = ['?','?'];
          $prmI  = [$id, $p];
          if ($hasPrimary){ $colsI[]='is_primary'; $valsI[]='?'; $prmI[] = 0; }
          if ($colSort){    $colsI[]=$colSort;     $valsI[]='?'; $prmI[] = $i; }
          $sqlI = "INSERT INTO product_images (".implode(',',$colsI).") VALUES (".implode(',',$valsI).")";
          try{ $pdo->prepare($sqlI)->execute($prmI); }catch(Throwable $e){}
        }
      }
    }

    flash('success','บันทึกสินค้าแล้ว');
    header('Location: '.BASE_URL.'/admin/products.php?edit='.$id); exit;
  }
}

/* --- DELETE product (พร้อม fallback ปิดการขาย) --- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); header('Location: '.BASE_URL.'/admin/products.php'); exit; }
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) { flash('error','ไม่พบสินค้า'); header('Location: '.BASE_URL.'/admin/products.php'); exit; }

  try {
    $pdo->beginTransaction();
    if (has_table($pdo,'product_images')) {
      $pdo->prepare("DELETE FROM product_images WHERE product_id=?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
    $pdo->commit();
    flash('success','ลบสินค้า #'.$id.' แล้ว');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try {
      if (has_column($pdo,'products','is_active')) {
        $pdo->prepare(
          "UPDATE products
             SET is_active=0,
                 stock=0,
                 name = CASE
                          WHEN name LIKE '%(เลิกจำหน่าย)%' THEN name
                          ELSE CONCAT(name,' (เลิกจำหน่าย)')
                        END
           WHERE id=?"
        )->execute([$id]);
        flash('error','ไม่สามารถลบได้เนื่องจากถูกใช้งานในระบบ — ปิดการแสดงผลและทำเครื่องหมายเลิกจำหน่ายแล้ว (#'.$id.')');
      } else {
        flash('error','ไม่สามารถลบสินค้าได้: '.$e->getMessage());
      }
    } catch (Throwable $e2) {
      flash('error','ล้มเหลวในการลบ: '.$e2->getMessage());
    }
  }
  header('Location: '.BASE_URL.'/admin/products.php'); exit;
}

/* ===================== read list & edit ===================== */
$q = trim($_GET['q'] ?? '');
$where = 'WHERE 1'; $p=[];
if ($q!==''){ $where.=' AND (name LIKE ? OR sku LIKE ?)'; $p=["%$q%","%$q%"]; }

$hasCover = has_column($pdo,'products','allow_cover_print');
$hasGroup = has_column($pdo,'products','stock_group');
$hasMaster= has_column($pdo,'products','is_stock_master');
/* >>> รองรับคอลัมน์สต๊อกเริ่มต้น */
$hasInit1 = has_column($pdo,'products','initial_stock');
$hasInit2 = has_column($pdo,'products','stock_initial');
$hasInit  = $hasInit1 || $hasInit2;
$initCol  = $hasInit1 ? 'initial_stock' : ($hasInit2 ? 'stock_initial' : null);

$selectCols = "id,name,price,stock,sku,image,is_active";
if ($hasCover) $selectCols .= ",allow_cover_print";
if ($hasGroup) $selectCols .= ",stock_group";
if ($hasMaster)$selectCols .= ",is_stock_master";
/* >>> ดึงสต๊อกเริ่มต้น (ถ้ามีคอลัมน์) */
if ($hasInit)  $selectCols .= ", {$initCol} AS initial_stock";

$rowsStmt = $pdo->prepare("SELECT $selectCols FROM products $where ORDER BY id DESC");
$rowsStmt->execute($p);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);  // <-- ใช้งานเป็นอาเรย์

/* ===== Compute available_now (group-aware) ให้เลขตรงกับหน้า order_edit ===== */
try {
  // ดึงสินค้าทั้งหมดเพื่อหา master ของแต่ละกลุ่ม และสต๊อคของตัวแม่
  $allProds = $pdo->query("
    SELECT id, stock, COALESCE(stock_group,'') AS stock_group, COALESCE(is_stock_master,0) AS is_stock_master
    FROM products
  ")->fetchAll(PDO::FETCH_ASSOC);

  $groupMasterId   = [];   // stock_group => master_id
  $masterStockById = [];   // master_id  => stock (ของตัวแม่)
  foreach ($allProds as $p) {
    if (!empty($p['is_stock_master']) && $p['stock_group'] !== '') {
      $groupMasterId[$p['stock_group']] = (int)$p['id'];
      $masterStockById[(int)$p['id']]    = (int)$p['stock'];
    }
  }
  // สำหรับที่ไม่มีกลุ่ม/ไม่มีตัวแม่ → ให้ถือว่าตัวเองเป็นตัวแม่
  foreach ($allProds as $p) {
    $pid = (int)$p['id'];
    if ($p['stock_group']==='' || empty($groupMasterId[$p['stock_group']])) {
      if (!isset($masterStockById[$pid])) $masterStockById[$pid] = (int)$p['stock'];
    }
  }

  // ติดจองของออเดอร์อื่น (เฉพาะ unpaid และยังไม่หมดอายุ)
  $reservedSingle = []; // pid => reserved
  $stA = $pdo->query("
    SELECT oi.product_id AS pid, COALESCE(SUM(oi.qty),0) AS reserved
    FROM order_items oi
    JOIN orders o  ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE o.status='unpaid'
      AND (o.expires_at IS NULL OR o.expires_at > NOW())
      AND (p.stock_group IS NULL OR p.stock_group='')
    GROUP BY oi.product_id
  ");
  foreach ($stA as $r) { $reservedSingle[(int)$r['pid']] = (int)$r['reserved']; }

  $reservedGroup = []; // group_code => reserved
  $stB = $pdo->query("
    SELECT p.stock_group AS g, COALESCE(SUM(oi.qty),0) AS reserved
    FROM order_items oi
    JOIN orders o  ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE o.status='unpaid'
      AND (o.expires_at IS NULL OR o.expires_at > NOW())
      AND (p.stock_group IS NOT NULL AND p.stock_group<>'')
    GROUP BY p.stock_group
  ");
  foreach ($stB as $r) { $reservedGroup[(string)$r['g']] = (int)$r['reserved']; }

  // พร้อมขายของตัวแม่แต่ละตัว (stock ของตัวแม่ - ติดจองในกลุ่ม/ของตัวเอง)
  $groupAvailByMaster = []; // master_id => available_now
  // กลุ่มที่มีตัวแม่
  foreach ($groupMasterId as $g => $mid) {
    $masterStock = (int)($masterStockById[$mid] ?? 0);
    $resv        = (int)($reservedGroup[$g] ?? 0);
    $groupAvailByMaster[$mid] = max(0, $masterStock - $resv);
  }
  // สินค้านอกกลุ่ม/ไม่มีตัวแม่
  foreach ($masterStockById as $mid => $stockMaster) {
    // ถ้า mid ไม่ได้เป็นตัวแม่ของกลุ่ม (คือกรณีสินค้านอกกลุ่มหรือกลุ่มไม่มีแม่) ใช้ reservedSingle
    $isRealMaster = in_array($mid, $groupMasterId, true);
    if (!$isRealMaster) {
      $resv = (int)($reservedSingle[$mid] ?? 0);
      $groupAvailByMaster[$mid] = max(0, (int)$stockMaster - $resv);
    }
  }

  // ใส่ available_now ให้กับสินค้าที่กำลังจะแสดงในตาราง ($rows)
  foreach ($rows as &$r) {
    $g   = isset($r['stock_group']) ? (string)$r['stock_group'] : '';
    $pid = (int)$r['id'];
    $mid = ($g!=='' && isset($groupMasterId[$g])) ? (int)$groupMasterId[$g] : $pid;
    $r['available_now'] = (int)($groupAvailByMaster[$mid] ?? (int)$r['stock']);
  }
  unset($r); // break reference
} catch (Throwable $e) {
  // ถ้าคำนวณไม่ได้ ปล่อยให้ใช้ stock ดิบตามเดิม
}

/* single edit */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = null; $gallery = [];
if ($editId) {
  $cols = "id,name,price,stock,sku,description,image,is_active";
  if ($hasCover) $cols .= ",allow_cover_print";
  if ($hasGroup) $cols .= ",stock_group";
  if ($hasMaster)$cols .= ",is_stock_master";
  if ($hasInit)  $cols .= ", {$initCol} AS initial_stock";
  $st=$pdo->prepare("SELECT $cols FROM products WHERE id=?");
  $st->execute([$editId]);
  $edit = $st->fetch(PDO::FETCH_ASSOC);

  if ($edit && has_table($pdo,'product_images')) {
    $colImg = has_column($pdo,'product_images','path') ? 'path'
             : (has_column($pdo,'product_images','image_path') ? 'image_path' : null);
    if ($colImg) {
      $orderCol = has_column($pdo,'product_images','sort_order') ? 'sort_order'
                : (has_column($pdo,'product_images','position') ? 'position' : 'id');
      $priCol   = has_column($pdo,'product_images','is_primary') ? ',is_primary' : '';
      $g = $pdo->prepare("SELECT id,$colImg AS path$priCol FROM product_images WHERE product_id=? ORDER BY $orderCol ASC, id ASC");
      $g->execute([$editId]); $gallery = $g->fetchAll(PDO::FETCH_ASSOC);
    }
  }
}
?>
<style>
body .container,.container{max-width:1200px!important}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th,.table td{padding:.6rem;border-bottom:1px solid #23234a;vertical-align:top}
.thumb{width:48px;height:48px;object-fit:cover;border-radius:.4rem;border:1px solid #2a2a40}
.card{border:1px solid #23234a;border-radius:1rem;padding:1rem;margin:.8rem 0}
.field{display:flex;flex-direction:column;margin:.6rem 0}
.row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.badge{display:inline-block;padding:.15rem .45rem;border-radius:.4rem;background:#23234a;color:#fff}
.badge.ok{background:#27462c}
.badge.off{background:#4a2b2b}
.switch{display:flex;align-items:center;gap:.4rem}
.gallery{display:flex;gap:.5rem;flex-wrap:wrap}
.gitem{border:1px solid #2a2a40;border-radius:.5rem;padding:.4rem}
.gitem img{width:86px;height:86px;object-fit:cover;border-radius:.3rem;display:block}
.small{opacity:.85}
.btn.danger{border-color:#c53030;color:#c53030;background:#fff}
.btn.danger:hover{background:#ffecec}
.btn.xs{padding:.15rem .45rem;min-height:auto;font-size:.85rem}

.code-previews{display:grid;grid-template-columns:1fr;gap:.75rem;margin:.5rem 0 0}
.code-card {
  border: 1px solid #e0e0e0;
  border-radius: .75rem;
  background: none;
  padding: .75rem;
  color: inherit;
}
.code-card h4{margin:.2rem 0 .6rem;font-size:1rem}
.code-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem}
.code-surface{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:.35rem}
</style>

<div id="csrfBox" style="display:none"><?= csrf_field() ?></div>

<h2>สินค้า</h2>

<form method="get" style="margin:.5rem 0;display:flex;gap:.5rem;flex-wrap:wrap">
  <input class="input" name="q" placeholder="ค้นหาชื่อ/รหัส" value="<?= htmlspecialchars($q) ?>">
  <button class="btn">ค้นหา</button>
  <a class="btn outline" href="<?= BASE_URL ?>/admin/add_product.php">+ เพิ่มสินค้า</a>
</form>

<?php if ($edit): ?>
  <div class="card">
    <h3>แก้ไขสินค้า #<?= (int)$edit['id'] ?></h3>
    <?php if ($errors): ?>
      <div class="alert error"><?php foreach($errors as $e) echo '<div>• '.htmlspecialchars($e).'</div>'; ?></div>
    <?php endif; ?>

    <form id="frmSave" method="post" enctype="multipart/form-data" style="margin-bottom:.6rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

      <div class="row">
        <div class="field">
          <label>ชื่อสินค้า *</label>
          <input class="input" name="name" value="<?= htmlspecialchars($edit['name']) ?>" required>
        </div>
        <div class="field">
          <label>ราคา *</label>
          <input class="input" type="number" step="0.01" min="0" name="price"
                 value="<?= htmlspecialchars((string)$edit['price']) ?>" required>
          <div class="small">ใส่ 0 ได้</div>
        </div>
      </div>

      <div class="row">
        <div class="field">
          <label>สต๊อก *</label>
          <input class="input" type="number" name="stock" min="0" value="<?= (int)$edit['stock'] ?>" required>
          <?php if ($hasInit): ?>
            <label style="margin-top:.6rem">สต๊อกเริ่มต้น</label>
            <input class="input" type="number" name="initial_stock" min="0" value="<?= (int)($edit['initial_stock'] ?? 0) ?>">
            <div class="small">ค่านี้ใช้บันทึก “สต๊อกเริ่มต้น” ของรุ่น/ปี ไม่ปรับยอดคงเหลืออัตโนมัติ</div>
          <?php endif; ?>
        </div>
        <div class="field">
          <label>SKU</label>
          <input class="input" name="sku" id="skuInput" value="<?= htmlspecialchars($edit['sku']) ?>">
        </div>
      </div>
      
      <div class="code-previews">
        <div class="code-card">
          <h4>Barcode (Code128) จาก SKU</h4>
          <div class="code-surface"><svg id="skuBarcode" style="width:100%;height:120px"></svg></div>
          <div class="code-actions">
            <button type="button" class="btn outline" id="dlBarcodePng">ดาวน์โหลดบาร์โค้ด (PNG)</button>
          </div>
        </div>
      </div>

      <?php if ($hasGroup || $hasMaster): ?>
      <div class="row">
        <div class="field">
          <label>Stock Group (สต๊อกร่วม)</label>
          <input class="input" name="stock_group" placeholder="เช่น NOTE-A (เว้นว่าง=แยกสต๊อก)"
                 value="<?= htmlspecialchars($edit['stock_group'] ?? '') ?>">
        </div>
        <div class="field">
          <label>เป็นตัวหลักของกลุ่ม?</label>
          <label style="display:flex;align-items:center;gap:.5rem">
            <input type="checkbox" name="is_stock_master" value="1" <?= !empty($edit['is_stock_master'])?'checked':'' ?>>
            ใช่ (ตัดสต๊อกจากตัวนี้)
          </label>
        </div>
      </div>
      <?php endif; ?>

      <div class="field">
        <label>รายละเอียดสินค้า</label>
        <textarea class="input" name="description" rows="4"><?= htmlspecialchars($edit['description'] ?? '') ?></textarea>
      </div>

      <?php if ($hasCover): ?>
      <label class="switch">
        <input type="checkbox" name="allow_cover_print" value="1" <?= !empty($edit['allow_cover_print'])?'checked':'' ?>>
        อนุญาต “พิมพ์ปก”
      </label>
      <?php endif; ?>

      <label class="switch" style="margin-top:.3rem">
        <input type="checkbox" name="is_active" value="1" <?= !empty($edit['is_active'])?'checked':'' ?>>
        แสดงบนหน้าเว็บไซต์ (index.php)
      </label>

      <div class="row" style="margin-top:.6rem">
        <div class="field">
          <label>รูปหลัก (อัปโหลดเพื่อแทนที่)</label>
          <input class="input" type="file" name="image" accept="image/*">
          <?php if(!empty($edit['image'])): ?>
            <div class="small" style="margin-top:.3rem">ปัจจุบัน:
              <a href="<?= htmlspecialchars(BASE_URL.'/'.ltrim($edit['image'],'/'), ENT_QUOTES) ?>" target="_blank">ดูรูป</a>
            </div>
          <?php endif; ?>
        </div>
        <div class="field">
          <label>เพิ่มรูป (เลือกได้หลายรูป)</label>
          <input class="input" type="file" name="images[]" accept="image/*" multiple>
          <div class="small">* ถ้ามีตาราง <code>product_images</code> จะบันทึกเป็นแกลเลอรี</div>
        </div>
      </div>

      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.6rem">
        <button class="btn" type="submit">บันทึก</button>
        <a class="btn outline" href="<?= BASE_URL ?>/admin/products.php">กลับ</a>
      </div>
    </form>

    <form method="post" action="<?= BASE_URL ?>/admin/products.php"
          onsubmit="return confirm('ลบสินค้า #<?= (int)$edit['id'] ?> ?\nการลบนี้อาจถูกปฏิเสธถ้ามีการอ้างอิงจากคำสั่งซื้อ');"
          style="margin-top:.25rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <button class="btn danger" type="submit">ลบสินค้า</button>
    </form>

    <?php if ($gallery): ?>
      <div class="field" style="margin-top:.8rem">
        <label>แกลเลอรี</label>
        <div class="gallery">
          <?php foreach($gallery as $gi): ?>
            <div class="gitem">
              <img src="<?= htmlspecialchars(BASE_URL.'/'.ltrim($gi['path'],'/'), ENT_QUOTES) ?>" alt="">
              <div class="small" style="margin-top:.25rem">
                <?php if (!empty($gi['is_primary'])): ?>
                  <span class="badge ok">รูปหลัก</span>
                <?php else: ?>
                  <form method="post" style="display:inline" action="<?= BASE_URL ?>/admin/products.php?edit<?= (int)$edit['id'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="img_primary">
                    <input type="hidden" name="pid" value="<?= (int)$edit['id'] ?>">
                    <input type="hidden" name="img_id" value="<?= (int)$gi['id'] ?>">
                    <button class="btn xs" type="submit">ตั้งเป็นรูปหลัก</button>
                  </form>
                <?php endif; ?>
                <form method="post" style="display:inline"
                      action="<?= BASE_URL ?>/admin/products.php?edit=<?= (int)$edit['id'] ?>"
                      onsubmit="return confirm('ลบรูปนี้?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="img_delete">
                  <input type="hidden" name="pid" value="<?= (int)$edit['id'] ?>">
                  <input type="hidden" name="img_id" value="<?= (int)$gi['id'] ?>">
                  <button class="btn xs danger" type="submit">ลบ</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<table class="table" id="prodTable">
  <tr>
    <th>#</th><th>รูป</th><th>สินค้า</th><th>ราคา</th>
    <?php if ($hasInit): ?><th>สต๊อกเริ่มต้น</th><?php endif; ?>
    <th>คงเหลือ</th>
    <?php if ($hasGroup): ?><th>สต๊อกร่วม</th><?php endif; ?>
    <?php if ($hasCover): ?><th>พิมพ์ปกได้</th><?php endif; ?>
    <th>แสดงบนเว็บ</th><th></th>
  </tr>
  <?php foreach($rows as $r): ?>
    <tr data-id="<?= (int)$r['id'] ?>">
      <td>#<?= (int)$r['id'] ?></td>
      <td><?php if(!empty($r['image'])): ?>
        <img class="thumb" src="<?= htmlspecialchars(BASE_URL.'/'.ltrim($r['image'],'/'), ENT_QUOTES) ?>" alt="">
      <?php endif; ?></td>
      <td>
        <div style="font-weight:600"><?= htmlspecialchars($r['name']) ?></div>
        <div class="small" style="opacity:.8">SKU: <?= htmlspecialchars($r['sku']) ?></div>
      </td>
      <td>฿<?= money($r['price']) ?></td>
      <?php if ($hasInit): ?>
        <td><?= (int)($r['initial_stock'] ?? 0) ?></td>
      <?php endif; ?>
      <td>
  <?= (int)($r['available_now'] ?? $r['stock']) ?>
  <?php if (isset($r['available_now'])): ?>
    <div class="small" style="opacity:.75">คงเหลือดิบ: <?= (int)$r['stock'] ?></div>
  <?php endif; ?>
</td>
      <?php if ($hasGroup): ?>
        <td>
          <?php if (!empty($r['stock_group'])): ?>
            <span class="badge"><?= htmlspecialchars($r['stock_group']) ?></span>
            <?php if (!empty($r['is_stock_master'])): ?> <span class="badge ok">หลัก</span> <?php endif; ?>
          <?php else: ?>
            <span class="small">แยกสต๊อก</span>
          <?php endif; ?>
        </td>
      <?php endif; ?>
      <?php if ($hasCover): ?>
      <td>
        <label class="switch">
          <input type="checkbox" class="js-toggle-cover" <?= !empty($r['allow_cover_print']) ? 'checked':'' ?>>
          อนุญาต
        </label>
      </td>
      <?php endif; ?>
      <td>
        <label class="switch">
          <input type="checkbox" class="js-toggle-active" <?= !empty($r['is_active']) ? 'checked':'' ?>>
          <?= !empty($r['is_active']) ? '<span class="badge ok">แสดง</span>' : '<span class="badge off">ไม่แสดง</span>' ?>
        </label>
      </td>
      <td>
        <a class="btn outline" href="?edit=<?= (int)$r['id'] ?>">แก้ไข</a>
        <form method="post" action="<?= BASE_URL ?>/admin/products.php" style="display:inline"
              onsubmit="return confirm('ลบสินค้า #<?= (int)$r['id'] ?> ?\nการลบนี้อาจถูกปฏิเสธถ้ามีการอ้างอิงจากคำสั่งซื้อ');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn danger xs" type="submit">ลบ</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
</table>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>

<script>
(function(){
  const csrf = (document.querySelector('#csrfBox input[name="csrf"]')||{}).value || '';

  document.querySelectorAll('#prodTable .js-toggle-cover').forEach(chk=>{
    chk.addEventListener('change', ()=>{
      const tr  = chk.closest('tr');
      const id  = tr.getAttribute('data-id');
      const fd  = new FormData();
      fd.append('csrf', csrf);
      fd.append('action','toggle_cover');
      fd.append('id', id);
      fd.append('val', chk.checked ? 1 : 0);
      fetch('<?= BASE_URL ?>/admin/products.php?action=toggle_cover', {
        method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}
      });
    });
  });

  document.querySelectorAll('#prodTable .js-toggle-active').forEach(chk=>{
    chk.addEventListener('change', ()=>{
      const tr  = chk.closest('tr');
      const id  = tr.getAttribute('data-id');
      const fd  = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'toggle_active');
      fd.append('id', id);
      fd.append('val', chk.checked ? 1 : 0);
      fetch('<?= BASE_URL ?>/admin/products.php?action=toggle_active', {
        method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}
      }).then(res=>{
        if(!res.ok) return;
        const holder = tr.querySelector('td:nth-last-child(2) label.switch');
        if (!holder) return;
        const badge = holder.querySelector('.badge');
        if (badge) badge.remove();
        const span = document.createElement('span');
        span.className = 'badge ' + (chk.checked ? 'ok' : 'off');
        span.textContent = chk.checked ? 'แสดง' : 'ไม่แสดง';
        holder.appendChild(span);
      });
    });
  });

  if (document.getElementById('frmSave')) {
    const skuInput = document.getElementById('skuInput');
    const bcSvg    = document.getElementById('skuBarcode');

    function renderBarcode(val) {
      const v = (val || '').trim();
      if (!v) { if (bcSvg) bcSvg.innerHTML = ''; return; }
      if (window.JsBarcode && bcSvg) {
        try {
          JsBarcode(bcSvg, v, {
            format: "code128",
            lineColor: "#111",
            width: 2, height: 80, displayValue: true, fontSize: 14, margin: 0
          });
        } catch (e) { bcSvg.innerHTML = ''; }
      }
    }

    function downloadBarcodePng() {
      if (!bcSvg || !bcSvg.innerHTML) return;
      const xml  = new XMLSerializer().serializeToString(bcSvg);
      const data = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(xml)));
      const img  = new Image();
      img.onload = function() {
        const c = document.createElement('canvas');
        c.width = img.width; c.height = img.height;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0,0,c.width,c.height);
        ctx.drawImage(img,0,0);
        const url = c.toDataURL('image/png');
        const a = document.createElement('a');
        a.href = url;
        a.download = 'barcode_' + (skuInput.value||'').replace(/[^A-Za-z0-9_-]/g,'') + '.png';
        a.click();
      };
      img.src = data;
    }

    document.getElementById('dlBarcodePng')?.addEventListener('click', downloadBarcodePng);
    skuInput?.addEventListener('input', e => renderBarcode(e.target.value));
    renderBarcode(skuInput?.value || '');
  }
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
