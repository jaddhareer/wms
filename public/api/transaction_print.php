<?php

require_once dirname(dirname(__DIR__)) . '/functions/bootstrap.php';

requireAuth();
$pdo = getDB();

$txn_id = sanitize($_GET['transaction_id'] ?? '');
$stmt = $pdo->prepare("SELECT t.*, u.username FROM transactions t LEFT JOIN users u ON t.user_id=u.id WHERE t.transaction_id=? ORDER BY t.id ASC");
$stmt->execute([$txn_id]);
$rows = $stmt->fetchAll();

$stmt2 = $pdo->prepare("SELECT batch, uom, source_location, destination_location, COUNT(pallet_number) AS total_pallet, SUM(quantity) AS total_quantity, SUM(quantity_kg) AS total_qty_kg FROM transactions WHERE transaction_id =? GROUP BY batch");
$stmt2->execute([$txn_id]);
$summary = $stmt2->fetchAll();

if (!$rows) { die('Transaksi tidak ditemukan'); }
$h = $rows[0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TXN <?= htmlspecialchars($txn_id) ?></title>
    <style>
        * { box-sizing: border-box; }

        html, body { margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            font-size: 13px;
            color: #111;
            padding: 30px;
            overflow-x: hidden;
        }

        h2 { margin: 0 0 4px; }
        .info div { margin-bottom: 3px; }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
            margin-top: 16px;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 6px 10px;
            text-align: left;
            font-size: 12px;
            white-space: nowrap;
        }

        th { background: #f0f0f0; }

        /* Area tanda tangan pada halaman */
        .sig-section { margin-top: 40px; }
        .sig-section-title { font-weight: bold; margin-bottom: 14px; }
        .sig-grid { display: flex; gap: 40px; flex-wrap: wrap; }
        .sig-box { width: 280px; }
        .sig-box-label { font-weight: bold; margin-bottom: 8px; }
        .sig-canvas-wrap {
            position: relative;
            width: 280px;
            height: 110px;
            border: 1px solid #999;
            cursor: pointer;
            background: #fff;
        }
        .sig-canvas-wrap:hover { border-color: #555; }
        .sig-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 12px;
            pointer-events: none;
            text-align: center;
            padding: 0 8px;
        }
        .sig-print-img {
            max-width: 100%;
            max-height: 100%;
            display: block;
            margin: 0 auto;
        }
        .sig-line { border-top: 1px solid #111; margin-top: 4px; }
        .sig-name-input {
            width: 100%;
            min-height: 40px;
            margin-top: 8px;
            padding: 6px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .sig-name-print { margin-top: 8px; font-size: 12px; display: none; }
        .sig-role-label { font-size: 11px; color: #666; margin-top: 2px; }

        /* Modal signature */
        .sig-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1000;
            padding: 12px;
            background: rgba(0, 0, 0, .55);
            align-items: center;
            justify-content: center;
        }
        .sig-modal-overlay.active { display: flex; }
        .sig-modal {
            width: min(720px, 100%);
            max-height: calc(100vh - 24px);
            max-height: calc(100dvh - 24px);
            box-sizing: border-box;
            overflow: hidden;
            background: #fff;
            padding: 18px;
            border-radius: 12px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, .25);
        }
        .sig-modal-title {
            flex: 0 0 auto;
            font-weight: bold;
            margin-bottom: 12px;
            font-size: 16px;
        }
        .sig-modal canvas {
            display: block;
            width: 100%;
            height: 220px;
            border: 1px solid #999;
            border-radius: 6px;
            background: #fff;
            touch-action: none;
        }
        .sig-modal-actions {
            display: grid;
            grid-template-columns: 1fr 1fr 1.3fr;
            gap: 8px;
            margin-top: 14px;
        }
        .sig-modal-actions button {
            min-height: 44px;
            padding: 8px 12px;
            border: 1px solid #bbb;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
        }
        .sig-modal-actions button:last-child {
            color: #fff;
            border-color: #1769aa;
            background: #1769aa;
        }
        #btnPrint:disabled { opacity: .5; cursor: not-allowed; }
        body.signature-modal-open { overflow: hidden; }

        /* Modal lebih luas pada HP: tinggi 70% viewport, canvas mengisi sisa ruang */
        @media (max-width: 600px) {
            body { padding: 12px; }

            .sig-grid { gap: 24px; }
            .sig-box { width: 100%; max-width: 320px; }
            .sig-canvas-wrap { width: 100%; }

            .sig-modal-overlay {
                align-items: flex-end;
                padding: 0;
            }
            .sig-modal {
                width: 100%;
                height: 70vh;
                height: 70dvh;
                max-height: 70vh;
                max-height: 70dvh;
                display: flex;
                flex-direction: column;
                padding: 16px;
                border-radius: 16px 16px 0 0;
                animation: sigSlideUp .18s ease-out;
                -webkit-text-size-adjust: 100%;
                text-size-adjust: 100%;
            }
            .sig-modal-title { flex: 0 0 auto; }
            .sig-modal canvas {
                flex: 1 1 0;
                width: 100%;
                height: 0;
                min-height: 0;
                max-height: none;
                aspect-ratio: auto;
            }
            .sig-modal-actions {
                flex: 0 0 auto;
                grid-template-columns: 1fr 1fr;
            }
            .sig-modal-actions button {
                font-size: 16px;
            }
            .sig-modal-actions button:last-child {
                grid-column: 1 / -1;
            }
        }

        @keyframes sigSlideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }

        @media print {
            .no-print, .sig-modal-overlay { display: none !important; }
            .sig-placeholder { display: none !important; }
            .sig-canvas-wrap { border: none; cursor: default; }
            .sig-name-input { display: none; }
            .sig-name-print { display: block; }
            body { padding: 30px; overflow: visible; }
            .table-wrap { overflow: visible; }
            table { min-width: 0; }
            th, td { white-space: normal; }
        }
    </style>
</head>
<body>
    <button class="no-print" id="btnPrint" onclick="handlePrint()" disabled>Print / Save as PDF</button>

    <h2>Detail <?= htmlspecialchars(ucfirst($h['movement_type'])) ?> — <?= htmlspecialchars($txn_id) ?></h2>
    <div class="info">
        <div><strong>Tipe:</strong> <?= htmlspecialchars(ucfirst($h['movement_type'])) ?> <?= $h['is_cancelled'] ? '(DIBATALKAN/REVISI)' : '' ?></div>
        <div><strong>Oleh:</strong> <?= htmlspecialchars($h['username']) ?></div>
        <div><strong>Waktu:</strong> <?= htmlspecialchars($h['created_at']) ?></div>
        <div><strong>Remarks:</strong> <?= htmlspecialchars($h['remarks'] ?? '-') ?></div>
    </div>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Batch</th><th>Pallet</th><th>Qty</th><th>UOM</th><th>Kg</th><th>Dari</th><th>Bin Asal</th><th>Ke</th><th>Bin Tujuan</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['batch']) ?></td>
                    <td><?= htmlspecialchars($r['pallet_number'] ?? '-') ?></td>
                    <td><?= number_format($r['quantity']) ?></td>
                    <td><?= htmlspecialchars($r['uom'] ?? '') ?></td>
                    <td><?= number_format($r['quantity_kg'], 2) ?></td>
                    <td><?= htmlspecialchars($r['source_location'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['source_bin'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['destination_location'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['destination_bin'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <table>
            <thead><tr><th>Batch</th><th>Total Pallet</th><th>Qty</th><th>UOM</th><th>Kg</th><th>Dari</th><th>Ke</th></tr></thead>
            <tbody>
                <?php foreach ($summary as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['batch']) ?></td>
                    <td><?= htmlspecialchars($s['total_pallet']) ?> Pallet</td>
                    <td><?= number_format($s['total_quantity']) ?></td>
                    <td><?= htmlspecialchars($s['uom'] ?? '') ?></td>
                    <td><?= number_format($s['total_qty_kg'], 2) ?></td>
                    <td><?= htmlspecialchars($s['source_location'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($s['destination_location'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="sig-section">
        <div class="sig-section-title">Tanda Tangan Serah Terima</div>
        <div class="sig-grid">
            <div class="sig-box" data-role="warehouse">
                <div class="sig-box-label">Staff Gudang (Warehouse)</div>
                <div class="sig-canvas-wrap" id="sigWrap_warehouse" onclick="openSignaturePad('warehouse')">
                    <div class="sig-placeholder" id="sigPlaceholder_warehouse">Klik untuk tanda tangan</div>
                    <img class="sig-print-img" id="sigImg_warehouse" style="display:none" alt="Tanda tangan staff gudang">
                </div>
                <div class="sig-line"></div>
                <input type="text" class="sig-name-input no-print" id="sigNameInput_warehouse" placeholder="Ketik nama lengkap" oninput="updateSigName('warehouse')">
                <div class="sig-name-print" id="sigNamePrint_warehouse">&nbsp;</div>
                <div class="sig-role-label">Nama &amp; Tanda Tangan</div>
                <input type="hidden" id="sigData_warehouse">
            </div>
            <div class="sig-box" data-role="customer">
                <div class="sig-box-label">Produksi / Customer</div>
                <div class="sig-canvas-wrap" id="sigWrap_customer" onclick="openSignaturePad('customer')">
                    <div class="sig-placeholder" id="sigPlaceholder_customer">Klik untuk tanda tangan</div>
                    <img class="sig-print-img" id="sigImg_customer" style="display:none" alt="Tanda tangan customer">
                </div>
                <div class="sig-line"></div>
                <input type="text" class="sig-name-input no-print" id="sigNameInput_customer" placeholder="Ketik nama lengkap" oninput="updateSigName('customer')">
                <div class="sig-name-print" id="sigNamePrint_customer">&nbsp;</div>
                <div class="sig-role-label">Nama &amp; Tanda Tangan</div>
                <input type="hidden" id="sigData_customer">
            </div>
        </div>
    </div>

    <div class="sig-modal-overlay no-print" id="sigModalOverlay">
        <div class="sig-modal" role="dialog" aria-modal="true" aria-labelledby="sigModalTitle">
            <div class="sig-modal-title" id="sigModalTitle">Tanda Tangan</div>
            <canvas id="sigPadCanvas" width="500" height="220"></canvas>
            <div class="sig-modal-actions">
                <button type="button" onclick="clearSigPad()">Hapus</button>
                <button type="button" onclick="closeSigModal()">Batal</button>
                <button type="button" onclick="saveSigPad()">Simpan</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/signature_pad@5.1.3/dist/signature_pad.umd.min.js"></script>
    <script>
        let sigPad = null;
        let activeSigRole = null;
        let resizeTimer = null;
        const sigCanvas = document.getElementById('sigPadCanvas');
        const sigOverlay = document.getElementById('sigModalOverlay');

        function setupCanvasDimensions() {
            const rect = sigCanvas.getBoundingClientRect();
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const width = Math.max(1, Math.round(rect.width));
            const height = Math.max(1, Math.round(rect.height));
            const ctx = sigCanvas.getContext('2d');

            ctx.setTransform(1, 0, 0, 1, 0, 0);
            sigCanvas.width = Math.round(width * ratio);
            sigCanvas.height = Math.round(height * ratio);
            ctx.scale(ratio, ratio);
        }

        function openSignaturePad(role) {
            activeSigRole = role;
            document.getElementById('sigModalTitle').textContent =
                role === 'warehouse'
                    ? 'Tanda Tangan - Staff Gudang'
                    : 'Tanda Tangan - Pihak Produksi/Customer';

            sigOverlay.classList.add('active');
            document.body.classList.add('signature-modal-open');

            if (!sigPad) {
                sigPad = new SignaturePad(sigCanvas, {
                    backgroundColor: 'rgb(255,255,255)',
                    minWidth: 0.8,
                    maxWidth: 2.5,
                    throttle: 8
                });
            }

            requestAnimationFrame(() => {
                const existing = document.getElementById('sigData_' + role).value;
                setupCanvasDimensions();
                sigPad.clear();

                if (existing) {
                    sigPad.fromDataURL(existing, {
                        ratio: Math.max(window.devicePixelRatio || 1, 1),
                        width: sigCanvas.clientWidth,
                        height: sigCanvas.clientHeight
                    });
                }
            });
        }

        function closeSigModal() {
            sigOverlay.classList.remove('active');
            document.body.classList.remove('signature-modal-open');
            activeSigRole = null;
        }

        function clearSigPad() {
            if (sigPad) sigPad.clear();
        }

        function saveSigPad() {
            if (!sigPad || sigPad.isEmpty()) {
                alert('Tanda tangan masih kosong.');
                return;
            }

            const role = activeSigRole;
            const dataUrl = sigPad.toDataURL('image/png');
            document.getElementById('sigData_' + role).value = dataUrl;

            const img = document.getElementById('sigImg_' + role);
            img.src = dataUrl;
            img.style.display = 'block';
            document.getElementById('sigPlaceholder_' + role).style.display = 'none';

            closeSigModal();
            validatePrintReady();
        }

        function updateSigName(role) {
            const val = document.getElementById('sigNameInput_' + role).value;
            document.getElementById('sigNamePrint_' + role).textContent = val || '\u00A0';
            validatePrintReady();
        }

        function validatePrintReady() {
            const roles = ['warehouse', 'customer'];
            const ready = roles.every(role =>
                document.getElementById('sigData_' + role).value !== '' &&
                document.getElementById('sigNameInput_' + role).value.trim() !== ''
            );
            document.getElementById('btnPrint').disabled = !ready;
        }

        function handlePrint() {
            if (document.getElementById('btnPrint').disabled) {
                alert('Mohon lengkapi tanda tangan dan nama untuk Staff Gudang dan Pihak Produksi/Customer sebelum mencetak.');
                return;
            }
            window.print();
        }

        window.addEventListener('resize', () => {
            if (!sigPad || !activeSigRole || !sigOverlay.classList.contains('active')) return;

            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                const savedData = sigPad.isEmpty() ? null : sigPad.toDataURL('image/png');
                setupCanvasDimensions();
                sigPad.clear();

                if (savedData) {
                    sigPad.fromDataURL(savedData, {
                        ratio: Math.max(window.devicePixelRatio || 1, 1),
                        width: sigCanvas.clientWidth,
                        height: sigCanvas.clientHeight
                    });
                }
            }, 150);
        });

        sigOverlay.addEventListener('click', event => {
            if (event.target === sigOverlay) closeSigModal();
        });
    </script>
</body>
</html>
