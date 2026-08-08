<?php
// ============================================================
// WMS LSN - Outbound API
// ============================================================
require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireModule('outbound');
csrfCheck();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user   = currentUser();
$vendor = requireVendorScope();

// ─── Input ────────────────────────────────────────────────
$destination   = sanitize(getInput('destination', ''));
$ext_warehouse = sanitize(getInput('ext_warehouse', '')); // dipilih staff LSN saat destination='WH External'
$rows          = getInput('rows', []);
$remarks       = sanitize(getInput('remarks', ''));

// ─── Validation ───────────────────────────────────────────
if (!$destination) {
    jsonResponse(['success' => false, 'error' => 'Destination wajib diisi']);
}
if (empty($rows) || !is_array($rows)) {
    jsonResponse(['success' => false, 'error' => 'Minimal 1 baris harus diisi']);
}

$isWHExternal = ($destination === 'WH External');

// Guard: vendor gak boleh kirim ke WH External (mereka SUDAH di WH External)
if ($isWHExternal && $vendor) {
    jsonResponse(['success' => false, 'error' => 'Tidak bisa outbound ke WH External dari akun vendor'], 403);
}

$pdo = getDB();
$targetVendorCode = null;
$targetVendorName = null;

if ($isWHExternal) {
    if (!$ext_warehouse) jsonResponse(['success' => false, 'error' => 'Pilih gudang eksternal tujuan']);
    $vchk = $pdo->prepare("SELECT code, name FROM vendors WHERE code = ? AND is_active = 1");
    $vchk->execute([$ext_warehouse]);
    $vrow = $vchk->fetch();
    if (!$vrow) jsonResponse(['success' => false, 'error' => 'Gudang eksternal tidak valid']);
    $targetVendorCode = $vrow['code'];
    $targetVendorName = $vrow['name'];
}

$results = [];

try {
    $pdo->beginTransaction();

    $txn_id       = generateTxnId($isWHExternal ? 'moving' : 'outbound', $pdo);
    $movementType = $isWHExternal ? 'moving' : 'outbound';

    foreach ($rows as $row) {
        $batch         = sanitize($row['batch'] ?? '');
        $pallet_number = $vendor ? EXT_STAGING_PALLET : ($row['pallet'] ?? '');
        $quantity      = (float)($row['quantity'] ?? 0);
        $bin_location  = sanitize($row['bin_location'] ?? '');

        if ($vendor) {
            $vendorName = getVendorName($vendor);
            if (stripos($bin_location, $vendorName) !== 0) {
                $bin_location = "$vendorName $bin_location";
            }
        }

        if (!$batch || !$pallet_number || $quantity <= 0 || !$bin_location) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => "Data tidak lengkap pada baris: batch=$batch"]);
        }

        // Check stock availability
        $checkStmt = $pdo->prepare("
            SELECT quantity, quantity_kg, uom, product_type, production_date, location_type, vendor_code
            FROM bin_locations
            WHERE batch = ? AND pallet_number = ? AND bin_location = ?
            FOR UPDATE
        ");
        $checkStmt->execute([$batch, $pallet_number, $bin_location]);
        $binData = $checkStmt->fetch();

        if (!$binData) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => "Bin tidak ditemukan: batch=$batch pallet=$pallet_number bin=$bin_location"]);
        }
        if ($vendor && $binData['vendor_code'] !== $vendor) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Bin ini bukan milik gudang Anda'], 403);
        }

        $row_uom     = sanitize($row['uom'] ?? 'CTN');
        $input_qty   = (float)($row['quantity'] ?? 0);
        $converted   = convertToCtnKg($binData['product_type'] ?? '', $row_uom, $input_qty);
        $removeQty   = $converted['ctn'];
        $removeKg    = $converted['kg'];
        $productType = $binData['product_type'];
        $uom         = $binData['uom'] ?: 'CTN';
        $pdate       = $binData['production_date'];
        $current     = (float)$binData['quantity'];
        $source      = $binData['location_type'];

        if ($current < $removeQty) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => "Stok tidak mencukupi untuk batch=$batch pallet=$pallet_number di bin=$bin_location. Tersedia: $current CTN, diminta: $removeQty CTN"]);
        }

        $destBinName   = $isWHExternal ? "$targetVendorName STAGE" : null;
        $txnVendorCode = $isWHExternal ? $targetVendorCode : $binData['vendor_code'];

        // Insert transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions
                (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                source_location, source_bin, destination_location, destination_bin, vendor_code, user_id, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $txn_id, $movementType, $batch, $pallet_number, $removeQty, $row_uom, $removeKg,
            $source, $bin_location,
            $isWHExternal ? 'WH External' : $destination, $destBinName,
            $txnVendorCode, $user['id'], $remarks
        ]);

        $stmt2 = $pdo->prepare("
            UPDATE bin_locations
            SET quantity    = quantity - ?,
                quantity_kg = ROUND(quantity_kg - ?, 2),
                updated_at  = NOW()
            WHERE batch = ? AND pallet_number = ? AND bin_location = ?
        ");
        $stmt2->execute([$removeQty, $removeKg, $batch, $pallet_number, $bin_location]);

        // Jika WH External: upsert ke bin STAGE vendor tujuan
        if ($isWHExternal) {
            $incrStmt = $pdo->prepare("
                INSERT INTO bin_locations
                    (batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, location_type, vendor_code, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'WH External', ?, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity    = quantity + ?,
                    quantity_kg = ROUND(quantity_kg + ?, 2),
                    updated_at  = NOW()
            ");
            $incrStmt->execute([
                $batch, EXT_STAGING_PALLET, $removeQty, $uom, $productType, $pdate, $removeKg,
                $destBinName, $targetVendorCode, $removeQty, $removeKg
            ]);
        }

        $results[] = ['batch' => $batch, 'pallet' => $pallet_number, 'qty' => $removeQty];
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
    error_log('[outbound.php] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}