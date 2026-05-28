<?php
// ============================================================
// WMS LSN - Softcase Monitoring API
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireModule('softcase-monitoring');

$pdo = getDB();

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

// ─── Filters ───────────────────────────────────────────────
$fBatch  = sanitize($_GET['batch']         ?? '');
$fPallet = sanitize($_GET['pallet_number'] ?? '');
$fStatus = sanitize($_GET['status']        ?? ''); // 'checked' or 'unchecked'

$conditions = ['1=1'];
$params     = [];

if ($fBatch)  { $conditions[] = 's.batch LIKE ?';          $params[] = "%$fBatch%"; }
if ($fPallet) { $conditions[] = 's.pallet_number LIKE ?';  $params[] = "%$fPallet%"; }
if ($fStatus === 'checked')   { $conditions[] = 's.qty_checked > 0'; }
if ($fStatus === 'unchecked') { $conditions[] = 's.qty_checked = 0'; }

$where = 'WHERE ' . implode(' AND ', $conditions);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM softcase s $where");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $limit);

$dataStmt = $pdo->prepare("
    SELECT s.* FROM softcase s
    $where
    ORDER BY s.checked_at DESC
    LIMIT $limit OFFSET $offset
");
$dataStmt->execute($params);
$data = $dataStmt->fetchAll();

// Summary
$sumStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN qty_checked > 0 THEN 1 ELSE 0 END) AS checked,
        SUM(CASE WHEN qty_checked = 0 THEN 1 ELSE 0 END) AS unchecked,
        SUM(qty_soft) AS total_soft
    FROM softcase s $where
");
$sumStmt->execute($params);
$summary = $sumStmt->fetch();

jsonResponse([
    'success'    => true,
    'data'       => $data,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'total'      => $total,
        'totalPages' => $totalPages,
    ],
    'summary'    => $summary,
]);
