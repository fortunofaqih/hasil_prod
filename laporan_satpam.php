<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['satpam', 'admin']);

$pdo = db();

$dari = (string) ($_GET['dari'] ?? date('Y-m-d'));
$sampai = (string) ($_GET['sampai'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) { $dari = date('Y-m-d'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) { $sampai = date('Y-m-d'); }
if ($dari > $sampai) { [$dari, $sampai] = [$sampai, $dari]; }

$stmt = $pdo->prepare('SELECT * FROM retur_header WHERE tgl_kembali BETWEEN ? AND ? ORDER BY tgl_kembali ASC, id ASC');
$stmt->execute([$dari, $sampai]);
$rows = $stmt->fetchAll();

$items = [];
foreach ($rows as $r) {
    $d = $pdo->prepare('SELECT ukuran, jumlah, satuan, keterangan FROM retur_detail WHERE retur_header_id=? ORDER BY id');
    $d->execute([(int) $r['id']]);
    $items[(int) $r['id']] = $d->fetchAll();
}

$totalItem = 0;
foreach ($items as $arr) { $totalItem += count($arr); }

$pageTitle = 'Laporan Satpam';
require __DIR__ . '/header.php';
?>
<style>
  @page { size: A4 portrait; margin: 14mm 12mm; }
  .laporan-kop { text-align: center; margin-bottom: 14px; }
  .laporan-kop h1 { font-size: 18pt; margin: 0; }
  .laporan-kop .sub { font-size: 10pt; }
  .laporan-tabel { width: 100%; border-collapse: collapse; }
  .laporan-tabel th, .laporan-tabel td { border: 1px solid #333; padding: 4px 5px; vertical-align: top; font-size: 9pt; }
  .laporan-tabel thead th { background: #e9ecef; font-weight: 700; }
  .laporan-ringkas { margin-top: 10px; font-size: 10pt; }
  @media print {
    body { background: #fff !important; }
    .app-header, .app-footer, .no-print, .page-heading { display: none !important; }
    .card, .card-mcp { border: 0 !important; box-shadow: none !important; margin: 0 !important; }
    .card-body, .card-header { padding: 0 !important; border: 0 !important; background: #fff !important; }
    .laporan-tabel thead { display: table-header-group; }
    .laporan-tabel tr { break-inside: avoid; page-break-inside: avoid; }
    .laporan-kop { margin-bottom: 8px; }
  }
</style>

<section>
  <div class="page-heading no-print">
    <div>
      <h1>Laporan Barang Kembalian</h1>
      <div class="page-subtitle">Cetak laporan harian satpam berdasarkan tanggal barang kembali</div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>barang_kembalian.php">&larr; Kembali</a>
  </div>

  <div class="card card-mcp no-print">
    <div class="card-header">Filter Periode</div>
    <div class="card-body">
      <form method="get" action="" class="row g-3 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label">Start date</label>
          <input type="date" class="form-control" name="dari" value="<?= e($dari) ?>" required>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">End date</label>
          <input type="date" class="form-control" name="sampai" value="<?= e($sampai) ?>" required>
        </div>
        <div class="col-12 col-md-4 d-flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary">Tampilkan</button>
          <button type="button" class="btn btn-success" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card card-mcp">
    <div class="card-body">
      <div class="laporan-kop">
        <h1>Laporan Barang Kembalian</h1>
        <div class="sub">Periode: <?= e(format_tanggal($dari)) ?> s/d <?= e(format_tanggal($sampai)) ?></div>
        <div class="sub">Dicetak oleh: <?= e($user['nama'] ?? '-') ?> &middot; <?= e(format_waktu(date('Y-m-d H:i:s'))) ?></div>
      </div>

      <?php if (!$rows): ?>
        <div class="empty-state">Tidak ada barang kembalian pada periode ini.</div>
      <?php else: ?>
        <div class="table-responsive-mcp">
          <table class="laporan-tabel">
            <thead>
              <tr>
                <th style="width:4%">No</th>
                <th style="width:10%">No SJ</th>
                <th style="width:10%">Nopol</th>
                <th style="width:11%">Sopir</th>
                <th style="width:15%">Customer</th>
                <th style="width:14%">Alasan</th>
                <th style="width:14%">Item</th>
                <th style="width:8%">Jumlah</th>
                <th style="width:7%">Satuan</th>
                <th style="width:17%">Keterangan</th>
              </tr>
            </thead>
            <tbody>
              <?php $no = 1; foreach ($rows as $r): ?>
                <?php $det = $items[(int) $r['id']] ?: [['ukuran' => '-', 'jumlah' => '-', 'satuan' => '-', 'keterangan' => '-']]; ?>
                <?php foreach ($det as $i => $d): ?>
                  <tr>
                    <?php if ($i === 0): ?>
                      <td rowspan="<?= count($det) ?>"><?= $no ?></td>
                      <td rowspan="<?= count($det) ?>"><?= e($r['no_sj']) ?></td>
                      <td rowspan="<?= count($det) ?>"><?= e($r['nopol']) ?></td>
                      <td rowspan="<?= count($det) ?>"><?= e($r['nama_sopir']) ?></td>
                      <td rowspan="<?= count($det) ?>"><?= e($r['customer']) ?></td>
                      <td rowspan="<?= count($det) ?>"><?= e(ucfirst((string) $r['alasan'])) ?></td>
                    <?php endif; ?>
                    <td><?= e($d['ukuran']) ?></td>
                    <td><?= e(format_angka($d['jumlah'])) ?></td>
                    <td><?= e($d['satuan']) ?></td>
                    <td><?= e($d['keterangan']) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php $no++; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="laporan-ringkas">
          <strong>Total retur:</strong> <?= count($rows) ?> &nbsp;|&nbsp; <strong>Total item:</strong> <?= $totalItem ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
