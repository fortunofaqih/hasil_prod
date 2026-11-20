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

$pageTitle = 'Dashboard';
require __DIR__ . '/header.php';
?>
<section>
  <div class="page-heading">
    <div>
      <h1>Dashboard</h1>
      <div class="page-subtitle">Halo, <?= e($user['nama']) ?> &mdash; peran: <?= e(strtoupper($role)) ?></div>
    </div>
  </div>

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
