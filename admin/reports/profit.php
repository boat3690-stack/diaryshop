<?php
// admin/reports/profit.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
require_admin();

/* ---------- helpers ---------- */
function col_exists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = "$table.$col";
  if (isset($cache[$key])) return $cache[$key];
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
  ");
  $st->execute([$table,$col]);
  return $cache[$key] = (bool)$st->fetchColumn();
}

/* ---------- input dates (safe defaults) ---------- */
$df = $_GET['date_from'] ?? date('Y-m-01');
$dt = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) $df = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) $dt = date('Y-m-d');
if (strtotime($df) > strtotime($dt)) { $tmp=$df; $df=$dt; $dt=$tmp; }

/* ---------- revenue = sum(grand_total) of effective orders ---------- */
$revSql = "SELECT DATE(o.created_at) d, SUM(o.grand_total) rev
           FROM orders o
           WHERE DATE(o.created_at) BETWEEN ? AND ?
             AND o.status IN ('paid','processing','shipped','completed')
           GROUP BY DATE(o.created_at)";
$rev = $pdo->prepare($revSql);
$rev->execute([$df,$dt]);
$rev = $rev->fetchAll(PDO::FETCH_KEY_PAIR);

/* ---------- cost expression (auto-detect available columns) ---------- */
$use_unit_cost  = col_exists($pdo,'order_items','unit_cost');
$use_unit_price = col_exists($pdo,'order_items','unit_price');
$use_price      = col_exists($pdo,'order_items','price');
$use_prod_cost  = col_exists($pdo,'products','cost');

// Priority: unit_cost -> unit_price/price -> products.cost -> 0
$parts = [];
if ($use_unit_cost)  $parts[] = 'oi.unit_cost';
if ($use_unit_price) $parts[] = 'oi.unit_price';
if ($use_price)      $parts[] = 'oi.price';
if ($use_prod_cost)  $parts[] = 'p.cost';
$coalesce = $parts ? ('COALESCE('.implode(',', $parts).',0)') : '0';

$costSql = "
  SELECT DATE(o.created_at) d, SUM( ($coalesce) * oi.qty ) cost
  FROM order_items oi
    JOIN orders o ON o.id=oi.order_id
    ".($use_prod_cost ? "LEFT JOIN products p ON p.id=oi.product_id" : "")."
  WHERE DATE(o.created_at) BETWEEN ? AND ?
    AND o.status IN ('paid','processing','shipped','completed')
  GROUP BY DATE(o.created_at)
";
$cost = $pdo->prepare($costSql);
$cost->execute([$df,$dt]);
$cost = $cost->fetchAll(PDO::FETCH_KEY_PAIR);

/* ---------- build daily series ---------- */
$days=[]; for($d=strtotime($df); $d<=strtotime($dt); $d+=86400){ $days[] = date('Y-m-d',$d); }
$data=[]; $totR=0; $totC=0; $totP=0;
foreach($days as $d){
  $r = (float)($rev[$d]  ?? 0);
  $c = (float)($cost[$d] ?? 0);
  $p = $r - $c;
  $m = $r>0 ? ($p/$r*100) : 0;
  $data[] = ['d'=>$d,'revenue'=>$r,'cost'=>$c,'profit'=>$p,'margin'=>$m];
  $totR += $r; $totC += $c; $totP += $p;
}
$totM = $totR>0 ? ($totP/$totR*100) : 0;

/* ---------- export ---------- */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  export_csv("profit_{$df}_{$dt}.csv",
    ['date','revenue','cost','profit','margin_%'],
    array_map(fn($x)=>[
      $x['d'],
      number_format($x['revenue'],2,'.',''),
      number_format($x['cost'],2,'.',''),
      number_format($x['profit'],2,'.',''),
      number_format($x['margin'],2,'.',''),
    ], $data)
  );
}
if (isset($_GET['export']) && $_GET['export']==='pdf') {
  ob_start(); ?>
  <style>
    table{width:100%;border-collapse:collapse}
    th,td{border:1px solid #ddd;padding:.5rem;text-align:right}
    th:first-child,td:first-child{text-align:left}
    tfoot td{font-weight:bold}
  </style>
  <h3>รายงานต้นทุน-กำไร (<?= htmlspecialchars($df) ?> → <?= htmlspecialchars($dt) ?>)</h3>
  <table>
    <thead>
      <tr><th>วันที่</th><th>รายได้</th><th>ต้นทุน</th><th>กำไร</th><th>Margin %</th></tr>
    </thead>
    <tbody>
      <?php foreach($data as $x): ?>
      <tr>
        <td><?= htmlspecialchars($x['d']) ?></td>
        <td><?= number_format($x['revenue'],2) ?></td>
        <td><?= number_format($x['cost'],2) ?></td>
        <td><?= number_format($x['profit'],2) ?></td>
        <td><?= number_format($x['margin'],2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td>รวม</td>
        <td><?= number_format($totR,2) ?></td>
        <td><?= number_format($totC,2) ?></td>
        <td><?= number_format($totP,2) ?></td>
        <td><?= number_format($totM,2) ?></td>
      </tr>
    </tfoot>
  </table>
  <?php
  export_pdf("profit_{$df}_{$dt}.pdf", "รายงานต้นทุน-กำไร ($df → $dt)", ob_get_clean());
}

/* ---------- render ---------- */
include __DIR__ . '/../../partials/header.php';
?>
<h2>รายงานต้นทุน - กำไร</h2>

<form method="get" class="filterbar" style="display:flex;gap:.5rem;flex-wrap:wrap">
  <input class="input" type="date" name="date_from" value="<?= htmlspecialchars($df) ?>">
  <input class="input" type="date" name="date_to"   value="<?= htmlspecialchars($dt) ?>">
  <button class="btn">ดูรายงาน</button>
  <a class="btn outline" href="?date_from=<?= urlencode($df) ?>&date_to=<?= urlencode($dt) ?>&export=csv">Export CSV</a>
  <a class="btn outline" href="?date_from=<?= urlencode($df) ?>&date_to=<?= urlencode($dt) ?>&export=pdf">Export PDF</a>
</form>

<div class="card">
  <table class="table">
    <tr><th>วันที่</th><th>รายได้</th><th>ต้นทุน</th><th>กำไร</th><th>Margin %</th></tr>
    <?php foreach($data as $x): ?>
    <tr>
      <td class="left"><?= htmlspecialchars($x['d']) ?></td>
      <td>฿<?= number_format($x['revenue'],2) ?></td>
      <td>฿<?= number_format($x['cost'],2) ?></td>
      <td>฿<?= number_format($x['profit'],2) ?></td>
      <td><?= number_format($x['margin'],2) ?>%</td>
    </tr>
    <?php endforeach; ?>
    <tr>
      <td class="left"><strong>รวม</strong></td>
      <td><strong>฿<?= number_format($totR,2) ?></strong></td>
      <td><strong>฿<?= number_format($totC,2) ?></strong></td>
      <td><strong>฿<?= number_format($totP,2) ?></strong></td>
      <td><strong><?= number_format($totM,2) ?>%</strong></td>
    </tr>
  </table>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
