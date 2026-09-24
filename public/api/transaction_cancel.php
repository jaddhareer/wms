<?php

require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
csrfCheck();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$me = currentUser();
if (!in_array($me['role'], ['admin','supervisor'])) {
    jsonResponse(['success' => false, 'error' => 'Anda tidak memiliki izin untuk membatalkan transaksi'], 403);
}

$txn_id = sanitize(getInput('transaction_id', ''));
$ids    = getInput('ids', []);
if (!$txn_id) jsonResponse(['success' => false, 'error' => 'transaction_id wajib diisi']);
if (!is_array($ids) || !$ids) jsonResponse(['success' => false, 'error' => 'Pilih minimal 1 baris yang ingin dibatalkan']);
$ids = array_values(array_unique(array_map('intval', $ids)));

$pdo = getDB();

try {
    $pdo->beginTransaction();

    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_id = ? AND id IN ($ph) FOR UPDATE");
    $stmt->execute(array_merge([$txn_id], $ids));
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Baris yang dipilih tidak ditemukan pada transaksi ini']);
    }
    if (array_filter($rows, fn($r) => $r['is_cancelled'])) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Salah satu baris yang dipilih sudah pernah dibatalkan']);
    }

    $originalType = $rows[0]['movement_type'];
    $reverseMap   = ['inbound' => 'outbound', 'outbound' => 'inbound', 'moving' => 'moving'];

    if (!isset($reverseMap[$originalType])) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Tipe transaksi ini tidak dapat dibatalkan']);
    }

    $reverseType = $reverseMap[$originalType];
    $newTxnId    = generateTxnId($reverseType, $pdo);

    foreach ($rows as $r) {
        $batch   = $r['batch'];
        $pallet  = $r['pallet_number'];
        $qty     = (float)$r['quantity'];
        $qtyKg   = (float)$r['quantity_kg'];
        $binMetaForConv = $pdo->prepare("SELECT product_type FROM bin_locations WHERE batch=? AND pallet_number=? LIMIT 1");
        $binMetaForConv->execute([$batch, $pallet]);
        $productTypeForConv = $binMetaForConv->fetchColumn() ?: '';
        $reconverted = convertToCtnKg($productTypeForConv, $r['uom'] ?? 'CTN', $qty);
        $qty = $reconverted['ctn'];

        if ($originalType === 'inbound') {
            if (!$pallet || !$r['destination_bin']) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Data lama tanpa bin/pallet, batch $batch tidak dapat dibatalkan otomatis"]);
            }
            $check = $pdo->prepare("SELECT quantity FROM bin_locations WHERE batch=? AND pallet_number=? AND bin_location=? FOR UPDATE");
            $check->execute([$batch, $pallet, $r['destination_bin']]);
            $current = (float)$check->fetchColumn();
            if ($current < $qty) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Stok batch $batch pallet $pallet sudah berkurang ({$current} < {$qty}), tidak dapat dibatalkan"]);
            }

            $upd = $pdo->prepare("UPDATE bin_locations 
                                  SET quantity=quantity-?, 
                                      quantity_kg=ROUND(quantity_kg-?,2), 
                                      updated_at=NOW() 
                                  WHERE batch=? AND pallet_number=? AND bin_location=?");
            $upd->execute([$qty, $qtyKg, $batch, $pallet, $r['destination_bin']]);

            $ins = $pdo->prepare("
                INSERT INTO transactions
                    (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                     source_location, source_bin, destination_location, destination_bin, vendor_code, user_id, remarks, created_at)
                VALUES (?, 'outbound', ?, ?, ?, ?, ?, ?, ?, 'CANCELLATION', NULL, ?, ?, ?, NOW())
            ");
            $ins->execute([$newTxnId, $batch, $pallet, $qty, $r['uom'], $qtyKg, $r['destination_location'], $r['destination_bin'], $r['vendor_code'], $me['id'], "Pembatalan TXN: $txn_id"]);

        } elseif ($originalType === 'outbound') {
            if (!$pallet || !$r['source_bin']) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Data lama tanpa bin/pallet, batch $batch tidak dapat dibatalkan otomatis"]);
            }

            $srcPallet = ($r['source_location'] === 'WH External') ? EXT_STAGING_PALLET : $pallet;

            $binMeta = $pdo->prepare("
                SELECT product_type, production_date, location_type
                FROM bin_locations
                WHERE batch = ? AND pallet_number = ? AND bin_location = ?
                FOR UPDATE
            ");
            $binMeta->execute([$batch, $srcPallet, $r['source_bin']]);
            $meta = $binMeta->fetch();

            $productType    = $meta['product_type']    ?? null;
            $productionDate = $meta['production_date'] ?? null;
            $locationType   = $meta['location_type']   ?? $r['source_location'];

            $upd = $pdo->prepare("
                INSERT INTO bin_locations
                    (batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, location_type, vendor_code, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity    = quantity + VALUES(quantity),
                    quantity_kg = ROUND(quantity_kg + VALUES(quantity_kg), 2),
                    updated_at  = NOW()
            ");
            $upd->execute([$batch, $srcPallet, $qty, $r['uom'], $productType, $productionDate, $qtyKg, $r['source_bin'], $locationType, $r['vendor_code']]);

            $ins = $pdo->prepare("
                INSERT INTO transactions
                    (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                     source_location, source_bin, destination_location, destination_bin, vendor_code, user_id, remarks, created_at)
                VALUES (?, 'inbound', ?, ?, ?, ?, ?, 'CANCELLATION', NULL, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([$newTxnId, $batch, $pallet, $qty, $r['uom'], $qtyKg, $r['source_location'], $r['source_bin'], $r['vendor_code'], $me['id'], "Pembatalan TXN: $txn_id"]);

        } elseif ($originalType === 'moving') {
            if (!$pallet) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Data lama tanpa pallet, batch $batch tidak dapat dibatalkan otomatis"]);
            }

            // Kalau salah satu sisi (source/destination) adalah WH External, bin di sisi itu
            // selalu pakai EXT_STAGING_PALLET (placeholder pallet vendor), bukan pallet_number transaksi asli.
            $destPallet = ($r['destination_location'] === 'WH External') ? EXT_STAGING_PALLET : $pallet;
            $srcPallet  = ($r['source_location'] === 'WH External') ? EXT_STAGING_PALLET : $pallet;

            $check = $pdo->prepare("SELECT quantity FROM bin_locations WHERE batch=? AND pallet_number=? AND bin_location=? FOR UPDATE");
            $check->execute([$batch, $destPallet, $r['destination_bin']]);
            $current = (float)$check->fetchColumn();
            if ($current < $qty) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Stok di {$r['destination_bin']} sudah berkurang, tidak dapat dibatalkan"]);
            }

            $decr = $pdo->prepare("
                UPDATE bin_locations
                SET quantity    = quantity - ?,
                    quantity_kg = ROUND(quantity_kg - ?, 2),
                    updated_at  = NOW()
                WHERE batch = ? AND pallet_number = ? AND bin_location = ?
            ");
            $decr->execute([$qty, $qtyKg, $batch, $destPallet, $r['destination_bin']]);

            $binMeta = $pdo->prepare("
                SELECT product_type, production_date
                FROM bin_locations
                WHERE batch = ? AND pallet_number = ?
                LIMIT 1
            ");
            $binMeta->execute([$batch, $pallet]);
            $meta           = $binMeta->fetch();
            $productType    = $meta['product_type']    ?? null;
            $productionDate = $meta['production_date'] ?? null;

            $incr = $pdo->prepare("
                INSERT INTO bin_locations
                    (batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, location_type, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity    = quantity + VALUES(quantity),
                    quantity_kg = ROUND(quantity_kg + VALUES(quantity_kg), 2),
                    updated_at  = NOW()
            ");
            $incr->execute([$batch, $srcPallet, $qty, $r['uom'], $productType, $productionDate, $qtyKg, $r['source_bin'], $r['source_location']]);

            $ins = $pdo->prepare("
                INSERT INTO transactions
                    (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                     source_location, source_bin, destination_location, destination_bin, vendor_code, user_id, remarks, created_at)
                VALUES (?, 'moving', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $newTxnId, $batch, $pallet, $qty, $r['uom'], $qtyKg,
                $r['destination_location'], $r['destination_bin'],
                $r['source_location'], $r['source_bin'],
                $r['vendor_code'], $me['id'], "Pembatalan TXN: $txn_id"
            ]);
        }
    }

    $pdo->prepare("UPDATE transactions SET is_cancelled = 1 WHERE transaction_id = ? AND id IN ($ph)")->execute(array_merge([$txn_id], $ids));

    $pdo->commit();
    jsonResponse(['success' => true, 'message' => count($rows) . " baris pada transaksi $txn_id berhasil dibatalkan | TXN Pembatalan: $newTxnId"]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[transaction_cancel.php] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}