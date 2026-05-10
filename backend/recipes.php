<?php
require_once 'config.php';

// All recipe endpoints require auth
$token = getBearerToken();
if (!$token) respond(['message' => 'Authorization required.'], 401);

$user = verifyToken($token);
if (!$user) respond(['message' => 'Token invalid or expired.'], 401);

$userId = $user['userId'];
$method = $_SERVER['REQUEST_METHOD'];

// ── GET /recipes.php  → list all recipes for this user
// ── GET /recipes.php?id=X → get single recipe with ingredients
if ($method === 'GET') {
    $db = getDB();

    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];

        $stmt = $db->prepare(
            'SELECT r.*, u.name AS author
             FROM recipes r
             JOIN users u ON u.id = r.user_id
             WHERE r.id = ? AND r.user_id = ?'
        );
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $recipe = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$recipe) {
            $db->close();
            respond(['message' => 'Recipe not found.'], 404);
        }

        // Fetch ingredients
        $stmt = $db->prepare('SELECT id, name, quantity FROM ingredients WHERE recipe_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $ingredients = [];
        while ($row = $result->fetch_assoc()) {
            $ingredients[] = $row;
        }
        $stmt->close();
        $db->close();

        $recipe['ingredients'] = $ingredients;
        respond(['recipe' => $recipe]);

    } else {
        // List all recipes (with ingredient count)
        $stmt = $db->prepare(
            'SELECT r.id, r.title, r.description, r.category, r.cook_time, r.servings, r.created_at,
                    COUNT(i.id) AS ingredient_count
             FROM recipes r
             LEFT JOIN ingredients i ON i.recipe_id = r.id
             WHERE r.user_id = ?
             GROUP BY r.id
             ORDER BY r.created_at DESC'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $recipes = [];
        while ($row = $result->fetch_assoc()) {
            $recipes[] = $row;
        }
        $stmt->close();
        $db->close();
        respond(['recipes' => $recipes]);
    }
}

// ── POST /recipes.php → create recipe + ingredients
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $title       = trim($body['title'] ?? '');
    $description = trim($body['description'] ?? '');
    $category    = trim($body['category'] ?? 'General');
    $cookTime    = (int)($body['cook_time'] ?? 0);
    $servings    = (int)($body['servings'] ?? 1);
    $ingredients = $body['ingredients'] ?? [];

    if (!$title) respond(['message' => 'Recipe title is required.'], 400);
    if (empty($ingredients)) respond(['message' => 'Add at least one ingredient.'], 400);

    $db = getDB();
    $db->begin_transaction();

    try {
        $stmt = $db->prepare(
            'INSERT INTO recipes (user_id, title, description, category, cook_time, servings)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssii', $userId, $title, $description, $category, $cookTime, $servings);
        $stmt->execute();
        $recipeId = $db->insert_id;
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO ingredients (recipe_id, name, quantity) VALUES (?, ?, ?)');
        foreach ($ingredients as $ing) {
            $name     = trim($ing['name'] ?? '');
            $quantity = trim($ing['quantity'] ?? '');
            if ($name && $quantity) {
                $stmt->bind_param('iss', $recipeId, $name, $quantity);
                $stmt->execute();
            }
        }
        $stmt->close();

        $db->commit();
        $db->close();
        respond(['message' => 'Recipe created successfully.', 'id' => $recipeId], 201);

    } catch (Exception $e) {
        $db->rollback();
        $db->close();
        respond(['message' => 'Failed to save recipe.'], 500);
    }
}

// ── PUT /recipes.php?id=X → update recipe + replace ingredients
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['message' => 'Recipe ID required.'], 400);

    $body = json_decode(file_get_contents('php://input'), true);

    $title       = trim($body['title'] ?? '');
    $description = trim($body['description'] ?? '');
    $category    = trim($body['category'] ?? 'General');
    $cookTime    = (int)($body['cook_time'] ?? 0);
    $servings    = (int)($body['servings'] ?? 1);
    $ingredients = $body['ingredients'] ?? [];

    if (!$title) respond(['message' => 'Recipe title is required.'], 400);
    if (empty($ingredients)) respond(['message' => 'Add at least one ingredient.'], 400);

    $db = getDB();

    // Make sure recipe belongs to this user
    $stmt = $db->prepare('SELECT id FROM recipes WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        $stmt->close(); $db->close();
        respond(['message' => 'Recipe not found.'], 404);
    }
    $stmt->close();

    $db->begin_transaction();
    try {
        // Update recipe row
        $stmt = $db->prepare(
            'UPDATE recipes SET title=?, description=?, category=?, cook_time=?, servings=?
             WHERE id=? AND user_id=?'
        );
        $stmt->bind_param('sssiii', $title, $description, $category, $cookTime, $servings, $id, $userId);
        $stmt->execute();
        $stmt->close();

        // Replace all ingredients
        $stmt = $db->prepare('DELETE FROM ingredients WHERE recipe_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO ingredients (recipe_id, name, quantity) VALUES (?, ?, ?)');
        foreach ($ingredients as $ing) {
            $name     = trim($ing['name'] ?? '');
            $quantity = trim($ing['quantity'] ?? '');
            if ($name && $quantity) {
                $stmt->bind_param('iss', $id, $name, $quantity);
                $stmt->execute();
            }
        }
        $stmt->close();

        $db->commit();
        $db->close();
        respond(['message' => 'Recipe updated successfully.']);

    } catch (Exception $e) {
        $db->rollback();
        $db->close();
        respond(['message' => 'Failed to update recipe.'], 500);
    }
}


if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['message' => 'Recipe ID required.'], 400);

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM recipes WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $db->close();

    if ($affected === 0) respond(['message' => 'Recipe not found.'], 404);
    respond(['message' => 'Recipe deleted.']);
}

respond(['message' => 'Method not allowed.'], 405);