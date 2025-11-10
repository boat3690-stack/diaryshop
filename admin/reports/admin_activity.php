<?php
require_once __DIR__ . '/../../config/config.php';
require_admin();

// ---------- ฟิลเตอร์ ----------
$df   = $_GET['date_from'] ?? date('Y-m-01');
$dt   = $_GET['date_to']   ?? date('Y-m-d');
$q    = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 30;
$off  = ($page - 1) * $per;

// ---------- ตรวจว่ามีตาราง users แบบปลอดภัย ----------
$hasUsers = false;
try {
  $pdo->query("SELECT 1 FROM users LIMIT 1");
  $hasUsers = true;
} catch (Throwable $e) {
  $hasUsers = false;
}

// ---------- WHERE ----------
$where   = ["DATE(l.created_at) BETWEEN ? AND ?"];
$params  = [$df, $dt];
if ($q !== '') {
  $where[] = "(l.action LIKE ? OR l.entity_type LIKE ? OR l.meta LIKE ?)";
  $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
$wsql = 'WHERE '.implode(' AND ', $where);

// ---------- นับทั้งหมด ----------
$cnt = $pdo->prepare("SELECT COUNT(*) FROM admin_logs l $wsql");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

// ---------- ดึงรายการ ----------
$nameExpr = $hasUsers
  ? "COALESCE(u.name, u.email, CONCAT('admin#', l.admin_id))"
  : "CONCAT('admin#', l.admin_id)";

$sql = "SELECT l.*, $nameExpr AS admin_name
        FROM admin_logs l
        ".($hasUsers ? "LEFT JOIN users u ON u.id = l.admin_id" : "")."
        $wsql
        ORDER BY l.id DESC
        LIMIT $per OFFSET $off";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ---------- Export CSV ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $sqlAll = "SELECT l.*, $nameExpr AS admin_name
             FROM admin_logs l
             ".($hasUsers ? "LEFT JOIN users u ON u.id = l.admin_id" : "")."
             $wsql
             ORDER BY l.id DESC";
  $all = $pdo->prepare($sqlAll);
  $all->execute($params);

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=admin_activity_'.$df.'_'.$dt.'.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['id','date','admin','action','entity','entity_id','meta','ip']);
  while($r = $all->fetch(PDO::FETCH_ASSOC)){
    fputcsv($out, [
      $r['id'],
      $r['created_at'],
      $r['admin_name'],
      $r['action'],
      $r['entity_type'],
      $r['entity_id'],
      $r['meta'],
      $r['ip']
    ]);
  }
  fclose($out); exit;
}

include __DIR__ . '/../../partials/header.php';
?>
<h2>รายงานการทำงานของแอดมิน</h2>

<form method="get" class="filterbar" style="display:flex;gap:.5rem;flex-wrap:wrap">
  <input class="input" type="date" name="date_from" value="<?= htmlspecialchars($df) ?>">
  <input class="input" type="date" name="date_to" value="<?= htmlspecialchars($dt) ?>">
  <input class="input" type="text" name="q" placeholder="ค้นหา action / entity / meta" value="<?= htmlspecialchars($q) ?>">
  <button class="btn">ค้นหา</button>
  <a class="btn outline" href="?date_from=<?= urlencode($df) ?>&date_to=<?= urlencode($dt) ?>&q=<?= urlencode($q) ?>&export=csv">Export CSV</a>
</form>

<div class="card">
  <table class="table">
    <tr>
      <th>#</th>
      <th>เวลา</th>
      <th>แอดมิน</th>
      <th>Action</th>
      <th>Entity</th>
      <th>Entity ID</th>
      <th>รายละเอียด</th>
      <th>IP</th>
    </tr>
    <?php foreach($rows as $r):
      $metaStr = '-';
      if (!empty($r['meta'])) {
        $j = json_decode($r['meta'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
          // render สั้น ๆ
          $pretty = json_encode($j, JSON_UNESCAPED_UNICODE);
          $metaStr = htmlspecialchars(mb_strimwidth($pretty, 0, 160, '…', 'UTF-8'));
        } else {
          $metaStr = htmlspecialchars(mb_strimwidth((string)$r['meta'], 0, 160, '…', 'UTF-8'));
        }
      }
    ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td class="small"><?= htmlspecialchars($r['created_at']) ?></td>
      <td><?= htmlspecialchars((string)$r['admin_name']) ?></td>
      <td><?= htmlspecialchars((string)$r['action']) ?></td>
      <td><?= htmlspecialchars((string)($r['entity_type'] ?: '-')) ?></td>
      <td><?= htmlspecialchars((string)($r['entity_id'] ?: '-')) ?></td>
      <td class="left small"><?= $metaStr ?></td>
      <td class="small"><?= htmlspecialchars((string)($r['ip'] ?: '-')) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php
$pages = max(1, (int)ceil($total / $per));
if ($pages > 1): ?>
<div class="pagination" style="margin-top:1rem;display:flex;gap:.4rem;flex-wrap:wrap">
  <?php for($i=1;$i<=$pages;$i++): $qs=$_GET; $qs['page']=$i; ?>
    <a class="btn <?= $i===$page?'':'outline' ?>" href="?<?= http_build_query($qs) ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<style>
    /* ==== FULL WIDTH เฉพาะหน้านี้ ==== */
body .container, .container { 
  max-width: 100% !important; 
  width: 100% !important;
  padding-left: 1rem;
  padding-right: 1rem;
}
</style>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
