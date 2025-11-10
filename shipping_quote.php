<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/shipping.php';
header('Content-Type: application/json; charset=utf-8');

$province = trim($_GET['province'] ?? '');
$postcode = trim($_GET['postcode'] ?? '');
$items    = json_decode($_GET['items'] ?? '[]', true);
/* items รูปแบบ: [ [product_id, qty], ... ] */

if ($province==='' || !$items) { echo json_encode(['ok'=>0,'err'=>'bad_params']); exit; }

$quotes = shipping_quotes($pdo, $province, $postcode, $items);
echo json_encode(['ok'=>1,'quotes'=>$quotes]);
