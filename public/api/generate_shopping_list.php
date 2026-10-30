<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();

    $userId = requireApiAuth();

    $date = new DateTimeImmutable('today');
    $weekStart = $date->modify('monday this week')->format('Y-m-d');

    /*
     * Récupération du menu de la semaine.
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM weekly_menus
        WHERE user_id = ?
          AND week_start = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $weekStart]);

    $weeklyMenuId = $stmt->fetchColumn();

    if (!$weeklyMenuId) {
        throw new RuntimeException("Aucun menu n'existe pour cette semaine.");
    }

    /*
     * Récupération des recettes du menu.
     */
    $stmt = $pdo->prepare("
        SELECT
            mi.recipe_id,
            mi.servings AS menu_servings,
            r.servings AS recipe_servings
        FROM menu_items mi
        INNER JOIN recipes r ON r.id = mi.recipe_id
        WHERE mi.weekly_menu_id = ?
        ORDER BY mi.menu_date, mi.id
    ");
    $stmt->execute([(int)$weeklyMenuId]);

    $menuItems = $stmt->fetchAll();

    if (!$menuItems) {
        throw new RuntimeException("Le menu de la semaine ne contient aucune recette.");
    }

    /*
     * Regroupement des ingrédients.
     *
     * La clé utilise le nom normalisé + unité afin de ne pas
     * additionner par erreur "500 g" et "2 cuillères à soupe".
     */
    $grouped = [];

    foreach ($menuItems as $menuItem) {

        $recipeId = (int)$menuItem['recipe_id'];

        $recipeServings = (int)$menuItem['recipe_servings'];
        $menuServings = (int)$menuItem['menu_servings'];

        if ($menuServings <= 0) {
            $menuServings = $recipeServings > 0 ? $recipeServings : 1;
        }

        if ($recipeServings <= 0) {
            $recipeServings = $menuServings;
        }

        $multiplier = $menuServings / $recipeServings;

        $ingredientStmt = $pdo->prepare("
            SELECT
                raw_text,
                quantity,
                unit,
                ingredient_name,
                position
            FROM recipe_ingredients
            WHERE recipe_id = ?
            ORDER BY position ASC, id ASC
        ");

        $ingredientStmt->execute([$recipeId]);

        foreach ($ingredientStmt->fetchAll() as $ingredient) {

            $name = trim((string)($ingredient['ingredient_name'] ?? ''));

            if ($name === '') {
                $name = trim((string)$ingredient['raw_text']);
            }

            if ($name === '') {
                continue;
            }

            $unit = trim((string)($ingredient['unit'] ?? ''));

            /*
             * Normalisation uniquement pour le regroupement.
             * Le nom affiché reste celui de la première occurrence.
             */
            $normalizedName = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);

            $normalizedName = preg_replace('/\s+/', ' ', $normalizedName);
            $normalizedName = trim($normalizedName);

            $normalizedUnit = function_exists('mb_strtolower')
                ? mb_strtolower($unit, 'UTF-8')
                : strtolower($unit);

            $normalizedUnit = preg_replace('/\s+/', ' ', $normalizedUnit);
            $normalizedUnit = trim($normalizedUnit);

            $key = $normalizedName . '|' . $normalizedUnit;

            $quantity = null;

            if ($ingredient['quantity'] !== null && $ingredient['quantity'] !== '') {
                $quantity = (float)$ingredient['quantity'];
                $quantity *= $multiplier;
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'ingredient_name' => $name,
                    'quantity' => $quantity,
                    'unit' => $unit,
                ];
            } else {
                if ($quantity !== null) {
                    if ($grouped[$key]['quantity'] === null) {
                        $grouped[$key]['quantity'] = $quantity;
                    } else {
                        $grouped[$key]['quantity'] += $quantity;
                    }
                }
            }
        }
    }

    /*
     * Création/remplacement de la liste de courses.
     */
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id
        FROM shopping_lists
        WHERE user_id = ?
          AND weekly_menu_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$userId, (int)$weeklyMenuId]);

    $shoppingListId = $stmt->fetchColumn();

    if ($shoppingListId) {

        $delete = $pdo->prepare("
            DELETE FROM shopping_items
            WHERE shopping_list_id = ?
        ");
        $delete->execute([(int)$shoppingListId]);

    } else {

        $insert = $pdo->prepare("
            INSERT INTO shopping_lists
                (user_id, weekly_menu_id)
            VALUES (?, ?)
        ");

        $insert->execute([
            $userId,
            (int)$weeklyMenuId
        ]);

        $shoppingListId = (int)$pdo->lastInsertId();
    }

    /*
     * Insertion des ingrédients.
     */
    $insertItem = $pdo->prepare("
        INSERT INTO shopping_items
            (shopping_list_id, ingredient_name, quantity, unit, checked)
        VALUES (?, ?, ?, ?, 0)
    ");

    foreach ($grouped as $item) {
        $insertItem->execute([
            (int)$shoppingListId,
            $item['ingredient_name'],
            $item['quantity'],
            $item['unit'] !== '' ? $item['unit'] : null,
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'shopping_list_id' => (int)$shoppingListId,
        'items_count' => count($grouped),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
