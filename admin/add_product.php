<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../partials/header.php';
if (function_exists('require_admin')) require_admin();

/* ---------- helpers: schema probes that tolerate missing tables/columns ---------- */
function has_table(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function has_column(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table,$col]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* ---------- submit ---------- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (function_exists('csrf_check')) {
    if (!csrf_check($_POST['csrf'] ?? '')) $errors[] = 'CSRF invalid';
  }

  $name  = trim($_POST['name'] ?? '');
  $price_raw = trim($_POST['price'] ?? '');
  $stock = (int)($_POST['stock'] ?? 0);
  $sku   = trim($_POST['sku'] ?? '');
  $desc  = trim($_POST['description'] ?? '');

  // options
  $allow_cover_print = isset($_POST['allow_cover_print']) ? 1 : 0;
  $stock_group = trim($_POST['stock_group'] ?? '');
  $is_stock_master = isset($_POST['is_stock_master']) ? 1 : 0;

  if ($name === '') $errors[] = 'กรอกชื่อสินค้า';
  if ($price_raw === '' || !is_numeric($price_raw)) $errors[] = 'กรอกราคาให้ถูกต้อง';
  $price = isset($price_raw) ? max(0.0, (float)$price_raw) : 0.0;
  if ($stock < 0) $errors[] = 'สต๊อกห้ามติดลบ';

  // ถ้าอยู่ในกลุ่มร่วม แต่ไม่ใช่ master -> บังคับ stock=0 (ตัดจาก master เท่านั้น)
  if ($stock_group !== '' && !$is_stock_master) {
    $stock = 0;
  }

  // SKU duplicate (ถ้ามีคอลัมน์ sku)
  if ($sku !== '' && has_column($pdo,'products','sku')) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
    $st->execute([$sku]);
    if ((int)$st->fetchColumn() > 0) $errors[] = 'SKU นี้มีอยู่แล้วในระบบ';
  }

  // ถ้า user ติ๊กเป็น master ตรวจว่ากลุ่มนั้นมี master อยู่แล้วหรือยัง
  if ($stock_group !== '' && $is_stock_master && has_column($pdo,'products','stock_group') && has_column($pdo,'products','is_stock_master')) {
    try {
      $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE stock_group = ? AND is_stock_master = 1");
      $st->execute([$stock_group]);
      if ((int)$st->fetchColumn() > 0) {
        $errors[] = "กลุ่ม '{$stock_group}' มีตัวหลักอยู่แล้ว กรุณาเอาติ๊ก 'เป็นตัวหลักของกลุ่ม?' ออก หรือใช้ชื่อกลุ่มใหม่";
      }
    } catch (Throwable $e) { /* ignore */ }
  }

  /* ---- multiple image uploads ---- */
  $imagePaths = [];
  $fileBatches = [];
  if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
    $f = $_FILES['images'];
    $n = count($f['name']);
    for ($i=0; $i<$n; $i++) {
      if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
      $fileBatches[] = [
        'name'     => $f['name'][$i],
        'type'     => $f['type'][$i],
        'tmp_name' => $f['tmp_name'][$i],
        'error'    => $f['error'][$i],
        'size'     => $f['size'][$i],
      ];
    }
  } elseif (!empty($_FILES['image']['name'])) {
    // backward compatibility
    $fileBatches[] = $_FILES['image'];
  }

  if ($fileBatches) {
    if (!function_exists('handle_upload')) $errors[] = 'ไม่พบฟังก์ชัน handle_upload() ในโปรเจกต์';
    foreach ($fileBatches as $one) {
      if (($one['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) continue;
      // store under uploads/products
      $p = handle_upload($one, 'uploads/products', ['jpg','jpeg','png','webp','gif']);
      if ($p) $imagePaths[] = $p;
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $coverPath = $imagePaths[0] ?? null;

      // สร้างคอลัมน์แผนที่ของ products
      $prodCols = [];
      try {
        $q = $pdo->query("SHOW COLUMNS FROM products");
        foreach ($q as $r) { $prodCols[strtolower($r['Field'])] = true; }
      } catch (Throwable $e) {}

      // เตรียม insert (ทนสคีม่า)
      $cols = ['name','price'];
      $vals = [$name,$price];

      // ใส่ stock ลงทุก alias ที่ตารางมี
      foreach (['stock','stock_qty','qty','quantity','in_stock'] as $sc) {
        if (isset($prodCols[$sc])) { $cols[] = $sc; $vals[] = $stock; }
      }
      if (isset($prodCols['sku']))          { $cols[]='sku';          $vals[]=$sku; }
      if (isset($prodCols['description']))  { $cols[]='description';  $vals[]=$desc; }
      if (isset($prodCols['image']))        { $cols[]='image';        $vals[]=$coverPath; }
      if (isset($prodCols['is_active']))    { $cols[]='is_active';    $vals[]=1; }
      if (isset($prodCols['allow_cover_print'])) { $cols[]='allow_cover_print'; $vals[]=$allow_cover_print; }
      if (isset($prodCols['stock_group']))       { $cols[]='stock_group';       $vals[] = ($stock_group !== '' ? $stock_group : null); }
      if (isset($prodCols['is_stock_master']))   { $cols[]='is_stock_master';   $vals[] = $is_stock_master; }

      $ph = implode(',', array_fill(0, count($cols), '?'));
      $sql = "INSERT INTO products (".implode(',', $cols).") VALUES ($ph)";
      $pdo->prepare($sql)->execute($vals);
      $pid = (int)$pdo->lastInsertId();

      // บันทึกรูปเพิ่มเติมลง product_images (ถ้ามีตาราง)
      if ($pid > 0 && $imagePaths && has_table($pdo,'product_images')) {
        $colImg = has_column($pdo,'product_images','path') ? 'path'
                 : (has_column($pdo,'product_images','image_path') ? 'image_path' : null);
        if ($colImg) {
          $hasPrimary = has_column($pdo,'product_images','is_primary') || has_column($pdo,'product_images','is_cover');
          $colSort    = has_column($pdo,'product_images','sort') ? 'sort'
                       : (has_column($pdo,'product_images','sort_order') ? 'sort_order'
                       : (has_column($pdo,'product_images','position') ? 'position' : null));
          foreach ($imagePaths as $i => $p) {
            $colsPI = ['product_id', $colImg];
            $valsPI = [$pid, $p];
            if ($hasPrimary) {
              $colsPI[] = has_column($pdo,'product_images','is_primary') ? 'is_primary' : 'is_cover';
              $valsPI[] = ($i === 0 ? 1 : 0);
            }
            if ($colSort) { $colsPI[] = $colSort; $valsPI[] = $i; }
            $ph2 = implode(',', array_fill(0, count($colsPI), '?'));
            $sqlPI = "INSERT INTO product_images (".implode(',', $colsPI).") VALUES ($ph2)";
            try { $pdo->prepare($sqlPI)->execute($valsPI); } catch (Throwable $e) {}
          }
        }
      }

      $pdo->commit();
      if (function_exists('flash')) flash('success','เพิ่มสินค้าเรียบร้อย');
      header('Location: '.BASE_URL.'/admin/products.php'); exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'บันทึกสินค้าไม่สำเร็จ: '.$e->getMessage();
    }
  }
}
?>
<style>
body .container,.container{max-width:1100px!important}
.card{border:1px solid #23234a;border-radius:1rem;padding:1rem}
.field{display:flex;flex-direction:column;margin:.6rem 0}
.row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.small{font-size:.9rem;opacity:.8}
/* --- CSS: แก้ไขตรงนี้ --- */
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

/* --- Adjusted Button Styles --- */
.btn{
  display:inline-flex;
  align-items:center;
  gap:.35rem;
  padding:.55rem .95rem;
  border:1px solid #2f4bf0;
  border-radius:.6rem;
  background:#4a68d5;
  cursor:pointer;
  color:#fff;
  font-weight:600;
  line-height:1;
  text-decoration:none;
}
.btn:hover{filter:brightness(1.1)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn.primary{
  background:#4a68d5;
  border-color:#2f4bf0;
  color:#fff;
}
.btn.secondary{
  background:#2a2a55;
  border-color:#3d3d78;
  color:#fff;
}
.btn.outline{
  background:transparent;
  color:#23234a;
  border-color:#3a3a55;
}
.btn.outline:hover{background:rgba(0,0,0,.05)}
a.btn{text-decoration:none;color:inherit}

/* --- Button reset to avoid white Safari/UA styles --- */
button, input[type=button], input[type=submit], input[type=reset]{
  -webkit-appearance:none;
  appearance:none;
  background:none;
  border:none;
  color:inherit;
  font: inherit;
  padding:0;
  margin:0;
}
button.btn, input[type=button].btn, input[type=submit].btn, a.btn{
  box-shadow:none;
}
a.btn{ color:#fff !important }
.btn.outline, a.btn.outline{ color:#23234a !important }
.btn:focus-visible{ outline:3px solid rgba(147,197,253,.9); outline-offset:2px }
.visually-hidden{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}
.file-row .btn{margin-top:.25rem}
.code-surface{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:.35rem}
</style>

<div id="addprod">
<div class="card">
  <h2>เพิ่มสินค้า</h2>

  <?php if ($errors): ?>
    <div class="alert error">
      <?php foreach($errors as $e): ?>
        <div>• <?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>

    <div class="row">
      <div class="field">
        <label>ชื่อสินค้า *</label>
        <input class="input" name="name" required value="<?= h($_POST['name'] ?? '') ?>">
      </div>

      <div class="field">
        <label>ราคา *</label>
        <input class="input" type="number" name="price" min="0" step="0.01" required value="<?= h($_POST['price'] ?? '') ?>">
        <div class="small">ใส่ 0 ได้ (เช่น ของแถม/ราคาตามตกลง)</div>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <label>สต๊อก *</label>
        <input class="input" type="number" name="stock" min="0" step="1" required value="<?= h($_POST['stock'] ?? '0') ?>">
      </div>
      <div class="field">
        <label>SKU</label>
        <input class="input" name="sku" placeholder="เช่น ABC-001" value="<?= h($_POST['sku'] ?? '') ?>">
        <div class="small">POS สามารถสแกนบาร์โค้ดจาก SKU (Code128) ได้</div>
      </div>
    </div>

    <div class="code-previews" id="codePreviews" aria-live="polite">
      <div class="code-card">
        <h4>Barcode (Code128) จาก SKU</h4>
        <div class="code-surface"><svg id="skuBarcode" style="width:100%;height:120px"></svg></div>
        <div class="code-actions">
          <button type="button" class="btn outline" id="dlBarcodePng">ดาวน์โหลดบาร์โค้ด (PNG)</button>
          <button type="button" class="btn outline" onclick="window.print()">พิมพ์ป้าย</button>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <label>Stock Group (สต๊อกร่วม)</label>
        <input class="input" name="stock_group" placeholder="เช่น diary-1 (เว้นว่าง=แยกสต๊อค)" value="<?= h($_POST['stock_group'] ?? '') ?>">
        <div class="small">ถ้าไม่ใช่ตัวหลักของกลุ่ม ให้ปล่อยสต๊อคเป็น 0 เพื่อให้ระบบไปตัดจากตัวหลักอัตโนมัติ</div>
      </div>
      <div class="field">
        <label>เป็นตัวหลักของกลุ่ม?</label>
        <label style="display:flex;align-items:center;gap:.5rem">
          <input type="checkbox" name="is_stock_master" value="1" <?= !empty($_POST['is_stock_master'])?'checked':'' ?>>
          ใช่ (ตัดสต๊อกจากตัวนี้)
        </label>
      </div>
    </div>

    <div class="field">
      <label>รายละเอียดสินค้า</label>
      <textarea class="input" name="description" rows="4"><?= h($_POST['description'] ?? '') ?></textarea>
    </div>

    <label style="display:flex;align-items:center;gap:.5rem;margin:.4rem 0">
      <input type="checkbox" name="allow_cover_print" value="1" <?= (!isset($_POST['allow_cover_print']) && $_SERVER['REQUEST_METHOD']!=='POST') ? 'checked' : (!empty($_POST['allow_cover_print'])?'checked':'') ?>>
      อนุญาต “พิมพ์ปก” สำหรับสินค้านี้
    </label>

    <div class="field">
      <label>อัปโหลดรูป (เลือกได้หลายรูป)</label>
      <input class="input visually-hidden" id="images" type="file" name="images[]" accept="image/*" multiple>
      <div class="file-row" style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <label for="images" class="btn secondary">เลือกไฟล์รูป</label>
        <span id="fileInfo" class="small">ยังไม่ได้เลือกไฟล์</span>
      </div>
      <div class="small">* รูปแรกจะเป็น “รูปหลัก”; รองรับ .jpg .jpeg .png .webp .gif</div>
    </div>

    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
      <button class="btn primary" type="submit">บันทึกสินค้า</button>
      <a class="btn outline" href="<?= BASE_URL ?>/admin/products.php">ยกเลิก</a>
    </div>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
(function(){
  // --- JavaScript: แก้ไขตรงนี้ ---
  const skuInput = document.querySelector('input[name="sku"]');
  const bcSvg    = document.getElementById('skuBarcode');

  function renderCodes(val){
    const v = (val||'').trim();
    if(!v){
      if (bcSvg) bcSvg.innerHTML = '';
      return;
    }
    if (window.JsBarcode && bcSvg){
      JsBarcode(bcSvg, v, {
        format: "code128",
        lineColor: "#111",
        width: 2, height: 80, displayValue: true, fontSize: 14, margin: 0
      });
    }
  }
  function downloadBarcodePng(){
    const xml  = new XMLSerializer().serializeToString(bcSvg);
    const data = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(xml)));
    const img  = new Image();
    img.onload = function(){
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
  skuInput?.addEventListener('input', e => renderCodes(e.target.value));
  renderCodes(skuInput?.value || '');

// file selector info
const fileInput = document.getElementById('images');
const fileInfo = document.getElementById('fileInfo');
if (fileInput && fileInfo) {
  fileInput.addEventListener('change', () => {
    if (!fileInput.files || fileInput.files.length === 0) {
      fileInfo.textContent = 'ยังไม่ได้เลือกไฟล์';
    } else if (fileInput.files.length === 1) {
      fileInfo.textContent = fileInput.files[0].name;
    } else {
      fileInfo.textContent = fileInput.files.length + ' ไฟล์ที่เลือก';
    }
  });
}
})();
</script>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>