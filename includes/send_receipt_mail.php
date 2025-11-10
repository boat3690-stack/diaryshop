<?php
// includes/send_receipt_mail.php (patched)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/config.php';

// (ถ้าคุณติดตั้ง PHPMailer ผ่าน Composer)
// composer require phpmailer/phpmailer
$have_phpmailer = false;
try {
  if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $have_phpmailer = class_exists(PHPMailer::class);
  }
} catch (Throwable $e) { /* noop */ }

// ป้องกัน XSS ง่าย ๆ
function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/**
 * สร้างลิงก์ใบเสร็จหน้าเต็ม (เปิดได้เสมอ)
 * - ถ้ามี phone4 -> ใช้ phone4
 * - ถ้าเป็น pick-up และมี pickup_code -> ใช้ code
 * - ไม่งั้น เซ็นชื่อด้วย session_id() -> sig
 */
function build_receipt_url(array $order): string {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $base = rtrim(BASE_URL, '/') . '/receipt.php?id=' . (int)$order['id'];

  $digits = preg_replace('/\D+/', '', (string)($order['phone'] ?? ''));
  $last4  = substr($digits, -4) ?: '';
  if ($last4 !== '') return $base . '&phone4=' . $last4;

  $pickup_code = trim((string)($order['pickup_code'] ?? ''));
  if ($pickup_code !== '') return $base . '&code=' . rawurlencode($pickup_code);

  $sig = hash_hmac('sha256', 'rcpt:' . $order['id'], session_id());
  return $base . '&sig=' . $sig;
}

/** ส่งอีเมลใบเสร็จให้ลูกค้า (สำเร็จ=true) */
function send_receipt_mail(PDO $pdo, int $order_id): bool {
  global $have_phpmailer;

  // ดึงคำสั่งซื้อ + สินค้า
  $st = $pdo->prepare("SELECT o.*, sm.name AS ship_name
                       FROM orders o
                       LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
                       WHERE o.id=?");
  $st->execute([$order_id]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if (!$o) return false;

  // ถ้าไม่มีอีเมลลูกค้า ก็ไม่ต้องส่ง
  $toEmail = trim((string)($o['email'] ?? ''));
  if ($toEmail === '') return false;

  $its = $pdo->prepare("
  SELECT 
    COALESCE(NULLIF(oi.item_name,''), p.name) AS name,
    oi.qty,
    COALESCE(NULLIF(oi.unit_price,0), NULLIF(oi.price,0), p.price, 0) AS unit_price,
    oi.line_total,
    oi.cover_fee
  FROM order_items oi
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_id = ?
");
$its->execute([$order_id]);
$items = $its->fetchAll(PDO::FETCH_ASSOC);

  // ข้อมูลร้าน + การตั้งค่าแจ้งเตือน
  $shop_name   = get_setting($pdo, 'shop_name',  'RTAF Diary Shop');
  $shop_email  = get_setting($pdo, 'shop_email', 'admin@rtafdiary.com');
  $notify_cc   = trim(get_setting($pdo, 'store_notify_email', '')); // จะถูก CC ให้แอดมิน
  $reply_to    = trim(get_setting($pdo, 'shop_reply_to', '')) ?: $shop_email;

  $subject = "หมายเลขคำสั่งซื้อที่ #{$o['id']} - {$shop_name}";
  $receipt_url = build_receipt_url($o);

  // เนื้อหาอีเมลแบบ HTML (สั้น กระชับ + ลิงก์ใบเสร็จ)
  ob_start(); ?>
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,'TH Sarabun New',sans-serif">
    <h2 style="margin:0 0 8px">หมายเลขคำสั่งซื้อที่ #<?= (int)$o['id'] ?></h2>
    <div>ขอบคุณสำหรับการสั่งซื้อจาก <b><?= h($shop_name) ?></b></div>
    <div style="margin:10px 0">ดูคำสั่งซื้อที่ได้ที่ลิงก์นี้: <a href="<?= h($receipt_url) ?>" target="_blank"><?= h($receipt_url) ?></a></div>

    <table style="width:100%;border-collapse:collapse;margin-top:8px">
      <tr>
        <th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 4px">สินค้า</th>
        <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 4px">จำนวน</th>
        <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 4px">ราคา</th>
      </tr>
      <?php
$sub = 0.0;
$cover_total_items = 0.0;

// กันพัง: ถ้า fetchAll() ล้มเหลว
$items = is_array($items) ? $items : [];

foreach ($items as $r):
  // อ่านค่าแบบปลอดภัย (กัน index ไม่อยู่หรือไม่ใช่ตัวเลข)
  $unit = isset($r['unit_price']) && is_numeric($r['unit_price']) ? (float)$r['unit_price'] : 0.0;
  $qty  = isset($r['qty']) && is_numeric($r['qty']) ? (int)$r['qty'] : 0;

  $line_total =
      (isset($r['line_total']) && is_numeric($r['line_total']) && (float)$r['line_total'] > 0)
      ? (float)$r['line_total'] : null;

  // ถ้ามี line_total ให้เชื่อมันก่อน (สะท้อนส่วนลด/ค่าปกต่อบรรทัดแล้ว)
  $line = $line_total ?? ($unit * $qty);

  $sub += $line;

  // ค่าพิมพ์ปกต่อบรรทัด (ถ้าไม่มีคอลัมน์นี้ จะนับเป็น 0 อัตโนมัติ)
  $cover_total_items += (isset($r['cover_fee']) && is_numeric($r['cover_fee'])) ? (float)$r['cover_fee'] : 0.0;
?>
  <tr>
    <td style="padding:6px 4px"><?= h($r['name'] ?? '') ?></td>
    <td style="padding:6px 4px;text-align:right"><?= $qty ?></td>
    <td style="padding:6px 4px;text-align:right"><?= number_format($line,2) ?> บาท</td>
  </tr>
<?php endforeach; ?>

    </table>

    <?php
      $discount = (float)($o['discount'] ?? 0);
      $shipping = (float)($o['shipping'] ?? 0);
      $grand    = (float)($o['grand_total'] ?? max(0, $sub - $discount + $shipping));
    ?>
    <div style="margin-top:8px;text-align:right">
      ยอดรวมสินค้า: <?= number_format($sub,2) ?> บาท<br>
      ส่วนลด: <?= number_format($discount,2) ?> บาท<br>
      ค่าส่ง: <?= number_format($shipping,2) ?> บาท<br>
      <b>ยอดสุทธิ: <?= number_format($grand,2) ?> บาท</b>
    </div>

    <p style="color:#555">นี่คืออีเมลอัตโนมัติ โปรดอย่าตอบกลับ</p>
  </div>
  <?php
  $html = ob_get_clean();
  $alt  = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html));

  // ===== ลองส่งด้วย PHPMailer (SMTP) ก่อน =====
  if ($have_phpmailer) {
    try {
      $mail = new PHPMailer(true);
      $mail->CharSet  = 'UTF-8';
      $mail->Encoding = 'base64';

      $smtp_host   = trim(get_setting($pdo, 'smtp_host', ''));
      $smtp_user   = trim(get_setting($pdo, 'smtp_user', ''));
      $smtp_pass   = trim(get_setting($pdo, 'smtp_pass', ''));
      $smtp_port   = (int)get_setting($pdo, 'smtp_port', 587);
      $smtp_secure = strtolower(trim(get_setting($pdo, 'smtp_secure', 'tls'))); // 'tls'|'ssl'|''

      if ($smtp_host !== '') {
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        if ($smtp_user !== '' || $smtp_pass !== '') {
          $mail->SMTPAuth = true;
          $mail->Username = $smtp_user;
          $mail->Password = $smtp_pass;
        } else {
          $mail->SMTPAuth = false; // เผื่อกรณีใช้ internal relay ที่ไม่ต้อง auth
        }
        if ($smtp_secure === 'ssl') {
          $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // 465
          if ($smtp_port <= 0) $smtp_port = 465;
        } elseif ($smtp_secure === 'tls') {
          $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 587
          if ($smtp_port <= 0) $smtp_port = 587;
        } else {
          $mail->SMTPSecure = false; // ไม่เข้ารหัส
        }
        $mail->Port = $smtp_port > 0 ? $smtp_port : 25;
      }

      $fromEmail = $shop_email ?: ($smtp_user ?: 'no-reply@' . parse_url(BASE_URL, PHP_URL_HOST));
      $mail->setFrom($fromEmail, $shop_name);
      $mail->addAddress($toEmail, (string)($o['fullname'] ?? ''));
      if ($notify_cc !== '') $mail->addCC($notify_cc);
      if ($reply_to !== '')  $mail->addReplyTo($reply_to, $shop_name);

      $mail->Subject = $subject;
      $mail->isHTML(true);
      $mail->Body    = $html;
      $mail->AltBody = $alt;

      // ---- (ทางเลือก) แนบ PDF ใบเสร็จ ----
      // require_once __DIR__ . '/export_helpers.php';
      // $pdf = build_receipt_pdf_bytes($pdo, $order_id);
      // if ($pdf) $mail->addStringAttachment($pdf, "receipt_{$o['id']}.pdf", 'base64', 'application/pdf');

      return $mail->send();
    } catch (\Throwable $e) {
      error_log('send_receipt_mail PHPMailer: ' . $e->getMessage());
      // ถ้าพัง ค่อยตกไปใช้ mail() ด้านล่าง
    }
  }

  // ===== fallback: mail() ธรรมดา (รองรับ UTF-8 แบบ base64) =====
  $fromEmail = $shop_email ?: 'no-reply@' . parse_url(BASE_URL, PHP_URL_HOST);

  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
  $headers .= "Content-Transfer-Encoding: base64\r\n";
  $headers .= "From: {$shop_name} <{$fromEmail}>\r\n";
  if ($reply_to !== '')  $headers .= "Reply-To: {$reply_to}\r\n";
  if ($notify_cc !== '') $headers .= "Cc: {$notify_cc}\r\n";

  $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $encodedBody    = base64_encode($html);

  return @mail($toEmail, $encodedSubject, $encodedBody, $headers);
}
