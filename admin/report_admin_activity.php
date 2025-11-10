<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';

require_admin();
require_perm('reports.view');

ini_set('display_errors', '1');
error_reporting(E_ALL);

/* สร้างตารางถ้ายังไม่มี — พยายามใช้ JSON ถ้าไม่ผ่านจะ fallback เป็น TEXT */
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT NULL,
    meta JSON NULL,
    ip VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_action (action),
    INDEX idx_admin_admin (admin_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  // fallback: TEXT
  $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT NULL,
    meta TEXT NULL,
    ip VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_action (action),
    INDEX idx_admin_admin (admin_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$admins = get_admins($pdo);

$aid    = (int)($_GET['admin_id'] ?? 0);
$action = trim($_GET['action'] ?? '');
$from   = $_GET['from'] ?? date('Y-m-01');
$to     = $_GET['to']   ?? date('Y-m-d');

/* ====== ใช้ l.created_at ให้ชัด ====== */
$where=[]; $p=[];
$where[] = "DATE(l.created_at) BETWEEN ? AND ?"; $p[]=$from; $p[]=$to;
if ($aid>0){ $where[]="l.admin_id=?"; $p[]=$aid; }
if ($action!==''){ $where[]="l.action=?"; $p[]=$action; }
$w = 'WHERE '.implode(' AND ',$where);


/* ====== Export ====== */
try {
  if (isset($_GET['download'])) {
    $st=$pdo->prepare("SELECT l.*, u.name AS admin_name
                       FROM admin_logs l
                       LEFT JOIN users u ON u.id = l.admin_id
                       $w
                       ORDER BY l.created_at DESC");
    $st->execute($p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=admin_activity_'.$from.'_'.$to.'.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['created_at','admin_id','admin_name','action','entity_type','entity_id','meta','ip','user_agent']);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      fputcsv($out, [
        $r['created_at'],$r['admin_id'],$r['admin_name'],
        $r['action'],$r['entity_type'],$r['entity_id'],$r['meta'],$r['ip'],$r['user_agent']
      ]);
    }
    exit;
  }

/* ====== หน้าแสดงผล ====== */
  $rows = $pdo->prepare("SELECT l.*, u.name AS admin_name
                         FROM admin_logs l
                         LEFT JOIN users u ON u.id = l.admin_id
                         $w
                         ORDER BY l.created_at DESC
                         LIMIT 500");
  $rows->execute($p);
} catch (Throwable $e) {
  include __DIR__ . '/../partials/header.php';
  echo '<div class="alert error">Error: '.htmlspecialchars($e->getMessage()).'</div>';
  include __DIR__ . '/../partials/footer.php';
  exit;
}

include __DIR__ . '/../partials/header.php';
?>
<h2>รายงานกิจกรรมแอดมิน</h2>

<form method="get" class="filterbar" style="display:flex;gap:.5rem;flex-wrap:wrap">
  <input class="input" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
  <input class="input" type="date" name="to"   value="<?= htmlspecialchars($to) ?>">
  <select class="input" name="admin_id">
    <option value="0">ทุกแอดมิน</option>
    <?php foreach($admins as $a): ?>
      <option value="<?= (int)$a['id'] ?>" <?= $aid===(int)$a['id']?'selected':'' ?>>
        <?= htmlspecialchars($a['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <input class="input" name="action" placeholder="เช่น orders.update" value="<?= htmlspecialchars($action) ?>">
  <button class="btn">ค้นหา</button>
  <a class="btn outline" href="report_admin_activity.php?<?= http_build_query(array_merge($_GET,['download'=>1])) ?>">
    ดาวน์โหลด CSV
  </a>
</form>

<div class="card">
  <table class="table">
    <tr><th>เวลา</th><th>แอดมิน</th><th>การกระทำ</th><th>Target</th><th>รายละเอียด</th><th>IP</th></tr>
    <?php foreach($rows as $r): ?>
      <tr>
        <td class="small"><?= htmlspecialchars($r['created_at']) ?></td>
        <td><?= htmlspecialchars($r['admin_name'] ?: '#'.$r['admin_id']) ?></td>
        <td><code><?= htmlspecialchars($r['action']) ?></code></td>
        <td><?= htmlspecialchars($r['entity_type'].' #'.(int)$r['entity_id']) ?></td>
        <td class="small"><?= htmlspecialchars($r['meta']) ?></td>
        <td class="small"><?= htmlspecialchars($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
