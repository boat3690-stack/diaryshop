<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
require_admin();

/* ป้องกัน null -> string เวลา escape HTML */
function h($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$inv   = product_inventory($pdo);
$lowTh = (int)get_setting($pdo,'low_stock_threshold',5);

$rows = $pdo->query('SELECT id, sku, name, price, stock, is_active, created_at
                     FROM products ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

$display=[];
foreach ($rows as $p) {
  $info = $inv[(int)$p['id']] ?? ['stock'=>0,'reserved'=>0,'available'=>0];
  $display[] = [
    'id'        => (int)$p['id'],
    'sku'       => (string)($p['sku'] ?? ''),    // << กัน null
    'name'      => (string)($p['name'] ?? ''),   // << กัน null
    'stock'     => (int)$info['stock'],
    'reserved'  => (int)$info['reserved'],
    'available' => (int)$info['available'],
    'price'     => (float)($p['price'] ?? 0),
    'is_active' => !empty($p['is_active']) ? 1 : 0,
  ];
}

/* ===== Export CSV ===== */
if (($_GET['export'] ?? '') === 'csv') {
  export_csv(
    'stock_report_'.date('Ymd_His').'.csv',
    ['id','sku','name','stock','reserved','available','price','is_active'],
    array_map(fn($x)=>[
      $x['id'], $x['sku'], $x['name'], $x['stock'], $x['reserved'],
      $x['available'], number_format($x['price'],2,'.',''), $x['is_active']
    ], $display)
  );
}

/* ===== Export PDF ===== */
if (($_GET['export'] ?? '') === 'pdf') {
  ob_start(); ?>
  <table>
    <tr><th>#</th><th>SKU</th><th>ชื่อสินค้า</th><th>สต๊อก</th><th>ติดจอง</th><th>พร้อมขาย</th><th>ราคา</th></tr>
    <?php foreach($display as $x): ?>
      <tr>
        <td><?= $x['id'] ?></td>
        <td><?= h($x['sku']) ?></td>     <!-- ใช้ h() -->
        <td><?= h($x['name']) ?></td>    <!-- ใช้ h() -->
        <td><?= $x['stock'] ?></td>
        <td><?= $x['reserved'] ?></td>
        <td><?= $x['available'] ?></td>
        <td><?= number_format($x['price'],2) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php export_pdf('stock_report_'.date('Ymd_His').'.pdf','รายงานสต๊อกสินค้า', ob_get_clean());
}

include __DIR__ . '/../../partials/header.php';
?>
<h2>รายงานสต๊อก</h2>
<div class="filterbar" style="margin:.75rem 0;display:flex;gap:.5rem;flex-wrap:wrap">
  <a class="btn outline" href="?export=csv">Export CSV</a>
  <a class="btn outline" href="?export=pdf">Export PDF</a>
</div>

<style>
  /* ช่วยจัดชิดขวาคอลัมน์ตัวเลขให้ตรงกัน */
  .table th.right, .table td.right { text-align:right; }
</style>

<div class="card">
  <table class="table">
    <tr>
      <th>#</th><th>SKU</th><th>ชื่อสินค้า</th>
      <th class="right">สต๊อก</th><th class="right">ติดจอง</th>
      <th class="right">พร้อมขาย</th><th class="right">ราคา</th><th>สถานะ</th>
    </tr>
    <?php foreach($display as $x):
      $low = ($x['available'] <= $lowTh);
    ?>
    <tr>
      <td><?= $x['id'] ?></td>
      <td><?= h($x['sku']) ?></td>
      <td><?= h($x['name']) ?></td>
      <td class="right"><?= $x['stock'] ?></td>
      <td class="right"><?= $x['reserved'] ?></td>
      <td class="right"><span class="badge <?= $low?'error':'' ?>"><?= $x['available'] ?></span></td>
      <td class="right">฿<?= number_format($x['price'],2) ?></td>
      <td><?= $x['is_active'] ? 'แสดง' : 'ปิด' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
