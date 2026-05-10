<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['message' => 'Method not allowed.'], 405);
}

// Verify token
$token = getBearerToken();
if (!$token) {
    respond(['message' => 'No token provided, authorization denied.'], 401);
}

$payload = verifyToken($token);
if (!$payload) {
    respond(['message' => 'Token is not valid or has expired.'], 401);
}

$db = getDB();

$stmt = $db->prepare('SELECT id, name, email, created_at FROM users WHERE id = ?');
$stmt->bind_param('i', $payload['userId']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$db->close();

if (!$user) {
    respond(['message' => 'User not found.'], 404);
}

respond(['user' => $user], 200);