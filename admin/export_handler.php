<?php
// admin/export_handler.php

// ป้องกันการแสดง Error ที่หน้าเว็บ และให้ไปเก็บใน Log แทน
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// --- Setup ---
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';
if (function_exists('require_admin')) {
    require_admin();
}

// --- Helpers ---
$status_labels = [
  'unpaid' => 'ยังไม่ชำระเงิน', 'paid' => 'ชำระเงินแล้ว', 'processing' => 'กำลังเตรียมของ', 
  'shipped' => 'จัดส่งแล้ว', 'completed' => 'สำเร็จ', 'cancelled' => 'ยกเลิก'
];

function generate_csv_from_array(string $filename, array $headers, array $data_rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF"; // BOM for Excel to read Thai correctly
    fputcsv($output, $headers);
    foreach ($data_rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

function generate_csv(string $filename, array $headers, PDOStatement $stmt, callable $row_processor) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    fputcsv($output, $headers);
    
    $i = 1;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row_processor($row, $i++));
    }
    
    fclose($output);
    exit();
}


// --- ตัวเลือกรายงาน ---
$report_type = $_GET['report'] ?? 'all';
$filename = $report_type . '_export_' . date('Y-m-d') . '.csv';

// ==================================================================
// --- สร้างรายงานตามประเภทที่ร้องขอ ---
// ==================================================================
switch ($report_type) {

    case 'booking_summary':
        // 1. ดึงรายการสินค้าทั้งหมดแบบไดนามิกจากตาราง products
        $target_products = [];
        $product_stmt = $pdo->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY id ASC");
        while ($p_row = $product_stmt->fetch(PDO::FETCH_ASSOC)) {
            $target_products[(int)$p_row['id']] = $p_row['name'];
        }
        $target_product_ids = array_keys($target_products);

        if (empty($target_product_ids)) {
            generate_csv_from_array($filename, ['ข้อความ'], [['ไม่มีข้อมูลสินค้าสำหรับสร้างรายงาน']]);
            exit;
        }

        // 2. [แก้ไข] เพิ่ม "รหัสสั่งซื้อ" ใน Headers
        $headers = ['ลำดับ', 'รหัสสั่งซื้อ', 'เลขที่บิล', 'ชื่อ-สกุล', 'เบอร์โทร', 'สังกัด'];
        foreach($target_products as $name) {
            $headers[] = $name;
        }
        $headers = array_merge($headers, ['รวม (เล่ม)', 'ค่าส่ง', 'ค่าพิมพ์ปก', 'ยอดชำระ', 'สถานะ', 'วันที่จอง']);

        // 3. ดึงข้อมูลดิบทั้งหมด
        $in_clause = implode(',', array_fill(0, count($target_product_ids), '?'));
        $sql = "SELECT 
                    o.id, o.receipt_no, o.fullname, o.phone, o.note, o.grand_total, o.status, o.created_at,
                    o.shipping, o.cover_fee_total,
                    oi.product_id, oi.qty
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                WHERE oi.product_id IN ($in_clause)
                ORDER BY o.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($target_product_ids);

        // 4. ประมวลผลใน PHP เพื่อสร้างตาราง Pivot
        $report_data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $oid = $row['id'];
            if (!isset($report_data[$oid])) {
                // ดึงสังกัดให้ชัดเจน: เอาเฉพาะ "บรรทัดแรก" หลังคำว่า สังกัด
$affiliation = '-';
if (!empty($row['note']) && preg_match('/สังกัด\s*[:\-]?\s*(.+)/u', $row['note'], $matches)) {
    $affiliation = trim(preg_split("/\R/", $matches[1])[0]);
}

$report_data[$oid] = [
    'receipt_no' => ($row['receipt_no'] ?? '') ?: '-',
    'fullname'   => $row['fullname'],
    'phone'      => $row['phone'],
    'affiliation'=> $affiliation,
    'quantities' => array_fill_keys($target_product_ids, 0),
    'total_qty'  => 0,
    'shipping'   => (float)$row['shipping'],
    'cover_fee_total' => (float)$row['cover_fee_total'],
    'grand_total'=> (float)$row['grand_total'],
    'status'     => $status_labels[$row['status']] ?? $row['status'],
    'created_at' => date('Y-m-d', strtotime($row['created_at']))
];
            }
            $pid = $row['product_id'];
            if (isset($report_data[$oid]['quantities'][$pid])) {
                $report_data[$oid]['quantities'][$pid] += $row['qty'];
            }
            $report_data[$oid]['total_qty'] += $row['qty'];
        }

        // 5. [แก้ไข] จัดเรียงข้อมูลลง Array สุดท้าย (เพิ่ม $oid)
        $csv_rows = [];
        $i = 1;
        foreach ($report_data as $oid => $data) {
            $row = [$i++, $oid, $data['receipt_no'], $data['fullname'], $data['phone'], $data['affiliation']];
            foreach($target_product_ids as $pid) {
                $row[] = $data['quantities'][$pid];
            }
            $row[] = $data['total_qty'];
            $row[] = number_format($data['shipping'], 2);
            $row[] = number_format($data['cover_fee_total'], 2);
            $row[] = number_format($data['grand_total'], 2);
            $row[] = $data['status'];
            $row[] = $data['created_at'];
            $csv_rows[] = $row;
        }

        generate_csv_from_array($filename, $headers, $csv_rows);
        break;

    // --- รายงานอื่นๆ (ไม่เปลี่ยนแปลง) ---
    case 'shipping':
        $sql = "SELECT o.id, o.fullname, o.phone, o.address, o.tracking_no, GROUP_CONCAT(CONCAT(p.name, ' (', oi.qty, ')') SEPARATOR '\n') AS items
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                JOIN products p ON oi.product_id = p.id
                WHERE o.delivery_option = 'home' AND o.status IN ('paid', 'processing')
                GROUP BY o.id ORDER BY o.id ASC";
        $stmt = $pdo->query($sql);
        generate_csv($filename, 
            ['ลำดับ', 'รหัสสั่งซื้อ', 'ชื่อ-สกุล', 'เบอร์โทร', 'ที่อยู่', 'รายการสินค้า', 'Tracking'],
            $stmt,
            function($row, $i) {
                return [$i, $row['id'], $row['fullname'], $row['phone'], $row['address'], $row['items'], $row['tracking_no']];
            }
        );
        break;

    case 'pickup':
        $sql = "SELECT o.id, o.fullname, o.phone, o.pickup_code, GROUP_CONCAT(CONCAT(p.name, ' (', oi.qty, ')') SEPARATOR '\n') AS items
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                JOIN products p ON oi.product_id = p.id
                WHERE o.delivery_option = 'pickup' AND o.status IN ('paid', 'processing')
                GROUP BY o.id ORDER BY o.id ASC";
        $stmt = $pdo->query($sql);
        generate_csv($filename,
            ['ลำดับ', 'รหัสสั่งซื้อ', 'ชื่อ-สกุล', 'เบอร์โทร', 'รหัสรับของ', 'รายการสินค้า'],
            $stmt,
            function($row, $i) {
                return [$i, $row['id'], $row['fullname'], $row['phone'], $row['pickup_code'], $row['items']];
            }
        );
        break;

            case 'inventory':
        // โหลดสินค้าทั้งหมด (ทั้งที่อยู่/ไม่อยู่ในกลุ่มสต็อคร่วม)
        $products_sql = "SELECT p.id, p.name, p.stock, p.stock_group, p.is_stock_master
                         FROM products p
                         ORDER BY p.name ASC";
        $products = $pdo->query($products_sql)->fetchAll(PDO::FETCH_ASSOC);

        // ยอดจอง (ออเดอร์ค้างชำระที่ยังไม่หมดอายุ)
        $reserved_sql = "SELECT p.id AS pid, SUM(oi.qty) AS reserved_qty
                         FROM order_items oi
                         JOIN orders o   ON o.id = oi.order_id
                         JOIN products p ON p.id = oi.product_id
                         WHERE o.status = 'unpaid'
                           AND (o.expires_at IS NULL OR o.expires_at > NOW())
                         GROUP BY p.id";
        $reserved_rows = $pdo->query($reserved_sql)->fetchAll(PDO::FETCH_ASSOC);

        // ยอดที่ลูกค้าสั่งซื้อ (สถานะที่ถือว่าตัดสต็อกแล้ว/กำลังดำเนินการ)
        $sold_sql = "SELECT p.id AS pid, SUM(oi.qty) AS sold_qty
                     FROM order_items oi
                     JOIN orders o   ON o.id = oi.order_id
                     JOIN products p ON p.id = oi.product_id
                     WHERE o.status IN ('paid','processing','shipped','completed')
                     GROUP BY p.id";
        $sold_rows = $pdo->query($sold_sql)->fetchAll(PDO::FETCH_ASSOC);

        // ยอดคืนสต็อก (ออเดอร์ที่ถูกยกเลิก)
        $restocked_sql = "SELECT p.id AS pid, SUM(oi.qty) AS restocked_qty
                          FROM order_items oi
                          JOIN orders o   ON o.id = oi.order_id
                          JOIN products p ON p.id = oi.product_id
                          WHERE o.status = 'cancelled'
                          GROUP BY p.id";
        $restocked_rows = $pdo->query($restocked_sql)->fetchAll(PDO::FETCH_ASSOC);

        // ทำเป็น map: product_id => qty
        $reserved_map  = [];
        foreach ($reserved_rows as $r) { $reserved_map[(int)$r['pid']]  = (int)$r['reserved_qty']; }
        $sold_map      = [];
        foreach ($sold_rows as $r)     { $sold_map[(int)$r['pid']]      = (int)$r['sold_qty']; }
        $restocked_map = [];
        foreach ($restocked_rows as $r){ $restocked_map[(int)$r['pid']] = (int)$r['restocked_qty']; }

        $inventory_rows   = [];
        $processed_groups = [];

        foreach ($products as $p) {
            $group = trim((string)$p['stock_group']);

            if ($group !== '') {
                // กลุ่มสต็อคร่วม: สรุปครั้งเดียวต่อกลุ่ม
                if (in_array($group, $processed_groups, true)) { continue; }

                // หา master ของกลุ่ม
                $master = null;
                foreach ($products as $pp) {
                    if ($pp['stock_group'] === $group && (int)$pp['is_stock_master'] === 1) {
                        $master = $pp; break;
                    }
                }
                if (!$master) {
                    // ถ้าไม่มี master ชัดเจน ใช้ตัวที่ stock มากที่สุดแทน
                    $maxStock = -1;
                    foreach ($products as $pp) {
                        if ($pp['stock_group'] === $group && (int)$pp['stock'] > $maxStock) {
                            $maxStock = (int)$pp['stock']; $master = $pp;
                        }
                    }
                }

                $total_stock   = (int)$master['stock']; // สต็อกปัจจุบันของกลุ่ม
                $reserved_qty  = 0;
                $sold_qty      = 0;
                $restocked_qty = 0;

                // รวมยอดของสมาชิกในกลุ่มทั้งหมด
                foreach ($products as $pp) {
                    if ($pp['stock_group'] === $group) {
                        $pid = (int)$pp['id'];
                        $reserved_qty  += ($reserved_map[$pid]  ?? 0);
                        $sold_qty      += ($sold_map[$pid]      ?? 0);
                        $restocked_qty += ($restocked_map[$pid] ?? 0);
                    }
                }

                // สต็อกตั้งแต่เริ่ม (ประมาณจากข้อมูลที่มี)
                $initial_all = $total_stock + $reserved_qty + $sold_qty - $restocked_qty;
                // คงเหลือขายได้ = สต็อกปัจจุบัน − ยอดจอง
                $available   = max(0, $total_stock - $reserved_qty);

                $inventory_rows[] = [
                    $group,
                    $initial_all,
                    $reserved_qty,
                    $sold_qty,
                    $restocked_qty,
                    $available
                ];
                $processed_groups[] = $group;

            } else {
                // สินค้าที่ไม่อยู่ในกลุ่ม
                $pid            = (int)$p['id'];
                $total_stock    = (int)$p['stock']; // สต็อกปัจจุบัน
                $reserved_qty   = (int)($reserved_map[$pid]  ?? 0);
                $sold_qty       = (int)($sold_map[$pid]      ?? 0);
                $restocked_qty  = (int)($restocked_map[$pid] ?? 0);

                $initial_all = $total_stock + $reserved_qty + $sold_qty - $restocked_qty;
                $available   = max(0, $total_stock - $reserved_qty);

                $inventory_rows[] = [
                    $p['name'],
                    $initial_all,
                    $reserved_qty,
                    $sold_qty,
                    $restocked_qty,
                    $available
                ];
            }
        }

        // ส่งออก CSV
        generate_csv_from_array(
            $filename,
            ['รายการ', 'สต็อกตั้งแต่เริ่ม', 'ยอดจอง', 'ยอดที่ลูกค้าสั่งซื้อ', 'ยอดคืนสต็อก', 'ยอดคงเหลือ (ขายได้)'],
            $inventory_rows
        );
        break;

    case 'paid':
    case 'unpaid':
    case 'cancelled':
        $sql = "SELECT o.id, o.created_at, o.fullname, o.phone, o.grand_total, o.status, o.note, 
                       GROUP_CONCAT(CONCAT(p.name, ' (', oi.qty, ')') SEPARATOR '\n') AS items, SUM(oi.qty) AS total_qty
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE o.status = ?
                GROUP BY o.id ORDER BY o.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$report_type]);
        generate_csv($filename,
            ['ลำดับ', 'รหัสสั่งซื้อ', 'วันที่', 'ชื่อ-สกุล', 'รายการสินค้า', 'จำนวนรวม (ชิ้น)', 'ยอดรวม (บาท)', 'หมายเหตุ'],
            $stmt,
            function($row, $i) {
                return [
                    $i, $row['id'], date('Y-m-d H:i', strtotime($row['created_at'])),
                    $row['fullname'], $row['items'], $row['total_qty'], 
                    number_format($row['grand_total'], 2), $row['note']
                ];
            }
        );
        break;
}

// ถ้าไม่มี report type ที่ตรง, กลับไปหน้า admin orders
header('Location: orders.php');
exit();