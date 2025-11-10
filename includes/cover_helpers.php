<?php
// includes/cover_helpers.php
require_once __DIR__.'/functions.php';


function cover_price_config(PDO $pdo): array {
$open_block = (float) get_setting($pdo,'cover_open_block',500); // ค่าเปิดบล็อก (รวม first_n)
$first_n = (int) get_setting($pdo,'cover_first_n',50); // จำนวนเล่มแรกที่รวมในบล็อก
$over_unit = (float) get_setting($pdo,'cover_over_unit',2); // เกินจาก first_n คิดเพิ่ม/เล่ม
return compact('open_block','first_n','over_unit');
}


function compute_cover_cost(PDO $pdo, int $qty): float {
$cfg = cover_price_config($pdo);
$qty = max(0, (int)$qty);
if ($qty <= 0) return 0.0;
$cost = (float)$cfg['open_block'];
if ($qty > $cfg['first_n']) {
$cost += ($qty - $cfg['first_n']) * (float)$cfg['over_unit'];
}
return round($cost, 2);
}


function format_cover_hint(PDO $pdo): string {
$c = cover_price_config($pdo);
return sprintf('เปิดบล็อก %s บาท (รวม %s เล่มแรก) เกินคิด %s บาท/เล่ม',
number_format($c['open_block'],0),
number_format($c['first_n'],0),
number_format($c['over_unit'],0));
}


// helper ปลอดภัยเวลา echo
if (!function_exists('e')) {
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>