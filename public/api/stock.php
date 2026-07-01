<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/helpers.php';

requireModule('stock');
$pdo  = getDB();
$mode = sanitize($_GET['mode'] ?? 'grouped'); // grouped | detail | export

// ─── Filter dasar ──────────────────────────────────────────
$fBatch   = sanitize($_GET['batch']         ?? '');
$fPallet  = sanitize($_GET['pallet_number'] ?? '');
$fBin     = sanitize($_GET['bin_location']  ?? '');
$fType    = sanitize($_GET['location_type'] ?? '');

// ─── Mode: detail per bin (untuk export & popup) ───────────
if ($mode === 'detail' || $mode === 'export') {
    $conditions = ['quantity > 0'];
    $params     = [];
    if ($fBatch)  { $conditions[] = 'batch LIKE ?';          $params[] = "%$fBatch%"; }
    if ($fPallet) { $conditions[] = 'pallet_number LIKE ?';  $params[] = "%$fPallet%"; }
    if ($fBin)    { $conditions[] = 'bin_location LIKE ?';   $params[] = "%$fBin%"; }
    if ($fType)   { $conditions[] = 'location_type LIKE ?';  $params[] = "%$fType%"; }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $stmt  = $pdo->prepare("
        SELECT batch, pallet_number, quantity, uom, quantity_kg, production_date,
               bin_location, location_type, updated_at
        FROM bin_locations $where
        ORDER BY batch, pallet_number
    ");
    $stmt->execute($params);
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ─── Mode: grouped by batch (tampilan utama) ───────────────
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

$conditions = ['quantity > 0'];
$params     = [];
if ($fBatch)  { $conditions[] = 'batch LIKE ?';         $params[] = "%$fBatch%"; }
if ($fType)   { $conditions[] = 'location_type LIKE ?'; $params[] = "%$fType%"; }
if ($fBin)    { $conditions[] = 'bin_location LIKE ?';  $params[] = "%$fBin%"; }
if ($fPallet) { $conditions[] = 'pallet_number LIKE ?'; $params[] = "%$fPallet%"; }

$where = 'WHERE ' . implode(' AND ', $conditions);

$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT batch) FROM bin_locations $where");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $limit);

$dataStmt = $pdo->prepare("
    SELECT
        batch,
        SUM(quantity)    AS total_qty,
        SUM(quantity_kg) AS total_kg,
        MAX(uom)         AS uom,
        MAX(production_date)  AS production_date,
        MAX(location_type) AS location_type,
        COUNT(*)         AS pallet_count,
        MAX(updated_at)  AS updated_at
    FROM bin_locations
    $where
    GROUP BY batch
    ORDER BY batch DESC
    LIMIT $limit OFFSET $offset
");
$dataStmt->execute($params);
$data = $dataStmt->fetchAll();

$sumStmt = $pdo->prepare("
    SELECT SUM(quantity) AS total_qty, SUM(quantity_kg) AS total_kg, COUNT(*) AS total_pallets
    FROM bin_locations $where
");
$sumStmt->execute($params);
$summary = $sumStmt->fetch();

jsonResponse([
    'success'    => true,
    'data'       => $data,
    'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'totalPages' => $totalPages],
    'summary'    => $summary,
]);