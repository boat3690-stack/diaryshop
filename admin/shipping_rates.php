<?php
require_once __DIR__ . '/../config/config.php';
require_admin();
$method = (int)($_GET['method'] ?? 0);
$m=$pdo->prepare("SELECT * FROM shipping_methods WHERE id=?"); $m->execute([$method]); $m=$m->fetch();
if(!$m || $m['type']!=='table'){ echo 'method not found or not table'; exit; }

$action=$_POST['action'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST' && !csrf_check($_POST['csrf']??'')){ flash('error','CSRF'); redirect('shipping_rates.php?method='.$method); }

if($action==='add'){
  $pdo->prepare("INSERT INTO shipping_rates(method_id,zone_id,weight_from_g,weight_to_g,rate) VALUES (?,?,?,?,?)")
      ->execute([$method,(int)$_POST['zone_id'], (int)$_POST['wf'], (int)$_POST['wt'], (float)$_POST['rate']]);
  flash('success','เพิ่มเรตแล้ว'); redirect('shipping_rates.php?method='.$method);
}
if($action==='delete'){
  $pdo->prepare("DELETE FROM shipping_rates WHERE id=?")->execute([(int)$_POST['id']]);
  flash('success','ลบเรตแล้ว'); redirect('shipping_rates.php?method='.$method);
}

$zones=$pdo->query("SELECT * FROM shipping_zones ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$rates=$pdo->prepare("SELECT r.*, z.name zone_name FROM shipping_rates r JOIN shipping_zones z ON z.id=r.zone_id WHERE r.method_id=? ORDER BY z.id, weight_from_g");
$rates->execute([$method]); $rates=$rates->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/header.php';
?>
<h2>เรตน้ำหนัก · <?= htmlspecialchars($m['name']) ?></h2>
<div class="grid" style="grid-template-columns:1fr 2fr; gap:1rem">
  <div class="card">
    <h3>เพิ่มเรต</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <label>โซน</label>
      <select class="input" name="zone_id">
        <?php foreach($zones as $z): ?>
          <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>จาก (g)</label><input class="input" name="wf" type="number" value="0">
      <label>ถึง (g)</label><input class="input" name="wt" type="number" value="1000">
      <label>ราคา (บาท)</label><input class="input" name="rate" type="number" step="0.01" value="40">
      <button class="btn">บันทึก</button>
    </form>
  </div>
  <div class="card">
    <h3>รายการเรต</h3>
    <table class="table">
      <tr><th>โซน</th><th>ช่วง (g)</th><th>ราคา</th><th>ลบ</th></tr>
      <?php foreach($rates as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['zone_name']) ?></td>
          <td><?= (int)$r['weight_from_g'] ?> - <?= (int)$r['weight_to_g'] ?></td>
          <td>฿<?= format_currency($r['rate']) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('ลบเรตนี้?')" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn outline">ลบ</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
