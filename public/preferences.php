<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$pdo = db();
$userId = requireAuth();

$stmt = $pdo->prepare("
    SELECT people_count, thermomix_preferred,
           dislikes_json, allergies_json, preferences_json
    FROM user_preferences
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$prefs = $stmt->fetch();

if (!$prefs) {
    $stmt = $pdo->prepare("
        INSERT INTO user_preferences
            (user_id, people_count, thermomix_preferred, dislikes_json, allergies_json, preferences_json)
        VALUES (?, 3, 0, '[]', '[]', '[]')
    ");
    $stmt->execute([$userId]);

    $prefs = [
        'people_count' => 3,
        'thermomix_preferred' => 0,
        'dislikes_json' => '[]',
        'allergies_json' => '[]',
        'preferences_json' => '[]',
    ];
}

function decodeList(?string $json): array
{
    if (!$json) {
        return [];
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [];
    }

    /*
     * preferences_json contient maintenant un objet avec notamment :
     * - recipe_sources
     * - food_preferences
     */
    if (isset($data['food_preferences']) && is_array($data['food_preferences'])) {
        return array_values(array_filter($data['food_preferences'], 'is_string'));
    }

    /* Compatibilité avec l'ancien format */
    return array_values(array_filter($data, 'is_string'));
}

$preferences = decodeList($prefs['preferences_json']);
$dislikes = decodeList($prefs['dislikes_json']);
$allergies = decodeList($prefs['allergies_json']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Préférences - Ma Cuisine</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f7f7f5;
            color: #292929;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .back {
            color: #555;
            text-decoration: none;
            font-size: 15px;
        }

        h1 {
            margin: 0;
            font-size: 30px;
        }

        .subtitle {
            color: #777;
            margin-top: 8px;
        }

        .card {
            background: white;
            border-radius: 18px;
            padding: 24px;
            margin-bottom: 18px;
            box-shadow: 0 3px 15px rgba(0,0,0,.06);
        }

        .card h2 {
            margin: 0 0 8px;
            font-size: 21px;
        }

        .card-description {
            color: #777;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .people-row {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .people-value {
            min-width: 55px;
            text-align: center;
            font-size: 25px;
            font-weight: bold;
        }

        .counter-btn {
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 50%;
            background: #eeeeeb;
            font-size: 24px;
            cursor: pointer;
        }

        .counter-btn:hover {
            background: #e3e3df;
        }

        .switch-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .switch-label {
            font-size: 16px;
            font-weight: 600;
        }

        .switch-description {
            color: #777;
            font-size: 14px;
            margin-top: 5px;
        }

        .switch {
            position: relative;
            width: 52px;
            height: 30px;
            flex-shrink: 0;
        }

        .switch input {
            display: none;
        }

        .slider {
            position: absolute;
            inset: 0;
            background: #ccc;
            border-radius: 30px;
            cursor: pointer;
            transition: .2s;
        }

        .slider:before {
            content: "";
            position: absolute;
            width: 24px;
            height: 24px;
            left: 3px;
            top: 3px;
            background: white;
            border-radius: 50%;
            transition: .2s;
            box-shadow: 0 1px 3px rgba(0,0,0,.25);
        }

        .switch input:checked + .slider {
            background: #4caf50;
        }

        .switch input:checked + .slider:before {
            transform: translateX(22px);
        }

        .field-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }

        textarea {
            width: 100%;
            min-height: 90px;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 12px;
            font-family: inherit;
            font-size: 15px;
            resize: vertical;
        }

        textarea:focus {
            outline: none;
            border-color: #999;
        }

        .save-area {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
        }

        .save-btn {
            border: 0;
            border-radius: 12px;
            padding: 13px 24px;
            background: #292929;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .save-btn:hover {
            background: #111;
        }

        .message {
            font-size: 14px;
            color: #4caf50;
            opacity: 0;
            transition: opacity .2s;
        }

        .message.visible {
            opacity: 1;
        }

        @media (max-width: 600px) {
            .container {
                padding: 20px 15px 40px;
            }

            h1 {
                font-size: 25px;
            }

            .card {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
<div style="margin-bottom: 20px;">
    <a href="/security.php">🔐 Sécurité du compte</a>
</div>


<div class="container">

    <div class="topbar">
        <div>
            <h1>⚙️ Mes préférences</h1>
            <div class="subtitle">
                Personnalise la façon dont Ma Cuisine prépare tes menus.
            </div>
        </div>

        <a class="back" href="/">← Accueil</a>
    </div>

    <div class="card">
        <h2>👨‍👩‍👧‍👦 Mon foyer</h2>

        <div class="card-description">
            Ce nombre sera utilisé par défaut pour les recettes et les menus.
        </div>

        <div class="people-row">
            <button type="button" class="counter-btn" id="minus">−</button>

            <div class="people-value">
                <span id="peopleCount"><?= (int)$prefs['people_count'] ?></span>
            </div>

            <button type="button" class="counter-btn" id="plus">+</button>
        </div>
    </div>

    <div class="card">
        <h2>🍽️ Ma façon de cuisiner</h2>

        <div class="switch-row">
            <div>
                <div class="switch-label">
                    Privilégier les recettes Thermomix
                </div>

                <div class="switch-description">
                    Lorsque c'est possible, les recettes compatibles seront favorisées.
                </div>
            </div>

            <label class="switch">
                <input
                    type="checkbox"
                    id="thermomix"
                    <?= ((int)$prefs['thermomix_preferred'] === 1) ? 'checked' : '' ?>
                >
                <span class="slider"></span>
            </label>
        </div>
    </div>

    <div class="card">
        <h2>❤️ Ce que j'aime</h2>

        <div class="card-description">
            Indique les aliments, cuisines ou types de plats que tu apprécies.
            Sépare les éléments par des virgules.
        </div>

        <label class="field-label" for="preferences">
            Préférences alimentaires
        </label>

        <textarea id="preferences"><?= htmlspecialchars(
            implode(', ', $preferences),
            ENT_QUOTES,
            'UTF-8'
        ) ?></textarea>
    </div>

    <div class="card">
        <h2>🚫 Ce que je n'aime pas</h2>

        <div class="card-description">
            Les aliments ou plats que tu préfères éviter.
        </div>

        <label class="field-label" for="dislikes">
            Aliments à éviter
        </label>

        <textarea id="dislikes"><?= htmlspecialchars(
            implode(', ', $dislikes),
            ENT_QUOTES,
            'UTF-8'
        ) ?></textarea>
    </div>

    <div class="card">
        <h2>⚠️ Allergies et intolérances</h2>

        <div class="card-description">
            Ces informations seront considérées comme des contraintes lors
            de la génération des menus.
        </div>

        <label class="field-label" for="allergies">
            Allergies / intolérances
        </label>

        <textarea id="allergies"><?= htmlspecialchars(
            implode(', ', $allergies),
            ENT_QUOTES,
            'UTF-8'
        ) ?></textarea>
    </div>

    <div class="save-area">
        <span class="message" id="message">
            Préférences enregistrées ✓
        </span>

        <button type="button" class="save-btn" id="save">
            Enregistrer
        </button>
    </div>

</div>

<script>
let peopleCount = <?= (int)$prefs['people_count'] ?>;

const peopleEl = document.getElementById('peopleCount');
const messageEl = document.getElementById('message');

function updatePeople() {
    peopleEl.textContent = peopleCount;
}

document.getElementById('minus').addEventListener('click', () => {
    if (peopleCount > 1) {
        peopleCount--;
        updatePeople();
    }
});

document.getElementById('plus').addEventListener('click', () => {
    if (peopleCount < 12) {
        peopleCount++;
        updatePeople();
    }
});

function parseList(value) {
    return value
        .split(/[\n,]+/)
        .map(item => item.trim())
        .filter(item => item.length > 0);
}

document.getElementById('save').addEventListener('click', async () => {
    const payload = {
        people_count: peopleCount,
        thermomix_preferred:
            document.getElementById('thermomix').checked,
        preferences:
            parseList(document.getElementById('preferences').value),
        dislikes:
            parseList(document.getElementById('dislikes').value),
        allergies:
            parseList(document.getElementById('allergies').value)
    };

    try {
        const response = await fetch('/api/save_preferences.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || 'Erreur lors de l’enregistrement.'
            );
        }

        messageEl.classList.add('visible');

        setTimeout(() => {
            messageEl.classList.remove('visible');
        }, 2500);

    } catch (error) {
        alert(error.message);
    }
});
</script>

</body>
</html>
