<?php
// ============================================================
// WMS LSN - Main Entry Point
// ============================================================
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/functions/auth.php';
require_once BASE_PATH . '/functions/csrf.php';
require_once BASE_PATH . '/functions/helpers.php';

sessionStart();
$isAuth  = isLoggedIn();
$user    = $isAuth ? currentUser() : null;
$csrf    = csrfGenerate();
$allowed = $isAuth ? (ROLE_ACCESS[$user['role']] ?? []) : [];
?>
<!DOCTYPE html>
<html lang="id" data-auth="<?= $isAuth ? 'true' : 'false' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= $csrf ?>">
<link rel="icon" href="assets/img/logo.webp">
<title>WMS LSN</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/js/chart.umd.min.js"></script>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?= $isAuth ? 'app-mode' : 'login-mode' ?>">

<?php if (!$isAuth): ?>
<!-- ======== LOGIN PAGE ======== -->
<div class="login-wrap">
  <div class="login-bg">
    <div class="login-grid"></div>
  </div>
  <div class="login-box">
    <div class="login-brand">
      <img class="brand-icon" src="assets/img/logo.png" alt="logo lsn">
      <span class="brand-text">WMS <em>LSN</em></span>
    </div>
    <p class="login-sub">Warehouse Management System</p>
    <form id="loginForm" autocomplete="off">
      <div class="field-group">
        <label class="field-label">User ID</label>
        <input type="text" id="loginUserid" class="field-input" placeholder="Masukkan User ID" autocomplete="username">
      </div>
      <div class="field-group">
        <label class="field-label">Password</label>
        <div class="input-wrap">
          <input type="password" id="loginPassword" class="field-input" placeholder="••••••••" autocomplete="current-password">
          <button type="button" class="input-icon-btn" id="togglePwd" tabindex="-1">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <div id="loginError" class="form-error hidden"></div>
      <button type="submit" class="btn btn-primary btn-block" id="loginBtn">
        <span>Masuk</span>
      </button>
    </form>
    <p class="login-footer">LSN Warehouse &copy; <?= date('Y') ?></p>
  </div>
</div>

<?php else: ?>
<!-- ======== APP SHELL ======== -->
<div class="app-layout">

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img class="brand-icon" src="assets/img/logo.png" alt="logo lsn">
      <span class="brand-text">WMS <em>LSN</em></span>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Overview</div>
      <a href="#dashboard" class="nav-item" data-page="dashboard">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
          <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
        </svg>
        Dashboard
      </a>

      <?php if (canAccess('stock')): ?>
      <a href="#stock" class="nav-item" data-page="stock">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
          <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
        </svg>
        Stock Overview
      </a>
      <?php endif; ?>

      <?php if (canAccess('movements')): ?>
      <a href="#movements" class="nav-item" data-page="movements">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/>
          <polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/>
        </svg>
        Movements
      </a>
      <?php endif; ?>

      <div class="nav-section-label">Transaksi</div>

      <?php if (canAccess('inbound')): ?>
      <a href="#inbound" class="nav-item" data-page="inbound">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M12 5v14M5 12l7 7 7-7"/>
        </svg>
        Inbound
      </a>
      <?php endif; ?>

      <?php if (canAccess('outbound')): ?>
      <a href="#outbound" class="nav-item" data-page="outbound">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M12 19V5M5 12l7-7 7 7"/>
        </svg>
        Outbound
      </a>
      <?php endif; ?>

      <?php if (canAccess('moving')): ?>
      <a href="#moving" class="nav-item" data-page="moving">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M5 12h14M12 5l7 7-7 7"/>
        </svg>
        Moving
      </a>
      <?php endif; ?>

      <?php if (canAccess('softcase')): ?>
      <div class="nav-section-label">Softcase</div>
      <a href="#softcase" class="nav-item" data-page="softcase">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        Softcase Check
      </a>
      <?php endif; ?>

      <?php if (canAccess('softcase-monitoring')): ?>
      <a href="#softcase-monitoring" class="nav-item" data-page="softcase-monitoring">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
          <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
        </svg>
        Softcase Monitor
      </a>
      <?php endif; ?>

      <?php if (canAccess('users')): ?>
      <div class="nav-section-label">Admin</div>
      <a href="#users" class="nav-item" data-page="users">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        User Management
      </a>
      <?php endif; ?>
    </nav>

    <div class="sidebar-user">
      <div class="user-avatar"><?= strtoupper(substr($user['username'], 0, 2)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
        <div class="user-role"><?= htmlspecialchars($user['role']) ?></div>
      </div>
      <button class="logout-btn" id="changePwdBtn" title="Ganti Password" style="margin-right:2px">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
      </button>
      <button class="logout-btn" id="logoutBtn" title="Logout">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </button>
    </div>
  </aside>

  <!-- Main -->
  <div class="main-wrap">
    <header class="top-bar">
      <button class="menu-toggle" id="menuToggle">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="3" y1="6" x2="21" y2="6"/>
          <line x1="3" y1="12" x2="21" y2="12"/>
          <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <div class="page-title" id="pageTitle">Dashboard</div>
      <div class="top-bar-right">
        <div class="top-clock" id="topClock"></div>
      </div>
    </header>

    <main class="main-content" id="mainContent">
      <div class="page-loader"><div class="loader-ring"></div></div>
    </main>
  </div>
</div>

<!-- Modal overlay -->
<div class="modal-overlay hidden" id="modalOverlay">
  <div class="modal" id="modalBox">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle"></div>
      <button class="modal-close" id="modalClose">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="modal-body" id="modalBody"></div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<?php endif; ?>

<!-- App state -->
<script>
window.WMS = {
  auth: <?= $isAuth ? 'true' : 'false' ?>,
  user: <?= $isAuth ? json_encode($user) : 'null' ?>,
  allowed: <?= json_encode($allowed) ?>,
  csrf: "<?= $csrf ?>"
};
</script>
<script src="assets/js/app.js"></script>
</body>
</html>
