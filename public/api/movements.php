<?php
// ============================================================
// WMS LSN - Movements API
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireModule('movements');

$pdo = getDB();

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

// ─── Filters ───────────────────────────────────────────────
$fType     = sanitize($_GET['movement_type'] ?? '');
$fBatch    = sanitize($_GET['batch']         ?? '');
$fTxn      = sanitize($_GET['transaction_id']?? '');
$fSource   = sanitize($_GET['source']        ?? '');
$fDest     = sanitize($_GET['destination']   ?? '');
$fDateFrom = sanitize($_GET['date_from']     ?? '');
$fDateTo   = sanitize($_GET['date_to']       ?? '');

$conditions = ['1=1'];
$params     = [];

if ($fType)     { $conditions[] = 't.movement_type = ?';         $params[] = $fType; }
if ($fBatch)    { $conditions[] = 't.batch LIKE ?';              $params[] = "%$fBatch%"; }
if ($fTxn)      { $conditions[] = 't.transaction_id LIKE ?';     $params[] = "%$fTxn%"; }
if ($fSource)   { $conditions[] = 't.source_location LIKE ?';    $params[] = "%$fSource%"; }
if ($fDest)     { $conditions[] = 't.destination_location LIKE ?'; $params[] = "%$fDest%"; }
if ($fDateFrom) { $conditions[] = 'DATE(t.created_at) >= ?';     $params[] = $fDateFrom; }
if ($fDateTo)   { $conditions[] = 'DATE(t.created_at) <= ?';     $params[] = $fDateTo; }

$where = 'WHERE ' . implode(' AND ', $conditions);

$countStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT CONCAT(t.transaction_id, '|', IFNULL(t.batch,'')))
    FROM transactions t $where
");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $limit);

$dataStmt = $pdo->prepare("
    SELECT
        t.transaction_id,
        t.movement_type,
        t.batch,
        SUM(t.quantity)    AS quantity,
        t.uom,
        SUM(t.quantity_kg) AS quantity_kg,
        t.source_location,
        t.destination_location,
        t.remarks,
        MIN(t.created_at)  AS created_at,
        u.username,
        u.userid
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    $where
    GROUP BY t.transaction_id, t.batch, t.movement_type, t.uom,
             t.source_location, t.destination_location, t.remarks,
             u.username, u.userid
    ORDER BY MIN(t.created_at) DESC
    LIMIT $limit OFFSET $offset
");
$dataStmt->execute($params);
$data = $dataStmt->fetchAll();

$hasFilters = $fType || $fBatch || $fTxn || $fSource || $fDest || $fDateFrom || $fDateTo;

jsonResponse([
    'success'    => true,
    'data'       => $data,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'total'      => $total,
        'totalPages' => $totalPages,
    ],
    'hasFilters' => $hasFilters,
    'filters'    => compact('fType','fBatch','fTxn','fSource','fDest','fDateFrom','fDateTo'),
]);
