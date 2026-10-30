<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';


/*
 * ============================================================
 * MENU INVITÉS — LOGIQUE COMMUNE
 * ============================================================
 */


function guestEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}


function guestFormatQuantity(mixed $quantity): string
{
    if ($quantity === null || $quantity === '') {
        return '';
    }

    $value = (float)$quantity;

    if (abs($value - round($value)) < 0.001) {
        return (string)(int)round($value);
    }

    return rtrim(
        rtrim(
            number_format($value, 2, ',', ' '),
            '0'
        ),
        ','
    );
}


/*
 * Les six rôles du menu invités.
 */
function guestRoles(): array
{
    return [
        'aperitif' => [
            'label' => 'Apéritif',
            'terms' => [
                'apéritif',
                'aperitif',
                'amuse-bouche',
                'amuse bouche',
                'toast',
                'verrine',
                'cocktail',
                'tartinade'
            ]
        ],

        'entree' => [
            'label' => 'Entrée',
            'terms' => [
                'entrée',
                'entree',
                'salade',
                'crudité',
                'crudites',
                'terrine',
                'velouté',
                'veloute',
                'soupe',
                'quiche'
            ]
        ],

        'plat' => [
            'label' => 'Plat',
            'terms' => [
                'plat principal',
                'plat',
                'viande',
                'poisson',
                'poulet',
                'boeuf',
                'bœuf',
                'veau',
                'porc',
                'agneau',
                'pâtes',
                'pates',
                'riz'
            ]
        ],

        'accompagnement' => [
            'label' => 'Accompagnement',
            'terms' => [
                'accompagnement',
                'garniture',
                'légumes',
                'legumes',
                'pommes de terre',
                'purée',
                'puree',
                'riz',
                'pâtes',
                'pates'
            ]
        ],

        'fromage' => [
            'label' => 'Fromage',
            'terms' => [
                'fromage',
                'camembert',
                'chèvre',
                'chevre',
                'comté',
                'comte',
                'brie',
                'roquefort'
            ]
        ],

        'dessert' => [
            'label' => 'Dessert',
            'terms' => [
                'dessert',
                'gâteau',
                'gateau',
                'tarte',
                'crème',
                'creme',
                'mousse',
                'flan',
                'glace',
                'chocolat',
                'pâtisserie',
                'patisserie'
            ]
        ],
    ];
}


/*
 * Recherche d'une recette correspondant à un rôle.
 */
function guestFindRecipe(
    PDO $pdo,
    array $role,
    array $usedIds,
    ?int $excludeId = null
): ?array {

    $conditions = [];
    $params = [];

    foreach ($role['terms'] as $term) {

        $conditions[] = '(
            LOWER(COALESCE(r.title, "")) LIKE ?
            OR LOWER(COALESCE(r.category, "")) LIKE ?
            OR LOWER(COALESCE(r.keywords, "")) LIKE ?
        )';

        $like = '%' . mb_strtolower($term, 'UTF-8') . '%';

        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = '
        SELECT
            r.id,
            r.title,
            r.description,
            r.image_url,
            r.category,
            r.keywords,
            r.servings,
            r.instructions
        FROM recipes r
        WHERE (' . implode(' OR ', $conditions) . ')
    ';

    if ($usedIds) {
        $placeholders = implode(',', array_fill(0, count($usedIds), '?'));
        $sql .= ' AND r.id NOT IN (' . $placeholders . ') ';
        $params = array_merge($params, array_map('intval', $usedIds));
    }

    if ($excludeId !== null) {
        $sql .= ' AND r.id <> ? ';
        $params[] = $excludeId;
    }

    $sql .= '
        AND COALESCE(r.is_demo, 0) = 0
        ORDER BY RAND()
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $recipe = $stmt->fetch();

    return $recipe ?: null;
}


/*
 * Fallback : une recette quelconque.
 */
function guestFindFallbackRecipe(
    PDO $pdo,
    array $usedIds,
    ?int $excludeId = null
): ?array {

    $sql = '
        SELECT
            r.id,
            r.title,
            r.description,
            r.image_url,
            r.category,
            r.keywords,
            r.servings,
            r.instructions
        FROM recipes r
        WHERE COALESCE(r.is_demo, 0) = 0
    ';

    $params = [];

    if ($usedIds) {
        $placeholders = implode(',', array_fill(0, count($usedIds), '?'));

        $sql .= ' AND r.id NOT IN (' . $placeholders . ') ';

        $params = array_map('intval', $usedIds);
    }

    if ($excludeId !== null) {
        $sql .= ' AND r.id <> ? ';
        $params[] = $excludeId;
    }

    $sql .= '
        ORDER BY RAND()
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $recipe = $stmt->fetch();

    return $recipe ?: null;
}


/*
 * Génération complète des six recettes.
 */
function guestGenerateMenu(PDO $pdo): array
{
    $roles = guestRoles();

    $menu = [];
    $usedIds = [];

    foreach ($roles as $roleKey => $role) {

        $recipe = guestFindRecipe(
            $pdo,
            $role,
            $usedIds
        );

        if (!$recipe) {
            $recipe = guestFindFallbackRecipe(
                $pdo,
                $usedIds
            );
        }

        if (!$recipe) {
            continue;
        }

        $recipeId = (int)$recipe['id'];

        $usedIds[] = $recipeId;

        $menu[$roleKey] = [
            'role' => $roleKey,
            'role_label' => $role['label'],
            'recipe_id' => $recipeId,
            'title' => (string)$recipe['title'],
            'description' => (string)($recipe['description'] ?? ''),
            'image_url' => (string)($recipe['image_url'] ?? ''),
            'category' => (string)($recipe['category'] ?? ''),
            'servings' => (int)($recipe['servings'] ?? 0),
        ];
    }

    return $menu;
}


/*
 * Remplacement d'une seule recette.
 */
function guestReplaceRecipe(
    PDO $pdo,
    array $menu,
    string $roleKey
): array {

    $roles = guestRoles();

    if (!isset($roles[$roleKey])) {
        return $menu;
    }

    if (!isset($menu[$roleKey])) {
        return $menu;
    }

    $oldId = (int)$menu[$roleKey]['recipe_id'];

    $usedIds = [];

    foreach ($menu as $key => $item) {

        if ($key === $roleKey) {
            continue;
        }

        $usedIds[] = (int)$item['recipe_id'];
    }

    $recipe = guestFindRecipe(
        $pdo,
        $roles[$roleKey],
        $usedIds,
        $oldId
    );

    if (!$recipe) {
        $recipe = guestFindFallbackRecipe(
            $pdo,
            $usedIds,
            $oldId
        );
    }

    if (!$recipe) {
        return $menu;
    }

    $menu[$roleKey] = [
        'role' => $roleKey,
        'role_label' => $roles[$roleKey]['label'],
        'recipe_id' => (int)$recipe['id'],
        'title' => (string)$recipe['title'],
        'description' => (string)($recipe['description'] ?? ''),
        'image_url' => (string)($recipe['image_url'] ?? ''),
        'category' => (string)($recipe['category'] ?? ''),
        'servings' => (int)($recipe['servings'] ?? 0),
    ];

    return $menu;
}


/*
 * Récupération complète d'une recette.
 */
function guestGetRecipe(PDO $pdo, int $recipeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            id,
            title,
            description,
            image_url,
            prep_time_minutes,
            cook_time_minutes,
            total_time_minutes,
            servings,
            category,
            keywords,
            instructions
         FROM recipes
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([$recipeId]);

    $recipe = $stmt->fetch();

    if (!$recipe) {
        return null;
    }

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

    $recipe['ingredients'] = $ingredientStmt->fetchAll();

    return $recipe;
}


/*
 * Liste de courses calculée à partir du menu invités.
 *
 * Chaque recette est ramenée au nombre de convives demandé.
 */
function guestBuildShoppingList(
    PDO $pdo,
    array $menu,
    int $people
): array {

    $grouped = [];

    foreach ($menu as $item) {

        $recipeId = (int)$item['recipe_id'];

        $recipe = guestGetRecipe(
            $pdo,
            $recipeId
        );

        if (!$recipe) {
            continue;
        }

        $recipeServings = (int)($recipe['servings'] ?? 0);

        if ($recipeServings <= 0) {
            $recipeServings = $people;
        }

        $multiplier = $people / $recipeServings;

        foreach ($recipe['ingredients'] as $ingredient) {

            $name = trim(
                (string)($ingredient['ingredient_name'] ?? '')
            );

            if ($name === '') {
                $name = trim(
                    (string)($ingredient['raw_text'] ?? '')
                );
            }

            if ($name === '') {
                continue;
            }

            $unit = trim(
                (string)($ingredient['unit'] ?? '')
            );

            $normalizedName = mb_strtolower(
                $name,
                'UTF-8'
            );

            $normalizedName = preg_replace(
                '/\s+/',
                ' ',
                $normalizedName
            );

            $normalizedUnit = mb_strtolower(
                $unit,
                'UTF-8'
            );

            $key = $normalizedName . '|' . $normalizedUnit;

            if (!isset($grouped[$key])) {

                $grouped[$key] = [
                    'ingredient_name' => $name,
                    'quantity' => 0,
                    'unit' => $unit,
                ];
            }

            if (
                $ingredient['quantity'] !== null &&
                $ingredient['quantity'] !== ''
            ) {

                $grouped[$key]['quantity'] +=
                    (float)$ingredient['quantity']
                    * $multiplier;
            }
        }
    }

    uasort(
        $grouped,
        static function (array $a, array $b): int {
            return strcasecmp(
                $a['ingredient_name'],
                $b['ingredient_name']
            );
        }
    );

    return array_values($grouped);
}


/*
 * Clé de session.
 */
function guestSessionKey(
    string $eventDate,
    int $people
): string {

    return 'guest_menu_' .
        $eventDate .
        '_' .
        $people;
}
