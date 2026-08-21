<?php
/**
 * Update bin_locations: set quantity = 0 dan quantity_kg = 0
 * berdasarkan daftar batch tertentu.
 * Jalankan: php update_bin_locations.php
 */

define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

$pdo = getDB();

// ==== ISI BATCH YANG MAU DI-ZEROKAN ====
$batches = [
    'DE23GC0057', 'DE23GC0003'
];

if (empty($batches)) {
    die("Array \$batches masih kosong. Isi dulu batch yang mau di-update.\n");
}

// === PENYESUAIAN KONEKSI ===
// Sesuaikan salah satu opsi di bawah tergantung isi config/database.php:

// Opsi A: config/database.php me-return objek PDO langsung
// $pdo = require __DIR__ . '/config/database.php';

// Opsi B: config/database.php punya fungsi/class, misal getConnection()
// $pdo = getConnection();

// Opsi C: config/database.php isinya array kredensial
// $config = require __DIR__ . '/config/database.php';
// $pdo = new PDO(
//     "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
//     $config['username'],
//     $config['password'],
//     [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
// );

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Variabel \$pdo belum terisi objek PDO. Sesuaikan bagian koneksi di atas dengan isi config/database.php Anda.\n");
}

$sql = "UPDATE bin_locations SET quantity = 0, quantity_kg = 0 WHERE batch = :batch";
$stmt = $pdo->prepare($sql);

$success = 0;
$failed  = [];

foreach ($batches as $batch) {
    try {
        $stmt->execute(['batch' => $batch]);
        $rows = $stmt->rowCount();
        echo "Batch {$batch}: {$rows} baris ter-update.\n";
        $success++;
    } catch (PDOException $e) {
        echo "Batch {$batch}: GAGAL - " . $e->getMessage() . "\n";
        $failed[] = $batch;
    }
}

echo "\n=== Ringkasan ===\n";
echo "Total batch diproses: " . count($batches) . "\n";
echo "Berhasil: {$success}\n";
if (!empty($failed)) {
    echo "Gagal: " . implode(', ', $failed) . "\n";
}