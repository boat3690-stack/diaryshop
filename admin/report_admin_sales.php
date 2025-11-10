<?php
// /admin/report_admin_sales.php — robust, self-diagnosing (no HTTP 500)
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';

// ===== DEV DEBUG (ลบสองบรรทัดนี้ทิ้งในโปรดักชัน) =====
ini_set('display_errors','1');
error_reporting(E_ALL);

// ===== guard & perms =====
require_admin();
require_perm('reports.view');

// ===== helpers =====
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function show_cols(PDO $pdo, string $table, string $like = null): array {
  try {
    if ($like !== null) {
      $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
      $st->execute([$like]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $r) $cols[$r['Field']] = true;
    return $cols;
  } catch (Throwable $e) { return []; }
}
function orders_has_col(PDO $pdo, string $col): bool {
  return !empty(show_cols($pdo, 'orders', $col));
}
function pick_admin_cols(PDO $pdo): array {
  // ใส่ชื่อคอลัมน์ที่พบได้บ่อย เรียงลำดับความสำคัญ
  $cand = ['owner_admin_id','assigned_admin_id','created_admin_id','created_by_admin_id','created_by'];
  $ok = [];
  foreach ($cand as $c) if (orders_has_col($pdo, $c)) $ok[] = $c;
  return $ok; // อาจเป็น [] ถ้าไม่มีเลย
}
function force_array($v): array { return is_array($v) ? $v : (($v===null||$v==='')?[]:[$v]); }

// ===== inputs =====
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$allowedStatuses = ['unpaid','paid','processing','shipped','completed','cancelled'];
$statusesIn = force_array($_GET['statuses'] ?? ['paid','processing','shipped','completed']);
$statuses   = array_values(array_intersect($allowedStatuses, $statusesIn));
if (!$statuses) $statuses = ['paid','processing','shipped','completed'];

// ดึงรายชื่อแอดมิน (เผื่อ get_admins ไม่มี)
$admins = [];
try {
  if (function_exists('get_admins')) {
    $admins = get_admins($pdo);
  } else {
    $q = $pdo->query("SELECT id,name,email FROM users WHERE role='admin' ORDER BY name");
    $admins = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
  }
} catch (Throwable $e) { $admins = []; }
$adminNames = [];
foreach ($admins as $a) $adminNames[(int)$a['id']] = $a['name'];

// ===== สร้าง expression ของ “admin ผู้รับเครดิต” แบบยืดหยุ่น =====
$adminCols = pick_admin_cols($pdo);            // อาจว่าง []
$adminExpr = 'NULL';                           // ถ้าไม่มีคอลัมน์เลย -> รวมเป็น “ไม่ระบุ”
if ($adminCols) {
  // ใช้ COALESCE จากซ้ายไปขวา (เอาค่าที่ไม่ใช่ NULL ตัวแรก)
  $quoted = array_map(fn($c)=>"`o`.`$c`", $adminCols);
  $adminExpr = 'COALESCE('.implode(',', $quoted).')';
}

// ===== สร้างช่วงเวลาแบบ [from 00:00:00, to+1day 00:00:00) =====
$start = date('Y-m-d 00:00:00', strtotime($from));
$end   = date('Y-m-d 00:00:00', strtotime($to.' +1 day'));

// ===== ตรวจสคีมาตาราง orders ที่จำเป็น =====
$ordCols = show_cols($pdo, 'orders');
$need = ['created_at','status','grand_total'];
foreach ($need as $c) {
  if (empty($ordCols[$c])) {
    include __DIR__ . '/../partials/header.php';
    echo '<div class="alert error">ตาราง <code>orders</code> ไม่มีคอลัมน์ที่จำเป็น <code>'.h($c).'</code> ทำให้คำนวณรายงานไม่ได้</div>';
    echo '<div class="small">มีคอลัมน์อยู่จริง: <code>'.h(implode(', ', array_keys($ordCols))).'</code></div>';
    include __DIR__ . '/../partials/footer.php';
    exit;
  }
}

// ===== query (ห่อ try/catch เพื่อไม่ให้ 500) =====
$where = "o.created_at >= ? AND o.created_at < ?";
$params = [$start, $end];

$ph = implode(',', array_fill(0, count($statuses), '?'));
$where .= " AND o.status IN ($ph)";
$params = array_merge($params, $statuses);

// ถ้าไม่มีคอลัมน์ผู้รับเครดิต -> group by admin_id = NULL ได้ 1 แถว (หมวด “ไม่ระบุ”)
$sql = "SELECT $adminExpr AS admin_id,
               COUNT(*) AS orders,
               COALESCE(SUM(o.grand_total),0) AS revenue
        FROM orders o
        WHERE $where
        GROUP BY admin_id
        ORDER BY revenue DESC";

try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $data = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // โหมดดีบัก: แสดงข้อผิดพลาดแทน 500
  include __DIR__ . '/../partials/header.php';
  echo '<div class="alert error">เกิดข้อผิดพลาดที่คิวรีรายงาน:</div>';
  echo '<pre class="small" style="white-space:pre-wrap;background:#111;color:#ddd;padding:8px;border-radius:8px">';
  echo h($e->getMessage())."\n\nSQL:\n".h($sql)."\n\nParams:\n".h(json_encode($params, JSON_UNESCAPED_UNICODE));
  echo '</pre>';
  include __DIR__ . '/../partials/footer.php';
  exit;
}

// ===== CSV download =====
if (isset($_GET['download'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=admin_sales_'.$from.'_'.$to.'.csv');
  $out=fopen('php://output','w');
  fputcsv($out,['admin_id','admin_name','orders','revenue']);
  foreach($data as $r){
    $aid = (int)($r['admin_id'] ?? 0);
    $label = $aid ? ($adminNames[$aid] ?? ('#'.$aid)) : '— ไม่ระบุ —';
    fputcsv($out, [$aid, $label, (int)$r['orders'], (float)$r['revenue']]);
  }
  exit;
}

// ===== view =====
include __DIR__ . '/../partials/header.php';
?>
<style>
body .container, .container { max-width: 100% !important; width: 100% !important; padding: 1rem; }
.card{border:1px solid #e5e7eb;border-radius:12px;background:#fff;padding:1rem;margin:.6rem 0}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:.5rem;border-bottom:1px solid #eee;text-align:left}
.input{width:auto;min-width:180px}
.small{font-size:.9rem;opacity:.85}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .9rem;border:1px solid #111;border-radius:10px;background:#fff;cursor:pointer}
.btn.outline{background:#fff}
.filterbar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
</style>

<h2>ยอดขายต่อแอดมิน</h2>
<form method="get" class="filterbar">
  <input class="input" type="date" name="from" value="<?= h($from) ?>">
  <input class="input" type="date" name="to"   value="<?= h($to) ?>">
  <label class="small">สถานะนับยอด:</label>
  <?php foreach($allowedStatuses as $s): ?>
    <label class="small">
      <input type="checkbox" name="statuses[]" value="<?= h($s) ?>" <?= in_array($s,$statuses,true)?'checked':'' ?>> <?= h($s) ?>
    </label>
  <?php endforeach; ?>
  <button class="btn">คำนวณ</button>
  <a class="btn outline" href="report_admin_sales.php?<?= h(http_build_query(array_merge($_GET,['download'=>1]))) ?>">ดาวน์โหลด CSV</a>
</form>

<div class="card">
  <div class="small" style="margin-bottom:.4rem">
    เครดิตยอดขายพิจารณาจากคอลัมน์: 
    <code><?= h($adminCols ? implode(' → ', $adminCols) : 'ไม่มีคอลัมน์ที่เกี่ยวข้อง — จัดเป็น “ไม่ระบุ”') ?></code>
  </div>
  <table class="table">
    <tr><th>แอดมิน</th><th>จำนวนบิล</th><th>ยอดรวม (฿)</th></tr>
    <?php
      $sumOrders=0; $sumRev=0.0;
      foreach($data as $r):
        $aid = (int)($r['admin_id'] ?? 0);
        $label = $aid ? ($adminNames[$aid] ?? ('#'.$aid)) : '— ไม่ระบุ —';
        $ord = (int)$r['orders'];
        $rev = (float)$r['revenue'];
        $sumOrders += $ord; $sumRev += $rev;
    ?>
      <tr>
        <td><?= h($label) ?></td>
        <td><?= $ord ?></td>
        <td><?= number_format($rev,2) ?></td>
      </tr>
    <?php endforeach; ?>
    <tr>
      <td><strong>รวม</strong></td>
      <td><strong><?= (int)$sumOrders ?></strong></td>
      <td><strong><?= number_format($sumRev,2) ?></strong></td>
    </tr>
  </table>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
