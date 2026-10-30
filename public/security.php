<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

$userId = requireAuth();
$pdo = db();

$google2fa = new PragmaRX\Google2FA\Google2FA();

$message = '';
$error = '';

$stmt = $pdo->prepare("
    SELECT
        u.email,
        ua.password_hash,
        ua.totp_secret,
        ua.totp_enabled
    FROM users u
    INNER JOIN user_auth ua ON ua.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    exit('Utilisateur introuvable.');
}

/*
 * Actions POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    /*
     * Génération d'un secret 2FA
     */
    if ($action === 'generate') {

        $secret = $google2fa->generateSecretKey();

        $stmt = $pdo->prepare("
            UPDATE user_auth
            SET totp_secret = ?, totp_enabled = 0
            WHERE user_id = ?
        ");
        $stmt->execute([$secret, $userId]);

        $user['totp_secret'] = $secret;
        $user['totp_enabled'] = 0;

        $message = 'Une nouvelle clé 2FA a été générée. Scannez le QR code avec FreeOTP.';
    }

    /*
     * Activation du 2FA
     */
    elseif ($action === 'enable') {

        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));

        if (strlen($code) !== 6) {
            $error = 'Veuillez saisir un code à 6 chiffres.';
        } elseif (empty($user['totp_secret'])) {
            $error = 'Aucune clé 2FA n’a été générée.';
        } elseif (!$google2fa->verifyKey($user['totp_secret'], $code)) {
            $error = 'Code 2FA incorrect.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE user_auth
                SET totp_enabled = 1
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);

            $user['totp_enabled'] = 1;

            $message = 'La double authentification est maintenant activée.';
        }
    }

    /*
     * Changement de mot de passe
     */
    elseif ($action === 'change_password') {

        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $code = preg_replace('/\D/', '', (string)($_POST['totp_code'] ?? ''));

        if (!$user['totp_enabled']) {
            $error = 'Activez d’abord la double authentification pour pouvoir modifier votre mot de passe.';
        } elseif ($currentPassword === '') {
            $error = 'Veuillez saisir votre mot de passe actuel.';
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            $error = 'Le mot de passe actuel est incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Les deux nouveaux mots de passe ne correspondent pas.';
        } elseif ($newPassword === $currentPassword) {
            $error = 'Le nouveau mot de passe doit être différent de l’ancien.';
        } elseif (strlen($code) !== 6) {
            $error = 'Veuillez saisir le code FreeOTP à 6 chiffres.';
        } elseif (
            empty($user['totp_secret']) ||
            !$google2fa->verifyKey($user['totp_secret'], $code)
        ) {
            $error = 'Code FreeOTP incorrect.';
        } else {

            $passwordHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare("
                UPDATE user_auth
                SET password_hash = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$passwordHash, $userId]);

            $user['password_hash'] = $passwordHash;

            $message = 'Votre mot de passe a été modifié avec succès.';
        }
    }
}

$totpUri = '';

if (!empty($user['totp_secret'])) {
    $totpUri = $google2fa->getQRCodeUrl(
        'Ma Cuisine',
        (string)$user['email'],
        $user['totp_secret']
    );
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sécurité — Ma Cuisine</title>

    <style>
        :root {
            --green: #6f8f72;
            --green-dark: #55705a;
            --bg: #f7f5ef;
            --card: #ffffff;
            --text: #333333;
            --muted: #777777;
            --border: #e2e0d9;
            --success-bg: #eaf4eb;
            --success: #416a48;
            --error-bg: #fbeaea;
            --error: #a33d3d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, sans-serif;
            padding: 30px 20px;
        }

        .container {
            max-width: 760px;
            margin: 0 auto;
        }

        h1 {
            margin: 0 0 8px;
        }

        .intro {
            color: var(--muted);
            margin-bottom: 28px;
        }

        .card {
            background: var(--card);
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.06);
        }

        h2 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .description {
            color: var(--muted);
            line-height: 1.5;
            margin-bottom: 22px;
        }

        .status {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .status-enabled {
            background: var(--success-bg);
            color: var(--success);
        }

        .status-disabled {
            background: #eeeeee;
            color: #666666;
        }

        .message,
        .error {
            padding: 13px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .message {
            background: var(--success-bg);
            color: var(--success);
        }

        .error {
            background: var(--error-bg);
            color: var(--error);
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
        }

        input {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 16px;
            margin-bottom: 17px;
        }

        input:focus {
            outline: none;
            border-color: var(--green);
        }

        button {
            border: 0;
            border-radius: 10px;
            padding: 12px 18px;
            background: var(--green);
            color: white;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover {
            background: var(--green-dark);
        }

        .qr {
            text-align: center;
            margin: 20px 0;
        }

        .qr img {
            width: 280px;
            height: 280px;
            max-width: 100%;
            border-radius: 8px;
        }

        .secret {
            background: #f5f3ed;
            border-radius: 10px;
            padding: 12px;
            word-break: break-all;
            font-family: monospace;
            text-align: center;
            margin: 15px 0 22px;
        }

        .back {
            display: inline-block;
            color: var(--green-dark);
            text-decoration: none;
            margin-top: 5px;
        }

        .back:hover {
            text-decoration: underline;
        }

        .code-input {
            max-width: 220px;
            text-align: center;
            font-size: 22px;
            letter-spacing: 6px;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>🔐 Sécurité</h1>

    <div class="intro">
        Gérez la sécurité de votre compte Ma Cuisine.
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>


    <!-- DOUBLE AUTHENTIFICATION -->

    <div class="card">

        <h2>📱 Double authentification</h2>

        <div class="description">
            Utilisez FreeOTP pour sécuriser votre compte avec un code supplémentaire lors de la connexion.
        </div>

        <?php if ((int)$user['totp_enabled'] === 1): ?>

            <div class="status status-enabled">
                ✓ Double authentification activée
            </div>

            <p>
                Votre compte est actuellement protégé par la double authentification.
            </p>

        <?php else: ?>

            <div class="status status-disabled">
                Double authentification non activée
            </div>

            <?php if (empty($user['totp_secret'])): ?>

                <form method="post">
                    <input type="hidden" name="action" value="generate">
                    <button type="submit">
                        Générer une clé 2FA
                    </button>
                </form>

            <?php else: ?>

                <p>
                    Scannez ce QR code avec FreeOTP :
                </p>

                <div class="qr">
                    <img
                        src="https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=<?= urlencode($totpUri) ?>"
                        alt="QR code pour FreeOTP"
                    >
                </div>

                <p>
                    Si nécessaire, vous pouvez également saisir manuellement cette clé dans FreeOTP :
                </p>

                <div class="secret">
                    <?= htmlspecialchars((string)$user['totp_secret'], ENT_QUOTES, 'UTF-8') ?>
                </div>

                <form method="post">
                    <input type="hidden" name="action" value="enable">

                    <label for="code">
                        Code FreeOTP
                    </label>

                    <input
                        class="code-input"
                        type="text"
                        id="code"
                        name="code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        required
                    >

                    <br>

                    <button type="submit">
                        Activer la double authentification
                    </button>
                </form>

            <?php endif; ?>

        <?php endif; ?>

    </div>


    <!-- CHANGEMENT DE MOT DE PASSE -->

    <div class="card">

        <h2>🔑 Changer mon mot de passe</h2>

        <div class="description">
            Pour modifier votre mot de passe, vous devez confirmer votre mot de passe actuel ainsi que votre code FreeOTP.
        </div>

        <?php if ((int)$user['totp_enabled'] === 1): ?>

            <form method="post">

                <input type="hidden" name="action" value="change_password">

                <label for="current_password">
                    Mot de passe actuel
                </label>

                <input
                    type="password"
                    id="current_password"
                    name="current_password"
                    autocomplete="current-password"
                    required
                >

                <label for="new_password">
                    Nouveau mot de passe
                </label>

                <input
                    type="password"
                    id="new_password"
                    name="new_password"
                    autocomplete="new-password"
                    minlength="8"
                    required
                >

                <label for="confirm_password">
                    Confirmer le nouveau mot de passe
                </label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    autocomplete="new-password"
                    minlength="8"
                    required
                >

                <label for="totp_code">
                    Code FreeOTP
                </label>

                <input
                    class="code-input"
                    type="text"
                    id="totp_code"
                    name="totp_code"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autocomplete="one-time-code"
                    required
                >

                <br>

                <button type="submit">
                    Changer le mot de passe
                </button>

            </form>

        <?php else: ?>

            <p>
                Activez d'abord la double authentification pour pouvoir modifier votre mot de passe.
            </p>

        <?php endif; ?>

    </div>


    <a class="back" href="/">
        ← Retour à l'accueil
    </a>

</div>

</body>
</html>
