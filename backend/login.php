<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['message' => 'Method not allowed.'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);

$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if (!$email || !$password) {
    respond(['message' => 'Please provide email and password.'], 400);
}

$db = getDB();

// Find user by email
$stmt = $db->prepare('SELECT id, name, email, password FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$db->close();

if (!$user) {
    respond(['message' => 'Invalid email or password.'], 401);
}

// Verify password
if (!password_verify($password, $user['password'])) {
    respond(['message' => 'Invalid email or password.'], 401);
}

// Generate token
$token = generateToken($user['id'], $user['email']);

respond([
    'message' => 'Login successful.',
    'token'   => $token,
    'user'    => [
        'id'    => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
    ]
], 200);