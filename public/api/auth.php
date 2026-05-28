<?php
// ============================================================
// WMS LSN - Auth API
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

sessionStart();
$method = $_SERVER['REQUEST_METHOD'];
$action = getInput('action', '');

header('Content-Type: application/json');

switch ($action) {
    // ─── Login ───────────────────────────────────────────
    case 'login':
        if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
        $userid   = trim(getInput('userid', ''));
        $password = getInput('password', '');
        if (!$userid || !$password) {
            jsonResponse(['success' => false, 'error' => 'UserID dan password wajib diisi']);
        }
        $result = authLogin($userid, $password);
        // Regenerate CSRF after login
        if ($result['success']) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $result['csrf'] = $_SESSION['csrf_token'];
        }
        jsonResponse($result);

    // ─── Logout ──────────────────────────────────────────
    case 'logout':
        authLogout();
        jsonResponse(['success' => true]);

    // ─── Check session ───────────────────────────────────
    case 'check':
        if (isLoggedIn()) {
            $user = currentUser();
            jsonResponse(['success' => true, 'user' => $user, 'csrf' => csrfGenerate()]);
        }
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
