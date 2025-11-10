<?php
require_once __DIR__ . '/../config/config.php';
require_admin();
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


<h2>ผู้ใช้งาน</h2>
<table class="table">
<tr><th>#</th><th>ชื่อ</th><th>อีเมล</th><th>เบอร์</th><th>สิทธิ์</th><th>สมัครเมื่อ</th></tr>
<?php foreach($pdo->query('SELECT * FROM users ORDER BY id DESC') as $u): ?>
<tr>
<td><?= (int)$u['id'] ?></td>
<td><?= htmlspecialchars($u['name']) ?></td>
<td><?= htmlspecialchars($u['email']) ?></td>
<td><?= htmlspecialchars($u['phone']) ?></td>
<td><?= htmlspecialchars($u['role']) ?></td>
<td><?= htmlspecialchars($u['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php include __DIR__ . '/../partials/footer.php'; ?>