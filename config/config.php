<?php
// config/config.php — bootstrap + returnable config array

// ===== 1) Bootstrap once =====
if (!defined('APP_BOOTSTRAPPED')) {
  // Toggle error level by ENV (APP_ENV=dev ให้โชว์ error)
  $APP_ENV = getenv('APP_ENV') ?: 'prod';
  $isDev = strtolower($APP_ENV) === 'dev';
  ini_set('display_errors', $isDev ? '1' : '0');
  ini_set('display_startup_errors', $isDev ? '1' : '0');
  error_reporting($isDev ? E_ALL : (E_ALL & ~E_NOTICE & ~E_WARNING));

  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  date_default_timezone_set('Asia/Bangkok');

  // ถ้าเว็บอยู่ใต้โฟลเดอร์ย่อย (เช่น /shop) ให้ใส่ path ตรงนี้
  if (!defined('BASE_PATH')) {
    define('BASE_PATH', '/rtaf'); // ตัวอย่าง: '/shop'
  }

  // ให้ตั้ง FORCE_BASE_URL ใน .env/Panel ได้ ถ้าอยากล็อก URL เอง
  $forceBase = getenv('FORCE_BASE_URL');

  // รองรับ proxy/CDN
  $scheme = 'http';
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $scheme = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
  } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
  }

  $host =
    $_SERVER['HTTP_X_FORWARDED_HOST'] ??
    $_SERVER['HTTP_HOST'] ??
    ($_SERVER['SERVER_NAME'] ?? 'localhost');

  if (!defined('BASE_URL')) {
    $calc = rtrim(($scheme . '://' . $host . BASE_PATH), '/');
    define('BASE_URL', rtrim($forceBase ?: $calc, '/'));
  }

  // Core includes
  require_once __DIR__ . '/database.php';               // ต้องนิยาม $pdo ให้เรียบร้อย
  require_once __DIR__ . '/../includes/functions.php';  // ฟังก์ชันทั่วไป (มี csrf, stock ฯลฯ)
  require_once __DIR__ . '/../includes/authz.php';      // ถ้ามี
  require_once __DIR__ . '/../includes/admin_log.php';  // ถ้ามี

  define('APP_BOOTSTRAPPED', true);
}

// ===== 2) Global config array =====
if (!isset($GLOBALS['APP_CONFIG'])) {
  $GLOBALS['APP_CONFIG'] = [
    'app' => [
      'base_url'      => BASE_URL,
      'mail_enabled'  => true,
      'mail_from'     => 'admin@attscyberclub.com',
      'shop_name'     => 'RTAF Diary Shop',
      'shop_reply_to' => 'support@example.com',
      'notify_email'  => 'admin@example.com',

      // SMTP (ถ้าต้องการ)
      'smtp' => [
        'enabled' => false,
        'host'    => 'smtp.example.com',
        'port'    => 587,
        'user'    => 'apikey',
        'pass'    => 'YOUR_SMTP_PASSWORD',
        'secure'  => 'tls', // 'tls' | 'ssl' | ''
      ],
    ],
  ];
}

// ===== 3) Always return config array =====
return $GLOBALS['APP_CONFIG'];
