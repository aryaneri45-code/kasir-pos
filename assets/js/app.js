let products = [];
let cart = [];
let transactionHistory = [];
let currentUser = JSON.parse(localStorage.getItem('kasir_user') || 'null');
let authToken = localStorage.getItem('kasir_token') || '';

const modal = document.getElementById('paymentModal');
const cashInput = document.getElementById('cashInput');
const modalTotal = document.getElementById('modalTotal');
const changeDisplay = document.getElementById('changeDisplay');

function formatRupiah(number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(Number(number || 0));
}

async function apiFetch(path, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {})
    };

    if (authToken) {
        headers.Authorization = `Bearer ${authToken}`;
    }

    const response = await fetch(path, { ...options, headers });
    const text = await response.text();
    let payload = {};

    try {
        payload = text ? JSON.parse(text) : {};
    } catch (error) {
        payload = { error: 'Invalid JSON response' };
    }

    if (!response.ok) {
        throw new Error(payload.error || 'Request gagal');
    }

    return payload;
}

function renderCashierBadge() {
    const cashierBadge = document.getElementById('cashierBadge');
    if (cashierBadge) {
        cashierBadge.textContent = currentUser ? `Kasir: ${currentUser.name}` : 'Kasir: -';
    }
}

function syncHeaderControls() {
    const historyBtn = document.getElementById('toggleHistoryBtn');
    const menuBtn = document.getElementById('toggleMenuBtn');

    if (historyBtn) {
        const isAdmin = canViewHistory();
        historyBtn.classList.toggle('hidden', !isAdmin);
        historyBtn.style.display = isAdmin ? 'inline-flex' : 'none';
    }

    if (menuBtn) {
        const canManage = canManageProducts();
        menuBtn.classList.toggle('hidden', !canManage);
        menuBtn.style.display = canManage ? 'inline-flex' : 'none';
        const isOpen = document.getElementById('productModal') && !document.getElementById('productModal').classList.contains('hidden');
        menuBtn.textContent = canManage ? (isOpen ? 'Tutup Edit' : 'Edit Menu') : 'Menu';
    }
}

function showApp() {
    document.getElementById('loginModal').classList.add('hidden');
    document.getElementById('appShell').classList.remove('hidden');
    renderCashierBadge();
    syncHeaderControls();
}

function showLogin() {
    document.getElementById('loginModal').classList.remove('hidden');
    document.getElementById('appShell').classList.add('hidden');
    renderCashierBadge();
    syncHeaderControls();
}

function saveSession(user, token) {
    currentUser = user;
    authToken = token;
    localStorage.setItem('kasir_user', JSON.stringify(user));
    localStorage.setItem('kasir_token', token);
    renderCashierBadge();
    syncHeaderControls();
}

function clearSession() {
    currentUser = null;
    authToken = '';
    localStorage.removeItem('kasir_user');
    localStorage.removeItem('kasir_token');
    renderCashierBadge();
    syncHeaderControls();
}

async function loadProducts() {
    const response = await apiFetch('/api/products.php');
    products = response.products || [];

    const grid = document.getElementById('productGrid');
    grid.innerHTML = products.map((product) => {
        const isOutOfStock = Number(product.stock || 0) <= 0;
        return `
            <div class="product-card ${isOutOfStock ? 'product-card-disabled' : ''}" onclick="${isOutOfStock ? 'alert(\'Stok produk habis.\')' : `addToCart(${product.id})`}">
                <div class="product-name">${product.name}</div>
                <div class="product-price">${formatRupiah(product.price)}</div>
                <div class="product-stock ${isOutOfStock ? 'out-of-stock' : ''}">
                    ${isOutOfStock ? 'Stok habis' : `Stok: ${product.stock}`}
                </div>
            </div>
        `;
    }).join('');
}

function canViewHistory() {
    return Boolean(currentUser && currentUser.role === 'admin');
}

function canManageProducts() {
    return Boolean(currentUser && currentUser.role === 'admin');
}

async function loadTransactions() {
    if (!canViewHistory()) {
        transactionHistory = [];
        renderHistory();
        renderDailyReport();
        return;
    }

    const response = await apiFetch('/api/transactions.php');
    transactionHistory = response.transactions || [];
    renderHistory();
    renderDailyReport();
}

async function loadReports() {
    const response = await apiFetch('/api/reports.php?range=daily');
    const dailyCount = document.getElementById('dailyCount');
    const dailyTotal = document.getElementById('dailyTotal');
    const dailyAverage = document.getElementById('dailyAverage');

    if (dailyCount) dailyCount.textContent = response.count || 0;
    if (dailyTotal) dailyTotal.textContent = formatRupiah(response.total || 0);
    if (dailyAverage) dailyAverage.textContent = formatRupiah(response.average || 0);
}

async function attemptLogin() {
    const username = document.getElementById('usernameInput').value.trim();
    const password = document.getElementById('passwordInput').value.trim();

    if (!username || !password) {
        alert('Username dan password wajib diisi.');
        return;
    }

    try {
        const response = await apiFetch('/api/login.php', {
            method: 'POST',
            body: JSON.stringify({ username, password })
        });

        saveSession(response.user, response.token);
        showApp();
        document.getElementById('loginForm').reset();
        await loadProducts();
        await loadTransactions();
        await loadReports();
    } catch (error) {
        alert(error.message || 'Login gagal');
    }
}

function logoutUser() {
    clearSession();
    cart = [];
    renderCart();
    closePaymentModal();
    showLogin();
}

function addToCart(productId) {
    const product = products.find((item) => item.id === productId);
    if (!product) return;

    if (Number(product.stock || 0) <= 0) {
        alert('Stok produk sedang habis.');
        return;
    }

    const existingItem = cart.find((item) => item.id === productId);
    const currentQtyInCart = existingItem ? existingItem.qty : 0;

    if (currentQtyInCart >= Number(product.stock || 0)) {
        alert(`Stok produk ${product.name} tersisa ${product.stock}.`);
        return;
    }

    if (existingItem) {
        existingItem.qty += 1;
    } else {
        cart.push({ ...product, qty: 1 });
    }

    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');

    if (cart.length === 0) {
        container.innerHTML = '<p class="empty-state">Keranjang kosong</p>';
        document.getElementById('totalAmount').innerText = formatRupiah(0);
        return;
    }

    container.innerHTML = cart.map((item) => `
        <div class="cart-item">
            <div>
                <strong>${item.name}</strong><br>
                <small>${formatRupiah(item.price)}</small>
            </div>
            <div class="qty-controls">
                <button class="btn-minus" onclick="updateQty(${item.id}, -1)">-</button>
                <span>${item.qty}</span>
                <button class="btn-plus" onclick="updateQty(${item.id}, 1)">+</button>
                <button class="btn-remove" onclick="removeItem(${item.id})" title="Hapus">✕</button>
            </div>
            <div style="text-align: right; margin-left: 10px;">
                <strong>${formatRupiah(item.price * item.qty)}</strong>
            </div>
        </div>
    `).join('');

    const total = cart.reduce((sum, item) => sum + item.price * item.qty, 0);
    document.getElementById('totalAmount').innerText = formatRupiah(total);
}

function updateQty(id, change) {
    const item = cart.find((element) => element.id === id);
    if (!item) return;

    item.qty += change;
    if (item.qty <= 0) {
        removeItem(id);
        return;
    }

    renderCart();
}

function removeItem(id) {
    cart = cart.filter((item) => item.id !== id);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) {
        alert('Keranjang sudah kosong.');
        return;
    }

    if (confirm('Yakin ingin menghapus semua item?')) {
        cart = [];
        renderCart();
    }
}

function openPaymentModal() {
    if (cart.length === 0) {
        alert('Keranjang masih kosong!');
        return;
    }

    const total = cart.reduce((sum, item) => sum + item.price * item.qty, 0);
    modalTotal.innerText = formatRupiah(total);
    cashInput.value = '';
    changeDisplay.innerText = 'Kembalian: Rp 0';
    modal.style.display = 'flex';
    cashInput.focus();
}

function closePaymentModal() {
    modal.style.display = 'none';
}

function calculateChange() {
    const total = cart.reduce((sum, item) => sum + item.price * item.qty, 0);
    const cash = Number(cashInput.value) || 0;
    const change = cash - total;

    if (change >= 0) {
        changeDisplay.innerText = `Kembalian: ${formatRupiah(change)}`;
        changeDisplay.style.color = '#16a34a';
    } else {
        changeDisplay.innerText = `Kurang: ${formatRupiah(Math.abs(change))}`;
        changeDisplay.style.color = '#dc2626';
    }
}

function buildReceiptContent(transaction) {
    const items = transaction.items || [];
    const list = items.map((item) => `
        <div class="receipt-item">
            <span>${item.name || item.product_name} x${item.qty}</span>
            <span>${formatRupiah((item.price || 0) * (item.qty || 0))}</span>
        </div>
    `).join('');

    return `
        <div class="receipt">
            <div class="receipt-header">
                <h2>Serba Serbi Khas Yiheng</h2>
                <div>Alamat: Warung Makan</div>
                <div>Kasir: ${transaction.cashier_name || currentUser?.name || 'Kasir'}</div>
            </div>

            <div class="receipt-detail">
                <span>No. Struk</span>
                <span>#${transaction.id}</span>
            </div>
            <div class="receipt-detail">
                <span>Waktu</span>
                <span>${transaction.created_at || new Date().toLocaleString('id-ID')}</span>
            </div>

            <div class="receipt-list">
                ${list}
            </div>

            <div class="receipt-total">
                <div class="receipt-line">
                    <span>Subtotal</span>
                    <span>${formatRupiah(transaction.total || 0)}</span>
                </div>
                <div class="receipt-line">
                    <span>Bayar</span>
                    <span>${formatRupiah(transaction.cash || 0)}</span>
                </div>
                <div class="receipt-line">
                    <span>Kembali</span>
                    <span>${formatRupiah(transaction.change || 0)}</span>
                </div>
            </div>

            <div style="margin-top: 12px; text-align: center; font-size: 12px;">Terima kasih atas kunjungan Anda</div>
        </div>
    `;
}

function printReceipt(transaction) {
    const printContainer = document.getElementById('printContainer');
    printContainer.innerHTML = buildReceiptContent(transaction);
    window.print();
}

async function processPayment(options = {}) {
    const { printReceiptAfter = false } = options;
    const total = cart.reduce((sum, item) => sum + item.price * item.qty, 0);
    const cash = Number(cashInput.value);

    if (Number.isNaN(cash) || cash < total) {
        alert('Uang pembayaran kurang!');
        return;
    }

    const change = cash - total;

    try {
        const response = await apiFetch('/api/transactions.php', {
            method: 'POST',
            body: JSON.stringify({
                cash,
                items: cart.map((item) => ({
                    id: item.id,
                    qty: item.qty,
                    price: item.price
                }))
            })
        });

        alert(`Pembayaran Berhasil!\nKembalian: ${formatRupiah(change)}`);
        if (printReceiptAfter) {
            const receiptItems = (response.transaction?.items || cart).map((item) => {
                const product = products.find((productItem) => Number(productItem.id) === Number(item.id));
                return {
                    ...item,
                    name: item.name || item.product_name || product?.name || 'Produk',
                    qty: Number(item.qty || 0),
                    price: Number(item.price || 0)
                };
            });

            printReceipt({
                ...(response.transaction || {}),
                id: response.transaction?.id || Date.now(),
                cashier_name: response.transaction?.cashier_name || currentUser?.name || 'Kasir',
                total: response.transaction?.total || total,
                cash: response.transaction?.cash || cash,
                change: response.transaction?.change || change,
                items: receiptItems,
                created_at: response.transaction?.created_at || new Date().toLocaleString('id-ID')
            });
        }

        cart = [];
        renderCart();
        closePaymentModal();
        await loadTransactions();
        await loadReports();
    } catch (error) {
        alert(error.message || 'Transaksi gagal');
    }
}

function toggleHistoryPanel(forceOpen = null) {
    const modal = document.getElementById('historyModal');
    const button = document.getElementById('toggleHistoryBtn');

    if (!modal || !button) return;

    const isHidden = modal.classList.contains('hidden');
    const shouldOpen = forceOpen !== null ? forceOpen : isHidden;

    modal.classList.toggle('hidden', !shouldOpen);
    modal.style.display = shouldOpen ? 'flex' : 'none';
    button.textContent = shouldOpen ? 'Tutup Riwayat' : 'Riwayat';
}

function renderHistory() {
    const historyDiv = document.getElementById('transactionHistory');

    if (!historyDiv) return;

    if (!canViewHistory()) {
        historyDiv.innerHTML = 'Riwayat transaksi hanya tersedia untuk owner/admin.';
        return;
    }

    if (!transactionHistory || transactionHistory.length === 0) {
        historyDiv.innerHTML = 'Belum ada transaksi.';
        return;
    }

    historyDiv.innerHTML = transactionHistory.map((transaction) => {
        const items = transaction.items || [];
        const itemText = items.map((item) => `${item.product_name || item.name} (${item.qty})`).join(', ');

        return `
            <div>
                <div><strong>${transaction.created_at || transaction.date}</strong></div>
                <div style="font-size: 0.85em; color: #555; margin-top: 4px;">${itemText}</div>
                <div class="summary-row" style="font-size: 0.9em; margin-top: 8px;">
                    <span>Total: ${formatRupiah(transaction.total)}</span>
                    <span>Kembali: ${formatRupiah(transaction.change)}</span>
                </div>
            </div>
        `;
    }).join('');
}

function renderDailyReport() {
    const dailyCount = document.getElementById('dailyCount');
    const dailyTotal = document.getElementById('dailyTotal');
    const dailyAverage = document.getElementById('dailyAverage');

    if (!transactionHistory || transactionHistory.length === 0) {
        if (dailyCount) dailyCount.textContent = '0';
        if (dailyTotal) dailyTotal.textContent = formatRupiah(0);
        if (dailyAverage) dailyAverage.textContent = formatRupiah(0);
        return;
    }

    const todayTransactions = transactionHistory.filter((txn) => {
        const created = txn.created_at || txn.date || '';
        return created && created.startsWith(new Date().toLocaleDateString('id-ID'));
    });

    const totalToday = todayTransactions.reduce((sum, txn) => sum + Number(txn.total || 0), 0);
    const avgToday = todayTransactions.length ? totalToday / todayTransactions.length : 0;

    if (dailyCount) dailyCount.textContent = todayTransactions.length;
    if (dailyTotal) dailyTotal.textContent = formatRupiah(totalToday);
    if (dailyAverage) dailyAverage.textContent = formatRupiah(avgToday);
}

function toggleProductPanel(forceOpen = null) {
    const modal = document.getElementById('productModal');
    const button = document.getElementById('toggleMenuBtn');

    if (!modal || !button) return;

    const isHidden = modal.classList.contains('hidden');
    const shouldOpen = forceOpen !== null ? forceOpen : isHidden;

    modal.classList.toggle('hidden', !shouldOpen);
    modal.style.display = shouldOpen ? 'flex' : 'none';
    button.textContent = shouldOpen ? 'Tutup Edit' : 'Edit Menu';
    syncHeaderControls();
}

function renderProductManagementList() {
    const container = document.getElementById('productManagementList');
    if (!container) return;

    if (!products || products.length === 0) {
        container.innerHTML = '<div class="empty-state">Belum ada menu yang tersedia.</div>';
        return;
    }

    container.innerHTML = products.map((product) => `
        <div class="product-item">
            <div class="product-edit-group">
                <label>Nama</label>
                <input data-field="name" data-id="${product.id}" value="${product.name.replace(/"/g, '&quot;')}" />
            </div>
            <div class="product-edit-group">
                <label>Harga</label>
                <input data-field="price" data-id="${product.id}" type="number" min="1000" step="1000" value="${Number(product.price || 0)}" />
            </div>
            <div class="product-edit-group">
                <label>Stok</label>
                <input data-field="stock" data-id="${product.id}" type="number" min="0" step="1" value="${Number(product.stock || 0)}" />
            </div>
            <button class="btn-small btn-save-product" type="button" onclick="updateProduct(${product.id})">Simpan</button>
            <button class="btn-small btn-delete-product" type="button" onclick="deleteProduct(${product.id})">Hapus</button>
        </div>
    `).join('');
}

async function updateProduct(productId) {
    const productRow = document.querySelector(`input[data-id="${productId}"][data-field="name"]`)?.closest('.product-item');
    if (!productRow) return;

    const nameInput = productRow.querySelector('[data-field="name"]');
    const priceInput = productRow.querySelector('[data-field="price"]');
    const stockInput = productRow.querySelector('[data-field="stock"]');

    const payload = {
        id: productId,
        name: nameInput.value.trim(),
        price: Number(priceInput.value || 0),
        stock: Number(stockInput.value || 0)
    };

    if (!payload.name || payload.price <= 0 || payload.stock < 0) {
        alert('Data menu tidak valid.');
        return;
    }

    try {
        await apiFetch('/api/products.php', {
            method: 'PUT',
            body: JSON.stringify(payload)
        });
        alert('Menu berhasil diperbarui.');
        await loadProducts();
        renderProductManagementList();
    } catch (error) {
        alert(error.message || 'Gagal memperbarui menu');
    }
}

async function deleteProduct(productId) {
    if (!confirm('Yakin ingin menghapus menu ini?')) return;

    try {
        await apiFetch('/api/products.php', {
            method: 'DELETE',
            body: JSON.stringify({ id: productId })
        });
        alert('Menu berhasil dihapus.');
        await loadProducts();
        renderProductManagementList();
    } catch (error) {
        alert(error.message || 'Gagal menghapus menu');
    }
}

async function addProduct(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const name = form.querySelector('#newProductName').value.trim();
    const price = Number(form.querySelector('#newProductPrice').value || 0);
    const stock = Number(form.querySelector('#newProductStock').value || 0);

    if (!name || price <= 0 || stock < 0) {
        alert('Nama, harga, dan stok harus valid.');
        return;
    }

    try {
        await apiFetch('/api/products.php', {
            method: 'POST',
            body: JSON.stringify({ name, price, stock })
        });
        form.reset();
        alert('Menu baru berhasil ditambahkan.');
        await loadProducts();
        renderProductManagementList();
    } catch (error) {
        alert(error.message || 'Gagal menambahkan menu');
    }
}

async function exportTransactions(format = 'csv') {
    if (!canViewHistory()) {
        alert('Export laporan hanya untuk owner/admin.');
        return;
    }

    if (!transactionHistory || transactionHistory.length === 0) {
        alert('Belum ada data transaksi yang bisa diekspor.');
        return;
    }

    if (format === 'pdf') {
        const printWindow = window.open('', '_blank', 'width=900,height=700');
        const rows = transactionHistory.map((transaction, index) => {
            const itemList = (transaction.items || []).map((item) => `${item.product_name || item.name} x${item.qty}`).join(', ');
            return `
                <tr>
                    <td>${index + 1}</td>
                    <td>${transaction.created_at || ''}</td>
                    <td>${itemList}</td>
                    <td>${formatRupiah(transaction.total || 0)}</td>
                    <td>${formatRupiah(transaction.cash || 0)}</td>
                    <td>${formatRupiah(transaction.change || 0)}</td>
                </tr>
            `;
        }).join('');

        printWindow.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Laporan Transaksi</title><style>body{font-family:Arial,sans-serif;padding:24px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px;text-align:left}h2{margin-bottom:12px}</style></head><body><h2>Laporan Transaksi</h2><table><thead><tr><th>No</th><th>Waktu</th><th>Item</th><th>Total</th><th>Bayar</th><th>Kembali</th></tr></thead><tbody>${rows}</tbody></table></body></html>`);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
        return;
    }

    const header = ['No', 'Waktu', 'Item', 'Total', 'Bayar', 'Kembali'];
    const rows = transactionHistory.map((transaction, index) => {
        const itemList = (transaction.items || []).map((item) => `${item.product_name || item.name} x${item.qty}`).join(' | ');
        return [
            index + 1,
            transaction.created_at || '',
            itemList,
            Number(transaction.total || 0),
            Number(transaction.cash || 0),
            Number(transaction.change || 0)
        ];
    });

    const csvContent = [header, ...rows]
        .map((row) => row.map((value) => `"${String(value).replace(/"/g, '""')}"`).join(','))
        .join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'laporan-transaksi.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

async function printHistory() {
    if (!transactionHistory || transactionHistory.length === 0) {
        alert('Tidak ada riwayat transaksi untuk dicetak!');
        return;
    }

    const printContainer = document.getElementById('printContainer');
    let printContent = `
        <div class="receipt">
            <div class="receipt-header">
                <h2>🍽️ Serba Serbi Khas Yiheng</h2>
                <div>Laporan Riwayat Transaksi</div>
                <div>Dicetak: ${new Date().toLocaleString('id-ID')}</div>
            </div>
            <div class="receipt-list">
    `;

    let totalSeluruhnya = 0;
    let jumlahTransaksi = 0;

    transactionHistory.forEach((transaction, index) => {
        const items = transaction.items || [];
        const itemList = items.map((item) => `${item.product_name || item.name} x${item.qty}`).join(', ');

        printContent += `
            <div style="margin-bottom: 12px; border-bottom: 1px dashed #111; padding-bottom: 8px;">
                <div><strong>#${index + 1}</strong> - ${transaction.created_at || transaction.date}</div>
                <div>${itemList}</div>
                <div style="margin-top: 6px;">Total: ${formatRupiah(transaction.total)} | Bayar: ${formatRupiah(transaction.cash)} | Kembali: ${formatRupiah(transaction.change)}</div>
            </div>
        `;

        totalSeluruhnya += Number(transaction.total || 0);
        jumlahTransaksi += 1;
    });

    printContent += `
            </div>
            <div class="receipt-total">
                <div class="receipt-line">
                    <span>Total Transaksi</span>
                    <span>${jumlahTransaksi}</span>
                </div>
                <div class="receipt-line">
                    <span>Total Penjualan</span>
                    <span>${formatRupiah(totalSeluruhnya)}</span>
                </div>
            </div>
        </div>
    `;

    printContainer.innerHTML = printContent;
    window.print();
}

async function initializeApp() {
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', (event) => {
            event.preventDefault();
            attemptLogin();
        });
    }

    const toggleHistoryBtn = document.getElementById('toggleHistoryBtn');
    if (toggleHistoryBtn) {
        toggleHistoryBtn.addEventListener('click', () => {
            if (!canViewHistory()) {
                alert('Riwayat transaksi hanya untuk owner/admin.');
                return;
            }
            toggleHistoryPanel();
        });
    }

    const toggleMenuBtn = document.getElementById('toggleMenuBtn');
    if (toggleMenuBtn) {
        toggleMenuBtn.addEventListener('click', () => {
            if (!canManageProducts()) {
                alert('Menu hanya bisa diubah oleh owner/admin.');
                return;
            }
            toggleProductPanel();
            renderProductManagementList();
        });
    }

    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', addProduct);
    }

    renderCashierBadge();
    renderCart();

    if (authToken && currentUser) {
        showApp();
        try {
            await loadProducts();
            await loadTransactions();
            await loadReports();
        } catch (error) {
            console.error('Gagal memuat data:', error);
            clearSession();
            showLogin();
        }
    } else {
        showLogin();
    }
}

initializeApp();
