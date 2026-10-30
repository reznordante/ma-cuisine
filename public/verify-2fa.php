<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (isAuthenticated()) {
    header('Location: /');
    exit;
}

if (!isset($_SESSION['pending_user_id']) || !is_int($_SESSION['pending_user_id'])) {
    header('Location: /login.php');
    exit;
}

$userId = $_SESSION['pending_user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));

    if (strlen($code) !== 6) {
        $error = 'Veuillez saisir le code à 6 chiffres.';
    } else {
        $pdo = db();

        $stmt = $pdo->prepare("
            SELECT totp_secret, totp_enabled
            FROM user_auth
            WHERE user_id = ?
            LIMIT 1
        ");

        $stmt->execute([$userId]);
        $auth = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $auth &&
            (int)$auth['totp_enabled'] === 1 &&
            !empty($auth['totp_secret'])
        ) {
            $google2fa = new PragmaRX\Google2FA\Google2FA();

            if ($google2fa->verifyKey($auth['totp_secret'], $code)) {
                unset($_SESSION['pending_user_id']);

                loginUser($userId);

                header('Location: /');
                exit;
            }
        }

        $error = 'Code de vérification incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification — Ma Cuisine</title>
    <style>
        :root {
            --green: #6f8f72;
            --green-dark: #55705a;
            --bg: #f7f5ef;
            --card: #ffffff;
            --text: #333333;
            --muted: #777777;
            --error: #b94a48;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, sans-serif;
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        h1 {
            margin: 0 0 10px;
        }

        .subtitle {
            color: var(--muted);
            margin-bottom: 28px;
            line-height: 1.5;
        }

        input {
            width: 100%;
            padding: 14px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 8px;
            margin-bottom: 18px;
        }

        input:focus {
            outline: none;
            border-color: var(--green);
        }

        button {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 13px;
            background: var(--green);
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: var(--green-dark);
        }

        .error {
            margin-bottom: 18px;
            padding: 12px;
            border-radius: 10px;
            background: #fbeaea;
            color: var(--error);
        }

        .back {
            display: block;
            margin-top: 18px;
            color: var(--muted);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔐 Vérification</h1>

        <div class="subtitle">
            Saisissez le code à 6 chiffres affiché dans FreeOTP.
        </div>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <input
                type="text"
                name="code"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                autocomplete="one-time-code"
                required
                autofocus
            >

            <button type="submit">Vérifier</button>
        </form>

        <a class="back" href="/login.php">Retour à la connexion</a>
    </div>
</body>
</html>
