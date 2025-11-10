<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/partials/header.php';

if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try{
      $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
      $st->execute([$table,$col]); return (bool)$st->fetchColumn();
    }catch(Throwable $e){ return false; }
  }
}
function money($n){ return number_format((float)$n,2,'.',','); }

/* ---------- handle add to cart (fallback form) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add') {
  if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
  $pid = (int)($_POST['id'] ?? 0);
  $qty = max(1, (int)($_POST['qty'] ?? 1));

  // ดึง eff_stock เพื่อจำกัดจำนวน
  $hasGroup  = has_column($pdo,'products','stock_group');
  $hasMaster = has_column($pdo,'products','is_stock_master');

  if ($hasGroup && $hasMaster) {
    $sql = "SELECT p.id,p.name,p.price,p.stock,p.stock_group,p.is_stock_master,
                   m.stock AS master_stock
            FROM products p
            LEFT JOIN products m
              ON m.stock_group=p.stock_group AND COALESCE(m.is_stock_master,0)=1
            WHERE p.id=? AND p.is_active=1";
  } else {
    $sql = "SELECT p.id,p.name,p.price,p.stock FROM products p WHERE p.id=? AND p.is_active=1";
  }
  $st=$pdo->prepare($sql); $st->execute([$pid]);
  $p=$st->fetch(PDO::FETCH_ASSOC);
  if ($p) {
    $eff = (int)$p['stock'];
    if ($hasGroup && $hasMaster && !empty($p['stock_group']) && empty($p['is_stock_master'])) {
      $eff = (int)($p['master_stock'] ?? $p['stock']);
    }
    $cur = (int)($_SESSION['cart'][$pid] ?? 0);
    $_SESSION['cart'][$pid] = min($eff, $cur + $qty);
  }

  // [แก้ไข] เปลี่ยนจาก checkout.php เป็น cart.php
  header('Location: '.BASE_URL.'/cart.php'); exit;
}

/* ---------- load product ---------- */
$id = (int)($_GET['id'] ?? 0);
$hasGroup  = has_column($pdo,'products','stock_group');
$hasMaster = has_column($pdo,'products','is_stock_master');
if ($hasGroup && $hasMaster) {
  $sql = "SELECT p.*, m.stock AS master_stock
          FROM products p
          LEFT JOIN products m ON m.stock_group=p.stock_group AND COALESCE(m.is_stock_master,0)=1
          WHERE p.id=? AND p.is_active=1";
} else {
  $sql = "SELECT p.* FROM products p WHERE p.id=? AND p.is_active=1";
}
$st=$pdo->prepare($sql); $st->execute([$id]); $p=$st->fetch(PDO::FETCH_ASSOC);
if (!$p){ echo '<div class="alert">ไม่พบสินค้า</div>'; require_once __DIR__ . '/partials/footer.php'; exit; }

// ดึงรูปภาพทั้งหมดจากตาราง product_images
$gallery_images = [];
if (has_column($pdo, 'product_images', 'path')) {
    $st_gallery = $pdo->prepare("SELECT path FROM product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $st_gallery->execute([$id]);
    $gallery_images = $st_gallery->fetchAll(PDO::FETCH_COLUMN);
}

// รวมรูปหลักและรูปในแกลเลอรีเข้าด้วยกัน (ป้องกันการซ้ำ)
$main_image = $p['image'] ? ltrim($p['image'],'/') : null;
$all_images = [];
if ($main_image) {
    $all_images[] = $main_image;
}
foreach ($gallery_images as $gallery_image) {
    $g_path = ltrim($gallery_image, '/');
    if (!in_array($g_path, $all_images)) {
        $all_images[] = $g_path;
    }
}
if (empty($all_images)) {
    $all_images[] = 'assets/images/noimg.png';
}


$eff_stock = (int)$p['stock'];
if ($hasGroup && $hasMaster && !empty($p['stock_group']) && empty($p['is_stock_master'])) {
  $eff_stock = (int)($p['master_stock'] ?? $p['stock']);
}
$allow_cover = has_column($pdo,'products','allow_cover_print') ? (int)($p['allow_cover_print'] ?? 0) : 0;
?>

<style>
body .container,.container{max-width: 100% !important; width:100% !important;}
.pwrap{display:grid;grid-template-columns:1.1fr 1fr;gap:1.2rem}
@media(max-width: 900px){ .pwrap{grid-template-columns:1fr} }
.card{border:1px solid #23234a;border-radius:1rem;padding:1rem}
.price{font-size:1.6rem;font-weight:800}
.small{opacity:.85}

.gallery-container .main-image img {
    width: 100%;
    height: auto;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    border-radius: .8rem;
    border: 1px solid var(--border-color, #e4e4e7);
}
.thumbnail-strip {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
}
.thumbnail-strip img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: .5rem;
    cursor: pointer;
    border: 2px solid transparent;
    transition: border-color 0.2s;
}
.thumbnail-strip img:hover {
    border-color: #cccccc;
}
.thumbnail-strip img.active {
    border-color: #007bff;
}
	
.qty-box{display:flex;align-items:center;gap:.4rem;max-width:240px}
.qty-input{text-align:center;width:100px}
.qty-btn{
  width:40px;height:40px;border:1px solid #e2e8f0;background:#f8fafc;
  border-radius:.5rem;font-size:1.2rem;font-weight:700;cursor:pointer;line-height:1
}
.qty-btn:disabled{opacity:.5;cursor:not-allowed}
</style>

<div class="pwrap">
  <div class="card">
    <div class="gallery-container">
        <div class="main-image">
            <img src="<?= BASE_URL . '/' . htmlspecialchars($all_images[0]) ?>" id="mainImage" alt="Product Image">
        </div>
        <?php if (count($all_images) > 1): ?>
        <div class="thumbnail-strip" id="thumbnailStrip">
            <?php foreach ($all_images as $index => $img_path): ?>
                <img src="<?= BASE_URL . '/' . htmlspecialchars($img_path) ?>" class="thumbnail <?= $index === 0 ? 'active' : '' ?>" alt="Thumbnail <?= $index + 1 ?>">
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <h2 style="margin:.2rem 0 0"><?= htmlspecialchars($p['name']) ?></h2>
    <div class="price">฿<?= money($p['price']) ?></div>
  <!--  <div class="small" style="margin:.25rem 0">คงเหลือ: <?= (int)$eff_stock ?> ชิ้น</div> -->
    <?php if(!empty($p['description'])): ?>
      <p><?= nl2br(htmlspecialchars($p['description'])) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= BASE_URL ?>/product.php?id=<?= (int)$id ?>" style="margin-top:.75rem">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <?php if ($allow_cover): ?>
        <label style="display:flex;gap:.4rem;align-items:center;margin:.25rem 0 .6rem">
          <input type="checkbox" name="cover_on" value="1"> ขอ “พิมพ์ปก”
        </label>
      <?php endif; ?>

      <label for="qtyInput">จำนวน</label>
<div class="qty-box">
  <button type="button" class="qty-btn" data-delta="-1" aria-label="ลดจำนวน" <?= $eff_stock>0 ? '' : 'disabled' ?>>−</button>

  <input
    id="qtyInput"
    class="input qty-input"
    type="number"
    name="qty"
    min="1"
    step="1"
    max="<?= max(1, $eff_stock) ?>"
    value="1"
    style="max-width:140px"
    <?= $eff_stock>0 ? '' : 'disabled' ?>
  >

  <button type="button" class="qty-btn" data-delta="1" aria-label="เพิ่มจำนวน" <?= $eff_stock>0 ? '' : 'disabled' ?>>+</button>
</div>


      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.7rem">
        <button class="btn" type="submit" <?= $eff_stock>0 ? '' : 'disabled title="สินค้าหมด"' ?>>
          🛒 ใส่ตะกร้า
        </button>
        <a class="btn outline" href="<?= BASE_URL ?>/index.php">กลับหน้าร้าน</a>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const mainImage = document.getElementById('mainImage');
    const thumbnailStrip = document.getElementById('thumbnailStrip');

    if (mainImage && thumbnailStrip) {
        thumbnailStrip.addEventListener('click', function(event) {
            if (event.target && event.target.classList.contains('thumbnail')) {
                mainImage.src = event.target.src;
                const allThumbnails = thumbnailStrip.querySelectorAll('.thumbnail');
                allThumbnails.forEach(thumb => thumb.classList.remove('active'));
                event.target.classList.add('active');
            }
        });
    }
});
	
	(function(){
  const input = document.getElementById('qtyInput');
  if (!input) return;
  const box   = input.closest('.qty-box');
  if (!box) return;

  const dec = box.querySelector('.qty-btn[data-delta="-1"]');
  const inc = box.querySelector('.qty-btn[data-delta="1"]');

  function clamp(val){
    const min = parseInt(input.getAttribute('min') || '1', 10);
    const max = parseInt(input.getAttribute('max') || '999999', 10);
    val = isNaN(val) ? min : val;
    val = Math.max(min, Math.min(max, val));
    input.value = val;
    const disabled = input.disabled;
    if (dec) dec.disabled = disabled || (val <= min);
    if (inc) inc.disabled = disabled || (val >= max);
  }

  if (dec) dec.addEventListener('click', ()=> clamp(parseInt(input.value||'1',10) - 1));
  if (inc) inc.addEventListener('click', ()=> clamp(parseInt(input.value||'1',10) + 1));
  input.addEventListener('input', ()=> clamp(parseInt(input.value,10)));

  // อัปเดตสถานะปุ่มครั้งแรก
  clamp(parseInt(input.value||'1',10));
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>