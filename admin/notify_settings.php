<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notify.php';
require_admin();

$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $err='CSRF invalid'; }
  else {
    if (isset($_POST['save'])) {
      $pairs = [
        'smtp_host','smtp_port','smtp_username','smtp_password','smtp_secure',
        'smtp_from','smtp_from_name','store_notify_email','line_notify_token'
      ];
      foreach ($pairs as $k) {
        $v = $_POST[$k] ?? '';
        $st = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
        $st->execute([$k, $v]);
      }
      $msg='บันทึกการตั้งค่าแล้ว';
    }

    if (isset($_POST['test_email'])) {
      $to = trim($_POST['test_email_addr'] ?? '');
      if ($to==='') { $err='กรอกอีเมลปลายทาง'; }
      else {
        $ok = send_mail_smtp($pdo, $to, 'ทดสอบ SMTP จากร้าน', 'สวัสดี นี่คืออีเมลทดสอบจากระบบแจ้งเตือน');
        $msg = $ok ? 'ส่งอีเมลทดสอบแล้ว' : 'ส่งอีเมลไม่สำเร็จ (ตรวจสอบ SMTP)';
      }
    }

    if (isset($_POST['test_line'])) {
      $txt = "ทดสอบ LINE Notify จากร้าน ".get_setting($pdo,'store_name','My PHP Shop')."\nเวลา: ".date('Y-m-d H:i:s');
      line_notify_multicast($pdo, $txt);
      $msg='ส่ง LINE Notify ทดสอบแล้ว (ตรวจในแอพ LINE)';
    }
  }
}

function gv($k,$d=''){ global $pdo; return htmlspecialchars(get_setting($pdo,$k,$d)); }

include __DIR__ . '/../partials/header.php';
?>
<h2>ตั้งค่าการแจ้งเตือน (SMTP & LINE)</h2>
<?php if($msg): ?><div class="alert success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card">
  <h3>SMTP (แนะนำใช้ PHPMailer + TLS/SSL)</h3>
  <form method="post" class="grid" style="grid-template-columns:1fr 1fr; gap:1rem">
    <?= csrf_field() ?>
    <div>
      <label>SMTP Host</label><input class="input" name="smtp_host" value="<?= gv('smtp_host') ?>" placeholder="smtp.gmail.com">
      <label>SMTP Port</label><input class="input" name="smtp_port" value="<?= gv('smtp_port','587') ?>">
      <label>Secure</label>
      <select class="input" name="smtp_secure">
        <?php foreach(['none','tls','ssl'] as $s): $sel = (get_setting($pdo,'smtp_secure','tls')===$s)?'selected':''; ?>
          <option value="<?= $s ?>" <?= $sel ?>><?= strtoupper($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Username</label><input class="input" name="smtp_username" value="<?= gv('smtp_username') ?>">
      <label>Password</label><input class="input" type="password" name="smtp_password" value="<?= gv('smtp_password') ?>">
      <label>From</label><input class="input" name="smtp_from" value="<?= gv('smtp_from','no-reply@example.com') ?>">
      <label>From Name</label><input class="input" name="smtp_from_name" value="<?= gv('smtp_from_name','My PHP Shop') ?>">
    </div>
    <div style="grid-column:1/3">
      <button class="btn" name="save" value="1" type="submit">บันทึก SMTP</button>
    </div>
  </form>
  <form method="post" style="margin-top:.5rem">
    <?= csrf_field() ?>
    <label>ทดสอบส่งอีเมลไปที่</label>
    <input class="input" type="email" name="test_email_addr" placeholder="you@example.com" required style="max-width:320px">
    <button class="btn" name="test_email" value="1" type="submit">ส่งอีเมลทดสอบ</button>
  </form>
</div>

<div class="card">
  <h3>LINE Notify</h3>
  <form method="post">
    <?= csrf_field() ?>
    <label>LINE Notify Token (คั่นด้วย , ได้หลาย token)</label>
    <input class="input" name="line_notify_token" value="<?= gv('line_notify_token') ?>" placeholder="xxxxx">
    <button class="btn" name="save" value="1" type="submit">บันทึก LINE</button>
    <button class="btn outline" name="test_line" value="1" type="submit">ส่งข้อความทดสอบ</button>
  </form>
  <p class="small" style="opacity:.8;margin-top:.5rem">
    * ใช้ <a target="_blank" href="https://notify-bot.line.me/">LINE Notify</a> เพื่อสร้าง token (เครือร้านแต่ละคนสร้าง token ของตนเองได้)
  </p>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
