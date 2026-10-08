const APP_TOKEN = localStorage.getItem('kasir_token') || '';

async function apiFetch(path, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {})
    };

    if (APP_TOKEN) {
        headers.Authorization = `Bearer ${APP_TOKEN}`;
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

function formatRupiah(number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(Number(number || 0));
}

function downloadMonthlyReport(format = 'csv') {
    const url = format === 'csv'
        ? '/api/reports.php?range=monthly&format=csv'
        : '/api/reports.php?range=monthly&format=pdf';

    const link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener';
    link.click();
}

function downloadDailyReport(format = 'csv') {
    const date = document.getElementById('dailyDetailDate')?.value || new Date().toISOString().slice(0, 10);
    const url = format === 'csv'
        ? `/api/reports.php?range=daily&date=${encodeURIComponent(date)}&format=csv`
        : `/api/reports.php?range=daily&date=${encodeURIComponent(date)}&format=pdf`;

    const link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener';
    link.click();
}

function renderDailyDetailReport(report = {}) {
    const summaryNode = document.getElementById('dailyDetailSummary');
    const tableBody = document.getElementById('dailyDetailTable');
    const transactions = report.transactions || [];
    const date = report.date || new Date().toISOString().slice(0, 10);

    if (summaryNode) {
        summaryNode.textContent = `${date} • ${report.count || 0} transaksi • ${formatRupiah(report.total || 0)}`;
    }

    if (!tableBody) {
        return;
    }

    if (!transactions.length) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; color: #6b7280; padding: 18px;">Belum ada transaksi pada tanggal ini.</td>
            </tr>
        `;
        return;
    }

    tableBody.innerHTML = transactions.map(transaction => {
        const items = (transaction.items || []).map(item => `${item.product_name} x${item.qty}`).join('<br>');
        return `
            <tr>
                <td>#${transaction.id}</td>
                <td>${new Date(transaction.created_at).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' })}</td>
                <td>${transaction.cashier_name}</td>
                <td>${formatRupiah(transaction.total)}</td>
                <td>${formatRupiah(transaction.cash)}</td>
                <td>${formatRupiah(transaction.change)}</td>
                <td>${items || '-'}</td>
            </tr>
        `;
    }).join('');
}

async function loadDailyDetail(date = '') {
    const selectedDate = date || document.getElementById('dailyDetailDate')?.value || new Date().toISOString().slice(0, 10);
    document.getElementById('dailyDetailDate').value = selectedDate;

    const data = await apiFetch(`/api/reports.php?range=daily_detail&date=${encodeURIComponent(selectedDate)}`);
    renderDailyDetailReport(data);
}

async function loadDashboard() {
    try {
        const summary = await apiFetch('/api/admin.php');
        const report = await apiFetch('/api/reports.php?range=monthly');

        const cards = [
            { label: 'Penjualan Hari Ini', value: formatRupiah(summary.today.total), tone: 'primary' },
            { label: 'Transaksi Hari Ini', value: summary.today.count, tone: 'success' },
            { label: 'Penjualan Bulan Ini', value: formatRupiah(summary.month.total), tone: 'warning' },
            { label: 'Stok Rendah', value: summary.low_stock, tone: 'danger' }
        ];

        const cardsContainer = document.getElementById('summaryCards');
        cardsContainer.innerHTML = cards.map(item => `
            <div class="card">
                <div class="label">${item.label}</div>
                <div class="value">${item.value}</div>
            </div>
        `).join('');

        const tableBody = document.getElementById('monthlyTable');
        tableBody.innerHTML = (report.reports || []).slice(0, 12).map(item => `
            <tr>
                <td>${item.month}</td>
                <td>${item.count}</td>
                <td>${formatRupiah(item.total)}</td>
            </tr>
        `).join('');

        const defaultDate = new Date().toISOString().slice(0, 10);
        document.getElementById('dailyDetailDate').value = defaultDate;
        await loadDailyDetail(defaultDate);
    } catch (error) {
        document.getElementById('summaryCards').innerHTML = `
            <div class="card">
                <div class="label">Status</div>
                <div class="value" style="font-size: 1rem;">${error.message}</div>
            </div>
        `;
    }
}

document.getElementById('exportMonthlyCsv')?.addEventListener('click', () => downloadMonthlyReport('csv'));
document.getElementById('exportMonthlyPdf')?.addEventListener('click', () => downloadMonthlyReport('pdf'));
document.getElementById('exportDailyCsv')?.addEventListener('click', () => downloadDailyReport('csv'));
document.getElementById('exportDailyPdf')?.addEventListener('click', () => downloadDailyReport('pdf'));
document.getElementById('loadDailyDetail')?.addEventListener('click', async () => {
    const selectedDate = document.getElementById('dailyDetailDate')?.value;
    if (selectedDate) {
        await loadDailyDetail(selectedDate);
    }
});

loadDashboard();
