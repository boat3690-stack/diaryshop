<?php
require_once __DIR__ . '/coupon_helpers.php';
require_once __DIR__ . '/mailer.php'; // ถ้าคุณใช้ SMTP
require_once __DIR__ . '/authz.php';

// ===== include guard: กันโหลดซ้ำ =====
if (defined('APP_NOTIFY_INCLUDED')) { return; }
define('APP_NOTIFY_INCLUDED', true);

function tpl_render(string $text, array $data): string {
  return preg_replace_callback('/\{([a-z0-9_]+)\}/i', fn($m)=> $data[$m[1]] ?? $m[0], $text);
}

function push_chat(PDO $pdo, int $order_id, string $sender, string $message, ?int $admin_id = null){
  $st=$pdo->prepare("INSERT INTO order_messages (order_id, sender, message, admin_id) VALUES (?,?,?,?)");
  $st->execute([$order_id, $sender, $message, $admin_id]);
}

function notify_order_status_change(PDO $pdo, int $order_id, string $old_status, string $new_status, ?int $by_admin_id=null){
  $st=$pdo->prepare("SELECT o.*, u.email AS user_email, u.name AS user_name
                     FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE o.id=?");
  $st->execute([$order_id]); $o=$st->fetch(); if(!$o) return;

  $email = $o['email'] ?: ($o['user_email'] ?? '');
  $name  = $o['fullname'] ?: ($o['user_name'] ?? 'ลูกค้า');
  $ttl   = (int)get_setting($pdo,'order_ttl_hours','48');
  $order_link = BASE_URL . '/track.php?order='.(int)$o['id'].'&phone='.urlencode((string)$o['phone']).'&email='.urlencode((string)$email);

  $tp=$pdo->prepare("SELECT * FROM notify_templates WHERE status=?"); $tp->execute([$new_status]);
  $tpl=$tp->fetch(); if(!$tpl) return;

  $data = [
    'order_id'=>(int)$o['id'],'status'=>$new_status,
    'grand_total'=>number_format((float)$o['grand_total'],2),
    'customer_name'=>$name,
    'tracking_no'=>(string)($o['tracking_no'] ?? ''),
    'pickup_code'=>(string)($o['pickup_code'] ?? ''),
    'store_name'=>get_setting($pdo,'store_name','My PHP Shop'),
    'bank_account'=>get_setting($pdo,'bank_account',''),
    'order_link'=>$order_link,'ttl_hours'=>$ttl,
  ];

  // แชท (ระบุ admin_id)
  $chat_msg = tpl_render($tpl['chat_body'], $data);
  if ($chat_msg!=='') push_chat($pdo, $order_id, 'admin', $chat_msg, $by_admin_id);

  // Email (SMTP/หรือ mail() fallback)
  if (!empty($email)) {
    $sub = tpl_render($tpl['email_subject'], $data);
    $bod = tpl_render($tpl['email_body'], $data);
    @send_mail_smtp($pdo, $email, $sub, $bod);
  }

  // LINE Notify (ถ้าตั้ง)
  $tokens = trim(get_setting($pdo,'line_notify_token',''));
  if ($tokens!=='') {
    $short = "ออเดอร์ #{$o['id']} สถานะ: {$new_status}\nยอด: {$data['grand_total']} บาท";
    if (!empty($data['tracking_no'])) $short .= "\nเลขพัสดุ: ".$data['tracking_no'];
    $short .= "\nลิงก์: ".$order_link;
    // ใช้ฟังก์ชัน line_notify_multicast() ถ้าคุณมี (ไม่แปะซ้ำเพื่อย่น)
    if (function_exists('line_notify_multicast')) line_notify_multicast($pdo, $short);
  }

  // Log
  admin_log($pdo, 'orders.update_status', 'order', (int)$o['id'], [
    'old'=>$old_status,'new'=>$new_status,'by_admin'=>$by_admin_id
  ]);

  function notify_tracking_assigned(PDO $pdo, int $order_id, ?int $admin_id=null): void {
    // อ่านข้อมูลบิล
    $st = $pdo->prepare("SELECT o.*, u.email AS uemail, u.name AS uname
                         FROM orders o LEFT JOIN users u ON u.id=o.user_id
                         WHERE o.id=?");
    $st->execute([$order_id]); $o = $st->fetch();
    if(!$o) return;

    $customer_name = $o['uname'] ?: $o['fullname'];
    $email = $o['email'] ?: $o['uemail'];
    $order_link = BASE_URL . '/order_status.php?id='.(int)$order_id; // ทำหน้าตรวจสถานะเองได้
    $tracking = $o['tracking_no'] ?: '';
    $ttl_hours = (int)get_setting($pdo,'order_ttl_hours','48');

    // โหลดเทมเพลต 'shipped'
    $tpl = $pdo->prepare("SELECT email_subject,email_body,chat_body FROM notify_templates WHERE status='shipped'");
    $tpl->execute(); $tpl=$tpl->fetch();
    $subject = $tpl ? $tpl['email_subject'] : 'ออเดอร์ #{order_id} จัดส่งแล้ว';
    $body    = $tpl ? $tpl['email_body']    : 'จัดส่งแล้ว เลขพัสดุ: {tracking_no}<br>เช็คสถานะ: {order_link}';
    $chat    = $tpl ? $tpl['chat_body']     : 'ส่งของแล้ว เลขพัสดุ {tracking_no}';

    $repl = [
      '{order_id}'     => (string)$order_id,
      '{customer_name}'=> htmlspecialchars($customer_name ?: 'ลูกค้า'),
      '{grand_total}'  => number_format((float)$o['grand_total'],2),
      '{order_link}'   => $order_link,
      '{tracking_no}'  => htmlspecialchars($tracking),
      '{ttl_hours}'    => (string)$ttl_hours
    ];
    $subject = strtr($subject, $repl);
    $body    = strtr($body, $repl);
    $chat    = strtr($chat, $repl);

    // ส่งอีเมล (ถ้ามี)
    if ($email) {
        notify_send_email($email, $subject, $body);
    }
    // ส่งไลน์ (ถ้าตั้งค่า token ไว้ใน notify.php)
    notify_send_line($chat);

    // log event
    $pdo->prepare("INSERT INTO shipping_events(order_id,admin_id,event,note) VALUES (?,?, 'tracking_set', ?)")
        ->execute([$order_id, $admin_id, 'tracking: '.$tracking]);
}
}

