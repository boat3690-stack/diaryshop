<?php
// qr.php — proxy QR image เพื่อหลบ CSP/บล็อกภายนอก
// ใช้แหล่งหลัก qrserver และ fallback ไป google chart
// ต้องเปิด allow_url_fopen หรือมี cURL (แนะนำ cURL)

$text = isset($_GET['text']) ? (string)$_GET['text'] : '';
$text = trim($text);
if ($text === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'missing text';
  exit;
}

$srcs = [
  'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data='.rawurlencode($text),
  'https://chart.googleapis.com/chart?chs=220x220&cht=qr&chld=M|0&choe=UTF-8&chl='.rawurlencode($text),
];

function fetch_bytes($url){
  // ใช้ cURL ถ้ามี
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 8,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT => 'QRProxy/1.0'
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && $data) return $data;
    return false;
  }
  // ตกลงมาใช้ file_get_contents
  $ctx = stream_context_create(['http'=>['timeout'=>8],'https'=>['timeout'=>8]]);
  $data = @file_get_contents($url, false, $ctx);
  return $data ?: false;
}

foreach($srcs as $u){
  $img = fetch_bytes($u);
  if ($img !== false){
    header('Content-Type: image/png');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $img;
    exit;
  }
}

http_response_code(502);
header('Content-Type: text/plain; charset=utf-8');
echo 'QR service unavailable';
