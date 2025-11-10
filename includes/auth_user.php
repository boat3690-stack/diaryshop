<?php
// includes/auth_user.php

function norm_phone(?string $s): ?string {
  $s = preg_replace('/\D+/', '', (string)$s);
  if ($s === '') return null;
  // ไทย: แปลง 66xxxxxxxxx -> 0xxxxxxxxx
  if (strpos($s, '66') === 0 && strlen($s) >= 11) {
    $s = '0'.substr($s, 2);
  }
  return $s;
}
function password_ok(string $pw): bool {
  return strlen($pw) >= 8 && preg_match('/[A-Za-z]/',$pw) && preg_match('/\d/',$pw);
}
function find_user_by_email_or_phone(PDO $pdo, string $id) {
  if (filter_var($id, FILTER_VALIDATE_EMAIL)) {
    $st=$pdo->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $st->execute([strtolower($id)]);
  } else {
    $ph = norm_phone($id);
    $st=$pdo->prepare('SELECT * FROM users WHERE phone=? LIMIT 1'); $st->execute([$ph]);
  }
  return $st->fetch();
}
function set_login_session(array $u): void {
  $_SESSION['user'] = [
    'id'    => (int)$u['id'],
    'email' => $u['email'] ?? null,
    'phone' => $u['phone'] ?? null,
    'name'  => $u['name']  ?? null,
    'role'  => $u['role']  ?? 'customer',
  ];
}

// ----- brute-force throttle (อิงตาราง auth_attempts ถ้ามี) -----
function throttle_check(PDO $pdo, string $ip, string $key): bool {
  try{
    $st=$pdo->prepare('SELECT COUNT(*) FROM auth_attempts WHERE ip=? AND user_key=? AND created_at>=NOW()-INTERVAL 5 MINUTE AND ok=0');
    $st->execute([$ip,$key]);
    $fails = (int)$st->fetchColumn();
    return $fails < 10; // เกิน 10 ครั้ง/5 นาที: บล็อก
  }catch(Throwable $e){ return true; }
}
function throttle_log(PDO $pdo, string $ip, string $key, bool $ok): void {
  try{
    $st=$pdo->prepare('INSERT INTO auth_attempts (ip,user_key,ok) VALUES (?,?,?)');
    $st->execute([$ip,$key,$ok?1:0]);
  }catch(Throwable $e){}
}

// ----- reset token helpers (อิงตาราง password_resets) -----
function create_reset_token(PDO $pdo, int $user_id): ?string {
  try{
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256',$raw);
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
        ->execute([$user_id,$hash]);
    return $raw;
  }catch(Throwable $e){ return null; }
}
function find_reset_row(PDO $pdo, string $rawToken) {
  $hash = hash('sha256',$rawToken);
  $st=$pdo->prepare('SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1');
  $st->execute([$hash]); return $st->fetch();
}
function consume_reset_token(PDO $pdo, int $row_id): void {
  $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=?')->execute([$row_id]);
}
