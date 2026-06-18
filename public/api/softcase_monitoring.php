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
$fBatch     = sanitize($_GET['batch']         ?? '');
$fDateFrom  = sanitize($_GET['date_from']     ?? ''); // format: 2024-01-15
$fTimeFrom  = sanitize($_GET['time_from']     ?? ''); // format: 08:00
$fDateTo    = sanitize($_GET['date_to']       ?? '');
$fTimeTo    = sanitize($_GET['time_to']       ?? '');

$conditions = ['1=1'];
$params     = [];

if ($fBatch)  { $conditions[] = 's.batch LIKE ?'; $params[] = "%$fBatch%"; }

if ($fDateFrom) {
    $datetimeFrom = $fDateFrom . ' ' . ($fTimeFrom ?: '00:00') . ':00';
    $conditions[] = 's.checked_at >= ?';
    $params[]     = $datetimeFrom;
}
if ($fDateTo) {
    $datetimeTo = $fDateTo . ' ' . ($fTimeTo ?: '23:59') . ':59';
    $conditions[] = 's.checked_at <= ?';
    $params[]     = $datetimeTo;
}

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
        SUM(qty_checked) AS total_checked_ctn,
        SUM(qty_soft)    AS total_soft_ctn
    FROM softcase s $where
");
$sumStmt->execute($params);
$summary = $sumStmt->fetch();

$totalChecked = (int)($summary['total_checked_ctn'] ?? 0);
$totalSoft    = (int)($summary['total_soft_ctn'] ?? 0);
$summary['soft_percentage'] = $totalChecked > 0 ? round(($totalSoft / $totalChecked) * 100, 2) : 0;

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
