<?php
// admin/post_actions.php
require_once __DIR__ . '/../config/config.php';

// บังคับให้เป็นแอดมินเท่านั้น หากยังไม่มีฟังก์ชันนี้ ให้สร้างในไฟล์ authz.php
if (function_exists('require_admin')) {
    require_admin();
} else {
    // Fallback กรณีไม่มีฟังก์ชัน require_admin
    if (!function_exists('is_admin') || !is_admin()) {
        die('Access Denied.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf'] ?? '')) {
        flash('error', 'Token ไม่ถูกต้อง');
        redirect('posts.php');
    }

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        try {
            // (ทางเลือก) ดึงชื่อไฟล์รูปภาพเพื่อลบไฟล์ออกจากเซิร์ฟเวอร์
            $st = $pdo->prepare("SELECT image FROM posts WHERE id = ?");
            $st->execute([$id]);
            $imgPath = $st->fetchColumn();
            if ($imgPath && file_exists(__DIR__ . '/../' . ltrim($imgPath, '/'))) {
                @unlink(__DIR__ . '/../' . ltrim($imgPath, '/'));
            }

            // ลบข้อมูลออกจาก Database
            $st = $pdo->prepare("DELETE FROM posts WHERE id = ?");
            $st->execute([$id]);

            flash('success', 'ลบบทความ #' . $id . ' เรียบร้อยแล้ว');
            redirect('admin/posts.php'); // กลับไปหน้ารายการบทความ
        } catch (Exception $e) {
            flash('error', 'เกิดข้อผิดพลาดในการลบ: ' . $e->getMessage());
            redirect('admin/posts.php');
        }
    }
}
redirect('admin/posts.php');