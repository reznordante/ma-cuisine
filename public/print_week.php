<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$pdo = db();
$userId = requireAuth();

$today = new DateTimeImmutable('today');
$weekStart = $today->modify('monday this week');
$weekEnd = $weekStart->modify('+4 days');

$weekStartSql = $weekStart->format('Y-m-d');

$monthNames = [
    1 => 'janvier',
    2 => 'février',
    3 => 'mars',
    4 => 'avril',
    5 => 'mai',
    6 => 'juin',
    7 => 'juillet',
    8 => 'août',
    9 => 'septembre',
    10 => 'octobre',
    11 => 'novembre',
    12 => 'décembre',
];

$weekLabel =
    'Du ' . $weekStart->format('j') .
    ' au ' . $weekEnd->format('j') .
    ' ' . $monthNames[(int)$weekEnd->format('n')] .
    ' ' . $weekEnd->format('Y');

$dayNames = [
    'Monday' => 'Lundi',
    'Tuesday' => 'Mardi',
    'Wednesday' => 'Mercredi',
    'Thursday' => 'Jeudi',
    'Friday' => 'Vendredi',
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatQuantity(mixed $quantity): string
{
    if ($quantity === null || $quantity === '') {
        return '';
    }

    $value = (float)$quantity;

    if (floor($value) === $value) {
        return (string)(int)$value;
    }

    return rtrim(
        rtrim(
            number_format($value, 3, ',', ' '),
            '0'
        ),
        ','
    );
}

/*
 * Récupération du menu de la semaine.
 */
$stmt = $pdo->prepare(
    'SELECT
        wm.id AS weekly_menu_id,
        mi.menu_date,
        mi.servings,
        r.id AS recipe_id,
        r.title,
        r.description,
        r.category,
        r.servings AS recipe_servings,
        r.instructions
     FROM weekly_menus wm
     INNER JOIN menu_items mi
        ON mi.weekly_menu_id = wm.id
     INNER JOIN recipes r
        ON r.id = mi.recipe_id
     WHERE wm.user_id = ?
       AND wm.week_start = ?
     ORDER BY mi.menu_date'
);

$stmt->execute([
    $userId,
    $weekStartSql
]);

$menu = $stmt->fetchAll();

/*
 * Liste de courses déjà générée pour cette semaine.
 */
$shoppingItems = [];

$stmt = $pdo->prepare(
    'SELECT
        si.ingredient_name,
        si.quantity,
        si.unit,
        si.checked
     FROM shopping_lists sl
     INNER JOIN shopping_items si
        ON si.shopping_list_id = sl.id
     WHERE sl.user_id = ?
       AND sl.weekly_menu_id = (
           SELECT id
           FROM weekly_menus
           WHERE user_id = ?
             AND week_start = ?
           LIMIT 1
       )
     ORDER BY si.ingredient_name ASC'
);

$stmt->execute([
    $userId,
    $userId,
    $weekStartSql
]);

$shoppingItems = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Ma Cuisine — <?= e($weekLabel) ?></title>

<style>

@page {
    size: A4;
    margin: 18mm;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family:
        Inter,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    color: #242820;
    background: white;
    font-size: 13px;
}

.page {
    page-break-after: always;
}

.page:last-child {
    page-break-after: auto;
}

.header {
    border-bottom: 3px solid #5f7563;
    padding-bottom: 14px;
    margin-bottom: 28px;
}

.logo {
    font-size: 26px;
    font-weight: 800;
    color: #5f7563;
}

.subtitle {
    margin-top: 5px;
    color: #777;
    font-size: 14px;
}

h1 {
    font-size: 25px;
    margin: 0 0 22px;
}

h2 {
    font-size: 21px;
    margin: 0 0 18px;
    color: #5f7563;
}

.menu-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 16px;
}

.menu-table th {
    background: #5f7563;
    color: white;
    text-align: left;
    padding: 13px 15px;
}

.menu-table td {
    padding: 15px;
    border-bottom: 1px solid #ddd;
}

.menu-table tr:nth-child(even) td {
    background: #f7f3ec;
}

.day {
    width: 25%;
    font-weight: 800;
    color: #c97858;
}

.recipe {
    font-weight: 700;
}

.recipe-block {
    margin-bottom: 28px;
}

.recipe-title {
    font-size: 20px;
    font-weight: 800;
    color: #5f7563;
    border-bottom: 2px solid #ddd;
    padding-bottom: 8px;
    margin-bottom: 12px;
}

.recipe-meta {
    font-size: 12px;
    color: #777;
    margin-bottom: 18px;
}

.section-title {
    font-weight: 800;
    font-size: 15px;
    margin: 16px 0 8px;
    color: #333;
}

.ingredients {
    margin: 0;
    padding-left: 22px;
}

.ingredients li {
    margin-bottom: 5px;
}

.instructions {
    white-space: normal;
    line-height: 1.55;
}

.shopping-list {
    columns: 2;
    column-gap: 35px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.shopping-item {
    break-inside: avoid;
    padding: 5px 0;
    border-bottom: 1px solid #eee;
}

.shopping-name {
    font-weight: 600;
}

.shopping-quantity {
    color: #666;
    margin-left: 5px;
}

.empty {
    padding: 18px;
    background: #f7f3ec;
    border-radius: 10px;
    color: #777;
}

.print-button {
    position: fixed;
    top: 18px;
    right: 18px;
    background: #5f7563;
    color: white;
    border: 0;
    border-radius: 9px;
    padding: 10px 15px;
    font-weight: 700;
    cursor: pointer;
}

@media print {
    .print-button {
        display: none;
    }
}

</style>
</head>

<body>

<button class="print-button" onclick="window.print()">
    🖨️ Imprimer / PDF
</button>


<!-- ========================================================= -->
<!-- PAGE 1 : MENU DE LA SEMAINE -->
<!-- ========================================================= -->

<section class="page">

    <div class="header">
        <div class="logo">🍳 Ma Cuisine</div>
        <div class="subtitle">
            Menu de la semaine — <?= e($weekLabel) ?>
        </div>
    </div>

    <h1>Ma semaine</h1>

    <?php if (!$menu): ?>

        <div class="empty">
            Aucune semaine n'est enregistrée pour cette période.
        </div>

    <?php else: ?>

        <table class="menu-table">

            <thead>
                <tr>
                    <th>Jour</th>
                    <th>Recette</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($menu as $item): ?>

                <?php
                $date = new DateTimeImmutable($item['menu_date']);
                $dayName = $dayNames[$date->format('l')]
                    ?? $date->format('d/m/Y');
                ?>

                <tr>
                    <td class="day">
                        <?= e($dayName) ?>
                    </td>

                    <td class="recipe">
                        <?= e((string)$item['title']) ?>
                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    <?php endif; ?>

</section>


<!-- ========================================================= -->
<!-- PAGE 2 : LISTE DE COURSES -->
<!-- ========================================================= -->

<section class="page">

    <div class="header">
        <div class="logo">🛒 Liste de courses</div>
        <div class="subtitle">
            Semaine <?= e($weekLabel) ?>
        </div>
    </div>

    <h1>Mes courses</h1>

    <?php if ($shoppingItems): ?>

        <ul class="shopping-list">

            <?php foreach ($shoppingItems as $item): ?>

                <?php
                $quantity = formatQuantity($item['quantity']);
                $unit = trim((string)$item['unit']);
                ?>

                <li class="shopping-item">

                    <span class="shopping-name">
                        <?= e((string)$item['ingredient_name']) ?>
                    </span>

                    <?php if ($quantity !== '' || $unit !== ''): ?>

                        <span class="shopping-quantity">
                            <?= e(trim($quantity . ' ' . $unit)) ?>
                        </span>

                    <?php endif; ?>

                </li>

            <?php endforeach; ?>

        </ul>

    <?php else: ?>

        <div class="empty">
            Aucune liste de courses n'est disponible pour cette semaine.
        </div>

    <?php endif; ?>

</section>


<!-- ========================================================= -->
<!-- UNE PAGE PAR RECETTE -->
<!-- ========================================================= -->

<?php foreach ($menu as $item): ?>

<?php

$recipeDate = new DateTimeImmutable($item['menu_date']);

$recipeDayName =
    $dayNames[$recipeDate->format('l')] ?? '';

$recipeDateLabel =
    strtoupper($recipeDayName) . ' ' .
    $recipeDate->format('j') . ' ' .
    strtoupper($monthNames[(int)$recipeDate->format('n')]) . ' ' .
    $recipeDate->format('Y');

$recipeId = (int)$item['recipe_id'];

$recipeServings = (int)$item['recipe_servings'];
$menuServings = (int)$item['servings'];

if ($recipeServings <= 0) {
    $recipeServings = $menuServings > 0 ? $menuServings : 1;
}

if ($menuServings <= 0) {
    $menuServings = $recipeServings;
}

$multiplier = $menuServings / $recipeServings;


/*
 * Ingrédients de la recette.
 */
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

$ingredientStmt->execute([
    $recipeId
]);

$ingredients = $ingredientStmt->fetchAll();

?>

<section class="page">

    <div class="header">
        <div class="logo">🍳 Ma Cuisine</div>

        <div class="subtitle">
            <?= e($recipeDateLabel) ?>
        </div>
    </div>

    <div class="recipe-block">

        <div class="recipe-title">

            <div style="
                font-size: 13px;
                letter-spacing: 1px;
                color: #c97858;
                margin-bottom: 5px;
            ">
                <?= e($recipeDateLabel) ?>
            </div>

            <?= e((string)$item['title']) ?>

        </div>

        <div class="recipe-meta">

            <?= $menuServings ?>
            personne<?= $menuServings > 1 ? 's' : '' ?>

            <?php if (!empty($item['category'])): ?>
                · <?= e((string)$item['category']) ?>
            <?php endif; ?>

        </div>


        <!-- INGREDIENTS -->

        <div class="section-title">
            Ingrédients
        </div>

        <?php if ($ingredients): ?>

            <ul class="ingredients">

                <?php foreach ($ingredients as $ingredient): ?>

                    <?php

                    $name = trim(
                        (string)($ingredient['ingredient_name'] ?? '')
                    );

                    if ($name === '') {
                        $name = trim(
                            (string)$ingredient['raw_text']
                        );
                    }

                    $quantity = null;

                    if (
                        $ingredient['quantity'] !== null &&
                        $ingredient['quantity'] !== ''
                    ) {
                        $quantity =
                            (float)$ingredient['quantity']
                            * $multiplier;
                    }

                    $quantityLabel =
                        formatQuantity($quantity);

                    $unit =
                        trim((string)$ingredient['unit']);

                    ?>

                    <li>

                        <?php if ($quantityLabel !== ''): ?>
                            <?= e($quantityLabel) ?>
                        <?php endif; ?>

                        <?php if ($unit !== ''): ?>
                            <?= e($unit) ?>
                        <?php endif; ?>

                        <?php if (
                            $quantityLabel !== '' ||
                            $unit !== ''
                        ): ?>
                            —
                        <?php endif; ?>

                        <?= e($name) ?>

                    </li>

                <?php endforeach; ?>

            </ul>

        <?php else: ?>

            <div class="empty">
                Aucun ingrédient disponible pour cette recette.
            </div>

        <?php endif; ?>


        <!-- PREPARATION -->

        <div class="section-title">
            Préparation
        </div>

        <?php if (trim((string)$item['instructions']) !== ''): ?>

            <div class="instructions">
                <?= nl2br(
                    e((string)$item['instructions'])
                ) ?>
            </div>

        <?php else: ?>

            <div class="empty">
                Aucune préparation disponible pour cette recette.
            </div>

        <?php endif; ?>

    </div>

</section>

<?php endforeach; ?>

</body>
</html>
