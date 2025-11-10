<?php require_once __DIR__ . '/partials/header.php'; ?>

<?php
/* ========= helpers ========= */
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try{
      $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
      $st->execute([$table,$col]);
      return (bool)$st->fetchColumn();
    }catch(Throwable $e){ return false; }
  }
}
if (!function_exists('format_currency')) {
  function format_currency($n){ return number_format((float)$n,2,'.',','); }
}

/* ========= โหลดสินค้า + คำนวณสต๊อคที่มีผลจริง (eff_stock) ========= */
$hasGroup  = has_column($pdo,'products','stock_group');
$hasMaster = has_column($pdo,'products','is_stock_master');

$select = "SELECT
            p.id, p.name, p.price, p.image,
            COALESCE(p.description,'') AS description,
            p.stock".
          ($hasGroup  ? ", p.stock_group"    : "").
          ($hasMaster ? ", p.is_stock_master": "");

if ($hasGroup && $hasMaster) {
  // ถ้ามีคอลัมน์สต๊อกร่วม → ใช้สต๊อคของตัวหลักสำหรับตัวรอง
  $select .= ",
    CASE
      WHEN COALESCE(p.stock_group,'') <> '' AND COALESCE(p.is_stock_master,0) <> 1
      THEN COALESCE(
        (SELECT m.stock
         FROM products m
         WHERE m.stock_group = p.stock_group
           AND COALESCE(m.is_stock_master,0) = 1
         ORDER BY m.id LIMIT 1),
        p.stock
      )
      ELSE p.stock
    END AS eff_stock";
} else {
  // ไม่มีคอลัมน์สต๊อกร่วม → ใช้สต๊อคของตัวเอง
  $select .= ", p.stock AS eff_stock";
}

$select .= " FROM products p WHERE p.is_active=1 ORDER BY p.id DESC";

$products = $pdo->query($select)->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* ==== FULL WIDTH เฉพาะหน้า index นี้ ==== */
body .container, .container { 
  max-width: 100% !important; 
  width: 100% !important;
  padding-left: 1rem;
  padding-right: 1rem;
}

/* ตาราง/เลย์เอาต์พื้นฐานที่ใช้ในหน้าเก่าๆ */
.table{ width:100%; table-layout:auto; }
.table th,.table td{ white-space:nowrap; }
.table td.left{ white-space:normal; }

.filterbar{ flex-wrap: wrap; }
.filterbar .input{ min-width: 180px; }

.summary{ display:flex; gap:.75rem; flex-wrap:wrap; margin:1rem 0; }
.summary .card{ padding:.75rem 1rem; border-radius:.8rem; }
.summary .num{ font-weight:700; font-size:1.1rem; }
.small{ font-size:.85rem; opacity:.85; }

.table-wrap{ overflow-x:auto; }

/* Hero Section */
.hero-section {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  padding: 4rem 2rem;
  border-radius: 1.5rem;
  margin-bottom: 3rem;
  text-align: center;
  position: relative;
  overflow: hidden;
}
.hero-section::before {
  content: '';
  position: absolute;
  top: -50%; right: -10%;
  width: 500px; height: 500px; border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
}
.hero-section h2 { font-size: 2.2rem; margin-bottom: .5rem; position: relative; z-index: 1; }
.hero-section p  { font-size: 1.1rem; opacity:.95; position: relative; z-index: 1; }

/* Product grid */
.products-container { margin-bottom: 3rem; }
.section-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; }
.section-header h2 { font-size:1.6rem; color:#1e293b; }

.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1.25rem;
}

.product-card {
  background: #fff; border-radius: 1rem; overflow: hidden;
  box-shadow: 0 4px 20px rgba(0,0,0,.08);
  transition: transform .25s ease, box-shadow .25s ease;
  position: relative;
}
.product-card:hover { transform: translateY(-6px); box-shadow:0 12px 40px rgba(0,0,0,.15); }

.product-badge {
  position:absolute; top: .8rem; right: .8rem;
  padding: .25rem .6rem; border-radius: 1rem; font-size:.75rem; font-weight:700; color:#fff;
}
.badge-new{ background: linear-gradient(135deg,#10b981,#059669); }
.badge-sale{ background: linear-gradient(135deg,#ef4444,#dc2626); }
.badge-hot{ background: linear-gradient(135deg,#f59e0b,#d97706); }

.product-image-wrapper { position:relative; height: 210px; overflow:hidden; background:#eef2ff; }
.product-card img { width:100%; height:100%; object-fit:cover; transition: transform .3s ease; }
.product-card:hover img { transform: scale(1.06); }

.quick-view{
  position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
  background: rgba(255,255,255,.96); padding:.6rem 1rem; border-radius: 2rem;
  opacity:0; transition: opacity .25s ease; font-weight:600; color:#667eea; cursor:pointer;
}
.product-card:hover .quick-view{ opacity:1; }

.product-info { padding: 1rem 1.1rem 1.2rem; }
.product-name { font-size:1.05rem; font-weight:700; color:#1e293b; margin:.25rem 0 .35rem; line-height:1.35; }
.product-description { color:#64748b; font-size:.9rem; line-height:1.5; min-height: 40px; }

.product-price-row { display:flex; align-items:center; gap:.6rem; margin:.6rem 0 .8rem; }
.product-price { font-size:1.35rem; font-weight:800; color:#0f172a; }
.product-discount{ background:#fee2e2; color:#ef4444; padding:.15rem .45rem; border-radius:.4rem; font-size:.8rem; font-weight:700; }

.product-stock{ display:flex; align-items:center; gap:.5rem; font-size:.9rem; margin-bottom:.8rem; }
.stock-indicator{ width:8px; height:8px; border-radius:50%; background:#10b981; }
.stock-low{ background:#f59e0b; } .stock-out{ background:#ef4444; }

.product-actions{ display:flex; gap:.65rem; }
.btn-detail{
  flex:1; padding:.65rem; border:2px solid #e2e8f0; background:#fff; color:#667eea;
  border-radius:.7rem; font-weight:700; text-align:center; transition:.2s;
}
.btn-detail:hover{ background:#f8fafc; border-color:#667eea; }
.btn-cart{
  flex:1; padding:.65rem; border:none; border-radius:.7rem; font-weight:800; color:#fff;
  background: linear-gradient(135deg,#667eea,#764ba2);
  cursor:pointer; display:flex; align-items:center; justify-content:center; gap:.45rem; transition:.2s;
}
.btn-cart:hover{ transform: translateY(-2px); box-shadow:0 6px 16px rgba(102,126,234,.3); }
.btn-cart[disabled]{ opacity:.55; cursor:not-allowed; box-shadow:none; transform:none; }
.no-products{ text-align:center; padding:4rem 2rem; background:#fff; border-radius:1rem; box-shadow:0 4px 20px rgba(0,0,0,.08); }
</style>

<!-- Hero -->
<div class="hero-section">
  <h2>🛍️ ยินดีต้อนรับทุกท่าน</h2>
  <p>เลือกซื้อสินค้าและชำระเงินได้เลยค่ะ</p>
	<p> สินค้าพรีออเดอร์ราคาพิเศษ</p>
</div>

<div class="products-container">
  <div class="section-header">
    <h2>🛒 สินค้าทั้งหมด</h2>
  </div>

  <div class="product-grid">
<?php if ($products): foreach ($products as $p):
  $img = $p['image'] ? BASE_URL.'/'.ltrim($p['image'],'/') : BASE_URL.'/assets/images/noimg.png';

  // จำนวนคงเหลือที่ใช้ตัดสินใจ
  $eff = (int)($p['eff_stock'] ?? $p['stock'] ?? 0);

  // ---------- สถานะสต๊อค (เกณฑ์ 100 ชิ้น) ----------
  $lowThreshold = 100;
  $isOut        = ($eff <= 0);
  $isLow        = ($eff > 0 && $eff < $lowThreshold);

  $stockClass = $isOut ? 'stock-out' : ($isLow ? 'stock-low' : '');
  $statusText = $isOut ? 'สินค้าหมดแล้ว' : ($isLow ? 'สินค้าใกล้หมด' : 'พร้อมจำหน่าย');
    ?>
      <div class="product-card">
        <?php if ($badge==='new'): ?><span class="product-badge badge-new">NEW</span>
        <?php elseif ($badge==='sale'): ?><span class="product-badge badge-sale">SALE</span>
        <?php elseif ($badge==='hot'): ?><span class="product-badge badge-hot">HOT</span><?php endif; ?>

<div class="product-image-wrapper">
      <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
      <div class="quick-view" onclick="window.location.href='<?= BASE_URL ?>/product.php?id=<?= (int)$p['id'] ?>'">👁️ ดูรายละเอียด</div>
      <?php if ($isOut): ?>
        <div class="soldout-banner">สินค้าหมดชั่วคราว</div>
      <?php endif; ?>
    </div>

    <div class="product-info">
      <h3 class="product-name"><?= htmlspecialchars($p['name']) ?></h3>
      <p class="product-description">
        <?= htmlspecialchars(mb_substr($p['description'] ?: 'สินค้าคุณภาพดี พร้อมจัดส่ง', 0, 60)) ?>...
      </p>

      <div class="product-price-row">
        <span class="product-price">฿<?= format_currency($p['price']) ?></span>
      </div>

      <!-- แสดงสถานะตามจำนวนคงเหลือ (ไม่โชว์ตัวเลข) -->
      <div class="product-stock">
        <span class="stock-indicator <?= $stockClass ?>"></span>
        <span><?= $statusText ?></span>
        <!-- <span>คงเหลือ: <?= $eff ?> ชิ้น</span> -->
      </div>

      <div class="product-actions">
        <a class="btn-detail" href="<?= BASE_URL ?>/product.php?id=<?= (int)$p['id'] ?>">รายละเอียด</a>
        <button
          class="btn-cart"
          data-add-to-cart
          data-id="<?= (int)$p['id'] ?>"
          <?= $isOut ? 'disabled title="สินค้าหมดแล้ว"' : '' ?>
        >
          <span>🛒</span>
          <span><?= $isOut ? 'สินค้าหมด' : 'ใส่ตะกร้า' ?></span>
        </button>
      </div>
    </div>
  </div>
<?php endforeach; else: ?>
      <div class="no-products">
        <div style="font-size:3rem">📦</div>
        <h3>ไม่พบสินค้า</h3>
        <p>ขออภัย ขณะนี้ยังไม่มีสินค้าในระบบ</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
// ป้องกัน error ถ้า search bar ถูกคอมเมนต์ไว้
(function(){
  const sb = document.getElementById('searchInput');
  if (sb) {
    sb.addEventListener('input', function(e) {
      const term = e.target.value.toLowerCase();
      document.querySelectorAll('.product-card').forEach(card=>{
        const name = card.querySelector('.product-name')?.textContent.toLowerCase() || '';
        const desc = card.querySelector('.product-description')?.textContent.toLowerCase() || '';
        card.style.display = (name.includes(term) || desc.includes(term)) ? '' : 'none';
      });
    });
  }
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
