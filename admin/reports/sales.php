<?php
// admin/reports/sales.php  — รุ่นกันพัง (ใช้คอลัมน์ที่มีแน่ ๆ)
require_once __DIR__ . '/../../config/config.php';
require_admin();

// ------ เปิดดู error ชั่วคราว (ปิดเมื่อใช้จริง) ------
if (isset($_GET['debug'])) { ini_set('display_errors',1); error_reporting(E_ALL); }

// ------ export helpers (fallback ถ้าไฟล์หลักไม่มี) ------
$eh = __DIR__ . '/../../includes/export_helpers.php';
if (is_file($eh)) require_once $eh;

if (!function_exists('export_csv')) {
  function export_csv($filename, array $headers, array $rows){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename='.$filename);
    $out = fopen('php://output','w');
    fputcsv($out, $headers);
    foreach($rows as $r) fputcsv($out, $r);
    fclose($out); exit;
  }
}
if (!function_exists('export_pdf')) {
  // ถ้าไม่มี lib ทำ PDF ก็ส่งเป็น HTML ดาวน์โหลดแทน (กันพัง)
  function export_pdf($filename, $title, $html){
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename='.preg_replace('/\.pdf$/i','.html',$filename));
    echo "<h3>{$title}</h3>", $html; exit;
  }
}

// ------ filters ------
$df = $_GET['date_from'] ?? date('Y-m-01');
$dt = $_GET['date_to']   ?? date('Y-m-d');

// นับเฉพาะสถานะที่ถือว่ามียอดขายแล้ว
$ok = "'paid','processing','shipped','completed'";

// ------ สรุปรายวัน (ใช้ grand_total อย่างเดียว เพื่อกัน Unknown column) ------
$daily = [];
try {
  $st = $pdo->prepare("
    SELECT DATE(o.created_at) AS d,
           COUNT(*)                     AS orders_cnt,
           SUM(COALESCE(o.grand_total,0)) AS revenue
    FROM orders o
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      AND o.status IN ($ok)
    GROUP BY DATE(o.created_at)
    ORDER BY d ASC
  ");
  $st->execute([$df,$dt]);
  $daily = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // แสดงข้อความเมื่อ debug=1
  if (isset($_GET['debug'])) { die('DAILY SQL ERROR: '.$e->getMessage()); }
  $daily = [];
}

// ------ สินค้าขายดี (รองรับได้ทั้ง unit_price/price; ถ้าไม่มี ใช้ products.price) ------
$top = [];
try {
  $st = $pdo->prepare("
    SELECT
      oi.product_id,
      COALESCE(p.name, CONCAT('สินค้า #', oi.product_id)) AS name,
      SUM(oi.qty)                                         AS qty,
      SUM( oi.qty * COALESCE(oi.unit_price, oi.price, p.price, 0) ) AS amount
    FROM order_items oi
    JOIN orders o        ON o.id = oi.order_id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      AND o.status IN ($ok)
    GROUP BY oi.product_id, name
    HAVING qty > 0
    ORDER BY amount DESC, qty DESC
  ");
  $st->execute([$df,$dt]);
  $top = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  if (isset($_GET['debug'])) { die('TOP SQL ERROR: '.$e->getMessage()); }
  $top = [];
}

// ------ Export ------
if (isset($_GET['export'])) {
  $exp = $_GET['export'];
  if ($exp === 'csv_daily') {
    export_csv("sales_daily_{$df}_{$dt}.csv",
      ['date','orders','revenue'],
      array_map(fn($r)=>[
        $r['d'],
        (int)$r['orders_cnt'],
        number_format((float)$r['revenue'],2,'.',''),
      ], $daily)
    );
  }
  if ($exp === 'csv_products') {
    export_csv("sales_products_{$df}_{$dt}.csv",
      ['product_id','name','qty','amount'],
      array_map(fn($r)=>[
        (int)$r['product_id'],
        $r['name'],
        (int)$r['qty'],
        number_format((float)$r['amount'],2,'.',''),
      ], $top)
    );
  }
  if ($exp === 'pdf_daily' || $exp === 'pdf_products') {
    ob_start();
    if ($exp === 'pdf_daily') { ?>
      <table border="1" cellpadding="6" cellspacing="0" width="100%">
        <tr><th>วันที่</th><th>ออเดอร์</th><th>รายได้ (grand_total)</th></tr>
        <?php foreach($daily as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['d']) ?></td>
            <td style="text-align:right"><?= (int)$r['orders_cnt'] ?></td>
            <td style="text-align:right"><?= number_format((float)$r['revenue'],2) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php } else { ?>
      <table border="1" cellpadding="6" cellspacing="0" width="100%">
        <tr><th>#</th><th>สินค้า</th><th>จำนวน</th><th>ยอดขาย</th></tr>
        <?php foreach($top as $r): ?>
          <tr>
            <td><?= (int)$r['product_id'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td style="text-align:right"><?= (int)$r['qty'] ?></td>
            <td style="text-align:right"><?= number_format((float)$r['amount'],2) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php }
    $html = ob_get_clean();
    $title = ($exp==='pdf_daily') ? "รายงานยอดขายรายวัน ($df → $dt)" : "รายงานสินค้า ($df → $dt)";
    export_pdf(($exp==='pdf_daily'?"sales_daily_{$df}_{$dt}.pdf":"sales_products_{$df}_{$dt}.pdf"), $title, $html);
  }
}

// ------ view ------
include __DIR__ . '/../../partials/header.php';
?>
<h2>รายงานยอดขาย</h2>

<form method="get" class="filterbar" style="display:flex;gap:.5rem;flex-wrap:wrap">
  <input class="input" type="date" name="date_from" value="<?= htmlspecialchars($df) ?>">
  <input class="input" type="date" name="date_to"   value="<?= htmlspecialchars($dt) ?>">
  <button class="btn">ดูรายงาน</button>
  <a class="btn outline" href="?date_from=<?= urlencode($df) ?>&date_to=<?= urlencode($dt) ?>&export=csv_daily">CSV (รายวัน)</a>
  <a class="btn outline" href="?date_from=<?= urlencode($df) ?>&date_to=<?= urlencode($dt) ?>&export=pdf_daily">PDF (รายวัน)</a>
  <a class="btn outline" href="?date_from=<?= urlencode($df) ?>&date_to=<?= urlencode($dt) ?>&export=csv_products">CSV (สินค้า)</a>
  <a class="btn outline" href="?date_from=<?= urlencode($df) ?>&date_to=<?= urlencode($dt) ?>&export=pdf_products">PDF (สินค้า)</a>
</form>

<div class="card">
  <h3>สรุปยอดขายรายวัน</h3>
  <table class="table">
    <tr><th>วันที่</th><th class="right">ออเดอร์</th><th class="right">รายได้ (grand_total)</th></tr>
    <?php $sumOrders=0; $sumRev=0;
    foreach($daily as $r): $sumOrders+=(int)$r['orders_cnt']; $sumRev+=(float)$r['revenue']; ?>
      <tr>
        <td><?= htmlspecialchars($r['d']) ?></td>
        <td class="right"><?= (int)$r['orders_cnt'] ?></td>
        <td class="right"><strong>฿<?= number_format((float)$r['revenue'],2) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <tr>
      <th>รวม</th>
      <th class="right"><?= $sumOrders ?></th>
      <th class="right">฿<?= number_format($sumRev,2) ?></th>
    </tr>
  </table>
</div>

<div class="card">
  <h3>สินค้าขายดี (ช่วงวันที่ที่เลือก)</h3>
  <table class="table">
    <tr><th>#</th><th>สินค้า</th><th class="right">จำนวน</th><th class="right">ยอดขาย</th></tr>
    <?php foreach($top as $r): ?>
      <tr>
        <td><?= (int)$r['product_id'] ?></td>
        <td class="left"><?= htmlspecialchars($r['name']) ?></td>
        <td class="right"><?= (int)$r['qty'] ?></td>
        <td class="right">฿<?= number_format((float)$r['amount'],2) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php include __DIR__ . '/../../partials/footer.php';
