<?php
require __DIR__ . '/bootstrap.php';

$user = requireAuth();
$pdo = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT * FROM products WHERE is_active = 1 ORDER BY name ASC');
    $products = $stmt->fetchAll();
    jsonResponse(['products' => $products], 200);
}

if (($user['role'] ?? '') !== 'admin') {
    jsonResponse(['error' => 'Menu hanya dapat diubah oleh owner/admin'], 403);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($input['name'] ?? ''));
    $price = (int) ($input['price'] ?? 0);
    $stock = (int) ($input['stock'] ?? 0);

    if ($name === '' || $price <= 0 || $stock < 0) {
        jsonResponse(['error' => 'Nama, harga, dan stok tidak valid'], 400);
    }

    $stmt = $pdo->prepare('INSERT INTO products (name, price, stock, is_active) VALUES (:name, :price, :stock, 1)');
    $stmt->execute([
        'name' => $name,
        'price' => $price,
        'stock' => $stock,
    ]);

    $productId = (int) $pdo->lastInsertId();
    $created = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
    $created->execute(['id' => $productId]);
    jsonResponse(['message' => 'Menu berhasil ditambahkan', 'product' => $created->fetch()], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $productId = (int) ($input['id'] ?? 0);
    if ($productId <= 0) {
        jsonResponse(['error' => 'ID menu tidak valid'], 400);
    }

    $name = trim((string) ($input['name'] ?? ''));
    $price = (int) ($input['price'] ?? 0);
    $stock = (int) ($input['stock'] ?? 0);

    if ($name === '' || $price <= 0 || $stock < 0) {
        jsonResponse(['error' => 'Data menu tidak valid'], 400);
    }

    $stmt = $pdo->prepare('UPDATE products SET name = :name, price = :price, stock = :stock WHERE id = :id AND is_active = 1');
    $stmt->execute([
        'id' => $productId,
        'name' => $name,
        'price' => $price,
        'stock' => $stock,
    ]);

    $updated = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
    $updated->execute(['id' => $productId]);
    jsonResponse(['message' => 'Menu berhasil diperbarui', 'product' => $updated->fetch()], 200);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $productId = (int) ($input['id'] ?? 0);
    if ($productId <= 0) {
        jsonResponse(['error' => 'ID menu tidak valid'], 400);
    }

    $stmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = :id');
    $stmt->execute(['id' => $productId]);
    jsonResponse(['message' => 'Menu berhasil dihapus'], 200);
}

jsonResponse(['error' => 'Method not allowed'], 405);
