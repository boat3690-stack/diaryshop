<?php
// admin/product_edit.php — แก้ไขสินค้า + แสดง Barcode/QR + อัปโหลดหลายรูป
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';

if (!function_exists('require_admin')) { function require_admin(){} }
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try { $pdo->query("SELECT `$col` FROM `$table` LIMIT 0"); return true; }
    catch(Throwable $e){ return false; }
  }
}

require_admin();
$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { header('Location: products.php'); exit; }

// โหลดสินค้า
$prod = [];
try {
  $r = $pdo->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
  $r->execute([$id]);
  $prod = $r->fetch(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e){ $prod=[]; }

if (!$prod) { echo "ไม่พบสินค้า #".h($id); exit; }

// คอลัมน์เสริมที่อาจมี/ไม่มีในบางโปรเจ็กต์
$has_sku   = has_column($pdo,'products','sku');
$has_group = has_column($pdo,'products','stock_group');
$has_master= has_column($pdo,'products','is_stock_master');
$has_desc1 = has_column($pdo,'products','description');
$has_desc2 = !$has_desc1 && has_column($pdo,'products','detail'); // เผื่อใช้ชื่อ 'detail'
$desc_col  = $has_desc1 ? 'description' : ($has_desc2 ? 'detail' : null);
$has_allow_cover = has_column($pdo,'products','allow_cover_print');

// อัปเดตสินค้า
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name  = trim((string)($_POST['name'] ?? ''));
  $price = (float)($_POST['price'] ?? 0);
  $stock = (int)($_POST['stock'] ?? 0);
  $sku   = $has_sku ? trim((string)($_POST['sku'] ?? '')) : null;
  $group = $has_group ? trim((string)($_POST['stock_group'] ?? '')) : null;
  $master= $has_master ? (int)!empty($_POST['is_stock_master']) : null;
  $desc  = $desc_col ? trim((string)($_POST['description'] ?? '')) : null;
  $allow_cover = $has_allow_cover ? (int)!empty($_POST['allow_cover_print']) : null;

  $sets = ['name=?','price=?','stock=?'];
  $prm  = [$name,$price,$stock];

  if ($has_sku)   { $sets[]='sku=?';              $prm[]=$sku!==''?$sku:null; }
  if ($has_group) { $sets[]='stock_group=?';      $prm[]=$group!==''?$group:null; }
  if ($has_master){ $sets[]='is_stock_master=?';  $prm[]=$master; }
  if ($desc_col)  { $sets[]="$desc_col=?";        $prm[]=$desc!==''?$desc:null; }
  if ($has_allow_cover){ $sets[]='allow_cover_print=?'; $prm[]=$allow_cover; }

  $prm[]=$id;

  try{
    $sql="UPDATE products SET ".implode(',',$sets)." WHERE id=?";
    $pdo->prepare($sql)->execute($prm);
  }catch(Throwable $e){
    // กรณี sku ชน unique
    if (strpos($e->getMessage(),'Duplicate')!==false) {
      $err = 'SKU ซ้ำกับสินค้ารายการอื่น';
    } else {
      $err = 'บันทึกไม่สำเร็จ: '.$e->getMessage();
    }
  }

  // อัปโหลดรูปหลายไฟล์ -> product_images
  if (!empty($_FILES['images']['name'][0])) {
    $baseDir = __DIR__ . '/../uploads/products';
    if (!is_dir($baseDir)) { @mkdir($baseDir,0777,true); }
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/jpg'];
    for($i=0;$i<count($_FILES['images']['name']);$i++){
      $nameF = $_FILES['images']['name'][$i];
      $tmp   = $_FILES['images']['tmp_name'][$i];
      $typ   = $_FILES['images']['type'][$i] ?: mime_content_type($tmp);
      if (!$tmp || !is_uploaded_file($tmp)) continue;
      if (!in_array($typ,$allowed,true)) continue;
      $ext = pathinfo($nameF, PATHINFO_EXTENSION);
      $new = uniqid('p'.$id.'_').'.'.strtolower($ext?:'jpg');
      $rel = 'uploads/products/'.$new;
      $abs = __DIR__.'/../'.$rel;
      if (move_uploaded_file($tmp,$abs)) {
        try {
          $pdo->prepare("INSERT INTO product_images (product_id, path, created_at) VALUES (?, ?, NOW())")
              ->execute([$id,$rel]);
        } catch(Throwable $e){}
      }
    }
  }

  if (empty($err)) { header("Location: product_edit.php?id=".$id."&ok=1"); exit; }
}

// โหลดรูปทั้งหมด
$images=[];
try{
  foreach($pdo->query("SELECT id, path FROM product_images WHERE product_id=".(int)$id." ORDER BY id DESC") as $r){
    $images[]=$r;
  }
}catch(Throwable $e){}

include __DIR__ . '/../partials/header.php';
?>
<style>
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
@media(max-width:900px){.form-grid{grid-template-columns:1fr}}
.input{width:100%;border:1px solid #c9cfdd;border-radius:.75rem;padding:.5rem .75rem}
label.small{font-size:.9rem;opacity:.8}
.imgs{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.5rem}
.imgs .card{border:1px solid #e5e7eb;border-radius:.6rem;padding:.4rem;text-align:center;background:#fff}
.imgs img{max-width:100%;max-height:120px;object-fit:contain}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .8rem;border:1px solid #23234a;border-radius:.6rem;background:#fff;cursor:pointer}
.btn.outline{background:transparent}
.code-previews{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin:.75rem 0}
@media(max-width:900px){.code-previews{grid-template-columns:1fr}}
.code-card{border:1px solid #e5e7eb;border-radius:.75rem;background:#fff;padding:.75rem}
.code-card h4{margin:.2rem 0 .6rem;font-size:1rem}
.code-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem}
</style>

<h2>แก้ไขสินค้า #<?= (int)$id ?></h2>
<?php if(!empty($_GET['ok'])): ?>
  <div style="border:1px solid #16a34a;background:#ecfdf5;padding:.6rem;border-radius:.6rem">บันทึกแล้ว</div>
<?php endif; ?>
<?php if(!empty($err)): ?>
  <div style="border:1px solid #b91c1c;background:#fef2f2;padding:.6rem;border-radius:.6rem">ERROR: <?= h($err) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <div class="form-grid">
    <div>
      <label class="small">ชื่อสินค้า *</label>
      <input class="input" name="name" required value="<?= h($prod['name'] ?? '') ?>">
    </div>
    <div>
      <label class="small">ราคา *</label>
      <input class="input" name="price" type="number" step="0.01" min="0" value="<?= h($prod['price'] ?? 0) ?>">
    </div>
  </div>

  <div class="form-grid" style="margin-top:.5rem">
    <div>
      <label class="small">สต็อก *</label>
      <input class="input" name="stock" type="number" step="1" min="0" value="<?= h($prod['stock'] ?? 0) ?>">
    </div>
    <?php if($has_sku): ?>
    <div>
      <label class="small">SKU</label>
      <input class="input" name="sku" value="<?= h($prod['sku'] ?? '') ?>" placeholder="เช่น ABC-001">
    </div>
    <?php endif; ?>
  </div>

  <div class="form-grid" style="margin-top:.5rem">
    <?php if($has_group): ?>
    <div>
      <label class="small">Stock Group (สต็อคร่วม)</label>
      <input class="input" name="stock_group" value="<?= h($prod['stock_group'] ?? '') ?>" placeholder="เว้นว่างถ้าไม่ร่วม">
    </div>
    <?php endif; ?>
    <?php if($has_master): ?>
    <div style="display:flex;align-items:flex-end">
      <label class="small" style="display:flex;align-items:center;gap:.4rem">
        <input type="checkbox" name="is_stock_master" value="1" <?= !empty($prod['is_stock_master'])?'checked':'' ?>>
        ตัวหลักของกลุ่ม?
      </label>
    </div>
    <?php endif; ?>
  </div>

  <?php if($desc_col): ?>
  <div style="margin-top:.5rem">
    <label class="small">รายละเอียดสินค้า</label>
    <textarea class="input" name="description" rows="3"><?= h($prod[$desc_col] ?? '') ?></textarea>
  </div>
  <?php endif; ?>

  <?php if($has_allow_cover): ?>
  <div style="margin-top:.5rem">
    <label class="small" style="display:flex;align-items:center;gap:.4rem">
      <input type="checkbox" name="allow_cover_print" value="1" <?= !empty($prod['allow_cover_print'])?'checked':'' ?>>
      อนุญาตให้พิมพ์ปกกับสินค้านี้
    </label>
  </div>
  <?php endif; ?>

  <?php if($has_sku): ?>
  <!-- Preview Barcode/QR จาก SKU -->
  <div class="code-previews">
    <div class="code-card">
      <h4>Barcode (Code128) จาก SKU</h4>
      <svg id="skuBarcode" style="width:100%;height:120px"></svg>
      <div class="code-actions">
        <button type="button" class="btn outline" id="dlBarcodePng">ดาวน์โหลดบาร์โค้ด (PNG)</button>
        <button type="button" class="btn outline" onclick="window.print()">พิมพ์ป้าย</button>
      </div>
    </div>
    <div class="code-card">
      <h4>QR Code จาก SKU</h4>
      <canvas id="skuQR" width="180" height="180" style="background:#fff;border:1px solid #e5e7eb;border-radius:.5rem"></canvas>
      <div class="code-actions">
        <button type="button" class="btn outline" id="dlQrPng">ดาวน์โหลด QR (PNG)</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div style="margin-top:1rem">
    <label class="small">อัปโหลดรูป (เลือกหลายไฟล์ได้)</label>
    <input class="input" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,.gif" multiple>
    <div class="small" style="opacity:.7">* ไฟล์จะถูกบันทึกไว้ที่ /uploads/products/ และลงตาราง product_images</div>
  </div>

  <?php if($images): ?>
  <div style="margin-top:1rem">
    <label class="small">รูปที่มีอยู่</label>
    <div class="imgs">
      <?php foreach($images as $im): ?>
        <div class="card">
          <img src="/<?= h($im['path']) ?>" alt="">
          <div style="margin-top:.3rem">
            <form method="post" action="products.php" onsubmit="return confirm('ยืนยันการลบรูปนี้?')">
    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
    <input type="hidden" name="action" value="img_delete">
    <input type="hidden" name="pid" value="<?= (int)$id ?>">
    <input type="hidden" name="img_id" value="<?= (int)$im['id'] ?>">
    <button type="submit" class="btn danger xs">ลบ</button>
</form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
    <button class="btn" type="submit">บันทึก</button>
    <a class="btn outline" href="products.php">กลับหน้ารายการ</a>
  </div>
</form>

<!-- libs -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
(function(){
  const skuInput = document.querySelector('input[name="sku"]');
  const bcSvg = document.getElementById('skuBarcode');
  const qrCanvas = document.getElementById('skuQR');

  function clearQR(){
    const ctx = qrCanvas.getContext('2d');
    ctx.clearRect(0,0,qrCanvas.width,qrCanvas.height);
    ctx.fillStyle = '#fff'; ctx.fillRect(0,0,qrCanvas.width,qrCanvas.height);
  }

  function render(v){
    v = (v||'').trim();
    if (!bcSvg || !qrCanvas) return;
    if (!v){ bcSvg.innerHTML=''; clearQR(); return; }
    JsBarcode(bcSvg, v, {format:"code128",lineColor:"#111",width:2,height:80,displayValue:true,fontSize:14,margin:0});
    QRCode.toCanvas(qrCanvas, v, {margin:1,width:qrCanvas.width}, function(){});
  }

  function dlBarcode(){
    const xml  = new XMLSerializer().serializeToString(bcSvg);
    const data = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(xml)));
    const img  = new Image();
    img.onload = function(){
      const c = document.createElement('canvas');
      c.width = img.width; c.height = img.height;
      const ctx=c.getContext('2d'); ctx.fillStyle='#fff'; ctx.fillRect(0,0,c.width,c.height); ctx.drawImage(img,0,0);
      const url=c.toDataURL('image/png');
      const a=document.createElement('a'); a.href=url; a.download='barcode_'+(skuInput.value||'')+'.png'; a.click();
    };
    img.src = data;
  }

  function dlQR(){
    const url = qrCanvas.toDataURL('image/png');
    const a=document.createElement('a'); a.href=url; a.download='qrcode_'+(skuInput.value||'')+'.png'; a.click();
  }

  document.getElementById('dlBarcodePng')?.addEventListener('click', dlBarcode);
  document.getElementById('dlQrPng')?.addEventListener('click', dlQR);
  skuInput?.addEventListener('input', e => render(e.target.value));
  render(skuInput?.value || '');
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
