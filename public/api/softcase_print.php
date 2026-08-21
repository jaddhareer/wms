<?php

require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireModule('softcase-monitoring');
$pdo = getDB();

// ─── Filters (sama seperti export.php > exportSoftcase) ──────
$fBatch     = sanitize($_GET['batch']         ?? '');
$fPallet    = sanitize($_GET['pallet_number'] ?? '');
$fStatus    = sanitize($_GET['status']        ?? '');
$fDateFrom  = sanitize($_GET['date_from']     ?? '');
$fTimeFrom  = sanitize($_GET['time_from']     ?? '');
$fDateTo    = sanitize($_GET['date_to']       ?? '');
$fTimeTo    = sanitize($_GET['time_to']       ?? '');

$conditions = ['1=1'];
$params     = [];

if ($fBatch)  { $conditions[] = 's.batch LIKE ?';         $params[] = "%$fBatch%"; }
if ($fPallet) { $conditions[] = 's.pallet_number LIKE ?'; $params[] = "%$fPallet%"; }
if ($fStatus === 'checked')   $conditions[] = 's.qty_checked > 0';
if ($fStatus === 'unchecked') $conditions[] = 's.qty_checked = 0';

if ($fDateFrom) {
    $conditions[] = 's.checked_at >= ?';
    $params[]     = $fDateFrom . ' ' . ($fTimeFrom ?: '00:00') . ':00';
}
if ($fDateTo) {
    $conditions[] = 's.checked_at <= ?';
    $params[]     = $fDateTo . ' ' . ($fTimeTo ?: '23:59') . ':59';
}

$where = 'WHERE ' . implode(' AND ', $conditions);

// ─── Summary per batch ─────────────────────────────────────
$sumStmt = $pdo->prepare("
    SELECT s.batch,
           COUNT(*)           AS total_pallet,
           SUM(s.qty_checked) AS total_checked,
           SUM(s.qty_soft)    AS total_soft
    FROM softcase s
    $where
    GROUP BY s.batch
    ORDER BY s.batch ASC
");
$sumStmt->execute($params);
$summaryRows = $sumStmt->fetchAll();

$grandPallet  = 0;
$grandChecked = 0;
$grandSoft    = 0;
foreach ($summaryRows as &$r) {
    $r['pct'] = $r['total_checked'] > 0 ? round(($r['total_soft'] / $r['total_checked']) * 100, 2) : 0;
    $grandPallet  += (int)$r['total_pallet'];
    $grandChecked += (float)$r['total_checked'];
    $grandSoft    += (float)$r['total_soft'];
}
unset($r);
$grandPct = $grandChecked > 0 ? round(($grandSoft / $grandChecked) * 100, 2) : 0;

// ─── Detail per pallet ──────────────────────────────────────
$detStmt = $pdo->prepare("
    SELECT s.batch, s.pallet_number, s.qty_checked, s.uom_checked,
           s.qty_soft, s.uom_soft, s.remarks, s.checked_at
    FROM softcase s
    $where
    ORDER BY s.batch ASC, s.pallet_number ASC
");
$detStmt->execute($params);
$detailRows = $detStmt->fetchAll();

// ─── Info filter aktif (ditampilkan di header) ─────────────
$filterParts = [];
if ($fBatch)    $filterParts[] = "Batch: $fBatch";
if ($fPallet)   $filterParts[] = "Pallet: $fPallet";
if ($fStatus)   $filterParts[] = "Status: " . ($fStatus === 'checked' ? 'Sudah Dicek' : 'Belum Dicek');
if ($fDateFrom) $filterParts[] = "Dari: $fDateFrom" . ($fTimeFrom ? " $fTimeFrom" : '');
if ($fDateTo)   $filterParts[] = "Sampai: $fDateTo" . ($fTimeTo ? " $fTimeTo" : '');
$filterInfo = $filterParts ? implode(' | ', $filterParts) : 'Semua data';

function fmtDt(?string $dt): string {
    if (!$dt) return '-';
    return date('d/m/Y H:i', strtotime($dt));
}
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>Softcase Monitoring Report</title>
        <style>
            body{font-family:Arial,sans-serif;font-size:13px;color:#111;padding:30px}
            h2{margin-bottom:4px}
            h3{margin:22px 0 6px}
            table{width:100%;border-collapse:collapse;margin-top:10px}
            th,td{border:1px solid #ccc;padding:6px 10px;text-align:left;font-size:12px}
            th{background:#f0f0f0}
            td.num, th.num{text-align:right}
            tfoot td{font-weight:bold;background:#f7f7f7}
            .info div{margin-bottom:3px}
            @media print{ .no-print{display:none} }
        </style>
    </head>
<body>
    <button class="no-print" onclick="window.print()">Print / Save as PDF</button>
    <h2>Softcase Monitoring Report</h2>
    <div class="info">
        <div><strong>Filter:</strong> <?= htmlspecialchars($filterInfo) ?></div>
        <div><strong>Dicetak:</strong> <?= date('d/m/Y H:i') ?></div>
    </div>

    <h3>Summary per Batch</h3>
    <table>
        <thead>
            <tr>
                <th>Batch</th>
                <th class="num">Jml Pallet</th>
                <th class="num">Total Qty Checked (CTN)</th>
                <th class="num">Total Qty Soft (CTN)</th>
                <th class="num">% Softcase</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($summaryRows): foreach ($summaryRows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['batch']) ?></td>
                <td class="num"><?= (int)$r['total_pallet'] ?></td>
                <td class="num"><?= number_format((float)$r['total_checked'], 0) ?></td>
                <td class="num"><?= number_format((float)$r['total_soft'], 0) ?></td>
                <td class="num"><?= number_format($r['pct'], 2) ?>%</td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5">Tidak ada data</td></tr>
            <?php endif; ?>
        </tbody>
        <?php if ($summaryRows): ?>
        <tfoot>
            <tr>
                <td>TOTAL</td>
                <td class="num"><?= $grandPallet ?></td>
                <td class="num"><?= number_format($grandChecked, 0) ?></td>
                <td class="num"><?= number_format($grandSoft, 0) ?></td>
                <td class="num"><?= number_format($grandPct, 2) ?>%</td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>

    <h3>Detail per Pallet</h3>
    <table>
        <thead>
            <tr>
                <th>Batch</th>
                <th>Pallet</th>
                <th class="num">Qty Checked</th>
                <th>UOM</th>
                <th class="num">Qty Soft</th>
                <th>UOM Soft</th>
                <th>Remarks</th>
                <th>Terakhir Dicek</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($detailRows): foreach ($detailRows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['batch']) ?></td>
                <td><?= htmlspecialchars($r['pallet_number']) ?></td>
                <td class="num"><?= number_format((float)$r['qty_checked'], 0) ?></td>
                <td><?= htmlspecialchars($r['uom_checked'] ?? 'CTN') ?></td>
                <td class="num"><?= number_format((float)$r['qty_soft'], 0) ?></td>
                <td><?= htmlspecialchars($r['uom_soft'] ?? 'CTN') ?></td>
                <td><?= htmlspecialchars($r['remarks'] ?? '-') ?></td>
                <td><?= fmtDt($r['checked_at']) ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="8">Tidak ada data</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body></html>