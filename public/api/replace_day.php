<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = null;

function normalizeText(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    if ($converted !== false) {
        $value = $converted;
    }

    return preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
}

function matchesTerm(string $text, string $term): bool
{
    $text = normalizeText($text);
    $term = normalizeText($term);

    return $term !== '' && str_contains($text, $term);
}

try {

    $pdo = db();

    $userId = requireApiAuth();
    $date = trim((string)($_GET['date'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Date invalide.');
    }

    $requestedDate = new DateTimeImmutable($date);
    $weekStart = $requestedDate->modify('monday this week');
    $weekEnd = $weekStart->modify('+4 days');

    if ($requestedDate < $weekStart || $requestedDate > $weekEnd) {
        throw new InvalidArgumentException(
            'Le remplacement est disponible uniquement du lundi au vendredi.'
        );
    }

    /*
     * Préférences utilisateur.
     */
    $stmt = $pdo->prepare("
        SELECT
            people_count,
            thermomix_preferred,
            dislikes_json,
            allergies_json,
            preferences_json
        FROM user_preferences
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $preferencesRow = $stmt->fetch();

    if (!$preferencesRow) {
        throw new RuntimeException(
            'Les préférences utilisateur sont introuvables.'
        );
    }

    $peopleCount = max(
        1,
        min(12, (int)($preferencesRow['people_count'] ?? 3))
    );

    $thermomixPreferred =
        (int)($preferencesRow['thermomix_preferred'] ?? 0) === 1;

    $dislikes = json_decode(
        (string)($preferencesRow['dislikes_json'] ?? '[]'),
        true
    );

    $allergies = json_decode(
        (string)($preferencesRow['allergies_json'] ?? '[]'),
        true
    );

    $dislikes = is_array($dislikes)
        ? array_values(array_filter($dislikes, 'is_string'))
        : [];

    $allergies = is_array($allergies)
        ? array_values(array_filter($allergies, 'is_string'))
        : [];

    $foodPreferences = [];

    $preferencesJson = json_decode(
        (string)($preferencesRow['preferences_json'] ?? ''),
        true
    );

    if (is_array($preferencesJson)) {

        if (
            isset($preferencesJson['food_preferences']) &&
            is_array($preferencesJson['food_preferences'])
        ) {
            $foodPreferences = array_values(
                array_filter(
                    $preferencesJson['food_preferences'],
                    'is_string'
                )
            );
        }
    }

    /*
     * Sources sélectionnées.
     *
     * CuisineAZ = 1
     * Cookidoo  = 3
     * Marmiton  = 2 si présent plus tard
     */
    $sourceIds = [];

    if (
        is_array($preferencesJson) &&
        isset($preferencesJson['recipe_sources']) &&
        is_array($preferencesJson['recipe_sources'])
    ) {
        $sources = $preferencesJson['recipe_sources'];

        if (($sources['cuisineaz'] ?? true) === true) {
            $sourceIds[] = 1;
        }

        if (($sources['cookidoo'] ?? true) === true) {
            $sourceIds[] = 3;
        }

        if (($sources['marmiton'] ?? false) === true) {
            $sourceIds[] = 2;
        }
    } else {
        /*
         * Compatibilité avec une ancienne configuration :
         * si aucune sélection de source n'existe encore,
         * CuisineAZ + Cookidoo restent actifs.
         */
        $sourceIds = [1, 3];
    }

    $sourceIds = array_values(
        array_unique(
            array_map('intval', $sourceIds)
        )
    );

    if (!$sourceIds) {
        throw new RuntimeException(
            'Aucune source de recettes disponible n’est sélectionnée.'
        );
    }

    /*
     * Semaine actuelle.
     */
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id
        FROM weekly_menus
        WHERE user_id = ?
          AND week_start = ?
        LIMIT 1
    ");

    $stmt->execute([
        $userId,
        $weekStart->format('Y-m-d')
    ]);

    $weeklyMenuId = (int)($stmt->fetchColumn() ?: 0);

    if ($weeklyMenuId === 0) {
        throw new RuntimeException(
            'Aucun menu n’existe pour cette semaine.'
        );
    }

    /*
     * Recette actuellement affichée ce jour.
     */
    $stmt = $pdo->prepare("
        SELECT recipe_id
        FROM menu_items
        WHERE weekly_menu_id = ?
          AND menu_date = ?
        LIMIT 1
    ");

    $stmt->execute([
        $weeklyMenuId,
        $date
    ]);

    $oldRecipeId = (int)($stmt->fetchColumn() ?: 0);

    if ($oldRecipeId === 0) {
        throw new RuntimeException(
            'Aucune recette n’est prévue pour cette journée.'
        );
    }

    /*
     * Recettes déjà présentes cette semaine.
     */
    $stmt = $pdo->prepare("
        SELECT recipe_id
        FROM menu_items
        WHERE weekly_menu_id = ?
          AND menu_date BETWEEN ? AND ?
    ");

    $stmt->execute([
        $weeklyMenuId,
        $weekStart->format('Y-m-d'),
        $weekEnd->format('Y-m-d')
    ]);

    $menuRecipeIds = array_values(
        array_filter(
            array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
            static fn(int $id): bool => $id > 0
        )
    );

    /*
     * Recettes utilisées dans les 8 dernières semaines.
     */
    $stmt = $pdo->prepare("
        SELECT DISTINCT recipe_id
        FROM recipe_history
        WHERE user_id = ?
          AND cooked_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
    ");

    $stmt->execute([$userId]);

    $recentIds = array_values(
        array_filter(
            array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
            static fn(int $id): bool => $id > 0
        )
    );

    /*
     * Récupération des candidats.
     *
     * Pas de IN () possible ici :
     * les placeholders sont toujours générés à partir
     * d'au moins une source valide.
     */
    $sourcePlaceholders = implode(
        ',',
        array_fill(0, count($sourceIds), '?')
    );

    $sql = "
        SELECT
            r.id,
            r.title,
            r.description,
            r.image_url,
            r.category,
            r.keywords,
            r.servings,
            r.is_thermomix
        FROM recipes r
        WHERE r.is_demo = 0
          AND r.recipe_type = 'main'
          AND r.source_id IN ($sourcePlaceholders)
          AND r.id <> ?
        ORDER BY RAND()
        LIMIT 1000
    ";

    $params = array_merge(
        $sourceIds,
        [$oldRecipeId]
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $candidates = $stmt->fetchAll();

    if (!$candidates) {
        throw new RuntimeException(
            'Aucun autre plat principal disponible avec les sources sélectionnées.'
        );
    }

    /*
     * Ingrédients de tous les candidats.
     */
    $candidateIds = array_values(
        array_unique(
            array_map(
                static fn(array $row): int => (int)$row['id'],
                $candidates
            )
        )
    );

    $ingredientMap = [];

    if ($candidateIds) {

        $ingredientPlaceholders = implode(
            ',',
            array_fill(0, count($candidateIds), '?')
        );

        $stmt = $pdo->prepare("
            SELECT
                recipe_id,
                raw_text,
                ingredient_name
            FROM recipe_ingredients
            WHERE recipe_id IN ($ingredientPlaceholders)
        ");

        $stmt->execute($candidateIds);

        foreach ($stmt->fetchAll() as $ingredient) {

            $recipeId = (int)$ingredient['recipe_id'];

            $ingredientText =
                (string)($ingredient['raw_text'] ?? '') . ' ' .
                (string)($ingredient['ingredient_name'] ?? '');

            $ingredientMap[$recipeId][] = $ingredientText;
        }
    }

    /*
     * Sélection des candidats :
     *
     * 1. Allergies = exclusion stricte sur les ingrédients.
     * 2. Dislikes = exclusion stricte sur titre + ingrédients.
     * 3. Historique 8 semaines = évité.
     * 4. Recette déjà présente dans la semaine = exclue.
     * 5. Préférences = bonus.
     * 6. Thermomix = bonus.
     */
    $validCandidates = [];

    foreach ($candidates as $candidate) {

        $recipeId = (int)$candidate['id'];

        if (in_array($recipeId, $menuRecipeIds, true)) {
            continue;
        }

        if (in_array($recipeId, $recentIds, true)) {
            continue;
        }

        $title = (string)($candidate['title'] ?? '');
        $description = (string)($candidate['description'] ?? '');
        $category = (string)($candidate['category'] ?? '');
        $keywords = (string)($candidate['keywords'] ?? '');

        $recipeText = implode(
            ' ',
            [
                $title,
                $description,
                $category,
                $keywords
            ]
        );

        $ingredientsText = implode(
            ' ',
            $ingredientMap[$recipeId] ?? []
        );

        /*
         * Allergies : exclusion stricte.
         */
        $allergic = false;

        foreach ($allergies as $term) {
            if (matchesTerm($ingredientsText, $term)) {
                $allergic = true;
                break;
            }
        }

        if ($allergic) {
            continue;
        }

        /*
         * Dislikes : exclusion stricte sur titre + ingrédients.
         */
        $disliked = false;

        foreach ($dislikes as $term) {

            if (
                matchesTerm($recipeText, $term) ||
                matchesTerm($ingredientsText, $term)
            ) {
                $disliked = true;
                break;
            }
        }

        if ($disliked) {
            continue;
        }

        /*
         * Score de préférence.
         */
        $score = 0;

        foreach ($foodPreferences as $term) {

            if (matchesTerm($recipeText, $term)) {
                $score += 100;
            }

            if (matchesTerm($ingredientsText, $term)) {
                $score += 80;
            }
        }

        if (
            $thermomixPreferred &&
            (int)$candidate['is_thermomix'] === 1
        ) {
            $score += 60;
        }

        /*
         * Petite part d'aléatoire pour éviter de toujours
         * sélectionner exactement la même recette.
         */
        $score += random_int(0, 20);

        $candidate['_score'] = $score;

        $validCandidates[] = $candidate;
    }

    /*
     * Si les contraintes strictes éliminent tout,
     * on ne choisit surtout pas une recette interdite.
     */
    if (!$validCandidates) {
        throw new RuntimeException(
            'Aucune recette ne respecte les préférences, exclusions et allergies sélectionnées.'
        );
    }

    usort(
        $validCandidates,
        static function (array $a, array $b): int {
            return (int)$b['_score'] <=> (int)$a['_score'];
        }
    );

    $newRecipe = $validCandidates[0];
    $newRecipeId = (int)$newRecipe['id'];

    /*
     * Retirer l'ancien plat.
     */
    $stmt = $pdo->prepare("
        DELETE FROM menu_items
        WHERE weekly_menu_id = ?
          AND menu_date = ?
    ");

    $stmt->execute([
        $weeklyMenuId,
        $date
    ]);

    /*
     * Ajouter le nouveau.
     */
    $stmt = $pdo->prepare("
        INSERT INTO menu_items
            (weekly_menu_id, recipe_id, menu_date, meal_type, servings)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $weeklyMenuId,
        $newRecipeId,
        $date,
        'dinner',
        $peopleCount
    ]);

    /*
     * Remplacer l'historique.
     */
    $stmt = $pdo->prepare("
        DELETE FROM recipe_history
        WHERE user_id = ?
          AND recipe_id = ?
          AND cooked_at = ?
    ");

    $stmt->execute([
        $userId,
        $oldRecipeId,
        $date
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO recipe_history
            (user_id, recipe_id, cooked_at)
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $newRecipeId,
        $date
    ]);

    $pdo->commit();

    /*
     * Régénérer automatiquement la liste de courses
     * après le remplacement de la recette.
     */
    ob_start();

    require __DIR__ . '/generate_shopping_list.php';

    $shoppingOutput = ob_get_clean();

    $shoppingResult = json_decode(
        $shoppingOutput,
        true
    );

    if (!is_array($shoppingResult) || !($shoppingResult['success'] ?? false)) {
        throw new RuntimeException(
            'La recette a été remplacée, mais la liste de courses n’a pas pu être régénérée.'
        );
    }

    echo json_encode(
        [
            'success' => true,
            'date' => $date,
            'recipe_id' => $newRecipeId,
            'title' => $newRecipe['title'],
            'description' => $newRecipe['description'],
            'image_url' => $newRecipe['image_url'],
            'category' => $newRecipe['category'],
            'servings' => $peopleCount
        ],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

} catch (Throwable $e) {

    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'error' => $e->getMessage()
        ],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
}
