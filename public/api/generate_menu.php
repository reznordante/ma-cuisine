<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = null;

try {
    $pdo = db();

    $userId = requireApiAuth();

    $forceRegenerate = filter_input(
        INPUT_GET,
        'force',
        FILTER_VALIDATE_INT
    ) === 1;

    require_once __DIR__ . '/menu_preferences.php';

    $menuPreferences = menuLoadPreferences($pdo, $userId);
    $peopleCount = $menuPreferences['people_count'];
    $sourceIds = menuSourceIds($menuPreferences);


    // Lundi de la semaine courante
    $today = new DateTimeImmutable('today');
    $weekStart = $today->modify('monday this week');
    $weekStartSql = $weekStart->format('Y-m-d');

    $pdo->beginTransaction();

    // Vérifier si une semaine existe déjà.
    $stmt = $pdo->prepare(
        'SELECT id
         FROM weekly_menus
         WHERE user_id = ?
           AND week_start = ?
         LIMIT 1'
    );

    $stmt->execute([
        $userId,
        $weekStartSql
    ]);

    $weeklyMenuId = (int)($stmt->fetchColumn() ?: 0);

    // Une semaine existe déjà et aucune régénération n'est demandée.
    if ($weeklyMenuId !== 0 && !$forceRegenerate) {

        $stmt = $pdo->prepare(
            'SELECT
                mi.menu_date,
                mi.servings,
                r.id AS recipe_id,
                r.title,
                r.description,
                r.image_url,
                r.category
             FROM menu_items mi
             INNER JOIN recipes r
                ON r.id = mi.recipe_id
             WHERE mi.weekly_menu_id = ?
             ORDER BY mi.menu_date'
        );

        $stmt->execute([$weeklyMenuId]);

        $existingMenu = $stmt->fetchAll();

        $pdo->commit();

        echo json_encode(
            [
                'success' => true,
                'already_exists' => true,
                'regenerated' => false,
                'week_start' => $weekStartSql,
                'people_count' => $peopleCount,
                'menu' => $existingMenu
            ],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        exit;
    }

    // Créer la semaine si nécessaire.
    if ($weeklyMenuId === 0) {

        $stmt = $pdo->prepare(
            'INSERT INTO weekly_menus
             (user_id, week_start)
             VALUES (?, ?)'
        );

        $stmt->execute([
            $userId,
            $weekStartSql
        ]);

        $weeklyMenuId = (int)$pdo->lastInsertId();

    } else {

        // Régénération : supprimer les repas actuels.
        $stmt = $pdo->prepare(
            'DELETE FROM menu_items
             WHERE weekly_menu_id = ?'
        );

        $stmt->execute([$weeklyMenuId]);

        // Supprimer l'historique de cette semaine.
        $stmt = $pdo->prepare(
            'DELETE FROM recipe_history
             WHERE user_id = ?
               AND cooked_at BETWEEN ?
               AND DATE_ADD(?, INTERVAL 4 DAY)'
        );

        $stmt->execute([
            $userId,
            $weekStartSql,
            $weekStartSql
        ]);
    }

    // Recettes utilisées durant les 8 dernières semaines.
    $stmt = $pdo->prepare(
        'SELECT DISTINCT recipe_id
         FROM recipe_history
         WHERE user_id = ?
           AND cooked_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)'
    );
    $stmt->execute([$userId]);
    $recentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));

    $sql = "
        SELECT id, title, description, image_url, category, keywords, servings, is_thermomix
        FROM recipes
        WHERE is_demo = 0
          AND recipe_type = 'main'
          AND source_id IN ($placeholders)
        ORDER BY RAND()
        LIMIT 5000
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($sourceIds);
    $recipes = $stmt->fetchAll();

    if (count($recipes) < 5) {
        throw new RuntimeException(
            'Il n’y a pas suffisamment de plats principaux disponibles avec les sources sélectionnées.'
        );
    }

    $candidateIds = array_map(static fn(array $recipe): int => (int)$recipe['id'], $recipes);
    $ingredientTexts = menuLoadIngredientTexts($pdo, $candidateIds);
    $eligible = [];

    foreach ($recipes as $recipe) {
        $recipeId = (int)$recipe['id'];
        $ingredientText = $ingredientTexts[$recipeId] ?? '';

        if (!menuRecipeAllowed($recipe, $ingredientText, $menuPreferences)) {
            continue;
        }

        $recipe['_score'] = menuRecipeScore($recipe, $ingredientText, $menuPreferences);
        $recipe['_recent'] = in_array($recipeId, $recentIds, true);
        $eligible[] = $recipe;
    }

    usort($eligible, static function (array $a, array $b): int {
        if ($a['_recent'] !== $b['_recent']) {
            return $a['_recent'] <=> $b['_recent'];
        }
        return $b['_score'] <=> $a['_score'];
    });

    /*
     * Sélection progressive avec diversification.
     *
     * On conserve les critères existants (récence + score),
     * mais on pénalise les recettes qui partagent trop
     * d'ingrédients significatifs avec les recettes déjà choisies.
     */
    $selected = [];
    $selectedIds = [];
    $selectedTerms = [];

    $stopWords = [
        'avec', 'sans', 'dans', 'pour', 'les', 'des', 'une', 'aux',
        'sur', 'sous', 'entre', 'plus', 'moins', 'tres', 'sel',
        'poivre', 'huile', 'eau', 'cuisson', 'gout'
    ];

    $extractTerms = static function (array $recipe, string $ingredientText) use ($stopWords): array {
        $text = menuNormalize(
            (string)$recipe['title'] . ' ' .
            (string)($recipe['category'] ?? '') . ' ' .
            (string)($recipe['keywords'] ?? '') . ' ' .
            $ingredientText
        );

        $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $terms = [];
        foreach ($tokens as $token) {
            if (strlen($token) < 5) continue;
            if (in_array($token, $stopWords, true)) continue;
            $terms[$token] = true;
        }

        return array_keys($terms);
    };

    while (count($selected) < 5) {
        $bestIndex = null;
        $bestValue = null;

        foreach ($eligible as $index => $recipe) {
            $recipeId = (int)$recipe['id'];

            if (in_array($recipeId, $selectedIds, true)) {
                continue;
            }

            $terms = $extractTerms(
                $recipe,
                $ingredientTexts[$recipeId] ?? ''
            );

            $overlap = count(array_intersect($terms, $selectedTerms));

            /*
             * Chaque terme significatif déjà rencontré retire 12 points.
             * Les goûts/score existants restent donc prioritaires,
             * mais la sélection évite les semaines trop répétitives.
             */
            $diversityScore = (int)$recipe['_score'] - ($overlap * 12);

            /*
             * Une recette non récente reste prioritaire comme auparavant.
             * On ajoute simplement la diversité comme second niveau.
             */
            $sortValue = ($recipe['_recent'] ? -100000 : 0) + $diversityScore;

            if ($bestValue === null || $sortValue > $bestValue) {
                $bestValue = $sortValue;
                $bestIndex = $index;
            }
        }

        if ($bestIndex === null) {
            break;
        }

        $recipe = $eligible[$bestIndex];
        $recipeId = (int)$recipe['id'];

        $terms = $extractTerms(
            $recipe,
            $ingredientTexts[$recipeId] ?? ''
        );

        $selected[] = $recipe;
        $selectedIds[] = $recipeId;
        $selectedTerms = array_values(array_unique(
            array_merge($selectedTerms, $terms)
        ));
    }

    foreach ($selected as &$recipe) {
        unset($recipe['_score'], $recipe['_recent']);
    }
    unset($recipe);

    if (count($selected) !== 5) {
        throw new RuntimeException(
            'Impossible de trouver 5 plats principaux différents respectant les préférences, exclusions et sources sélectionnées.'
        );
    }

    $days = [
        'Lundi' => $weekStart,
        'Mardi' => $weekStart->modify('+1 day'),
        'Mercredi' => $weekStart->modify('+2 days'),
        'Jeudi' => $weekStart->modify('+3 days'),
        'Vendredi' => $weekStart->modify('+4 days'),
    ];

    $insertItem = $pdo->prepare(
        'INSERT INTO menu_items
         (weekly_menu_id, recipe_id, menu_date, meal_type, servings)
         VALUES (?, ?, ?, ?, ?)'
    );

    $insertHistory = $pdo->prepare(
        'INSERT INTO recipe_history
         (user_id, recipe_id, cooked_at)
         VALUES (?, ?, ?)'
    );

    $result = [];

    foreach (array_keys($days) as $index => $dayName) {

        $date = $days[$dayName];
        $recipe = $selected[$index];

        $dateString = $date->format('Y-m-d');

        $insertItem->execute([
            $weeklyMenuId,
            (int)$recipe['id'],
            $dateString,
            'dinner',
            $peopleCount
        ]);

        $insertHistory->execute([
            $userId,
            (int)$recipe['id'],
            $dateString
        ]);

        $result[] = [
            'day' => $dayName,
            'date' => $dateString,
            'recipe_id' => (int)$recipe['id'],
            'title' => $recipe['title'],
            'description' => $recipe['description'],
            'image_url' => $recipe['image_url'],
            'category' => $recipe['category'],
            'servings' => $peopleCount
        ];
    }

    /*
     * Génération automatique de la liste de courses
     * à partir du menu nouvellement créé.
     */
    $stmt = $pdo->prepare(
        'SELECT
            mi.recipe_id,
            mi.servings AS menu_servings,
            r.servings AS recipe_servings
         FROM menu_items mi
         INNER JOIN recipes r
            ON r.id = mi.recipe_id
         WHERE mi.weekly_menu_id = ?
         ORDER BY mi.menu_date, mi.id'
    );

    $stmt->execute([$weeklyMenuId]);

    $menuItems = $stmt->fetchAll();

    if (!$menuItems) {
        throw new RuntimeException(
            'Le menu généré ne contient aucune recette.'
        );
    }

    $grouped = [];

    foreach ($menuItems as $menuItem) {

        $recipeId = (int)$menuItem['recipe_id'];

        $recipeServings = (int)$menuItem['recipe_servings'];
        $menuServings = (int)$menuItem['menu_servings'];

        if ($menuServings <= 0) {
            $menuServings = $recipeServings > 0
                ? $recipeServings
                : 1;
        }

        if ($recipeServings <= 0) {
            $recipeServings = $menuServings;
        }

        $multiplier = $menuServings / $recipeServings;

        $ingredientStmt = $pdo->prepare(
            'SELECT
                raw_text,
                quantity,
                unit,
                ingredient_name,
                position
             FROM recipe_ingredients
             WHERE recipe_id = ?
             ORDER BY position ASC, id ASC'
        );

        $ingredientStmt->execute([$recipeId]);

        foreach ($ingredientStmt->fetchAll() as $ingredient) {

            $name = trim(
                (string)($ingredient['ingredient_name'] ?? '')
            );

            if ($name === '') {
                $name = trim(
                    (string)$ingredient['raw_text']
                );
            }

            if ($name === '') {
                continue;
            }

            $unit = trim(
                (string)($ingredient['unit'] ?? '')
            );

            $normalizedName = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);

            $normalizedName = preg_replace(
                '/\s+/',
                ' ',
                $normalizedName
            );

            $normalizedName = trim($normalizedName);

            $normalizedUnit = function_exists('mb_strtolower')
                ? mb_strtolower($unit, 'UTF-8')
                : strtolower($unit);

            $normalizedUnit = preg_replace(
                '/\s+/',
                ' ',
                $normalizedUnit
            );

            $normalizedUnit = trim($normalizedUnit);

            $key = $normalizedName . '|' . $normalizedUnit;

            $quantity = null;

            if (
                $ingredient['quantity'] !== null &&
                $ingredient['quantity'] !== ''
            ) {
                $quantity = (float)$ingredient['quantity'];
                $quantity *= $multiplier;
            }

            if (!isset($grouped[$key])) {

                $grouped[$key] = [
                    'ingredient_name' => $name,
                    'quantity' => $quantity,
                    'unit' => $unit,
                ];

            } elseif ($quantity !== null) {

                if ($grouped[$key]['quantity'] === null) {
                    $grouped[$key]['quantity'] = $quantity;
                } else {
                    $grouped[$key]['quantity'] += $quantity;
                }
            }
        }
    }

    /*
     * Remplacer la liste de courses de cette semaine.
     * Les articles cochés sont donc réinitialisés.
     */
    $stmt = $pdo->prepare(
        'SELECT id
         FROM shopping_lists
         WHERE user_id = ?
           AND weekly_menu_id = ?
         LIMIT 1
         FOR UPDATE'
    );

    $stmt->execute([
        $userId,
        $weeklyMenuId
    ]);

    $shoppingListId = $stmt->fetchColumn();

    if ($shoppingListId) {

        $delete = $pdo->prepare(
            'DELETE FROM shopping_items
             WHERE shopping_list_id = ?'
        );

        $delete->execute([
            (int)$shoppingListId
        ]);

    } else {

        $insert = $pdo->prepare(
            'INSERT INTO shopping_lists
             (user_id, weekly_menu_id)
             VALUES (?, ?)'
        );

        $insert->execute([
            $userId,
            $weeklyMenuId
        ]);

        $shoppingListId = (int)$pdo->lastInsertId();
    }

    $insertItem = $pdo->prepare(
        'INSERT INTO shopping_items
         (shopping_list_id, ingredient_name, quantity, unit, checked)
         VALUES (?, ?, ?, ?, 0)'
    );

    foreach ($grouped as $item) {

        $insertItem->execute([
            (int)$shoppingListId,
            $item['ingredient_name'],
            $item['quantity'],
            $item['unit'] !== '' ? $item['unit'] : null,
        ]);
    }

    $pdo->commit();

    echo json_encode(
        [
            'success' => true,
            'already_exists' => false,
            'regenerated' => $forceRegenerate,
            'week_start' => $weekStartSql,
            'people_count' => $peopleCount,
            'menu' => $result
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
