<?php
require_once __DIR__ . '/config/config.php';
$pg = max(1,(int)($_GET['page'] ?? 1)); $pp=10; $off=($pg-1)*$pp;
$rows = $pdo->prepare("SELECT * FROM posts WHERE is_published=1 ORDER BY created_at DESC LIMIT $pp OFFSET $off");
$rows->execute();
$cnt  = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE is_published=1")->fetchColumn();
$total = max(1,(int)ceil($cnt/$pp));
include __DIR__.'/partials/header.php';
?>
<h2>บล็อก/ข่าวสาร</h2>
<style>
/* ==== FULL WIDTH เฉพาะหน้านี้ ==== */
body .container, .container { 
  max-width: 100% !important; 
  width: 100% !important;
  padding-left: 1rem;
  padding-right: 1rem;
}
</style>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
<?php foreach($rows as $p): $img=$p['image']?BASE_URL.'/'.ltrim($p['image'],'/'):BASE_URL.'/assets/images/noimg.png'; ?>
  <a class="card" href="post.php?slug=<?= urlencode($p['slug']) ?>">
    <img src="<?= htmlspecialchars($img) ?>" alt="" style="width:100%;height:160px;object-fit:cover;border-radius:.6rem">
    <h3 style="margin:.6rem 0"><?= htmlspecialchars($p['title']) ?></h3>
    <div class="small"><?= htmlspecialchars(substr(strip_tags($p['content']),0,120)) ?>...</div>
  </a>
<?php endforeach; ?>
</div>
<?php if($total>1): ?>
<div class="pagination" style="margin-top:1rem;display:flex;gap:.4rem">
  <?php for($i=1;$i<=$total;$i++): ?>
    <a class="btn <?= $i===$pg?'':'outline' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php include __DIR__.'/partials/footer.php'; ?>
