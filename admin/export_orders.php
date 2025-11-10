<?php
// บังคับให้ PHP ไม่แสดง Error ที่หน้าเว็บ แต่ไปเก็บใน Log แทน
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/config.php';
// สมมติว่ามีไฟล์สำหรับ Authentication
require_once __DIR__ . '/../includes/authz.php';

// ตรวจสอบสิทธิ์แอดมิน
if (function_exists('require_admin')) {
    require_admin();
}

// รับค่าตัวกรองจาก URL
$status = $_GET['status'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// --- สร้างเงื่อนไขสำหรับ Query ---
$params = [];
$where_clauses = ["1=1"];

if ($status !== '' && $status !== 'all') {
    $where_clauses[] = "o.status = ?";
    $params[] = $status;
}
if ($start_date !== '') {
    $where_clauses[] = "o.created_at >= ?";
    $params[] = $start_date . ' 00:00:00';
}
if ($end_date !== '') {
    $where_clauses[] = "o.created_at <= ?";
    $params[] = $end_date . ' 23:59:59';
}

$where_sql = implode(' AND ', $where_clauses);

// --- เตรียม Query หลัก ---
// ใช้ GROUP_CONCAT เพื่อรวมรายการสินค้าของแต่ละออเดอร์มาไว้ในช่องเดียว
$sql = "
    SELECT
        o.id,
        o.created_at,
        o.fullname,
        o.phone,
        o.grand_total,
        o.status,
        o.note,
        o.tracking_no,
        o.delivery_option,
        GROUP_CONCAT(CONCAT(p.name, ' (', oi.qty, ' ชิ้น)') SEPARATOR '\n') AS items_list,
        SUM(oi.qty) AS total_qty
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE $where_sql
    GROUP BY o.id
    ORDER BY o.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// --- ส่วนของการสร้างไฟล์ CSV ---
$filename = "orders_export_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// เปิด stream เพื่อเขียนไฟล์ไปยัง output ของเบราว์เซอร์โดยตรง
$output = fopen('php://output', 'w');

// เพิ่ม BOM (Byte Order Mark) เพื่อให้ Excel เปิดไฟล์ภาษาไทยได้ถูกต้อง
echo "\xEF\xBB\xBF";

// เขียนหัวข้อคอลัมน์
$headers = [
    'ลำดับ', 'รหัสสั่งซื้อ', 'วันที่', 'ชื่อ-สกุล', 'เบอร์โทร', 
    'รายการสินค้า', 'จำนวนรวม (ชิ้น)', 'ยอดรวม (บาท)', 'สถานะ', 
    'รูปแบบ', 'เลขพัสดุ', 'หมายเหตุ'
];
fputcsv($output, $headers);

// เขียนข้อมูลลงไฟล์ทีละแถว
$i = 1;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status_labels = [
      'unpaid' => 'ยังไม่ชำระเงิน', 'paid' => 'ชำระเงินแล้ว',
      'processing' => 'กำลังเตรียมของ', 'shipped' => 'จัดส่งแล้ว',
      'completed' => 'สำเร็จ', 'cancelled' => 'ยกเลิก'
    ];
    $status_th = $status_labels[$row['status']] ?? $row['status'];
    $delivery_th = ($row['delivery_option'] === 'pickup') ? 'รับเองที่ร้าน' : 'จัดส่ง';

    $data_row = [
        $i++,
        $row['id'],
        date('Y-m-d H:i', strtotime($row['created_at'])),
        $row['fullname'],
        $row['phone'],
        $row['items_list'],
        $row['total_qty'],
        number_format($row['grand_total'], 2),
        $status_th,
        $delivery_th,
        $row['tracking_no'],
        $row['note']
    ];
    fputcsv($output, $data_row);
}

fclose($output);
exit();