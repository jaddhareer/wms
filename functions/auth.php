<?php
// ============================================================
// WMS LSN - Authentication Functions
// ============================================================
defined('BASE_PATH') or die('Direct access not allowed');

function sessionStart(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    sessionStart();
    if (!isset($_SESSION['user_id'])) return false;
    // Session timeout check
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            session_destroy();
            return false;
        }
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'error' => 'Unauthorized', 'redirect' => '/'], 401);
    }
}

function authLogin(string $userid, string $password): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE userid = ? LIMIT 1");
    $stmt->execute([trim($userid)]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        sessionStart();
        session_regenerate_id(true);
        $_SESSION['user_id']      = $user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['userid']       = $user['userid'];
        $_SESSION['role']         = $user['role'];
        $_SESSION['last_activity']= time();
        return ['success' => true, 'user' => [
            'id'       => $user['id'],
            'username' => $user['username'],
            'userid'   => $user['userid'],
            'role'     => $user['role'],
        ]];
    }
    return ['success' => false, 'error' => 'UserID atau password salah'];
}

function authLogout(): void {
    sessionStart();
    $_SESSION = [];
    session_destroy();
}

function currentUser(): array {
    return [
        'id'       => $_SESSION['user_id']  ?? 0,
        'username' => $_SESSION['username'] ?? '',
        'userid'   => $_SESSION['userid']   ?? '',
        'role'     => $_SESSION['role']     ?? '',
    ];
}

function canAccess(string $module): bool {
    $role    = $_SESSION['role'] ?? '';
    $allowed = ROLE_ACCESS[$role] ?? [];
    return in_array($module, $allowed, true);
}

function requireModule(string $module): void {
    requireAuth();
    if (!canAccess($module)) {
        jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
    }
}
