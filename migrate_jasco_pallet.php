<?php
/**
 * Script sekali-jalan: menggabungkan baris bin_locations yang bin_location='Jasco'
 * supaya setiap batch cuma punya SATU baris, dengan pallet_number = JASCO_PALLET.
 *
 * CARA PAKAI:
 * 1. Taruh file ini di root project (sejajar folder config/, functions/, public/).
 * 2. Pastikan config.php sudah punya: define('JASCO_PALLET', '-');
 * 3. Jalankan dulu dalam mode DRY RUN (default) untuk lihat apa yang AKAN terjadi:
 *      php migrate_jasco_pallet.php
 * 4. Kalau hasilnya sudah sesuai ekspektasi, jalankan mode REAL:
 *      php migrate_jasco_pallet.php --apply
 * 5. Jalankan saat tidak ada orang lain sedang input inbound/outbound
 *    dari/ke WH External (supaya tidak bentrok dengan data yang sedang berjalan).
 * 6. Setelah sukses & sudah dicek, file ini aman dihapus.
 */

define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

if (!defined('JASCO_PALLET')) {
    die("ERROR: konstanta JASCO_PALLET belum ada di config.php. Tambahkan dulu:\n"
      . "define('JASCO_PALLET', '-');\n");
}

$isApply = in_array('--apply', $argv ?? [], true);

echo "==============================================\n";
echo $isApply ? " MODE: APPLY (data akan diubah!)\n" : " MODE: DRY RUN (tidak ada yang diubah)\n";
echo "==============================================\n\n";

$pdo = getDB();

// Ambil semua baris Jasco, dikelompokkan per batch
$stmt = $pdo->query("
    SELECT batch, pallet_number, quantity, quantity_kg, uom, product_type, production_date, updated_at
    FROM bin_locations
    WHERE location_type = 'WH External'
    ORDER BY batch, pallet_number
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Tidak ada baris Jasco sama sekali. Tidak ada yang perlu digabung.\n";
    exit;
}

// Kelompokkan per batch
$byBatch = [];
foreach ($rows as $r) {
    $byBatch[$r['batch']][] = $r;
}

$totalBatch   = 0;
$totalRowsOld = 0;
$warnings     = [];

if ($isApply) {
    $pdo->beginTransaction();
}

foreach ($byBatch as $batch => $group) {
    $totalBatch++;
    $totalRowsOld += count($group);

    $sumQty   = 0.0;
    $sumKg    = 0.0;
    $ptypes   = [];
    $pdates   = [];
    $newestUpdatedAt = null;
    $newestRow = null;

    foreach ($group as $r) {
        $sumQty += (float)$r['quantity'];
        $sumKg  += (float)$r['quantity_kg'];
        $ptypes[$r['product_type']] = true;
        $pdates[$r['production_date']] = true;
        if ($newestUpdatedAt === null || $r['updated_at'] > $newestUpdatedAt) {
            $newestUpdatedAt = $r['updated_at'];
            $newestRow = $r;
        }
    }

    // Kalau dalam satu batch ternyata product_type atau production_date beda-beda,
    // itu janggal (harusnya satu batch = satu jenis produk/tanggal produksi).
    // Tetap lanjut (pakai data dari baris ter-update terakhir), tapi dicatat sebagai warning
    // supaya Anda bisa cek manual baris asalnya kalau perlu.
    if (count($ptypes) > 1) {
        $warnings[] = "Batch $batch: product_type berbeda-beda di antara baris yang digabung (" . implode(', ', array_keys($ptypes)) . "), dipakai punya baris paling baru.";
    }
    if (count($pdates) > 1) {
        $warnings[] = "Batch $batch: production_date berbeda-beda di antara baris yang digabung (" . implode(', ', array_keys($pdates)) . "), dipakai punya baris paling baru.";
    }

    $finalProductType   = $newestRow['product_type'];
    $finalProductionDate = $newestRow['production_date'];
    $finalUom            = $newestRow['uom'];

    echo "Batch $batch: " . count($group) . " baris -> 1 baris "
       . "(total qty=" . round($sumQty, 4) . " CTN, kg=" . round($sumKg, 2) . ")\n";
    foreach ($group as $r) {
        echo "    - pallet_number lama: {$r['pallet_number']}, qty: {$r['quantity']}, kg: {$r['quantity_kg']}\n";
    }

    if ($isApply) {
        // Hapus semua baris lama batch ini di Jasco
        $del = $pdo->prepare("DELETE FROM bin_locations WHERE batch = ? AND location_type = 'WH External'");
        $del->execute([$batch]);

        // Masukkan satu baris baru dengan pallet_number global
        $ins = $pdo->prepare("
            INSERT INTO bin_locations
                (batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, location_type, vendor_code, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Jasco STAGE', 'WH External', 'Jasco', NOW())
        ");
        $ins->execute([$batch, JASCO_PALLET, $sumQty, $finalUom, $finalProductType, $finalProductionDate, $sumKg]);
    }
}

if ($isApply) {
    $pdo->commit();
}

echo "\n==============================================\n";
echo "Ringkasan: $totalBatch batch, $totalRowsOld baris lama -> $totalBatch baris baru.\n";
if ($warnings) {
    echo "\nPERINGATAN (perlu dicek manual):\n";
    foreach ($warnings as $w) echo " - $w\n";
}
echo $isApply
    ? "\nSELESAI — data sudah diubah di database.\n"
    : "\nDRY RUN selesai — TIDAK ADA yang diubah. Jalankan ulang dengan --apply kalau hasil di atas sudah sesuai.\n";
