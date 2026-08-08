<?php
require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireModule('vendor-dashboard');
if (!isVendor()) {
    jsonResponse(['success' => false, 'error' => 'Halaman ini khusus akun vendor'], 403);
}

$vendor = requireVendorScope();
$pdo    = getDB();

$vendorInfo = $pdo->prepare("SELECT name, capacity FROM vendors WHERE code = ?");
$vendorInfo->execute([$vendor]);
$vinfo = $vendorInfo->fetch();

$occStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT bin_location) FROM bin_locations
    WHERE vendor_code = ? AND quantity > 0 AND bin_location != ?
");
$occStmt->execute([$vendor, $vinfo['name'] . ' STAGE']);
$occupiedBins = (int)$occStmt->fetchColumn();

$capacity  = (int)($vinfo['capacity'] ?? 0);
$occupancy = $capacity > 0 ? round(($occupiedBins / $capacity) * 100, 1) : 0;

$stockStmt = $pdo->prepare("
    SELECT batch, bin_location, SUM(quantity) AS quantity, MAX(uom) AS uom, SUM(quantity_kg) AS quantity_kg
    FROM bin_locations
    WHERE vendor_code = ? AND quantity > 0
    GROUP BY batch, bin_location
    ORDER BY updated_at DESC LIMIT 20
");
$stockStmt->execute([$vendor]);

$recentIn = $pdo->prepare("
    SELECT t.batch, SUM(t.quantity) AS quantity, t.uom, SUM(t.quantity_kg) AS quantity_kg,
           t.destination_bin, MIN(t.created_at) AS created_at
    FROM transactions t
    WHERE t.vendor_code = ? AND source_location != 'WH External' AND t.destination_location = 'WH External'
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY t.batch, t.uom, t.destination_bin
    ORDER BY MIN(t.created_at) DESC LIMIT 20
");
$recentIn->execute([$vendor]);

$recentOut = $pdo->prepare("
    SELECT t.batch, SUM(t.quantity) AS quantity, t.uom, SUM(t.quantity_kg) AS quantity_kg,
           t.source_bin, t.destination_location, MIN(t.created_at) AS created_at
    FROM transactions t
    WHERE t.vendor_code = ? AND t.source_location = 'WH External' AND t.destination_location != 'WH External'
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY t.batch, t.uom, t.source_bin, t.destination_location
    ORDER BY MIN(t.created_at) DESC LIMIT 20
");
$recentOut->execute([$vendor]);

jsonResponse([
    'success'    => true,
    'vendor'     => $vinfo,
    'stats'      => ['occupied' => $occupiedBins, 'capacity' => $capacity, 'occupancy' => $occupancy],
    'stock'      => $stockStmt->fetchAll(),
    'recent_in'  => $recentIn->fetchAll(),
    'recent_out' => $recentOut->fetchAll(),
]);