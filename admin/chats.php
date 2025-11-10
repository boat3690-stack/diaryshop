<?php
require_once __DIR__ . '/../config/config.php';
require_admin();
include __DIR__ . '/../partials/header.php';
?>
<h2>แชทลูกค้า</h2>
<div class="grid" style="grid-template-columns:340px 1fr;gap:1rem">
  <div class="card">
    <h3>ห้องแชท</h3>
    <ul id="chatList" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.5rem"></ul>
  </div>
  <div class="card">
    <h3 id="roomTitle">เลือกห้องแชท</h3>
    <div id="chatBox" style="height:420px;overflow:auto;background:#0e0e24;border-radius:.6rem;padding:.6rem"></div>
    <form id="sendForm" style="display:flex;gap:.5rem;margin-top:.5rem">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="cid">
      <input class="input" name="message" id="cmsg" placeholder="พิมพ์ตอบ..." style="flex:1">
      <button class="btn" type="submit">ส่ง</button>
    </form>
  </div>
</div>

<script>
let cur=0;

async function loadRooms(){
  const res = await fetch('../chat_api.php?action=admin_list');
  const j = await res.json(); const ul=document.getElementById('chatList'); ul.innerHTML='';
  j.rows.forEach(r=>{
    const who = r.user_name ? `${r.user_name} (${r.user_email||'-'})` : (r.name||'ผู้เยี่ยมชม');
    const li=document.createElement('li');
    li.innerHTML = `
      <a class="btn outline" href="#" data-id="${r.id}">
        #${r.id} · ${who}
        <div class="small" style="opacity:.8">${r.last_msg||''}</div>
      </a>`;
    li.querySelector('a').onclick=(e)=>{e.preventDefault(); openRoom(r.id, who);};
    ul.appendChild(li);
  });
}
async function openRoom(id, who){
  cur=id;
  document.getElementById('cid').value=id;
  document.getElementById('roomTitle').textContent='ห้อง #' + id + (who?(' · '+who):'');
  await pull();
}
async function pull(){
  if(!cur) return;
  const res=await fetch('../chat_api.php?action=admin_fetch&id='+cur);
  const j=await res.json(); const box=document.getElementById('chatBox'); box.innerHTML='';
  j.messages.forEach(m=>{
    const div=document.createElement('div');
    div.className='small'; div.style.margin='.25rem 0';
    div.innerHTML = (m.sender==='admin' ? '<b>ฉัน:</b> ' : '<b>ลูกค้า:</b> ') + m.message;
    box.appendChild(div);
  });
  box.scrollTop = box.scrollHeight;
}
document.getElementById('sendForm').addEventListener('submit', async (e)=>{
  e.preventDefault(); if(!cur) return;
  const fd=new FormData(e.target);
  await fetch('../chat_api.php?action=admin_send',{method:'POST',body:fd});
  document.getElementById('cmsg').value=''; pull();
});

loadRooms(); setInterval(loadRooms, 5000); setInterval(pull, 3000);
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
