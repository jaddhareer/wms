<?php
// ============================================================
// WMS LSN - Helper Functions
// ============================================================
defined('BASE_PATH') or die('Direct access not allowed');

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput(string $key, $default = null) {
    // Support JSON body or POST
    static $json = null;
    if ($json === null) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true) ?? [];
    }
    return $json[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
}

function sanitize(string $val): string {
    return trim(htmlspecialchars($val, ENT_QUOTES, 'UTF-8'));
}

function calcKg(string $productType, int $quantity): float {
    $map = PRODUCT_KG_MAP;
    $rate = $map[$productType] ?? 0;
    return round($rate * $quantity, 2);
}

/**
 * Konversi input quantity+uom apapun → [quantity_ctn, quantity_kg]
 */
function convertToCtnKg(string $productType, string $uom, float $inputQty): array {
    $kgPerCtn  = PRODUCT_KG_MAP[$productType] ?? 0;
    $pcsPerCtn = PRODUCT_PCS_MAP[$productType] ?? 1;

    switch (strtoupper($uom)) {
        case 'CTN':
        case 'BAG': // 25kg pakai BAG, 1 bag = 1 ctn
            $ctn = $inputQty;
            break;
        case 'PCS':
            $ctn = $pcsPerCtn > 0 ? $inputQty / $pcsPerCtn : 0;
            break;
        case 'KG':
            $ctn = $kgPerCtn > 0 ? $inputQty / $kgPerCtn : 0;
            break;
        default:
            $ctn = $inputQty;
    }

    $kg = round($ctn * $kgPerCtn, 4);
    $ctn = round($ctn, 4);

    return ['ctn' => $ctn, 'kg' => $kg];
}

function generateTxnId(string $type, PDO $pdo): string {
    $prefix = TXN_PREFIX[$type] ?? 'LSN';
    $mmyy   = date('my');
    $like   = $prefix . $mmyy . '%';

    $stmt = $pdo->prepare(
        "SELECT transaction_id FROM transactions
         WHERE transaction_id LIKE ?
         ORDER BY transaction_id DESC
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$like]);
    $last = $stmt->fetchColumn();

    $num = $last ? (intval(substr($last, -4)) + 1) : 1;
    return $prefix . $mmyy . str_pad($num, 4, '0', STR_PAD_LEFT);
}

function palletFormat(string $p): string {
    return str_pad(preg_replace('/\D/', '', $p), 2, '0', STR_PAD_LEFT);
}

function buildWhereClause(array $filters, array $allowedCols): array {
    $where  = [];
    $params = [];
    foreach ($filters as $col => $val) {
        if (!in_array($col, $allowedCols, true)) continue;
        if ($val === null || $val === '') continue;
        $where[]  = "`$col` LIKE ?";
        $params[] = '%' . $val . '%';
    }
    return [
        'sql'    => $where ? ('WHERE ' . implode(' AND ', $where)) : '',
        'params' => $params,
    ];
}

function buildDateRange(?string $from, ?string $to, string $col = 'created_at'): array {
    $where  = [];
    $params = [];
    if ($from) { $where[] = "DATE(`$col`) >= ?"; $params[] = $from; }
    if ($to)   { $where[] = "DATE(`$col`) <= ?"; $params[] = $to; }
    return [
        'sql'    => $where ? ('WHERE ' . implode(' AND ', $where)) : '',
        'params' => $params,
    ];
}
