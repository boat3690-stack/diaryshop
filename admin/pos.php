<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../partials/header.php';
if (function_exists('require_admin')) {
    require_admin();
}
?>
<style>
    :root { --pos-bg: #f4f4f5; --surface-bg: #ffffff; --border-color: #e4e4e7; }
    body { background-color: var(--pos-bg); }
    .pos-container { display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; height: calc(100vh - 100px); }
    .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem; overflow-y: auto; padding: 0.5rem; }
    .product-card { background: var(--surface-bg); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 0.5rem; text-align: center; cursor: pointer; transition: all 0.2s; position: relative; }
    .product-card:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    .product-card img { width: 100%; height: 100px; object-fit: cover; border-radius: 0.5rem; }
    .product-name { font-weight: 600; font-size: 0.9rem; margin-top: 0.5rem; height: 40px; overflow: hidden; }
    .product-price { color: #166534; font-weight: bold; }
    .product-stock { position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,0.6); color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; }
    .cart-panel { background: var(--surface-bg); border: 1px solid var(--border-color); border-radius: 0.75rem; display: flex; flex-direction: column; }
    .cart-items { flex-grow: 1; overflow-y: auto; padding: 0.75rem; }
    .cart-item { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
    .cart-item-info { flex-grow: 1; min-width: 0; }
    .cart-item-info .product-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; height: auto; }
    .cart-summary { padding: 1rem; border-top: 1px solid var(--border-color); }
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
    .grand-total { font-size: 1.5rem; font-weight: bold; }
    #barcode-scanner { border: 2px dashed var(--border-color); padding: 1rem; text-align: center; margin-bottom: 1rem; border-radius: 0.75rem; }
    .btn.checkout { background-color: #16a34a; color: white; border-color: #15803d; }
    .alert.error { border-color:#7f1d1d; background:#fff6f6; border-radius:.75rem; padding:.7rem .9rem; }
</style>

<div class="container" style="max-width: 100% !important;">
    <h2>Point of Sale (POS)</h2>
    <div class="pos-container">
        <div class="product-panel card">
            <div id="barcode-scanner">
                <input type="text" id="sku-input" class="input" placeholder="ยิงบาร์โค้ด หรือค้นหา SKU แล้วกด Enter...">
            </div>
            <div class="product-grid" id="product-grid">
                <p>กำลังโหลดสินค้า...</p>
            </div>
        </div>
        <div class="cart-panel">
            <div class="cart-items" id="cart-items">
                <p style="text-align: center; color: #777;">ตะกร้าว่าง</p>
            </div>
            <div class="cart-summary">
                <div class="summary-row">
                    <span>ยอดรวม</span>
                    <b id="summary-subtotal">฿0.00</b>
                </div>
                <hr>
                <div class="summary-row grand-total">
                    <span>ยอดสุทธิ</span>
                    <span id="summary-grandtotal">฿0.00</span>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-top: 1rem;">
                    <button id="btn-cash" class="btn checkout">เงินสด</button>
                    <button id="btn-qr" class="btn checkout">โอน/QR</button>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="product-card-template">
    <div class="product-card">
        <div class="product-stock"></div>
        <img src="" alt="Product Image">
        <div class="product-name"></div>
        <div class="product-price"></div>
    </div>
</template>

<template id="cart-item-template">
    <div class="cart-item">
        <div class="cart-item-info">
            <div class="product-name" style="font-weight: 600;"></div>
            <div class="product-price" style="font-size: 0.9rem;"></div>
        </div>
        <input type="number" class="input qty-input" value="1" min="1" style="width: 70px; text-align: center;">
        <button class="btn btn-remove" style="color: #b91c1c;">X</button>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const apiURL = 'pos_api.php';
    const productGrid = document.getElementById('product-grid');
    const productCardTemplate = document.getElementById('product-card-template');
    const skuInput = document.getElementById('sku-input');
    const cartItemsContainer = document.getElementById('cart-items');
    const cartItemTemplate = document.getElementById('cart-item-template');
    const btnCash = document.getElementById('btn-cash');
    const btnQr = document.getElementById('btn-qr');
    
    let allProducts = [];
    let cart = [];

    const fmt = (n) => Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    async function loadProducts() {
        try {
            const response = await fetch(`${apiURL}?action=get_products`);
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
            }
            const data = await response.json();
            if (data.success && data.products) {
                allProducts = data.products;
                productGrid.innerHTML = '';
                if (allProducts.length === 0) {
                     productGrid.innerHTML = '<p>ไม่พบสินค้าที่เปิดใช้งานอยู่</p>';
                     return;
                }
                allProducts.forEach(p => {
                    const card = productCardTemplate.content.cloneNode(true).querySelector('.product-card');
                    card.querySelector('img').src = p.image ? `../${p.image}` : '../assets/images/noimg.png';
                    card.querySelector('.product-name').textContent = p.name;
                    card.querySelector('.product-price').textContent = `฿${fmt(p.price)}`;
                    card.querySelector('.product-stock').textContent = `คงเหลือ: ${p.eff_stock}`;
                    card.addEventListener('click', () => addProductToCart(p));
                    productGrid.appendChild(card);
                });
            } else {
                 productGrid.innerHTML = `<p>ไม่พบสินค้า หรือเกิดข้อผิดพลาด: ${data.error || 'Unknown API error'}</p>`;
            }
        } catch (error) {
            console.error('Error loading products:', error);
            productGrid.innerHTML = `<div class="alert error" style="grid-column: 1 / -1;"><h4>เกิดข้อผิดพลาดในการโหลดสินค้า</h4><p style="word-break: break-all;">${error.message}</p></div>`;
        }
    }

    async function handleSkuSearch(event) {
        if (event.key !== 'Enter') return;
        const sku = skuInput.value.trim();
        if (sku === '') return;
        try {
            const response = await fetch(`${apiURL}?action=search_sku&sku=${encodeURIComponent(sku)}`);
            const data = await response.json();
            if (data.success && data.product) {
                addProductToCart(data.product);
                skuInput.value = '';
            } else {
                alert('ไม่พบสินค้าสำหรับรหัสนี้');
            }
        } catch (error) {
            console.error('Error searching SKU:', error);
            alert('เกิดข้อผิดพลาดในการค้นหา');
        }
    }

    function addProductToCart(product) {
        const itemInCart = cart.find(item => item.id === product.id);
        const currentQtyInCart = itemInCart ? itemInCart.qty : 0;
        if (currentQtyInCart >= product.eff_stock) {
            alert(`สินค้า "${product.name}" หมดสต๊อกแล้ว (คงเหลือ ${product.eff_stock} ชิ้น)`);
            return;
        }
        if (itemInCart) {
            itemInCart.qty++;
        } else {
            cart.push({ ...product, qty: 1 });
        }
        renderCart();
    }

    function renderCart() {
        cartItemsContainer.innerHTML = '';
        if (cart.length === 0) {
            cartItemsContainer.innerHTML = '<p style="text-align: center; color: #777;">ตะกร้าว่าง</p>';
        } else {
            cart.forEach(item => {
                const cartItemEl = cartItemTemplate.content.cloneNode(true).querySelector('.cart-item');
                cartItemEl.querySelector('.product-name').textContent = item.name;
                cartItemEl.querySelector('.product-price').textContent = `฿${fmt(item.price)}`;
                const qtyInput = cartItemEl.querySelector('.qty-input');
                qtyInput.value = item.qty;
                qtyInput.max = item.eff_stock;
                qtyInput.addEventListener('change', () => {
                    let newQty = parseInt(qtyInput.value, 10);
                    if (newQty > item.eff_stock) { newQty = item.eff_stock; qtyInput.value = newQty; }
                    if (newQty <= 0) { cart = cart.filter(cartItem => cartItem.id !== item.id);
                    } else { item.qty = newQty; }
                    renderCart();
                });
                cartItemEl.querySelector('.btn-remove').addEventListener('click', () => {
                    cart = cart.filter(cartItem => cartItem.id !== item.id);
                    renderCart();
                });
                cartItemsContainer.appendChild(cartItemEl);
            });
        }
        calculateSummary();
    }

    function calculateSummary() {
        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
        document.getElementById('summary-subtotal').textContent = `฿${fmt(subtotal)}`;
        document.getElementById('summary-grandtotal').textContent = `฿${fmt(subtotal)}`;
    }
    
    async function handleCheckout(paymentMethod) {
        if (cart.length === 0) {
            alert('กรุณาเพิ่มสินค้าลงในตะกร้าก่อน');
            return;
        }
        if (!confirm(`ยืนยันการชำระเงินด้วย "${paymentMethod === 'cash' ? 'เงินสด' : 'โอน/QR'}" ?`)) {
            return;
        }
        const formData = new FormData();
        formData.append('action', 'create_order');
        formData.append('payment_method', paymentMethod);
        formData.append('cart_items', JSON.stringify(cart));
        try {
            const response = await fetch(apiURL, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                const receiptInfo = data.receipt_no ? `\nเลขที่บิล: ${data.receipt_no}` : '\n(ไม่สามารถสร้างเลขที่บิลได้)';
                alert(`สร้างออเดอร์ #${data.order_id} สำเร็จ!${receiptInfo}`);
                cart = [];
                renderCart();
                skuInput.focus();
                loadProducts();
            } else {
                alert(`เกิดข้อผิดพลาด: ${data.error}`);
            }
        } catch (error) {
            console.error('Checkout error:', error);
            alert('เกิดข้อผิดพลาดในการเชื่อมต่อกับ Server');
        }
    }

    skuInput.addEventListener('keyup', handleSkuSearch);
    btnCash.addEventListener('click', () => handleCheckout('cash'));
    btnQr.addEventListener('click', () => handleCheckout('bank_transfer'));

    loadProducts();
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>