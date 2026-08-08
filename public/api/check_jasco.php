<?php
require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
$pdo = getDB();

$batch = sanitize($_GET['batch'] ?? '');

if ($batch) {
    $stmt = $pdo->prepare("
        SELECT bl.batch, bl.pallet_number, bl.quantity, bl.uom, bl.quantity_kg,
               bl.bin_location, bl.location_type, bl.product_type, bl.vendor_code, v.name AS vendor_name
        FROM bin_locations bl
        JOIN vendors v ON v.code = bl.vendor_code
        WHERE bl.batch = ? AND bl.location_type = 'WH External' AND bl.quantity > 0
        ORDER BY bl.bin_location
    ");
    $stmt->execute([$batch]);
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}
jsonResponse(['success' => false, 'error' => 'Parameter tidak valid'], 400);
exit;