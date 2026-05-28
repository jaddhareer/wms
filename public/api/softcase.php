<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireModule('softcase');
csrfCheck();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$batch         = sanitize(getInput('batch', ''));
$pallet_number = palletFormat(getInput('pallet', '01'));
$qty_checked   = (int)getInput('qty_checked', 0);
$uom_checked   = sanitize(getInput('uom_checked', 'CTN'));
$qty_soft      = (int)getInput('qty_soft', 0);
$uom_soft      = sanitize(getInput('uom_soft', 'CTN'));
$source_bin    = 'STAGE';
$remarks       = sanitize(getInput('remarks', ''));

if (!$batch || !$pallet_number) {
    jsonResponse(['success' => false, 'error' => 'Batch dan nomor pallet wajib diisi']);
}

// Konversi PCS → CTN
$qty_checked_ctn = strtoupper($uom_checked) === 'PCS' ? (int)ceil($qty_checked / 20) : $qty_checked;
$qty_soft_ctn    = strtoupper($uom_soft)    === 'PCS' ? (int)ceil($qty_soft    / 20) : $qty_soft;
if ($qty_checked_ctn < 1 && $qty_checked > 0) $qty_checked_ctn = 1;

$SC_BIN = 'SC AREA'; // Destinasi softcase

$user = currentUser();
$pdo  = getDB();

try {
    $pdo->beginTransaction();

    // Lock & ambil data source bin
    $srcStmt = $pdo->prepare("
        SELECT quantity, quantity_kg, uom, location_type, product_type
        FROM bin_locations
        WHERE batch = ? AND pallet_number = ? AND bin_location = ?
        FOR UPDATE
    ");
    $srcStmt->execute([$batch, $pallet_number, $source_bin]);
    $src = $srcStmt->fetch();

    if (!$src) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => "Pallet $pallet_number batch $batch tidak ditemukan di bin $source_bin"]);
    }

    $decrementQty = max(1, $qty_checked_ctn);
    if ((int)$src['quantity'] < $decrementQty) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => "Stok tidak mencukupi. Tersedia: {$src['quantity']} CTN, diperlukan: $decrementQty CTN"]);
    }


    // Generate transaction ID
    $txn_id = generateTxnId('softcase', $pdo);

    $softKg = calcKg($src['product_type'] ?? '', $qty_soft);

    // Insert transaction
    $stmt = $pdo->prepare("
        INSERT INTO transactions
            (transaction_id, movement_type, batch, quantity, uom, quantity_kg,
             source_location, destination_location, user_id, remarks, created_at)
        VALUES (?, 'softcase', ?, ?, 'CTN', ?, ?, 'SC AREA', ?, ?, NOW())
    ");
    $stmt->execute([
        $txn_id, $batch, $qty_soft_ctn, $softKg, $source_bin, $user['id'],
        $remarks
    ]);

    // Decrement source bin
    $decrStmt = $pdo->prepare("
        UPDATE bin_locations
        SET quantity    = quantity - ?,
            quantity_kg = ROUND(quantity_kg - ?, 2),
            updated_at  = NOW()
        WHERE batch = ? AND pallet_number = ? AND bin_location = ?
    ");
    $decrStmt->execute([$qty_soft_ctn, $softKg, $batch, $pallet_number, $source_bin]);

    // Increment SC AREA bin
    $incrStmt = $pdo->prepare("
        INSERT INTO bin_locations
            (batch, pallet_number, quantity, uom, product_type, quantity_kg, bin_location, location_type, updated_at)
        VALUES (?, ?, ?, 'CTN', ?, ?, 'SC AREA', 'LSN Ambient', NOW())
        ON DUPLICATE KEY UPDATE
            quantity    = quantity + ?,
            quantity_kg = ROUND(quantity_kg + ?, 2),
            updated_at  = NOW()
    ");
    $incrStmt->execute([$batch, $pallet_number, $qty_soft_ctn, $src['product_type'], $softKg, $qty_soft_ctn, $softKg]);

    // Update tabel softcase
    $scStmt = $pdo->prepare("
        INSERT INTO softcase (batch, pallet_number, qty_checked, uom_checked, qty_soft, uom_soft, remarks, checked_at)
        VALUES (?, ?, ?, 'CTN', ?, 'CTN', ?, NOW())
        ON DUPLICATE KEY UPDATE
            qty_checked = qty_checked,
            uom_checked = 'CTN',
            qty_soft    = qty_soft + ?,
            uom_soft    = 'CTN',
            remarks     = ?
            checked_at  = NOW()
    ");
    $scStmt->execute([$batch, $pallet_number, $qty_checked_ctn, $qty_soft_ctn, $remarks, $qty_soft_ctn, $remarks]);

    $pdo->commit();
    jsonResponse([
        'success'  => true,
        'message'  => "Softcase check berhasil | TXN: $txn_id | $source_bin → SC AREA",
        'txn_id'   => $txn_id,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}