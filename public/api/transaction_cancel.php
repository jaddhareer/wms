<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

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
if (!$txn_id) jsonResponse(['success' => false, 'error' => 'transaction_id wajib diisi']);

$pdo = getDB();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_id = ? FOR UPDATE");
    $stmt->execute([$txn_id]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Transaksi tidak ditemukan']);
    }
    if ($rows[0]['is_cancelled']) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Transaksi sudah pernah dibatalkan']);
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
            if (!$pallet || !$r['bin_location']) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Data lama tanpa bin/pallet, batch $batch tidak dapat dibatalkan otomatis"]);
            }
            $check = $pdo->prepare("SELECT quantity FROM bin_locations WHERE batch=? AND pallet_number=? AND bin_location=? FOR UPDATE");
            $check->execute([$batch, $pallet, $r['bin_location']]);
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
            $upd->execute([$qty, $qtyKg, $batch, $pallet, $r['bin_location']]);

            $ins = $pdo->prepare("
                INSERT INTO transactions
                    (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                     source_location, destination_location, bin_location, user_id, remarks, created_at)
                VALUES (?, 'outbound', ?, ?, ?, ?, ?, 'WH LSN', 'CANCELLATION', ?, ?, ?, NOW())
            ");
            $ins->execute([$newTxnId, $batch, $pallet, $qty, $r['uom'], $qtyKg, $r['bin_location'], $me['id'], "Pembatalan TXN: $txn_id"]);

        } elseif ($originalType === 'outbound') {
            if (!$pallet || !$r['bin_location']) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Data lama tanpa bin/pallet, batch $batch tidak dapat dibatalkan otomatis"]);
            }
            
            $binMeta = $pdo->prepare("
                SELECT product_type, production_date
                FROM bin_locations
                WHERE batch = ? AND pallet_number = ?
                LIMIT 1
            ");
            $binMeta->execute([$batch, $pallet]);
            $meta = $binMeta->fetch();
            $productType    = $meta['product_type'];
            $productionDate = $meta['production_date'];
            
            $upd = $pdo->prepare("
                INSERT INTO bin_locations
                    (batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity        = quantity + VALUES(quantity),
                    quantity_kg     = ROUND(quantity_kg + VALUES(quantity_kg), 2),
                    updated_at      = NOW()
            ");
            $upd->execute([$batch, $pallet, $qty, $r['uom'], $productType, $productionDate, $qtyKg, $r['bin_location']]);

            $ins = $pdo->prepare("
                INSERT INTO transactions
                    (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                     source_location, destination_location, bin_location, user_id, remarks, created_at)
                VALUES (?, 'inbound', ?, ?, ?, ?, ?, 'CANCELLATION', 'WH LSN', ?, ?, ?, NOW())
            ");
            $ins->execute([$newTxnId, $batch, $pallet, $qty, $r['uom'], $qtyKg, $r['bin_location'], $me['id'], "Pembatalan TXN: $txn_id"]);

        } elseif ($originalType === 'moving') {
            if (!$pallet) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Data lama tanpa pallet, batch $batch tidak dapat dibatalkan otomatis"]);
            }
            $srcBin      = $r['source_location'];
            $dstBin      = $r['destination_location'];
            $binLocation = $r['bin_location'] ?? null;
            
            $binMeta = $pdo->prepare("
                SELECT product_type, production_date, location_type
                FROM bin_locations
                WHERE batch = ? AND pallet_number = ?
                LIMIT 1
            ");
            $binMeta->execute([$batch, $pallet]);
            $meta           = $binMeta->fetch();
            $productType    = $meta['product_type']    ?? null;
            $productionDate = $meta['production_date'] ?? null;
            $locationType   = $meta['location_type']   ?? null;
            
            // Jika ini cancel dari outbound WH External (moving ke Jasco)
            // maka kembalikan ke bin_location asli, bukan ke source_location (WH LSN)
            $returnToBin = ($dstBin === 'Jasco' && $binLocation) ? $binLocation : $srcBin;

            $check = $pdo->prepare("SELECT quantity FROM bin_locations WHERE batch=? AND pallet_number=? AND bin_location=? FOR UPDATE");
            $check->execute([$batch, $pallet, $dstBin]);
            $current = (float)$check->fetchColumn();
            if ($current < $qty) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'error' => "Stok di $dstBin sudah berkurang, tidak dapat dibatalkan"]);
            }
            
            $decr = $pdo->prepare("
                UPDATE bin_locations
                SET quantity    = quantity - ?,
                    quantity_kg = ROUND(quantity_kg - ?, 2),
                    updated_at  = NOW()
                WHERE batch = ? AND pallet_number = ? AND bin_location = ?
            ");
            $decr->execute([$qty, $qtyKg, $batch, $pallet, $dstBin]);

            $incr = $pdo->prepare("
                INSERT INTO bin_locations
                    (batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, location_type, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity    = quantity + VALUES(quantity),
                    quantity_kg = ROUND(quantity_kg + VALUES(quantity_kg), 2),
                    updated_at  = NOW()
            ");
            $incr->execute([$batch, $pallet, $qty, $r['uom'], $productType, $productionDate, $qtyKg, $returnToBin, $locationType]);

            $ins = $pdo->prepare("
                INSERT INTO transactions
                    (transaction_id, movement_type, batch, pallet_number, quantity, uom, quantity_kg,
                     source_location, destination_location, bin_location, user_id, remarks, created_at)
                VALUES (?, 'moving', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $newTxnId, $batch, $pallet, $qty, $r['uom'], $qtyKg,
                $dstBin, $returnToBin, $binLocation,
                $me['id'], "Pembatalan TXN: $txn_id"
            ]);
        }
    }

    $pdo->prepare("UPDATE transactions SET is_cancelled = 1 WHERE transaction_id = ?")->execute([$txn_id]);

    $pdo->commit();
    jsonResponse(['success' => true, 'message' => "Transaksi $txn_id berhasil dibatalkan | TXN Pembatalan: $newTxnId"]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[transaction_cancel.php] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}