<?php
require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT code, name, capacity FROM vendors WHERE is_active = 1 ORDER BY name");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

csrfCheck();
if (!canAccess('vendors')) {
    jsonResponse(['success' => false, 'error' => 'Tidak memiliki akses'], 403);
}

$action = getInput('action', '');
if ($action === 'create') {
    $code     = sanitize(getInput('code', ''));
    $name     = sanitize(getInput('name', ''));
    $capacity = (int)getInput('capacity', 0);

    if (!$code || !$name) jsonResponse(['success' => false, 'error' => 'Kode dan nama gudang wajib diisi']);
    if (strpos($code, ' ') !== false) jsonResponse(['success' => false, 'error' => 'Kode gudang tidak boleh mengandung spasi']);

    $chk = $pdo->prepare("SELECT code FROM vendors WHERE code = ?");
    $chk->execute([$code]);
    if ($chk->fetch()) jsonResponse(['success' => false, 'error' => 'Kode gudang sudah dipakai']);

    $stmt = $pdo->prepare("INSERT INTO vendors (code, name, capacity) VALUES (?, ?, ?)");
    $stmt->execute([$code, $name, $capacity]);
    jsonResponse(['success' => true, 'message' => "Gudang '$name' berhasil ditambahkan"]);
}

jsonResponse(['success' => false, 'error' => 'Action tidak dikenali'], 400);