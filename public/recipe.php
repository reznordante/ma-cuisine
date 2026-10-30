<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$pdo = db();

$userId = requireAuth();
$recipeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$recipeId) {
    http_response_code(400);
    exit('Recette invalide.');
}

/*
 * Nombre de personnes sélectionné.
 */
$stmt = $pdo->prepare(
    'SELECT people_count
     FROM user_preferences
     WHERE user_id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);

$peopleCount = (int)($stmt->fetchColumn() ?: 3);
$peopleCount = max(1, min(12, $peopleCount));

/*
 * Nombre de personnes demandé uniquement pour cette fiche.
 * Il ne modifie pas le réglage global.
 */
$displayPeopleCount = filter_input(
    INPUT_GET,
    'people',
    FILTER_VALIDATE_INT
);

if ($displayPeopleCount === false || $displayPeopleCount === null) {
    $displayPeopleCount = $peopleCount;
}

$displayPeopleCount = max(1, min(12, (int)$displayPeopleCount));

/*
 * Recette.
 */
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
        instructions,
        nutrition_json,
        is_thermomix
     FROM recipes
     WHERE id = ?
     LIMIT 1'
);

$stmt->execute([$recipeId]);

$recipe = $stmt->fetch();

if (!$recipe) {
    http_response_code(404);
    exit('Recette introuvable.');
}

/*
 * Ingrédients.
 */
$stmt = $pdo->prepare(
    'SELECT
        raw_text,
        quantity,
        unit,
        ingredient_name,
        position
     FROM recipe_ingredients
     WHERE recipe_id = ?
     ORDER BY position, id'
);

$stmt->execute([$recipeId]);

$ingredients = $stmt->fetchAll();

/*
 * Étapes.
 */
$stmt = $pdo->prepare(
    'SELECT
        step_number,
        instruction,
        thermomix_time_seconds,
        thermomix_temperature,
        thermomix_speed,
        thermomix_mode
     FROM recipe_steps
     WHERE recipe_id = ?
     ORDER BY step_number'
);

$stmt->execute([$recipeId]);

$steps = $stmt->fetchAll();

/*
 * Calcul du facteur de proportion.
 */
$recipeServings = (int)($recipe['servings'] ?? 0);

if ($recipeServings > 0) {
    $scaleFactor = $displayPeopleCount / $recipeServings;
} else {
    $scaleFactor = 1;
}

/*
 * Formatage d'une quantité.
 */
function formatQuantity(?float $quantity): string
{
    if ($quantity === null) {
        return '';
    }

    if (abs($quantity - round($quantity)) < 0.001) {
        return (string)(int)round($quantity);
    }

    $value = round($quantity, 2);

    return rtrim(
        rtrim(number_format($value, 2, '.', ''), '0'),
        '.'
    );
}

/*
 * Durée.
 */
function formatDuration(?int $minutes): string
{
    if (!$minutes) {
        return '';
    }

    if ($minutes < 60) {
        return $minutes . ' min';
    }

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    if ($mins === 0) {
        return $hours . ' h';
    }

    return $hours . ' h ' . $mins . ' min';
}

$nutrition = null;

if (!empty($recipe['nutrition_json'])) {
    $decoded = json_decode($recipe['nutrition_json'], true);

    if (is_array($decoded)) {
        $nutrition = $decoded;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
    <?= htmlspecialchars($recipe['title'], ENT_QUOTES, 'UTF-8') ?>
    — Ma Cuisine
</title>

<style>
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
    background: #f7f8fa;
    color: #1f2937;
}

header {
    height: 76px;
    background: white;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 38px;
}

.logo {
    font-size: 23px;
    font-weight: 800;
    color: #222;
    text-decoration: none;
}

nav {
    display: flex;
    gap: 28px;
    align-items: center;
}

nav a {
    text-decoration: none;
    color: #6b7280;
    font-weight: 600;
}

nav a:hover {
    color: #111827;
}

.settings {
    font-size: 22px;
    color: #6b7280;
}

main {
    max-width: 1050px;
    margin: 0 auto;
    padding: 38px 28px 60px;
}

.back {
    display: inline-block;
    margin-bottom: 24px;
    color: #6b7280;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
}

.back:hover {
    color: #111827;
}

.recipe-header {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 3px 12px rgba(0,0,0,.035);
}

.recipe-image {
    width: 100%;
    height: 330px;
    object-fit: cover;
    display: block;
    background: #eef0f2;
}

.recipe-image-placeholder {
    height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 70px;
    background: #f1f3f5;
}

.recipe-head-content {
    padding: 28px;
}

.recipe-category {
    color: #6b7280;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 8px;
}

h1 {
    margin: 0 0 10px;
    font-size: 32px;
    letter-spacing: -0.7px;
}

.description {
    color: #6b7280;
    line-height: 1.6;
    margin: 0;
}

.meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 22px;
}

.meta-item {
    background: #f4f5f6;
    border-radius: 10px;
    padding: 9px 12px;
    font-size: 13px;
    font-weight: 700;
}

.content {
    display: grid;
    grid-template-columns: 330px 1fr;
    gap: 20px;
    margin-top: 20px;
}

.panel {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 3px 12px rgba(0,0,0,.025);
}

.panel h2 {
    margin: 0 0 18px;
    font-size: 19px;
}

.people {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}

.people strong {
    font-size: 14px;
}

.people button {
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 8px;
    background: #f1f2f3;
    font-size: 19px;
    cursor: pointer;
}

.ingredients {
    list-style: none;
    margin: 0;
    padding: 0;
}

.ingredients li {
    padding: 10px 0;
    border-bottom: 1px solid #f0f1f2;
    font-size: 14px;
    line-height: 1.45;
}

.ingredients li:last-child {
    border-bottom: 0;
}

.ingredient-quantity {
    font-weight: 800;
}

.steps {
    margin: 0;
    padding: 0;
    list-style: none;
    counter-reset: steps;
}

.steps li {
    position: relative;
    padding: 0 0 22px 48px;
    line-height: 1.65;
    font-size: 15px;
}

.steps li::before {
    counter-increment: steps;
    content: counter(steps);
    position: absolute;
    left: 0;
    top: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #f1f2f3;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.empty {
    color: #9ca3af;
    font-size: 14px;
}

.thermomix {
    margin-top: 9px;
    padding: 9px 11px;
    border-radius: 9px;
    background: #f6f7f8;
    font-size: 12px;
    color: #59616b;
}

@media (max-width: 800px) {
    header {
        padding: 0 18px;
    }

    nav {
        display: none;
    }

    main {
        padding: 28px 16px 45px;
    }

    .content {
        grid-template-columns: 1fr;
    }

    .recipe-image {
        height: 240px;
    }
}
</style>

</head>

<body>

<header>

    <a href="/" class="logo">🍳 Ma Cuisine</a>

    <nav>
        <a href="/">Accueil</a>
        <a href="#">Recettes</a>
        <a href="#">Courses</a>
    </nav>

    <div class="settings">⚙️</div>

</header>

<main>

    <a href="<?= isset($_GET['from']) && $_GET['from'] === 'guest' ? '/guest_menu.php' : '/' ?>" class="back">
    ← Retour au menu
</a>

    <section class="recipe-header">

        <?php if (!empty($recipe['image_url'])): ?>

            <img
                class="recipe-image"
                src="<?= htmlspecialchars($recipe['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                alt="<?= htmlspecialchars($recipe['title'], ENT_QUOTES, 'UTF-8') ?>"
            >

        <?php else: ?>

            <div class="recipe-image-placeholder">
                🍳
            </div>

        <?php endif; ?>

        <div class="recipe-head-content">

            <?php if (!empty($recipe['category'])): ?>
                <div class="recipe-category">
                    <?= htmlspecialchars($recipe['category'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <h1>
                <?= htmlspecialchars($recipe['title'], ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <?php if (!empty($recipe['description'])): ?>
                <p class="description">
                    <?= htmlspecialchars($recipe['description'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>

            <div class="meta">

                <?php if ($recipe['prep_time_minutes']): ?>
                    <div class="meta-item">
                        🔪 Préparation
                        <?= formatDuration((int)$recipe['prep_time_minutes']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($recipe['cook_time_minutes']): ?>
                    <div class="meta-item">
                        🔥 Cuisson
                        <?= formatDuration((int)$recipe['cook_time_minutes']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($recipe['total_time_minutes']): ?>
                    <div class="meta-item">
                        ⏱️ Total
                        <?= formatDuration((int)$recipe['total_time_minutes']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($recipe['is_thermomix']): ?>
                    <div class="meta-item">
                        🤖 Thermomix
                    </div>
                <?php endif; ?>

            </div>

        </div>

    </section>

    <div class="content">

        <section class="panel">

            <h2>🥕 Ingrédients</h2>

            <div class="people">

                <button
                    type="button"
                    onclick="changePeople(-1)"
                >−</button>

                <strong id="peopleLabel">
                    <?= $displayPeopleCount ?> personne<?= $displayPeopleCount > 1 ? 's' : '' ?>
                </strong>

                <button
                    type="button"
                    onclick="changePeople(1)"
                >+</button>

            </div>

            <?php if ($ingredients): ?>

                <ul class="ingredients">

                    <?php foreach ($ingredients as $ingredient): ?>

                        <?php
                        $quantity = null;

                        if ($ingredient['quantity'] !== null) {
                            $quantity =
                                (float)$ingredient['quantity'] * $scaleFactor;
                        }

                        $name = $ingredient['ingredient_name']
                            ?: $ingredient['raw_text'];
                        ?>

                        <li>

                            <?php if ($quantity !== null): ?>

                                <span class="ingredient-quantity">
                                    <?= htmlspecialchars(
                                        formatQuantity($quantity),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                    <?php if (!empty($ingredient['unit'])): ?>
                                        <?= htmlspecialchars(
                                            $ingredient['unit'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    <?php endif; ?>
                                </span>

                                <?= htmlspecialchars(
                                    $name,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            <?php else: ?>

                                <?= htmlspecialchars(
                                    $ingredient['raw_text'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            <?php endif; ?>

                        </li>

                    <?php endforeach; ?>

                </ul>

            <?php else: ?>

                <div class="empty">
                    Les ingrédients seront disponibles lors de l'import
                    des recettes.
                </div>

            <?php endif; ?>

        </section>

        <section class="panel">

            <h2>👨‍🍳 Préparation</h2>

            <?php if ($steps): ?>

                <ol class="steps">

                    <?php foreach ($steps as $step): ?>

                        <li>

                            <?= nl2br(
                                htmlspecialchars(
                                    $step['instruction'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                )
                            ) ?>

                            <?php
                            $tmx = [];

                            if ($step['thermomix_time_seconds']) {
                                $seconds = (int)$step['thermomix_time_seconds'];
                                $minutes = intdiv($seconds, 60);
                                $remaining = $seconds % 60;

                                $tmx[] =
                                    '⏱️ ' .
                                    $minutes .
                                    ' min' .
                                    ($remaining ? ' ' . $remaining . ' s' : '');
                            }

                            if (!empty($step['thermomix_temperature'])) {
                                $tmx[] =
                                    '🌡️ ' .
                                    $step['thermomix_temperature'];
                            }

                            if (!empty($step['thermomix_speed'])) {
                                $tmx[] =
                                    '⚙️ ' .
                                    $step['thermomix_speed'];
                            }

                            if (!empty($step['thermomix_mode'])) {
                                $tmx[] =
                                    '🔧 ' .
                                    $step['thermomix_mode'];
                            }
                            ?>

                            <?php if ($tmx): ?>

                                <div class="thermomix">
                                    <?= htmlspecialchars(
                                        implode(' · ', $tmx),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </div>

                            <?php endif; ?>

                        </li>

                    <?php endforeach; ?>

                </ol>

            <?php elseif (!empty($recipe['instructions'])): ?>

                <div style="line-height:1.7; font-size:15px;">
                    <?= nl2br(
                        htmlspecialchars(
                            $recipe['instructions'],
                            ENT_QUOTES,
                            'UTF-8'
                        )
                    ) ?>
                </div>

            <?php else: ?>

                <div class="empty">
                    Les étapes de préparation seront disponibles lors de
                    l'import des recettes.
                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

<script>

let currentPeople = <?= $displayPeopleCount ?>;

function changePeople(delta) {

    const next = Math.max(
        1,
        Math.min(12, currentPeople + delta)
    );

    if (next === currentPeople) {
        return;
    }

    const url = new URL(window.location.href);

    url.searchParams.set('people', next);

    window.location.href = url.toString();
}

</script>

</body>
</html>
