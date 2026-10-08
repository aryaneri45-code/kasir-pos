<?php
require __DIR__ . '/bootstrap.php';

$user = requireAuth();

$range = $_GET['range'] ?? 'daily';
$format = strtolower($_GET['format'] ?? 'json');
$pdo = getConnection();

$normalizedRange = strtolower(str_replace(['-', '_'], '', (string) $range));

if ($normalizedRange === 'dailydetail') {
    if (($user['role'] ?? '') !== 'admin') {
        jsonResponse(['error' => 'Akses detail laporan harian hanya untuk owner/admin'], 403);
    }

    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $stmt = $pdo->prepare(
        "SELECT t.id, t.cashier_name, t.total, t.cash, t.change, t.created_at,
                ti.product_name, ti.qty, ti.price, ti.subtotal
         FROM transactions t
         LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
         WHERE DATE(t.created_at) = :date
         ORDER BY t.created_at DESC"
    );
    $stmt->execute(['date' => $date]);
    $rows = $stmt->fetchAll();

    $transactions = [];
    foreach ($rows as $row) {
        $transactionId = (int) ($row['id'] ?? 0);
        if (!isset($transactions[$transactionId])) {
            $transactions[$transactionId] = [
                'id' => $transactionId,
                'cashier_name' => $row['cashier_name'] ?? 'Unknown',
                'total' => (int) ($row['total'] ?? 0),
                'cash' => (int) ($row['cash'] ?? 0),
                'change' => (int) ($row['change'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
                'items' => []
            ];
        }

        $transactions[$transactionId]['items'][] = [
            'product_name' => $row['product_name'] ?? 'Produk',
            'qty' => (int) ($row['qty'] ?? 0),
            'price' => (int) ($row['price'] ?? 0),
            'subtotal' => (int) ($row['subtotal'] ?? 0)
        ];
    }

    $transactionList = array_values($transactions);
    $count = count($transactionList);
    $total = array_sum(array_map(fn($item) => (int) ($item['total'] ?? 0), $transactionList));

    jsonResponse([
        'date' => $date,
        'count' => $count,
        'total' => $total,
        'average' => $count > 0 ? (int) round($total / $count) : 0,
        'transactions' => $transactionList,
    ], 200);
}

if ($range === 'monthly') {
    if (($user['role'] ?? '') !== 'admin') {
        jsonResponse(['error' => 'Akses laporan bulanan hanya untuk owner/admin'], 403);
    }

    $stmt = $pdo->query(
        "SELECT `month`, total_transactions AS count, total_sales AS total
         FROM transaction_archives
         ORDER BY `month` DESC LIMIT 12"
    );
    $reports = $stmt->fetchAll();

    if (empty($reports) && $user['role'] === 'admin') {
        $fallback = $pdo->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS count, SUM(total) AS total
             FROM transactions
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month DESC LIMIT 12"
        );
        $reports = $fallback->fetchAll();
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="laporan-bulanan.csv"');
        echo "Bulan,JumlahTransaksi,TotalPenjualan\n";
        foreach ($reports as $row) {
            echo $row['month'] . ',' . (int) ($row['count'] ?? 0) . ',' . (int) ($row['total'] ?? 0) . "\n";
        }
        exit;
    }

    if ($format === 'pdf') {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Laporan Bulanan</title></head><body>';
        $html .= '<h2>Laporan Bulanan</h2>';
        $html .= '<table border="1" cellpadding="8" cellspacing="0"><tr><th>Bulan</th><th>Jumlah Transaksi</th><th>Total Penjualan</th></tr>';
        foreach ($reports as $row) {
            $html .= '<tr><td>' . htmlspecialchars($row['month']) . '</td><td>' . (int) ($row['count'] ?? 0) . '</td><td>Rp ' . number_format((int) ($row['total'] ?? 0), 0, ',', '.') . '</td></tr>';
        }
        $html .= '</table></body></html>';
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    jsonResponse(['reports' => $reports], 200);
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total, COALESCE(AVG(total),0) AS average
     FROM transactions
     WHERE DATE(created_at) = :date"
);
$stmt->execute(['date' => $date]);
$row = $stmt->fetch();

$dailyRows = $pdo->prepare(
    "SELECT t.id, t.cashier_name, t.total, t.cash, t.change, t.created_at, ti.product_name, ti.qty, ti.price, ti.subtotal
     FROM transactions t
     LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
     WHERE DATE(t.created_at) = :date
     ORDER BY t.created_at DESC"
);
$dailyRows->execute(['date' => $date]);
$dailyData = $dailyRows->fetchAll();

if ($format === 'csv' && ($user['role'] ?? '') === 'admin') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-harian-' . $date . '.csv"');
    echo "Tanggal,ID,Kasir,Total,Uang,Kembalian,Produk\n";
    foreach ($dailyData as $item) {
        $product = $item['product_name'] ?? 'Produk';
        $qty = (int) ($item['qty'] ?? 0);
        echo $date . ',' . (int) ($item['id'] ?? 0) . ',' . $product . ',' . (int) ($item['total'] ?? 0) . ',' . (int) ($item['cash'] ?? 0) . ',' . (int) ($item['change'] ?? 0) . ',' . $product . ' x' . $qty . "\n";
    }
    exit;
}

if ($format === 'pdf' && ($user['role'] ?? '') === 'admin') {
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Laporan Harian</title></head><body>';
    $html .= '<h2>Laporan Harian ' . htmlspecialchars($date) . '</h2>';
    $html .= '<table border="1" cellpadding="8" cellspacing="0"><tr><th>ID</th><th>Kasir</th><th>Total</th><th>Uang</th><th>Kembalian</th><th>Produk</th></tr>';
    foreach ($dailyData as $item) {
        $product = $item['product_name'] ?? 'Produk';
        $qty = (int) ($item['qty'] ?? 0);
        $html .= '<tr><td>' . (int) ($item['id'] ?? 0) . '</td><td>' . htmlspecialchars($item['cashier_name'] ?? 'Unknown') . '</td><td>Rp ' . number_format((int) ($item['total'] ?? 0), 0, ',', '.') . '</td><td>Rp ' . number_format((int) ($item['cash'] ?? 0), 0, ',', '.') . '</td><td>Rp ' . number_format((int) ($item['change'] ?? 0), 0, ',', '.') . '</td><td>' . htmlspecialchars($product . ' x' . $qty) . '</td></tr>';
    }
    $html .= '</table></body></html>';
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

jsonResponse([
    'range' => 'daily',
    'date' => $date,
    'count' => (int) ($row['count'] ?? 0),
    'total' => (int) ($row['total'] ?? 0),
    'average' => (int) ($row['average'] ?? 0),
], 200);
