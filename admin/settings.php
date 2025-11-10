<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

if ($_SERVER['REQUEST_METHOD']==='POST' && (!isset($_POST['__section']) || $_POST['__section']!=='shipping_cover')) {
  if (!csrf_check($_POST['csrf'] ?? '')) { flash('error','CSRF invalid'); redirect('settings.php'); }

  // เก็บค่าข้อความทั่วไป
  $pairs = [
    'store_name'   => trim($_POST['store_name'] ?? ''),
    'bank_account' => trim($_POST['bank_account'] ?? ''),
  ];

  /* ---------- QR โอนเงิน (เดิม) ---------- */
  $qrUrl = trim($_POST['bank_qr_url'] ?? '');
  if (!empty($_FILES['bank_qr']['name'])) {
    $qr = handle_upload($_FILES['bank_qr'], 'uploads', ['jpg','jpeg','png','gif','webp']);
    if ($qr) { $pairs['bank_qr'] = $qr; }
  } elseif ($qrUrl !== '') {
    $pairs['bank_qr'] = $qrUrl;
  }

  /* ---------- QR แผนที่ (ใหม่) ---------- */
  $mapUrl = trim($_POST['map_qr_url'] ?? '');
  if (!empty($_FILES['map_qr']['name'])) {
    $map = handle_upload($_FILES['map_qr'], 'uploads/settings', ['jpg','jpeg','png','gif','webp']);
    if ($map) { $pairs['map_qr'] = $map; }
  } elseif ($mapUrl !== '') {
    $pairs['map_qr'] = $mapUrl;
  }

  foreach ($pairs as $k=>$v) {
    $st = $pdo->prepare('INSERT INTO settings (`key`,`value`)
                         VALUES (?,?)
                         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $st->execute([$k,$v]);
  }

  flash('success','บันทึกการตั้งค่าแล้ว');
  redirect('settings.php');
}

include __DIR__ . '/../partials/header.php';

function setting_img_src(PDO $pdo, string $key): string {
  $val = trim(get_setting($pdo,$key,''));
  if ($val==='') return '';
  if (preg_match('#^https?://#i',$val)) return $val;
  return BASE_URL . '/' . ltrim($val,'/');
}

$map_qr_src  = setting_img_src($pdo,'map_qr');
$bank_qr_src = setting_img_src($pdo,'bank_qr');
?>
<h2>ตั้งค่าร้านค้า</h2>

<form method="post" enctype="multipart/form-data"
      class="card"
      style="display:grid;gap:.6rem;max-width:680px;
             background:#fff;border:1px solid #ddd;border-radius:.8rem;
             padding:1rem;color:#222">
  <?= csrf_field() ?>

  <label>ชื่อร้าน</label>
  <input class="input" name="store_name" value="<?= htmlspecialchars(get_setting($pdo,'store_name','PHP Shop')) ?>">

  <label>บัญชีธนาคาร (สำหรับโอน)</label>
  <input class="input" name="bank_account" value="<?= htmlspecialchars(get_setting($pdo,'bank_account','')) ?>">

  <div style="display:grid;gap:.4rem;grid-template-columns:1fr;justify-items:start">
    <label>รูป QR ธนาคาร (อัปโหลด)</label>
    <?php if($bank_qr_src): ?>
      <img src="<?= htmlspecialchars($bank_qr_src) ?>" alt="Bank QR" style="width:160px;height:auto;border:1px solid #ccc;border-radius:.5rem">
    <?php endif; ?>
    <input class="input" type="file" name="bank_qr" accept="image/*">
    <label>หรือ URL รูป QR</label>
    <input class="input" name="bank_qr_url" placeholder="https://...">
  </div>

  <hr style="border:none;border-top:1px solid #eee;margin:.6rem 0">

  <div style="display:grid;gap:.4rem;grid-template-columns:1fr;justify-items:start">
    <label>QR โค้ดแผนที่ (แสดงตอนลูกค้าเลือก &ldquo;มารับเองที่ร้าน&rdquo; และหลังสั่งซื้อ)</label>
    <?php if($map_qr_src): ?>
      <img src="<?= htmlspecialchars($map_qr_src) ?>" alt="Map QR" style="width:160px;height:auto;border:1px solid #ccc;border-radius:.5rem">
    <?php endif; ?>
    <input class="input" type="file" name="map_qr" accept="image/*">
    <label>หรือ URL รูป QR แผนที่</label>
    <input class="input" name="map_qr_url" placeholder="https://...">
    <div class="small" style="opacity:.8">แนะนำ PNG/JPG 512×512px</div>
  </div>

  <button class="btn" type="submit">บันทึก</button>
</form>

<?php
/* ===============================================================
   Shipping & Cover Settings Block (white background)
   =============================================================== */
if (file_exists(__DIR__ . '/../includes/settings.php')) {
  require_once __DIR__ . '/../includes/settings.php';
}
$__canUpdateSettings = function_exists('has_perm') ? has_perm('settings.update') : true;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['__section'] ?? '') === 'shipping_cover') {
  if (function_exists('csrf_check') && !csrf_check($_POST['csrf'] ?? '')) {
    flash('error','CSRF invalid'); redirect('settings.php');
  }
  if (!$__canUpdateSettings) {
    flash('error','ไม่มีสิทธิ์แก้ไข'); redirect('settings.php');
  }

  $first = (float)($_POST['shipping_first'] ?? 50);
  $next  = (float)($_POST['shipping_next'] ?? 10);
  $cbase = (float)($_POST['cover_base'] ?? 500);
  $cover = (float)($_POST['cover_over_rate'] ?? 2);

  foreach (['shipping_first'=>$first,'shipping_next'=>$next,'cover_base'=>$cbase,'cover_over_rate'=>$cover] as $k=>$v) {
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$k,$v]);
  }
  flash('success','บันทึกค่า ค่าส่ง/ค่าพิมพ์ปก แล้ว');
  redirect('settings.php');
}

$__shipping_first = (float)get_setting($pdo,'shipping_first',50);
$__shipping_next  = (float)get_setting($pdo,'shipping_next',10);
$__cover_base     = (float)get_setting($pdo,'cover_base',500);
$__cover_over     = (float)get_setting($pdo,'cover_over_rate',2);
?>

<div class="settings-card"
     style="background:#fff;border:1px solid #ddd;border-radius:.8rem;
            margin:1rem 0;box-shadow:0 6px 20px rgba(0,0,0,.1)">
  <div class="head"
       style="padding:.9rem 1rem;border-bottom:1px solid #eee;font-weight:600;color:#333">
    ค่าส่ง & ค่าพิมพ์ปก
  </div>
  <div class="body" style="padding:1rem">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="__section" value="shipping_cover">

      <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div class="form-row">
          <label style="display:block;font-size:.95rem;margin-bottom:.35rem;color:#444">
            ค่าส่งเล่มแรก (บาท)
          </label>
          <input type="number" step="0.01" min="0" name="shipping_first"
                 value="<?= htmlspecialchars(number_format($__shipping_first,2,'.','')) ?>"
                 style="width:100%;padding:.55rem .7rem;background:#fff;border:1px solid #ccc;border-radius:.5rem;color:#000" required>
        </div>
        <div class="form-row">
          <label style="display:block;font-size:.95rem;margin-bottom:.35rem;color:#444">
            ค่าส่งเล่มถัดไป (บาท/เล่ม)
          </label>
          <input type="number" step="0.01" min="0" name="shipping_next"
                 value="<?= htmlspecialchars(number_format($__shipping_next,2,'.','')) ?>"
                 style="width:100%;padding:.55rem .7rem;background:#fff;border:1px solid #ccc;border-radius:.5rem;color:#000" required>
        </div>
        <div class="form-row">
          <label style="display:block;font-size:.95rem;margin-bottom:.35rem;color:#444">
            ค่าพิมพ์ปก (ฐาน)
          </label>
          <input type="number" step="0.01" min="0" name="cover_base"
                 value="<?= htmlspecialchars(number_format($__cover_base,2,'.','')) ?>"
                 style="width:100%;padding:.55rem .7rem;background:#fff;border:1px solid #ccc;border-radius:.5rem;color:#000" required>
        </div>
        <div class="form-row">
          <label style="display:block;font-size:.95rem;margin-bottom:.35rem;color:#444">
            ค่าพิมพ์ปก (ต่อเล่ม ≥ 51)
          </label>
          <input type="number" step="0.01" min="0" name="cover_over_rate"
                 value="<?= htmlspecialchars(number_format($__cover_over,2,'.','')) ?>"
                 style="width:100%;padding:.55rem .7rem;background:#fff;border:1px solid #ccc;border-radius:.5rem;color:#000" required>
        </div>
      </div>

      <div class="actions" style="margin-top:.8rem;display:flex;gap:.5rem;align-items:center">
        <button class="btn" type="submit" <?= $__canUpdateSettings ? '' : 'disabled' ?>
                style="display:inline-block;background:#3b82f6;border:0;color:#fff;
                       padding:.55rem .9rem;border-radius:.55rem;cursor:pointer">
          บันทึกการตั้งค่า
        </button>
        <?php if (!$__canUpdateSettings): ?>
          <span class="muted" style="opacity:.7;font-size:.9rem;color:#666">
            * คุณไม่มีสิทธิ์แก้ไขการตั้งค่า
          </span>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
