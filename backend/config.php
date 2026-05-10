<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP default is empty password
define('DB_NAME', 'recipe');

// JWT-like secret for session tokens (used with HMAC)
define('SECRET_KEY', 'change_this_to_a_long_random_secret_string');

// Allow requests from the frontend (XAMPP serves everything on same origin)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Connect to MySQL
function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['message' => 'Database connection failed: ' . $conn->connect_error]);
        exit();
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

// Send JSON response and exit
function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

// Generate a simple signed token: base64(payload).base64(hmac)
function generateToken($userId, $email) {
    $payload = base64_encode(json_encode([
        'userId' => $userId,
        'email'  => $email,
        'exp'    => time() + (7 * 24 * 60 * 60) // 7 days
    ]));
    $sig = base64_encode(hash_hmac('sha256', $payload, SECRET_KEY, true));
    return $payload . '.' . $sig;
}

// Verify token and return payload, or false
function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return false;

    [$payload, $sig] = $parts;
    $expectedSig = base64_encode(hash_hmac('sha256', $payload, SECRET_KEY, true));

    if (!hash_equals($expectedSig, $sig)) return false;

    $data = json_decode(base64_decode($payload), true);
    if (!$data || $data['exp'] < time()) return false;

    return $data;
}

// Get Bearer token from Authorization header
function getBearerToken() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) {
        return substr($auth, 7);
    }
    return null;
}