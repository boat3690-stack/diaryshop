<?php
// welcome_settings.php - Final Version with Full Error Reporting

// --- ส่วน API สำหรับจัดการการบันทึกข้อมูล ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // บังคับให้แสดง Error ทุกชนิดก่อนทำอะไรทั้งสิ้น
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    header('Content-Type: application/json; charset=utf-8');
    
    try {
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../includes/authz.php';
        
        // ตั้งค่าให้ PDO โยน Exception เมื่อมี Error (สำคัญมาก)
        if (isset($pdo)) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } else {
            throw new Exception('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ ($pdo is not defined)');
        }
        
        if (function_exists('require_admin')) { require_admin(); }

        if (!function_exists('csrf_check') || !csrf_check($_POST['csrf'] ?? '')) {
            throw new Exception('CSRF token ไม่ถูกต้อง กรุณาลองรีเฟรชหน้าจอ');
        }

        $html = $_POST['welcome_popup_html'] ?? '';
        $on = isset($_POST['welcome_popup_enabled']) ? '1' : '0';

        // บันทึก HTML
        $stmt_html = $pdo->prepare(
            "INSERT INTO settings(`key`, `value`) VALUES('welcome_popup_html', ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"
        );
        $stmt_html->execute([$html]);

        // บันทึกสถานะ
        $stmt_enabled = $pdo->prepare(
            "INSERT INTO settings(`key`, `value`) VALUES('welcome_popup_enabled', ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"
        );
        $stmt_enabled->execute([$on]);
        
        echo json_encode(['success' => true, 'message' => 'บันทึกเรียบร้อยแล้ว']);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'เกิดข้อผิดพลาดฝั่งเซิร์ฟเวอร์',
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ]);
    }
    exit;
}

// --- ส่วนแสดงผลหน้าเว็บ (จะทำงานเมื่อเปิดหน้าเว็บปกติ) ---
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';
require_admin();

if (!function_exists('get_setting')) {
    function get_setting(PDO $pdo, string $key, $default = null) {
        try {
            $stmt = $pdo->prepare("SELECT `value` FROM `settings` WHERE `key` = ?");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return ($value !== false) ? $value : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}

$val = get_setting($pdo, 'welcome_popup_html', '<h3>ยินดีต้อนรับ</h3>');
$ena = get_setting($pdo, 'welcome_popup_enabled', '1');

include __DIR__.'/../partials/header.php';
?>

<script src="https://cdn.tiny.cloud/1/k5okesppqoja25vplimt2e65lan9j9h8bvjqc0cdeugl02q3/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>

<h2>ตั้งค่าข้อความต้อนรับ</h2>
<div id="form-message" style="margin-bottom: 1rem;"></div>
<form method="post" class="card" style="max-width:800px" id="settingsForm">
    <?= csrf_field() ?>
    <label class="small" style="display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem;">
        <input type="checkbox" id="popup_enabled" name="welcome_popup_enabled" value="1" <?= $ena === '1' ? 'checked' : ''; ?>> 
        แสดงป๊อปอัป
    </label>
    <textarea id="html_content" name="welcome_popup_html" rows="15"><?= htmlspecialchars($val) ?></textarea>
    <div style="margin-top:.6rem"><button id="saveBtn" class="btn" type="submit">บันทึก</button></div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    tinymce.init({
        selector: '#html_content',
        plugins: 'lists link image media table code help wordcount',
        toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist outdent indent | link image media | code | help'
    });
    const form = document.getElementById('settingsForm');
    const messageDiv = document.getElementById('form-message');
    const saveBtn = document.getElementById('saveBtn');
    if (form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            saveBtn.disabled = true;
            messageDiv.innerHTML = '<div class="alert">กำลังบันทึก...</div>';
            const formData = new FormData(form);
            formData.append('welcome_popup_html', tinymce.get('html_content').getContent());
            fetch('welcome_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const responseText = await response.text();
                try {
                    const jsonData = JSON.parse(responseText);
                    if (!response.ok) {
                        throw new Error(jsonData.message || `Server Error ${response.status}`);
                    }
                    return jsonData;
                } catch (e) {
                    throw new Error(`Invalid response from server: ${responseText}`);
                }
            })
            .then(data => {
                if (data.success) {
                    messageDiv.innerHTML = `<div class="alert success">${data.message}</div>`;
                } else {
                    messageDiv.innerHTML = `<div class="alert error"><b>เกิดข้อผิดพลาด:</b> ${data.message}</div>`;
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                messageDiv.innerHTML = `<div class="alert error" style="white-space: pre-wrap; text-align: left;"><b>เกิดข้อผิดพลาดร้ายแรง:</b>\n${error.message}</div>`;
            })
            .finally(() => {
                saveBtn.disabled = false;
            });
        });
    }
});
</script>

<?php include __DIR__.'/../partials/footer.php'; ?>