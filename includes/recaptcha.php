<?php
function recaptcha_verify($token): bool {
  if (!RECAPTCHA_SECRET || !$token) return false;
  $data = http_build_query(['secret'=>RECAPTCHA_SECRET,'response'=>$token]);
  $opts = ['http'=>['method'=>'POST','header'=>"Content-type: application/x-www-form-urlencoded\r\n",'content'=>$data,'timeout'=>10]];
  $context = stream_context_create($opts);
  $resp = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
  if ($resp === false) return false;
  $j = json_decode($resp, true);
  return !empty($j['success']);
}
