<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/guest_menu_lib.php';

$pdo = db();

$people = isset($_GET['people'])
    ? (int)$_GET['people']
    : 6;

$people = max(2, min(20, $people));

$day = $_GET['day'] ?? 'saturday';

if (!in_array($day, ['saturday', 'sunday'], true)) {
    $day = 'saturday';
}

$today = new DateTimeImmutable('today');
$monday = $today->modify('monday this week');

$eventDate = $day === 'saturday'
    ? $monday->modify('+5 days')
    : $monday->modify('+6 days');

$eventDateSql = $eventDate->format('Y-m-d');

$sessionKey = guestSessionKey(
    $eventDateSql,
    $people
);

$menu = [];

if (
    isset($_SESSION[$sessionKey]) &&
    is_array($_SESSION[$sessionKey])
) {
    $menu = $_SESSION[$sessionKey];
}

if (!$menu) {
    http_response_code(404);
    exit('Aucun menu invités n’a été généré.');
}

$shoppingItems = guestBuildShoppingList(
    $pdo,
    $menu,
    $people
);

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

$dayLabel = $day === 'saturday'
    ? 'Samedi'
    : 'Dimanche';

$dateLabel =
    $eventDate->format('j') .
    ' ' .
    $monthNames[(int)$eventDate->format('n')] .
    ' ' .
    $eventDate->format('Y');

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatQuantity(mixed $quantity): string
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
            number_format(
                $value,
                2,
                ',',
                ' '
            ),
            '0'
        ),
        ','
    );
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Ma Cuisine — Menu invités
</title>

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

.menu-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 15px;
}

.menu-table th {
    background: #5f7563;
    color: white;
    text-align: left;
    padding: 13px 15px;
}

.menu-table td {
    padding: 13px 15px;
    border-bottom: 1px solid #ddd;
}

.menu-table tr:nth-child(even) td {
    background: #f7f3ec;
}

.role {
    width: 28%;
    font-weight: 800;
    color: #c97858;
}

.recipe {
    font-weight: 700;
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
    line-height: 1.55;
    white-space: normal;
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

<button
    class="print-button"
    onclick="window.print()"
>
    🖨️ Imprimer / PDF
</button>


<section class="page">

    <div class="header">

        <div class="logo">
            🍳 Ma Cuisine
        </div>

        <div class="subtitle">
            Menu invités —
            <?= e($dayLabel) ?>
            <?= e($dateLabel) ?>
            ·
            <?= $people ?>
            personne<?= $people > 1 ? 's' : '' ?>
        </div>

    </div>

    <h1>
        🎉 Menu invités
    </h1>

    <table class="menu-table">

        <thead>
            <tr>
                <th>Moment</th>
                <th>Recette</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ($menu as $item): ?>

            <tr>

                <td class="role">
                    <?= e($item['role_label']) ?>
                </td>

                <td class="recipe">
                    <?= e($item['title']) ?>
                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

</section>


<section class="page">

    <div class="header">

        <div class="logo">
            🛒 Ma Cuisine
        </div>

        <div class="subtitle">
            Courses —
            <?= e($dayLabel) ?>
            <?= e($dateLabel) ?>
        </div>

    </div>

    <h1>
        Liste de courses
    </h1>

    <?php if ($shoppingItems): ?>

        <ul class="shopping-list">

        <?php foreach ($shoppingItems as $item): ?>

            <?php
            $quantity = formatQuantity(
                $item['quantity']
            );

            $unit = trim(
                (string)$item['unit']
            );
            ?>

            <li class="shopping-item">

                <span class="shopping-name">
                    <?= e($item['ingredient_name']) ?>
                </span>

                <?php if (
                    $quantity !== '' ||
                    $unit !== ''
                ): ?>

                    <span class="shopping-quantity">
                        <?= e(
                            trim(
                                $quantity .
                                ' ' .
                                $unit
                            )
                        ) ?>
                    </span>

                <?php endif; ?>

            </li>

        <?php endforeach; ?>

        </ul>

    <?php else: ?>

        <div class="empty">
            Aucune liste de courses disponible.
        </div>

    <?php endif; ?>

</section>


<?php foreach ($menu as $item): ?>

<?php

$recipeId = (int)$item['recipe_id'];

$recipe = guestGetRecipe(
    $pdo,
    $recipeId
);

if (!$recipe) {
    continue;
}

$recipeServings = (int)(
    $recipe['servings'] ?? 0
);

if ($recipeServings <= 0) {
    $recipeServings = $people;
}

$multiplier =
    $people / $recipeServings;

?>

<section class="page">

    <div class="header">

        <div class="logo">
            🍳 Ma Cuisine
        </div>

        <div class="subtitle">
            <?= e($item['role_label']) ?>
            ·
            <?= e($dayLabel) ?>
            <?= e($dateLabel) ?>
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
                <?= e($item['role_label']) ?>
            </div>

            <?= e($recipe['title']) ?>

        </div>

        <div class="recipe-meta">

            <?= $people ?>
            personne<?= $people > 1 ? 's' : '' ?>

            <?php if (!empty($recipe['category'])): ?>

                ·
                <?= e(
                    (string)$recipe['category']
                ) ?>

            <?php endif; ?>

        </div>

        <div class="section-title">
            Ingrédients
        </div>

        <?php if (!empty($recipe['ingredients'])): ?>

            <ul class="ingredients">

            <?php foreach ($recipe['ingredients'] as $ingredient): ?>

                <?php

                $name = trim(
                    (string)(
                        $ingredient['ingredient_name']
                        ?? ''
                    )
                );

                if ($name === '') {
                    $name = trim(
                        (string)(
                            $ingredient['raw_text']
                            ?? ''
                        )
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

                $unit = trim(
                    (string)$ingredient['unit']
                );

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
                Aucun ingrédient disponible
                pour cette recette.
            </div>

        <?php endif; ?>

        <div class="section-title">
            Préparation
        </div>

        <?php if (
            trim(
                (string)$recipe['instructions']
            ) !== ''
        ): ?>

            <div class="instructions">

                <?= nl2br(
                    e(
                        (string)$recipe['instructions']
                    )
                ) ?>

            </div>

        <?php else: ?>

            <div class="empty">
                Aucune préparation disponible
                pour cette recette.
            </div>

        <?php endif; ?>

    </div>

</section>

<?php endforeach; ?>

</body>

</html>
