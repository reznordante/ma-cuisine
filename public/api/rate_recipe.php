<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();

    $userId = requireApiAuth();

    $recipeId = filter_input(
        INPUT_POST,
        'recipe_id',
        FILTER_VALIDATE_INT
    );

    $rating = filter_input(
        INPUT_POST,
        'rating',
        FILTER_VALIDATE_INT
    );

    if (!$recipeId || !in_array($rating, [-1, 1], true)) {
        throw new RuntimeException('Données d’appréciation invalides.');
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM recipes
         WHERE id = ?
           AND is_demo = 0
         LIMIT 1'
    );

    $stmt->execute([$recipeId]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Recette introuvable.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO recipe_ratings
            (user_id, recipe_id, rating)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            rating = VALUES(rating),
            updated_at = CURRENT_TIMESTAMP'
    );

    $stmt->execute([
        $userId,
        $recipeId,
        $rating
    ]);

    echo json_encode(
        [
            'success' => true,
            'recipe_id' => $recipeId,
            'rating' => $rating
        ],
        JSON_UNESCAPED_UNICODE
    );

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'error' => $e->getMessage()
        ],
        JSON_UNESCAPED_UNICODE
    );
}
