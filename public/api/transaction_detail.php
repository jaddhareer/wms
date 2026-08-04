<?php

require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
$pdo = getDB();

$txn_id = sanitize($_GET['transaction_id'] ?? '');
if (!$txn_id) jsonResponse(['success' => false, 'error' => 'transaction_id wajib diisi'], 400);

$stmt = $pdo->prepare("
    SELECT t.*, u.username, u.userid
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.transaction_id = ?
    ORDER BY t.id ASC
");
$stmt->execute([$txn_id]);
$rows = $stmt->fetchAll();

if (!$rows) jsonResponse(['success' => false, 'error' => 'Transaksi tidak ditemukan'], 404);

$header = $rows[0];
$me     = currentUser();

$canCancel = in_array($header['movement_type'], ['inbound','outbound','moving'])
    && !$header['is_cancelled']
    && in_array($me['role'], ['admin','supervisor']);

// Data lama tanpa bin_location/pallet_number tidak bisa dibatalkan otomatis
foreach ($rows as $r) {
    $hasBin = !empty($r['source_bin']) || !empty($r['destination_bin']) || !empty($r['bin_location']);
    if (in_array($r['movement_type'], ['inbound','outbound']) && (!$hasBin || empty($r['pallet_number']))) {
        $canCancel = false;
        break;
    }
}

jsonResponse([
    'success'    => true,
    'header'     => [
        'transaction_id' => $header['transaction_id'],
        'movement_type'  => $header['movement_type'],
        'username'       => $header['username'],
        'userid'         => $header['userid'],
        'created_at'     => $header['created_at'],
        'is_cancelled'   => (bool)$header['is_cancelled'],
    ],
    'rows'       => $rows,
    'can_cancel' => $canCancel,
]);