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
$allRows = $stmt->fetchAll();

if (!$allRows) jsonResponse(['success' => false, 'error' => 'Transaksi tidak ditemukan'], 404);

$header = $allRows[0];
$me     = currentUser();

$canCancel = in_array($header['movement_type'], ['inbound','outbound','moving'])
    && !$header['is_cancelled']
    && in_array($me['role'], ['admin','supervisor']);

// Status boleh-dibatalkan dihitung dari SEMUA baris transaksi ini,
// bukan cuma yang nanti tampil setelah difilter di bawah.
foreach ($allRows as $r) {
    $hasBin = !empty($r['source_bin']) || !empty($r['destination_bin']) || !empty($r['bin_location']);
    if (in_array($r['movement_type'], ['inbound','outbound']) && (!$hasBin || empty($r['pallet_number']))) {
        $canCancel = false;
        break;
    }
}

// Filter TAMPILAN saja (dibawa dari filter aktif di halaman Movements)
$fBatch  = sanitize($_GET['batch']       ?? '');
$fSource = sanitize($_GET['source']      ?? '');
$fDest   = sanitize($_GET['destination'] ?? '');

$rows = array_values(array_filter($allRows, function ($r) use ($fBatch, $fSource, $fDest) {
    if ($fBatch  && stripos($r['batch'] ?? '', $fBatch) === false)              return false;
    if ($fSource && stripos($r['source_location'] ?? '', $fSource) === false)   return false;
    if ($fDest   && stripos($r['destination_location'] ?? '', $fDest) === false) return false;
    return true;
}));

if (!$rows) $rows = $allRows; // kalau filter tidak cocok satu pun baris, tampilkan semua drpd kosong

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