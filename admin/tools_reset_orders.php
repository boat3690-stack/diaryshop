<?php
// admin/tools_reset_orders.php — เครื่องมือรีเซ็ตเลขออเดอร์ให้เริ่มจาก 1 (ลบออเดอร์ทั้งหมด)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

/* helpers */
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function table_exists(PDO $pdo, string $name): bool {
  try{
    $st=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
    $st->execute([$name]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function truncate_or_delete(PDO $pdo, string $tbl): void {
  try {
    $pdo->exec("TRUNCATE TABLE `$tbl`");
  } catch (Throwable $e) {
    // shared host/permission บางที่ TRUNCATE ไม่ได้ → fallback เป็น DELETE
    try { $pdo->exec("DELETE FROM `$tbl`"); } catch (Throwable $e2) { /* ignore */ }
  }
  try { $pdo->exec("ALTER TABLE `$tbl` AUTO_INCREMENT=1"); } catch (Throwable $e) { /* ignore */ }
}

/* ตารางลูกที่มักเกี่ยวกับ orders (จะลบเฉพาะที่มีจริง) */
$maybeChildren = ['order_messages','payments','order_items','shipments','shipment_items','refunds','order_logs'];
$existingChildren = array_values(array_filter($maybeChildren, fn($t)=>table_exists($pdo,$t)));

$ordersCount = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$autoNext = null;
try{
  $q = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='orders'");
  $autoNext = $q->fetchColumn();
}catch(Throwable $e){ $autoNext = null; }

/* POST: ทำการรีเซ็ตจริง (ไม่ใช้ transaction เพราะ TRUNCATE จะ implicit commit เอง) */
$msg = null; $err = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='hard_reset') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err='CSRF invalid';
  } else {
    try{
      // ปิด FK ก่อน แล้วเปิดคืนทีหลัง
      $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
      foreach ($existingChildren as $tbl) { truncate_or_delete($pdo, $tbl); }
      truncate_or_delete($pdo, 'orders');
      $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

      $msg = 'รีเซ็ตเรียบร้อย! ระบบจะเริ่มนับออเดอร์ใหม่จาก #1';
      $ordersCount = 0; $autoNext = 1;
    }catch(Throwable $e){
      // พยายามเปิด FK คืน เผื่อค้าง
      try{ $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); }catch(Throwable $e2){}
      $err = 'รีเซ็ตไม่สำเร็จ: '.$e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>เครื่องมือรีเซ็ตออเดอร์ | Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{ --ink:#111; --muted:#6b7280; --red:#b91c1c; --green:#166534; }
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,'TH Sarabun New',sans-serif;color:var(--ink);background:#fff;margin:0}
  .wrap{max-width:900px;margin:28px auto;padding:0 16px}
  h1{margin:0 0 6px 0}
  .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:16px 0}
  .muted{color:var(--muted)}
  .rows{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:800px){ .rows{grid-template-columns:1fr} }
  .btn{display:inline-block;padding:.55rem .9rem;border-radius:.6rem;border:1px solid #111;background:#111;color:#fff;text-decoration:none;cursor:pointer}
  .btn.outline{background:#fff;color:#111}
  .btn.red{background:#fff;color:var(--red);border-color:var(--red)}
  .alert{padding:.75rem .9rem;border-radius:.6rem;margin:12px 0;border:1px solid}
  .alert.ok{border-color:#bbf7d0;background:#f0fdf4;color:var(--green)}
  .alert.err{border-color:#fecaca;background:#fef2f2;color:var(--red)}
  .warn{color:#b45309}
  ul{margin:.4rem 0 .2rem 1.1rem}
  code{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;padding:.05rem .35rem}
</style>
</head>
<body>
<div class="wrap">
  <h1>เครื่องมือรีเซ็ตเลขออเดอร์</h1>
  <div class="muted">ใช้สำหรับลบออเดอร์ทดสอบทั้งหมดแล้วเริ่มนับจาก #1 ใหม่ (เฉพาะผู้ดูแลระบบ)</div>

  <?php if($msg): ?><div class="alert ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <h3 style="margin:0 0 8px 0">สรุปข้อมูลปัจจุบัน</h3>
    <div class="rows">
      <div>
        <div>จำนวนออเดอร์ในระบบ: <b><?= (int)$ordersCount ?></b></div>
        <div class="muted">เลขถัดไป (AUTO_INCREMENT): <?= $autoNext!==null? (int)$autoNext : '-' ?></div>
      </div>
      <div>
        <div>ตารางที่เกี่ยวข้องจะถูกลบข้อมูล:</div>
        <ul>
          <?php foreach($existingChildren as $t): ?><li><code><?= h($t) ?></code></li><?php endforeach; ?>
          <li><code>orders</code></li>
        </ul>
      </div>
    </div>

    <div class="alert" style="border-color:#fde68a;background:#fffbeb;color:#92400e;">
      <b>คำเตือน:</b> การรีเซ็ตนี้จะ <u>ลบข้อมูลออเดอร์/สลิป/รายการสินค้า ทั้งหมด</u> และเริ่มนับใหม่จาก #1<br>
      การกระทำนี้ย้อนกลับไม่ได้ แนะนำให้สำรองฐานข้อมูลก่อนเสมอครับ
    </div>

    <form method="post" onsubmit="return confirm('ยืนยันรีเซ็ตออเดอร์ทั้งหมด และเริ่มนับใหม่จาก #1 ?\n\nคำเตือน: ลบข้อมูลถาวร ย้อนกลับไม่ได้');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="hard_reset">
      <button class="btn red" type="submit">รีเซ็ตนับออเดอร์ใหม่ (ลบทดสอบทั้งหมด)</button>
      <a class="btn outline" href="./orders.php" style="margin-left:.5rem">กลับรายการออเดอร์</a>
    </form>

    <div class="muted" style="margin-top:.75rem">
      * หากสต๊อกถูกตัดไปจากออเดอร์ทดสอบ แนะนำปรับ <code>products.stock</code> ให้ถูกต้องภายหลังรีเซ็ต
    </div>
  </div>
</div>
</body>
</html>
