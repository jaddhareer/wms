<?php
// ============================================================
// WMS LSN - Bootstrap
// ============================================================

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';

// Exception handler terpusat: setiap error yang TIDAK ditangkap try/catch
// Ini jaring pengaman lapis terakhir, bukan pengganti try/catch yang sudah ada, itu tetap perlu, karena butuh rollBack()

set_exception_handler(function (Throwable $e) {
    error_log('[Unhandled] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => 'Terjadi kesalahan pada server. Silakan coba lagi.']);
    exit;
});