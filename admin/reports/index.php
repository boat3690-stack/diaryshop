<?php
require_once __DIR__ . '/../../config/config.php';
require_admin();
include __DIR__ . '/../../partials/header.php';
?>
<h2>รายงาน</h2>
<div class="card" style="display:grid;gap:.5rem;max-width:640px">
  <a class="btn" href="sales.php">รายงานยอดขาย</a>
  <a class="btn" href="profit.php">รายงานต้นทุน - กำไร</a>
  <a class="btn" href="admin_activity.php">รายงานการทำงานของแอดมิน</a>
  <a class="btn" href="stock.php">รายงานสต๊อกสินค้า</a>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
