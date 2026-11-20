<?php
declare(strict_types=1);

require_once __DIR__ . '/config/functions.php';

$user = current_user();
$current = basename($_SERVER['PHP_SELF'] ?? '');

$navMenus = [];
if ($user) {
    $stmt = db()->prepare(
        'SELECT m.nama, m.route, m.icon FROM menus m
         INNER JOIN role_menus rm ON rm.menu_id = m.id
         WHERE rm.role = ? AND rm.is_active = 1 AND m.is_active = 1
         ORDER BY m.urut ASC'
    );
    $stmt->execute([$user['role']]);
    $navMenus = $stmt->fetchAll();
}
$flash = get_flash();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0d6efd">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' : '' ?><?= e(APP_NAME) ?></title>
  <link rel="icon" href="<?= BASE_URL ?>img/logo_mcp.png" type="image/png">
  <link rel="stylesheet" href="<?= BASE_URL ?>library/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>library/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>css/app.css">
</head>
<body>
<header class="app-header sticky-top">
  <nav class="navbar navbar-expand-lg navbar-dark bg-mcp">
    <div class="container-fluid">
      <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE_URL ?>dashboard.php">
        <img class="navbar-logo" src="<?= BASE_URL ?>img/logo_mcp.png" alt="Logo PT. MCP" width="34" height="34">
        <span class="brand-text">PT. MCP <small>Hasil Produksi</small></span>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
              aria-controls="mainNav" aria-expanded="false" aria-label="Buka menu">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="mainNav">
        <?php if ($user): ?>
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <?php foreach ($navMenus as $menu): ?>
            <li class="nav-item">
              <a class="nav-link <?= $current === $menu['route'] ? 'active' : '' ?>"
                 href="<?= BASE_URL . e($menu['route']) ?>">
              <?php
                $iconClass = (preg_match('/^bi-[a-z0-9-]+$/', $menu['icon'] ?? '') === 1)
                    ? $menu['icon'] : 'bi-circle';
              ?>
              <span class="nav-icon"><i class="bi <?= e($iconClass) ?>" aria-hidden="true"></i></span> <?= e($menu['nama']) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="nav-user d-flex align-items-center gap-2">
          <span class="badge bg-light text-dark rounded-pill"><?= e(strtoupper($user['role'])) ?></span>
          <span class="nav-name"><?= e($user['nama']) ?></span>
          <a class="btn btn-sm btn-outline-light" href="<?= BASE_URL ?>ganti_password.php"
             title="Ganti password" aria-label="Ganti password">
            <i class="bi bi-key" aria-hidden="true"></i><span class="d-none d-lg-inline"> Ganti Password</span>
          </a>
          <a class="btn btn-sm btn-outline-light" href="<?= BASE_URL ?>logout.php">Keluar</a>
        </div>
        <?php else: ?>
        <ul class="navbar-nav ms-auto">
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>login.php">Masuk</a></li>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </nav>
</header>

<main class="app-main container-fluid py-3">
  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
  <?php endif; ?>
