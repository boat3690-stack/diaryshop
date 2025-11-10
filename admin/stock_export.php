<?php
// /admin/stock_export.php
require_once __DIR__ . '/../config/config.php';
require_admin();

// ตัวกรองเล็กน้อย (เลือกได้)
$q       = trim($_GET['q'] ?? '');
$statusF = $_GET['status'] ?? ''; // '', 'active', 'inactive'

$where = []; $p = [];
if ($q !== '') {
  $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
  $p[] = "%$q%"; $p[] = "%$q%";
}
if ($statusF === 'active')   $where[] = 'p.is_active=1';
if ($statusF === 'inactive') $where[] = 'p.is_active=0';
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT p.id, p.sku, p.name, p.stock, p.price, p.is_active
                       FROM products p
                       $whereSql
                       ORDER BY p.id ASC");
$stmt->execute($p);

// header CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=stock_'.date('Ymd_His').'.csv');

// (ใส่ BOM เพื่อกันภาษาไทยเพี้ยนใน Excel)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
// ชื่อคอลัมน์
fputcsv($out, ['id','sku','name','stock','price','is_active']);

// แถวข้อมูล
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  fputcsv($out, [
    $r['id'],
    $r['sku'],
    $r['name'],
    (int)$r['stock'],
    (float)$r['price'],
    (int)$r['is_active'],
  ]);
}
fclose($out);
exit;
