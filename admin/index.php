<?php
// sh/admin/index.php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
if (function_exists('require_admin')) { require_admin(); } // ถ้ามีฟังก์ชันเช็คสิทธิ์

// ไปหน้าแดชบอร์ด
header('Cache-Control: no-store');
header('Location: dashboard.php', true, 302);
exit;
