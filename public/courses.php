<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$pdo = db();
$userId = requireAuth();

$weekStart = new DateTimeImmutable('monday this week');
$weekStartSql = $weekStart->format('Y-m-d');
$weekEnd = $weekStart->modify('+4 days');

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

$weekLabel =
    $weekStart->format('j') . ' ' .
    $monthNames[(int)$weekStart->format('n')] . ' au ' .
    $weekEnd->format('j') . ' ' .
    $monthNames[(int)$weekEnd->format('n')] . ' ' .
    $weekEnd->format('Y');

$stmt = $pdo->prepare("
    SELECT
        si.id,
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
    ORDER BY si.checked ASC, si.ingredient_name ASC
");

$stmt->execute([$userId, $userId, $weekStartSql]);
$items = $stmt->fetchAll();

$total = count($items);
$checked = count(array_filter(
    $items,
    static fn(array $item): bool => (bool)$item['checked']
));

$percent = $total > 0 ? round(($checked / $total) * 100) : 0;

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

    return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Courses — Ma Cuisine</title>

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
    max-width: 900px;
    margin: 0 auto;
    padding: 38px 22px 60px;
}

.page-heading {
    text-align: center;
    margin-bottom: 25px;
}

.page-heading h1 {
    margin: 0;
    font-size: 31px;
}

.page-heading p {
    margin: 8px 0 0;
    color: var(--muted);
    font-size: 15px;
}

.progress-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 17px;
    padding: 20px;
    box-shadow: var(--shadow);
    margin-bottom: 18px;
}

.progress-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.progress-text {
    font-weight: 800;
    color: var(--green-dark);
}

.progress-percent {
    color: var(--muted);
    font-size: 13px;
}

.progress-bar {
    height: 9px;
    background: #eee7dd;
    border-radius: 20px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    width: <?= $percent ?>%;
    background: var(--green);
    border-radius: 20px;
    transition: width .25s ease;
}

.list-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 17px;
    box-shadow: var(--shadow);
    overflow: hidden;
}

.list-header {
    padding: 19px 21px;
    background: #f7f1e8;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.list-title {
    font-size: 19px;
    font-weight: 800;
}

.list-subtitle {
    color: var(--muted);
    font-size: 13px;
    margin-top: 4px;
}

.generate-btn {
    border: 0;
    border-radius: 10px;
    padding: 10px 14px;
    background: var(--green);
    color: white;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
}

.generate-btn:hover {
    background: var(--green-dark);
}

.shopping-item {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 58px;
    padding: 13px 21px;
    border-bottom: 1px solid #eee7dd;
    cursor: pointer;
    transition: background .15s ease;
}

.shopping-item:last-child {
    border-bottom: 0;
}

.shopping-item:hover {
    background: #fcfaf6;
}

.shopping-item input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.checkbox {
    width: 23px;
    height: 23px;
    min-width: 23px;
    border: 2px solid #c8c0b5;
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 900;
}

.shopping-item.checked .checkbox {
    background: var(--green);
    border-color: var(--green);
}

.shopping-item.checked .checkbox::after {
    content: "✓";
}

.item-name {
    flex: 1;
    font-size: 15px;
    font-weight: 600;
}

.item-quantity {
    color: var(--muted);
    font-size: 13px;
    white-space: nowrap;
}

.shopping-item.checked .item-name {
    color: var(--checked);
    text-decoration: line-through;
}

.shopping-item.checked .item-quantity {
    color: #a7aaa7;
}

.empty {
    padding: 65px 25px;
    text-align: center;
}

.empty-icon {
    font-size: 45px;
    margin-bottom: 12px;
}

.empty h2 {
    margin: 0 0 8px;
    font-size: 21px;
}

.empty p {
    margin: 0;
    color: var(--muted);
    line-height: 1.5;
}

.bottom-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 22px;
}

.bottom-actions a,
.print-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 15px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--white);
    color: var(--text);
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
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

    .list-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .generate-btn {
        width: 100%;
    }

    .item-quantity {
        font-size: 12px;
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
            <a href="/recipes.php">Recettes</a>
            <a href="/courses.php" class="active">Courses</a>
        </nav>

        <a class="settings" href="/preferences.php" aria-label="Préférences">⚙️</a>

    </div>
</header>

<main>

    <div class="page-heading">
        <h1>🛒 Ma liste de courses</h1>
        <p>Semaine du <?= e($weekLabel) ?></p>
    </div>

    <div class="progress-card">
        <div class="progress-top">
            <div class="progress-text">
                <span id="checkedCount"><?= $checked ?></span> / <span id="totalCount"><?= $total ?></span> articles achetés
            </div>

            <div class="progress-percent" id="progressPercent">
                <?= $percent ?> %
            </div>
        </div>

        <div class="progress-bar">
            <div class="progress-fill" id="progressFill"></div>
        </div>
    </div>

    <div class="list-card">

        <div class="list-header">

            <div>
                <div class="list-title">🛒 Courses de la semaine</div>
                <div class="list-subtitle">
                    Coche les produits au fur et à mesure de tes achats.
                </div>
            </div>

            <button class="generate-btn" id="generateBtn" type="button">
                🔄 Générer la liste
            </button>

        </div>

        <div id="shoppingList">

            <?php if ($items): ?>

                <?php foreach ($items as $item): ?>

                    <label
                        class="shopping-item <?= (int)$item['checked'] === 1 ? 'checked' : '' ?>"
                        data-id="<?= (int)$item['id'] ?>"
                    >

                        <input
                            type="checkbox"
                            <?= (int)$item['checked'] === 1 ? 'checked' : '' ?>
                        >

                        <span class="checkbox"></span>

                        <span class="item-name">
                            <?= e((string)$item['ingredient_name']) ?>
                        </span>

                        <?php
                        $quantity = formatQuantity($item['quantity']);
                        $unit = trim((string)$item['unit']);
                        ?>

                        <?php if ($quantity !== '' || $unit !== ''): ?>
                            <span class="item-quantity">
                                <?= e(trim($quantity . ' ' . $unit)) ?>
                            </span>
                        <?php endif; ?>

                    </label>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty">
                    <div class="empty-icon">🛒</div>
                    <h2>Ta liste est encore vide</h2>
                    <p>
                        Clique sur « Générer la liste » pour créer les courses
                        à partir des recettes de ta semaine.
                    </p>
                </div>

            <?php endif; ?>

        </div>

    </div>

    <div class="bottom-actions">
        <button class="print-btn" type="button" onclick="window.print()">
            🖨️ Imprimer
        </button>

        <a href="/">
            ← Ma semaine
        </a>
    </div>

</main>

<script>
function updateProgress() {
    const items = document.querySelectorAll('.shopping-item');
    const total = items.length;
    const checked = document.querySelectorAll('.shopping-item.checked').length;

    const percent = total > 0 ? Math.round((checked / total) * 100) : 0;

    document.getElementById('checkedCount').textContent = checked;
    document.getElementById('totalCount').textContent = total;
    document.getElementById('progressPercent').textContent = percent + ' %';
    document.getElementById('progressFill').style.width = percent + '%';
}

document.querySelectorAll('.shopping-item').forEach(item => {

    const checkbox = item.querySelector('input');

    checkbox.addEventListener('change', async () => {

        const checked = checkbox.checked;
        item.classList.toggle('checked', checked);

        try {

            const response = await fetch('/api/toggle_shopping_item.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: Number(item.dataset.id),
                    checked: checked
                })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Erreur.');
            }

            updateProgress();

        } catch (error) {

            checkbox.checked = !checked;
            item.classList.toggle('checked', checkbox.checked);

            alert(error.message);
        }
    });
});

document.getElementById('generateBtn').addEventListener('click', async () => {

    const button = document.getElementById('generateBtn');

    button.disabled = true;
    button.textContent = '⏳ Génération...';

    try {

        const response = await fetch('/api/generate_shopping_list.php', {
            method: 'POST'
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Impossible de générer la liste.');
        }

        window.location.reload();

    } catch (error) {

        alert(error.message);

        button.disabled = false;
        button.textContent = '🔄 Générer la liste';
    }
});
</script>

</body>
</html>
