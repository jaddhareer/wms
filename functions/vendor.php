<?php
// ============================================================
// WMS LSN - Vendor (Gudang External) Helper Functions
// ============================================================
defined('BASE_PATH') or die('Direct access not allowed');

function isVendor(): bool {
    return ($_SESSION['role'] ?? '') === 'vendor';
}

function currentVendorCode(): ?string {
    return $_SESSION['vendor_code'] ?? null;
}

/**
 * Panggil di awal endpoint yang boleh diakses vendor.
 * Return vendor_code kalau role=vendor, string kosong kalau role lain (no scoping).
 */
function requireVendorScope(): string {
    if (!isVendor()) return '';
    $vc = currentVendorCode();
    if (!$vc) {
        jsonResponse(['success' => false, 'error' => 'Akun vendor tidak terhubung ke gudang manapun. Hubungi admin.'], 403);
    }
    return $vc;
}

function getVendorName(string $code): ?string {
    static $cache = [];
    if (array_key_exists($code, $cache)) return $cache[$code];
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT name FROM vendors WHERE code = ? AND is_active = 1");
    $stmt->execute([$code]);
    return $cache[$code] = $stmt->fetchColumn() ?: null;
}