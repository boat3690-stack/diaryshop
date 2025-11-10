<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

$q = trim($_GET['q'] ?? '');
$df = $_GET['date_from'] ?? '';
$dt = $_GET['date_to'] ?? '';
$where=[]; $p=[];

if($q!==''){ $where[]='(o.id LIKE ? OR o.fullname LIKE ? OR o.tracking_no LIKE ? OR u.email LIKE ?)'; $p=["%$q%","%$q%","%$q%","%$q%"]; }
if($df!==''){ $where[]='DATE(o.created_at)>=?'; $p[]=$df; }
if($dt!==''){ $where[]='DATE(o.created_at)<=?'; $p[]=$dt; }
$w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// === แก้ไขโดยเพิ่ม LEFT JOIN users u... เข้าไปที่นี่ ===
$sql = "SELECT o.id,o.created_at,o.status,o.fullname,o.phone,o.email,o.tracking_no,o.shipped_at,o.delivered_at,
               sm.name AS ship_name, o.shipping, o.grand_total
        FROM orders o
        LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
        LEFT JOIN users u ON u.id=o.user_id
        $w ORDER BY o.id DESC LIMIT 300";
// ===============================================

$st = $pdo->prepare($sql); $st->execute($p); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/../partials/header.php'; ?>

<style>
/* ==== FULL WIDTH เฉพาะหน้า orders.php นี้ ==== */
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
</style>

<h2>ประวัติการจัดส่ง</h2>
<form method="get" class="filterbar" style="display:flex;gap:.5rem;margin:.6rem 0">
  <input class="input" name="q" placeholder="เลขบิล/ชื่อ/อีเมล/Tracking" value="<?= htmlspecialchars($q) ?>">
  <input class="input" type="date" name="date_from" value="<?= htmlspecialchars($df) ?>">
  <input class="input" type="date" name="date_to" value="<?= htmlspecialchars($dt) ?>">
  <button class="btn">ค้นหา</button>
  <a class="btn outline" href="shipping_export.php?<?= http_build_query($_GET) ?>">Export Excel (CSV)</a>
</form>

<table class="table">
<tr><th>#</th><th>วันที่</th><th>ลูกค้า</th><th>ขนส่ง</th><th>ค่าส่ง</th><th>Tracking</th><th>ส่งเมื่อ</th><th>ถึงเมื่อ</th><th>สถานะ</th></tr>
<?php foreach($rows as $r): ?>
<tr>
  <td>#<?= (int)$r['id'] ?></td>
  <td><?= htmlspecialchars($r['created_at']) ?></td>
  <td class="left"><?= htmlspecialchars($r['fullname']) ?> · <?= htmlspecialchars($r['email'] ?: $r['phone']) ?></td>
  <td><?= htmlspecialchars($r['ship_name'] ?: '-') ?></td>
  <td>฿<?= number_format((float)$r['shipping'],2) ?></td>
  <td><?= htmlspecialchars($r['tracking_no'] ?: '-') ?></td>
  <td><?= htmlspecialchars($r['shipped_at'] ?: '-') ?></td>
  <td><?= htmlspecialchars($r['delivered_at'] ?: '-') ?></td>
  <td><span class="badge"><?= htmlspecialchars($r['status']) ?></span></td>
</tr>
<?php endforeach; ?>
</table>
<?php include __DIR__ . '/../partials/footer.php'; ?>
