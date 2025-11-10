<?php
// admin/pos_api.php

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
set_exception_handler(function($e){
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => false, 'error' => 'Server Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';

if (function_exists('require_admin')) {
    try {
        require_admin();
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Authentication failed.']);
        exit;
    }
}

// --- Helpers ---
function get_setting(PDO $pdo, string $key, $default = null) {
    try {
        $st = $pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false) ? $default : $v;
    } catch (Throwable $e) { return $default; }
}

function generate_next_receipt_no(PDO $pdo): ?string {
    try {
        $prefix = get_setting($pdo, 'receipt_prefix', 'RC-');
        $next_num = (int)get_setting($pdo, 'receipt_next_number', 1);
        $digits = (int)get_setting($pdo, 'receipt_digits', 5);
        $receipt_no = $prefix . str_pad((string)$next_num, $digits, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE settings SET value = ? WHERE `key` = 'receipt_next_number'")->execute([$next_num + 1]);
        return $receipt_no;
    } catch (Throwable $e) { return null; }
}

function log_admin_action(PDO $pdo, ?int $admin_id, string $action, string $entity_type, int $entity_id, array $meta = []) {
    try {
        $sql = "INSERT INTO admin_logs (admin_id, action, entity_type, entity_id, meta, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$admin_id, $action, $entity_type, $entity_id, json_encode($meta, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
    } catch (Throwable $e) { /* ignore */ }
}

// [สาเหตุของ Error] ฟังก์ชันนี้ขาดหายไปจากไฟล์ของคุณ
function get_all_products_with_stock(PDO $pdo): array {
    $productMap = [];
    $cols = "id, name, price, stock, sku, image, stock_group, is_stock_master, allow_cover_print AS allow_cover";
    $rows = $pdo->query("SELECT $cols FROM products WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    $byGroup = [];
    foreach ($rows as $r) {
        $g = trim((string)($r['stock_group'] ?? '')) ?: '__NO_GROUP__';
        $byGroup[$g][] = $r;
    }

    foreach ($byGroup as $g => $list) {
        $target = null;
        foreach ($list as $r) if (!empty($r['is_stock_master'])) { $target = $r; break; }
        if (!$target) {
            $target = $list[0];
            foreach ($list as $r) if ((int)$r['stock'] > (int)$target['stock']) $target = $r;
        }
        $eff_stock = (int)$target['stock'];
        $stock_target = (int)$target['id'];

        foreach ($list as $r) {
            $productMap[$r['id']] = [
                'id' => (int)$r['id'], 'name' => (string)$r['name'], 'price' => (float)$r['price'],
                'sku' => $r['sku'], 'image' => $r['image'],
                'stock' => (int)$r['stock'], 'eff_stock' => $eff_stock, 'stock_target' => $stock_target,
            ];
        }
    }
    return $productMap;
}

$action = $_REQUEST['action'] ?? '';
$admin_id = $_SESSION['user']['id'] ?? null;

switch ($action) {
    case 'get_products':
        $productMap = get_all_products_with_stock($pdo);
        echo json_encode(['success' => true, 'products' => array_values($productMap)]);
        break;

    case 'search_sku':
        $productMap = get_all_products_with_stock($pdo);
        $sku = trim($_GET['sku'] ?? '');
        if (empty($sku)) {
            echo json_encode(['success' => false, 'error' => 'SKU required']);
            break;
        }
        $found_product = null;
        foreach ($productMap as $product) {
            if ($product['sku'] && strcasecmp($product['sku'], $sku) == 0) {
                $found_product = $product;
                break;
            }
        }
        echo json_encode(['success' => !!$found_product, 'product' => $found_product]);
        break;

    case 'create_order':
        $productMap = get_all_products_with_stock($pdo);
        $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
        $customer_name = trim($_POST['customer_name'] ?? 'ลูกค้าหน้าร้าน (POS)');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        
        if (empty($cart_items)) {
            echo json_encode(['success' => false, 'error' => 'Cart is empty']);
            break;
        }

        try {
            $pdo->beginTransaction();
            $subtotal = 0;
            $stock_deductions = [];

            foreach ($cart_items as $item) {
                $pid = (int)$item['id'];
                $qty = (int)$item['qty'];
                if (!isset($productMap[$pid]) || $qty <= 0) continue;
                
                $product = $productMap[$pid];
                if ($product['eff_stock'] < $qty) {
                    throw new Exception("สินค้าไม่พอ: " . $product['name']);
                }
                $subtotal += $product['price'] * $qty;
                $target_id = $product['stock_target'];
                $stock_deductions[$target_id] = ($stock_deductions[$target_id] ?? 0) + $qty;
            }

            $receipt_no = generate_next_receipt_no($pdo);
            $sql = "INSERT INTO orders (created_by_admin, fullname, status, subtotal, grand_total, delivery_option, receipt_no, receipt_issued_at, created_at, updated_at) 
                    VALUES (?, ?, 'completed', ?, ?, 'pickup', ?, NOW(), NOW(), NOW())";
            $pdo->prepare($sql)->execute([$admin_id, $customer_name, $subtotal, $subtotal, $receipt_no]);
            $order_id = (int)$pdo->lastInsertId();

            $sql_items = "INSERT INTO order_items (order_id, product_id, qty, unit_price, item_name) VALUES (?, ?, ?, ?, ?)";
            $stmt_items = $pdo->prepare($sql_items);
            foreach ($cart_items as $item) {
                $product = $productMap[(int)$item['id']];
                $stmt_items->execute([$order_id, $product['id'], $item['qty'], $product['price'], $product['name']]);
            }
            
            $stmt_stock = $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?");
            foreach ($stock_deductions as $pid => $qty) {
                $stmt_stock->execute([$qty, $pid]);
            }

            $pdo->prepare("INSERT INTO payments (order_id, method, is_verified) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_verified=1")->execute([$order_id, $payment_method]);
            $pdo->commit();
            
            log_admin_action($pdo, $admin_id, 'pos.create_order', 'order', $order_id, ['total' => $subtotal]);
            echo json_encode(['success' => true, 'order_id' => $order_id, 'receipt_no' => $receipt_no]);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}