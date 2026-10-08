<?php
require __DIR__ . '/bootstrap.php';

$user = requireAuth('admin');

$pdo = getConnection();

$today = $pdo->query(
    "SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total FROM transactions WHERE DATE(created_at) = CURDATE()"
)->fetch();

$month = $pdo->query(
    "SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total FROM transactions WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
)->fetch();

$products = $pdo->query('SELECT COUNT(*) AS total FROM products WHERE is_active = 1')->fetch();
$lowStock = $pdo->query('SELECT COUNT(*) AS total FROM products WHERE stock <= 10 AND is_active = 1')->fetch();
$recent = $pdo->query('SELECT * FROM transactions ORDER BY created_at DESC LIMIT 10')->fetchAll();

jsonResponse([
    'today' => [
        'count' => (int) ($today['count'] ?? 0),
        'total' => (int) ($today['total'] ?? 0)
    ],
    'month' => [
        'count' => (int) ($month['count'] ?? 0),
        'total' => (int) ($month['total'] ?? 0)
    ],
    'products_total' => (int) ($products['total'] ?? 0),
    'low_stock' => (int) ($lowStock['total'] ?? 0),
    'recent' => $recent
], 200);
