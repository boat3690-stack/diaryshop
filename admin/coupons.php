<?php
// /admin/coupons.php — Coupons admin (supports item-level coupons)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';
require_admin();

/* ====================== Helpers ====================== */
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function table_exists(PDO $pdo, string $name): bool {
  try { $stmt = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($name)); return (bool)$stmt->fetchColumn(); }
  catch (Exception $e) { return false; }
}
function get_columns_meta(PDO $pdo, string $table): array {
  $out = [];
  try {
    foreach($pdo->query("SHOW COLUMNS FROM `{$table}`") as $r){
      $out[$r['Field']] = $r; // keep whole meta (Type/Null/Key/Default/Extra)
    }
  } catch(Exception $e){}
  return $out;
}
function parse_datetime(?string $str): ?string {
  $str = trim((string)$str);
  if ($str==='') return null;
  $ts = strtotime($str);
  if ($ts===false) return null;
  return date('Y-m-d H:i:s', $ts);
}
function enum_has_values(array $colMeta, array $need): bool {
  if (empty($colMeta['Type'])) return false;
  if (stripos($colMeta['Type'], 'enum(') === false) return false;
  preg_match_all("/'([^']*)'/", $colMeta['Type'], $m);
  $vals = $m[1] ?? [];
  foreach($need as $v){ if(!in_array($v, $vals, true)) return false; }
  return true;
}

/* ====================== Schema Inspect ====================== */
$couponColsMeta = get_columns_meta($pdo, 'coupons');     // meta ของทุกคอลัมน์
$hasCoupons     = !empty($couponColsMeta);
$hasMapTable    = table_exists($pdo, 'coupon_products');

$enumSupportsItem = $hasCoupons
  ? enum_has_values($couponColsMeta['type'] ?? [], ['item_percent','item_fixed'])
  : false;

// products list (best effort)
$allProducts = [];
try {
  $q = $pdo->query("SELECT id, name FROM products ORDER BY name");
  $allProducts = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
} catch(Exception $e){}

/* ====================== Filters / list ====================== */
$q = trim($_GET['q'] ?? '');
$only_active = isset($_GET['active']) ? 1 : 0;

$where=[]; $params=[];
if ($q!==''){ $where[]='(code LIKE ? OR type LIKE ?)'; $params[]="%$q%"; $params[]="%$q%"; }
if ($only_active && isset($couponColsMeta['is_active'])){ $where[]='is_active=1'; }
$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$rows=[];
try{
  $st=$pdo->prepare("SELECT * FROM coupons $whereSql ORDER BY id DESC");
  $st->execute($params);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){
  flash('error','อ่านคูปองไม่สำเร็จ: '.$e->getMessage());
}

/* ====================== Actions ====================== */
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!csrf_check($_POST['csrf'] ?? '')) {
    flash('error','CSRF invalid'); redirect('coupons.php?'.http_build_query($_GET));
  }

  // CREATE
  if ($action==='create'){
    $code = trim($_POST['code'] ?? '');
    $type = trim($_POST['type'] ?? 'fixed');
    $value = (float)($_POST['value'] ?? 0);
    $max_discount = ($_POST['max_discount'] ?? '') !== '' ? (float)$_POST['max_discount'] : null;
    $min_total    = ($_POST['min_total'] ?? '')    !== '' ? (float)$_POST['min_total']    : 0;
    $is_active    = isset($_POST['is_active']) ? 1 : 0;
    $expires_at   = parse_datetime($_POST['expires_at'] ?? null);

    if ($code===''){ flash('error','กรุณากรอกโค้ดคูปอง'); redirect('coupons.php'); }

    // guard: duplicate code
    try{
      $du = $pdo->prepare("SELECT COUNT(*) FROM coupons WHERE UPPER(code)=UPPER(?)");
      $du->execute([$code]);
      if ((int)$du->fetchColumn() > 0){ flash('error','โค้ดนี้มีอยู่แล้ว'); redirect('coupons.php'); }
    }catch(Exception $e){/* best-effort */}

    $allowed = ['percent','fixed','item_percent','item_fixed'];
    if (!in_array($type,$allowed,true)) $type = 'fixed';
    if (($type==='item_percent' || $type==='item_fixed') && !$enumSupportsItem){
      flash('error',"สคีมา coupons.type ยังไม่มีค่า '{$type}' — โปรดอัปเดต ENUM ตามที่หน้าเพจแนะนำ"); redirect('coupons.php');
    }

    // dynamic insert ตามคอลัมน์ที่มีจริง
    $fields=[]; $holders=[]; $vals=[];
    $maybe = [
      'code'=>$code,'type'=>$type,'value'=>$value,
      'max_discount'=>$max_discount,'min_total'=>$min_total,
      'is_active'=>$is_active,'expires_at'=>$expires_at
    ];
    foreach($maybe as $col=>$val){
      if (isset($couponColsMeta[$col])){ $fields[]="`$col`"; $holders[]='?'; $vals[]=$val; }
    }
    if (!$fields){ flash('error','ตาราง coupons ไม่มีคอลัมน์ที่รองรับ'); redirect('coupons.php'); }

    try{
      $sql="INSERT INTO coupons(".implode(',',$fields).") VALUES(".implode(',',$holders).")";
      $pdo->prepare($sql)->execute($vals);
      $cid=(int)$pdo->lastInsertId();

      if ($hasMapTable){
        $prod_ids = array_map('intval', $_POST['products'] ?? []);
        if ($prod_ids){
          $ins=$pdo->prepare("INSERT INTO coupon_products (coupon_id, product_id) VALUES (?,?)");
          foreach($prod_ids as $pid){ $ins->execute([$cid,$pid]); }
        }
      }

      flash('success','สร้างคูปองแล้ว');
      redirect('coupons.php?action=edit&id='.$cid);
    }catch(Exception $e){
      flash('error','บันทึกคูปองไม่สำเร็จ: '.$e->getMessage());
      redirect('coupons.php');
    }
  }

  // UPDATE
  if ($action==='update'){
    $id=(int)($_POST['id'] ?? 0);
    if ($id<=0){ flash('error','ไม่พบคูปอง'); redirect('coupons.php'); }

    $code = trim($_POST['code'] ?? '');
    $type = trim($_POST['type'] ?? 'fixed');
    $value = (float)($_POST['value'] ?? 0);
    $max_discount = ($_POST['max_discount'] ?? '') !== '' ? (float)$_POST['max_discount'] : null;
    $min_total    = ($_POST['min_total'] ?? '')    !== '' ? (float)$_POST['min_total']    : 0;
    $is_active    = isset($_POST['is_active']) ? 1 : 0;
    $expires_at   = parse_datetime($_POST['expires_at'] ?? null);

    $allowed = ['percent','fixed','item_percent','item_fixed'];
    if (!in_array($type,$allowed,true)) $type='fixed';
    if (($type==='item_percent' || $type==='item_fixed') && !$enumSupportsItem){
      flash('error',"สคีมา coupons.type ยังไม่มีค่า '{$type}' — โปรดอัปเดต ENUM ตามที่หน้าเพจแนะนำ"); redirect('coupons.php?action=edit&id='.$id);
    }

    // guard: duplicate code (exclude self)
    try{
      $du = $pdo->prepare("SELECT COUNT(*) FROM coupons WHERE UPPER(code)=UPPER(?) AND id<>?");
      $du->execute([$code,$id]);
      if ((int)$du->fetchColumn() > 0){ flash('error','โค้ดนี้ถูกใช้แล้ว'); redirect('coupons.php?action=edit&id='.$id); }
    }catch(Exception $e){}

    $sets=[]; $vals=[];
    $maybe = [
      'code'=>$code,'type'=>$type,'value'=>$value,
      'max_discount'=>$max_discount,'min_total'=>$min_total,
      'is_active'=>$is_active,'expires_at'=>$expires_at
    ];
    foreach($maybe as $col=>$val){
      if (isset($couponColsMeta[$col])){ $sets[]="`$col`=?"; $vals[]=$val; }
    }
    if (!$sets){ flash('error','ไม่มีคอลัมน์ให้อัปเดต'); redirect('coupons.php?action=edit&id='.$id); }

    try{
      $vals[]=$id;
      $pdo->prepare("UPDATE coupons SET ".implode(',',$sets)." WHERE id=?")->execute($vals);

      if ($hasMapTable){
        $pdo->prepare("DELETE FROM coupon_products WHERE coupon_id=?")->execute([$id]);
        $prod_ids = array_map('intval', $_POST['products'] ?? []);
        if ($prod_ids){
          $ins=$pdo->prepare("INSERT INTO coupon_products (coupon_id, product_id) VALUES (?,?)");
          foreach($prod_ids as $pid){ $ins->execute([$id,$pid]); }
        }
      }

      flash('success','อัปเดตคูปองแล้ว');
      redirect('coupons.php?action=edit&id='.$id);
    }catch(Exception $e){
      flash('error','อัปเดตไม่สำเร็จ: '.$e->getMessage());
      redirect('coupons.php?action=edit&id='.$id);
    }
  }

  // TOGGLE
  if ($action==='toggle'){
    $id=(int)($_POST['id'] ?? 0);
    try{ $pdo->prepare("UPDATE coupons SET is_active = 1 - is_active WHERE id=?")->execute([$id]); }
    catch(Exception $e){ flash('error','สลับสถานะไม่สำเร็จ: '.$e->getMessage()); }
    redirect('coupons.php?'.http_build_query($_GET));
  }

  // DELETE
  if ($action==='delete'){
    $id=(int)($_POST['id'] ?? 0);
    try{
      if ($hasMapTable) $pdo->prepare("DELETE FROM coupon_products WHERE coupon_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM coupons WHERE id=?")->execute([$id]);
      flash('success','ลบคูปองแล้ว');
    }catch(Exception $e){ flash('error','ลบไม่สำเร็จ: '.$e->getMessage()); }
    redirect('coupons.php');
  }
}

/* ====================== Edit target ====================== */
$edit=null; $editProducts=[];
if (($action==='edit') && isset($_GET['id'])){
  $id=(int)$_GET['id'];
  $st=$pdo->prepare("SELECT * FROM coupons WHERE id=?"); $st->execute([$id]);
  $edit=$st->fetch(PDO::FETCH_ASSOC);
  if ($edit && $hasMapTable){
    try{
      $mm=$pdo->prepare("SELECT product_id FROM coupon_products WHERE coupon_id=?");
      $mm->execute([$id]);
      $editProducts = array_map('intval', $mm->fetchAll(PDO::FETCH_COLUMN,0));
    }catch(Exception $e){}
  }
}

/* ====================== View ====================== */
include __DIR__ . '/../partials/header.php';
?>
<style>
body .container, .container{max-width:100% !important; width:100% !important; padding:1rem}
.card{border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:1rem; margin:.6rem 0}
h2{margin:.2rem 0 .6rem}
.input, select.input, textarea.input{width:100%; box-sizing:border-box; background:#fff; color:#111; border:1px solid #c9cfdd; border-radius:12px; padding:.5rem .7rem; line-height:1.35; min-height:38px}
.input:disabled{background:#f3f4f6; color:#6b7280}
.btn{display:inline-flex; align-items:center; justify-content:center; gap:.35rem; padding:.45rem .9rem; border:1px solid #111; border-radius:10px; background:#fff; cursor:pointer}
.btn.outline{background:#fff}
.btn.danger{border-color:#b91c1c; color:#b91c1c}
.table{width:100%; border-collapse:collapse}
.table th,.table td{padding:.5rem; border-bottom:1px solid #eee; text-align:left}
.small{font-size:.9rem; opacity:.85}
.badge{display:inline-block; padding:.15rem .5rem; border-radius:999px; background:#111; color:#fff; font-size:.8rem}
.badge.green{background:#14532d}
.badge.gray{background:#6b7280}
.grid2{display:grid; grid-template-columns:1fr 1fr; gap:.8rem}
@media (max-width:900px){ .grid2{grid-template-columns:1fr} }
.flex{display:flex; gap:.5rem; flex-wrap:wrap; align-items:center}
.alert{border:1px solid #eab308; background:#fffbeb; color:#713f12; padding:.6rem .8rem; border-radius:.6rem; margin:.6rem 0}
/* === Force readable button text === */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.35rem;
  padding:.45rem .9rem;border:1px solid #111;border-radius:10px;
  background:#fff; color:#111 !important; font-weight:600;
}
a.btn{ color:#111 !important; text-decoration:none; }
.btn.outline{ background:#fff; color:#111 !important; border-color:#111; }
.btn.danger{ background:#fff; color:#b91c1c !important; border-color:#b91c1c; }
.btn:disabled{ opacity:.6; cursor:not-allowed; }

</style>

<h2>จัดการคูปอง</h2>

<?php if(!$hasCoupons): ?>
  <div class="alert">ไม่พบตาราง <code>coupons</code> — โปรดตรวจสอบฐานข้อมูล</div>
<?php endif; ?>

<?php if(!$enumSupportsItem): ?>
  <div class="alert">
    คอลัมน์ <code>coupons.type</code> ยังไม่รองรับค่า <code>item_percent</code> / <code>item_fixed</code><br>
    ถ้าต้องการ “คูปองลดต่อชิ้น” ให้รัน SQL นี้:<br>
    <pre style="white-space:pre-wrap;margin:.4rem 0">ALTER TABLE coupons
  MODIFY COLUMN `type` ENUM('percent','fixed','item_percent','item_fixed') NOT NULL DEFAULT 'fixed';</pre>
  </div>
<?php endif; ?>

<?php if(!$hasMapTable): ?>
  <div class="alert">
    ไม่พบตาราง <code>coupon_products</code> (คูปองจะมีผลกับ “ทุกสินค้า”)<br>
    หากต้องการเลือกสินค้าเฉพาะ ให้สร้างตารางนี้:<br>
    <pre style="white-space:pre-wrap;margin:.4rem 0">CREATE TABLE coupon_products(
  id INT AUTO_INCREMENT PRIMARY KEY,
  coupon_id INT NOT NULL,
  product_id INT NOT NULL,
  UNIQUE KEY uq_coupon_product (coupon_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
  </div>
<?php endif; ?>

<div class="card">
  <form class="flex" method="get" action="coupons.php">
    <input class="input" name="q" placeholder="ค้นหาโค้ด/ประเภท" value="<?= h($q) ?>" style="max-width:260px">
    <label><input type="checkbox" name="active" value="1" <?= $only_active?'checked':'' ?>> เฉพาะที่เปิดใช้งาน</label>
    <button class="btn" type="submit">ค้นหา</button>
    <a class="btn outline" href="coupons.php">รีเซ็ต</a>
  </form>
</div>

<div class="card">
  <h3><?= $edit ? 'แก้ไขคูปอง #'.(int)$edit['id'] : 'สร้างคูปองใหม่' ?></h3>
  <form method="post" action="coupons.php">
    <?= csrf_field() ?>
    <?php if($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">

    <div class="grid2">
      <div>
        <label>โค้ดคูปอง *</label>
        <input class="input" name="code" required value="<?= h($edit['code'] ?? '') ?>">

        <label>ประเภท *</label>
        <?php
          $typeVal = (string)($edit['type'] ?? 'fixed');
          $types = [
            'fixed' => 'ลดทั้งบิล: จำนวนเงินคงที่',
            'percent' => 'ลดทั้งบิล: เปอร์เซ็นต์',
            'item_fixed' => 'ลดต่อชิ้น: จำนวนเงิน/ชิ้น',
            'item_percent' => 'ลดต่อชิ้น: เปอร์เซ็นต์/ชิ้น',
          ];
        ?>
        <select class="input" name="type" required>
          <?php foreach($types as $k=>$label): ?>
            <option value="<?= h($k) ?>" <?= $typeVal===$k?'selected':'' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>

        <label>มูลค่าคูปอง *</label>
        <input class="input" type="number" step="0.01" min="0" name="value"
               value="<?= h(isset($edit['value'])?(string)$edit['value']:'0') ?>">
      </div>

      <div>
        <label>ส่วนลดสูงสุด (ถ้ามี)</label>
        <input class="input" type="number" step="0.01" min="0" name="max_discount"
               value="<?= h(isset($edit['max_discount'])?(string)$edit['max_discount']:'') ?>">

        <label>ยอดขั้นต่ำ (ถ้ามี)</label>
        <input class="input" type="number" step="0.01" min="0" name="min_total"
               value="<?= h(isset($edit['min_total'])?(string)$edit['min_total']:'0') ?>">

        <label>หมดอายุ (YYYY-MM-DD หรือ YYYY-MM-DD HH:MM:SS)</label>
        <input class="input" name="expires_at" placeholder="เช่น 2025-12-31 23:59:59"
               value="<?= h($edit['expires_at'] ?? '') ?>">

        <label style="margin-top:.5rem">
          <input type="checkbox" name="is_active" <?= !empty($edit) ? (!empty($edit['is_active'])?'checked':'') : 'checked' ?>>
          เปิดใช้งาน
        </label>
      </div>
    </div>

    <?php if ($hasMapTable): ?>
      <div style="margin-top:.6rem">
        <label>ใช้ได้กับสินค้า (ถ้าไม่เลือก = ใช้ได้กับทุกสินค้า)</label>
        <select class="input" name="products[]" multiple size="8" style="min-height:180px">
          <?php foreach($allProducts as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'],$editProducts,true)?'selected':'' ?>>
              <?= h($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="small">กด Ctrl/Command เพื่อเลือกหลายรายการ</div>
      </div>
    <?php else: ?>
      <div class="small" style="color:#6b7280; margin-top:.5rem">* ไม่พบตาราง <code>coupon_products</code> — ตอนนี้คูปองจะมีผลกับทุกสินค้า</div>
    <?php endif; ?>

    <div style="margin-top:.7rem">
      <button class="btn" type="submit"><?= $edit ? 'บันทึกการแก้ไข' : 'สร้างคูปอง' ?></button>
      <?php if($edit): ?><a class="btn outline" href="coupons.php">ยกเลิก</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h3>รายการคูปอง</h3>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>โค้ด</th>
          <th>ประเภท</th>
          <th>ค่า</th>
          <?php if(isset($couponColsMeta['max_discount'])): ?><th>Max</th><?php endif; ?>
          <?php if(isset($couponColsMeta['min_total'])): ?><th>Min</th><?php endif; ?>
          <?php if(isset($couponColsMeta['expires_at'])): ?><th>หมดอายุ</th><?php endif; ?>
          <?php if(isset($couponColsMeta['is_active'])): ?><th>สถานะ</th><?php endif; ?>
          <th>จัดการ</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><strong><?= h($r['code']) ?></strong></td>
          <td><?= h($r['type'] ?? '') ?></td>
          <td><?= h(isset($r['value'])?(string)$r['value']:'') ?></td>
          <?php if(isset($couponColsMeta['max_discount'])): ?>
            <td><?= h(isset($r['max_discount'])?(string)$r['max_discount']:'') ?></td>
          <?php endif; ?>
          <?php if(isset($couponColsMeta['min_total'])): ?>
            <td><?= h(isset($r['min_total'])?(string)$r['min_total']:'') ?></td>
          <?php endif; ?>
          <?php if(isset($couponColsMeta['expires_at'])): ?>
            <td><?= h($r['expires_at'] ?? '') ?></td>
          <?php endif; ?>
          <?php if(isset($couponColsMeta['is_active'])): ?>
            <td><?= !empty($r['is_active']) ? '<span class="badge green">เปิด</span>' : '<span class="badge gray">ปิด</span>' ?></td>
          <?php endif; ?>
          <td class="flex">
            <a class="btn outline" href="coupons.php?action=edit&id=<?= (int)$r['id'] ?>">แก้ไข</a>
            <form method="post" action="coupons.php?<?= http_build_query($_GET) ?>" onsubmit="return confirm('ลบคูปองนี้?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn danger" type="submit">ลบ</button>
            </form>
            <?php if(isset($couponColsMeta['is_active'])): ?>
            <form method="post" action="coupons.php?<?= http_build_query($_GET) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn outline" type="submit"><?= !empty($r['is_active'])?'ปิด':'เปิด' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="9" class="small">— ไม่มีข้อมูล —</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
