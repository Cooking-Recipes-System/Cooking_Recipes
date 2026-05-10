<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['message' => 'Method not allowed.'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);

$name     = trim($body['name'] ?? '');
$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

// Validate
if (!$name || !$email || !$password) {
    respond(['message' => 'Please provide name, email, and password.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['message' => 'Invalid email address.'], 400);
}

if (strlen($password) < 6) {
    respond(['message' => 'Password must be at least 6 characters.'], 400);
}

$db = getDB();

// Check if email already exists
$stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    $db->close();
    respond(['message' => 'An account with that email already exists.'], 409);
}
$stmt->close();

// Hash password
$hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

// Insert user
$stmt = $db->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $name, $email, $hashedPassword);

if (!$stmt->execute()) {
    $stmt->close();
    $db->close();
    respond(['message' => 'Could not create account. Please try again.'], 500);
}

$stmt->close();
$db->close();

respond(['message' => 'Account created successfully.'], 201);