<?php


if ($act === 'update_line') {
foreach (($_POST['line'] ?? []) as $lid => $in) {
if (!isset($_SESSION['cart']['lines'][$lid])) continue;
$L =& $_SESSION['cart']['lines'][$lid];


// qty ปกติ
$L['qty'] = max(1, (int)($in['qty'] ?? $L['qty'] ?? 1));


// ปกต่อแถว
$allow = (int)($L['allow_cover'] ?? 1);
$print = $allow ? (!empty($in['print_cover']) ? 1 : 0) : 0;
$cq = $print ? max(0, (int)($in['cover_qty'] ?? $L['qty'])) : 0;


$L['print_cover'] = $print;
$L['cover_qty'] = $cq;
$L['cover_cost'] = $print ? compute_cover_cost($pdo, $cq) : 0.0;
}
redirect('cart.php');
}
}


function cart_cover_boot(): void {
if (empty($_SESSION['cart'])) $_SESSION['cart'] = ['lines'=>[]];
foreach ($_SESSION['cart']['lines'] as $k => $row) {
if (empty($row['line_id'])) {
$_SESSION['cart']['lines'][$k]['line_id'] = bin2hex(random_bytes(6));
}
$_SESSION['cart']['lines'][$k] += [
'print_cover'=>0,
'cover_qty'=>0,
'cover_cost'=>0.0,
'allow_cover'=> (int)($row['allow_cover'] ?? 1), // default allow
];
}
}


function cart_cover_line_total(array $row): float {
$u = (float)($row['unit_price'] ?? 0);
$q = (int)($row['qty'] ?? 1);
return $u * $q + (float)($row['cover_cost'] ?? 0);
}


function cart_cover_subtotal(): float {
$sum = 0.0;
foreach (($_SESSION['cart']['lines'] ?? []) as $row) {
$sum += cart_cover_line_total($row);
}
return round($sum,2);
}
}


// boot + handle
cart_cover_boot();
cart_cover_handle_post($pdo);
?>