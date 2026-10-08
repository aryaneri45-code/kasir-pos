<?php
require __DIR__ . '/bootstrap.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($user['role'] ?? '') !== 'admin') {
        jsonResponse(['error' => 'Akses riwayat hanya untuk owner/admin'], 403);
    }

    $pdo = getConnection();
    $stmt = $pdo->query(
        'SELECT t.*, ti.product_name, ti.qty, ti.price, ti.subtotal
         FROM transactions t
         LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
         ORDER BY t.created_at DESC LIMIT 50'
    );

    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['id']]['id'] = $row['id'];
        $result[$row['id']]['cashier_name'] = $row['cashier_name'];
        $result[$row['id']]['total'] = (int) $row['total'];
        $result[$row['id']]['cash'] = (int) $row['cash'];
        $result[$row['id']]['change'] = (int) $row['change'];
        $result[$row['id']]['created_at'] = $row['created_at'];
        $result[$row['id']]['items'][] = [
            'product_name' => $row['product_name'],
            'qty' => (int) $row['qty'],
            'price' => (int) $row['price'],
            'subtotal' => (int) $row['subtotal']
        ];
    }

    jsonResponse(['transactions' => array_values($result)], 200);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$items = $input['items'] ?? [];
$cash = (int) ($input['cash'] ?? 0);

if (!is_array($items) || empty($items)) {
    jsonResponse(['error' => 'Item transaksi tidak valid'], 400);
}

$pdo = getConnection();
$total = 0;
foreach ($items as $item) {
    $productId = (int) ($item['id'] ?? 0);
    $qty = (int) ($item['qty'] ?? 0);
    $price = (int) ($item['price'] ?? 0);

    if ($productId <= 0 || $qty <= 0 || $price <= 0) {
        jsonResponse(['error' => 'Data item tidak lengkap'], 400);
    }

    $stmt = $pdo->prepare('SELECT id, name, stock, price FROM products WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();

    if (!$product) {
        jsonResponse(['error' => 'Produk tidak ditemukan'], 404);
    }

    if ((int) $product['stock'] < $qty) {
        jsonResponse(['error' => 'Stok produk ' . $product['name'] . ' tidak cukup'], 400);
    }

    $total += $price * $qty;
}

if ($cash < $total) {
    jsonResponse(['error' => 'Uang yang diterima kurang dari total transaksi'], 400);
}

$change = $cash - $total;

$pdo->beginTransaction();
$stmt = $pdo->prepare('INSERT INTO transactions (cashier_id, cashier_name, total, cash, `change`) VALUES (:cashier_id, :cashier_name, :total, :cash, :change)');
$stmt->execute([
    'cashier_id' => $user['id'],
    'cashier_name' => $user['name'],
    'total' => $total,
    'cash' => $cash,
    'change' => $change
]);
$transactionId = (int) $pdo->lastInsertId();

foreach ($items as $item) {
    $productId = (int) ($item['id'] ?? 0);
    $qty = (int) ($item['qty'] ?? 0);
    $price = (int) ($item['price'] ?? 0);
    $subtotal = $price * $qty;

    $sqlProduct = $pdo->prepare('SELECT name FROM products WHERE id = :id LIMIT 1');
    $sqlProduct->execute(['id' => $productId]);
    $productName = $sqlProduct->fetchColumn();

    $pdo->prepare('INSERT INTO transaction_items (transaction_id, product_id, product_name, qty, price, subtotal) VALUES (:transaction_id, :product_id, :product_name, :qty, :price, :subtotal)')
        ->execute([
            'transaction_id' => $transactionId,
            'product_id' => $productId,
            'product_name' => $productName,
            'qty' => $qty,
            'price' => $price,
            'subtotal' => $subtotal
        ]);

    $pdo->prepare('UPDATE products SET stock = stock - :qty WHERE id = :id')
        ->execute(['qty' => $qty, 'id' => $productId]);
}

$pdo->commit();

$responseItems = [];
foreach ($items as $item) {
    $productId = (int) ($item['id'] ?? 0);
    $qty = (int) ($item['qty'] ?? 0);
    $price = (int) ($item['price'] ?? 0);

    $productStmt = $pdo->prepare('SELECT name FROM products WHERE id = :id LIMIT 1');
    $productStmt->execute(['id' => $productId]);
    $productName = (string) $productStmt->fetchColumn();

    $responseItems[] = [
        'id' => $productId,
        'name' => $productName,
        'qty' => $qty,
        'price' => $price
    ];
}

jsonResponse([
    'message' => 'Transaksi berhasil disimpan',
    'transaction' => [
        'id' => $transactionId,
        'cashier_name' => $user['name'],
        'total' => $total,
        'cash' => $cash,
        'change' => $change,
        'items' => $responseItems,
        'created_at' => date('Y-m-d H:i:s')
    ]
], 201);
