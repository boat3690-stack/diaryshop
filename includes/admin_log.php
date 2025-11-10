<?php
// includes/admin_log.php

// คืน admin_id ปัจจุบัน (ดึงจาก session ของคุณ)
if (!function_exists('current_admin_id')) {
  function current_admin_id(): ?int {
    return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
  }
}

// ฟังก์ชันบันทึก log
if (!function_exists('admin_log')) {
  function admin_log(PDO $pdo, string $action, ?string $entity_type=null, ?int $entity_id=null, $meta=null): void {
    // กันตารางไม่มี (กรณี dev)
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      admin_id INT NULL,
      action VARCHAR(100) NOT NULL,
      entity_type VARCHAR(50) NULL,
      entity_id INT NULL,
      meta TEXT NULL,
      ip VARCHAR(64) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(created_at), INDEX(action), INDEX(entity_type), INDEX(admin_id)
    ) ENGINE=InnoDB");

    // meta ให้เป็น string/JSON สวย ๆ
    if (is_array($meta) || is_object($meta)) {
      $meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
    } elseif ($meta === null) {
      $meta = '';
    } else {
      $meta = (string)$meta;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, entity_type, entity_id, meta, ip)
                           VALUES (?,?,?,?,?,?)");
    $stmt->execute([ current_admin_id(), $action, $entity_type, $entity_id, $meta, $ip ]);
  }
}
