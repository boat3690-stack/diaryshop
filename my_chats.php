<?php
require_once __DIR__ . '/config/config.php';
require_login();
$u = current_user();

$rooms = $pdo->prepare("SELECT id,status,created_at,last_msg_at FROM site_chats WHERE user_id=? ORDER BY COALESCE(last_msg_at,created_at) DESC");
$rooms->execute([(int)$u['id']]); $rooms=$rooms->fetchAll(PDO::FETCH_ASSOC);

$cur = (int)($_GET['id'] ?? ($rooms[0]['id'] ?? 0));
include __DIR__ . '/partials/header.php';
?>
<h2>แชทของฉัน</h2>
<div class="grid" style="grid-template-columns:300px 1fr;gap:1rem">
  <div class="card">
    <h3>ห้องทั้งหมด</h3>
    <ul style="list-style:none;padding:0;margin:0">
      <?php foreach($rooms as $r): ?>
        <li style="margin:.35rem 0">
          <a class="btn outline" href="my_chats.php?id=<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?> · <?= htmlspecialchars($r['status']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card">
    <?php if($cur): ?>
      <h3>ห้อง #<?= (int)$cur ?></h3>
      <div id="chatBox" style="max-height:440px;overflow:auto;background:#0e0e24;border-radius:.6rem;padding:.6rem"></div>
      <form id="sendForm" style="display:flex;gap:.5rem;margin-top:.5rem">
        <?= csrf_field() ?>
        <input type="hidden" id="cid" value="<?= (int)$cur ?>">
        <input class="input" id="cmsg" placeholder="พิมพ์ข้อความ..." style="flex:1">
        <button class="btn" type="submit">ส่ง</button>
      </form>
    <?php else: ?>
      <div class="alert">ยังไม่มีประวัติแชท</div>
    <?php endif; ?>
  </div>
</div>

<?php if($cur): ?>
<script>
let last = 0;       // เก็บ message id ล่าสุด
let pulling = false;

function render(messages){
  const box = document.getElementById('chatBox');
  messages.forEach(m=>{
    last = Math.max(last, parseInt(m.id,10));
    const div=document.createElement('div');
    div.className='small'; div.style.margin='.25rem 0';
    div.innerHTML = (m.sender==='customer'?'ฉัน':'ร้าน') + ': ' + escapeHtml(m.message)
      + ' <span style="opacity:.6">· '+ escapeHtml(m.created_at) +'</span>';
    box.appendChild(div);
  });
  if(messages.length) box.scrollTop = box.scrollHeight;
}

async function pull(){
  if(pulling) return; pulling = true;
  try{
    const id = document.getElementById('cid').value;
    const res = await fetch('chat_api.php?action=customer_fetch&id='+encodeURIComponent(id)+'&after='+last);
    const j = await res.json();
    if(j.ok && j.messages){ render(j.messages); }
  }catch(e){}
  pulling = false;
}

document.getElementById('sendForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const id  = document.getElementById('cid').value;
  const msg = document.getElementById('cmsg').value.trim();
  if(!msg) return;
  const fd = new FormData();
  fd.append('id', id);
  fd.append('message', msg);
  fd.append('csrf', document.querySelector('#sendForm input[name="csrf"]').value);
  // โชว์ข้อความฝั่งลูกค้าทันที (optimistic UI)
  render([{id: String(last+1), sender:'customer', message: msg, created_at: new Date().toISOString().slice(0,19).replace('T',' ')}]);
  document.getElementById('cmsg').value='';
  try{
    await fetch('chat_api.php?action=customer_send',{method:'POST', body:fd});
  }catch(e){}
  // ดึงเผื่อมีข้อความใหม่จากร้าน
  pull();
});

function escapeHtml(s){ return s.replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

// โหลดแรกเริ่ม
(async function init(){
  await pull();
  setInterval(pull, 1000);  // ดึงทุก 1 วิ
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
