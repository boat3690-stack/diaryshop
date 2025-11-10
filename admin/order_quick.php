<?php
// admin/order_quick.php — Quick create order (ADMIN) + cover note + payment validation

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authz.php';

require_admin();
include __DIR__ . '/../partials/header.php';

/* ---------- helpers ---------- */
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $col): bool {
    try { $pdo->query("SELECT `$col` FROM `$table` LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
  }
}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- products for dropdown ---------- */
$prodStmt = $pdo->query("SELECT id, name, price, stock FROM products WHERE is_active=1 ORDER BY name");
$allProducts = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- create quick order ---------- */
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'create_quick') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $errors[]='CSRF invalid'; }

  $cust_type  = $_POST['cust_type'] ?? 'store';                   // store | online
  $pay_method = (string)($_POST['pay_method'] ?? '');             // '' | cash | bank_transfer
  $fullname   = trim((string)($_POST['fullname'] ?? ''));
  $phone      = trim((string)($_POST['phone'] ?? ''));
  $email      = trim((string)($_POST['email'] ?? ''));
  $address    = trim((string)($_POST['address'] ?? ''));
  $note       = trim((string)($_POST['note'] ?? ''));
  $cover_opt  = (($_POST['cover_print'] ?? 'no') === 'yes');
  $cover_note = trim((string)($_POST['cover_note'] ?? ''));

  // รายการสินค้า
  $prod_ids = $_POST['prod_id'] ?? [];
  $qtys     = $_POST['qty']     ?? [];
  $lines = [];
  if (is_array($prod_ids) && is_array($qtys)){
    for($i=0;$i<count($prod_ids);$i++){
      $pid = (int)$prod_ids[$i];
      $q   = (int)$qtys[$i];
      if ($pid>0 && $q>0) $lines[] = ['id'=>$pid,'qty'=>$q];
    }
  }

  // validate
  if ($fullname==='') $errors[]='กรอกชื่อผู้รับ';
  if ($phone==='')    $errors[]='กรอกเบอร์โทร';
  if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) $errors[]='อีเมลไม่ถูกต้อง';
  if ($cust_type==='online' && $address==='') $errors[]='กรอกที่อยู่จัดส่ง (ลูกค้าออนไลน์)';
  if (!$lines) $errors[]='กรุณาเลือกสินค้าอย่างน้อย 1 รายการ';
  if (!in_array($pay_method, ['cash','bank_transfer'], true)) $errors[]='กรุณาเลือกวิธีชำระเงิน';

  // build product map
  $productMap=[];
  $ids = array_values(array_unique(array_column($lines,'id')));
  if ($ids){
    $in = implode(',', array_fill(0,count($ids),'?'));
    $st = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE is_active=1 AND id IN ($in)");
    $st->execute($ids);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $productMap[(int)$r['id']] = [
        'name'=>$r['name'],
        'price'=>(float)$r['price'],
        'stock'=>(int)$r['stock'],
      ];
    }
  }

  // compute and stock check
  $subtotal=0.0; $total_qty=0; $itemsToInsert=[]; $errs=[];
  foreach($lines as $ln){
    $pid=$ln['id']; $q=$ln['qty'];
    if (!isset($productMap[$pid])) { $errs[]='ไม่พบสินค้า ID#'.$pid; continue; }
    $p=$productMap[$pid];
    if ($q > $p['stock']) {
      $errs[] = 'สต๊อคไม่พอ: '.$p['name'].' (คงเหลือ '.$p['stock'].')';
      continue;
    }
    $subtotal += $p['price'] * $q;
    $total_qty += $q;
    $itemsToInsert[] = ['pid'=>$pid,'qty'=>$q,'price'=>$p['price'],'name'=>$p['name']];
  }
  if ($errs) $errors = array_merge($errors, $errs);

  // shipping
  $shipping = 0.0;
  if ($cust_type==='online') {
    $shipping = $total_qty>0 ? (50 + max(0,$total_qty-1)*10) : 0;
  }

  // cover fee rule
  $cover_fee = 0.0;
  if ($cover_opt) $cover_fee = ($total_qty>0) ? (500 + max(0,$total_qty-50)*2) : 0.0;

  $grand_total = max(0.0, $subtotal + $shipping + $cover_fee);
  $status = ($pay_method==='cash') ? 'paid' : 'unpaid';

  if (!$errors) {
    try{
      $pdo->beginTransaction();

      $pickup_code = ($cust_type==='store') ? strtoupper(substr(bin2hex(random_bytes(4)),0,8)) : null;
      $expires     = ($status==='unpaid') ? date('Y-m-d H:i:s', time()+48*3600) : null;

      // ต่อท้ายหมายเหตุด้วยบรรทัด "พิมพ์ปก: ... | รายละเอียด: ..." (receipt.php จะอ่านบรรทัดนี้)
      $note_full = $note;
      if ($cover_opt) {
        $clean_detail = preg_replace('/\s+/', ' ', $cover_note); // ดึงเฉพาะในบรรทัดเดียว
        $note_full .= ($note_full!=='' ? "\n" : '') .
          'พิมพ์ปก: เปิดบล็อก 500 + เกิน '.max(0,$total_qty-50).' เล่ม × 2 = ' . number_format($cover_fee,2) . ' บาท'
          . ($clean_detail!=='' ? ' | รายละเอียด: '.$clean_detail : '');
      }
      $note_full .= ($note_full!=='' ? "\n" : '') . ($cust_type==='store' ? 'ลูกค้าหน้าร้าน' : 'ลูกค้าออนไลน์');

      // INSERT order
      $sql = "INSERT INTO orders
                (user_id, fullname, phone, email, address, note,
                 shipping, coupon_code, grand_total, status, expires_at, pickup_code,
                 created_at, updated_at)
              VALUES (NULL,?,?,?,?,?, ?, NULL, ?, ?, ?, ?, NOW(), NOW())";
      $pdo->prepare($sql)->execute([
        $fullname, $phone, $email, $address, $note_full,
        $shipping, $grand_total, $status, $expires, $pickup_code
      ]);
      $oid = (int)$pdo->lastInsertId();

      // ตรวจ schema order_items เสริม
      $has_cover_print = has_column($pdo, 'order_items', 'cover_print');
      $has_cover_fee   = has_column($pdo, 'order_items', 'cover_fee');
      $has_cover_note  = has_column($pdo, 'order_items', 'cover_note');

      // INSERT items (แถวแรกจะติดธงพิมพ์ปก + ค่าปก + รายละเอียด)
      $first = true;
      foreach($itemsToInsert as $it){
        $cols = ['order_id','product_id','qty','unit_price'];
        $vals = ['?','?','?','?'];
        $prm  = [$oid,$it['pid'],$it['qty'],$it['price']];

        if ($has_cover_print) { $cols[]='cover_print'; $vals[]='?'; $prm[] = ($cover_opt && $first) ? 1 : 0; }
        if ($has_cover_fee)   { $cols[]='cover_fee';   $vals[]='?'; $prm[] = ($cover_opt && $first) ? $cover_fee : 0.0; }
        if ($has_cover_note)  { $cols[]='cover_note';  $vals[]='?'; $prm[] = ($cover_opt && $first) ? $cover_note : ''; }

        $sql_i = "INSERT INTO order_items (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
        $pdo->prepare($sql_i)->execute($prm);

        $first = false;
      }

      // เก็บ subtotal/discount=0 ถ้ามีคอลัมน์
      try {
        $pdo->prepare("UPDATE orders SET subtotal=?, discount=? WHERE id=?")
            ->execute([$subtotal, 0.0, $oid]);
      } catch(Exception $e){}

      // บันทึก Payment
      $pdo->prepare('INSERT INTO payments (order_id, method, is_verified)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE method=VALUES(method), is_verified=VALUES(is_verified)')
          ->execute([$oid, $pay_method, ($pay_method==='cash')?1:0]);

      // ถ้ารับเงินสด -> หักสต๊อคทันที + ยืนยันสถานะ
      if ($pay_method==='cash') {
        $it = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id=?');
        $it->execute([$oid]);
        foreach ($it as $row) {
          $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?')
              ->execute([ (int)$row['qty'], (int)$row['product_id'] ]);
        }
        $pdo->prepare('UPDATE orders SET status="paid", updated_at=NOW() WHERE id=?')->execute([$oid]);
      }

      $pdo->commit();
      flash('success','สร้างออเดอร์ด่วน #'.$oid.' เรียบร้อย');
      header('Location: '.BASE_URL.'/admin/orders.php');
      exit;
    } catch(Exception $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[]='สร้างออเดอร์ไม่สำเร็จ: '.$e->getMessage();
    }
  }
}
?>
<style>
body .container, .container { max-width: 100% !important; width: 100% !important; padding: 0 1rem; }
.card{ border:1px solid #23234a; border-radius:1rem; padding:1rem; }
.row{ display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
@media (max-width: 900px){ .row{ grid-template-columns:1fr; } }
.input{ width:100%; border:1px solid #c9cfdd; border-radius:.75rem; padding:.5rem .75rem; background:#fff; color:#0e1530; }
select.input{ height:44px; }
.btn{ display:inline-flex; align-items:center; gap:.4rem; padding:.5rem .9rem; border:1px solid #23234a; border-radius:.75rem; background:#fff; color:#0e1530; }
.btn.outline{ background:transparent; }
.table{ width:100%; border-collapse:separate; border-spacing:0; }
.table th,.table td{ padding:.5rem; border-bottom:1px solid #e5e7eb; vertical-align:top; }
.small{ font-size:.9rem; opacity:.85; }
.badge{ display:inline-block; padding:.15rem .45rem; border-radius:.5rem; background:#222448; color:#fff; font-size:.85rem; }
.summary{ display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:.6rem; margin-top:1rem; }
.summary .box{ border:1px solid #23234a; border-radius:.75rem; padding:.75rem; }
.muted{ opacity:.75; }
</style>

<h2>สั่งออเดอร์ด่วน (สำหรับแอดมิน)</h2>

<?php if ($errors): ?>
  <div class="card" style="border-color:#8b2d2d; background:#fff6f6">
    <?php foreach($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" action="order_quick.php" id="qcForm" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create_quick">

  <div class="row">
    <div>
      <label>ประเภทลูกค้า</label>
      <select class="input" name="cust_type" id="custType">
        <option value="store">หน้าร้าน</option>
        <option value="online">ออนไลน์</option>
      </select>
    </div>
    <div>
      <label>วิธีชำระเงิน *</label>
      <select class="input" name="pay_method" id="payMethod" required>
        <option value="" selected disabled>— เลือกวิธีชำระเงิน —</option>
        <option value="cash">เงินสด</option>
        <option value="bank_transfer">โอนเงิน</option>
      </select>
      <div class="small muted">ถ้าเลือก “เงินสด” ระบบจะบันทึกเป็น “ชำระเงินแล้ว” และหักสต๊อคทันที</div>
    </div>
  </div>

  <div class="row" style="margin-top:.5rem">
    <div>
      <label>ชื่อ-นามสกุล *</label>
      <input class="input" name="fullname" required placeholder="เช่น น.ต.สมชาย ใจดี">
    </div>
    <div>
      <label>เบอร์โทร *</label>
      <input class="input" name="phone" required placeholder="เช่น 0812345678">
    </div>
  </div>

  <div id="addrWrap" class="row" style="margin-top:.5rem; display:none">
    <div style="grid-column:1 / -1">
      <label>ที่อยู่จัดส่ง (เฉพาะลูกค้าออนไลน์)</label>
      <textarea class="input" name="address" rows="2" placeholder="บ้านเลขที่, ถนน, แขวง/ตำบล, เขต/อำเภอ, จังหวัด, รหัสไปรษณีย์"></textarea>
    </div>
  </div>

  <div class="row" style="margin-top:.5rem">
    <div>
      <label>อีเมล (ถ้ามี)</label>
      <input class="input" type="email" name="email" placeholder="you@example.com">
    </div>
    <div>
      <label>ออปชั่นพิมพ์ปก</label>
      <select class="input" name="cover_print" id="coverPrint">
        <option value="no">ไม่พิมพ์ปก</option>
        <option value="yes">พิมพ์ปก (+ค่าบล็อก/ค่าเกิน)</option>
      </select>
      <div class="small muted">พิมพ์ปก: เปิดบล็อก 500 บาท (รวม 50 เล่มแรก) เกินคิด 2 บาท/เล่ม</div>
    </div>
  </div>

  <div class="row" id="coverNoteRow" style="margin-top:.5rem; display:none">
    <div style="grid-column:1 / -1">
      <label>รายละเอียดพิมพ์ปก</label>
      <textarea class="input" name="cover_note" rows="2" placeholder="เช่น ยศ-ชื่อ-นามสกุล / ตำแหน่ง ฯลฯ"></textarea>
    </div>
  </div>

  <div style="margin-top:1rem">
    <strong>สินค้าในออเดอร์</strong>
    <table class="table" id="itemsTable">
      <thead>
        <tr>
          <th style="width:45%">สินค้า</th>
          <th style="width:15%">ราคา</th>
          <th style="width:15%">คงเหลือ</th>
          <th style="width:15%">จำนวน</th>
          <th style="width:10%">ลบ</th>
        </tr>
      </thead>
      <tbody></tbody>
      <tfoot>
        <tr>
          <td colspan="5">
            <button type="button" class="btn outline" id="btnAdd">+ เพิ่มแถว</button>
          </td>
        </tr>
      </tfoot>
    </table>
    <template id="tplSelect">
      <select class="input selProd" name="prod_id[]" required>
        <option value="">— เลือกสินค้า —</option>
        <?php foreach($allProducts as $pp): ?>
          <option value="<?= (int)$pp['id'] ?>"
                  data-price="<?= h((float)$pp['price']) ?>"
                  data-stock="<?= (int)$pp['stock'] ?>">
            <?= h($pp['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </template>
  </div>

  <div class="row" style="margin-top:.5rem">
    <div style="grid-column:1 / -1">
      <label>หมายเหตุ (ถ้ามี)</label>
      <textarea class="input" name="note" rows="2" placeholder="คำอธิบายเพิ่มเติม/เงื่อนไข ฯลฯ"></textarea>
    </div>
  </div>

  <div class="summary">
    <div class="box">
      <div class="small muted">ยอดสินค้า</div>
      <div><b>฿ <span id="sumSub">0.00</span></b></div>
    </div>
    <div class="box">
      <div class="small muted">ค่าส่ง</div>
      <div><b>฿ <span id="sumShip">0.00</span></b> <span class="small muted" id="shipRule"></span></div>
    </div>
    <div class="box">
      <div class="small muted">ค่าพิมพ์ปก</div>
      <div><b>฿ <span id="sumCover">0.00</span></b> <div class="small muted" id="coverExplain"></div></div>
    </div>
    <div class="box">
      <div class="small muted">ยอดสุทธิ</div>
      <div style="font-size:1.2rem"><b>฿ <span id="sumGrand">0.00</span></b></div>
    </div>
  </div>

  <div style="margin-top:1rem">
    <button class="btn" type="submit">สร้างออเดอร์</button>
    <a class="btn outline" href="orders.php">กลับไปหน้าคำสั่งซื้อ</a>
  </div>
</form>

<script>
(function(){
  const form       = document.getElementById('qcForm');
  const custType   = document.getElementById('custType');
  const addrWrap   = document.getElementById('addrWrap');
  const payMethod  = document.getElementById('payMethod');
  const coverPrint = document.getElementById('coverPrint');
  const coverNoteRow = document.getElementById('coverNoteRow');

  const tblBody = document.querySelector('#itemsTable tbody');
  const btnAdd  = document.getElementById('btnAdd');
  const tplSel  = document.getElementById('tplSelect');

  const sumSub   = document.getElementById('sumSub');
  const sumShip  = document.getElementById('sumShip');
  const sumCover = document.getElementById('sumCover');
  const sumGrand = document.getElementById('sumGrand');
  const shipRule = document.getElementById('shipRule');
  const coverExplain = document.getElementById('coverExplain');

  function fmt(n){ return Number(n||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  function refreshAddr(){
    const online = (custType.value==='online');
    addrWrap.style.display = online ? 'block' : 'none';
    const ta = addrWrap.querySelector('textarea[name="address"]');
    if (ta) ta.required = online;
    shipRule.textContent = online ? '(ชิ้นแรก 50, ชิ้นถัดไป 10)' : '(หน้าร้านไม่คิดค่าส่ง)';
    computeTotals();
  }
  function refreshCoverNote(){
    coverNoteRow.style.display = (coverPrint.value==='yes') ? 'block' : 'none';
    computeTotals();
  }

  function addRow(){
    const tr = document.createElement('tr');

    const tdSel = document.createElement('td');
    const selFrag = tplSel.content.cloneNode(true);
    const sel = selFrag.querySelector('select');
    tdSel.appendChild(sel);

    const tdP = document.createElement('td'); const spP = document.createElement('span'); spP.textContent='—'; tdP.appendChild(spP);
    const tdS = document.createElement('td'); const spS = document.createElement('span'); spS.textContent='—'; tdS.appendChild(spS);

    const tdQ = document.createElement('td');
    const q = document.createElement('input'); q.type='number'; q.min='1'; q.step='1'; q.name='qty[]'; q.className='input'; q.placeholder='จำนวน';
    tdQ.appendChild(q);

    const tdDel = document.createElement('td');
    const btn = document.createElement('button'); btn.type='button'; btn.className='btn outline'; btn.textContent='ลบ';
    tdDel.appendChild(btn);

    tr.appendChild(tdSel); tr.appendChild(tdP); tr.appendChild(tdS); tr.appendChild(tdQ); tr.appendChild(tdDel);
    tblBody.appendChild(tr);

    function onSel(){
      const opt = sel.options[sel.selectedIndex];
      const price = opt ? parseFloat(opt.getAttribute('data-price')||'0') : 0;
      const stock = opt ? parseInt(opt.getAttribute('data-stock')||'0',10) : 0;
      spP.textContent = price ? '฿'+fmt(price) : '—';
      spS.textContent = Number.isFinite(stock) ? stock : '—';
      if (stock>0 && (!q.value || parseInt(q.value,10)<=0)) q.value = '1';
      if (stock>0) q.max = String(stock);
      computeTotals();
    }

    function onChange(){
      const max = parseInt(q.max||'0',10);
      let v = parseInt(q.value||'0',10);
      if (max>0 && v>max) q.value = String(max);
      computeTotals();
    }

    sel.addEventListener('change', onSel);
    q.addEventListener('input', onChange);
    btn.addEventListener('click', ()=>{ tr.remove(); computeTotals(); });

    onSel();
  }

  function computeTotals(){
    let subtotal=0, qty=0;
    tblBody.querySelectorAll('tr').forEach(tr=>{
      const sel = tr.querySelector('select.selProd');
      const q   = parseInt(tr.querySelector('input[name="qty[]"]')?.value || '0', 10);
      const price = parseFloat(sel?.options[sel.selectedIndex]?.getAttribute('data-price') || '0');
      if (sel && sel.value && Number.isFinite(price) && q>0){
        subtotal += price*q; qty += q;
      }
    });

    const online = (custType.value==='online');
    const shipping = online ? (qty>0 ? (50 + Math.max(0, qty-1)*10) : 0) : 0;

    const cover = (coverPrint.value==='yes');
    const cover_fee = cover ? (qty>0 ? (500 + Math.max(0, qty-50)*2) : 0) : 0;

    sumSub.textContent   = fmt(subtotal);
    sumShip.textContent  = fmt(shipping);
    sumCover.textContent = fmt(cover_fee);
    sumGrand.textContent = fmt(Math.max(0, subtotal + shipping + cover_fee));

    if (cover) {
      const over = Math.max(0, qty-50);
      coverExplain.textContent = `เปิดบล็อก 500 + เกิน ${over} เล่ม × 2`;
    } else {
      coverExplain.textContent = '';
    }
  }

  // client validations
  form.addEventListener('submit', (e)=>{
    if (!payMethod.value) {
      e.preventDefault();
      alert('กรุณาเลือกวิธีชำระเงิน');
      payMethod.focus();
    }
  });

  btnAdd?.addEventListener('click', addRow);
  custType.addEventListener('change', refreshAddr);
  coverPrint.addEventListener('change', refreshCoverNote);

  // init
  addRow();
  refreshAddr();
  refreshCoverNote();
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
