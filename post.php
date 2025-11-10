<?php
// post.php — หน้าอ่านบทความสาธารณะ
// [แก้ไข] แก้ไข Path ให้ถูกต้อง
require_once __DIR__ . '/config/config.php';

/* helper: escape ปลอดภัย */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* รับพารามิเตอร์: slug (แนะนำ) หรือ id (สำรอง) */
$slug = trim((string)($_GET['slug'] ?? ''));
$id   = (int)($_GET['id'] ?? 0);

/* ดึงบทความ */
$st = null; $post = null;
if ($slug !== '') {
  $st = $pdo->prepare("SELECT * FROM posts WHERE slug=? LIMIT 1");
  $st->execute([$slug]);
  $post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($id > 0) {
  $st = $pdo->prepare("SELECT * FROM posts WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ถ้าไม่พบ หรือยังไม่เผยแพร่ (และไม่ได้เป็นแอดมิน) -> 404 */
if (!$post || (empty($post['is_published']) && !is_admin())) {
  http_response_code(404);
  include __DIR__ . '/partials/header.php';
  echo '<h2>ไม่พบบทความ</h2><div class="card">ขออภัย ไม่พบบทความที่คุณต้องการ</div>';
  include __DIR__ . '/partials/footer.php';
  exit;
}

/* เตรียมข้อมูล */
$store_name = get_setting($pdo, 'store_name', 'PHP Shop');
$title      = (string)$post['title'];
$desc       = mb_substr(strip_tags((string)$post['content']), 0, 160);
$imgPath    = trim((string)($post['image'] ?? ''));
$imgUrl     = $imgPath !== '' ? (rtrim(BASE_URL, '/').'/'.ltrim($imgPath, '/')) : '';
$canonical  = rtrim(BASE_URL, '/').'/post.php?slug='.rawurlencode((string)$post['slug']);
$created    = $post['created_at'] ?? null;
$updated    = $post['updated_at'] ?? $created;

/* แทรก meta พิเศษก่อนโหลด header */
ob_start(); ?>
  <meta name="description" content="<?= h($desc) ?>">
  <link rel="canonical" href="<?= h($canonical) ?>">
  <meta property="og:type" content="article">
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($desc) ?>">
  <?php if ($imgUrl): ?><meta property="og:image" content="<?= h($imgUrl) ?>"><?php endif; ?>
  <meta property="og:url" content="<?= h($canonical) ?>">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>,
    "datePublished": <?= json_encode($created) ?>,
    "dateModified": <?= json_encode($updated) ?>,
    "image": <?= json_encode($imgUrl ?: null) ?>,
    "author": { "@type": "Organization", "name": <?= json_encode($store_name, JSON_UNESCAPED_UNICODE) ?> }
  }
  </script>
<?php $extra_head = ob_get_clean();

/* header.php จะใช้ <title> จากชื่อร้าน เลยเราตั้งผ่านตัวแปร */
$page_title_prefix = $title . ' · ';

include __DIR__ . '/partials/header.php';
?>
<?= $extra_head ?? '' ?>

<style>
/* ==== FULL WIDTH เฉพาะหน้านี้ ==== */
body .container, .container { 
  max-width: 100% !important; 
  width: 100% !important;
  padding-left: 1rem;
  padding-right: 1rem;
}

.post-wrap{max-width:900px;margin:0 auto}
.post-hero{border-radius:12px;overflow:hidden;margin:.5rem 0 1rem}
.post-hero img{width:100%;height:auto;display:block}
.post-title{margin:.25rem 0 .5rem;font-size:clamp(1.6rem, 2vw + 1rem, 2.2rem)}
.post-meta{opacity:.75;font-size:.9rem;margin-bottom:1rem}
.post-body{font-size:1.02rem;line-height:1.75}
.post-body img{max-width:100%;height:auto;border-radius:8px}
.post-body h2,.post-body h3{margin-top:1.2em}
.share-row{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem}
</style>

<div class="post-wrap">
  <article class="card" style="padding:1rem 1.2rem">
    <?php if ($imgUrl): ?>
      <div class="post-hero"><img src="<?= h($imgUrl) ?>" alt="<?= h($title) ?>"></div>
    <?php endif; ?>

    <h1 class="post-title"><?= h($title) ?></h1>
    <div class="post-meta">
      เผยแพร่: <?= h($created ?: '') ?>
      <?php if (!empty($updated) && $updated !== $created): ?>
        · อัปเดต: <?= h($updated) ?>
      <?php endif; ?>
      <?php if (!empty($post['is_published']) === 0): ?>
        <span class="badge">ฉบับร่าง</span>
      <?php endif; ?>
    </div>

    <div class="post-body">
      <?= (string)$post['content'] /* อนุญาต HTML ที่บันทึกจากแอดมิน */ ?>
    </div>

    <div class="share-row">
      <a class="btn outline" href="<?= h($canonical) ?>">ลิงก์ถาวร</a>
      <a class="btn outline" href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($canonical) ?>" target="_blank" rel="noopener">แชร์ Facebook</a>
      <a class="btn outline" href="https://twitter.com/intent/tweet?url=<?= urlencode($canonical) ?>&text=<?= urlencode($title) ?>" target="_blank" rel="noopener">แชร์ X/Twitter</a>
      <a class="btn outline" href="https://line.me/R/msg/text/?<?= urlencode($title.' '.$canonical) ?>" target="_blank" rel="noopener">แชร์ LINE</a>
    </div>
  </article>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>