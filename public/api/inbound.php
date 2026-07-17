<?php
// ============================================================
// WMS LSN - Inbound API
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireModule('inbound');
csrfCheck();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// ─── Input ────────────────────────────────────────────────
$inbound_from      = sanitize(getInput('inbound_from', ''));
$storage_location  = sanitize(getInput('storage_location', ''));
$product_type      = sanitize(getInput('product_type', ''));
$uom               = sanitize(getInput('uom', 'CTN'));
$rows              = getInput('rows', []); // array of {batch, pallet, quantity, bin_location}
$remarks           = sanitize(getInput('remarks', ''));

// ─── Validation ───────────────────────────────────────────
if (!$inbound_from || !$storage_location) {
    jsonResponse(['success' => false, 'error' => 'Inbound from, storage location, dan product type wajib diisi']);
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
    $isFromWHExternal = ($inbound_from === 'WH External');
    $txn_id           = generateTxnId($isFromWHExternal ? 'moving' : 'inbound', $pdo);
    $movementType     = $isFromWHExternal ? 'moving' : 'inbound';

    foreach ($rows as $row) {
        $batch           = sanitize($row['batch'] ?? '');
        $pallet_number   = palletFormat($row['pallet'] ?? '01');
        $input_qty       = (float)($row['quantity'] ?? 0);
        $row_uom         = sanitize($row['uom'] ?? $uom);
        $bin_location    = sanitize($row['bin_location'] ?? '');
        $production_date = sanitize($row['production_date'] ?? '');
        $ptype           = sanitize($row['ptype'] ?? '');

        if (!$batch || !$pallet_number || $input_qty <= 0 || !$bin_location) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => "Data tidak lengkap pada baris: batch=$batch"]);
        }
        if (!$production_date) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => "Production date wajib diisi untuk batch=$batch"]);
        }
        if (!array_key_exists($ptype, PRODUCT_KG_MAP)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => "Product type tidak valid pada baris batch=$batch"]);
        }

        $converted   = convertToCtnKg($ptype, $row_uom, $input_qty);
        $quantity    = $converted['ctn'];   // disimpan ke bin_locations (boleh desimal)
        $quantity_kg = $converted['kg'];

        // Insert transaction — sesuai input asli user
        $stmt = $pdo->prepare("
            INSERT INTO transactions
                (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                source_location, destination_location, bin_location, user_id, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'WH LSN', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $txn_id, $movementType, $batch, $pallet_number, $input_qty, $row_uom, $quantity_kg,
            $isFromWHExternal ? 'Jasco' : $inbound_from, $bin_location, $user['id'], $remarks
        ]);

        // Upsert bin_locations — pakai hasil konversi CTN
        $stmt2 = $pdo->prepare("
            INSERT INTO bin_locations
                (batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, location_type, updated_at)
            VALUES (?, ?, ?, 'CTN', ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                quantity     = quantity + VALUES(quantity),
                quantity_kg  = quantity_kg + VALUES(quantity_kg),
                updated_at   = NOW()
        ");
        $stmt2->execute([
            $batch, $pallet_number, $quantity, $ptype, $production_date, $quantity_kg, $bin_location, $storage_location
        ]);

        // Jika dari WH External: decrement bin Jasco (logic sama, ganti $quantity dengan hasil konversi)
        if ($isFromWHExternal) {
            $checkJasco = $pdo->prepare("
                SELECT quantity FROM bin_locations
                WHERE batch = ? AND pallet_number = ? AND bin_location = 'Jasco'
                FOR UPDATE
            ");
            $checkJasco->execute([$batch, $pallet_number]);
            $jascoQty = (float)$checkJasco->fetchColumn();

            if ($jascoQty < $quantity) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Stok Jasco tidak mencukupi untuk batch=$batch pallet=$pallet_number. Tersedia: $jascoQty, diminta: $quantity"]);
            }

            $decrJasco = $pdo->prepare("
                UPDATE bin_locations
                SET quantity    = quantity - ?,
                    quantity_kg = ROUND(quantity_kg - ?, 2),
                    updated_at  = NOW()
                WHERE batch = ? AND pallet_number = ? AND bin_location = 'Jasco'
            ");
            $decrJasco->execute([$quantity, $quantity_kg, $batch, $pallet_number]);
        }

        $results[] = ['batch' => $batch, 'pallet' => $pallet_number, 'qty' => $input_qty, 'uom' => $row_uom];
    }

    $pdo->commit();
    jsonResponse([
        'success'  => true,
        'message'  => "Inbound berhasil | TXN: $txn_id | " . count($results) . ' batch',
        'txn_id'   => $txn_id,
        'results'  => $results,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[inbound.php] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
