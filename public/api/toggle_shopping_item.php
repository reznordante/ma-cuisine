<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

$userId = requireApiAuth();

try {

    $input = json_decode(
        file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $checked = !empty($input['checked']) ? 1 : 0;

    if ($id <= 0) {
        throw new RuntimeException('Article invalide.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE shopping_items si
        INNER JOIN shopping_lists sl
            ON sl.id = si.shopping_list_id
        SET si.checked = ?
        WHERE si.id = ?
          AND sl.user_id = ?
    ");

    $stmt->execute([$checked, $id, $userId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Article introuvable ou accès non autorisé.');
    }

    echo json_encode([
        'success' => true,
        'checked' => $checked
    ]);

} catch (Throwable $e) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
