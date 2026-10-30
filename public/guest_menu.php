<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/guest_menu_lib.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pdo = db();

$people = isset($_GET['people'])
    ? (int)$_GET['people']
    : 6;

$people = max(2, min(20, $people));

$day = $_GET['day'] ?? 'saturday';

if (!in_array($day, ['saturday', 'sunday'], true)) {
    $day = 'saturday';
}

$weekStart = new DateTimeImmutable('monday this week');

$eventDate = $day === 'saturday'
    ? $weekStart->modify('+5 days')
    : $weekStart->modify('+6 days');

$dayLabel = $day === 'saturday'
    ? 'Samedi'
    : 'Dimanche';

/*
 * Rôles du menu invité.
 *
 * On utilise recipe_type quand il existe pour éviter
 * par exemple qu'une salade soit choisie comme plat.
 */
$roles = [
    'Apéritif' => [
        'terms' => [
            'apéritif',
            'aperitif',
            'amuse-bouche',
            'amuse bouche',
            'cocktail'
        ]
    ],

    'Entrée' => [
        'terms' => [
            'entrée',
            'entree',
            'salade',
            'velouté',
            'veloute',
            'terrine'
        ],
        'recipe_type' => 'starter'
    ],

    'Plat' => [
        'terms' => [
            'plat principal',
            'viande',
            'poisson',
            'volaille',
            'boeuf',
            'bœuf',
            'poulet'
        ],
        'recipe_type' => 'main'
    ],

    'Accompagnement' => [
        'terms' => [
            'accompagnement',
            'garniture',
            'pommes de terre',
            'riz',
            'purée',
            'puree',
            'gratin'
        ],
        'recipe_type' => 'side'
    ],

    'Fromage' => [
        'terms' => [
            'fromage',
            'camembert',
            'chèvre',
            'chevre',
            'comté',
            'comte'
        ]
    ],

    'Dessert' => [
        'terms' => [
            'dessert',
            'gâteau',
            'gateau',
            'tarte',
            'mousse',
            'crème',
            'creme'
        ],
        'recipe_type' => 'dessert'
    ],
];

/*
 * Sources disponibles dans Ma Cuisine.
 */
$sourceOptions = [
    'cuisineaz' => [
        'label' => 'CuisineAZ',
        'id' => 1
    ],

    'marmiton' => [
        'label' => 'Marmiton',
        'id' => 2
    ],

    'cookidoo' => [
        'label' => 'Cookidoo',
        'id' => 3
    ],
];

/*
 * Récupération des préférences existantes.
 */
$stmt = $pdo->prepare(
    'SELECT preferences_json
     FROM user_preferences
     WHERE user_id = 1
     LIMIT 1'
);

$stmt->execute();

$preferencesRow = $stmt->fetch(PDO::FETCH_ASSOC);

$preferencesJson = [];

if (
    $preferencesRow &&
    !empty($preferencesRow['preferences_json'])
) {
    $decoded = json_decode(
        (string)$preferencesRow['preferences_json'],
        true
    );

    if (is_array($decoded)) {
        $preferencesJson = $decoded;
    }
}

/*
 * Par défaut on reprend exactement les sources
 * de l'application principale.
 */
$defaultSources = [
    'cuisineaz' => true,
    'cookidoo' => true,
    'marmiton' => true
];

if (
    isset($preferencesJson['recipe_sources']) &&
    is_array($preferencesJson['recipe_sources'])
) {
    foreach ($defaultSources as $slug => $_value) {
        if (isset($preferencesJson['recipe_sources'][$slug])) {
            $defaultSources[$slug] =
                $preferencesJson['recipe_sources'][$slug] === true;
        }
    }
}

/*
 * Sources demandées dans le formulaire.
 */
if (isset($_GET['sources']) && is_array($_GET['sources'])) {

    $selectedSources = [];

    foreach ($_GET['sources'] as $slug) {

        $slug = (string)$slug;

        if (isset($sourceOptions[$slug])) {
            $selectedSources[] = $slug;
        }
    }

    $selectedSources =
        array_values(
            array_unique($selectedSources)
        );

} else {

    $selectedSources = [];

    foreach ($defaultSources as $slug => $enabled) {

        if ($enabled) {
            $selectedSources[] = $slug;
        }
    }
}

/*
 * Si aucune source n'est sélectionnée,
 * on n'autorise pas la génération.
 */
$sourceIds = [];

foreach ($selectedSources as $slug) {

    $sourceIds[] =
        (int)$sourceOptions[$slug]['id'];
}

$sourceIds =
    array_values(
        array_unique($sourceIds)
    );

/*
 * Clé de session.
 *
 * Les sources font partie de la clé :
 * changer les sources donne donc bien un nouveau menu.
 */
$sourceKey =
    implode(
        '-',
        $selectedSources
    );

$sessionKey =
    'guest_menu_' .
    $eventDate->format('Y-m-d') .
    '_' .
    $people .
    '_' .
    ($sourceKey ?: 'none');

$menu =
    $_SESSION[$sessionKey] ?? [];

$error = null;

/*
 * Génération.
 */
if (
    isset($_GET['generate']) &&
    $_GET['generate'] === '1'
) {

    if (!$sourceIds) {

        $error =
            'Sélectionne au moins une source de recettes.';

    } else {

        /*
         * Génération directement depuis la base.
         */
        $menu = [];

        $usedIds = [];

        foreach ($roles as $roleName => $role) {

            $conditions = [
                'r.is_demo = 0',
                'r.source_id IN (' .
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($sourceIds),
                            '?'
                        )
                    ) .
                ')'
            ];

            $params = $sourceIds;

            if ($usedIds) {

                $conditions[] =
                    'r.id NOT IN (' .
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($usedIds),
                            '?'
                        )
                    ) .
                    ')';

                $params =
                    array_merge(
                        $params,
                        $usedIds
                    );
            }

            /*
             * On construit les critères du rôle.
             */
            $roleParts = [];

            foreach (
                ($role['terms'] ?? [])
                as $term
            ) {

                $roleParts[] =
                    'LOWER(COALESCE(r.title, \'\')) LIKE ?';

                $roleParts[] =
                    'LOWER(COALESCE(r.category, \'\')) LIKE ?';

                $roleParts[] =
                    'LOWER(COALESCE(r.keywords, \'\')) LIKE ?';

                $like =
                    '%' .
                    mb_strtolower(
                        (string)$term,
                        'UTF-8'
                    ) .
                    '%';

                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            if ($roleParts) {

                $conditions[] =
                    '(' .
                    implode(
                        ' OR ',
                        $roleParts
                    ) .
                    ')';
            }

            /*
             * Le type correct passe en priorité.
             */
            $recipeType =
                $role['recipe_type'] ?? null;

            if ($recipeType !== null) {

                $sql =
                    'SELECT
                        r.id,
                        r.title,
                        r.description,
                        r.image_url,
                        r.category,
                        r.servings
                     FROM recipes r
                     WHERE ' .
                    implode(
                        ' AND ',
                        $conditions
                    ) .
                    '
                     ORDER BY
                        CASE
                            WHEN r.recipe_type = ?
                            THEN 0
                            ELSE 1
                        END,
                        RAND()
                     LIMIT 1';

                $params[] = $recipeType;

            } else {

                $sql =
                    'SELECT
                        r.id,
                        r.title,
                        r.description,
                        r.image_url,
                        r.category,
                        r.servings
                     FROM recipes r
                     WHERE ' .
                    implode(
                        ' AND ',
                        $conditions
                    ) .
                    '
                     ORDER BY RAND()
                     LIMIT 1';
            }

            $stmt =
                $pdo->prepare($sql);

            $stmt->execute($params);

            $recipe =
                $stmt->fetch(PDO::FETCH_ASSOC);

            /*
             * Si aucune recette correspondant
             * au rôle n'existe, on prend une recette
             * du bon type parmi les sources sélectionnées.
             */
            if (!$recipe && $recipeType !== null) {

                $fallbackConditions = [
                    'r.is_demo = 0',
                    'r.recipe_type = ?',
                    'r.source_id IN (' .
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($sourceIds),
                                '?'
                            )
                        ) .
                    ')'
                ];

                $fallbackParams = [
                    $recipeType,
                    ...$sourceIds
                ];

                if ($usedIds) {

                    $fallbackConditions[] =
                        'r.id NOT IN (' .
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($usedIds),
                                '?'
                            )
                        ) .
                        ')';

                    $fallbackParams =
                        array_merge(
                            $fallbackParams,
                            $usedIds
                        );
                }

                $sql =
                    'SELECT
                        r.id,
                        r.title,
                        r.description,
                        r.image_url,
                        r.category,
                        r.servings
                     FROM recipes r
                     WHERE ' .
                    implode(
                        ' AND ',
                        $fallbackConditions
                    ) .
                    '
                     ORDER BY RAND()
                     LIMIT 1';

                $stmt =
                    $pdo->prepare($sql);

                $stmt->execute(
                    $fallbackParams
                );

                $recipe =
                    $stmt->fetch(
                        PDO::FETCH_ASSOC
                    );
            }

            /*
             * Dernier fallback : n'importe quelle recette
             * des sources sélectionnées.
             */
            if (!$recipe) {

                $fallbackConditions = [
                    'r.is_demo = 0',
                    'r.source_id IN (' .
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($sourceIds),
                                '?'
                            )
                        ) .
                    ')'
                ];

                $fallbackParams =
                    $sourceIds;

                if ($usedIds) {

                    $fallbackConditions[] =
                        'r.id NOT IN (' .
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($usedIds),
                                '?'
                            )
                        ) .
                        ')';

                    $fallbackParams =
                        array_merge(
                            $fallbackParams,
                            $usedIds
                        );
                }

                $sql =
                    'SELECT
                        r.id,
                        r.title,
                        r.description,
                        r.image_url,
                        r.category,
                        r.servings
                     FROM recipes r
                     WHERE ' .
                    implode(
                        ' AND ',
                        $fallbackConditions
                    ) .
                    '
                     ORDER BY RAND()
                     LIMIT 1';

                $stmt =
                    $pdo->prepare($sql);

                $stmt->execute(
                    $fallbackParams
                );

                $recipe =
                    $stmt->fetch(
                        PDO::FETCH_ASSOC
                    );
            }

            if ($recipe) {

                $menu[$roleName] =
                    $recipe;

                $usedIds[] =
                    (int)$recipe['id'];
            }
        }

        if (count($menu) === count($roles)) {

            $_SESSION[$sessionKey] =
                $menu;

        } else {

            $error =
                'Impossible de générer les 6 éléments du menu avec les sources sélectionnées.';
        }
    }
}

/*
 * Remplacement d'une recette.
 */
if (
    isset($_GET['replace']) &&
    is_string($_GET['replace'])
) {

    $roleName =
        $_GET['replace'];

    if (
        isset(
            $roles[$roleName],
            $_SESSION[$sessionKey]
        ) &&
        $sourceIds
    ) {

        $menu =
            $_SESSION[$sessionKey];

        $usedIds = [];

        foreach ($menu as $name => $recipe) {

            if ($name !== $roleName) {

                $usedIds[] =
                    (int)$recipe['id'];
            }
        }

        $recipeType =
            $roles[$roleName]['recipe_type']
            ?? null;

        $conditions = [
            'r.is_demo = 0',
            'r.source_id IN (' .
                implode(
                    ',',
                    array_fill(
                        0,
                        count($sourceIds),
                        '?'
                    )
                ) .
            ')'
        ];

        $params = $sourceIds;

        if ($usedIds) {

            $conditions[] =
                'r.id NOT IN (' .
                implode(
                    ',',
                    array_fill(
                        0,
                        count($usedIds),
                        '?'
                    )
                ) .
                ')';

            $params =
                array_merge(
                    $params,
                    $usedIds
                );
        }

        if ($recipeType) {

            $conditions[] =
                'r.recipe_type = ?';

            $params[] =
                $recipeType;
        }

        $sql =
            'SELECT
                r.id,
                r.title,
                r.description,
                r.image_url,
                r.category,
                r.servings
             FROM recipes r
             WHERE ' .
            implode(
                ' AND ',
                $conditions
            ) .
            '
             ORDER BY RAND()
             LIMIT 1';

        $stmt =
            $pdo->prepare($sql);

        $stmt->execute($params);

        $replacement =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if ($replacement) {

            $menu[$roleName] =
                $replacement;

            $_SESSION[$sessionKey] =
                $menu;

        } else {

            $error =
                'Aucune autre recette disponible pour ce rôle avec les sources sélectionnées.';
        }
    }
}

$menu =
    $_SESSION[$sessionKey] ?? [];

$hasMenu =
    count($menu) === count($roles);

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
    12 => 'décembre'
];

$dateLabel =
    $eventDate->format('j') .
    ' ' .
    $monthNames[
        (int)$eventDate->format('n')
    ] .
    ' ' .
    $eventDate->format('Y');

?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Ma Cuisine — Menu invités</title>

<style>

:root {
    --cream: #fbf7f0;
    --paper: #fffdf9;
    --green: #5f7563;
    --green-dark: #4e6252;
    --green-soft: #edf3ee;
    --terracotta: #c97858;
    --text: #354238;
    --muted: #7b817b;
    --border: #e7ded2;
    --shadow: 0 7px 20px rgba(78, 98, 82, .08);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--cream);
    color: var(--text);
    font-family: Arial, Helvetica, sans-serif;
}

.site-header {
    height: 72px;
    background: rgba(255, 253, 249, .96);
    border-bottom: 1px solid var(--border);
}

.site-header-inner {
    max-width: 1360px;
    height: 100%;
    margin: 0 auto;
    padding: 0 26px;

    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brand {
    font-size: 22px;
    font-weight: 800;
    text-decoration: none;
}

.brand span {
    color: var(--terracotta);
}

.nav {
    display: flex;
    align-items: center;
    gap: 32px;

    font-size: 14px;
    font-weight: 700;

    color: var(--green-dark);
}

.nav a {
    text-decoration: none;
}

.settings {
    font-size: 22px;
    opacity: .7;
}

main {
    max-width: 1360px;
    margin: 0 auto;
    padding: 40px 26px 70px;
}

.topline {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;

    gap: 30px;
    margin-bottom: 24px;
}

h1 {
    margin: 0 0 7px;

    font-size: 30px;
    letter-spacing: -.4px;
}

.subtitle {
    color: var(--muted);
    font-size: 15px;
}

.people-badge {
    background: var(--paper);

    border: 1px solid var(--border);
    border-radius: 14px;

    padding: 10px 14px;

    font-weight: 800;
    white-space: nowrap;

    box-shadow: 0 3px 10px rgba(0,0,0,.03);
}

.controls {
    background: var(--paper);

    border: 1px solid var(--border);
    border-radius: 16px;

    padding: 20px;

    margin-bottom: 30px;

    box-shadow: var(--shadow);
}

.control-row {
    display: flex;
    align-items: flex-end;
    gap: 14px;
    flex-wrap: wrap;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.field label,
.sources-title {
    font-size: 12px;
    font-weight: 800;
    color: var(--green-dark);
}

select,
input[type="number"] {
    height: 42px;

    min-width: 150px;

    padding: 0 12px;

    border: 1px solid var(--border);
    border-radius: 10px;

    background: #fff;

    color: var(--text);

    font-size: 14px;
    font-weight: 700;
}

.sources-block {
    display: flex;
    flex-direction: column;
    gap: 8px;

    margin-left: 4px;
}

.sources {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.source-option {
    position: relative;
}

.source-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.source-option label {
    display: inline-flex;
    align-items: center;

    height: 42px;

    padding: 0 14px;

    border: 1px solid var(--border);
    border-radius: 10px;

    background: #fff;

    color: var(--green-dark);

    font-size: 14px;
    font-weight: 700;

    cursor: pointer;
}

.source-option input:checked + label {
    background: var(--green-soft);
    border-color: var(--green);
}

.source-option input:checked + label::before {
    content: '✓';
    margin-right: 6px;
    font-weight: 900;
}

.generate {
    height: 42px;

    border: 0;
    border-radius: 10px;

    padding: 0 18px;

    background: var(--green);

    color: #fff;

    font-size: 14px;
    font-weight: 800;

    cursor: pointer;
}

.error {
    margin-top: 14px;

    padding: 12px 14px;

    border-radius: 10px;

    background: #fff1ec;

    color: #a34f35;

    font-size: 14px;
    font-weight: 700;
}

.section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;

    margin-bottom: 14px;
}

.section-head h2 {
    margin: 0;
    font-size: 17px;
}

.section-head span {
    color: var(--muted);
    font-size: 13px;
}

.empty {
    background: var(--paper);

    border: 1px solid var(--border);
    border-radius: 16px;

    padding: 42px 20px;

    text-align: center;

    color: var(--muted);

    box-shadow: var(--shadow);
}

.menu-grid {
    display: grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 18px;

    align-items: stretch;
}

.recipe-card {
    min-width: 0;

    height: 410px;

    background: var(--paper);

    border: 1px solid var(--border);
    border-radius: 16px;

    overflow: hidden;

    box-shadow: var(--shadow);

    display: flex;
    flex-direction: column;
}

.recipe-image {
    width: 100%;
    height: 190px;
    flex: 0 0 190px;

    background: #eee7de;
    overflow: hidden;
}

.recipe-image img {
    width: 100%;
    height: 100%;

    display: block;

    object-fit: cover;
}

.recipe-placeholder {
    width: 100%;
    height: 100%;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 42px;
}

.recipe-content {
    padding: 17px 18px 15px;

    display: flex;
    flex-direction: column;

    flex: 1;
}

.role {
    margin-bottom: 7px;

    color: var(--terracotta);

    font-size: 11px;
    font-weight: 900;

    letter-spacing: .07em;
    text-transform: uppercase;
}

.recipe-title {
    font-size: 19px;
    line-height: 1.24;
    font-weight: 800;
}

.recipe-category {
    margin-top: 7px;

    color: var(--muted);

    font-size: 12px;
}

.card-actions {
    margin-top: auto;
    padding-top: 16px;

    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: 8px;
}

.card-btn {
    height: 38px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    border-radius: 9px;

    text-decoration: none;

    font-size: 13px;
    font-weight: 800;
}

.view-btn {
    border: 1px solid var(--border);
    background: #fff;

    color: var(--green-dark);
}

.change-btn {
    border: 1px solid var(--green);
    background: var(--green);

    color: #fff;
}

.bottom-actions {
    display: flex;
    justify-content: center;

    gap: 10px;

    margin-top: 28px;
}

.bottom-btn {
    height: 42px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    padding: 0 18px;

    border-radius: 10px;

    text-decoration: none;

    font-size: 14px;
    font-weight: 800;
}

.print-btn {
    background: var(--terracotta);
    color: #fff;
}

.back-btn {
    background: var(--paper);
    color: var(--green-dark);

    border: 1px solid var(--border);
}

@media (max-width: 1000px) {

    .menu-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 680px) {

    main {
        padding: 28px 16px 50px;
    }

    .topline {
        flex-direction: column;
        align-items: flex-start;
    }

    .menu-grid {
        grid-template-columns: 1fr;
    }

    .recipe-card {
        height: auto;
        min-height: 410px;
    }

    .control-row {
        flex-direction: column;
        align-items: stretch;
    }

    select,
    input[type="number"],
    .generate {
        width: 100%;
    }

    .sources-block {
        margin-left: 0;
    }
}

</style>

</head>

<body>

<header class="site-header">

    <div class="site-header-inner">

        <a
            class="brand"
            href="/"
        >
            🍳 Ma <span>Cuisine</span>
        </a>

        <nav class="nav">

            <a href="/">
                Accueil
            </a>

            <a href="/recipes.php">
                Recettes
            </a>

            <a href="/courses.php">
                Courses
            </a>

            <span class="settings">
                ⚙️
            </span>

        </nav>

    </div>

</header>

<main>

    <div class="topline">

        <div>

            <h1>
                🎉 Menu invités
            </h1>

            <div class="subtitle">
                <?= e($dayLabel . ' ' . $dateLabel) ?>
                · un repas complet pour
                <?= $people ?>
                personnes
            </div>

        </div>

        <div class="people-badge">
            👥 <?= $people ?>
            personne<?= $people > 1 ? 's' : '' ?>
        </div>

    </div>

    <form
        class="controls"
        method="get"
    >

        <div class="control-row">

            <div class="field">

                <label for="day">
                    Jour
                </label>

                <select
                    id="day"
                    name="day"
                >

                    <option
                        value="saturday"
                        <?= $day === 'saturday'
                            ? 'selected'
                            : '' ?>
                    >
                        Samedi
                    </option>

                    <option
                        value="sunday"
                        <?= $day === 'sunday'
                            ? 'selected'
                            : '' ?>
                    >
                        Dimanche
                    </option>

                </select>

            </div>

            <div class="field">

                <label for="people">
                    Personnes
                </label>

                <input
                    id="people"
                    name="people"
                    type="number"
                    min="2"
                    max="20"
                    value="<?= $people ?>"
                >

            </div>

            <div class="sources-block">

                <div class="sources-title">
                    Sources des recettes
                </div>

                <div class="sources">

                    <?php foreach (
                        $sourceOptions
                        as $slug => $source
                    ): ?>

                        <div class="source-option">

                            <input
                                type="checkbox"
                                id="source_<?= e($slug) ?>"
                                name="sources[]"
                                value="<?= e($slug) ?>"
                                <?= in_array(
                                    $slug,
                                    $selectedSources,
                                    true
                                )
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                for="source_<?= e($slug) ?>"
                            >
                                <?= e($source['label']) ?>
                            </label>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

            <button
                class="generate"
                type="submit"
                name="generate"
                value="1"
            >
                <?= $hasMenu
                    ? '🔄 Générer un autre menu'
                    : '✨ Générer le menu' ?>
            </button>

        </div>

        <?php if ($error): ?>

            <div class="error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

    </form>

    <div class="section-head">

        <h2>
            Votre menu invité
        </h2>

        <span>
            <?php if ($hasMenu): ?>
                6 propositions pour votre repas
            <?php else: ?>
                Choisissez vos sources puis générez le menu
            <?php endif; ?>
        </span>

    </div>

    <?php if (!$hasMenu): ?>

        <div class="empty">

            <strong>
                Aucun menu généré pour le moment.
            </strong>

            <br><br>

            Sélectionnez les sources qui vous intéressent
            et cliquez sur « Générer le menu ».

        </div>

    <?php else: ?>

        <section class="menu-grid">

            <?php foreach (
                $roles
                as $roleName => $role
            ): ?>

                <?php if (!isset($menu[$roleName])): ?>
                    <?php continue; ?>
                <?php endif; ?>

                <?php
                $recipe =
                    $menu[$roleName];
                ?>

                <article class="recipe-card">

                    <div class="recipe-image">

                        <?php if (
                            !empty(
                                $recipe['image_url']
                            )
                        ): ?>

                            <img
                                src="<?= e(
                                    (string)$recipe['image_url']
                                ) ?>"
                                alt="<?= e(
                                    (string)$recipe['title']
                                ) ?>"
                                loading="lazy"
                            >

                        <?php else: ?>

                            <div class="recipe-placeholder">
                                🍽️
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="recipe-content">

                        <div class="role">
                            <?= e($roleName) ?>
                        </div>

                        <div class="recipe-title">
                            <?= e(
                                (string)$recipe['title']
                            ) ?>
                        </div>

                        <?php if (
                            !empty(
                                $recipe['category']
                            )
                        ): ?>

                            <div class="recipe-category">
                                <?= e(
                                    (string)$recipe['category']
                                ) ?>
                            </div>

                        <?php endif; ?>

                        <div class="card-actions">

                            <a
                                class="card-btn view-btn"
                                href="/recipe.php?id=<?= (int)$recipe['id'] ?>&people=<?= $people ?>&from=guest"
                            >
                                📖 Voir
                            </a>

                            <a
                                class="card-btn change-btn"
                                href="?day=<?= e($day) ?>&people=<?= $people ?>&replace=<?= rawurlencode($roleName) ?>&<?= http_build_query(
                                    ['sources' => $selectedSources]
                                ) ?>"
                            >
                                🔄 Changer
                            </a>

                        </div>

                    </div>

                </article>

            <?php endforeach; ?>

        </section>

        <div class="bottom-actions">

            <a
                class="bottom-btn print-btn"
                href="/print_guest_menu.php?day=<?= e($day) ?>&people=<?= $people ?>"
            >
                🖨️ Menu + courses
            </a>

            <a
                class="bottom-btn back-btn"
                href="/"
            >
                ← Retour à ma semaine
            </a>

        </div>

    <?php endif; ?>

</main>

</body>
</html>
