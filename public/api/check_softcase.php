<?php
require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
$pdo = getDB();

$batch  = sanitize($_GET['batch']  ?? '');
$pallet = palletFormat($_GET['pallet'] ?? '01');

if ($batch && $pallet) {
    $stmt = $pdo->prepare("
        SELECT batch, pallet_number, qty_checked, uom_checked, qty_soft, uom_soft, checked_at
        FROM softcase
        WHERE batch = ? AND pallet_number = ?
    ");
    $stmt->execute([$batch, $pallet]);
    $row = $stmt->fetch();
    jsonResponse(['success' => true, 'exists' => (bool)$row, 'data' => $row ?: null]);
    exit;
}

jsonResponse(['success' => false, 'error' => 'Parameter tidak valid'], 400);
exit;