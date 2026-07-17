<?php
// ============================================================
// WMS LSN - User Management API
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireAuth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET = list users
if ($method === 'GET') {
    if (!canAccess('users')) {
        jsonResponse(['success' => false, 'error' => 'Tidak memiliki akses'], 403);
    }
    $stmt = $pdo->query("SELECT id, username, userid, role, created_at FROM users ORDER BY created_at ASC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

// POST/PUT/DELETE require CSRF
csrfCheck();
$action = getInput('action', '');

if ($action !== 'change_own_password' && !canAccess('users')) {
    jsonResponse(['success' => false, 'error' => 'Tidak memiliki akses'], 403);
}

switch ($action) {
    // ─── Create ────────────────────────────────────────────
    case 'create':
        $username = sanitize(getInput('username', ''));
        $userid   = sanitize(getInput('userid', ''));
        $role     = sanitize(getInput('role', ''));
        $password = getInput('password', '');

        if (!$username || !$userid || !$role || !$password) {
            jsonResponse(['success' => false, 'error' => 'Semua field wajib diisi']);
        }
        if (!in_array($role, ['admin','supervisor','staff','softchecker'])) {
            jsonResponse(['success' => false, 'error' => 'Role tidak valid']);
        }
        if (strlen($userid) > 10) {
            jsonResponse(['success' => false, 'error' => 'UserID maksimal 10 karakter']);
        }
        if (strlen($password) < 6) {
            jsonResponse(['success' => false, 'error' => 'Password minimal 6 karakter']);
        }

        // Check duplicate userid
        $chk = $pdo->prepare("SELECT id FROM users WHERE userid = ?");
        $chk->execute([$userid]);
        if ($chk->fetch()) jsonResponse(['success' => false, 'error' => 'UserID sudah digunakan']);

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("INSERT INTO users (username, userid, role, password) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $userid, $role, $hash]);
        jsonResponse(['success' => true, 'message' => "User '$username' berhasil dibuat", 'id' => $pdo->lastInsertId()]);

    // ─── Update ────────────────────────────────────────────
    case 'update':
        $id       = (int)getInput('id', 0);
        $username = sanitize(getInput('username', ''));
        $role     = sanitize(getInput('role', ''));

        if (!$id || !$username || !$role) {
            jsonResponse(['success' => false, 'error' => 'ID, username, dan role wajib diisi']);
        }
        if (!in_array($role, ['admin','supervisor','staff','softchecker'])) {
            jsonResponse(['success' => false, 'error' => 'Role tidak valid']);
        }

        // Prevent demoting own account
        $me = currentUser();
        if ($me['id'] == $id && $role !== $me['role']) {
            jsonResponse(['success' => false, 'error' => 'Tidak dapat mengubah role akun sendiri']);
        }

        $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
        $stmt->execute([$username, $role, $id]);
        jsonResponse(['success' => true, 'message' => 'User berhasil diupdate']);

    // ─── Reset password ────────────────────────────────────
    case 'reset_password':
        $id          = (int)getInput('id', 0);
        $new_password= getInput('new_password', '');

        if (!$id || !$new_password) {
            jsonResponse(['success' => false, 'error' => 'ID dan password baru wajib diisi']);
        }
        if (strlen($new_password) < 6) {
            jsonResponse(['success' => false, 'error' => 'Password minimal 6 karakter']);
        }

        $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hash, $id]);
        jsonResponse(['success' => true, 'message' => 'Password berhasil direset']);

    // ─── Delete ────────────────────────────────────────────
    case 'delete':
        $id = (int)getInput('id', 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID tidak valid']);

        $me = currentUser();
        if ($me['id'] == $id) jsonResponse(['success' => false, 'error' => 'Tidak dapat menghapus akun sendiri']);

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'User berhasil dihapus']);

    // ─── Ganti password sendiri ────────────────────────────────
    case 'change_own_password':
        $me          = currentUser();
        $old_password= getInput('old_password', '');
        $new_password= getInput('new_password', '');

        if (!$old_password || !$new_password) {
            jsonResponse(['success' => false, 'error' => 'Semua field wajib diisi']);
        }
        if (strlen($new_password) < 6) {
            jsonResponse(['success' => false, 'error' => 'Password baru minimal 6 karakter']);
        }

        // Verifikasi password lama
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$me['id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($old_password, $user['password'])) {
            jsonResponse(['success' => false, 'error' => 'Password lama tidak sesuai']);
        }

        $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hash, $me['id']]);
        jsonResponse(['success' => true, 'message' => 'Password berhasil diubah']);

    default:
        jsonResponse(['success' => false, 'error' => 'Action tidak dikenali'], 400);
}
