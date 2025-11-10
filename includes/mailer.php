<?php
// ใช้ร่วมกับ get_setting() ใน includes/functions.php
// พยายามใช้ PHPMailer (ผ่าน composer) ถ้ามี; ไม่งั้น fallback ไป mail()

function smtp_config(PDO $pdo): array {
  return [
    'host'   => trim(get_setting($pdo,'smtp_host','')),
    'port'   => (int)get_setting($pdo,'smtp_port','587'),
    'user'   => trim(get_setting($pdo,'smtp_username','')),
    'pass'   => trim(get_setting($pdo,'smtp_password','')),
    'secure' => trim(get_setting($pdo,'smtp_secure','tls')), // none|tls|ssl
    'from'   => trim(get_setting($pdo,'smtp_from','no-reply@example.com')),
    'from_name' => trim(get_setting($pdo,'smtp_from_name','My PHP Shop')),
  ];
}

function send_mail_smtp(PDO $pdo, string $to, string $subject, string $html): bool {
  $cfg = smtp_config($pdo);
  if ($to==='') return false;

  // ถ้ามี composer autoload ให้ลองใช้ PHPMailer
  $havePHPMailer = false;
  $autoloads = [
    __DIR__.'/../vendor/autoload.php', // โปรเจกต์ราก/vendor
    __DIR__.'/../../vendor/autoload.php',
  ];
  foreach ($autoloads as $a) { if (is_file($a)) { require_once $a; $havePHPMailer = class_exists('PHPMailer\PHPMailer\PHPMailer'); break; } }

  if ($havePHPMailer && $cfg['host']!=='') {
    try {
      $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      $mail->isSMTP();
      $mail->Host = $cfg['host'];
      $mail->Port = $cfg['port'];
      if ($cfg['secure']==='ssl') $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
      elseif ($cfg['secure']==='tls') $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mail->SMTPAuth = ($cfg['user']!=='' || $cfg['pass']!=='');
      $mail->Username = $cfg['user'];
      $mail->Password = $cfg['pass'];
      $mail->CharSet  = 'UTF-8';
      $mail->setFrom($cfg['from'], $cfg['from_name']);
      $mail->addAddress($to);
      $cc = trim(get_setting($pdo,'store_notify_email',''));
      if ($cc!=='') $mail->addCC($cc);
      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $html;
      $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));
      $mail->send();
      return true;
    } catch (Throwable $e) {
      // ตกไปใช้ mail() ด้านล่าง
    }
  }

  // Fallback: PHP mail()
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type: text/html; charset=UTF-8\r\n";
  $headers .= "From: ".$cfg['from_name']." <".$cfg['from'].">\r\n";
  return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);
}
