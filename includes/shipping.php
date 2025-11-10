<?php
// includes/shipping.php
if (!isset($pdo)) { require_once __DIR__.'/../config/config.php'; }

/** น้ำหนักรวม (เลือก max(น้ำหนักจริง, ปริมาตร)) */
function calc_cart_weight_g(PDO $pdo, array $items): int {
  // $items: [ [product_id, qty], ... ]
  $total_real = 0; $total_vol = 0;
  $st = $pdo->prepare("SELECT id, weight_gram, length_cm, width_cm, height_cm FROM products WHERE id=?");
  foreach ($items as $it) {
    $pid=(int)$it[0]; $qty=(int)$it[1]; if($qty<=0) continue;
    $st->execute([$pid]); $p=$st->fetch();
    $w = (int)($p['weight_gram'] ?? 0);
    $L = (float)($p['length_cm'] ?? 0);
    $W = (float)($p['width_cm'] ?? 0);
    $H = (float)($p['height_cm'] ?? 0);
    $vw = (int)round(($L*$W*$H)/5000*1000); // (cm) -> vol weight (g) divisor 5000
    $total_real += $w * $qty;
    $total_vol  += $vw * $qty;
  }
  return max($total_real, $total_vol);
}

/** หา zone จากชื่อจังหวัด */
function resolve_zone_id(PDO $pdo, string $province): ?int {
  $st=$pdo->prepare("SELECT zone_id FROM shipping_zone_provinces WHERE province=? LIMIT 1");
  $st->execute([trim($province)]);
  $z=$st->fetchColumn();
  if ($z) return (int)$z;
  // fallback: BKK ถ้าชื่อมี "กรุงเทพ"
  if (mb_strpos($province,'กรุงเทพ')!==false){
    $z=$pdo->query("SELECT id FROM shipping_zones WHERE code='BKK'")->fetchColumn();
    if ($z) return (int)$z;
  }
  return (int)$pdo->query("SELECT id FROM shipping_zones ORDER BY id LIMIT 1")->fetchColumn();
}

/** คืนรายการ quote ทั้งหมดสำหรับปลายทาง */
function shipping_quotes(PDO $pdo, string $province, string $postcode, array $items): array {
  $zone_id = resolve_zone_id($pdo, $province);
  $weight  = calc_cart_weight_g($pdo, $items);

  $rows = $pdo->query("SELECT * FROM shipping_methods WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
  $out=[];
  foreach($rows as $m){
    $rate = 0.0;

    if ($m['type']==='flat'){
      $rate = (float)$m['base_rate'];
    } elseif ($m['type']==='table'){
      $st=$pdo->prepare("SELECT rate FROM shipping_rates WHERE method_id=? AND zone_id=? AND ? BETWEEN weight_from_g AND weight_to_g ORDER BY weight_to_g LIMIT 1");
      $st->execute([(int)$m['id'], $zone_id, $weight]);
      $r=$st->fetchColumn();
      if ($r===false){
        // เลือกแถวที่ใกล้สุด (over-weight ใช้แถวบนสุดสุดท้าย)
        $st2=$pdo->prepare("SELECT rate FROM shipping_rates WHERE method_id=? AND zone_id=? ORDER BY weight_to_g DESC LIMIT 1");
        $st2->execute([(int)$m['id'],$zone_id]); $r=$st2->fetchColumn();
      }
      $rate = (float)$r;
    } elseif ($m['type']==='api'){
      // ตัวอย่าง: เลือกระหว่าง J&T / Flash
      if ($m['carrier']==='jt'){
        $rate = (float) jt_rate_quote($pdo, $province, $postcode, $weight, $m);
      } elseif ($m['carrier']==='flash'){
        $rate = (float) flash_rate_quote($pdo, $province, $postcode, $weight, $m);
      }
    }

    // handling + markup
    $rate = max(0, $rate + (float)$m['handling_fee']);
    if ((float)$m['markup_percent']>0){
      $rate += $rate * ((float)$m['markup_percent']/100.0);
    }

    $out[] = [
      'id'    => (int)$m['id'],
      'code'  => $m['code'],
      'name'  => $m['name'],
      'amount'=> round($rate,2),
      'weight_g'=>$weight
    ];
  }
  return $out;
}

/** === J&T API (ตัวอย่างโครง, ใส่ key/secret ใน settings) === */
function jt_rate_quote(PDO $pdo, string $province, string $postcode, int $weight, array $methodRow){
  // ปกติ J&T มี endpoint คำนวณค่าส่ง/เช็กพื้นที่; ที่นี่ทำ mock ถ้าไม่มี key จะคืน 0
  $key = get_setting($pdo,'carrier_jt_key','');
  if ($key==='') return 0;
  // TODO: เขียน cURL เรียก API จริงที่นี่
  return 45 + max(0, ceil(($weight-1000)/1000))*10; // mock tier
}

/** === Flash Express API (ตัวอย่างโครง) === */
function flash_rate_quote(PDO $pdo, string $province, string $postcode, int $weight, array $methodRow){
  $key = get_setting($pdo,'carrier_flash_key','');
  if ($key==='') return 0;
  // TODO: cURL ไป endpoint Flash
  return 40 + max(0, ceil(($weight-1000)/1000))*12; // mock tier
}

/** สร้างพัสดุกับ carrier (ได้ tracking/label) */
function create_shipment_for_order(PDO $pdo, int $order_id): array {
  // อ่านคำสั่งซื้อ
  $st=$pdo->prepare("SELECT o.*, sm.code AS sm_code, sm.carrier, sm.type
                     FROM orders o LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
                     WHERE o.id=?");
  $st->execute([$order_id]); $o=$st->fetch();
  if(!$o) return ['ok'=>0,'err'=>'order_not_found'];
  if(!$o['shipping_method_id']) return ['ok'=>0,'err'=>'no_shipping_method'];

  // ถ้าเป็น API ค่อยยิงสร้าง label/เลข
  $track=''; $label='';
  if ($o['type']==='api'){
    if ($o['carrier']==='jt'){
      // TODO: เรียก J&T create shipment
      $track = 'JT'.date('ymd').$order_id;
      $label = ''; // url label pdf ถ้ามี
    } elseif ($o['carrier']==='flash'){
      // TODO: เรียก Flash create shipment
      $track = 'FX'.date('ymd').$order_id;
      $label = '';
    }
  }

  if ($track!==''){
    $pdo->prepare("UPDATE orders SET tracking_no=?, shipping_label_url=?, updated_at=NOW() WHERE id=?")
        ->execute([$track,$label,$order_id]);
  }
  return ['ok'=>1,'tracking_no'=>$track,'label_url'=>$label];
}
