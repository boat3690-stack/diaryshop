<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

if (isset($_GET['download'])) {
  $st = $pdo->prepare("SELECT id,created_at,expires_at,fullname,email,phone,grand_total,status,tracking_no,coupon_code
                       FROM orders WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC");
  $st->execute([$from,$to]);

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=sales_'.$from.'_'.$to.'.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['order_id','created_at','expires_at','name','email','phone','grand_total','status','tracking_no','coupon_code']);
  while($r=$st->fetch()){ fputcsv($out, $r); }
  fclose($out); exit;
}

include __DIR__ . '/../partials/header.php';
?>
<h2>Export ข้อมูลการขาย</h2>
<div class="card" style="max-width:560px">
  <form method="get">
    <label>ตั้งแต่</label><input class="input" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <label>ถึง</label><input class="input" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    <button class="btn" type="submit" name="download" value="1">ดาวน์โหลด CSV</button>
    <p class="small" style="opacity:.8">เปิดได้ด้วย Excel/Numbers/Google Sheets</p>
  </form>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
