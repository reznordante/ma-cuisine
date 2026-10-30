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

    $peopleCount = isset($input['people_count'])
        ? (int)$input['people_count']
        : 3;

    $peopleCount = max(1, min(12, $peopleCount));

    $thermomix = !empty($input['thermomix_preferred']) ? 1 : 0;

    $preferences = isset($input['preferences']) && is_array($input['preferences'])
        ? array_values(array_filter($input['preferences'], 'is_string'))
        : [];

    $dislikes = isset($input['dislikes']) && is_array($input['dislikes'])
        ? array_values(array_filter($input['dislikes'], 'is_string'))
        : [];

    $allergies = isset($input['allergies']) && is_array($input['allergies'])
        ? array_values(array_filter($input['allergies'], 'is_string'))
        : [];

    $pdo = db();

    /*
     * On conserve le contenu existant de preferences_json,
     * notamment recipe_sources.
     */
    $stmt = $pdo->prepare("
        SELECT preferences_json
        FROM user_preferences
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([1]);

    $existingJson = $stmt->fetchColumn();

    $preferencesData = [];

    if (is_string($existingJson) && trim($existingJson) !== '') {
        $decoded = json_decode($existingJson, true);

        if (is_array($decoded)) {
            $preferencesData = $decoded;
        }
    }

    /*
     * Les sources de recettes restent dans preferences_json.
     * Les préférences alimentaires sont stockées séparément
     * dans la clé "food_preferences".
     */
    $preferencesData['food_preferences'] = $preferences;

    $preferencesJson = json_encode(
        $preferencesData,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    $stmt = $pdo->prepare("
        INSERT INTO user_preferences
            (
                user_id,
                people_count,
                thermomix_preferred,
                dislikes_json,
                allergies_json,
                preferences_json
            )
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            people_count = VALUES(people_count),
            thermomix_preferred = VALUES(thermomix_preferred),
            dislikes_json = VALUES(dislikes_json),
            allergies_json = VALUES(allergies_json),
            preferences_json = VALUES(preferences_json)
    ");

    $stmt->execute([
        1,
        $peopleCount,
        $thermomix,
        json_encode(
            $dislikes,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ),
        json_encode(
            $allergies,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ),
        $preferencesJson
    ]);

    echo json_encode(
        ['success' => true],
        JSON_UNESCAPED_UNICODE
    );

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' => $e->getMessage()
        ],
        JSON_UNESCAPED_UNICODE
    );
}
