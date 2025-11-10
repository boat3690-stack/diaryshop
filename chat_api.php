<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/authz.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function chat_session_id(){
  if (empty($_SESSION['site_chat_sid'])) {
    $_SESSION['site_chat_sid'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['site_chat_sid'];
}

function get_or_create_chat(PDO $pdo): int {
  $sid = chat_session_id();
  $u   = current_user();

  if ($u) {
    $uid = (int)$u['id'];
    $st = $pdo->prepare("SELECT id FROM site_chats WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]); $id = (int)$st->fetchColumn();
    if ($id) return $id;

    $st = $pdo->prepare("SELECT id FROM site_chats WHERE session_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$sid]); $tmp = (int)$st->fetchColumn();
    if ($tmp) {
      $pdo->prepare("UPDATE site_chats SET user_id=?, name=COALESCE(name,?), email=COALESCE(email,?) WHERE id=?")
          ->execute([$uid, $u['name'] ?? null, $u['email'] ?? null, $tmp]);
      return $tmp;
    }
    $pdo->prepare("INSERT INTO site_chats(session_id,user_id,name,email) VALUES(?,?,?,?)")
        ->execute([$sid,$uid,$u['name'] ?? null,$u['email'] ?? null]);
    return (int)$pdo->lastInsertId();
  }

  $st = $pdo->prepare("SELECT id FROM site_chats WHERE session_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$sid]); $id = (int)$st->fetchColumn();
  if ($id) return $id;

  $pdo->prepare("INSERT INTO site_chats(session_id) VALUES(?)")->execute([$sid]);
  return (int)$pdo->lastInsertId();
}

/* ---------- วิดเจ็ตหน้าเว็บ (คงเดิม) ---------- */
if ($action==='init') {
  $id = get_or_create_chat($pdo);
  $u  = current_user();
  echo json_encode(['ok'=>1,'chat_id'=>$id,'user'=>$u?['id'=>$u['id'],'name'=>$u['name']??'','email'=>$u['email']??'']:null]); exit;
}
if ($action==='send') {
  $id  = get_or_create_chat($pdo);
  $msg = trim($_POST['message'] ?? '');
  if ($msg===''){ echo json_encode(['ok'=>0]); exit; }
  $pdo->prepare("INSERT INTO site_chat_messages(chat_id,sender,message) VALUES(?, 'customer', ?)")
      ->execute([$id,$msg]);
  $pdo->prepare("UPDATE site_chats SET last_msg_at=NOW() WHERE id=?")->execute([$id]);
  echo json_encode(['ok'=>1]); exit;
}
if ($action==='pull') {
  $id    = get_or_create_chat($pdo);
  $after = (int)($_GET['after'] ?? 0);
  $q = $pdo->prepare("SELECT id,sender,message,created_at FROM site_chat_messages WHERE chat_id=? AND id>? ORDER BY id ASC");
  $q->execute([$id,$after]);
  echo json_encode(['ok'=>1,'chat_id'=>$id,'messages'=>$q->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

/* ---------- ประวัติลูกค้าที่ล็อกอิน ---------- */
if ($action==='customer_history') {
  require_login();
  $uid = (int)current_user()['id'];
  $rooms = $pdo->prepare("SELECT id,status,created_at,last_msg_at FROM site_chats WHERE user_id=? ORDER BY COALESCE(last_msg_at,created_at) DESC");
  $rooms->execute([$uid]); echo json_encode(['ok'=>1,'rooms'=>$rooms->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

/* ---------- ใหม่: ลูกค้าดึง/ส่งในห้องที่เลือก (สำหรับ my_chats.php) ---------- */
if ($action==='customer_fetch') {
  require_login();
  $uid = (int)current_user()['id'];
  $id = (int)($_GET['id'] ?? 0);
  $after = (int)($_GET['after'] ?? 0);

  $own = $pdo->prepare("SELECT COUNT(*) FROM site_chats WHERE id=? AND user_id=?");
  $own->execute([$id,$uid]);
  if (!$id || !(int)$own->fetchColumn()) { echo json_encode(['ok'=>0,'err'=>'forbidden']); exit; }

  $q = $pdo->prepare("SELECT id,sender,message,created_at FROM site_chat_messages WHERE chat_id=? AND id>? ORDER BY id ASC");
  $q->execute([$id,$after]); echo json_encode(['ok'=>1,'messages'=>$q->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

if ($action==='customer_send') {
  require_login();
  if (!csrf_check($_POST['csrf'] ?? '')) { echo json_encode(['ok'=>0,'err'=>'csrf']); exit; }

  $uid = (int)current_user()['id'];
  $id = (int)($_POST['id'] ?? 0);
  $msg = trim($_POST['message'] ?? '');

  $own = $pdo->prepare("SELECT COUNT(*) FROM site_chats WHERE id=? AND user_id=?");
  $own->execute([$id,$uid]);
  if (!$id || !(int)$own->fetchColumn() || $msg==='') { echo json_encode(['ok'=>0]); exit; }

  $pdo->prepare("INSERT INTO site_chat_messages(chat_id,sender,message) VALUES(?, 'customer', ?)")
      ->execute([$id,$msg]);
  $pdo->prepare("UPDATE site_chats SET last_msg_at=NOW() WHERE id=?")->execute([$id]);
  echo json_encode(['ok'=>1]); exit;
}

/* ---------- ฝั่งแอดมิน (คงเดิม) ---------- */
if ($action==='admin_list') {
  require_admin();
  $rows=$pdo->query("
    SELECT c.*, u.name AS user_name, u.email AS user_email,
           (SELECT message FROM site_chat_messages m WHERE m.chat_id=c.id ORDER BY id DESC LIMIT 1) AS last_msg
    FROM site_chats c LEFT JOIN users u ON u.id=c.user_id
    ORDER BY COALESCE(c.last_msg_at,c.created_at) DESC LIMIT 200
  ")->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['ok'=>1,'rows'=>$rows]); exit;
}
if ($action==='admin_fetch') {
  require_admin();
  $id=(int)($_GET['id'] ?? 0);
  $q=$pdo->prepare("SELECT id,sender,message,created_at FROM site_chat_messages WHERE chat_id=? ORDER BY id ASC");
  $q->execute([$id]); echo json_encode(['ok'=>1,'messages'=>$q->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action==='admin_send') {
  require_admin();
  $id=(int)($_POST['id'] ?? 0); $msg=trim($_POST['message'] ?? '');
  if($id && $msg!==''){
    $pdo->prepare("INSERT INTO site_chat_messages(chat_id,sender,message) VALUES(?, 'admin', ?)")->execute([$id,$msg]);
    $pdo->prepare("UPDATE site_chats SET last_msg_at=NOW() WHERE id=?")->execute([$id]);
  }
  echo json_encode(['ok'=>1]); exit;
}

echo json_encode(['ok'=>0,'err'=>'no-action']);
