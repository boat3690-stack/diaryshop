// app.js — เพิ่มสินค้าแบบเร็ว (ปุ่ม [data-add-to-cart])
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-add-to-cart]');
  if (!btn) return;

  // กันกดรัว
  if (btn.dataset.busy === '1') return;
  btn.dataset.busy = '1';

  // อ่าน id และ qty (ถ้ามี input ใกล้ ๆ ให้ใช้ค่านั้น)
  const id  = btn.dataset.id || btn.getAttribute('data-id');
  let qty   = btn.dataset.qty || 1;
  const qtyInput = btn.closest('.product-card, form, .buy')?.querySelector('input[name="qty"], input[type="number"]');
  if (qtyInput && qtyInput.value) qty = qtyInput.value;

  const params = new URLSearchParams();
  params.set('action', 'add');          // สำคัญ: ให้แน่ใจว่ามี action=add ใน POST
  params.set('id', String(id));
  params.set('qty', String(qty));
  if (window.CSRF) params.set('csrf', window.CSRF); // ถ้า header ฝั่ง PHP ใส่มา

  try {
    const res = await fetch((window.BASE_URL || '') + '/cart.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',       // ให้แน่ใจว่าส่งคุกกี้/เซสชันไปด้วย
      body: params.toString()
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    // ไปหน้าตะกร้า
    location.href = (window.BASE_URL || '') + '/cart.php';
  } catch (err) {
    console.error(err);
    alert('เพิ่มสินค้าไม่สำเร็จ ลองใหม่อีกครั้ง');
    btn.dataset.busy = '0';
  }
});
