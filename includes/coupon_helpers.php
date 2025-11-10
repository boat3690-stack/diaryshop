<?php
// ใช้ร่วมกับ includes/functions.php ที่มี get_setting(), format_currency() อยู่แล้ว

// อายุบิล (ชั่วโมง) อ่านจาก settings (default 48)
function order_ttl_hours(PDO $pdo): int {
    $v = (int) get_setting($pdo, 'order_ttl_hours', 48);
    return $v > 0 ? $v : 48;
}

/**
 * หาคูปองที่ใช้ได้กับยอด $subtotal
 * คืนค่า: ['row'=>แถวคูปอง, 'discount'=>จำนวนเงินส่วนลด] หรือ null ถ้าใช้ไม่ได้
 */
function find_valid_coupon(PDO $pdo, string $code, float $subtotal): ?array {
    $code = strtoupper(trim($code));
    if ($code === '' || $subtotal <= 0) return null;

    $st = $pdo->prepare("SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1");
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c) return null;

    $now = date('Y-m-d H:i:s');
    if (!empty($c['start_at']) && $c['start_at'] > $now) return null;
    if (!empty($c['end_at'])   && $c['end_at']   < $now) return null;
    if ((float)$c['min_order'] > $subtotal) return null;

    $discount = 0.0;
    if ($c['discount_type'] === 'percent') {
        $rate = max(0.0, min(100.0, (float)$c['discount_value']));
        $discount = round($subtotal * ($rate/100), 2);
    } else { // fixed
        $discount = min($subtotal, (float)$c['discount_value']);
    }
    return ['row'=>$c, 'discount'=>$discount];
}
