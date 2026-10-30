<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/*
 * Protection CSRF centralisée.
 *
 * Toutes les requêtes POST passant par auth.php doivent provenir
 * de la même origine que l'application.
 */
function requireSameOrigin(): void
{
    $requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($requestMethod !== 'POST') {
        return;
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        http_response_code(403);
        exit('Requête refusée.');
    }

    $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $scheme = $forwardedProto !== ''
        ? strtolower(trim(explode(',', $forwardedProto)[0]))
        : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    $expectedOrigin = $scheme . '://' . $host;

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($origin !== '') {
        if (!hash_equals($expectedOrigin, $origin)) {
            http_response_code(403);
            exit('Requête refusée.');
        }

        return;
    }

    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));

    if ($referer !== '') {
        $refererParts = parse_url($referer);

        if (
            !is_array($refererParts) ||
            !isset($refererParts['scheme'], $refererParts['host'])
        ) {
            http_response_code(403);
            exit('Requête refusée.');
        }

        $refererOrigin = strtolower(
            $refererParts['scheme'] . '://' . $refererParts['host']
            . (isset($refererParts['port']) ? ':' . $refererParts['port'] : '')
        );

        if (!hash_equals(strtolower($expectedOrigin), $refererOrigin)) {
            http_response_code(403);
            exit('Requête refusée.');
        }

        return;
    }

    http_response_code(403);
    exit('Requête refusée.');
}

requireSameOrigin();

function isAuthenticated(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function requireAuth(): int
{
    if (!isAuthenticated()) {
        header('Location: /login.php');
        exit;
    }

    return $_SESSION['user_id'];
}

function currentUserId(): ?int
{
    return isAuthenticated() ? $_SESSION['user_id'] : null;
}

function requireApiAuth(): int
{
    if (!isAuthenticated()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Authentification requise.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $_SESSION['user_id'];
}

function loginUser(int $userId): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;
    $_SESSION['authenticated_at'] = time();

    unset($_SESSION['pending_user_id']);
}

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}

function authClientIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    return filter_var($ip, FILTER_VALIDATE_IP) !== false
        ? $ip
        : 'unknown';
}

function authAccountKey(string $identifier): string
{
    return hash('sha256', strtolower(trim($identifier)));
}

function authUserKey(int $userId): string
{
    return 'user:' . $userId;
}

function authRateLimited(
    PDO $pdo,
    string $action,
    string $accountKey
): bool {
    $since = date('Y-m-d H:i:s', time() - 900);
    $ip = authClientIp();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM auth_attempts
        WHERE action = ?
          AND scope_type = 'ip'
          AND scope_key = ?
          AND attempted_at >= ?
    ");
    $stmt->execute([$action, $ip, $since]);

    if ((int)$stmt->fetchColumn() >= 10) {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM auth_attempts
        WHERE action = ?
          AND scope_type = 'account'
          AND scope_key = ?
          AND attempted_at >= ?
    ");
    $stmt->execute([$action, $accountKey, $since]);

    return (int)$stmt->fetchColumn() >= 5;
}

function recordAuthFailure(
    PDO $pdo,
    string $action,
    string $accountKey
): void {
    $ip = authClientIp();

    $stmt = $pdo->prepare("
        INSERT INTO auth_attempts
            (action, scope_type, scope_key, attempted_at)
        VALUES
            (?, 'ip', ?, NOW()),
            (?, 'account', ?, NOW())
    ");

    $stmt->execute([
        $action,
        $ip,
        $action,
        $accountKey
    ]);
}

function clearAuthAccountFailures(
    PDO $pdo,
    string $action,
    string $accountKey
): void {
    $stmt = $pdo->prepare("
        DELETE FROM auth_attempts
        WHERE action = ?
          AND scope_type = 'account'
          AND scope_key = ?
    ");

    $stmt->execute([
        $action,
        $accountKey
    ]);
}

function cleanupAuthAttempts(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $stmt = $pdo->prepare("
        DELETE FROM auth_attempts
        WHERE attempted_at < ?
    ");

    $stmt->execute([
        date('Y-m-d H:i:s', time() - 86400)
    ]);
}
