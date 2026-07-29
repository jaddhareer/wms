<?php

require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
$pdo = getDB();

$batch  = sanitize($_GET['batch']  ?? '');

if ($batch) {
    // Feature 5: autofill moving dari source bin
    $stmt = $pdo->prepare("
        SELECT batch, pallet_number, quantity, uom, quantity_kg, bin_location, location_type, product_type
        FROM bin_locations
        WHERE batch = ? AND bin_location = 'Jasco' AND quantity > 0
    ");
    $stmt->execute([$batch]);
    $results = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $results]);
    exit;
}

jsonResponse(['success' => false, 'error' => 'Parameter tidak valid'], 400);
exit;