<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/helpers.php';

requireAuth();
$pdo  = getDB();
$mode = sanitize($_GET['mode'] ?? 'daily');

// Outbound = hanya yang ke customer
$custDest = "destination_location IN ('Customer Lokal', 'Customer Export')";

$labels = []; $inbound = []; $outbound = [];

switch ($mode) {

    // ─── DAILY: 7 hari dari tanggal dipilih ─────────────────
    case 'daily':
        $dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-d', strtotime('-6 days')));
        $dateTo   = date('Y-m-d', strtotime($dateFrom . ' +7 days'));

        $stmtIn = $pdo->prepare("
            SELECT DATE(created_at) AS p, SUM(quantity) AS total
            FROM transactions
            WHERE movement_type = 'inbound'
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY p ORDER BY p
        ");
        $stmtIn->execute([$dateFrom, $dateTo]);

        $stmtOut = $pdo->prepare("
            SELECT DATE(created_at) AS p, SUM(quantity) AS total
            FROM transactions
            WHERE movement_type = 'outbound' AND $custDest
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY p ORDER BY p
        ");
        $stmtOut->execute([$dateFrom, $dateTo]);

        $inMap = []; $outMap = [];
        foreach ($stmtIn->fetchAll()  as $r) $inMap[$r['p']]  = (int)$r['total'];
        foreach ($stmtOut->fetchAll() as $r) $outMap[$r['p']] = (int)$r['total'];

        for ($i = 0; $i <= 7; $i++) {
            $d          = date('Y-m-d', strtotime($dateFrom . " +$i days"));
            $labels[]   = date('d/m', strtotime($d));
            $inbound[]  = $inMap[$d]  ?? 0;
            $outbound[] = $outMap[$d] ?? 0;
        }
        break;

    // ─── WEEKLY: minggu-minggu dalam 2 bulan dipilih ────────
    case 'weekly':
        $monthFrom = sanitize($_GET['month_from'] ?? date('Y-m', strtotime('-1 month')));
        $monthTo   = sanitize($_GET['month_to']   ?? date('Y-m'));
        $dateFrom  = $monthFrom . '-01';
        $dateTo    = date('Y-m-t', strtotime($monthTo . '-01'));

        $stmtIn = $pdo->prepare("
            SELECT YEARWEEK(created_at, 1) AS p, SUM(quantity) AS total
            FROM transactions
            WHERE movement_type = 'inbound'
            AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY p ORDER BY p
        ");
        $stmtIn->execute([$dateFrom, $dateTo]);

        $stmtOut = $pdo->prepare("
            SELECT YEARWEEK(created_at, 1) AS p, SUM(quantity) AS total
            FROM transactions
            WHERE movement_type = 'outbound' AND $custDest
            AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY p ORDER BY p
        ");
        $stmtOut->execute([$dateFrom, $dateTo]);

        $inMap = []; $outMap = [];
        foreach ($stmtIn->fetchAll()  as $r) $inMap[$r['p']]  = (int)$r['total'];
        foreach ($stmtOut->fetchAll() as $r) $outMap[$r['p']] = (int)$r['total'];

        $startTs  = strtotime($dateFrom);
        $dow      = (int)date('N', $startTs); // 1=Senin
        $weekTs   = strtotime('-' . ($dow - 1) . ' days', $startTs); // mundur ke Senin
        $endTs    = strtotime($dateTo);

        while ($weekTs <= $endTs) {
            $ywKey      = (int)(date('o', $weekTs) . str_pad(date('W', $weekTs), 2, '0', STR_PAD_LEFT));
            $labels[]   = 'W' . date('W', $weekTs) . ' ' . date('d/m', $weekTs);
            $inbound[]  = $inMap[$ywKey]  ?? 0;
            $outbound[] = $outMap[$ywKey] ?? 0;
            $weekTs     = strtotime('+7 days', $weekTs);
        }
        break;

    // ─── MONTHLY: bulan ke bulan, max 12 bulan ───────────────
    case 'monthly':
        $monthFrom = sanitize($_GET['month_from'] ?? date('Y-m', strtotime('-11 months')));
        $monthTo   = sanitize($_GET['month_to']   ?? date('Y-m'));
        $dateFrom  = $monthFrom . '-01';
        $dateTo    = date('Y-m-t', strtotime($monthTo . '-01'));

        $stmtIn = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS p, SUM(quantity) AS total
            FROM transactions
            WHERE movement_type = 'inbound'
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY p ORDER BY p
        ");
        $stmtIn->execute([$dateFrom, $dateTo]);

        $stmtOut = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS p, SUM(quantity) AS total
            FROM transactions
            WHERE movement_type = 'outbound' AND $custDest
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY p ORDER BY p
        ");
        $stmtOut->execute([$dateFrom, $dateTo]);

        $inMap = []; $outMap = [];
        foreach ($stmtIn->fetchAll()  as $r) $inMap[$r['p']]  = (int)$r['total'];
        foreach ($stmtOut->fetchAll() as $r) $outMap[$r['p']] = (int)$r['total'];

        $cur = strtotime($monthFrom . '-01');
        $end = strtotime($monthTo   . '-01');
        while ($cur <= $end) {
            $key        = date('Y-m', $cur);
            $labels[]   = date('M y', $cur);
            $inbound[]  = $inMap[$key]  ?? 0;
            $outbound[] = $outMap[$key] ?? 0;
            $cur        = strtotime('+1 month', $cur);
        }
        break;

    // ─── YEARLY: max 10 tahun ────────────────────────────────
    case 'yearly':
        $yearFrom = (int)sanitize($_GET['year_from'] ?? (date('Y') - 4));
        $yearTo   = (int)sanitize($_GET['year_to']   ?? date('Y'));
        if ($yearTo - $yearFrom > 9) $yearFrom = $yearTo - 9;

        $stmtIn = $pdo->prepare("
            SELECT YEAR(created_at) AS p, SUM(quantity) AS total
            FROM transactions
            WHERE movement_type = 'inbound'
              AND YEAR(created_at) BETWEEN ? AND ?
            GROUP BY p ORDER BY p
        ");
        $stmtIn->execute([$yearFrom, $yearTo]);

        $stmtOut = $pdo->prepare("
            SELECT YEAR(created_at) AS p, SUM(quantity) AS total
            FROM transactions
            WHERE movement_type = 'outbound' AND $custDest
              AND YEAR(created_at) BETWEEN ? AND ?
            GROUP BY p ORDER BY p
        ");
        $stmtOut->execute([$yearFrom, $yearTo]);

        $inMap = []; $outMap = [];
        foreach ($stmtIn->fetchAll()  as $r) $inMap[$r['p']]  = (int)$r['total'];
        foreach ($stmtOut->fetchAll() as $r) $outMap[$r['p']] = (int)$r['total'];

        for ($y = $yearFrom; $y <= $yearTo; $y++) {
            $labels[]   = (string)$y;
            $inbound[]  = $inMap[$y]  ?? 0;
            $outbound[] = $outMap[$y] ?? 0;
        }
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Mode tidak valid'], 400);
}

jsonResponse([
    'success'  => true,
    'mode'     => $mode,
    'labels'   => $labels,
    'inbound'  => $inbound,
    'outbound' => $outbound,
]);