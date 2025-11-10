<?php
require_once __DIR__ . '/../config/config.php';
require_admin();
check_low_stock($pdo);

$u = $pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'] ?? 0;
$p = $pdo->query('SELECT COUNT(*) c FROM products')->fetch()['c'] ?? 0;
$o = $pdo->query('SELECT COUNT(*) c FROM orders')->fetch()['c'] ?? 0;
$sales = $pdo->query('SELECT COALESCE(SUM(grand_total),0) s FROM orders WHERE status IN ("paid","processing","shipped","completed")')->fetch()['s'] ?? 0;

// Get recent orders
$recent_orders = $pdo->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 5')->fetchAll();

// Get today's stats
$today = date('Y-m-d');
$today_orders = $pdo->query("SELECT COUNT(*) c FROM orders WHERE DATE(created_at) = '$today'")->fetch()['c'] ?? 0;
$today_sales = $pdo->query("SELECT COALESCE(SUM(grand_total),0) s FROM orders WHERE DATE(created_at) = '$today' AND status IN ('paid','processing','shipped','completed')")->fetch()['s'] ?? 0;
?>
<?php include __DIR__ . '/../partials/header.php'; ?>

<style>
/* ==== FULL WIDTH เฉพาะหน้านี้ ==== */
body .container, .container { 
  max-width: 100% !important; 
  width: 100% !important;
  padding-left: 1rem;
  padding-right: 1rem;
}

/* ตารางให้กว้างเต็ม + อ่านง่ายขึ้น */
.table{ width:100%; table-layout:auto; }
.table th,.table td{ white-space:nowrap; }
.table td.left{ white-space:normal; }

/* แถบค้นหาให้ยืด/พับบรรทัดได้เมื่อจอเล็ก */
.filterbar{ flex-wrap: wrap; }
.filterbar .input{ min-width: 180px; }

/* สรุปตัวเลขอยู่ชิดซ้าย-ขวาได้ดีขึ้นบนจอกว้าง/แคบ */
.summary{ display:flex; gap:.75rem; flex-wrap:wrap; margin:1rem 0; }
.summary .card{ padding:.75rem 1rem; border-radius:.8rem; }
.summary .num{ font-weight:700; font-size:1.1rem; }
.small{ font-size:.85rem; opacity:.85; }

/* ถ้าตารางมีคอลัมน์เยอะ ให้เลื่อนในแนวนอนได้ */
.table-wrap{ overflow-x:auto; }

/* Dashboard Styles */
.dashboard-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 15px;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.dashboard-header h2 {
    margin: 0;
    font-size: 2rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 15px;
}

.dashboard-header p {
    margin: 0.5rem 0 0;
    opacity: 0.9;
}

.stats-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    border: 1px solid #f0f0f0;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, #667eea, #764ba2);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
}

.stat-card.users::before { background: linear-gradient(180deg, #667eea, #764ba2); }
.stat-card.products::before { background: linear-gradient(180deg, #f093fb, #f5576c); }
.stat-card.orders::before { background: linear-gradient(180deg, #4facfe, #00f2fe); }
.stat-card.sales::before { background: linear-gradient(180deg, #43e97b, #38f9d7); }

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-bottom: 1rem;
}

.stat-card.users .stat-icon { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
.stat-card.products .stat-icon { background: linear-gradient(135deg, #f093fb, #f5576c); color: white; }
.stat-card.orders .stat-icon { background: linear-gradient(135deg, #4facfe, #00f2fe); color: white; }
.stat-card.sales .stat-icon { background: linear-gradient(135deg, #43e97b, #38f9d7); color: white; }

.stat-label {
    color: #666;
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: #333;
}

.stat-trend {
    font-size: 0.85rem;
    color: #28a745;
    margin-top: 0.5rem;
}

.quick-actions {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.quick-actions h3 {
    margin-top: 0;
    color: #333;
    font-size: 1.3rem;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}

.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 1rem;
}

.action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1rem;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    color: #333;
    text-decoration: none;
    border-radius: 10px;
    transition: all 0.3s ease;
    text-align: center;
    font-size: 0.9rem;
    border: 1px solid transparent;
}

.action-btn:hover {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.action-btn i {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.recent-activity {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}

.recent-activity h3 {
    margin-top: 0;
    color: #333;
    font-size: 1.3rem;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}

.activity-list {
    max-height: 300px;
    overflow-y: auto;
}

.activity-item {
    padding: 0.75rem;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.activity-item:last-child {
    border-bottom: none;
}

.order-id {
    font-weight: 600;
    color: #667eea;
}

.order-status {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-pending { background: #fef3c7; color: #92400e; }
.status-paid { background: #d1fae5; color: #065f46; }
.status-processing { background: #dbeafe; color: #1e40af; }
.status-shipped { background: #e9d5ff; color: #6b21a8; }
.status-completed { background: #cffafe; color: #0e7490; }

.order-amount {
    font-weight: 600;
    color: #333;
}

/* Icon imports */
.icon-users::before { content: "👥"; }
.icon-products::before { content: "📦"; }
.icon-orders::before { content: "🛒"; }
.icon-sales::before { content: "💰"; }
.icon-dashboard::before { content: "📊"; }

/* Responsive */
@media (max-width: 768px) {
    .stats-container {
        grid-template-columns: 1fr;
    }
    
    .action-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

</style>

<!-- Dashboard Header -->
<div class="dashboard-header">
    <h2><span class="icon-dashboard"></span> แผงควบคุมแอดมิน</h2>
    <p>ยินดีต้อนรับกลับ! วันนี้คุณมี <?= $today_orders ?> คำสั่งซื้อใหม่ มูลค่า ฿<?= format_currency($today_sales) ?></p>
</div>

<!-- Stats Cards -->
<div class="stats-container">
    <div class="stat-card users">
        <div class="stat-icon">
            <span class="icon-users"></span>
        </div>
        <div class="stat-label">ผู้ใช้ทั้งหมด</div>
        <div class="stat-value"><?= number_format($u) ?></div>
        <div class="stat-trend">↑ เพิ่มขึ้น 12% จากเดือนก่อน</div>
    </div>
    
    <div class="stat-card products">
        <div class="stat-icon">
            <span class="icon-products"></span>
        </div>
        <div class="stat-label">สินค้าทั้งหมด</div>
        <div class="stat-value"><?= number_format($p) ?></div>
        <div class="stat-trend">→ คงที่</div>
    </div>
    
    <div class="stat-card orders">
        <div class="stat-icon">
            <span class="icon-orders"></span>
        </div>
        <div class="stat-label">คำสั่งซื้อทั้งหมด</div>
        <div class="stat-value"><?= number_format($o) ?></div>
        <div class="stat-trend">↑ เพิ่มขึ้น 8% จากเดือนก่อน</div>
    </div>
    
    <div class="stat-card sales">
        <div class="stat-icon">
            <span class="icon-sales"></span>
        </div>
        <div class="stat-label">ยอดขายสะสม</div>
        <div class="stat-value">฿<?= format_currency($sales) ?></div>
        <div class="stat-trend">↑ เพิ่มขึ้น 15% จากเดือนก่อน</div>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions">
    <h3>🚀 เมนูด่วน</h3>
    <div class="action-grid">
        <a class="action-btn" href="add_product.php">
            <i>✚📦</i>
            เพิ่มสินค้า
        </a>
        <a class="action-btn" href="products.php">
            <i>📦</i>
            จัดการสินค้า
        </a>
        <a class="action-btn" href="orders.php">
            <i>🛒</i>
            จัดการออเดอร์
        </a>
        <a class="action-btn" href="coupons.php">
            <i>🎟️</i>
            จัดการคูปอง
        </a>
        <a class="action-btn" href="customers.php">
            <i>👥</i>
            สมาชิก
        </a>
        <a class="action-btn" href="quick_order.php">
            <i>💳</i>
            ระบบขายหน้าร้าน
        </a>
        <a class="action-btn" href="welcome_settings.php">
            <i>📈</i>
            ตั้งค่าข้อความต้อนรับ
        </a>
         <a class="action-btn" href="posts.php">
            <i>🗯️</i>
            ตั้งค่า Blog
        </a>
        <a class="action-btn" href="settings.php">
            <i>⚙️</i>
            ตั้งค่าระบบ
        </a>
        <a class="action-btn" href="chats.php">
            <i>💬</i>
            ข้อความแชท
        </a>
    </div>
</div>

<!-- Recent Orders -->
<div class="recent-activity">
    <h3>📋 คำสั่งซื้อล่าสุด</h3>
<?php
// ===== ดึงคำสั่งซื้อล่าสุด (พยายามดึงชื่อผู้ซื้อจากหลายแหล่ง) =====
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
if (!function_exists('status_th')) {
  function status_th($s){
    $map = [
      'unpaid' => 'ยังไม่ชำระเงิน',
      'paid' => 'ชำระเงินแล้ว',
      'processing' => 'กำลังเตรียมสินค้า',
      'shipped' => 'กำลังจัดส่ง',
      'completed' => 'สำเร็จ',
      'cancelled' => 'ยกเลิก',
    ];
    $s = strtolower((string)$s);
    return $map[$s] ?? $s;
  }
}

$latest = [];
try {
  // ตัวเลือกที่ 1: มี order_addresses เก็บชื่อผู้รับ (type='shipping')
  $sql = "SELECT o.id, o.status, o.grand_total,
                 COALESCE(NULLIF(o.fullname,''), a.recipient_name, u.fullname, u.name) AS customer
          FROM orders o
          LEFT JOIN order_addresses a ON a.order_id = o.id AND (a.type='shipping' OR a.type IS NULL)
          LEFT JOIN users u ON u.id = o.user_id
          ORDER BY o.id DESC
          LIMIT 10";
  $st = $pdo->query($sql);
  $latest = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  try {
    // ตัวเลือกที่ 2: ไม่มี order_addresses → ดึงจาก orders + users
    $sql = "SELECT o.id, o.status, o.grand_total,
                   COALESCE(NULLIF(o.fullname,''), u.fullname, u.name) AS customer
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            ORDER BY o.id DESC
            LIMIT 10";
    $st = $pdo->query($sql);
    $latest = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e2) {
    // ตัวเลือกสุดท้าย: ใช้เฉพาะ orders (อาจไม่มีชื่อ)
    $sql = "SELECT o.id, o.status, o.grand_total, o.fullname AS customer
            FROM orders o
            ORDER BY o.id DESC
            LIMIT 10";
    $st = $pdo->query($sql);
    $latest = $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
?>

<ul class="list reset" style="margin:0;padding:0;list-style:none">
  <?php if (!$latest): ?>
    <li style="padding:.6rem 0;opacity:.7">ไม่มีข้อมูล</li>
  <?php else: ?>
    <?php foreach ($latest as $row): ?>
      <li style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid #f0f2f5">
        <div style="min-width:0">
          <a href="receipt.php?id=<?= (int)$row['id'] ?>" style="text-decoration:none;color:#0e1530">
            #<?= (int)$row['id'] ?>
          </a>
          <span style="opacity:.7;margin-left:.5rem;">
            <?= h($row['customer'] ?: 'ไม่ระบุชื่อ') ?>
          </span>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;flex-shrink:0;">
          <span style="opacity:.8;">฿<?= number_format((float)($row['grand_total'] ?? 0), 2) ?></span>
          <span class="badge"
                style="padding:.15rem .5rem;border-radius:999px;background:#e9ecff;color:#23234a;font-size:.8rem;">
            <?= h(status_th($row['status'] ?? '')) ?>
          </span>
        </div>
      </li>
    <?php endforeach; ?>
  <?php endif; ?>
</ul>
</div>

<!-- All Menu Links 
<nav style="background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
    <h3 style="margin-top: 0; color: #333; font-size: 1.3rem; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #f0f0f0;">
        📂 เมนูทั้งหมด
    </h3>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <span class="badge" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 0.5rem 1rem; border-radius: 20px;">Admin</span>
        <a class="btn" href="products.php">จัดการสินค้า</a>
        <a class="btn" href="orders.php">จัดการออเดอร์</a>
        <a class="btn" href="coupons.php">จัดการคูปอง</a>
        <a class="btn" href="settings_order.php">ตั้งค่าออเดอร์</a>
        <a class="btn" href="notify_settings.php">ตั้งค่าแจ้งเตือน</a>
        <a class="btn" href="customers.php">ลูกค้า</a>
        <a class="btn" href="users.php">สิทธิ์ผู้ใช้</a>
        <a class="btn" href="permissions.php">สิทธิ์แอดมิน</a>
        <a class="btn" href="report_admin_activity.php">กิจกรรมแอดมิน</a>
        <a class="btn" href="report_admin_sales.php">ยอดขายแอดมิน</a>
        <a class="btn" href="notify_templates.php">เทมเพลตแจ้งเตือน</a>
        <a class="btn" href="export_sales.php">ส่งออกการขาย</a>
        <a class="btn" href="settings.php">ตั้งค่า</a>
        <a class="btn" href="chats.php">ข้อความแชท</a>
        <a class="btn" href="stock_export.php">ส่งออกสต็อก</a>
        <a class="btn" href="stock_import.php">นำเข้าสต็อก</a>
        <a class="btn" href="pos.php">ระบบ POS</a>
		<a class="btn outline" href="tools_reset_orders.php" style="margin-left:.5rem">รีเซ็ตนับออเดอร์</a>
    </div>
</nav> -->

<?php include __DIR__ . '/../partials/footer.php'; ?>