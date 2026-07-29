<?php
require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
$pdo = getDB();

$batch  = sanitize($_GET['batch']  ?? '');
$pallet = palletFormat($_GET['pallet'] ?? '01');

if ($batch && $pallet) {
    // Feature 5: autofill moving dari source bin
    $stmt = $pdo->prepare("
        SELECT batch, pallet_number, quantity, uom, quantity_kg, bin_location, location_type
        FROM bin_locations
        WHERE batch = ? AND pallet_number = ? AND quantity > 0
    ");
    $stmt->execute([$batch, $pallet]);
    $results = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $results]);
    exit;
}

jsonResponse(['success' => false, 'error' => 'Parameter tidak valid'], 400);
exit;