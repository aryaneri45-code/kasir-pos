<?php
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($username === '' || $password === '') {
    jsonResponse(['error' => 'Username dan password wajib diisi'], 400);
}

$pdo = getConnection();
$stmt = $pdo->prepare('SELECT id, username, name, role, password_hash FROM users WHERE username = :username LIMIT 1');
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    jsonResponse(['error' => 'Username atau password salah'], 401);
}

$payload = [
    'id' => (int) $user['id'],
    'username' => $user['username'],
    'name' => $user['name'],
    'role' => $user['role']
];

jsonResponse([
    'token' => createToken($payload),
    'user' => $payload
], 200);
