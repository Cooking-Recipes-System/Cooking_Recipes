<?php
require_once 'config.php';

// Auth required
$token = getBearerToken();
if (!$token) respond(['message' => 'Authorization required.'], 401);

$user = verifyToken($token);
if (!$user) respond(['message' => 'Token invalid or expired.'], 401);

$userId = $user['userId'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['message' => 'Method not allowed.'], 405);
}

$q = trim($_GET['q'] ?? '');
if (!$q) respond(['recipes' => []]);

$like = '%' . $q . '%';
$db   = getDB();

// Search by recipe title OR ingredient name
$stmt = $db->prepare(
    'SELECT DISTINCT r.id, r.title, r.description, r.category, r.cook_time, r.servings, r.created_at,
            COUNT(i2.id) AS ingredient_count
     FROM recipes r
     LEFT JOIN ingredients i  ON i.recipe_id  = r.id AND (i.name LIKE ?)
     LEFT JOIN ingredients i2 ON i2.recipe_id = r.id
     WHERE r.user_id = ? AND (r.title LIKE ? OR i.id IS NOT NULL)
     GROUP BY r.id
     ORDER BY r.created_at DESC'
);
$stmt->bind_param('sis', $like, $userId, $like);
$stmt->execute();
$result  = $stmt->get_result();
$recipes = [];
while ($row = $result->fetch_assoc()) {
    $recipes[] = $row;
}
$stmt->close();
$db->close();

respond(['recipes' => $recipes]);