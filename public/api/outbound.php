<?php
// ============================================================
// WMS LSN - Outbound API
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireModule('outbound');
csrfCheck();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// ─── Input ────────────────────────────────────────────────
$destination   = sanitize(getInput('destination', ''));
$rows          = getInput('rows', []);
$remarks       = sanitize(getInput('remarks', ''));

// ─── Validation ───────────────────────────────────────────
if (!$destination) {
    jsonResponse(['success' => false, 'error' => 'Destination wajib diisi']);
}
if (empty($rows) || !is_array($rows)) {
    jsonResponse(['success' => false, 'error' => 'Minimal 1 baris harus diisi']);
}

$user    = currentUser();
$pdo     = getDB();
$results = [];

try {
    $pdo->beginTransaction();

    // Generate SATU transaction ID untuk seluruh submission ini
    $isWHExternal = ($destination === 'WH External');
    $txn_id = generateTxnId($isWHExternal ? 'moving' : 'outbound', $pdo);
    $movementType = $isWHExternal ? 'moving' : 'outbound';

    foreach ($rows as $row) {
        $batch         = sanitize($row['batch'] ?? '');
        $pallet_number = palletFormat($row['pallet'] ?? '01');
        $quantity      = (int)($row['quantity'] ?? 0);
        $bin_location  = sanitize($row['bin_location'] ?? '');

        if (!$batch || !$pallet_number || $quantity <= 0 || !$bin_location) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => "Data tidak lengkap pada baris: batch=$batch"]);
        }

        // Check stock availability
        $checkStmt = $pdo->prepare("
            SELECT quantity, quantity_kg, uom, product_type FROM bin_locations
            WHERE batch = ? AND pallet_number = ? AND bin_location = ?
            FOR UPDATE
        ");
        $checkStmt->execute([$batch, $pallet_number, $bin_location]);
        $binData   = $checkStmt->fetch();

        if (!$binData) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => "Bin tidak ditemukan: batch=$batch pallet=$pallet_number bin=$bin_location"]);
        }

        $uom       = $binData['uom'] ?: 'CTN';
        $current   = (int)$binData['quantity'];
        $productType = $binData['product_type'];

        if ($current < $quantity) {
            $pdo->rollBack();
            jsonResponse([
                'success' => false,
                'error'   => "Stok tidak mencukupi untuk batch=$batch pallet=$pallet_number di bin=$bin_location. Tersedia: $current, diminta: $quantity"
            ]);
        }

        $removeKg = calcKg($productType ?? '', $quantity);

        // Insert transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions
                (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                source_location, destination_location, bin_location, user_id, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'WH LSN', ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $txn_id, $movementType, $batch, $pallet_number, $quantity, $uom, $removeKg,
            $isWHExternal ? 'Jasco' : $destination,
            $bin_location, $user['id'], $remarks
        ]);

        // Decrement source bin
        $stmt2 = $pdo->prepare("
            UPDATE bin_locations
            SET quantity    = quantity - ?,
                quantity_kg = ROUND(quantity_kg - ?, 2),
                updated_at  = NOW()
            WHERE batch = ? AND pallet_number = ? AND bin_location = ?
        ");
        $stmt2->execute([$quantity, $removeKg, $batch, $pallet_number, $bin_location]);

        // Jika WH External: upsert ke bin Jasco
        if ($isWHExternal) {
            $incrStmt = $pdo->prepare("
                INSERT INTO bin_locations
                    (batch, pallet_number, quantity, uom, product_type, quantity_kg, bin_location, location_type, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Jasco', 'WH External', NOW())
                ON DUPLICATE KEY UPDATE
                    quantity    = quantity + ?,
                    quantity_kg = ROUND(quantity_kg + ?, 2),
                    updated_at  = NOW()
            ");
            $incrStmt->execute([$batch, $pallet_number, $quantity, $uom, $productType, $removeKg, $quantity, $removeKg]);
        }

        $results[] = ['batch' => $batch, 'pallet' => $pallet_number, 'qty' => $quantity];

    }

    $pdo->commit();
    jsonResponse([
        'success' => true,
        'message' => "Outbound berhasil | TXN: $txn_id | " . count($results) . ' batch',
        'txn_id'  => $txn_id,
        'results' => $results,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}
