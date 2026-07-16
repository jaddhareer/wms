<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/helpers.php';

requireAuth();
$pdo = getDB();

$bin    = sanitize($_GET['bin']    ?? '');
$batch  = sanitize($_GET['batch']  ?? '');

if ($bin) {
    // Feature 5: autofill moving dari source bin
    $stmt = $pdo->prepare("
        SELECT batch, pallet_number, quantity, uom, quantity_kg, bin_location, location_type, production_date
        FROM bin_locations
        WHERE bin_location = ? AND quantity > 0
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$bin]);
    $results = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $results]);
    exit;
}

if ($batch) {
    // Feature 7: autofill outbound dari batch + pallet
    $stmt = $pdo->prepare("
        SELECT batch, pallet_number, quantity, uom, quantity_kg, bin_location, location_type, production_date
        FROM bin_locations
        WHERE batch = ? AND quantity > 0
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$batch]);
    $results = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $results]);
    exit;
}

jsonResponse(['success' => false, 'error' => 'Parameter tidak valid'], 400);
exit;