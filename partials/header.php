<?php
require_once __DIR__ . '/../config/config.php';

$store_name   = get_setting($pdo, 'store_name', 'PHP Shop');
$flash_error  = flash('error');
$flash_success= flash('success');

$reqPath       = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
$is_admin_area = (strpos($reqPath, '/admin/') !== false);

// --- [แก้ไข] เพิ่มเงื่อนไขให้ Popup แสดงเฉพาะหน้า index.php ---
$popup_enabled = false;
$popup_html = '';
// เพิ่ม basename($_SERVER['SCRIPT_NAME']) === 'index.php' ในเงื่อนไข
if (!$is_admin_area && basename($_SERVER['SCRIPT_NAME']) === 'index.php' && isset($pdo) && function_exists('get_setting')) {
    $popup_enabled = (get_setting($pdo, 'welcome_popup_enabled', '0') === '1');
    if ($popup_enabled) {
        $popup_html = get_setting($pdo, 'welcome_popup_html', '');
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($store_name) ?></title>

  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <script>window.CSRF=<?= json_encode(csrf_token()) ?>;</script>
  <script src="<?= BASE_URL ?>/assets/js/app.js" defer></script>

  <style>
    .popup-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 1050;
        display: none;
        align-items: center;
        justify-content: center;
    }
    .popup-content {
        background: #fff;
        padding: 2rem;
        border-radius: 1rem;
        max-width: 600px;
        width: 90%;
        position: relative;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        animation: fadeIn 0.3s ease-out;
    }
    .popup-close {
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 2rem;
        color: #888;
        cursor: pointer;
        line-height: 1;
    }
    .popup-close:hover { color: #000; }
    @keyframes fadeIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
  </style>
	
	<script>
  window.CSRF = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';
  window.BASE_URL = '<?= rtrim(BASE_URL, '/') ?>';
</script>

<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/images/cart.svg">
<link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/images/cart.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= BASE_URL ?>/assets/images/cart.png">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/images/cart.png">
<meta name="theme-color" content="#2b2b2b">
</head>
	
<body>
<?php if ($popup_enabled && !empty($popup_html)): ?>
<div id="welcomePopup" class="popup-overlay">
    <div class="popup-content">
        <span class="popup-close" id="popupCloseBtn">&times;</span>
        <div class="popup-body">
            <?= $popup_html; ?>
        </div>
    </div>
</div>
<?php endif; ?>

  <div class="container">
    <div class="nav">
      <div class="logo">
        <a href="<?= BASE_URL ?>/index.php"><?= htmlspecialchars($store_name) ?></a>
      </div>
      <div class="right">
        <?php if ($is_admin_area): ?>
          <?php if (is_admin()): ?>
            <a class="btn outline" href="<?= BASE_URL ?>/admin/dashboard.php">แดชบอร์ด</a>
            <a class="btn outline" href="<?= BASE_URL ?>/admin/quick_order.php">+ ระบบขายหน้าร้าน</a>
            <a class="btn outline" href="<?= BASE_URL ?>/admin/shipping_history.php">ประวัติส่ง</a>
            <a class="btn" href="<?= BASE_URL ?>/logout.php">ออกระบบ</a>
          <?php else: ?>
            <a class="btn" href="<?= BASE_URL ?>/login.php">เข้าสู่ระบบ</a>
            <a class="btn outline" href="<?= BASE_URL ?>/index.php">กลับหน้าร้าน</a>
          <?php endif; ?>
        <?php else: ?>
		  <a class="btn outline" href="<?= BASE_URL ?>/blog.php">📣ข่าว</a>
          <a class="btn outline" href="<?= BASE_URL ?>/cart.php">🛒ตะกร้า</a>
          <a class="btn outline" href="<?= BASE_URL ?>/track.php">📦ติดตามสถานะ</a>
          <?php if (is_logged_in()): ?>
            <?php if (is_admin()): ?>
              <a class="btn outline" href="<?= BASE_URL ?>/admin/dashboard.php">แอดมิน</a>
            <?php endif; ?>
            <a class="btn" href="<?= BASE_URL ?>/logout.php">ออกระบบ</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($flash_error): ?>
      <div class="alert error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_success): ?>
      <div class="alert success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>
    
<script>
document.addEventListener('DOMContentLoaded', function() {
    const popup = document.getElementById('welcomePopup');
    if (popup) {
        const closeBtn = document.getElementById('popupCloseBtn');
        popup.style.display = 'flex';
        const closePopup = () => { popup.style.display = 'none'; };
        closeBtn.addEventListener('click', closePopup);
        popup.addEventListener('click', function(event) {
            if (event.target === popup) {
                closePopup();
            }
        });
    }
});
</script>