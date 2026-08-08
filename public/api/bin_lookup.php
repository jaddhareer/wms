<?php
require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
$vendor = requireVendorScope();
$pdo = getDB();

$bin    = sanitize($_GET['bin']    ?? '');
$batch  = sanitize($_GET['batch']  ?? '');

if ($bin) {
    $sql = "SELECT batch, pallet_number, quantity, uom, quantity_kg, bin_location, location_type, production_date
            FROM bin_locations WHERE bin_location = ? AND quantity > 0";
    $params = [$bin];
    if ($vendor) { $sql .= " AND vendor_code = ?"; $params[] = $vendor; }
    $sql .= " ORDER BY updated_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

if ($batch) {
    $sql = "SELECT batch, pallet_number, quantity, uom, quantity_kg, bin_location, location_type, production_date
            FROM bin_locations WHERE batch = ? AND quantity > 0";
    $params = [$batch];
    if ($vendor) { $sql .= " AND vendor_code = ?"; $params[] = $vendor; }
    $sql .= " ORDER BY updated_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

jsonResponse(['success' => false, 'error' => 'Parameter tidak valid'], 400);
exit;