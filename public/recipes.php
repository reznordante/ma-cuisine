<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$pdo = db();
$userId = requireAuth();

$search = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$thermomix = isset($_GET['thermomix']) && $_GET['thermomix'] === '1';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(r.title LIKE :search_title OR r.description LIKE :search_description OR r.keywords LIKE :search_keywords)";
    $term = '%' . $search . '%';
    $params['search_title'] = $term;
    $params['search_description'] = $term;
    $params['search_keywords'] = $term;
}

if ($category !== '') {
    $where[] = "r.category = :category";
    $params['category'] = $category;
}

if ($thermomix) {
    $where[] = "r.is_thermomix = 1";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM recipes r
    $whereSql
");
$countStmt->execute($params);
$totalRecipes = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRecipes / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.title,
        r.description,
        r.image_url,
        r.total_time_minutes,
        r.servings,
        r.category,
        r.is_thermomix,
        r.is_demo
    FROM recipes r
    $whereSql
    ORDER BY r.title ASC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$recipes = $stmt->fetchAll();

$categoriesStmt = $pdo->query("
    SELECT DISTINCT category
    FROM recipes
    WHERE category IS NOT NULL
      AND TRIM(category) <> ''
    ORDER BY category ASC
");

$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

$peopleStmt = $pdo->prepare("
    SELECT people_count
    FROM user_preferences
    WHERE user_id = ?
    LIMIT 1
");
$peopleStmt->execute([$userId]);
$peopleCount = (int)($peopleStmt->fetchColumn() ?: 3);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function recipeUrl(int $id, int $peopleCount): string
{
    return '/recipe.php?id=' . $id . '&people=' . $peopleCount;
}

function queryUrl(array $changes = []): string
{
    $query = $_GET;

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return '/recipes.php' . ($query ? '?' . http_build_query($query) : '');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recettes — Ma Cuisine</title>

<style>
:root {
    --cream: #fbf7f0;
    --green: #5f7563;
    --green-dark: #4e6252;
    --terracotta: #c97858;
    --text: #2c2925;
    --muted: #7c746b;
    --white: #ffffff;
    --border: #e9e0d4;
    --shadow: 0 8px 24px rgba(74,58,42,.07);
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

header {
    height: 72px;
    background: var(--cream);
    border-bottom: 1px solid var(--border);
}

.header-inner {
    max-width: 1200px;
    height: 100%;
    margin: auto;
    padding: 0 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brand {
    color: var(--text);
    text-decoration: none;
    font-size: 21px;
    font-weight: 800;
}

.brand-icon {
    margin-right: 7px;
}

nav {
    display: flex;
    align-items: center;
    gap: 28px;
}

nav a {
    color: #4c554f;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
}

nav a:hover,
nav a.active {
    color: var(--terracotta);
}

.settings {
    color: #7f718b;
    text-decoration: none;
    font-size: 23px;
    margin-left: 30px;
}

main {
    max-width: 1100px;
    margin: 0 auto;
    padding: 38px 22px 60px;
}

.page-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 24px;
}

.page-heading h1 {
    margin: 0;
    font-size: 31px;
    letter-spacing: -.5px;
}

.page-heading p {
    margin: 7px 0 0;
    color: var(--muted);
    font-size: 14px;
}

.people-info {
    color: var(--muted);
    font-size: 13px;
}

.filters {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 17px;
    padding: 16px;
    box-shadow: var(--shadow);
    margin-bottom: 25px;
}

.filter-form {
    display: grid;
    grid-template-columns: 1.8fr 1fr auto auto;
    gap: 10px;
    align-items: center;
}

.search,
.select {
    height: 45px;
    width: 100%;
    border: 1px solid #ddd7ce;
    border-radius: 10px;
    background: white;
    padding: 0 13px;
    color: var(--text);
    font: inherit;
}

.search:focus,
.select:focus {
    outline: none;
    border-color: var(--green);
}

.filter-btn {
    height: 45px;
    border: 0;
    border-radius: 10px;
    background: var(--green);
    color: white;
    padding: 0 18px;
    font-weight: 700;
    cursor: pointer;
}

.filter-btn:hover {
    background: var(--green-dark);
}

.reset-btn {
    height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 14px;
    border-radius: 10px;
    background: #eee8df;
    color: var(--text);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.thermomix {
    display: flex;
    align-items: center;
    gap: 7px;
    white-space: nowrap;
    font-size: 13px;
    font-weight: 600;
}

.thermomix input {
    accent-color: var(--green);
}

.results-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 0 2px 14px;
    color: var(--muted);
    font-size: 13px;
}

.recipes-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}

.recipe-card {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 16px;
    color: inherit;
    text-decoration: none;
    box-shadow: var(--shadow);
    transition: transform .15s ease, box-shadow .15s ease;
}

.recipe-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(78,98,82,.13);
}

.recipe-image {
    aspect-ratio: 1.45;
    background: #f1ece4;
    position: relative;
    overflow: hidden;
}

.recipe-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.recipe-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 48px;
}

.badges {
    position: absolute;
    top: 10px;
    left: 10px;
    display: flex;
    gap: 6px;
}

.badge {
    background: rgba(255,253,249,.94);
    border-radius: 20px;
    padding: 5px 9px;
    font-size: 10px;
    font-weight: 800;
}

.badge.demo {
    color: #966700;
}

.badge.thermomix-badge {
    color: var(--green-dark);
}

.recipe-body {
    padding: 14px 14px 15px;
}

.recipe-category {
    margin-bottom: 5px;
    color: var(--terracotta);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.recipe-title {
    font-size: 16px;
    font-weight: 800;
    line-height: 1.25;
    margin-bottom: 7px;
}

.recipe-description {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
    min-height: 34px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.recipe-meta {
    display: flex;
    gap: 12px;
    margin-top: 12px;
    color: var(--muted);
    font-size: 12px;
}

.empty {
    padding: 60px 20px;
    text-align: center;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 17px;
}

.empty-icon {
    font-size: 42px;
    margin-bottom: 10px;
}

.empty h2 {
    margin: 0 0 7px;
}

.empty p {
    margin: 0;
    color: var(--muted);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 7px;
    margin-top: 28px;
}

.page-btn {
    min-width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: var(--white);
    border: 1px solid var(--border);
    color: var(--text);
    text-decoration: none;
    font-size: 13px;
}

.page-btn.active {
    background: var(--green);
    color: white;
    border-color: var(--green);
    font-weight: 800;
}

@media (max-width: 950px) {
    .recipes-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .filter-form {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 650px) {
    header {
        height: auto;
    }

    .header-inner {
        min-height: 70px;
        flex-wrap: wrap;
        padding: 13px 18px;
    }

    nav {
        order: 3;
        width: 100%;
        justify-content: center;
        padding-bottom: 7px;
        gap: 22px;
    }

    .settings {
        margin-left: auto;
    }

    main {
        padding: 25px 15px 45px;
    }

    .page-heading {
        align-items: flex-start;
    }

    .recipes-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .filter-form {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 450px) {
    .recipes-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<header>
    <div class="header-inner">
        <a class="brand" href="/">
            <span class="brand-icon">🔍</span>Ma Cuisine
        </a>

        <nav>
            <a href="/">Accueil</a>
            <a href="/recipes.php" class="active">Recettes</a>
            <a href="/courses.php">Courses</a>
        </nav>

        <a class="settings" href="/preferences.php" aria-label="Préférences">⚙️</a>
    </div>
</header>

<main>

    <div class="page-heading">
        <div>
            <h1>🍽️ Mes recettes</h1>
            <p><?= number_format($totalRecipes, 0, ',', ' ') ?> recette<?= $totalRecipes > 1 ? 's' : '' ?> disponible<?= $totalRecipes > 1 ? 's' : '' ?></p>
        </div>

        <div class="people-info">
            👥 <?= $peopleCount ?> personne<?= $peopleCount > 1 ? 's' : '' ?>
        </div>
    </div>

    <div class="filters">
        <form class="filter-form" method="get" action="/recipes.php">

            <input
                class="search"
                type="search"
                name="q"
                value="<?= e($search) ?>"
                placeholder="🔎 Rechercher une recette..."
            >

            <select class="select" name="category">
                <option value="">Toutes les catégories</option>

                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e((string)$cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                        <?= e((string)$cat) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="thermomix">
                <input type="checkbox" name="thermomix" value="1" <?= $thermomix ? 'checked' : '' ?>>
                🌡️ Thermomix
            </label>

            <button class="filter-btn" type="submit">Rechercher</button>

            <?php if ($search !== '' || $category !== '' || $thermomix): ?>
                <a class="reset-btn" href="/recipes.php">Réinitialiser</a>
            <?php endif; ?>

        </form>
    </div>

    <?php if ($recipes): ?>

        <div class="results-bar">
            <span><?= number_format($totalRecipes, 0, ',', ' ') ?> résultat<?= $totalRecipes > 1 ? 's' : '' ?></span>
            <span>Page <?= $page ?> / <?= $totalPages ?></span>
        </div>

        <div class="recipes-grid">

            <?php foreach ($recipes as $recipe): ?>

                <a class="recipe-card"
                   href="<?= e(recipeUrl((int)$recipe['id'], $peopleCount)) ?>">

                    <div class="recipe-image">

                        <?php if (!empty($recipe['image_url'])): ?>

                            <img
                                src="<?= e((string)$recipe['image_url']) ?>"
                                alt="<?= e((string)$recipe['title']) ?>"
                                loading="lazy"
                            >

                        <?php else: ?>

                            <div class="recipe-placeholder">🍽️</div>

                        <?php endif; ?>

                        <div class="badges">

                            <?php if ((int)$recipe['is_demo'] === 1): ?>
                                <span class="badge demo">Démo</span>
                            <?php endif; ?>

                            <?php if ((int)$recipe['is_thermomix'] === 1): ?>
                                <span class="badge thermomix-badge">🌡️ Thermomix</span>
                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="recipe-body">

                        <?php if (!empty($recipe['category'])): ?>
                            <div class="recipe-category">
                                <?= e((string)$recipe['category']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="recipe-title">
                            <?= e((string)$recipe['title']) ?>
                        </div>

                        <?php if (!empty($recipe['description'])): ?>
                            <div class="recipe-description">
                                <?= e((string)$recipe['description']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="recipe-meta">

                            <?php if (!empty($recipe['total_time_minutes'])): ?>
                                <span>⏱️ <?= (int)$recipe['total_time_minutes'] ?> min</span>
                            <?php endif; ?>

                            <?php if (!empty($recipe['servings'])): ?>
                                <span>👥 <?= (int)$recipe['servings'] ?></span>
                            <?php endif; ?>

                        </div>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

        <?php if ($totalPages > 1): ?>

            <div class="pagination">

                <?php if ($page > 1): ?>
                    <a class="page-btn"
                       href="<?= e(queryUrl(['page' => $page - 1])) ?>">←</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                ?>

                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                    <a class="page-btn <?= $p === $page ? 'active' : '' ?>"
                       href="<?= e(queryUrl(['page' => $p])) ?>">
                        <?= $p ?>
                    </a>

                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-btn"
                       href="<?= e(queryUrl(['page' => $page + 1])) ?>">→</a>
                <?php endif; ?>

            </div>

        <?php endif; ?>

    <?php else: ?>

        <div class="empty">
            <div class="empty-icon">🔎</div>
            <h2>Aucune recette trouvée</h2>
            <p>Essaie de modifier ta recherche ou tes filtres.</p>
        </div>

    <?php endif; ?>

</main>

</body>
</html>
