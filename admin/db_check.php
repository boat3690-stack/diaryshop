<?php
// admin/db_check.php

// เปิดการแสดง Error ทั้งหมด
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Connection Check</h1>";
echo "<pre>";

// เรียกใช้ไฟล์ config หลักของคุณ ซึ่งจะไปเรียก database.php ต่อเอง
require_once __DIR__ . '/../config/config.php';

if (isset($pdo)) {
    try {
        // ถามฐานข้อมูลโดยตรงว่า "เธอชื่ออะไร?"
        $db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();

        echo "✅ SUCCESS! PHP script connected to the database.\n\n";
        echo "--------------------------------------------------\n";
        echo "The script is currently connected to this database: \n";
        echo "👉 '" . htmlspecialchars($db_name) . "' 👈\n";
        echo "--------------------------------------------------\n\n";
        echo "Please compare this name with the one you see in phpMyAdmin.";

    } catch (Exception $e) {
        echo "❌ ERROR! Could not query the database.\n";
        echo "Message: " . $e->getMessage();
    }
} else {
    echo "❌ FAILED! The \$pdo variable was not created after including config.php.";
}

echo "</pre>";