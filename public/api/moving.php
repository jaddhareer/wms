<?php
// ============================================================
// WMS LSN - Moving (Bin to Bin) API
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireModule('moving');
csrfCheck();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// ─── Input ─────────────────────────────────────────────────
$source_bin   = sanitize(getInput('source_bin', ''));
$dest_bin     = sanitize(getInput('destination_bin', ''));
$batch        = sanitize(getInput('batch', ''));
$pallet       = palletFormat(getInput('pallet', '01'));
$rawQty       = (float)getInput('quantity', 0);
$uom          = sanitize(getInput('uom', 'CTN'));
$remarks      = sanitize(getInput('remarks', ''));

// ─── Validation ────────────────────────────────────────────
if (!$source_bin || !$dest_bin || !$batch || !$pallet || $rawQty <= 0) {
    jsonResponse(['success' => false, 'error' => 'Semua field wajib diisi dan quantity harus > 0']);
}
if ($source_bin === $dest_bin) {
    jsonResponse(['success' => false, 'error' => 'Source dan destination bin tidak boleh sama']);
}

$user = currentUser();
$pdo  = getDB();

try {
    $pdo->beginTransaction();

    // Lock and check source
    $srcStmt = $pdo->prepare("
        SELECT id, quantity, quantity_kg, uom, product_type, production_date, location_type FROM bin_locations
        WHERE batch = ? AND pallet_number = ? AND bin_location = ?
        FOR UPDATE
    ");
    $srcStmt->execute([$batch, $pallet, $source_bin]);

    $src = $srcStmt->fetch();
    if (!$src) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => "Stok tidak ditemukan: batch=$batch pallet=$pallet bin=$source_bin"]);
    }
    
    $converted = convertToCtnKg($src['product_type'] ?? '', $uom, $rawQty);
    $quantity  = $converted['ctn'];
    $moveKg    = $converted['kg'];

    if ((float)$src['quantity'] < $quantity) {
        $pdo->rollBack();
        jsonResponse([
            'success' => false,
            'error'   => "Stok tidak mencukupi. Tersedia: {$src['quantity']} {$src['uom']}, diminta: $quantity CTN"
        ]);
    }

    // Generate transaction ID
    $txn_id = generateTxnId('moving', $pdo);

    // Insert transaction
    $stmt = $pdo->prepare("
        INSERT INTO transactions
            (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
            source_location, destination_location, user_id, remarks, created_at)
        VALUES (?, 'moving', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $txn_id, $batch, $pallet, $quantity, $uom, $moveKg,
        $source_bin, $dest_bin, $user['id'], $remarks
    ]);

    // Decrement source bin
    $decrStmt = $pdo->prepare("
        UPDATE bin_locations
        SET quantity    = quantity - ?,
            quantity_kg = ROUND(quantity_kg - ?, 2),
            updated_at  = NOW()
        WHERE batch = ? AND pallet_number = ? AND bin_location = ?
    ");
    $decrStmt->execute([$quantity, $moveKg, $batch, $pallet, $source_bin]);

    // Increment (upsert) destination bin
    $incrStmt = $pdo->prepare("
        INSERT INTO bin_locations
            (batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, location_type, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            quantity    = quantity + ?,
            quantity_kg = ROUND(quantity_kg + ?, 2),
            updated_at  = NOW()
    ");
    $incrStmt->execute([
        $batch, $pallet, $quantity, $src['uom'] ?: $uom, $src['product_type'],
        $src['production_date'], $moveKg, $dest_bin, $src['location_type'] ?? null,
        $quantity, $moveKg
    ]);

    $pdo->commit();
    jsonResponse([
        'success' => true,
        'message' => "Moving berhasil | TXN: $txn_id | $batch pallet $pallet: $source_bin → $dest_bin ($quantity {$src['uom']})",
        'txn_id'  => $txn_id,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}
