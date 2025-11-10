<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

$q = trim($_GET['q'] ?? '');
$df = $_GET['date_from'] ?? '';
$dt = $_GET['date_to'] ?? '';
$where=[]; $p=[];
if($q!==''){ $where[]='(o.id LIKE ? OR o.fullname LIKE ? OR o.tracking_no LIKE ? OR u.email LIKE ?)'; $p=["%$q%","%$q%","%$q%","%$q%"]; }
if($df!==''){ $where[]='DATE(o.created_at)>=?'; $p[]=$df; }
if($dt!==''){ $where[]='DATE(o.created_at)<=?'; $p[]=$dt; }
$w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$sql = "SELECT o.id,o.created_at,o.status,o.fullname,o.phone,o.email,o.tracking_no,o.shipped_at,o.delivered_at,
               sm.name AS ship_name, o.shipping, o.grand_total, o.address, o.province, o.zipcode
        FROM orders o
        LEFT JOIN users u ON u.id=o.user_id
        LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
        $w ORDER BY o.id DESC";
$st = $pdo->prepare($sql); $st->execute($p);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=shipping_'.date('Ymd_His').'.csv');
$out = fopen('php://output','w');
fputcsv($out, ['order_id','created_at','customer','email','phone','method','shipping_fee',
               'tracking_no','shipped_at','delivered_at','status','address','province','zipcode','grand_total']);
while($r=$st->fetch()){
  fputcsv($out, [
    $r['id'],$r['created_at'],$r['fullname'],$r['email'],$r['phone'],
    $r['ship_name'],$r['shipping'],$r['tracking_no'],$r['shipped_at'],$r['delivered_at'],$r['status'],
    $r['address'],$r['province'],$r['zipcode'],$r['grand_total']
  ]);
}
fclose($out); exit;
