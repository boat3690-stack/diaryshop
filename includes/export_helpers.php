<?php
// /includes/export_helpers.php
function export_csv(string $filename, array $headers, iterable $rows): void {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename='.$filename);
  echo "\xEF\xBB\xBF"; // UTF-8 BOM (กันภาษาไทยเพี้ยนใน Excel)
  $out = fopen('php://output', 'w');
  fputcsv($out, $headers);
  foreach ($rows as $r) fputcsv($out, $r);
  fclose($out);
  exit;
}

/** พยายามสร้าง PDF ด้วย dompdf ถ้ามี; ถ้าไม่มีจะให้หน้า Print-Friendly */
function export_pdf(string $filename, string $title, string $tableHtml): void {
  $html = '<html><head><meta charset="utf-8">
  <style>
    body{font-family:Tahoma,Arial,sans-serif;font-size:12px;color:#111}
    h2{margin:0 0 .5rem 0}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #ccc;padding:.35rem .5rem;text-align:left}
    th{background:#f2f2f7}
    .small{font-size:11px;color:#666}
  </style></head><body>
  <h2>'.htmlspecialchars($title).'</h2>'.$tableHtml.'
  <div class="small">สร้างเมื่อ '.date('Y-m-d H:i:s').'</div>
  </body></html>';

  // หา autoload dompdf
  $cands = [
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../vendor/autoload.php',
    dirname(__DIR__,2).'/vendor/autoload.php',
  ];
  foreach ($cands as $a) { if (is_file($a)) { require_once $a; break; } }

  if (class_exists(\Dompdf\Dompdf::class)) {
    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename='.$filename);
    echo $dompdf->output();
    exit;
  }

  // fallback: print-friendly
  header('Content-Type: text/html; charset=utf-8');
  echo $html.'<script>window.print()</script>';
  exit;
}
