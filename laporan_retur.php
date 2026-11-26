<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'manager']);

$pdo = db();

$komplain = $pdo->query("SELECT k.no_komplain, k.no_sj, k.customer, k.jenis, k.status, k.tgl_komplain,
                         (SELECT COUNT(*) FROM komplain_detail d WHERE d.komplain_header_id=k.id) AS item
                         FROM komplain_header k ORDER BY k.id DESC")->fetchAll();
$retur = $pdo->query("SELECT r.no_retur, r.no_sj, r.customer, r.nopol, r.alasan, r.asal_usul, r.status, r.tgl_kembali,
                      (SELECT COUNT(*) FROM retur_detail d WHERE d.retur_header_id=r.id) AS item
                      FROM retur_header r ORDER BY r.id DESC")->fetchAll();
$gudang = $pdo->query("SELECT g.tgl_terima, r.no_retur, r.no_sj, r.customer, g.catatan, g.status,
                      (SELECT SUM(d.jumlah) FROM gudang_retur_detail d WHERE d.gudang_retur_header_id=g.id) AS total_jumlah,
                      (SELECT SUM(d.berat_kg) FROM gudang_retur_detail d WHERE d.gudang_retur_header_id=g.id) AS total_berat
                      FROM gudang_retur_header g JOIN retur_header r ON r.id=g.retur_id ORDER BY g.id DESC")->fetchAll();
$produksi = $pdo->query("SELECT p.tgl_tindakan, k.no_komplain, k.no_sj, k.customer, p.status, p.keterangan
                         FROM produksi_retur_header p JOIN komplain_header k ON k.id=p.komplain_id ORDER BY p.id DESC")->fetchAll();

$hitung = function (array $rows): array {
    $open = 0; $close = 0;
    foreach ($rows as $r) { ($r['status'] === 'closed') ? $close++ : $open++; }
    return [$open, $close];
};
[$kOpen, $kClose] = $hitung($komplain);
[$rOpen, $rClose] = $hitung($retur);
[$gOpen, $gClose] = $hitung($gudang);
[$pOpen, $pClose] = $hitung($produksi);

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari(array_merge($komplain, $retur, $gudang, $produksi), $statusSekarang);
$komplain = status_pisah($komplain, $statusSekarang);
$retur = status_pisah($retur, $statusSekarang);
$gudang = status_pisah($gudang, $statusSekarang);
$produksi = status_pisah($produksi, $statusSekarang);

$pageTitle = 'Laporan Retur';
require __DIR__ . '/header.php';
?>
<section>
  <div class="page-heading">
    <div><h1>Laporan Retur &amp; Komplain</h1>
      <div class="page-subtitle">Progres tiap bagian: Marketing, Satpam, Gudang, dan Produksi</div></div>
  </div>

  <div class="row g-3">
    <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">✎</div><div>
      <div class="stat-value"><?= $kOpen ?></div><div class="stat-label">Marketing OPEN / <?= $kClose ?> CLOSE</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">▤</div><div>
      <div class="stat-value"><?= $rOpen ?></div><div class="stat-label">Satpam OPEN / <?= $rClose ?> CLOSE</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">⚙</div><div>
      <div class="stat-value"><?= $pOpen ?></div><div class="stat-label">Produksi OPEN / <?= $pClose ?> CLOSE</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-mcp stat-card"><div class="stat-icon">✓</div><div>
      <div class="stat-value"><?= $gOpen ?></div><div class="stat-label">Gudang OPEN / <?= $gClose ?> CLOSE</div></div></div></div>
  </div>

  <div class="alert alert-light border small">
    <strong>OPEN</strong> = masih ada tindak lanjut pada bagian terkait. <strong>CLOSE</strong> = seluruh bagian terkait sudah menyelesaikan tindak lanjut.
  </div>

  <ul class="nav nav-tabs flex-nowrap overflow-auto" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabMarketing" type="button">Marketing</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSatpam" type="button">Satpam</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabProduksi" type="button">Produksi</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabGudang" type="button">Gudang</button></li>
  </ul>

  <div class="tab-content pt-3">
    <?= $tabsStatus ?>
    <div class="tab-pane fade show active" id="tabMarketing">
      <div class="card card-mcp"><div class="card-header">Laporan Marketing &mdash; Komplain Mutu</div><div class="card-body p-0">
        <?php if (!$komplain): ?><div class="empty-state">Belum ada komplain.</div><?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:720px">
          <thead><tr><th>No. Komplain</th><th>No. SJ</th><th>Customer</th><th>Jenis</th><th>Item</th><th>Tgl</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($komplain as $r): ?><tr>
            <td><?= e($r['no_komplain']) ?></td><td><?= e($r['no_sj']) ?></td><td><?= e($r['customer']) ?></td>
            <td><?= $r['jenis'] === 'retur' ? 'Retur' : 'Komplain saja' ?></td><td><?= (int)$r['item'] ?></td>
            <td><?= e(format_tanggal($r['tgl_komplain'])) ?></td><td><?= status_tiket($r['status']) ?></td>
          </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div></div>
    </div>

    <div class="tab-pane fade" id="tabSatpam">
      <div class="card card-mcp"><div class="card-header">Laporan Satpam &mdash; Barang Kembalian</div><div class="card-body p-0">
        <?php if (!$retur): ?><div class="empty-state">Belum ada barang kembalian.</div><?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:820px">
          <thead><tr><th>No. Retur</th><th>No. SJ</th><th>Nopol</th><th>Customer</th><th>Alasan</th><th>Hasil Cek Gudang</th><th>Item</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($retur as $r): ?><tr>
            <td><?= e($r['no_retur']) ?></td><td><?= e($r['no_sj']) ?></td><td><?= e($r['nopol']) ?></td><td><?= e($r['customer']) ?></td>
            <td><?= alasan_badge($r['alasan']) ?></td><td><?= $r['asal_usul'] ? e(ucfirst($r['asal_usul'])) : '-' ?></td>
            <td><?= (int)$r['item'] ?></td><td><?= status_tiket($r['status']) ?></td>
          </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div></div>
    </div>

    <div class="tab-pane fade" id="tabProduksi">
      <div class="card card-mcp"><div class="card-header">Laporan Admin Produksi &mdash; Tindak Lanjut Retur</div><div class="card-body p-0">
        <?php if (!$produksi): ?><div class="empty-state">Belum ada tindak lanjut produksi.</div><?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:760px">
          <thead><tr><th>Tgl</th><th>No. Komplain</th><th>No. SJ</th><th>Customer</th><th>Keterangan Tindak Lanjut</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($produksi as $r): ?><tr>
            <td><?= e(format_tanggal($r['tgl_tindakan'])) ?></td><td><?= e($r['no_komplain']) ?></td><td><?= e($r['no_sj']) ?></td>
            <td><?= e($r['customer']) ?></td>
            <td><?= e(mb_strimwidth((string)$r['keterangan'], 0, 100, '...')) ?></td><td><?= status_tiket($r['status']) ?></td>
          </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div></div>
    </div>

    <div class="tab-pane fade" id="tabGudang">
      <div class="card card-mcp"><div class="card-header">Laporan Admin Gudang &mdash; Barang Diterima</div><div class="card-body p-0">
        <?php if (!$gudang): ?><div class="empty-state">Belum ada penerimaan gudang.</div><?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:760px">
          <thead><tr><th>Tgl Terima</th><th>No. Retur</th><th>No. SJ</th><th>Customer</th><th>Jumlah</th><th>Berat (KG)</th><th>Catatan</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($gudang as $r): ?><tr>
            <td><?= e(format_tanggal($r['tgl_terima'])) ?></td><td><?= e($r['no_retur']) ?></td><td><?= e($r['no_sj']) ?></td>
            <td><?= e($r['customer']) ?></td><td><?= e(format_angka($r['total_jumlah'])) ?></td>
            <td><?= e(number_format((float)$r['total_berat'], 2, ',', '.')) ?></td>
            <td><?= e(mb_strimwidth((string)$r['catatan'], 0, 40, '...')) ?></td><td><?= status_tiket($r['status']) ?></td>
          </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div></div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
