<?php
// admin/_stock_hooks.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * เรียกผลข้างเคียงเรื่องสต๊อกให้ถูกจังหวะตามการเปลี่ยนสถานะออเดอร์
 */
function apply_stock_side_effects(PDO $pdo, int $orderId, string $oldStatus, string $newStatus): void
{
    if ($newStatus === $oldStatus) return;

    // 1) เข้าสู่ขั้นตอนจัดของ/ส่งของครั้งแรก -> "ตัดสต๊อก"
    $toDeductStages = ['processing','shipped'];
    $alreadyDeductStages = ['processing','shipped','completed'];
    if (in_array($newStatus, $toDeductStages, true) && !in_array($oldStatus, $alreadyDeductStages, true)) {
        deduct_stock_for_order($pdo, $orderId);
    }

    // 2) ถูกยกเลิก -> "คืนสต๊อก"
    if ($newStatus === 'cancelled') {
        // ถ้าเคยตัดจริง (stock_locked=1) ให้คืนเต็ม,
        // ถ้าไม่แน่ใจ/ไม่เคยตัด ให้ใช้ reconcile เพื่อคืนเท่าที่เคยตัดจริงเท่านั้น
        $locked = 0;
        if (has_column($pdo, 'orders', 'stock_locked')) {
            $st = $pdo->prepare('SELECT stock_locked FROM orders WHERE id=?');
            $st->execute([$orderId]);
            $locked = (int)($st->fetchColumn() ?? 0);
        }
        if ($locked) {
            return_stock_for_order($pdo, $orderId);
        } else {
            // ปลอดภัยกว่า: คืนเท่าที่ตัดจริง (กันโอเวอร์รีเทิร์น)
            reconcile_stock_for_order($pdo, $orderId);
        }
    }
}
