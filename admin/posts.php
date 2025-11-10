<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

function make_slug($s){ $s=trim(mb_strtolower($s)); $s=preg_replace('~[^a-z0-9ก-๙\- ]~u','',$s); $s=preg_replace('~\s+~','-',$s); return $s?:('post-'.time()); }

$act=$_GET['action'] ?? $_POST['action'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST' && !csrf_check($_POST['csrf'] ?? '')){ flash('error','CSRF invalid'); redirect('posts.php'); }

if($_SERVER['REQUEST_METHOD']==='POST' && $act==='create'){
  $title=trim($_POST['title']??''); $slug=make_slug($_POST['slug']??$title);
  $content=$_POST['content']??''; $pub = isset($_POST['is_published'])?1:0;
  $img=handle_upload($_FILES['image'] ?? [], 'uploads/posts', ['jpg','jpeg','png','gif','webp']);
  $st=$pdo->prepare("INSERT INTO posts(title,slug,content,image,is_published) VALUES(?,?,?,?,?)");
  $st->execute([$title,$slug,$content,$img,$pub]); flash('success','เพิ่มบทความแล้ว'); redirect('posts.php');
}
if($_SERVER['REQUEST_METHOD']==='POST' && $act==='update'){
  $id=(int)($_POST['id']??0); $title=trim($_POST['title']??''); $slug=make_slug($_POST['slug']??$title);
  $content=$_POST['content']??''; $pub = isset($_POST['is_published'])?1:0;
  $img=handle_upload($_FILES['image'] ?? [], 'uploads/posts', ['jpg','jpeg','png','gif','webp']);
  if($img){ $st=$pdo->prepare("UPDATE posts SET title=?,slug=?,content=?,image=?,is_published=?,updated_at=NOW() WHERE id=?");
            $st->execute([$title,$slug,$content,$img,$pub,$id]);
  } else {  $st=$pdo->prepare("UPDATE posts SET title=?,slug=?,content=?,is_published=?,updated_at=NOW() WHERE id=?");
            $st->execute([$title,$slug,$content,$pub,$id]); }
  flash('success','อัปเดตบทความแล้ว'); redirect('posts.php');
}
if($act==='delete' && isset($_GET['id'])){ $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([(int)$_GET['id']]); flash('success','ลบบทความแล้ว'); redirect('posts.php'); }

include __DIR__ . '/../partials/header.php';
?>
<h2>บล็อก/ข่าวสาร (เมื่อสร้างโพสต์หรือแก้ไขต้องอัปโหลดรูปทุกครั้ง)</h2>
<div class="grid" style="grid-template-columns:1fr 2fr">
  <div class="card">
    <h3>เพิ่มบทความ</h3>
    <form method="post" enctype="multipart/form-data" action="posts.php?action=create">
      <?= csrf_field() ?>
      <label>หัวเรื่อง</label><input class="input" name="title" required>
      <label>Slug (URL)</label><input class="input" name="slug" placeholder="ปล่อยว่างให้สร้างอัตโนมัติ">
      <label>เนื้อหา (HTML ได้)</label><textarea class="input" name="content" rows="8"></textarea>
      <label>รูปปก</label><input class="input" type="file" name="image" accept="image/*">
      <label><input type="checkbox" name="is_published" checked> เผยแพร่</label>
      <button class="btn">บันทึก</button>
    </form>
  </div>

  <div class="card">
    <h3>รายการบทความ</h3>
    <table class="table">
      <tr><th>#</th><th>หัวเรื่อง</th><th>เผยแพร่</th><th>จัดการ</th></tr>
      <?php foreach($pdo->query("SELECT * FROM posts ORDER BY id DESC") as $p): ?>
        <tr>
          <td><?= (int)$p['id'] ?></td>
          <td><?= htmlspecialchars($p['title']) ?></td>
          <td><?= $p['is_published']?'✅':'❌' ?></td>
          <td>
            <a class="btn outline" href="posts.php?action=edit&id=<?= (int)$p['id'] ?>">แก้ไข</a>
            <a class="btn outline" href="posts.php?action=delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('ลบ?')">ลบ</a>
            <a class="btn outline" target="_blank" href="<?= BASE_URL ?>/post.php?slug=<?= urlencode($p['slug']) ?>">ดูหน้า</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<?php if(($act==='edit') && isset($_GET['id'])):
  $id=(int)$_GET['id']; $st=$pdo->prepare("SELECT * FROM posts WHERE id=?"); $st->execute([$id]); $r=$st->fetch();
?>
<div class="card">
  <h3>แก้ไขบทความ #<?= (int)$r['id'] ?></h3>
  <form method="post" enctype="multipart/form-data" action="posts.php?action=update">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <label>หัวเรื่อง</label><input class="input" name="title" value="<?= htmlspecialchars($r['title']) ?>" required>
    <label>Slug</label><input class="input" name="slug" value="<?= htmlspecialchars($r['slug']) ?>">
    <label>เนื้อหา (HTML ได้)</label><textarea class="input" name="content" rows="10"><?= htmlspecialchars($r['content']) ?></textarea>
    <label>รูปปก (อัปโหลดใหม่เพื่อเปลี่ยน)</label><input class="input" type="file" name="image" accept="image/*">
    <label><input type="checkbox" name="is_published" <?= $r['is_published']?'checked':'' ?>> เผยแพร่</label>
    <button class="btn">อัปเดต</button>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>