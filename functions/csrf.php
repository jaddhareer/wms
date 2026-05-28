<?php
// ============================================================
// WMS LSN - CSRF Protection
// ============================================================
defined('BASE_PATH') or die('Direct access not allowed');

function csrfGenerate(): string {
    sessionStart();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfVerify(?string $token): bool {
    sessionStart();
    $stored = $_SESSION['csrf_token'] ?? '';
    if (empty($stored) || empty($token)) return false;
    return hash_equals($stored, $token);
}

function csrfCheck(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['_csrf']
          ?? $_GET['_csrf']
          ?? null;
    if (!csrfVerify($token)) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
    }
}
