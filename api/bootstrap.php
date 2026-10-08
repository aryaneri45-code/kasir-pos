<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

const APP_SECRET = 'kasir-pos-secret-2026';
const DB_NAME = 'kasir_pos';
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_USER = 'kasir_user';
const DB_PASS = 'kasirpass123';

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode(string $value): string
{
    return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
}

function createToken(array $user): string
{
    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = [
        'sub' => (int) $user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'role' => $user['role'],
        'exp' => time() + (60 * 60 * 12)
    ];
    $encodedPayload = base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', "$header.$encodedPayload", APP_SECRET, true);
    return "$header.$encodedPayload." . base64UrlEncode($signature);
}

function verifyToken(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $payload, $signature] = $parts;
    $expected = hash_hmac('sha256', "$header.$payload", APP_SECRET, true);
    $expectedToken = base64UrlEncode($expected);

    if (!hash_equals($expectedToken, $signature)) {
        return null;
    }

    $decoded = json_decode(base64UrlDecode($payload), true);
    if (!is_array($decoded) || ($decoded['exp'] ?? 0) < time()) {
        return null;
    }

    return $decoded;
}

function getBearerToken(): ?string
{
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function currentUser(): ?array
{
    $token = getBearerToken();
    if (!$token) {
        return null;
    }

    $decoded = verifyToken($token);
    if (!$decoded) {
        return null;
    }

    $pdo = getConnection();
    $stmt = $pdo->prepare('SELECT id, username, name, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $decoded['sub']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function requireAuth(?string $requiredRole = null): array
{
    $user = currentUser();
    if (!$user) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    if ($requiredRole && ($user['role'] ?? '') !== $requiredRole && ($user['role'] ?? '') !== 'admin') {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    return $user;
}

function dbServerConnection(): PDO
{
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4; SET time_zone = '+07:00'"
            ]
        );
    }

    return $pdo;
}

function getConnection(): PDO
{
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4; SET time_zone = '+07:00'"
            ]
        );
    }

    return $pdo;
}

function archiveExpiredTransactions(): void
{
    $pdo = getConnection();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS transaction_archives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            `month` VARCHAR(7) NOT NULL UNIQUE,
            total_transactions INT NOT NULL DEFAULT 0,
            total_sales INT NOT NULL DEFAULT 0,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $expired = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                COUNT(*) AS total_transactions,
                SUM(total) AS total_sales
         FROM transactions
         WHERE created_at < :cutoff
         GROUP BY DATE_FORMAT(created_at, '%Y-%m')"
    );
    $expired->execute(['cutoff' => $cutoff]);
    $months = $expired->fetchAll();

    foreach ($months as $monthRow) {
        $month = (string) ($monthRow['month'] ?? '');
        if ($month === '') {
            continue;
        }

        $existing = $pdo->prepare('SELECT id FROM transaction_archives WHERE `month` = :month LIMIT 1');
        $existing->execute(['month' => $month]);

        if ($existing->fetch()) {
            $pdo->prepare(
                'UPDATE transaction_archives SET total_transactions = :total_transactions, total_sales = :total_sales, archived_at = NOW() WHERE `month` = :month'
            )->execute([
                'total_transactions' => (int) ($monthRow['total_transactions'] ?? 0),
                'total_sales' => (int) ($monthRow['total_sales'] ?? 0),
                'month' => $month
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO transaction_archives (`month`, total_transactions, total_sales, archived_at) VALUES (:month, :total_transactions, :total_sales, NOW())'
            )->execute([
                'month' => $month,
                'total_transactions' => (int) ($monthRow['total_transactions'] ?? 0),
                'total_sales' => (int) ($monthRow['total_sales'] ?? 0)
            ]);
        }
    }

    $idsQuery = $pdo->prepare('SELECT id FROM transactions WHERE created_at < :cutoff');
    $idsQuery->execute(['cutoff' => $cutoff]);
    $expiredIds = $idsQuery->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!empty($expiredIds)) {
        $placeholders = implode(',', array_fill(0, count($expiredIds), '?'));
        $pdo->prepare('DELETE FROM transaction_items WHERE transaction_id IN (' . $placeholders . ')')->execute($expiredIds);
        $pdo->prepare('DELETE FROM transactions WHERE id IN (' . $placeholders . ')')->execute($expiredIds);
    }
}

function seedDatabase(): void
{
    $server = dbServerConnection();
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $pdo = getConnection();

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(150) NOT NULL,
            role ENUM("cashier","admin") NOT NULL DEFAULT "cashier",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            price INT NOT NULL,
            stock INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS transactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            cashier_id INT NOT NULL,
            cashier_name VARCHAR(150) NOT NULL,
            total INT NOT NULL,
            cash INT NOT NULL,
            `change` INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cashier_id (cashier_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS transaction_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            transaction_id BIGINT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            qty INT NOT NULL,
            price INT NOT NULL,
            subtotal INT NOT NULL,
            FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id),
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_product_id (product_id)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS transaction_archives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            `month` VARCHAR(7) NOT NULL UNIQUE,
            total_transactions INT NOT NULL DEFAULT 0,
            total_sales INT NOT NULL DEFAULT 0,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $pdo->prepare('DELETE FROM users WHERE username = :username')->execute(['username' => 'admin']);

    $defaultUsers = [
        ['kasir', '123456', 'Kasir Utama', 'cashier'],
        ['aryaneri', 'erigusri123', 'Aryaneri', 'admin']
    ];

    foreach ($defaultUsers as [$username, $password, $name, $role]) {
        $stmt = $pdo->prepare('SELECT id, password_hash, role FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $pdo->prepare('UPDATE users SET password_hash = :hash, name = :name, role = :role WHERE username = :username')
                ->execute([
                    'username' => $username,
                    'hash' => password_hash($password, PASSWORD_BCRYPT),
                    'name' => $name,
                    'role' => $role
                ]);
            continue;
        }

        $pdo->prepare('INSERT INTO users (username, password_hash, name, role) VALUES (:username, :hash, :name, :role)')
            ->execute([
                'username' => $username,
                'hash' => password_hash($password, PASSWORD_BCRYPT),
                'name' => $name,
                'role' => $role
            ]);
    }

    $products = [
        ['Sop Iga Sapi Special', 30000, 20],
        ['Sop Tunjang Kaki Sapi', 35000, 15],
        ['Sop Tetelan', 30000, 18],
        ['Nasi Bakar Ayam Suwir Kemangi', 18000, 25],
        ['Kwetiau Goreng', 18000, 20],
        ['Kwetiau Siram', 18000, 20],
        ['Mie Goreng', 18000, 25],
        ['Mie Nyemek', 18000, 22],
        ['Nasi Goreng', 18000, 20],
        ['Soto Ayam', 18000, 25],
        ['Lontong Sayur', 18000, 22],
        ['Bubur Ayam', 18000, 24],
        ['Nasi Putih', 5000, 40],
        ['Minuman Dingin', 8000, 30],
        ['Minuman Panas', 7000, 30],
        ['Air Mineral', 5000, 35],
        ['Teh Manis', 5000, 35],
        ['Es Teh Tawar', 3000, 30],
        ['Teh Tawar Panas', 2000, 40],
        ['Teh Pucuk', 5000, 25],
        ['Nutrisari', 5000, 22],
        ['Jahe', 5000, 20],
        ['Pop ice', 10000, 18],
        ['Dancow Dingin', 10000, 12],
        ['Dancow Panas', 8000, 15],
        ['Es Batu', 1000, 50]
    ];

    foreach ($products as [$name, $price, $stock]) {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        if (!$stmt->fetch()) {
            $pdo->prepare('INSERT INTO products (name, price, stock, is_active) VALUES (:name, :price, :stock, 1)')
                ->execute([
                    'name' => $name,
                    'price' => $price,
                    'stock' => $stock
                ]);
        }
    }
}

seedDatabase();
archiveExpiredTransactions();
