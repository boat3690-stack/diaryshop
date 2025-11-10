<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $err='CSRF invalid'; }
  else {
    $status = $_POST['status'];
    $st = $pdo->prepare("UPDATE notify_templates SET email_subject=?, email_body=?, chat_body=? WHERE status=?");
    $st->execute([
      trim($_POST['email_subject'] ?? ''),
      trim($_POST['email_body'] ?? ''),
      trim($_POST['chat_body'] ?? ''),
      $status
    ]);
    $msg='บันทึกเทมเพลตแล้ว';
  }
}
$rows = $pdo->query("SELECT * FROM notify_templates ORDER BY FIELD(status,'unpaid','paid','processing','shipped','completed','cancelled')")->fetchAll();
include __DIR__ . '/../partials/header.php';
?>
<h2>ข้อความแจ้งเตือนสถานะออเดอร์</h2>
<p class="small">ตัวแปรที่ใช้ได้: {order_id}, {status}, {grand_total}, {customer_name}, {tracking_no}, {pickup_code}, {store_name}, {bank_account}, {order_link}, {ttl_hours}</p>
<?php if($msg): ?><div class="alert success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php foreach($rows as $r): ?>
<div class="card" style="margin-bottom:1rem">
  <h3>Status: <code><?= htmlspecialchars($r['status']) ?></code></h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="status" value="<?= htmlspecialchars($r['status']) ?>">
    <label>อีเมล: หัวข้อ</label>
    <input class="input" name="email_subject" value="<?= htmlspecialchars($r['email_subject']) ?>">
    <label>อีเมล: เนื้อหา (HTML/ข้อความ)</label>
    <textarea class="input" name="email_body" rows="5"><?= htmlspecialchars($r['email_body']) ?></textarea>
    <label>ข้อความแชท</label>
    <textarea class="input" name="chat_body" rows="3"><?= htmlspecialchars($r['chat_body']) ?></textarea>
    <button class="btn" type="submit">บันทึก</button>
  </form>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
