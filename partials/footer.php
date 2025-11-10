<?php // partials/footer.php ?>
    <div class="footer">© <?= date('Y') ?> <?= htmlspecialchars($store_name) ?> <br> กองวิทยบริการ กรมยุทธศึกษาทหารอากาศ </div>

  <!-- Welcome popup 
  <div id="welcomePop" style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.7);align-items:center;justify-content:center">
    <div style="background:#141432;color:#fff;border:1px solid #23234a;border-radius:1rem;max-width:520px;width:92vw;padding:1rem;position:relative">
      <button type="button" id="welcomeClose" style="position:absolute;top:.5rem;right:.5rem" class="btn outline">ปิด</button>
      <div id="welcomeHTML">
        <?= get_setting($pdo,'welcome_popup_html','<h3>ยินดีต้อนรับ</h3>') ?>
      </div>
    </div>
  </div> -->

  <!-- ===== Floating chat + LINE ===== -->
  <?php
    // ตั้งค่า LINE URL ใน Settings (key: line_url) ได้ เช่น https://line.me/R/ti/p/@yourid
    $line_url = trim(get_setting($pdo,'line_url',''));
    if ($line_url==='') { $line_url = 'https://line.me/R/ti/p/@rtafdiary'; } // fallback
  ?>

  <?php if(get_setting($pdo,'chat_enabled','1')==='1'): ?>
  <div id="chatBubble" class="chat-fab">
    <div id="chatPane" class="chat-pane">
      <div class="chat-head">แชทกับร้าน</div>
      <div id="cArea" class="chat-area"></div>
      <form id="cSend" class="chat-send">
        <?= csrf_field() ?>
        <input class="input" name="message" id="cInput" placeholder="พิมพ์ข้อความ..." style="flex:1">
        <button class="btn" type="submit">ส่ง</button>
      </form>
    </div>

    <!-- ปุ่มเปิด/ปิดแชท -->
    <!--<button class="btn" id="chatBtn" type="button">สอบถาม</button>-->

    <!-- ปุ่มติดต่อ LINE (ใช้ <a> ไม่ใช่ <button>) -->
    <a class="btn line-btn" id="lineBtn"
       href="<?= htmlspecialchars($line_url) ?>"
       target="_blank" rel="noopener"
       aria-label="ติดต่อ LINE">ติดต่อ LINE</a>
  </div>
  <?php else: ?>
  <!-- ถ้าปิดแชท แต่ยังอยากให้มีปุ่ม LINE ก็แสดงเฉพาะปุ่ม LINE -->
  <div class="chat-fab">
    <a class="btn line-btn" id="lineBtn"
       href="<?= htmlspecialchars($line_url) ?>"
       target="_blank" rel="noopener"
       aria-label="ติดต่อ LINE">ติดต่อ LINE</a>
  </div>
  <?php endif; ?>

  <script>
  /* Welcome (แสดงครั้งแรกเท่านั้น) */
  (function(){
    try{
      if(!localStorage.getItem('welcomed')){
        const pop=document.getElementById('welcomePop');
        pop.style.display='flex';
        document.getElementById('welcomeClose').onclick=function(){
          pop.style.display='none'; localStorage.setItem('welcomed','1');
        };
      }
    }catch(e){}
  })();

  /* Chat widget */
  <?php if(get_setting($pdo,'chat_enabled','1')==='1'): ?>
  (function(){
    let last = 0;
    let chat_id = 0;

    async function initWidget(){
      try{
        const res = await fetch('<?= BASE_URL ?>/chat_api.php?action=init');
        const j = await res.json();
        chat_id = j.chat_id || 0;

        // ทักชื่อผู้ใช้ (ถ้ามี)
        if (j.user && j.user.name){
          const area = document.getElementById('cArea');
          const hi = document.createElement('div');
          hi.className = 'msg sys';
          hi.innerHTML = '<span>สวัสดี ' + escapeHtml(j.user.name) + '</span>';
          area.appendChild(hi);
        }
        await pull(); // โหลดข้อความรอบแรก
      }catch(e){}
    }

    async function pull(){
      if(!chat_id) return;
      try{
        const res = await fetch('<?= BASE_URL ?>/chat_api.php?action=pull&after='+last);
        const j = await res.json();
        if (j.messages && j.messages.length){
          const box = document.getElementById('cArea');
          j.messages.forEach(m=>{
            last = Math.max(last, parseInt(m.id,10));
            const el = document.createElement('div');
            el.className = 'msg ' + (m.sender==='customer' ? 'me' : 'shop');
            el.innerHTML = '<span>'+escapeHtml(m.message)+'</span>';
            box.appendChild(el);
          });
          box.scrollTop = box.scrollHeight;
        }
      }catch(e){}
    }

    function escapeHtml(s){
      return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
    }

    // เปิด/ปิดหน้าต่างแชท (มีการ์ดกัน null)
    const chatBtnEl = document.getElementById('chatBtn');
    if (chatBtnEl){
      chatBtnEl.addEventListener('click', ()=>{
        const p = document.getElementById('chatPane');
        const opened = p && p.style.display === 'block';
        p.style.display = opened ? 'none' : 'block';
        if (!opened) pull();
      });
    }

    // ส่งข้อความ (optimistic)
    document.getElementById('cSend').addEventListener('submit', async (e)=>{
      e.preventDefault();
      const inp = document.getElementById('cInput');
      const v = inp.value.trim();
      if(!v) return;
      const fd = new FormData(e.target);
      fd.set('message', v);

      const box = document.getElementById('cArea');
      const el = document.createElement('div');
      el.className = 'msg me';
      el.innerHTML = '<span>'+escapeHtml(v)+'</span>';
      box.appendChild(el);
      box.scrollTop = box.scrollHeight;

      inp.value = '';
      try{ await fetch('<?= BASE_URL ?>/chat_api.php?action=send',{method:'POST',body:fd}); }catch(e){}
      pull();
    });

    initWidget();
    setInterval(pull, 1000);
  })();
  <?php endif; ?>

  // ยกบับเบิลหนีแถบล่างอัตโนมัติ (เช่นปุ่มชำระเงิน)
  (function(){
    function adjustChatOffset(){
      let extra = 0;
      document.querySelectorAll('body *').forEach(el=>{
        const s = getComputedStyle(el);
        if (s.position === 'fixed'){
          const r = el.getBoundingClientRect();
          const isBottomBar = r.height > 30 && r.bottom >= window.innerHeight - 1 && r.top > window.innerHeight - 240;
          if (isBottomBar && el.id !== 'chatBubble') {
            extra = Math.max(extra, r.height + 12);
          }
        }
      });
      const known = document.querySelector('.sticky-mobile, .checkout-sticky, .cart-sticky, #mobileCheckoutBar');
      if (known){
        const rr = known.getBoundingClientRect();
        extra = Math.max(extra, rr.height + 12);
      }
      document.documentElement.style.setProperty('--chat-offset', extra + 'px');
    }
    ['load','resize','scroll','orientationchange'].forEach(ev=> window.addEventListener(ev, adjustChatOffset, {passive:true}));
    const ro = new ResizeObserver(adjustChatOffset);
    ro.observe(document.body);
    adjustChatOffset();
  })();
  </script>

  <style>
    /* FAB คุมตำแหน่งและสแต็กปุ่มแนวตั้ง */
    .chat-fab{
      position: fixed;
      left: 14px;
      bottom: calc(env(safe-area-inset-bottom) + 14px + var(--chat-offset, 0px));
      z-index: 9997;
      display: flex;
      flex-direction: column;
      gap: .5rem;
    }

    /* กล่องแชท – ปรับให้ดูเป็นการ์ด และตัวอักษรสีขาวทั้งหมด */
    .chat-pane{
      display:none;
      background:#141432;
      color:#fff;
      border:1px solid #23234a;
      border-radius:1rem;
      width:300px;height:360px;overflow:hidden;margin-bottom:.25rem;
      box-shadow:0 10px 30px rgba(0,0,0,.35);
    }
    .chat-head{
      background:#1b1b3a;
      padding:.6rem .8rem;
      font-weight:600;
      border-bottom:1px solid #23234a;
    }
    .chat-area{
      height:260px;overflow:auto;
      padding:.6rem;
      display:flex;flex-direction:column;gap:.35rem;
    }
    /* ฟองข้อความ */
    #chatPane .msg{display:flex;line-height:1.4;}
    #chatPane .msg > span{
      display:inline-block;max-width:82%;
      padding:.45rem .6rem;border-radius:1rem;
      color:#fff;word-wrap:break-word;word-break:break-word;
    }
    #chatPane .msg.me{justify-content:flex-end;}
    #chatPane .msg.me  > span{background:#5b5af3;}
    #chatPane .msg.shop> span{background:#222448;}
    #chatPane .msg.sys > span{background:#2a2a4a;opacity:.9;}

    .chat-send{display:flex;gap:.4rem;padding:.5rem;background:#141432;}

    /* ช่องพิมพ์ให้มองเห็นแน่ ๆ */
    #chatPane input[type="text"],
    #chatPane textarea,
    #chatPane .input {
      color: #fff !important;
      caret-color: #fff !important;
      background: #0f1026 !important;
    }
    #chatPane input::placeholder,
    #chatPane textarea::placeholder,
    #chatPane .input::placeholder { color:#dfe3ee !important; opacity:1; }
    #chatPane input:-webkit-autofill,
    #chatPane input:-webkit-autofill:focus,
    #chatPane textarea:-webkit-autofill,
    #chatPane textarea:-webkit-autofill:focus {
      -webkit-text-fill-color: #fff !important;
      transition: background-color 9999s ease-in-out 0s;
    }

    /* ปุ่ม LINE (โทนเขียว) */
    .line-btn{
      background:#06c755;
      border-color:#06c755;
      color:#fff !important;
    }
    .line-btn:hover{ filter:brightness(.95); }

    /* มือถือ ให้กล่องแชทพอดีจอมากขึ้น */
    @media (max-width: 600px){
      .chat-pane{ width: calc(100vw - 24px); height: 60vh; }
      .chat-area{ height: calc(60vh - 100px); } /* เว้นหัว/ฟอร์มส่ง */
    }
  </style>
