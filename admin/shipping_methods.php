<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST' && !csrf_check($_POST['csrf']??'')){ flash('error','CSRF'); redirect('shipping_methods.php'); }

if($action==='create'){
  $st=$pdo->prepare("INSERT INTO shipping_methods(code,name,type,carrier,is_active,base_rate,handling_fee,markup_percent) VALUES(?,?,?,?,?,?,?,?)");
  $st->execute([
    trim($_POST['code']), trim($_POST['name']), $_POST['type'], $_POST['carrier'],
    isset($_POST['is_active'])?1:0, (float)$_POST['base_rate'], (float)$_POST['handling_fee'], (float)$_POST['markup_percent']
  ]);
  flash('success','เพิ่มวิธีส่งแล้ว'); redirect('shipping_methods.php');
}
if($action==='update'){
  $id=(int)$_POST['id'];
  $st=$pdo->prepare("UPDATE shipping_methods SET code=?,name=?,type=?,carrier=?,is_active=?,base_rate=?,handling_fee=?,markup_percent=? WHERE id=?");
  $st->execute([
    trim($_POST['code']), trim($_POST['name']), $_POST['type'], $_POST['carrier'],
    isset($_POST['is_active'])?1:0, (float)$_POST['base_rate'], (float)$_POST['handling_fee'], (float)$_POST['markup_percent'], $id
  ]);
  flash('success','อัปเดตแล้ว'); redirect('shipping_methods.php');
}
if($action==='delete'){
  $id=(int)$_GET['id']; $pdo->prepare("DELETE FROM shipping_methods WHERE id=?")->execute([$id]);
  flash('success','ลบแล้ว'); redirect('shipping_methods.php');
}

$rows=$pdo->query("SELECT * FROM shipping_methods ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/../partials/header.php';
?>
<h2>วิธีจัดส่ง</h2>
<div class="grid" style="grid-template-columns:1fr 2fr; gap:1rem">
  <div class="card">
    <h3>เพิ่ม</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <label>Code</label><input class="input" name="code" required>
      <label>ชื่อ</label><input class="input" name="name" required>
      <label>ชนิด</label>
      <select class="input" name="type">
        <option value="flat">Flat</option>
        <option value="table">Table (ตามเรต)</option>
        <option value="api">API</option>
      </select>
      <label>Carrier</label>
      <select class="input" name="carrier">
        <option value="other">อื่นๆ</option>
        <option value="jt">J&T</option>
        <option value="flash">Flash Express</option>
      </select>
      <label><input type="checkbox" name="is_active" checked> ใช้งาน</label>
      <label>Base rate</label><input class="input" type="number" step="0.01" name="base_rate" value="0">
      <label>Handling fee</label><input class="input" type="number" step="0.01" name="handling_fee" value="0">
      <label>Markup %</label><input class="input" type="number" step="0.01" name="markup_percent" value="0">
      <button class="btn">บันทึก</button>
    </form>
  </div>

  <div class="card">
    <h3>รายการ</h3>
    <table class="table">
      <tr><th>#</th><th>code</th><th>ชื่อ</th><th>ชนิด</th><th>carrier</th><th>active</th><th>เรต</th><th>จัดการ</th></tr>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['code']) ?></td>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td><?= htmlspecialchars($r['type']) ?></td>
          <td><?= htmlspecialchars($r['carrier']) ?></td>
          <td><?= $r['is_active']?'Y':'N' ?></td>
          <td>
            <?php if($r['type']==='table'): ?>
              <a class="btn outline" href="shipping_rates.php?method=<?= (int)$r['id'] ?>">เรต</a>
            <?php else: ?>-
            <?php endif; ?>
          </td>
          <td>
            <form method="post" style="display:inline-flex;gap:.4rem;flex-wrap:wrap">
              <?= csrf_field() ?><input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input class="input" name="code" value="<?= htmlspecialchars($r['code']) ?>" style="width:6rem">
              <input class="input" name="name" value="<?= htmlspecialchars($r['name']) ?>" style="width:10rem">
              <select class="input" name="type">
                <?php foreach(['flat','table','api'] as $t): ?>
                  <option value="<?= $t ?>" <?= $r['type']===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
              <select class="input" name="carrier">
                <?php foreach(['other','jt','flash'] as $t): ?>
                  <option value="<?= $t ?>" <?= $r['carrier']===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
              <label><input type="checkbox" name="is_active" <?= $r['is_active']?'checked':'' ?>> active</label>
              <input class="input" type="number" step="0.01" name="base_rate" value="<?= htmlspecialchars($r['base_rate']) ?>" style="width:6rem">
              <input class="input" type="number" step="0.01" name="handling_fee" value="<?= htmlspecialchars($r['handling_fee']) ?>" style="width:6rem">
              <input class="input" type="number" step="0.01" name="markup_percent" value="<?= htmlspecialchars($r['markup_percent']) ?>" style="width:6rem">
              <button class="btn">อัปเดต</button>
              <a class="btn outline" href="shipping_methods.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('ลบ?')">ลบ</a>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
