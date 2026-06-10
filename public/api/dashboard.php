<?php
// ============================================================
// WMS LSN - Dashboard API
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireAuth();
$pdo = getDB();

// ─── Pallet stats split by location_type ────────────────────
$capAmbient    = 1305;
$capChiller    = 81;

$palletAmbient = (int)$pdo->query("
    SELECT
    (SELECT COUNT(DISTINCT bin_location) FROM bin_locations WHERE quantity > 0 AND location_type = 'LSN Ambient') + 
    (SELECT COUNT(*) FROM bin_locations WHERE quantity > 0 AND location_type = 'LSN Ambient' AND bin_location = 'STAGE') - 3;
")->fetchColumn();

$palletChiller = (int)$pdo->query("
    SELECT
    (SELECT COUNT(DISTINCT bin_location) FROM bin_locations WHERE quantity > 0 AND location_type = 'LSN Chiller') + 
    (SELECT COUNT(*) FROM bin_locations WHERE quantity > 0 AND location_type = 'LSN Chiller' AND bin_location = 'STAGE')
")->fetchColumn();

$occAmbient = $capAmbient > 0 ? round(($palletAmbient / $capAmbient) * 100, 1) : 0;
$occChiller = $capChiller > 0 ? round(($palletChiller / $capChiller) * 100, 1) : 0;

// ─── Recent inbound (3 days) ────────────────────────────────
$recentIn = $pdo->query("
    SELECT t.batch,
           SUM(t.quantity) AS quantity, t.uom,
           SUM(t.quantity_kg) AS quantity_kg,
           t.source_location, t.destination_location,
           MIN(t.created_at) AS created_at, u.username
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.movement_type = 'inbound'
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
    GROUP BY t.batch, t.uom,
             t.source_location, t.destination_location, u.username
    ORDER BY MIN(t.created_at) DESC
    LIMIT 20
")->fetchAll();

// ─── Recent outbound (3 days) ───────────────────────────────
$recentOut = $pdo->query("
    SELECT t.batch,
           SUM(t.quantity) AS quantity, t.uom,
           SUM(t.quantity_kg) AS quantity_kg,
           t.source_location, t.destination_location,
           MIN(t.created_at) AS created_at, u.username
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.movement_type = 'outbound'
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
    GROUP BY t.batch, t.uom,
             t.source_location, t.destination_location, u.username
    ORDER BY MIN(t.created_at) DESC
    LIMIT 20
")->fetchAll();

// ─── Softcase summary ───────────────────────────────────────
$softcase = $pdo->query("
    SELECT s.batch,
           SUM(s.qty_checked) AS qty_checked, s.uom_checked,
           COUNT(*)           AS total_pallet,
           SUM(s.qty_soft)    AS qty_soft,    s.uom_soft,
           MAX(s.checked_at)  AS checked_at
    FROM softcase s
    GROUP BY s.batch, s.uom_checked, s.uom_soft
    ORDER BY MAX(s.checked_at) DESC
    LIMIT 15
")->fetchAll();

// ─── Today's counters ───────────────────────────────────────
$todayStmt = $pdo->query("
    SELECT movement_type, COUNT(*) AS cnt, SUM(quantity) AS total_qty
    FROM transactions
    WHERE DATE(created_at) = CURDATE()
    GROUP BY movement_type
");
$todayRaw  = $todayStmt->fetchAll();
$today     = [];
foreach ($todayRaw as $r) {
    $today[$r['movement_type']] = ['count' => $r['cnt'], 'qty' => $r['total_qty']];
}

jsonResponse([
    'success'   => true,
    'stats'     => [
        'ambient'     => ['count' => $palletAmbient, 'capacity' => $capAmbient, 'occupancy' => $occAmbient],
        'chiller'     => ['count' => $palletChiller, 'capacity' => $capChiller, 'occupancy' => $occChiller],
    ],
    'recent_in'  => $recentIn,
    'recent_out' => $recentOut,
    'softcase'   => $softcase,
    'today'      => $today,
]);
