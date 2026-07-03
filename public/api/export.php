<?php
// ============================================================
// WMS LSN - Export API (PhpSpreadsheet & CSV Fallback)
// ============================================================
define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

requireAuth();

$type = sanitize($_GET['type'] ?? '');
$pdo  = getDB();

// Check if PhpSpreadsheet is available
$autoload = BASE_PATH . '/vendor/autoload.php';
$hasComposer = file_exists($autoload);

if ($hasComposer) {
    require_once $autoload;
}

// Use statements are fine even if classes don't exist, 
// as long as we don't instantiate them when $hasComposer is false.
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function fmtDate(?string $dt): string {
    if (!$dt) return '';
    return date('d/m/Y H:i:s', strtotime($dt));
}

switch ($type) {
    case 'stock':
        requireModule('stock');
        exportStock($pdo);
        break;
    case 'movements':
        requireModule('movements');
        exportMovements($pdo);
        break;
    case 'softcase':
        requireModule('softcase-monitoring');
        exportSoftcase($pdo);
        break;
    default:
        jsonResponse(['success' => false, 'error' => 'Tipe export tidak valid'], 400);
}

/**
 * Dispatch export to either XLSX or CSV based on Composer availability
 */
function dispatchExport(string $title, array $headers, array $rows, string $filename): void {
    global $hasComposer;
    if ($hasComposer) {
        buildXlsx($title, $headers, $rows, $filename);
    } else {
        buildCsv($headers, $rows, $filename);
    }
}

// ─── Stock Export ────────────────────────────────────────────
function exportStock(PDO $pdo): void {
    $conditions = ['quantity > 0'];
    $params     = [];

    if (!empty($_GET['batch']))         { $conditions[] = 'batch LIKE ?';         $params[] = '%'.$_GET['batch'].'%'; }
    if (!empty($_GET['pallet_number'])) { $conditions[] = 'pallet_number LIKE ?'; $params[] = '%'.$_GET['pallet_number'].'%'; }
    if (!empty($_GET['bin_location']))  { $conditions[] = 'bin_location LIKE ?';  $params[] = '%'.$_GET['bin_location'].'%'; }
    if (!empty($_GET['location_type'])) { $conditions[] = 'location_type LIKE ?'; $params[] = '%'.$_GET['location_type'].'%'; }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $stmt = $pdo->prepare("
        SELECT batch, pallet_number, quantity, uom, product_type, production_date, quantity_kg, bin_location, location_type, updated_at 
        FROM bin_locations $where 
        ORDER BY updated_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) { $r['updated_at'] = fmtDate($r['updated_at']); $r['production_date'] = $r['production_date'] ? date('Y/m/d', strtotime($r['production_date'])) : ''; }

    $headers = ['Batch','Pallet No','Quantity','UOM','Product Type','Production Date','Qty KG','Bin Location','Location Type','Updated At'];
    buildXlsx('Stock Overview', $headers, $rows, 'stock_overview_'.date('Ymd_His'));
}

// ─── Movements Export ────────────────────────────────────────
function exportMovements(PDO $pdo): void {
    $conditions = ['1=1'];
    $params     = [];

    if (!empty($_GET['movement_type'])) { $conditions[] = 't.movement_type = ?';         $params[] = $_GET['movement_type']; }
    if (!empty($_GET['batch']))         { $conditions[] = 't.batch LIKE ?';               $params[] = '%'.$_GET['batch'].'%'; }
    if (!empty($_GET['transaction_id'])){ $conditions[] = 't.transaction_id LIKE ?';      $params[] = '%'.$_GET['transaction_id'].'%'; }
    if (!empty($_GET['source']))        { $conditions[] = 't.source_location LIKE ?';     $params[] = '%'.$_GET['source'].'%'; }
    if (!empty($_GET['destination']))   { $conditions[] = 't.destination_location LIKE ?';$params[] = '%'.$_GET['destination'].'%'; }
    if (!empty($_GET['date_from']))     { $conditions[] = 'DATE(t.created_at) >= ?';      $params[] = $_GET['date_from']; }
    if (!empty($_GET['date_to']))       { $conditions[] = 'DATE(t.created_at) <= ?';      $params[] = $_GET['date_to']; }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $stmt  = $pdo->prepare("
        SELECT t.transaction_id, t.movement_type, t.batch, t.quantity, t.uom,
               t.quantity_kg, t.source_location, t.destination_location,
               t.remarks, t.created_at, u.username
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        $where ORDER BY t.created_at ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['created_at'] = fmtDate($r['created_at']); }

    $headers = ['Transaction ID','Type','Batch','Qty','UOM','Qty KG','From','To','Remarks','Date','User'];
    buildXlsx('Movements', $headers, $rows, 'movements_'.date('Ymd_His'));
}

// ─── Softcase Export ─────────────────────────────────────────
function exportSoftcase(PDO $pdo): void {
    $conditions = ['1=1'];
    $params     = [];

    if (!empty($_GET['batch']))         { $conditions[] = 's.batch LIKE ?';         $params[] = '%'.$_GET['batch'].'%'; }
    if (!empty($_GET['pallet_number'])) { $conditions[] = 's.pallet_number LIKE ?'; $params[] = '%'.$_GET['pallet_number'].'%'; }
    if (($_GET['status'] ?? '') === 'checked')   $conditions[] = 's.qty_checked > 0';
    if (($_GET['status'] ?? '') === 'unchecked') $conditions[] = 's.qty_checked = 0';

    if (!empty($_GET['date_from'])) {
        $conditions[] = 's.checked_at >= ?';
        $params[]     = $_GET['date_from'] . ' ' . ($_GET['time_from'] ?: '00:00') . ':00';
    }
    if (!empty($_GET['date_to'])) {
        $conditions[] = 's.checked_at <= ?';
        $params[]     = $_GET['date_to'] . ' ' . ($_GET['time_to'] ?: '23:59') . ':59';
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $stmt = $pdo->prepare("
        SELECT s.batch, s.pallet_number, s.qty_checked, s.uom_checked,
            s.qty_soft, s.uom_soft, s.remarks,
            (SELECT b.production_date FROM bin_locations b
                WHERE b.batch = s.batch AND b.pallet_number = s.pallet_number
                LIMIT 1) AS production_date,
            s.checked_at
        FROM softcase s
        $where
        ORDER BY s.checked_at ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['checked_at']      = fmtDate($r['checked_at']);
        $r['production_date'] = $r['production_date'] ? date('Y/m/d', strtotime($r['production_date'])) : '';
    }

    $headers = ['Batch','Pallet No','Qty Checked','UOM','Qty Soft','UOM Soft','Remarks','Production Date','Checked At'];
    buildXlsx('Softcase Monitoring', $headers, $rows, 'softcase_'.date('Ymd_His'));
}

// ─── XLSX Builder ────────────────────────────────────────────
function buildXlsx(string $title, array $headers, array $rows, string $filename): void {
    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle($title);

    // Header style
    $headerStyle = [
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1a2035']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
    ];

    // Write headers
    foreach ($headers as $col => $hdr) {
        $cell = chr(65 + $col) . '1';
        $sheet->setCellValue($cell, $hdr);
    }
    $lastCol = chr(65 + count($headers) - 1);
    $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($headerStyle);

    // Write data
    foreach ($rows as $rowIdx => $row) {
        $values = array_values($row);
        foreach ($values as $colIdx => $val) {
            $cell = chr(65 + $colIdx) . ($rowIdx + 2);
            $sheet->setCellValue($cell, $val);
        }
        // Alternate row colors
        if ($rowIdx % 2 === 0) {
            $sheet->getStyle("A".($rowIdx+2).":{$lastCol}".($rowIdx+2))
                  ->getFill()->setFillType(Fill::FILL_SOLID)
                  ->getStartColor()->setRGB('e2e8f0');
        }
    }

    // Auto-width
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$filename}.xlsx\"");
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ─── CSV Builder ─────────────────────────────────────────────
function buildCsv(array $headers, array $rows, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
    header('Cache-Control: max-age=0');
    
    $out = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    fputcsv($out, $headers, ";");
    
    // Write data rows
    foreach ($rows as $row) {
        $formatedRow = array_map(function($value) {
            if(is_numeric($value)) {
                return str_replace('.', ',',(string)$value);
            }
            return $value;
        }, array_values($row));
        
        fputcsv($out, $formatedRow, ";");
    }
    
    fclose($out);
    exit;
}
