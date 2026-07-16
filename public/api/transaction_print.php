<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/helpers.php';

requireAuth();
$pdo = getDB();

$txn_id = sanitize($_GET['transaction_id'] ?? '');
$stmt = $pdo->prepare("SELECT t.*, u.username FROM transactions t LEFT JOIN users u ON t.user_id=u.id WHERE t.transaction_id=? ORDER BY t.id ASC");
$stmt->execute([$txn_id]);
$rows = $stmt->fetchAll();
if (!$rows) { die('Transaksi tidak ditemukan'); }
$h = $rows[0];
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>TXN <?= htmlspecialchars($txn_id) ?></title>
        <style>
            body{font-family:Arial,sans-serif;font-size:13px;color:#111;padding:30px}
            h2{margin-bottom:4px}
            table{width:100%;border-collapse:collapse;margin-top:16px}
            th,td{border:1px solid #ccc;padding:6px 10px;text-align:left;font-size:12px}
            th{background:#f0f0f0}
            .info div{margin-bottom:3px}
            @media print{ .no-print{display:none} }
        </style>
    </head>
<body>
    <button class="no-print" onclick="window.print()">Print / Save as PDF</button>
    <h2>Picking List — <?= htmlspecialchars($txn_id) ?></h2>
    <div class="info">
    <div><strong>Tipe:</strong> <?= htmlspecialchars(ucfirst($h['movement_type'])) ?> <?= $h['is_cancelled'] ? '(DIBATALKAN/REVISI)' : '' ?></div>
    <div><strong>Oleh:</strong> <?= htmlspecialchars($h['username']) ?></div>
    <div><strong>Waktu:</strong> <?= htmlspecialchars($h['created_at']) ?></div>
    <div><strong>Remarks:</strong> <?= htmlspecialchars($h['remarks'] ?? '-') ?></div>
    </div>
    <table>
        <thead><tr><th>Batch</th><th>Pallet</th><th>Qty</th><th>UOM</th><th>Kg</th><th>Dari</th><th>Ke</th><th>Bin</th></tr></thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['batch']) ?></td>
                <td><?= htmlspecialchars($r['pallet_number'] ?? '-') ?></td>
                <td><?= $r['quantity'] ?></td>
                <td><?= htmlspecialchars($r['uom'] ?? '') ?></td>
                <td><?= number_format($r['quantity_kg'],2) ?></td>
                <td><?= htmlspecialchars($r['source_location'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['bin_location'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['destination_location'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body></html>