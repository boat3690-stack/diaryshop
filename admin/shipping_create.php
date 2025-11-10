<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/shipping.php';
require_admin();

$id=(int)($_GET['id'] ?? 0);
$r = create_shipment_for_order($pdo, $id);
if(!$r['ok']) { flash('error','สร้างไม่สำเร็จ: '.($r['err'] ?? '')); }
else { flash('success','สร้างเลขพัสดุแล้ว: '.($r['tracking_no'] ?? '')); }
redirect('orders.php');
