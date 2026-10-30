<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$pdo = db();
$userId = requireAuth();

/*
 * Mise à jour du nombre de personnes
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['source_update'])) {
    header('Content-Type: application/json; charset=utf-8');

    $source = $_POST['source'] ?? '';
    $enabled = ($_POST['enabled'] ?? '') === '1';

    $allowedSources = [
        'cuisineaz',
        'cookidoo',
        'marmiton'
    ];

    if (!in_array($source, $allowedSources, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Source invalide.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT preferences_json
         FROM user_preferences
         WHERE user_id = ?
         LIMIT 1'
    );

    $stmt->execute([$userId]);

    $preferencesJson = $stmt->fetchColumn();

    $preferences = [];

    if (is_string($preferencesJson) && trim($preferencesJson) !== '') {
        $decoded = json_decode($preferencesJson, true);

        if (is_array($decoded)) {
            $preferences = $decoded;
        }
    }

    if (!isset($preferences['recipe_sources']) || !is_array($preferences['recipe_sources'])) {
        $preferences['recipe_sources'] = [];
    }

    $preferences['recipe_sources'][$source] = $enabled;

    $stmt = $pdo->prepare(
        'UPDATE user_preferences
         SET preferences_json = ?
         WHERE user_id = ?'
    );

    $stmt->execute([
        json_encode($preferences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $userId
    ]);

    echo json_encode([
        'success' => true,
        'source' => $source,
        'enabled' => $enabled
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['people_count'])) {
    $peopleCount = max(1, min(12, (int)$_POST['people_count']));

    $stmt = $pdo->prepare(
        'UPDATE user_preferences
         SET people_count = ?
         WHERE user_id = ?'
    );

    $stmt->execute([$peopleCount, $userId]);

    header('Location: /');
    exit;
}

/*
 * Nombre de personnes
 */
$stmt = $pdo->prepare(
    'SELECT people_count
     FROM user_preferences
     WHERE user_id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);

$peopleCount = (int)($stmt->fetchColumn() ?: 3);

/*
 * Sources de recettes sélectionnées pour les menus.
 * Les trois sources sont activées par défaut.
 */
$stmt = $pdo->prepare(
    'SELECT preferences_json
     FROM user_preferences
     WHERE user_id = ?
     LIMIT 1'
);

$stmt->execute([$userId]);

$preferencesJson = $stmt->fetchColumn();

$preferences = [];

if (is_string($preferencesJson) && trim($preferencesJson) !== '') {
    $decoded = json_decode($preferencesJson, true);

    if (is_array($decoded)) {
        $preferences = $decoded;
    }
}

$recipeSources = [
    'cuisineaz' => true,
    'cookidoo' => true,
    'marmiton' => true
];

if (
    isset($preferences['recipe_sources']) &&
    is_array($preferences['recipe_sources'])
) {
    foreach ($recipeSources as $source => $default) {
        if (array_key_exists($source, $preferences['recipe_sources'])) {
            $recipeSources[$source] = (bool)$preferences['recipe_sources'][$source];
        }
    }
}

/*
 * Semaine courante
 */
$today = new DateTimeImmutable('today');
$weekStart = $today->modify('monday this week');
$weekEnd = $weekStart->modify('+4 days');

$weekStartSql = $weekStart->format('Y-m-d');

/*
 * Récupération du menu réellement enregistré
 */
$stmt = $pdo->prepare(
    'SELECT
        mi.menu_date,
        mi.servings,
        r.id AS recipe_id,
        r.title,
        r.description,
        r.image_url,
        r.category,
        rr.rating
     FROM weekly_menus wm
     INNER JOIN menu_items mi
        ON mi.weekly_menu_id = wm.id
     INNER JOIN recipes r
        ON r.id = mi.recipe_id
     LEFT JOIN recipe_ratings rr
        ON rr.recipe_id = r.id
       AND rr.user_id = ?
     WHERE wm.user_id = ?
       AND wm.week_start = ?
     ORDER BY mi.menu_date'
);

$stmt->execute([
    $userId,
    $userId,
    $weekStartSql
]);

$menuRows = $stmt->fetchAll();

/*
 * Indexation par date
 */
$menuByDate = [];

foreach ($menuRows as $row) {
    $menuByDate[$row['menu_date']] = $row;
}

$days = [
    [
        'name' => 'Lundi',
        'date' => $weekStart,
        'icon' => '🍗'
    ],
    [
        'name' => 'Mardi',
        'date' => $weekStart->modify('+1 day'),
        'icon' => '🥔'
    ],
    [
        'name' => 'Mercredi',
        'date' => $weekStart->modify('+2 days'),
        'icon' => '🐟'
    ],
    [
        'name' => 'Jeudi',
        'date' => $weekStart->modify('+3 days'),
        'icon' => '🍝'
    ],
    [
        'name' => 'Vendredi',
        'date' => $weekStart->modify('+4 days'),
        'icon' => '🌮'
    ],
];

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
    'Semaine du ' .
    $weekStart->format('j') .
    ' au ' .
    $weekEnd->format('j') .
    ' ' .
    $monthNames[(int)$weekEnd->format('n')] .
    ' ' .
    $weekEnd->format('Y');

$hasMenu = count($menuRows) > 0;

/*
 * Résumé de la liste de courses
 */
$stmt = $pdo->prepare(
    'SELECT
        COUNT(si.id) AS total,
        COALESCE(SUM(CASE WHEN si.checked = 1 THEN 1 ELSE 0 END), 0) AS checked
     FROM shopping_lists sl
     LEFT JOIN shopping_items si
        ON si.shopping_list_id = sl.id
     WHERE sl.user_id = ?
       AND sl.weekly_menu_id = (
           SELECT id
           FROM weekly_menus
           WHERE user_id = ?
             AND week_start = ?
           LIMIT 1
       )'
);

$stmt->execute([
    $userId,
    $userId,
    $weekStartSql
]);

$shoppingSummary = $stmt->fetch();

$shoppingTotal = (int)($shoppingSummary['total'] ?? 0);
$shoppingChecked = (int)($shoppingSummary['checked'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Ma Cuisine</title>

<style>
* {
    box-sizing: border-box;
}

:root {
    --cream: #fbf7f0;
    --cream-dark: #f4eee4;
    --white: #ffffff;
    --ink: #2c2925;
    --muted: #7c746b;
    --border: #e9e0d4;
    --green: #5f7563;
    --green-dark: #4e6252;
    --terracotta: #c97858;
    --terracotta-dark: #b76648;
    --shadow: 0 8px 24px rgba(74, 58, 42, .07);
}

body {
    margin: 0;
    font-family:
        Inter,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    background: var(--cream);
    color: var(--ink);
}

button,
a {
    -webkit-tap-highlight-color: transparent;
}

header {
    height: 76px;
    background: var(--cream);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 38px;
    position: relative;
    z-index: 2;
}

.logo {
    font-size: 23px;
    font-weight: 800;
    color: var(--ink);
    letter-spacing: -.4px;
}

.logo span {
    color: var(--terracotta);
}

nav {
    display: flex;
    gap: 30px;
    align-items: center;
}

nav a {
    text-decoration: none;
    color: var(--muted);
    font-weight: 650;
    font-size: 14px;
    transition: color .15s ease;
}

nav a.active,
nav a:hover {
    color: var(--green-dark);
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.settings,
.logout {
    font-size: 21px;
    color: var(--muted);
    text-decoration: none;
}

.settings:hover,
.logout:hover {
    color: var(--green-dark);
}

main {
    max-width: 1280px;
    margin: 0 auto;
    padding: 42px 28px 64px;
}

.top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 22px;
}

h1 {
    margin: 0 0 7px;
    font-size: 32px;
    letter-spacing: -.9px;
    color: var(--ink);
}

.subtitle {
    color: var(--muted);
    font-size: 15px;
}

.top-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.people-control {
    display: flex;
    align-items: center;
    gap: 9px;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 7px 9px;
    box-shadow: 0 3px 12px rgba(74, 58, 42, .05);
}

.people-control button {
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 9px;
    background: var(--cream-dark);
    color: var(--green-dark);
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    transition: background .15s ease, transform .15s ease;
}

.people-control button:hover {
    background: #e9e0d4;
    transform: translateY(-1px);
}

.people-count {
    min-width: 112px;
    text-align: center;
    font-weight: 750;
    font-size: 14px;
}

.source-selector {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-bottom: 12px;
}

.generate-area {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 31px;
    flex-wrap: wrap;
}

.source-selector-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 750;
}

.source-buttons {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.source-btn {
    border: 1px solid var(--border);
    background: var(--white);
    color: var(--muted);
    border-radius: 10px;
    padding: 9px 12px;
    font-size: 12px;
    font-weight: 750;
    cursor: pointer;
    transition:
        background .15s ease,
        color .15s ease,
        border-color .15s ease,
        transform .15s ease;
}

.source-btn:hover {
    transform: translateY(-1px);
    border-color: #d7cdbf;
}

.source-btn.active {
    background: #edf3ee;
    border-color: var(--green);
    color: var(--green-dark);
}

.source-btn.active::before {
    content: '✓ ';
    font-weight: 850;
}


.generate-btn {
    border: 0;
    background: var(--green);
    color: white;
    border-radius: 13px;
    padding: 13px 19px;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 7px 18px rgba(95, 117, 99, .20);
    transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
}

.generate-btn:hover {
    background: var(--green-dark);
    transform: translateY(-1px);
    box-shadow: 0 9px 21px rgba(95, 117, 99, .24);
}

.generate-btn:disabled {
    opacity: .7;
    cursor: wait;
    transform: none;
}

.status {
    color: var(--muted);
    font-size: 13px;
}

.week-heading {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 14px;
}

.week-title {
    font-size: 15px;
    font-weight: 800;
    color: var(--ink);
}

.week-hint {
    color: var(--muted);
    font-size: 12px;
}

.menu-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
}

.day-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
    min-width: 0;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    transition: transform .16s ease, box-shadow .16s ease;
}

.day-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(74, 58, 42, .10);
}

.recipe-image-wrap {
    height: 154px;
    background: var(--cream-dark);
    overflow: hidden;
    position: relative;
}

.recipe-image {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
    transition: transform .3s ease;
}

.day-card:hover .recipe-image {
    transform: scale(1.025);
}

.recipe-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 42px;
    background: linear-gradient(135deg, #f5eee3, #eee5d8);
}

.day-content {
    padding: 15px 16px 16px;
    display: flex;
    flex-direction: column;
    flex: 1;
}

.day-name {
    color: var(--terracotta);
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .7px;
    margin-bottom: 8px;
}

.recipe-title {
    font-size: 17px;
    line-height: 1.25;
    font-weight: 800;
    margin-bottom: 7px;
}

.recipe-title a {
    color: inherit;
    text-decoration: none;
}

.recipe-title a:hover {
    color: var(--green-dark);
}

.recipe-category {
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 10px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.recipe-meta {
    font-size: 12px;
    color: var(--muted);
    margin-top: auto;
}

.recipe-rating {
    display: flex;
    gap: 8px;
    margin: 12px 0 10px;
}

.rating-btn {
    flex: 1;
    border: 1px solid #d8d8d8;
    border-radius: 8px;
    background: #fff;
    padding: 7px 8px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: 0.15s ease;
}

.rating-btn:hover {
    background: #f5f5f5;
}

.rating-btn.active {
    border-color: #333;
    background: #f0f0f0;
    font-weight: 600;
}

.rating-btn:disabled {
    opacity: 0.6;
    cursor: wait;
}

.replace-btn {
    margin-top: 13px;
    border: 0;
    background: transparent;
    color: var(--green-dark);
    cursor: pointer;
    text-align: left;
    padding: 0;
    font-size: 12px;
    font-weight: 750;
}

.replace-btn:hover {
    color: var(--terracotta-dark);
}

.empty-card {
    min-height: 238px;
    color: #9c958d;
    font-size: 14px;
    line-height: 1.5;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 25px;
}

.bottom-grid {
    margin-top: 32px;
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 18px;
}

.panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 22px;
    box-shadow: 0 5px 18px rgba(74, 58, 42, .04);
}

.panel-title {
    font-size: 17px;
    font-weight: 800;
    margin-bottom: 9px;
}

.shopping-empty {
    color: #9c958d;
    font-size: 14px;
    line-height: 1.5;
}


.guest-card {
    margin-top: 30px;
    background: linear-gradient(135deg, #fffaf4, #ffffff);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 24px 26px;
    box-shadow: 0 5px 18px rgba(74, 58, 42, .04);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 22px;
}

.guest-card-content {
    min-width: 0;
}

.guest-card-title {
    font-size: 19px;
    font-weight: 850;
    color: var(--green-dark);
    margin-bottom: 5px;
}

.guest-card-text {
    color: var(--muted);
    font-size: 14px;
    line-height: 1.5;
}

.guest-btn {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    padding: 13px 19px;
    background: var(--terracotta);
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 800;
    box-shadow: 0 7px 18px rgba(201, 120, 88, .20);
    transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
}

.guest-btn:hover {
    background: var(--terracotta-dark);
    transform: translateY(-1px);
    box-shadow: 0 9px 21px rgba(201, 120, 88, .24);
}

@media (max-width: 650px) {
    .guest-card {
        align-items: flex-start;
        flex-direction: column;
    }

    .guest-btn {
        width: 100%;
    }
}

@media (max-width: 1100px) {
    .menu-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 800px) {
    header {
        padding: 0 22px;
    }

    .top {
        flex-direction: column;
    }

    .top-controls {
        width: 100%;
        justify-content: flex-start;
    }

    .menu-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .bottom-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 650px) {
    header {
        height: 68px;
        padding: 0 18px;
    }

    .logo {
        font-size: 20px;
    }

    nav {
        display: none;
    }

    main {
        padding: 28px 16px 45px;
    }

    h1 {
        font-size: 28px;
    }

    .top-controls {
        align-items: stretch;
        flex-direction: column;
    }

    .people-control {
        align-self: flex-start;
    }

    .generate-area {
        align-items: flex-start;
        flex-direction: column;
        margin-bottom: 26px;
    }

    .source-selector {
        width: 100%;
    }

    .source-buttons {
        width: 100%;
    }

    .menu-grid {
        grid-template-columns: 1fr;
    }

    .recipe-image-wrap {
        height: 190px;
    }

    .week-heading {
        display: block;
    }

    .week-hint {
        display: block;
        margin-top: 4px;
    }
}
</style>
</head>

<body>

<header>
    <div class="logo">🍳 Ma <span>Cuisine</span></div>

    <nav>
        <a href="/" class="active">Accueil</a>
        <a href="/recipes.php">Recettes</a>
        <a href="/courses.php">Courses</a>
    </nav>

    <div class="header-actions">
        <a class="settings" href="/preferences.php" aria-label="Préférences">⚙️</a>
        <a class="logout" href="/logout.php" aria-label="Déconnexion">🚪</a>
    </div>
</header>

<main>

    <div class="top">

        <div>
            <h1>Bonjour Jérémy 👋</h1>
            <div class="subtitle">
                Voici ton menu de la semaine.
            </div>
        </div>

        <div class="top-controls">

            <form method="post" class="people-control">

                <button
                    type="submit"
                    name="people_count"
                    value="<?= max(1, $peopleCount - 1) ?>"
                >−</button>

                <div class="people-count">
                    👨‍👩‍👧 <?= $peopleCount ?> personne<?= $peopleCount > 1 ? 's' : '' ?>
                </div>

                <button
                    type="submit"
                    name="people_count"
                    value="<?= min(12, $peopleCount + 1) ?>"
                >+</button>

            </form>

        </div>

    </div>

    <div class="source-selector">
        <div class="source-selector-label">
            Sources des recettes
        </div>

        <div class="source-buttons">
            <button
                type="button"
                class="source-btn<?= $recipeSources['cuisineaz'] ? ' active' : '' ?>"
                data-source="cuisineaz"
                aria-pressed="<?= $recipeSources['cuisineaz'] ? 'true' : 'false' ?>"
                onclick="toggleRecipeSource(this)"
            >
                CuisineAZ
            </button>

            <button
                type="button"
                class="source-btn<?= $recipeSources['cookidoo'] ? ' active' : '' ?>"
                data-source="cookidoo"
                aria-pressed="<?= $recipeSources['cookidoo'] ? 'true' : 'false' ?>"
                onclick="toggleRecipeSource(this)"
            >
                Cookidoo
            </button>

            <button
                type="button"
                class="source-btn<?= $recipeSources['marmiton'] ? ' active' : '' ?>"
                data-source="marmiton"
                aria-pressed="<?= $recipeSources['marmiton'] ? 'true' : 'false' ?>"
                onclick="toggleRecipeSource(this)"
            >
                Marmiton
            </button>
        </div>
    </div>

    <div class="generate-area">

        <button
            class="generate-btn"
            type="button"
            id="generateBtn"
            onclick="generateMenu()"
        >
            <?= $hasMenu ? '🔄 Régénérer ma semaine' : '✨ Générer ma semaine' ?>
        </button>

        <button
            class="generate-btn print-week-btn"
            type="button"
            onclick="window.open('/print_week.php', '_blank')"
        >
            🖨️ Imprimer ma semaine
        </button>

        <div class="status" id="status">
            <?php if ($hasMenu): ?>
                Menu actuel enregistré.
            <?php else: ?>
                Aucune semaine générée.
            <?php endif; ?>
        </div>

    </div>

    <div class="week-heading">
        <div class="week-title">
            <?= htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="week-hint">
            Clique sur une recette pour voir sa fiche.
        </div>
    </div>

    <div class="menu-grid">

        <?php foreach ($days as $day): ?>

            <?php
            $date = $day['date']->format('Y-m-d');
            $recipe = $menuByDate[$date] ?? null;
            ?>

            <div class="day-card">

                <?php if ($recipe): ?>

                    <a
                        href="/recipe.php?id=<?= (int)$recipe['recipe_id'] ?>"
                        aria-label="Voir <?= htmlspecialchars($recipe['title'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <div class="recipe-image-wrap">

                            <?php if (!empty($recipe['image_url'])): ?>

                                <img
                                    class="recipe-image"
                                    src="<?= htmlspecialchars($recipe['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                    alt="<?= htmlspecialchars($recipe['title'], ENT_QUOTES, 'UTF-8') ?>"
                                    loading="lazy"
                                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                >

                                <div class="recipe-placeholder" style="display:none;">
                                    <?= $day['icon'] ?>
                                </div>

                            <?php else: ?>

                                <div class="recipe-placeholder">
                                    <?= $day['icon'] ?>
                                </div>

                            <?php endif; ?>

                        </div>
                    </a>

                    <div class="day-content">

                        <div class="day-name">
                            <?= htmlspecialchars($day['name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="recipe-title">
                            <a href="/recipe.php?id=<?= (int)$recipe['recipe_id'] ?>">
                                <?= htmlspecialchars($recipe['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </div>

                        <?php if (!empty($recipe['category'])): ?>
                            <div class="recipe-category">
                                <?= htmlspecialchars($recipe['category'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <div class="recipe-meta">
                            <?= (int)$recipe['servings'] ?> personne<?= (int)$recipe['servings'] > 1 ? 's' : '' ?>
                        </div>

                        <div class="recipe-rating" data-recipe-id="<?= (int)$recipe['recipe_id'] ?>">
                            <button
                                class="rating-btn <?= ((int)($recipe['rating'] ?? 0) === 1) ? 'active' : '' ?>"
                                type="button"
                                onclick="rateRecipe(<?= (int)$recipe['recipe_id'] ?>, 1, this)"
                            >
                                👍 J'aime
                            </button>

                            <button
                                class="rating-btn <?= ((int)($recipe['rating'] ?? 0) === -1) ? 'active' : '' ?>"
                                type="button"
                                onclick="rateRecipe(<?= (int)$recipe['recipe_id'] ?>, -1, this)"
                            >
                                👎 Pas aimé
                            </button>
                        </div>

                        <button
                            class="replace-btn"
                            type="button"
                            onclick="replaceDay('<?= $date ?>')"
                        >
                            ↻ Remplacer
                        </button>

                    </div>

                <?php else: ?>

                    <div class="empty-card">
                        Aucun repas prévu pour cette journée.
                    </div>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </div>


    <section class="guest-card">
        <div class="guest-card-content">
            <div class="guest-card-title">🎉 J’ai des invités</div>
            <div class="guest-card-text">
                Prépare un menu complet pour tes invités, avec plusieurs plats pour un vrai repas.
            </div>
        </div>

        <a class="guest-btn" href="/guest_menu.php">
            🎉 Créer un menu
        </a>
    </section>

    <div class="bottom-grid">

        <div class="panel">
            <div class="panel-title">🛒 Liste de courses</div>

            <?php if ($shoppingTotal > 0): ?>

                <div class="shopping-summary">
                    <strong><?= $shoppingTotal ?> articles</strong>
                    <span> · <?= $shoppingChecked ?> acheté<?= $shoppingChecked > 1 ? 's' : '' ?></span>
                </div>

                <a class="shopping-link" href="/courses.php">
                    Voir ma liste de courses →
                </a>

            <?php else: ?>

                <div class="shopping-empty">
                    La liste de courses n'est pas encore générée.
                </div>

                <a class="shopping-link" href="/courses.php">
                    Ouvrir les courses →
                </a>

            <?php endif; ?>
        </div>

    </div>

</main>

<script>

async function toggleRecipeSource(button) {

    const source = button.dataset.source;
    const wasActive = button.classList.contains('active');
    const enabled = !wasActive;

    button.disabled = true;

    try {

        const body = new URLSearchParams({
            source_update: '1',
            source: source,
            enabled: enabled ? '1' : '0'
        });

        const response = await fetch('/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString(),
            cache: 'no-store'
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Impossible de modifier la source.');
        }

        button.classList.toggle('active', enabled);
        button.setAttribute('aria-pressed', enabled ? 'true' : 'false');

    } catch (error) {

        console.error(error);
        alert('Impossible d’enregistrer ce choix.');

    } finally {

        button.disabled = false;
    }
}

async function generateMenu() {

    const button = document.getElementById('generateBtn');
    const status = document.getElementById('status');

    const hasMenu = <?= $hasMenu ? 'true' : 'false' ?>;

    if (hasMenu) {
        const confirmed = window.confirm(
            '⚠️ Régénérer la semaine ?\\n\\n' +
            'Les 5 repas actuels seront remplacés par une nouvelle sélection.'
        );

        if (!confirmed) {
            return;
        }
    }

    button.disabled = true;
    button.textContent = '⏳ Génération...';
    status.textContent = hasMenu
        ? 'Régénération de ta semaine...'
        : 'Création de ton menu...';

    try {

        const url = hasMenu
            ? '/api/generate_menu.php?force=1'
            : '/api/generate_menu.php';

        const response = await fetch(url, {
            method: 'GET',
            cache: 'no-store'
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Erreur pendant la génération.');
        }

        status.textContent = hasMenu
            ? 'Semaine régénérée !'
            : 'Menu généré !';

        window.location.reload();

    } catch (error) {

        console.error(error);

        status.textContent = '❌ ' + error.message;

        button.disabled = false;
        button.textContent = hasMenu
            ? '🔄 Régénérer ma semaine'
            : '✨ Générer ma semaine';
    }
}

async function replaceDay(date) {

    const status = document.getElementById('status');

    status.textContent = '⏳ Recherche d’une nouvelle recette...';

    try {

        const response = await fetch(
            '/api/replace_day.php?date=' + encodeURIComponent(date),
            {
                method: 'GET',
                cache: 'no-store'
            }
        );

        const data = await response.json();

        if (!data.success) {
            throw new Error(
                data.error || 'Impossible de remplacer cette recette.'
            );
        }

        status.textContent = '✅ ' + data.title + ' ajouté au menu.';

        window.location.reload();

    } catch (error) {

        console.error(error);

        status.textContent = '❌ ' + error.message;
    }
}

async function rateRecipe(recipeId, rating, button) {

    const container = button.closest('.recipe-rating');

    if (!container) {
        return;
    }

    const buttons = container.querySelectorAll('.rating-btn');

    buttons.forEach(item => {
        item.disabled = true;
    });

    try {

        const body = new URLSearchParams({
            recipe_id: String(recipeId),
            rating: String(rating)
        });

        const response = await fetch('/api/rate_recipe.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString(),
            cache: 'no-store'
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(
                data.error || 'Impossible d’enregistrer ton appréciation.'
            );
        }

        buttons.forEach(item => {
            item.classList.remove('active');
        });

        button.classList.add('active');

    } catch (error) {

        console.error(error);
        alert(error.message);

    } finally {

        buttons.forEach(item => {
            item.disabled = false;
        });
    }
}

</script>

</body>
</html>
