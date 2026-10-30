<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

if (isAuthenticated()) {
    header('Location: /');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Veuillez renseigner votre email et votre mot de passe.';
    } else {
        $pdo = db();
        cleanupAuthAttempts($pdo);

        $accountKey = authAccountKey($email);

        if (authRateLimited($pdo, 'password', $accountKey)) {
            $error = 'Trop de tentatives. Veuillez patienter quelques minutes.';
        } else {

        $stmt = $pdo->prepare("
            SELECT u.id, u.email, ua.password_hash, ua.totp_enabled
            FROM users u
            INNER JOIN user_auth ua ON ua.user_id = u.id
            WHERE u.email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            clearAuthAccountFailures($pdo, 'password', $accountKey);

            session_regenerate_id(true);

            if ((int)$user['totp_enabled'] === 1) {
                $_SESSION['pending_user_id'] = (int)$user['id'];
                header('Location: /verify-2fa.php');
                exit;
            }

            loginUser((int)$user['id']);
            header('Location: /');
            exit;
        }

        recordAuthFailure($pdo, 'password', $accountKey);
        $error = 'Email ou mot de passe incorrect.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — Ma Cuisine</title>
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

        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin: 0 0 8px;
            text-align: center;
        }

        .subtitle {
            text-align: center;
            color: var(--muted);
            margin-bottom: 28px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
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
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>🍳 Ma Cuisine</h1>
        <div class="subtitle">Connexion à votre compte</div>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                autocomplete="username"
                required
            >

            <label for="password">Mot de passe</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Se connecter</button>
        </form>
    </div>
</body>
</html>
