/* ============================================================
   WMS LSN — Main Application JS
   ============================================================ */

'use strict';

// ─── Constants ───────────────────────────────────────────────
const PRODUCT_TYPES = ['500gr','5kg','10kg','25kg','11gr/2.64kg','11gr/3.3kg','11gr/5.5kg'];
const UOM_TYPES     = ['CTN','PCS','BAG'];
const MOVE_TYPES    = { inbound:'Inbound', outbound:'Outbound', softcase:'Softcase', moving:'Moving' };
const BADGE_MAP     = { inbound:'badge-green', outbound:'badge-red', softcase:'badge-amber', moving:'badge-blue' };
const API           = (p) => `../public/api/${p}`;

// ─── State ───────────────────────────────────────────────────
const state = {
  inboundRows:  [],
  outboundRows: [],
  charts:       {},
};

// ─── Init ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (!WMS.auth) { initLogin(); return; }
  initApp();
});

/* ═══════════════════════════════════════════════════════════
   LOGIN
   ═══════════════════════════════════════════════════════════ */
function initLogin() {
  const form   = q('#loginForm');
  const toggle = q('#togglePwd');
  const pwd    = q('#loginPassword');
  const err    = q('#loginError');
  const btn    = q('#loginBtn');

  if (toggle) toggle.addEventListener('click', () => {
    pwd.type = pwd.type === 'password' ? 'text' : 'password';
  });

  autoSelect();

  if (form) form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.classList.add('hidden');
    btn.disabled = true;
    btn.innerHTML = '<span>Memproses...</span>';

    const res = await api('auth.php', 'POST', {
      action: 'login',
      userid: q('#loginUserid').value.trim(),
      password: pwd.value,
    }, false);

    if (res.success) {
      WMS.csrf = res.csrf;
      location.reload();
    } else {
      err.textContent = res.error || 'Login gagal';
      err.classList.remove('hidden');
      btn.disabled = false;
      btn.innerHTML = '<span>Masuk</span>';
    }
  });
}

/* ═══════════════════════════════════════════════════════════
   APP INIT
   ═══════════════════════════════════════════════════════════ */
function initApp() {
  autoSelect();
  initClock();
  initNavigation();
  initSidebar();

  q('#logoutBtn')?.addEventListener('click', async () => {
    await api('auth.php', 'POST', { action: 'logout' }, false);
    location.href = '../public/index.php';
  });

  q('#changePwdBtn')?.addEventListener('click', () => {
    openModal('Ganti Password', `
      <div id="cpResult"></div>
      <div class="field-group">
        <label class="field-label">Password Lama *</label>
        <input id="cpOld" class="field-input" type="password" placeholder="Password saat ini">
      </div>
      <div class="field-group">
        <label class="field-label">Password Baru *</label>
        <input id="cpNew" class="field-input" type="password" placeholder="Min 6 karakter">
      </div>
      <div class="field-group">
        <label class="field-label">Konfirmasi Password Baru *</label>
        <input id="cpConfirm" class="field-input" type="password" placeholder="Ulangi password baru">
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" id="cpSubmitBtn">Simpan</button>
        <button class="btn btn-secondary" onclick="closeModal()">Batal</button>
      </div>
    `);

    q('#cpSubmitBtn').addEventListener('click', async () => {
      const old_password = q('#cpOld').value;
      const new_password = q('#cpNew').value;
      const confirm      = q('#cpConfirm').value;
      const resultDiv    = q('#cpResult');

      if (!old_password || !new_password || !confirm) {
        resultDiv.innerHTML = '<div class="form-error">Semua field wajib diisi</div>'; return;
      }
      if (new_password !== confirm) {
        resultDiv.innerHTML = '<div class="form-error">Konfirmasi password tidak cocok</div>'; return;
      }

      const btn = q('#cpSubmitBtn');
      btn.disabled = true;

      const res = await api('users.php', 'POST', {
        action: 'change_own_password',
        old_password,
        new_password,
      });

      btn.disabled = false;
      if (res.success) {
        toast(res.message, 'success');
        closeModal();
      } else {
        resultDiv.innerHTML = `<div class="form-error">${res.error}</div>`;
      }
    });
  });

  // Initial route
  const hash = location.hash.replace('#', '') || 'dashboard';
  navigate(hash);
}

function initClock() {
  const el = q('#topClock');
  if (!el) return;
  const update = () => {
    el.textContent = new Date().toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
  };
  update();
  setInterval(update, 1000);
}

function initSidebar() {
  const toggle = q('#menuToggle');
  const sidebar = q('#sidebar');
  const overlay = document.createElement('div');
  overlay.className = 'sidebar-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.5);display:none;';
  document.body.appendChild(overlay);

  toggle?.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.style.display = sidebar.classList.contains('open') ? 'block' : 'none';
  });
  overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.style.display = 'none';
  });
  sidebar.addEventListener('click', () => {
    overlay.style.display = 'none';
  })
}

function initNavigation() {
  qAll('.nav-item').forEach(a => {
    a.addEventListener('click', (e) => {
      e.preventDefault();
      const page = a.dataset.page;
      if (!WMS.allowed.includes(page)) { toast('Akses tidak diizinkan', 'error'); return; }
      navigate(page);
      location.hash = page;
      // Close sidebar on mobile
      q('#sidebar')?.classList.remove('open');
    });
  });
  window.addEventListener('hashchange', () => {
    const page = location.hash.replace('#','') || 'dashboard';
    navigate(page);
  });
}

function navigate(page) {
  if (!WMS.allowed.includes(page) && page !== 'dashboard') {
    page = 'dashboard';
  }
  // Set active nav
  qAll('.nav-item').forEach(a => a.classList.toggle('active', a.dataset.page === page));
  // Set title
  const titles = {
    dashboard: 'Dashboard', inbound: 'Inbound', outbound: 'Outbound',
    softcase: 'Softcase Check', moving: 'Moving', stock: 'Stock Overview',
    movements: 'Movements', 'softcase-monitoring': 'Softcase Monitoring', users: 'User Management',
  };
  setPageTitle(titles[page] || page);

  const content = q('#mainContent');
  content.innerHTML = '<div class="page-loader"><div class="loader-ring"></div></div>';

  const pages = { dashboard, inbound, outbound, softcase, moving, stock, movements, 'softcase-monitoring': softcaseMonitoring, users };
  const fn = pages[page];
  if (fn) fn(); else content.innerHTML = '<p style="color:var(--text-muted);padding:40px">Halaman tidak ditemukan.</p>';
}

/* ═══════════════════════════════════════════════════════════
   DASHBOARD
   ═══════════════════════════════════════════════════════════ */
async function dashboard() {
  const data = await api('dashboard.php');
  if (!data.success) { showError(data.error); return; }

  const { stats, recent_in, recent_out, softcase: sc, today } = data;
  const amb = stats.ambient;
  const chi = stats.chiller;
  const todayIn  = today.inbound  || { count:0, qty:0 };
  const todayOut = today.outbound || { count:0, qty:0 };

  setContent(`
    <div class="section">

      <!-- Stat cards -->
      <div class="grid grid-4" style="margin-bottom:16px">
        ${statCard('LSN Ambient', amb.count + ' Pallets',
          `Kapasitas: ${amb.capacity}`,
          `<div class="occ-bar"><div class="occ-fill ${amb.occupancy>=90?'danger':amb.occupancy>=70?'warning':''}"
            style="width:${Math.min(amb.occupancy,100)}%"></div></div>
           <div class="card-sub" style="margin-top:6px">Occupancy: ${amb.occupancy}%</div>`,
          svgBox())}
        ${statCard('LSN Chiller', chi.count + ' Pallets',
          `Kapasitas: ${chi.capacity}`,
          `<div class="occ-bar"><div class="occ-fill ${chi.occupancy>=90?'danger':chi.occupancy>=70?'warning':''}"
            style="width:${Math.min(chi.occupancy,100)}%"></div></div>
           <div class="card-sub" style="margin-top:6px">Occupancy: ${chi.occupancy}%</div>`,
          svgBox())}
        ${statCard('Inbound Hari Ini', todayIn.count + ' Txn', `${todayIn.qty||0} CTN masuk`, '', svgArrowDown())}
        ${statCard('Outbound Hari Ini', todayOut.count + ' Txn', `${todayOut.qty||0} CTN keluar`, '', svgArrowUp())}
      </div>

      <!-- Chart -->
      <div class="card" style="margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px">
          <div class="card-title" style="margin:0">Inbound vs Outbound (Customer)</div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <select id="chartMode" class="filter-select" style="width:110px">
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
              <option value="yearly">Yearly</option>
            </select>
            <div id="chartRangeInputs" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"></div>
            <button class="btn btn-secondary btn-sm" id="chartApplyBtn">Show Data</button>
          </div>
        </div>
        <div class="chart-container" style="height:220px"><canvas id="chartMain"></canvas></div>
      </div>

      <!-- Recent tables -->
      <div class="grid grid-2">
        <div class="section">
          <div class="section-header"><div class="section-title">Inbound — 3 Hari Terakhir</div></div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Batch</th><th>Qty</th><th>Kg</th><th>Dari</th><th>Waktu</th></tr></thead>
              <tbody>${recent_in.length ? recent_in.map(r=>`
                <tr>
                  <td>${r.batch||'-'}</td>
                  <td class="mono">${r.quantity} ${r.uom}</td>
                  <td class="mono">${fNum(r.quantity_kg)} kg</td>
                  <td class="txt-muted">${r.source_location||'-'}</td>
                  <td class="mono txt-muted">${fDateTime(r.created_at)}</td>
                </tr>`).join('') : '<tr class="empty-row"><td colspan="6">Tidak ada data</td></tr>'}</tbody>
            </table>
          </div>
        </div>
        <div class="section">
          <div class="section-header"><div class="section-title">Outbound — 3 Hari Terakhir</div></div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Batch</th><th>Qty</th><th>Kg</th><th>Ke</th><th>Waktu</th></tr></thead>
              <tbody>${recent_out.length ? recent_out.map(r=>`
                <tr>
                  <td>${r.batch||'-'}</td>
                  <td class="mono">${r.quantity} ${r.uom}</td>
                  <td class="mono">${fNum(r.quantity_kg)} kg</td>
                  <td class="txt-muted">${r.destination_location||'-'}</td>
                  <td class="mono txt-muted">${fDateTime(r.created_at)}</td>
                </tr>`).join('') : '<tr class="empty-row"><td colspan="6">Tidak ada data</td></tr>'}</tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Softcase -->
      <div class="section" style="margin-top:20px">
        <div class="section-header"><div class="section-title">Softcase — Terbaru</div></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Batch</th><th>Total Pallet</th><th>Qty Checked</th><th>Qty Soft</th><th>Terakhir Dicek</th></tr></thead>
            <tbody>${sc.length ? sc.map(r=>`
              <tr>
                <td>${r.batch}</td>
                <td class="mono">${r.total_pallet}</td>
                <td class="mono">${r.qty_checked} ${r.uom_checked||'CTN'}</td>
                <td class="mono">${r.qty_soft||0} ${r.uom_soft||'CTN'}</td>
                <td class="mono txt-muted">${fDateTime(r.checked_at)}</td>
              </tr>`).join('') : '<tr class="empty-row"><td colspan="5">Belum ada data softcase</td></tr>'}</tbody>
          </table>
        </div>
      </div>
    </div>
  `);

  // Init chart controls
  const modeEl   = q('#chartMode');
  const rangeEl  = q('#chartRangeInputs');
  const applyBtn = q('#chartApplyBtn');

  function buildRangeInputs(mode) {
    const today     = new Date().toISOString().split('T')[0];
    const thisMonth = today.slice(0,7);
    const lastMonth = new Date(new Date().setMonth(new Date().getMonth()-1)).toISOString().slice(0,7);
    const thisYear  = new Date().getFullYear();

    const map = {
      daily:   `<label style="font-size:11px;color:var(--text-muted)">From</label>
                <input type="date" id="cDateFrom" class="filter-input" style="width:140px"
                  value="${new Date(Date.now()-6*864e5).toISOString().split('T')[0]}">`,
      weekly:  `<label style="font-size:11px;color:var(--text-muted)">Month</label>
                <input type="month" id="cMonthFrom" class="filter-input" style="width:130px" value="${lastMonth}">
                <span style="color:var(--text-muted)">–</span>
                <input type="month" id="cMonthTo" class="filter-input" style="width:130px" value="${thisMonth}">`,
      monthly: `<label style="font-size:11px;color:var(--text-muted)">Month</label>
                <input type="month" id="cMonthFrom" class="filter-input" style="width:130px"
                  value="${new Date(Date.now()-11*30*864e5).toISOString().slice(0,7)}">
                <span style="color:var(--text-muted)">–</span>
                <input type="month" id="cMonthTo" class="filter-input" style="width:130px" value="${thisMonth}">`,
      yearly:  `<label style="font-size:11px;color:var(--text-muted)">Year</label>
                <input type="number" id="cYearFrom" class="filter-input" style="width:80px"
                  value="${thisYear-4}" min="2000" max="${thisYear}">
                <span style="color:var(--text-muted)">–</span>
                <input type="number" id="cYearTo" class="filter-input" style="width:80px"
                  value="${thisYear}" min="2000" max="${thisYear}">`,
    };
    rangeEl.innerHTML = map[mode] || '';
  }

  function getChartParams(mode) {
    const p = { mode };
    if (mode === 'daily')   p.date_from  = q('#cDateFrom')?.value  || '';
    if (mode === 'weekly' || mode === 'monthly') {
      p.month_from = q('#cMonthFrom')?.value || '';
      p.month_to   = q('#cMonthTo')?.value   || '';
    }
    if (mode === 'yearly') {
      p.year_from = q('#cYearFrom')?.value || '';
      p.year_to   = q('#cYearTo')?.value   || '';
    }
    return p;
  }

  async function loadChart() {
    const mode = modeEl.value;
    const params = new URLSearchParams(getChartParams(mode));
    const cd = await api(`chart.php?${params}`, 'GET');
    if (!cd.success) { toast('Gagal memuat chart', 'error'); return; }
    renderMainChart(cd.labels, cd.inbound, cd.outbound);
  }

  buildRangeInputs('daily');
  modeEl.addEventListener('change', () => buildRangeInputs(modeEl.value));
  applyBtn.addEventListener('click', loadChart);

  // Load default chart
  loadChart();
}

function renderMainChart(labels, inboundData, outboundData) {
  const canvas = q('#chartMain');
  if (!canvas) return;
  if (state.charts['main']) state.charts['main'].destroy();
  state.charts['main'] = new Chart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Inbound (CTN)',
          data: inboundData,
          backgroundColor: 'rgba(37,99,235,.7)',
          borderColor: '#2563eb',
          borderWidth: 1.5,
          borderRadius: 4,
        },
        {
          label: 'Outbound ke Customer (CTN)',
          data: outboundData,
          backgroundColor: 'rgba(220,38,38,.7)',
          borderColor: '#dc2626',
          borderWidth: 1.5,
          borderRadius: 4,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          labels: { color: '#6b829a', font: { family: 'IBM Plex Mono', size: 11 } }
        }
      },
      scales: {
        x: { grid: { color: 'rgba(220,220,220,.15)' }, ticks: { color: '#6b829a', font: { family: 'IBM Plex Mono', size: 11 } } },
        y: { grid: { color: 'rgba(220,220,220,.15)' }, ticks: { color: '#6b829a', font: { family: 'IBM Plex Mono', size: 11 } }, beginAtZero: true },
      }
    }
  });
}

function statCard(title, value, sub, extra = '', icon = '') {
  return `<div class="card stat-card">
    <div class="stat-icon">${icon}</div>
    <div class="card-title">${title}</div>
    <div class="card-value">${value}</div>
    <div class="card-sub">${sub}</div>
    ${extra}
  </div>`;
}

/* ═══════════════════════════════════════════════════════════
   INBOUND
   ═══════════════════════════════════════════════════════════ */
function inbound() {
  state.inboundRows = [];
  setContent(`
    <div class="grid" style="grid-template-columns:1 1fr;gap:20px">
      <div class="panel">
        <div class="panel-header"><div class="panel-title">Form Inbound</div></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field-group">
              <label class="field-label">Inbound From *</label>
              <input id="ibFrom" class="field-input" list="ibFromList" placeholder="Ketik atau pilih..." autocomplete="off">
              <datalist id="ibFromList">
                <option value="Production">
                <option value="WH External">
                <option value="Customer Reject">
              </datalist>
            </div>
            <div class="field-group">
              <label class="field-label">Storage Location *</label>
              <input id="ibStorage" class="field-input" list="ibToList" placeholder="Ketik atau pilih..." autocomplete="off">
              <datalist id="ibToList">
                <option value="LSN Ambient">
                <option value="LSN Chiller">
              </datalist>
            </div>
            <div class="field-group"><label class="field-label">Product Type *</label>
              <select id="ibProductType" class="field-select"><option value="">-- Pilih --</option>${PRODUCT_TYPES.map(p=>`<option>${p}</option>`).join('')}</select>
            </div>
            <div class="field-group"><label class="field-label">UOM</label>
              <select id="ibUom" class="field-select">${UOM_TYPES.map(u=>`<option>${u}</option>`).join('')}</select>
            </div>
          </div>
          <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
          <div class="form-grid">
            <div class="field-group"><label class="field-label">Batch *</label><input id="ibBatch" class="field-input" placeholder="Batch number"></div>
            <div class="field-group"><label class="field-label">Pallet No *</label><input id="ibPallet" class="field-input" placeholder="01" maxlength="3"></div>
            <div class="field-group"><label class="field-label">Quantity *</label><input id="ibQty" class="field-input" type="number" min="1" placeholder="0"></div>
            <div class="field-group"><label class="field-label">Bin Location *</label><input id="ibBin" class="field-input" placeholder="A-01-A-01"></div>
          </div>
          <div class="field-group"><label class="field-label">Remarks</label><input id="ibRemarks" class="field-input" placeholder="Opsional"></div>
          <div class="form-actions">
            <button class="btn btn-secondary" id="ibAddBtn">
              ${svgPlus()} Tambah ke Tabel
            </button>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">Tabel Sementara</div>
          <span id="ibRowCount" class="badge badge-gray">0 rows</span>
        </div>
        <div class="panel-body" style="padding:0">
          <div id="ibSummary" class="temp-summary hidden" style="margin:12px"></div>
          <div class="table-wrap" style="border:none;border-radius:0">
            <table>
              <thead><tr><th>#</th><th>Batch</th><th>Type</th><th>Pallet</th><th>Qty</th><th>Bin</th><th></th></tr></thead>
              <tbody id="ibTableBody"><tr class="empty-row"><td colspan="6">Tambahkan baris terlebih dahulu</td></tr></tbody>
            </table>
          </div>
        </div>
        <div class="panel-header" style="border-top:1px solid var(--border);border-bottom:none;justify-content:flex-end">
          <div style="display:flex;gap:8px">
            <button class="btn btn-secondary btn-sm" id="ibClearBtn">Hapus Semua</button>
            <button class="btn btn-primary" id="ibSubmitBtn" disabled>
              ${svgSend()} Submit Inbound
            </button>
          </div>
        </div>
      </div>
    </div>
  `);

  q('#ibBatch')?.addEventListener('input', function() {
    this.value = this.value.toUpperCase();
    if (this.value.length >= 10) q('#ibPallet')?.focus();
  });
  q('#ibPallet')?.addEventListener('input', function() {
    if (this.value.length >= 2) q('#ibQty')?.focus();
  });
  q('#ibQty')?.addEventListener('input', function() {
    if (this.value.toString().length >= 2) q('#ibBin')?.focus();
  });
  q('#ibBin')?.addEventListener('input', function() {
    this.value = this.value.toUpperCase();
    if (this.value.length >= 9) q('#ibAddBtn')?.focus();
  });

  autoSelect();

  q('#ibBatch').addEventListener('input', ibAutoFill);
  
  async function ibAutoFill() {
    const isFromExt = q('#ibFrom').value === 'WH External';
    if(!isFromExt) return;
    if(this.value.length !== 10) return;
    const batch = q('#ibBatch')?.value.trim();
    const location = 'Jasco';
    const data = await api(`check_jasco.php?batch=${encodeURIComponent(batch)}&bin_location=${encodeURIComponent(location)}`);

    if(!data.success || !data.data.length) return;
    const jascoStock = data.data;
    if(jascoStock.length === 1) {
      q('#ibPallet').value = jascoStock[0].pallet_number;
      q('#ibBin').value = jascoStock[0].bin_location;
      q('#ibQty').value = jascoStock[0].quantity;
      q('#ibProductType').value = jascoStock[0].product_type;
      q('#ibUom').value = jascoStock[0].uom;
    } else {
      openModal(`Pilih Bin ${batch}`, `
      <div style="display:flex;flex-direction:column;gap:8px">
        ${jascoStock.map(b => {
          const isAdded = state.inboundRows.some(r => r.batch === b.batch && r.pallet === b.pallet_number);
          return `
            <button class="btn btn-secondary" 
              style="justify-content:flex-start;font-family:var(--font-mono);${isAdded ? 'opacity:.4;cursor:not-allowed' : ''}"
              onclick="${isAdded ? '' : `ibFillBin('${b.pallet_number}','${b.bin_location}',${b.quantity},event)`}"
              ${isAdded ? 'disabled' : ''}>
              ${b.pallet_number} - ${b.bin_location} ${b.quantity} ${b.uom}
              ${isAdded ? '<span style="margin-left:auto;font-size:10px;color:var(--text-muted)">sudah ditambahkan</span>' : ''}
            </button>`;
        }).join('')}
      </div>
    `);
    }
  }
  window.ibFillBin = (pallet, bin, qty, e) => {
  e.preventDefault();
  q('#ibPallet').value = pallet;
  q('#ibBin').value = bin;
  q('#ibQty').value = qty;
  closeModal();
  };

  q('#ibAddBtn').addEventListener('click', ibAddRow);
  q('#ibClearBtn').addEventListener('click', () => { state.inboundRows = []; ibRenderTable(); });
  q('#ibSubmitBtn').addEventListener('click', ibSubmit);

  // Enter key on last row input → add
  q('#ibBin').addEventListener('keydown', e => { if (e.key === 'Enter') ibAddRow(); });
}

async function ibAddRow() {
  const batch   = q('#ibBatch').value.trim();
  const pallet  = q('#ibPallet').value.trim().padStart(2,'0') || '01';
  const qty     = parseInt(q('#ibQty').value) || 0;
  const bin     = q('#ibBin').value.trim();
  const from    = q('#ibFrom').value.trim();
  const storage = q('#ibStorage').value.trim();
  const ptype   = q('#ibProductType').value;

  if (!from || !storage || !ptype) { toast('Isi Inbound From, Storage Location, dan Product Type terlebih dahulu', 'warning'); return; }
  if (!batch) { toast('Batch wajib diisi', 'warning'); q('#ibBatch').focus(); return; }
  if (qty <= 0) { toast('Quantity harus lebih dari 0', 'warning'); q('#ibQty').focus(); return; }

  // Cek apakah batch + pallet sudah ada di database
  let isFromExt = q('#ibFrom').value === 'WH External'
  const check = await api(`check_inbound.php?batch=${encodeURIComponent(batch)}&pallet=${encodeURIComponent(pallet)}`);
  if (!isFromExt && check.success && check.data.length > 0) {
    const palls = check.data;
    openModal(`PERINGATAN`, `
      <div style="display:flex;flex-direction:column;gap:8px">
        ${palls.map(b => `
          Batch : ${b.batch} <br> Pallet : ${b.pallet_number} <br> sudah ada di ${b.location_type} ${b.bin_location}! <br> input Nomor Pallet yang sesuai!
          `).join('')}
      </div>
    `);
    
    q('#ibPallet').value = '';
    return;
  }

  const finalBin = bin || 'STAGE';

  state.inboundRows.push({ batch, ptype, pallet, quantity: qty, bin_location: finalBin });
  ibRenderTable();

  // Reset per-row fields, focus back to pallet
  q('#ibPallet').value = '';
  q('#ibQty').value    = '';
  q('#ibBin').value    = '';
  q('#ibPallet').focus();
}

function ibRenderTable() {
  const tbody  = q('#ibTableBody');
  const rows   = state.inboundRows;
  const count  = q('#ibRowCount');
  const submit = q('#ibSubmitBtn');
  const sumDiv = q('#ibSummary');

  count.textContent  = `${rows.length} rows`;
  submit.disabled    = rows.length === 0;

  if (!rows.length) {
    tbody.innerHTML = '<tr class="empty-row"><td colspan="6">Tambahkan baris terlebih dahulu</td></tr>';
    sumDiv.classList.add('hidden');
    return;
  }

  const totalQty = rows.reduce((s,r) => s + r.quantity, 0);
  sumDiv.classList.remove('hidden');
  sumDiv.innerHTML = `<span class="temp-summary-item">Total Baris: <strong>${rows.length}</strong></span>
    <span class="temp-summary-item">Total Qty: <strong>${totalQty} CTN</strong></span>`;

  tbody.innerHTML = rows.map((r,i) => `
    <tr>
      <td class="mono txt-muted">${i+1}</td>
      <td>${r.batch}</td>
      <td class="mono">${r.ptype}</td>
      <td class="mono">${r.pallet}</td>
      <td class="mono">${r.quantity}</td>
      <td class="mono">${r.bin_location}</td>
      <td>
        <button class="btn btn-ghost btn-sm" onclick="ibDeleteRow(${i})" title="Hapus">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
        </button>
      </td>
    </tr>`).join('');
}

window.ibDeleteRow = (i) => { state.inboundRows.splice(i,1); ibRenderTable(); };

async function ibSubmit() {
  if (!state.inboundRows.length) return;
  const btn = q('#ibSubmitBtn');
  btn.disabled = true; btn.innerHTML = svgSpinner() + ' Submitting...';

  const payload = {
    inbound_from:     q('#ibFrom').value.trim(),
    storage_location: q('#ibStorage').value.trim(),
    product_type:     q('#ibProductType').value,
    uom:              q('#ibUom').value,
    remarks:          q('#ibRemarks').value.trim(),
    rows:             state.inboundRows,
  };

  const res = await api('inbound.php', 'POST', payload);
  btn.disabled = false; btn.innerHTML = svgSend() + ' Submit Inbound';

  if (res.success) {
    toast(res.message, 'success');
    state.inboundRows = [];
    ibRenderTable();
    // Reset header form
    ['ibFrom','ibStorage','ibBatch','ibPallet','ibQty','ibBin','ibRemarks'].forEach(id => { const el = q(`#${id}`); if(el) el.value=''; });
    q('#ibProductType').value = '';
  } else {
    toast(res.error || 'Submit gagal', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   OUTBOUND
   ═══════════════════════════════════════════════════════════ */
function outbound() {
  state.outboundRows = [];
  setContent(`
    <div class="grid" style="grid-template-columns:1 1fr;gap:20px">
      <div class="panel">
        <div class="panel-header"><div class="panel-title">Form Outbound</div></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field-group">
              <label class="field-label">Destination</label>
              <select class="field-select" id="obDest">
                <option value="">--Tujuan--</option>
                <option value="Customer Lokal">Customer Lokal</option>
                <option value="Customer Export">Customer Export</option>
                <option value="WH External">WH External</option>
                <option value="Production">Production</option>
                <option value="Other">Tulis di remark</option>
              </select> 
            </div>
          </div>
          <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
          <div class="form-grid">
            <div class="field-group"><label class="field-label">Batch *</label><input id="obBatch" class="field-input" placeholder="Batch number"></div>
            <div class="field-group"><label class="field-label">Pallet No *</label><input id="obPallet" class="field-input" placeholder="01" maxlength="3"></div>
            <div class="field-group"><label class="field-label">Quantity *</label><input id="obQty" class="field-input" type="number" min="1" placeholder="0"></div>
            <div class="field-group"><label class="field-label">Bin Location *</label><input id="obBin" class="field-input" placeholder="A01-01"></div>
          </div>
          <div class="field-group"><label class="field-label">Remarks (Nama Customer, Nopol, dan lain-lain)</label><input id="obRemarks" class="field-input" placeholder="Opsional"></div>
          <div class="form-actions">
            <button class="btn btn-secondary" id="obAddBtn">${svgPlus()} Tambah ke Tabel</button>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">Tabel Sementara</div>
          <span id="obRowCount" class="badge badge-gray">0 rows</span>
        </div>
        <div class="panel-body" style="padding:0">
          <div id="obSummary" class="temp-summary hidden" style="margin:12px"></div>
          <div class="table-wrap" style="border:none;border-radius:0">
            <table>
              <thead><tr><th>#</th><th>Batch</th><th>Pallet</th><th>Qty</th><th>Bin</th><th></th></tr></thead>
              <tbody id="obTableBody"><tr class="empty-row"><td colspan="6">Tambahkan baris terlebih dahulu</td></tr></tbody>
            </table>
          </div>
        </div>
        <div class="panel-header" style="border-top:1px solid var(--border);border-bottom:none;justify-content:flex-end">
          <div style="display:flex;gap:8px">
            <button class="btn btn-secondary btn-sm" id="obClearBtn">Hapus Semua</button>
            <button class="btn btn-primary" id="obSubmitBtn" disabled>${svgSend()} Submit Outbound</button>
          </div>
        </div>
      </div>
    </div>
  `);

  autoSelect();
  q('#obAddBtn').addEventListener('click', obAddRow);

  q('#obBatch')?.addEventListener('input', obAutoFill);

  // Autofill qty & bin dari batch + pallet
  async function obAutoFill() {
    if(this.value.length !== 10) return;
    const batch  = q('#obBatch')?.value.trim();
    const data = await api(`bin_lookup.php?batch=${encodeURIComponent(batch)}`);
    if (!data.success || !data.data.length) return;
    const bins = data.data;
    if (bins.length === 1) {
      q('#obPallet').value = bins[0].pallet_number;
      q('#obBin').value = bins[0].bin_location;
      q('#obQty').value = bins[0].quantity;
    } else {
      // Multiple bins: tampilkan pilihan
      openModal(`Pilih Bin — ${batch}`, `
        <div style="display:flex;flex-direction:column;gap:8px">
          ${bins.map(b => {
            const isAdded = state.outboundRows.some(r => r.batch === b.batch && r.pallet === b.pallet_number);
            return `
              <button class="btn btn-secondary" 
                style="justify-content:flex-start;font-family:var(--font-mono);${isAdded ? 'opacity:.4;cursor:not-allowed' : ''}"
                onclick="${isAdded ? '' : `obFillBin('${b.pallet_number}','${b.bin_location}',${b.quantity},event)`}"
                ${isAdded ? 'disabled' : ''}>
                ${b.pallet_number} - ${b.bin_location} — ${b.quantity} ${b.uom}
                ${isAdded ? '<span style="margin-left:auto;font-size:10px;color:var(--text-muted)">sudah ditambahkan</span>' : ''}
              </button>`;
          }).join('')}
        </div>
      `);
    }
  }
  window.obFillBin = (pallet, bin, qty, e) => {
    e.preventDefault();
    q('#obPallet').value = pallet;
    q('#obBin').value = bin;
    q('#obQty').value = qty;
    closeModal();
  };

  q('#obClearBtn').addEventListener('click', () => { state.outboundRows = []; obRenderTable(); });
  q('#obSubmitBtn').addEventListener('click', obSubmit);
  q('#obBin').addEventListener('keydown', e => { if (e.key === 'Enter') obAddRow(); });
}

function obAddRow() {
  const dest  = q('#obDest').value;
  if (!dest) { toast('Isi Destination terlebih dahulu', 'warning'); return; }

  const batch  = q('#obBatch').value.trim();
  const pallet = q('#obPallet').value.trim().padStart(2,'0') || '01';
  const qty    = parseInt(q('#obQty').value) || 0;
  const bin    = q('#obBin').value.trim();

  if (!batch)  { toast('Batch wajib diisi', 'warning'); q('#obBatch').focus(); return; }
  if (qty <= 0){ toast('Quantity harus lebih dari 0', 'warning'); q('#obQty').focus(); return; }
  if (!bin)    { toast('Bin Location wajib diisi', 'warning'); q('#obBin').focus(); return; }

  state.outboundRows.push({ batch, pallet, quantity: qty, bin_location: bin });
  obRenderTable();
  q('#obPallet').value = ''; q('#obQty').value = ''; q('#obBin').value = '';
  q('#obBatch').focus();
}

function obRenderTable() {
  const tbody  = q('#obTableBody');
  const rows   = state.outboundRows;
  q('#obRowCount').textContent = `${rows.length} rows`;
  q('#obSubmitBtn').disabled   = rows.length === 0;

  if (!rows.length) {
    tbody.innerHTML = '<tr class="empty-row"><td colspan="6">Tambahkan baris terlebih dahulu</td></tr>';
    q('#obSummary').classList.add('hidden'); return;
  }

  const totalQty = rows.reduce((s,r) => s+r.quantity, 0);
  const sumDiv = q('#obSummary');
  sumDiv.classList.remove('hidden');
  sumDiv.innerHTML = `<span class="temp-summary-item">Total Baris: <strong>${rows.length}</strong></span><span class="temp-summary-item">Total Qty: <strong>${totalQty} CTN</strong></span>`;

  tbody.innerHTML = rows.map((r,i) => `
    <tr><td class="mono txt-muted">${i+1}</td><td>${r.batch}</td><td class="mono">${r.pallet}</td>
    <td class="mono">${r.quantity}</td><td class="mono">${r.bin_location}</td>
    <td><button class="btn btn-ghost btn-sm" onclick="obDeleteRow(${i})">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
    </button></td></tr>`).join('');
}

window.obDeleteRow = (i) => { state.outboundRows.splice(i,1); obRenderTable(); };

async function obSubmit() {
  if (!state.outboundRows.length) return;
  const btn = q('#obSubmitBtn');
  btn.disabled = true; btn.innerHTML = svgSpinner() + ' Submitting...';

  const res = await api('outbound.php', 'POST', {
    destination:  q('#obDest').value.trim(),
    remarks:      q('#obRemarks').value.trim(),
    rows:         state.outboundRows,
  });

  btn.disabled = false; btn.innerHTML = svgSend() + ' Submit Outbound';

  if (res.success) {
    toast(res.message, 'success'); state.outboundRows = []; obRenderTable();
    ['obDest','obBatch','obPallet','obQty','obBin','obRemarks'].forEach(id => { const el=q(`#${id}`); if(el) el.value=''; });
  } else {
    toast(res.error || 'Submit gagal', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   SOFTCASE CHECK
   ═══════════════════════════════════════════════════════════ */
function softcase() {
  setContent(`
    <div class="panel" style="max-width:600px">
      <div class="panel-header"><div class="panel-title">Softcase Check</div></div>
      <div class="panel-body">
        <div id="scResult"></div>
        <div class="form-row">
          <div class="field-group"><label class="field-label">Batch *</label><input id="scBatch" class="field-input" placeholder="Batch number"></div>
          <div class="field-group"><label class="field-label">Pallet *</label><input id="scPallet" class="field-input" placeholder="01" maxlength="3"></div>
        </div>
        <div class="form-row">
          <div class="field-group"><label class="field-label">Qty Checked *</label><input id="scQtyChecked" class="field-input" type="number" min="0" placeholder="0"></div>
          <div class="field-group"><label class="field-label">UOM Checked</label>
            <select id="scUomChecked" class="field-select">${UOM_TYPES.map(u=>`<option>${u}</option>`).join('')}</select>
          </div>
        </div>
        <div class="form-row">
          <div class="field-group"><label class="field-label">Qty Soft</label><input id="scQtySoft" class="field-input" type="number" min="0" placeholder="0"></div>
          <div class="field-group"><label class="field-label">UOM Soft</label>
            <select id="scUomSoft" class="field-select">${UOM_TYPES.map(u=>`<option>${u}</option>`).join('')}</select>
          </div>
        </div>
        <div class="field-group"><label class="field-label">Remarks</label><input id="scRemarks" class="field-input" placeholder="Opsional"></div>
        <div class="form-actions">
          <button class="btn btn-primary" id="scSubmitBtn">${svgSend()} Submit Softcase</button>
          <button class="btn btn-secondary" id="scResetBtn">Reset</button>
        </div>
      </div>
    </div>
  `);
  autoSelect();

  async function scAutoFill() {
  if(this.value.length !== 10) return;
  const batch  = q('#scBatch')?.value.trim();
  const data = await api(`bin_lookup.php?batch=${encodeURIComponent(batch)}`);
  if (!data.success || !data.data.length) return;
  const bins = data.data;
    if (bins.length === 1) {
      q('#scPallet').value = bins[0].pallet_number;
      q('#scQtyChecked').value = bins[0].quantity;
    } else {
      // Multiple bins: tampilkan pilihan
      openModal(`Pilih No. Pallet & Bin — ${batch}`, `
        <div style="display:flex;flex-direction:column;gap:8px">
          ${bins.map(b => `
            <button class="btn btn-secondary" style="justify-content:flex-start;font-family:var(--font-mono)"
              onclick="scFillQty('${b.pallet_number}',${b.quantity},event)">
              ${b.pallet_number} - ${b.quantity} ${b.uom} - ${b.bin_location}
            </button>`).join('')}
        </div>
      `);
    }
  }
  window.scFillQty = (pallet, qty, e) => {
    e.preventDefault();
    q('#scPallet').value = pallet;
    q('#scQtyChecked').value = qty;
    closeModal();
  };

  q('#scBatch')?.addEventListener('input', scAutoFill);  


  q('#scSubmitBtn').addEventListener('click', scSubmit);
  q('#scResetBtn').addEventListener('click', () => {
    ['scBatch','scPallet','scQtyChecked','scQtySoft','scRemarks'].forEach(id => { const el=q(`#${id}`); if(el) el.value=''; });
    q('#scResult').innerHTML = '';
  });
}

async function scSubmit() {
  const batch = q('#scBatch').value.trim();
  const pallet = q('#scPallet').value.trim();
  if (!batch || !pallet) { toast('Batch dan Pallet wajib diisi', 'warning'); return; }

  const btn = q('#scSubmitBtn');
  btn.disabled = true; btn.innerHTML = svgSpinner() + ' Submitting...';

  const res = await api('softcase.php', 'POST', {
    batch, pallet,
    qty_checked:  parseInt(q('#scQtyChecked').value) || 0,
    uom_checked:  q('#scUomChecked').value,
    qty_soft:     parseInt(q('#scQtySoft').value) || 0,
    uom_soft:     q('#scUomSoft').value,
    remarks:      q('#scRemarks').value.trim(),
  });

  btn.disabled = false; btn.innerHTML = svgSend() + ' Submit Softcase';

  const resultDiv = q('#scResult');
  if (res.success) {
    resultDiv.innerHTML = `<div class="form-success">${res.message}</div>`;
    toast(res.message, 'success');
    ['scBatch','scPallet','scQtyChecked','scQtySoft','scRemarks'].forEach(id => { const el=q(`#${id}`); if(el) el.value=''; });
  } else {
    resultDiv.innerHTML = `<div class="form-error">${res.error}</div>`;
    toast(res.error || 'Submit gagal', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   MOVING
   ═══════════════════════════════════════════════════════════ */
function moving() {
  setContent(`
    <div class="panel" style="max-width:600px">
      <div class="panel-header"><div class="panel-title">Moving (Bin to Bin)</div></div>
      <div class="panel-body">
        <div id="mvResult"></div>
        <div class="form-row">
          <div class="field-group"><label class="field-label">Source Bin *</label><input id="mvSrc" class="field-input" placeholder="A-01-A-01"></div>
          <div class="field-group"><label class="field-label">Destination Bin *</label><input id="mvDst" class="field-input" placeholder="B-01-A-01"></div>
        </div>
        <div class="form-row">
          <div class="field-group"><label class="field-label">Batch *</label><input id="mvBatch" class="field-input" placeholder="Batch number"></div>
          <div class="field-group"><label class="field-label">Pallet *</label><input id="mvPallet" class="field-input" placeholder="01" maxlength="3"></div>
        </div>
        <div class="form-row">
          <div class="field-group"><label class="field-label">Quantity *</label><input id="mvQty" class="field-input" type="number" min="1" placeholder="0"></div>
          <div class="field-group"><label class="field-label">UOM</label>
            <select id="mvUom" class="field-select">${UOM_TYPES.map(u=>`<option>${u}</option>`).join('')}</select>
          </div>
        </div>
        <div class="field-group"><label class="field-label">Remarks</label><input id="mvRemarks" class="field-input" placeholder="Opsional"></div>
        <div class="form-actions">
          <button class="btn btn-primary" id="mvSubmitBtn">${svgSend()} Submit Moving</button>
          <button class="btn btn-secondary" id="mvResetBtn">Reset</button>
        </div>
      </div>
    </div>
  `);
  autoSelect();
  q('#mvSubmitBtn').addEventListener('click', mvSubmit);

  // Autofill dari source bin (trigger ketika length = 9)
  q('#mvSrc')?.addEventListener('input', async function() {
    if (this.value.length !== 9) return;
    const data = await api(`bin_lookup.php?bin=${encodeURIComponent(this.value)}`);
    console.log(data);
    if (!data.success || !data.data.length) return;
    const bins = data.data;
    if (bins.length === 1) {
      q('#mvBatch').value  = bins[0].batch;
      q('#mvPallet').value = bins[0].pallet_number;
      q('#mvQty').value    = bins[0].quantity;
    } else {
      openModal(`Pilih Stock di ${this.value}`, `
        <div style="display:flex;flex-direction:column;gap:8px">
          ${bins.map(b => `
            <button class="btn btn-secondary" style="justify-content:flex-start;font-family:var(--font-mono)"
              onclick="mvFillbyBin('${b.batch}','${b.pallet_number}',${b.quantity},event)">
              ${b.batch} / Pallet ${b.pallet_number} — ${b.quantity} ${b.uom}
            </button>`).join('')}
        </div>
      `);
    }
  });
  window.mvFillbyBin = (batch, pallet, qty, e) => {
    e.preventDefault();
    q('#mvBatch').value  = batch;
    q('#mvPallet').value = pallet;
    q('#mvQty').value    = qty;
    closeModal();
  };

  async function mvAutoFill() {
    if(this.value.length !== 10) return;
    const batch  = q('#mvBatch')?.value.trim();
    const data = await api(`bin_lookup.php?batch=${encodeURIComponent(batch)}`);
    if (!data.success || !data.data.length) return;
    const bins = data.data;
      if (bins.length === 1) {
        q('#mvPallet').value = bins[0].pallet_number;
        q('#mvSrc').value = bins[0].bin_location;
        q('#mvQty').value = bins[0].quantity;
      } else {
        // Multiple bins: tampilkan pilihan
        openModal(`Pilih No. Pallet & Bin — ${batch}`, `
          <div style="display:flex;flex-direction:column;gap:8px">
            ${bins.map(b => `
              <button class="btn btn-secondary" style="justify-content:flex-start;font-family:var(--font-mono)"
                onclick="mvFillBin('${b.pallet_number}','${b.bin_location}',${b.quantity},event)">
                ${b.pallet_number} - ${b.bin_location} - ${b.quantity} ${b.uom}
              </button>`).join('')}
          </div>
        `);
      }
  }
  window.mvFillBin = (pallet, bin, qty, e) => {
    e.preventDefault();
    q('#mvPallet').value = pallet;
    q('#mvSrc').value = bin;
    q('#mvQty').value = qty;
    closeModal();
  };

  q('#mvBatch')?.addEventListener('input', mvAutoFill);

  q('#mvResetBtn').addEventListener('click', () => {
    ['mvSrc','mvDst','mvBatch','mvPallet','mvQty','mvRemarks'].forEach(id => { const el=q(`#${id}`); if(el) el.value=''; });
    q('#mvResult').innerHTML = '';
  });
}

async function mvSubmit() {
  const btn = q('#mvSubmitBtn');
  btn.disabled = true; btn.innerHTML = svgSpinner() + ' Submitting...';

  const res = await api('moving.php', 'POST', {
    source_bin:      q('#mvSrc').value.trim(),
    destination_bin: q('#mvDst').value.trim() || 'STAGE',
    batch:           q('#mvBatch').value.trim(),
    pallet:          q('#mvPallet').value.trim(),
    quantity:        parseInt(q('#mvQty').value) || 0,
    uom:             q('#mvUom').value,
    remarks:         q('#mvRemarks').value.trim(),
  });

  btn.disabled = false; btn.innerHTML = svgSend() + ' Submit Moving';
  const resultDiv = q('#mvResult');
  if (res.success) {
    resultDiv.innerHTML = `<div class="form-success">${res.message}</div>`;
    toast(res.message, 'success');
    ['mvSrc','mvDst','mvBatch','mvPallet','mvQty','mvRemarks'].forEach(id => { const el=q(`#${id}`); if(el) el.value=''; });
  } else {
    resultDiv.innerHTML = `<div class="form-error">${res.error}</div>`;
    toast(res.error || 'Submit gagal', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   STOCK OVERVIEW
   ═══════════════════════════════════════════════════════════ */
let stockFilters = {}, stockPage = 1;

async function stock(page = 1) {
  stockPage = page;
  if (page === 1) setContent(`
    <div class="panel">
      <div class="filters-bar">
        <div class="filter-field"><label>Batch</label><input class="filter-input" id="fStBatch" placeholder="Filter..."></div>
        <div class="filter-field"><label>Pallet</label><input class="filter-input" id="fStPallet" placeholder="Filter..."></div>
        <div class="filter-field"><label>Bin Location</label><input class="filter-input" id="fStBin" placeholder="Filter..."></div>
        <div class="filter-field"><label>Location Type</label><input class="filter-input" id="fStType" placeholder="Filter..."></div>
        <div class="filters-actions" style="padding-bottom:0">
          <button class="btn btn-ghost btn-sm" id="stResetBtn">Reset</button>
          <button class="btn btn-green btn-sm" id="stExportBtn">${svgDownload()} Export</button>
        </div>
      </div>
      <div id="stSummaryBar" style="padding:10px 16px;font-size:12px;color:var(--text-muted);border-bottom:1px solid var(--border);font-family:var(--font-mono)"></div>
      <div class="table-wrap" style="border:none;border-radius:0">
        <table>
          <thead><tr><th>#</th><th>Batch</th><th>Pallet</th><th>Qty</th><th>UOM</th><th>Weight</th><th>Sloc</th><th>Updated</th></tr></thead>
          <tbody id="stBody"><tr class="empty-row"><td colspan="8">Memuat...</td></tr></tbody>
        </table>
      </div>
      <div id="stPagination" style="padding:12px 16px"></div>
    </div>
  `);

  let searchTimer;
    ['fStBatch','fStPallet','fStBin','fStType'].forEach(id => {
      q(`#${id}`)?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
          stockFilters = {
            batch:         q('#fStBatch')?.value  || '',
            pallet_number: q('#fStPallet')?.value || '',
            bin_location:  q('#fStBin')?.value    || '',
            location_type: q('#fStType')?.value   || '',
          };
          stockPage = 1;
          stockFetchData();
        }, 400); // tunggu 400ms setelah user berhenti mengetik
      });
    });

  q('#stResetBtn')?.addEventListener('click', () => {
    stockFilters = {}; 
    ['fStBatch','fStPallet','fStBin','fStType'].forEach(id => { const el=q(`#${id}`); if(el) el.value=''; });
    stockPage = 1;
    stockFetchData();
  });
  q('#stExportBtn')?.addEventListener('click', () => {
    const p = new URLSearchParams({type:'stock', ...stockFilters});
    window.open(`api/export.php?${p}`, '_blank');
  });

  await stockFetchData()
}

async function stockFetchData() {
  const params = new URLSearchParams({ page: stockPage, ...stockFilters });
  const data   = await api(`stock.php?${params}`, 'GET');
  if (!data.success) { showError(data.error); return; }

  const offset = (stockPage - 1) * 50;
  const sumBar = q('#stSummaryBar');
  if (sumBar) sumBar.textContent = `Menampilkan ${data.data.length} dari ${data.pagination.total} batch | Total Qty: ${data.summary?.total_qty||0} CTN`;

  q('#stBody').innerHTML = data.data.length
    ? data.data.map((r,i) => `
        <tr style="cursor:pointer" onclick="stockShowBins('${r.batch}')" title="Klik untuk lihat detail bin">
          <td class="mono txt-muted">${offset+i+1}</td>
          <td><strong>${r.batch}</strong></td>
          <td class="mono">${r.pallet_count} pallet</td>
          <td class="mono"><strong>${r.total_qty}</strong></td>
          <td class="mono txt-muted">${r.uom||'CTN'}</td>
          <td class="mono">${fNum(r.total_kg)} kg</td>
          <td>${r.location_type ? `<span class="badge badge-gray">${r.location_type}</span>` : '-'}</td>
          <td class="mono txt-muted">${fDateTime(r.updated_at)}</td>
        </tr>`).join('')
    : '<tr class="empty-row"><td colspan="8">Tidak ada data</td></tr>';

  q('#stPagination').innerHTML = renderPagination(data.pagination, 'stock');
}

window.stockShowBins = async (batch) => {
  const data = await api(`stock.php?mode=detail&batch=${encodeURIComponent(batch)}`);
  if (!data.success) { toast(data.error, 'error'); return; }
  openModal(`Detail Bin — ${batch}`, `
    <div class="table-wrap" style="border:none">
      <table>
        <thead><tr><th>Pallet</th><th>Qty</th><th>UOM</th><th>Qty KG</th><th>Bin</th><th>Type</th><th>Updated</th></tr></thead>
        <tbody>
          ${data.data.length
            ? data.data.map(r => `
              <tr>
                <td class="mono">${r.pallet_number}</td>
                <td class="mono"><strong>${r.quantity}</strong></td>
                <td class="mono txt-muted">${r.uom||'-'}</td>
                <td class="mono">${fNum(r.quantity_kg)} kg</td>
                <td class="mono">${r.bin_location}</td>
                <td>${r.location_type ? `<span class="badge badge-gray">${r.location_type}</span>` : '-'}</td>
                <td class="mono txt-muted">${fDateTime(r.updated_at)}</td>
              </tr>`).join('')
            : '<tr class="empty-row"><td colspan="7">Tidak ada data</td></tr>'}
        </tbody>
      </table>
    </div>
  `);
};

window.gotoStock = (p) => {stockPage = p; stockFetchData();};

/* ═══════════════════════════════════════════════════════════
   MOVEMENTS
   ═══════════════════════════════════════════════════════════ */
let movFilters = {}, movPage = 1;

function movGetFilters() {
  return {
    movement_type:  q('#fMvType')?.value  || '',
    batch:          q('#fMvBatch')?.value || '',
    transaction_id: q('#fMvTxn')?.value   || '',
    source:         q('#fMvSrc')?.value   || '',
    destination:    q('#fMvDst')?.value   || '',
    date_from:      q('#fMvDateFrom')?.value || '',
    date_to:        q('#fMvDateTo')?.value   || '',
  };
}

async function movements(page = 1) {
  movPage = page;
  if (page === 1) {
    setContent(`
      <div class="panel">
        <div class="filters-bar">
          <div class="filter-field"><label>Type</label>
            <select class="filter-select" id="fMvType" style="width:120px">
              <option value="">Semua</option>
              <option value="inbound">Inbound</option>
              <option value="outbound">Outbound</option>
              <option value="softcase">Softcase</option>
              <option value="moving">Moving</option>
            </select>
          </div>
          <div class="filter-field"><label>Batch</label><input class="filter-input" id="fMvBatch" placeholder="Filter..."></div>
          <div class="filter-field"><label>TXN ID</label><input class="filter-input" id="fMvTxn" placeholder="Filter..."></div>
          <div class="filter-field"><label>Source</label><input class="filter-input" id="fMvSrc" placeholder="Filter..."></div>
          <div class="filter-field"><label>Destination</label><input class="filter-input" id="fMvDst" placeholder="Filter..."></div>
          <div class="filter-field"><label>Dari</label><input class="filter-input" id="fMvDateFrom" type="date" style="width:140px"></div>
          <div class="filter-field"><label>Sampai</label><input class="filter-input" id="fMvDateTo" type="date" style="width:140px"></div>
          <div class="filters-actions">
            <button class="btn btn-ghost btn-sm" id="mvResetBtn">Reset</button>
            <button class="btn btn-green btn-sm" id="mvExportBtn">${svgDownload()} Export</button>
          </div>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0">
          <table>
            <thead><tr><th>TXN ID</th><th>Type</th><th>Batch</th><th>Qty</th><th>Kg</th><th>Dari</th><th>Ke</th><th>User</th><th>Remarks</th><th>Waktu</th></tr></thead>
            <tbody id="mvBody"><tr class="empty-row"><td colspan="10">Memuat...</td></tr></tbody>
          </table>
        </div>
        <div id="mvPagination" style="padding:12px 16px"></div>
      </div>
    `);

    // Reset
    q('#mvResetBtn')?.addEventListener('click', () => {
      ['fMvType','fMvBatch','fMvTxn','fMvSrc','fMvDst','fMvDateFrom','fMvDateTo']
        .forEach(id => { const el = q(`#${id}`); if (el) el.value = ''; });
      movFilters = {};
      movPage    = 1;
      movementsFetchData();
    });

    // Export
    q('#mvExportBtn')?.addEventListener('click', () => {
      const f    = movGetFilters();
      const hasF = Object.values(f).some(v => v);
      if (!hasF && !confirm('Tidak ada filter aktif. Export semua data?')) return;
      window.open(`api/export.php?${new URLSearchParams({type:'movements', ...f})}`, '_blank');
    });

    // Live search
    let movTimer;
    ['fMvBatch','fMvTxn','fMvSrc','fMvDst','fMvDateFrom','fMvDateTo'].forEach(id => {
      q(`#${id}`)?.addEventListener('input', () => {
        clearTimeout(movTimer);
        movTimer = setTimeout(() => {
          movFilters = movGetFilters();
          movPage    = 1;
          movementsFetchData();
        }, 400);
      });
    });
    q('#fMvType')?.addEventListener('change', () => {
      movFilters = movGetFilters();
      movPage    = 1;
      movementsFetchData();
    });
  }

  await movementsFetchData();
}

async function movementsFetchData() {
  const params = new URLSearchParams({ page: movPage, ...movFilters });
  const data   = await api(`movements.php?${params}`, 'GET');
  if (!data.success) { showError(data.error); return; }

  const offset = (movPage - 1) * 50;
  q('#mvBody').innerHTML = data.data.length
    ? data.data.map((r, i) => `
        <tr style="cursor:pointer" onclick="showTxnDetail('${r.transaction_id}')" title="Klik untuk detail">
          <td class="mono" style="font-size:11px">${r.transaction_id}</td>
          <td><span class="badge ${BADGE_MAP[r.movement_type]||'badge-gray'}">${r.movement_type}</span></td>
          <td>${r.batch||'-'}</td>
          <td class="mono">${r.quantity||0} ${r.uom||''}</td>
          <td class="mono">${fNum(r.quantity_kg)} kg</td>
          <td class="txt-muted">${r.source_location||'-'}</td>
          <td class="txt-muted">${r.destination_location||'-'}</td>
          <td class="mono txt-muted">${r.username||'-'}</td>
          <td class="mono txt-muted">${r.remarks||'-'}</td>
          <td class="mono txt-muted" style="font-size:11px">${fDateTime(r.created_at)}</td>
        </tr>`).join('')
    : '<tr class="empty-row"><td colspan="10">Tidak ada data</td></tr>';

  q('#mvPagination').innerHTML = renderPagination(data.pagination, 'movements');
}

window.showTxnDetail = async (txnId) => {
  const data = await api(`transaction_detail.php?transaction_id=${encodeURIComponent(txnId)}`);
  if (!data.success) { toast(data.error, 'error'); return; }

  const h = data.header, rows = data.rows;
  const totalQty = rows.reduce((s,r) => s + Number(r.quantity||0), 0);
  const totalKg  = rows.reduce((s,r) => s + Number(r.quantity_kg||0), 0);

  openModal(`Detail Transaksi — ${txnId}`, `
    <div style="margin-bottom:14px;font-size:13px;line-height:1.7">
      <div><strong>Tipe:</strong> <span class="badge ${BADGE_MAP[h.movement_type]||'badge-gray'}">${h.movement_type}</span>
        ${h.is_cancelled ? '<span class="badge badge-red" style="margin-left:6px">DIBATALKAN</span>' : ''}</div>
      <div><strong>Oleh:</strong> ${h.username} (${h.userid})</div>
      <div><strong>Waktu:</strong> ${fDateTime(h.created_at)}</div>
    </div>
    <div class="table-wrap" style="border:none">
      <table>
        <thead><tr><th>Batch</th><th>Pallet</th><th>Qty</th><th>Kg</th><th>Dari</th><th>Bin</th><th>Ke</th><th>Remarks</th></tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr>
              <td>${r.batch||'-'}</td>
              <td class="mono">${r.pallet_number||'-'}</td>
              <td class="mono">${r.quantity} ${r.uom||''}</td>
              <td class="mono">${fNum(r.quantity_kg)} kg</td>
              <td class="txt-muted">${r.source_location||'-'}</td>
              <td class="mono txt-muted">${r.bin_location||'-'}</td>
              <td class="txt-muted">${r.destination_location||'-'}</td>
              <td class="mono txt-muted">${r.remarks||'-'}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>
    <div style="margin-top:10px;font-size:12px;color:var(--text-muted)">
      Total: <strong>${totalQty}</strong> CTN | <strong>${fNum(totalKg)}</strong> kg
    </div>
    <div class="form-actions" style="margin-top:18px">
      <button class="btn btn-secondary" onclick="window.open('api/transaction_print.php?transaction_id=${encodeURIComponent(txnId)}','_blank')">
        ${svgDownload()} Export PDF
      </button>
      ${data.can_cancel
        ? `<button class="btn btn-danger" onclick="cancelTransaction('${txnId}')">Batalkan Transaksi</button>`
        : (h.is_cancelled ? '<span class="badge badge-gray">Sudah dibatalkan</span>' : '')}
      <button class="btn btn-secondary" onclick="closeModal()">Tutup</button>
    </div>
  `);
};

window.cancelTransaction = async (txnId) => {
  if (!confirm(`Yakin membatalkan transaksi ${txnId}? Stok akan disesuaikan otomatis dan tidak dapat diulang.`)) return;
  const res = await api('transaction_cancel.php', 'POST', { transaction_id: txnId });
  if (res.success) {
    toast(res.message, 'success');
    closeModal();
    movementsFetchData();
  } else {
    toast(res.error, 'error');
  }
};

window.gotoMovements = (p) => { movPage = p; movementsFetchData(); };

/* ═══════════════════════════════════════════════════════════
   SOFTCASE MONITORING
   ═══════════════════════════════════════════════════════════ */
let scmFilters = {}, scmPage = 1;

function scmGetFilters() {
  return {
    batch:         q('#fScBatch')?.value     || '',
    pallet_number: q('#fScPallet')?.value    || '',
    status:        q('#fScStatus')?.value    || '',
    date_from:     q('#fScDateFrom')?.value  || '',
    time_from:     q('#fScTimeFrom')?.value  || '',
    date_to:       q('#fScDateTo')?.value    || '',
    time_to:       q('#fScTimeTo')?.value    || '',
  };
}

async function softcaseMonitoring(page = 1) {
  scmPage = page;
  if (page === 1) {
    setContent(`
      <div class="panel">
        <div class="filters-bar">
          <div class="filter-field"><label>Batch</label><input class="filter-input" id="fScBatch" placeholder="Filter..."></div>
          <div class="filter-field"><label>Dari Tanggal</label><input class="filter-input" id="fScDateFrom" type="date" style="width:140px"></div>
          <div class="filter-field"><label>Jam</label><input class="filter-input" id="fScTimeFrom" type="time" style="width:100px"></div>
          <div class="filter-field"><label>Sampai Tanggal</label><input class="filter-input" id="fScDateTo" type="date" style="width:140px"></div>
          <div class="filter-field"><label>Jam</label><input class="filter-input" id="fScTimeTo" type="time" style="width:100px"></div>
          <div class="filters-actions">
            <button class="btn btn-ghost btn-sm" id="scmResetBtn">Reset</button>
            <button class="btn btn-green btn-sm" id="scmExportBtn">${svgDownload()} Export</button>
          </div>
        </div>
        <div id="scmSummary" style="display:flex;gap:20px;padding:10px 16px;border-bottom:1px solid var(--border);font-size:12px;font-family:var(--font-mono);flex-wrap:wrap;"></div>
        <div class="table-wrap" style="border:none;border-radius:0">
          <table>
            <thead><tr><th>Batch</th><th>Pallet</th><th>Qty Checked</th><th>UOM</th><th>Qty Soft</th><th>UOM Soft</th><th>Remarks</th><th>Terakhir Dicek</th></tr></thead>
            <tbody id="scmBody"><tr class="empty-row"><td colspan="8">Memuat...</td></tr></tbody>
          </table>
        </div>
        <div id="scmPagination" style="padding:12px 16px"></div>
      </div>
    `);

    q('#scmResetBtn')?.addEventListener('click', () => {
      ['fScBatch','fScPallet','fScStatus','fScDateFrom','fScTimeFrom','fScDateTo','fScTimeTo']
        .forEach(id => { const el = q(`#${id}`); if (el) el.value = ''; });
      scmFilters = {};
      scmPage    = 1;
      scmFetchData();
    });

    q('#scmExportBtn')?.addEventListener('click', () => {
      const p = new URLSearchParams({type:'softcase', ...scmGetFilters()});
      window.open(`api/export.php?${p}`, '_blank');
    });

    let scmTimer;
    ['fScBatch','fScPallet','fScDateFrom','fScTimeFrom','fScDateTo','fScTimeTo'].forEach(id => {
      q(`#${id}`)?.addEventListener('input', () => {
        clearTimeout(scmTimer);
        scmTimer = setTimeout(() => {
          scmFilters = scmGetFilters();
          scmPage    = 1;
          scmFetchData();
        }, 400);
      });
    });
    q('#fScStatus')?.addEventListener('change', () => {
      scmFilters = scmGetFilters();
      scmPage    = 1;
      scmFetchData();
    });
  }

  await scmFetchData();
}

async function scmFetchData() {
  const params = new URLSearchParams({ page: scmPage, ...scmFilters });
  const data   = await api(`softcase_monitoring.php?${params}`, 'GET');
  if (!data.success) { showError(data.error); return; }

  const sum   = data.summary || {};
  const sumEl = q('#scmSummary');
  if (sumEl) sumEl.innerHTML = `
    <span style="color:var(--text)">Total Pallet: <strong>${sum.total||0}</strong></span>
    <span style="color:var(--text)">Total Qty Checked: <strong>${sum.total_checked_ctn||0} CTN</strong></span>
    <span style="color:var(--accent)">Total Qty Soft: <strong>${sum.total_soft_ctn||0} CTN</strong></span>
    <span style="color:var(--yellow)">% Soft: <strong>${sum.soft_percentage||0}%</strong></span>`;

  q('#scmBody').innerHTML = data.data.length
    ? data.data.map(r => {
        const rowCls = (r.qty_checked == 0) ? 'row-red' : 'row-green';
        return `<tr class="${rowCls}">
          <td>${r.batch}</td>
          <td class="mono">${r.pallet_number}</td>
          <td class="mono">${r.qty_checked}</td>
          <td class="mono txt-muted">${r.uom_checked||'CTN'}</td>
          <td class="mono">${r.qty_soft||0}</td>
          <td class="mono txt-muted">${r.uom_soft||'CTN'}</td>
          <td class="mono txt-muted">${r.remarks||'-'}</td>
          <td class="mono txt-muted">${fDateTime(r.checked_at)}</td>
        </tr>`;
      }).join('')
    : '<tr class="empty-row"><td colspan="8">Tidak ada data</td></tr>';

  q('#scmPagination').innerHTML = renderPagination(data.pagination, 'scm');
}

window.gotoScm = (p) => { scmPage = p; scmFetchData(); };

/* ═══════════════════════════════════════════════════════════
   USERS
   ═══════════════════════════════════════════════════════════ */
async function users() {
  setContent('<div class="page-loader"><div class="loader-ring"></div></div>');
  const data = await api('users.php', 'GET');
  if (!data.success) { showError(data.error); return; }

  setContent(`
    <div class="section-header" style="margin-bottom:16px">
      <div class="section-title">Daftar User</div>
      <button class="btn btn-primary btn-sm" id="usCreateBtn">${svgPlus()} Tambah User</button>
    </div>
    <div class="panel">
      <div class="table-wrap" style="border:none;border-radius:0">
        <table>
          <thead><tr><th>#</th><th>Username</th><th>User ID</th><th>Role</th><th>Dibuat</th><th>Aksi</th></tr></thead>
          <tbody>
            ${data.data.length ? data.data.map((u,i) => `
              <tr>
                <td class="mono txt-muted">${i+1}</td>
                <td><strong>${escHtml(u.username)}</strong></td>
                <td class="mono">${u.userid}</td>
                <td><span class="badge ${u.role==='admin'?'badge-red':u.role==='supervisor'?'badge-amber':u.role==='staff'?'badge-blue':'badge-gray'}">${u.role}</span></td>
                <td class="mono txt-muted">${fDateTime(u.created_at)}</td>
                <td>
                  <div style="display:flex;gap:6px">
                    <button class="btn btn-secondary btn-sm" onclick="usEdit(${u.id},'${escHtml(u.username)}','${u.role}')">Edit</button>
                    <button class="btn btn-secondary btn-sm" onclick="usResetPwd(${u.id},'${escHtml(u.username)}')">Reset Pwd</button>
                    ${u.userid !== WMS.user.userid ? `<button class="btn btn-danger btn-sm" onclick="usDelete(${u.id},'${escHtml(u.username)}')">Hapus</button>` : ''}
                  </div>
                </td>
              </tr>`).join('') : '<tr class="empty-row"><td colspan="6">Tidak ada user</td></tr>'}
          </tbody>
        </table>
      </div>
    </div>
  `);

  q('#usCreateBtn').addEventListener('click', usCreate);
}

function usCreate() {
  openModal('Tambah User', `
    <div id="usModalResult"></div>
    <div class="form-row">
      <div class="field-group"><label class="field-label">Username *</label><input id="muUsername" class="field-input" placeholder="Nama lengkap"></div>
      <div class="field-group"><label class="field-label">User ID *</label><input id="muUserid" class="field-input" placeholder="Max 10 karakter" maxlength="10"></div>
    </div>
    <div class="form-row">
      <div class="field-group"><label class="field-label">Role *</label>
        <select id="muRole" class="field-select">
          <option value="">-- Pilih --</option>
          <option value="admin">Admin</option><option value="supervisor">Supervisor</option>
          <option value="staff">Staff</option><option value="softchecker">Softchecker</option>
        </select>
      </div>
      <div class="field-group"><label class="field-label">Password *</label><input id="muPassword" class="field-input" type="password" placeholder="Min 6 karakter"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" id="muSubmitBtn">${svgSend()} Simpan</button>
      <button class="btn btn-secondary" onclick="closeModal()">Batal</button>
    </div>
  `);
  autoSelect();
  q('#muSubmitBtn').addEventListener('click', async () => {
    const btn = q('#muSubmitBtn'); btn.disabled=true;
    const res = await api('users.php', 'POST', { action:'create', username: q('#muUsername').value.trim(), userid: q('#muUserid').value.trim(), role: q('#muRole').value, password: q('#muPassword').value });
    btn.disabled=false;
    if (res.success) { toast(res.message,'success'); closeModal(); users(); }
    else q('#usModalResult').innerHTML = `<div class="form-error">${res.error}</div>`;
  });
}

window.usEdit = (id, username, role) => {
  openModal('Edit User', `
    <div id="usModalResult"></div>
    <div class="field-group"><label class="field-label">Username *</label><input id="muUsername" class="field-input" value="${escHtml(username)}"></div>
    <div class="field-group"><label class="field-label">Role *</label>
      <select id="muRole" class="field-select">
        <option value="admin" ${role==='admin'?'selected':''}>Admin</option>
        <option value="supervisor" ${role==='supervisor'?'selected':''}>Supervisor</option>
        <option value="staff" ${role==='staff'?'selected':''}>Staff</option>
        <option value="softchecker" ${role==='softchecker'?'selected':''}>Softchecker</option>
      </select>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" id="muSubmitBtn">Simpan</button>
      <button class="btn btn-secondary" onclick="closeModal()">Batal</button>
    </div>
  `);
  autoSelect();
  q('#muSubmitBtn').addEventListener('click', async () => {
    const btn = q('#muSubmitBtn'); btn.disabled=true;
    const res = await api('users.php', 'POST', { action:'update', id, username: q('#muUsername').value.trim(), role: q('#muRole').value });
    btn.disabled=false;
    if (res.success) { toast(res.message,'success'); closeModal(); users(); }
    else q('#usModalResult').innerHTML = `<div class="form-error">${res.error}</div>`;
  });
};

window.usResetPwd = (id, username) => {
  openModal(`Reset Password — ${username}`, `
    <div id="usModalResult"></div>
    <div class="field-group"><label class="field-label">Password Baru *</label><input id="muNewPwd" class="field-input" type="password" placeholder="Min 6 karakter"></div>
    <div class="form-actions">
      <button class="btn btn-primary" id="muSubmitBtn">Reset</button>
      <button class="btn btn-secondary" onclick="closeModal()">Batal</button>
    </div>
  `);
  autoSelect();
  q('#muSubmitBtn').addEventListener('click', async () => {
    const btn = q('#muSubmitBtn'); btn.disabled=true;
    const res = await api('users.php', 'POST', { action:'reset_password', id, new_password: q('#muNewPwd').value });
    btn.disabled=false;
    if (res.success) { toast(res.message,'success'); closeModal(); }
    else q('#usModalResult').innerHTML = `<div class="form-error">${res.error}</div>`;
  });
};

window.usDelete = (id, username) => {
  openModal('Hapus User', `
    <p style="margin-bottom:20px">Apakah Anda yakin ingin menghapus user <strong>${escHtml(username)}</strong>? Tindakan ini tidak dapat dibatalkan.</p>
    <div class="form-actions">
      <button class="btn btn-danger" id="muSubmitBtn">Ya, Hapus</button>
      <button class="btn btn-secondary" onclick="closeModal()">Batal</button>
    </div>
  `);
  q('#muSubmitBtn').addEventListener('click', async () => {
    const res = await api('users.php', 'POST', { action:'delete', id });
    if (res.success) { toast(res.message,'success'); closeModal(); users(); }
    else toast(res.error,'error');
  });
};

/* ═══════════════════════════════════════════════════════════
   UTILITIES
   ═══════════════════════════════════════════════════════════ */
function q(sel) { return document.querySelector(sel); }
function qAll(sel) { return document.querySelectorAll(sel); }

function setContent(html) { const el = q('#mainContent'); if(el) el.innerHTML = html; initBatchBinPopups(); }
function setPageTitle(t) { const el = q('#pageTitle'); if(el) el.textContent = t; }
function showError(msg) { setContent(`<div style="padding:40px;color:var(--red)">${msg||'Error'}</div>`); }

async function api(endpoint, method = 'GET', body = null, withCsrf = true) {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (withCsrf) opts.headers['X-Csrf-Token'] = WMS.csrf;
  if (body && method !== 'GET') opts.body = JSON.stringify(body);
  try {
    const res = await fetch(API(endpoint), opts);
    const data = await res.json();
    if (data.csrf) WMS.csrf = data.csrf;
    return data;
  } catch (e) {
    return { success: false, error: 'Network error: ' + e.message };
  }
}

function autoSelect() {
  qAll('.field-input[type="text"], .field-input[type="number"], .field-input[type="password"], .filter-input').forEach(el => {
    el.addEventListener('focus', function() { this.select();});
  });
}

function fNum(n) { return n ? parseFloat(n).toFixed(2) : '0.00'; }
function fDateTime(dt) {
  if (!dt) return '-';
  const d = new Date(dt);
  return d.toLocaleDateString('id-ID',{day:'2-digit',month:'2-digit',year:'2-digit'}) + ' ' +
         d.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'});
}
function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function renderPagination(p, scope) {
  if (!p || p.totalPages <= 1) return '';
  const fn = { stock:'gotoStock', movements:'gotoMovements', scm:'gotoScm' }[scope] || 'gotoStock';
  let html = '<div class="pagination">';
  html += `<button class="page-btn" onclick="${fn}(${p.page-1})" ${p.page<=1?'disabled':''}>‹</button>`;
  for (let i = Math.max(1,p.page-2); i <= Math.min(p.totalPages,p.page+2); i++) {
    html += `<button class="page-btn ${i===p.page?'active':''}" onclick="${fn}(${i})">${i}</button>`;
  }
  html += `<button class="page-btn" onclick="${fn}(${p.page+1})" ${p.page>=p.totalPages?'disabled':''}>›</button>`;
  html += `<span class="page-info">${p.page} / ${p.totalPages} (${p.total} records)</span>`;
  return html + '</div>';
}

// ─── Toast ───────────────────────────────────────────────────
function toast(msg, type = 'info') {
  const icons = {
    success: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    error:   '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    warning: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    info:    '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
  };
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.innerHTML = `${icons[type]||icons.info}<span>${msg}</span>`;
  q('#toastContainer').appendChild(el);
  el.addEventListener('click', () => { el.classList.add('out'); setTimeout(() => el.remove(), 200); });
  setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 200); }, 4000);
}

// ─── Modal ───────────────────────────────────────────────────
function openModal(title, bodyHtml) {
  q('#modalTitle').textContent = title;
  q('#modalBody').innerHTML = bodyHtml;
  q('#modalOverlay').classList.remove('hidden');
  autoSelect();
  initBatchBinPopups();
}
function closeModal() { q('#modalOverlay').classList.add('hidden'); q('#modalBody').innerHTML = ''; }
window.closeModal = closeModal;

q('#modalClose')?.addEventListener('click', closeModal);
q('#modalOverlay')?.addEventListener('click', e => { if (e.target === q('#modalOverlay')) closeModal(); });

// ─── SVG icons ───────────────────────────────────────────────
const svgBox     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>`;
const svgArrowDown = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12l7 7 7-7"/></svg>`;
const svgArrowUp   = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 19V5M5 12l7-7 7 7"/></svg>`;
const svgCheck     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>`;
const svgPlus      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`;
const svgSend      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>`;
const svgDownload  = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`;
const svgSpinner   = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;animation:spin .7s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>`;

/* ═══════════════════════════════════════════════════════════
   BATCH & BIN POPUP HELPERS
   ═══════════════════════════════════════════════════════════ */

function initBatchBinPopups() {
  const batchInputs       = ['#ibBatch','#obBatch','#scBatch','#mvBatch','#fStBatch','#fScBatch','#fMvBatch'];
  const binInputs         = ['#ibBin','#obBin','#mvSrc','#mvDst','#fStBin','#scSourceBin'];

  batchInputs.forEach(sel => {
    const el = q(sel);
    if (el && !el.dataset.popupBound) {
      el.addEventListener('dblclick', () => openBatchPopup(el));
      el.dataset.popupBound = 'true';
    }
  });
  binInputs.forEach(sel => {
    const el = q(sel);
    if (el && !el.dataset.popupBound) {
      el.addEventListener('dblclick', () => openBinPopup(el));
      el.dataset.popupBound = 'true';
    }
  });
}

function openBatchPopup(targetEl) {
  const years = ['23', '24', '25', '26'];
  const types = ['GV', 'GB', 'GC', 'GA', 'GF', 'GS'];
  
  openModal('Pilih Batch', `
    <div class="form-row">
      <div class="field-group">
        <label class="field-label">Tahun</label>
        <select id="popBatchYear" class="field-select">
          ${years.map(y => `<option value="${y}">${y}</option>`).join('')}
        </select>
      </div>
      <div class="field-group">
        <label class="field-label">Jenis</label>
        <select id="popBatchType" class="field-select">
          ${types.map(t => `<option value="${t}">${t}</option>`).join('')}
        </select>
      </div>
    </div>
    <div class="field-group">
      <label class="field-label">4 Digit Angka</label>
      <input id="popBatchNum" type="number" class="field-input" placeholder="Contoh: 0123" min="0" max="9999">
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" id="popBatchSubmit">Terapkan</button>
    </div>
  `);

  q('#popBatchSubmit').onclick = () => {
    const year = q('#popBatchYear').value;
    const type = q('#popBatchType').value;
    const num  = q('#popBatchNum').value.padStart(4, '0');
    targetEl.value = `DE${year}${type}${num}`;
    closeModal();
    targetEl.dispatchEvent(new Event('input')); // Trigger input event jika ada listener lain
  };
}

function openBinPopup(targetEl) {
  const letters1 = ['A','B','C','D','E','F','G','H'];
  const letters2 = ['A','B','C','D'];
  
  openModal('Pilih Bin Location', `
    <div class="form-row">
      <div class="field-group">
        <label class="field-label">Baris (A-H)</label>
        <select id="popBinL1" class="field-select">
          ${letters1.map(l => `<option value="${l}">${l}</option>`).join('')}
        </select>
      </div>
      <div class="field-group">
        <label class="field-label">Nomor (01-17)</label>
        <input id="popBinN1" type="number" class="field-input" value="1" min="1" max="17">
      </div>
    </div>
    <div class="form-row">
      <div class="field-group">
        <label class="field-label">Level (A-D)</label>
        <select id="popBinL2" class="field-select">
          ${letters2.map(l => `<option value="${l}">${l}</option>`).join('')}
        </select>
      </div>
      <div class="field-group">
        <label class="field-label">Posisi (01-03)</label>
        <input id="popBinN2" type="number" class="field-input" value="1" min="1" max="3">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" id="popBinSubmit">Terapkan</button>
    </div>
  `);

  q('#popBinSubmit').onclick = () => {
    const l1 = q('#popBinL1').value;
    const n1 = q('#popBinN1').value.padStart(2, '0');
    const l2 = q('#popBinL2').value;
    const n2 = q('#popBinN2').value.padStart(2, '0');
    targetEl.value = `${l1}-${n1}-${l2}-${n2}`;
    closeModal();
    targetEl.dispatchEvent(new Event('input'));
  };
}

window.applyPopupValue = (val, e) => {
  e.preventDefault();
  if (window._popupTarget) {
    window._popupTarget.value = val;
    window._popupTarget.dispatchEvent(new Event('input'));
    window._popupTarget = null;
  }
  closeModal();
};

