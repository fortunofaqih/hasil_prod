<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/report.php';
$user = require_login();
$role = $user['role'];

$showReport = in_array($role, ['manager', 'admin'], true);
if ($showReport) {
    $statusData = report_status_counts();
    $jsonFlags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
}

$pdo = db();
$stats = [
    'total'   => $pdo->query('SELECT COUNT(*) FROM do_header')->fetchColumn(),
    'ppic'    => $pdo->query("SELECT COUNT(*) FROM do_header WHERE status='ppic'")->fetchColumn(),
    'qc'      => $pdo->query("SELECT COUNT(*) FROM do_header WHERE status='qc'")->fetchColumn(),
    'gudang'  => $pdo->query("SELECT COUNT(*) FROM do_header WHERE status='gudang'")->fetchColumn(),
    'manager' => $pdo->query("SELECT COUNT(*) FROM do_header WHERE status='manager'")->fetchColumn(),
    'siap'    => $pdo->query("SELECT COUNT(*) FROM do_header WHERE status='siap_ambil'")->fetchColumn(),
    'koreksi' => $pdo->query("SELECT COUNT(*) FROM do_header WHERE status='koreksi'")->fetchColumn(),
];

$recent = $pdo->query('SELECT no_do, no_spk, customer, status, created_at FROM do_header ORDER BY id DESC LIMIT 6')->fetchAll();

// Dashboard khusus peran yang hanya berurusan dengan barang kembalian.
$isReturRole = in_array($role, ['satpam', 'produksi'], true);
$returStat = ['total' => 0, 'open' => 0, 'closed' => 0, 'hari' => 0];
$returRecent = [];
$prodStat = ['open' => 0, 'closed' => 0, 'dicatat' => 0, 'menunggu' => 0];
$prodRecent = [];
$gudangReturInfo = null;

if ($role === 'satpam') {
    $returStat = [
        'total'  => (int) $pdo->query('SELECT COUNT(*) FROM retur_header')->fetchColumn(),
        'open'   => (int) $pdo->query("SELECT COUNT(*) FROM retur_header WHERE status='open'")->fetchColumn(),
        'closed' => (int) $pdo->query("SELECT COUNT(*) FROM retur_header WHERE status='closed'")->fetchColumn(),
        'hari'   => (int) $pdo->query('SELECT COUNT(*) FROM retur_header WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
    ];
    $returRecent = $pdo->query('SELECT no_retur, no_sj, nopol, customer, alasan, status FROM retur_header ORDER BY id DESC LIMIT 6')->fetchAll();
} elseif ($role === 'produksi') {
    $prodStat = [
        'open'    => (int) $pdo->query("SELECT COUNT(*) FROM komplain_header WHERE jenis='retur' AND status='open'")->fetchColumn(),
        'closed'  => (int) $pdo->query("SELECT COUNT(*) FROM komplain_header WHERE jenis='retur' AND status='closed'")->fetchColumn(),
        'dicatat'  => (int) $pdo->query('SELECT COUNT(*) FROM produksi_retur_header')->fetchColumn(),
        'menunggu' => (int) $pdo->query("SELECT COUNT(*) FROM retur_header WHERE status='open'")->fetchColumn(),
    ];
    $prodRecent = $pdo->query("SELECT no_komplain, no_sj, customer, status, tgl_komplain FROM komplain_header WHERE jenis='retur' ORDER BY id DESC LIMIT 6")->fetchAll();
} elseif ($role === 'gudang') {
    $gudangReturInfo = [
        'open'  => (int) $pdo->query("SELECT COUNT(*) FROM retur_header WHERE status='open'")->fetchColumn(),
        'total' => (int) $pdo->query('SELECT COUNT(*) FROM retur_header')->fetchColumn(),
    ];
}

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari(array_merge($recent, $returRecent, $prodRecent), $statusSekarang);
$recent = status_pisah($recent, $statusSekarang);
$returRecent = status_pisah($returRecent, $statusSekarang);
$prodRecent = status_pisah($prodRecent, $statusSekarang);

$pageTitle = 'Dashboard';
require __DIR__ . '/header.php';
?>
<?php if (!$isReturRole): ?>
<section>
  <div class="page-heading">
    <div>
      <h1>Dashboard</h1>
      <div class="page-subtitle">Halo, <?= e($user['nama']) ?> &mdash; peran: <?= e(strtoupper($role)) ?></div>
    </div>
  </div>

  <?= $tabsStatus ?>
  <div class="row g-3">
    <div class="col-6 col-lg-3">
      <div class="card card-mcp stat-card"><div class="stat-icon">▦</div><div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Total DO</div></div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card card-mcp stat-card"><div class="stat-icon">⚙</div><div><div class="stat-value"><?= (int)$stats['ppic'] + (int)$stats['qc'] ?></div><div class="stat-label">Dalam Produksi / QC</div></div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card card-mcp stat-card"><div class="stat-icon">★</div><div><div class="stat-value"><?= (int)$stats['manager'] ?></div><div class="stat-label">Menunggu Manager</div></div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card card-mcp stat-card"><div class="stat-icon">✓</div><div><div class="stat-value"><?= (int)$stats['siap'] ?></div><div class="stat-label">Siap Diambil</div></div></div>
    </div>
  </div>

  <?php if ((int)$stats['koreksi'] > 0): ?>
    <div class="alert alert-warning">Ada <?= (int)$stats['koreksi'] ?> DO yang dikembalikan untuk koreksi. Cek menu masing-masing bagian.</div>
  <?php endif; ?>

  <div class="card card-mcp">
    <div class="card-header">Aktivitas Terbaru</div>
    <div class="card-body p-0">
      <?php if (!$recent): ?>
        <div class="empty-state">Belum ada data DO. Mulai dari menu <strong>Input DO</strong>.</div>
      <?php else: ?>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0">
            <thead><tr><th>No. DO</th><th>No. SPK</th><th>Customer</th><th>Status</th><th>Dibuat</th></tr></thead>
            <tbody>
              <?php foreach ($recent as $row): ?>
                <tr>
                  <td><?= e($row['no_do']) ?></td>
                  <td><?= e($row['no_spk']) ?></td>
                  <td><?= e($row['customer']) ?></td>
                  <td><?= status_badge($row['status']) ?></td>
                  <td><?= e(format_tanggal(substr($row['created_at'], 0, 10))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php else: ?>
<section>
  <div class="page-heading">
    <div>
      <h1>Dashboard <?= $role === 'satpam' ? 'Satpam' : 'Admin Produksi' ?></h1>
      <div class="page-subtitle">Halo, <?= e($user['nama']) ?> &mdash; fokus pada barang yang kembali ke pabrik</div>
    </div>
    <a class="btn btn-primary" href="<?= BASE_URL ?><?= $role === 'satpam' ? 'barang_kembalian.php?act=form' : 'produksi_retur.php' ?>">
      <?= $role === 'satpam' ? '+ Input Barang Kembali' : 'Lihat Retur' ?>
    </a>
  </div>

  <?= $tabsStatus ?>
  <?php if ($role === 'satpam'): ?>
    <div class="row g-3">
      <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">▦</div><div>
        <div class="stat-value"><?= $returStat['total'] ?></div><div class="stat-label">Total Barang Kembali</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">!</div><div>
        <div class="stat-value"><?= $returStat['open'] ?></div><div class="stat-label">Menunggu Gudang</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">✓</div><div>
        <div class="stat-value"><?= $returStat['closed'] ?></div><div class="stat-label">Sudah CLOSE</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">▤</div><div>
        <div class="stat-value"><?= $returStat['hari'] ?></div><div class="stat-label">Input Hari Ini</div></div></div></div>
    </div>
    <div class="card card-mcp">
      <div class="card-header">Barang Kembalian Terbaru</div>
      <div class="card-body p-0">
        <?php if (!$returRecent): ?><div class="empty-state">Belum ada barang kembalian.</div><?php else: ?>
          <div class="table-responsive-mcp"><table class="table table-mcp mb-0" style="min-width:720px">
            <thead><tr><th>No. Retur</th><th>No. SJ</th><th>Nopol</th><th>Customer</th><th>Alasan</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($returRecent as $r): ?><tr>
              <td><?= e($r['no_retur']) ?></td><td><?= e($r['no_sj']) ?></td><td><?= e($r['nopol']) ?></td>
              <td><?= e($r['customer']) ?></td><td><?= alasan_badge($r['alasan']) ?></td>
              <td><?= status_tiket($r['status']) ?></td>
            </tr><?php endforeach; ?></tbody>
          </table></div>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">!</div><div>
        <div class="stat-value"><?= $prodStat['open'] ?></div><div class="stat-label">Komplain Retur OPEN</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">✓</div><div>
        <div class="stat-value"><?= $prodStat['closed'] ?></div><div class="stat-label">Komplain CLOSE</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">⚙</div><div>
        <div class="stat-value"><?= $prodStat['dicatat'] ?></div><div class="stat-label">Tindakan Dicatat</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">×</div><div>
        <div class="stat-value"><?= $prodStat['menunggu'] ?></div><div class="stat-label">Retur Menunggu Gudang</div></div></div></div>
    </div>
    <div class="card card-mcp">
      <div class="card-header">Antrean Komplain Retur dari Marketing</div>
      <div class="card-body p-0">
        <?php if (!$prodRecent): ?><div class="empty-state">Belum ada komplain retur.</div><?php else: ?>
          <div class="table-responsive-mcp"><table class="table table-mcp mb-0" style="min-width:680px">
            <thead><tr><th>No. Komplain</th><th>No. SJ</th><th>Customer</th><th>Tanggal</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($prodRecent as $r): ?><tr>
              <td><?= e($r['no_komplain']) ?></td><td><?= e($r['no_sj']) ?></td><td><?= e($r['customer']) ?></td>
              <td><?= e(format_tanggal($r['tgl_komplain'])) ?></td><td><?= status_tiket($r['status']) ?></td>
            </tr><?php endforeach; ?></tbody>
          </table></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($gudangReturInfo): ?>
<section class="mt-4">
  <div class="card card-mcp border-primary">
    <div class="card-header">Retur Gudang &mdash; dashboard kedua untuk barang kembali</div>
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div class="small">
        <strong><?= $gudangReturInfo['open'] ?></strong> barang kembali masih menunggu klarifikasi dari
        <?= $gudangReturInfo['total'] ?> total retur. Data DO/SPK tetap ada di menu <strong>Gudang</strong>.
      </div>
      <a class="btn btn-outline-primary" href="<?= BASE_URL ?>gudang_retur.php">Buka Retur Gudang</a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($showReport): ?>
<section class="mt-4">
  <div class="page-heading">
    <div>
      <h1>Distribusi Status DO</h1>
      <div class="page-subtitle">Ringkasan jumlah DO pada setiap tahap alur</div>
    </div>
  </div>
  <div class="card card-mcp">
    <div class="card-header">Distribusi Status DO Saat Ini</div>
    <div class="card-body">
      <div class="chart-box"><canvas id="chartStatus" aria-label="Grafik distribusi status DO"></canvas></div>
    </div>
  </div>

  <script src="<?= BASE_URL ?>library/chartjs/chart.umd.js"></script>
  <script>
  (function () {
    var status = <?= json_encode($statusData, $jsonFlags) ?>;
    if (!status.length) { return; }
    var warna = ['#0d4f8b', '#198754', '#ffb703', '#dc3545', '#6f42c1', '#0dcaf0', '#fd7e14', '#20c997'];
    new Chart(document.getElementById('chartStatus'), {
      type: 'doughnut',
      data: {
        labels: status.map(function (r) { return r.label; }),
        datasets: [{
          data: status.map(function (r) { return r.total; }),
          backgroundColor: status.map(function (_, i) { return warna[i % warna.length]; })
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '58%',
        plugins: { legend: { position: 'bottom' } }
      }
    });
  })();
  </script>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
