<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'satpam']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);

$alasanList = [
    'belum terverifikasi',
    'gagal kirim - customer tutup',
    'gagal kirim - sisa sales kelilingan',
    'keluhan mutu',
    'titipan',
];
$satuanList = ['roll', 'bal', 'KG', 'lembar'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!csrf_check()) { set_flash('Token tidak valid.', 'danger'); redirect('barang_kembalian.php'); }
    $editId = (int) post('id');
    $cekSt = $pdo->prepare('SELECT status FROM retur_header WHERE id=?');
    $cekSt->execute([$editId]);
    tolak_jika_closed($cekSt->fetchColumn(), 'barang_kembalian.php?act=form&id=' . $editId . '');
    $noSj = strtoupper(post('no_sj'));
    if ($noSj === '') { $noSj = '-'; }
    $ukuranArr = $_POST['ukuran'] ?? [];
    $jumlahArr = $_POST['jumlah'] ?? [];
    $satuanArr = $_POST['satuan'] ?? [];
    $ketArr = $_POST['keterangan'] ?? [];
    $kgArr = $_POST['jumlah_kg'] ?? [];
    $alasan = post('alasan');

    if (post('nopol') === '' || post('nama_sopir') === '' || post('customer') === ''
        || !in_array($alasan, $alasanList, true) || !is_array($ukuranArr) || count($ukuranArr) === 0) {
        set_flash('Lengkapi field wajib dan minimal satu item barang.', 'danger');
        redirect('barang_kembalian.php?act=form' . ($editId ? '&id=' . $editId : ''));
    }

    try {
        $pdo->beginTransaction();
        if ($editId > 0) {
            $pdo->prepare('UPDATE retur_header SET no_sj=?, nopol=?, nama_sopir=?, customer=?, tgl_kembali=?,
                           alasan=?, komplain_id=?, modified_by=?, modified_at=? WHERE id=?')
                ->execute([$noSj, strtoupper(post('nopol')), post('nama_sopir'), post('customer'), post('tgl_kembali'),
                    $alasan, (post('komplain_id') ?: null) ?: null, user_id(), date('Y-m-d H:i:s'), $editId]);
            $pdo->prepare('DELETE FROM retur_detail WHERE retur_header_id=?')->execute([$editId]);
            $returId = $editId;
        } else {
            $noRetur = nomor_otomatis('RTR', 'retur_header', 'id');
            $pdo->prepare('INSERT INTO retur_header
                (no_retur, no_sj, nopol, nama_sopir, customer, tgl_kembali, alasan, status, komplain_id, created_by, modified_by, created_at, modified_at)
                VALUES (?,?,?,?,?,?,?,\'open\',?,?,?,NOW(),NOW())')
                ->execute([$noRetur, $noSj, strtoupper(post('nopol')), post('nama_sopir'), post('customer'),
                    post('tgl_kembali'), $alasan, (post('komplain_id') ?: null) ?: null, user_id(), user_id()]);
            $returId = (int) $pdo->lastInsertId();
        }

        $ins = $pdo->prepare('INSERT INTO retur_detail
            (retur_header_id, ukuran, jumlah, jumlah_kg, satuan, keterangan, created_by, modified_by, created_at, modified_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');
        foreach ($ukuranArr as $i => $uk) {
            if (trim((string) $uk) === '') { continue; }
            $ins->execute([$returId, trim((string) $uk), (float) ($jumlahArr[$i] ?? 0),
                (float) ($kgArr[$i] ?? 0), $satuanArr[$i] ?? 'roll', $ketArr[$i] ?? '', user_id(), user_id()]);
        }
        $pdo->commit();
        set_flash('Barang kembalian tersimpan. Menunggu konfirmasi Admin Gudang.');
        redirect('barang_kembalian.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('barang_kembalian.php?act=form' . ($editId ? '&id=' . $editId : ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (!csrf_check()) { set_flash('Token tidak valid.', 'danger'); redirect('barang_kembalian.php'); }
    $hapus = (int) post('id');
    $row = $pdo->prepare('SELECT status, created_by FROM retur_header WHERE id=?');
    $row->execute([$hapus]);
    $r = $row->fetch();
    if ($r && $r['status'] === 'open' && ((int) $r['created_by'] === user_id() || $user['role'] === 'admin')) {
        $pdo->prepare('DELETE FROM retur_header WHERE id=?')->execute([$hapus]);
        set_flash('Data barang kembalian dihapus.');
    } else {
        set_flash('Data tidak bisa dihapus (sudah close atau bukan milik Anda).', 'danger');
    }
    redirect('barang_kembalian.php');
}

$list = $pdo->query('SELECT r.*, (SELECT COUNT(*) FROM retur_detail d WHERE d.retur_header_id=r.id) AS item_count
                     FROM retur_header r ORDER BY r.id DESC')->fetchAll();
$komplainRetur = $pdo->query("SELECT id, no_komplain, no_sj, customer, tgl_komplain, status FROM komplain_header
                              WHERE jenis='retur' ORDER BY id DESC")->fetchAll();

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari(array_merge($list, $komplainRetur), $statusSekarang);
$list = status_pisah($list, $statusSekarang);
$komplainRetur = status_pisah($komplainRetur, $statusSekarang);

$edit = null; $editItems = [];
if ($act === 'form' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM retur_header WHERE id=?');
    $stmt->execute([$id]); $edit = $stmt->fetch();
    if ($edit) {
        $stmt = $pdo->prepare('SELECT * FROM retur_detail WHERE retur_header_id=? ORDER BY id');
        $stmt->execute([$id]); $editItems = $stmt->fetchAll();
    }
}

// Prefill form saat satpam menekan "Catat" pada komplain retur marketing.
$prefill = null; $prefillItems = [];
if ($act === 'form' && !$edit) {
    $kid = (int) ($_GET['komplain'] ?? 0);
    if ($kid > 0) {
        $stmt = $pdo->prepare('SELECT * FROM komplain_header WHERE id=?');
        $stmt->execute([$kid]); $kom = $stmt->fetch();
        if ($kom) {
            $prefill = [
                'no_sj' => $kom['no_sj'], 'customer' => $kom['customer'],
                'tgl_kembali' => date('Y-m-d'), 'alasan' => 'keluhan mutu', 'komplain_id' => $kom['id'],
            ];
            $stmt = $pdo->prepare('SELECT * FROM komplain_detail WHERE komplain_header_id=? ORDER BY id');
            $stmt->execute([$kid]);
            foreach ($stmt->fetchAll() as $d) {
                $prefillItems[] = ['ukuran' => $d['ukuran'], 'jumlah' => $d['jumlah'],
                    'jumlah_kg' => $d['jumlah_kg'] ?? 0,
                    'satuan' => $d['satuan'], 'keterangan' => $d['keterangan']];
            }
        }
    }
}
$data = $edit ?: ($prefill ?? []);

$pageTitle = 'Barang Kembalian';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'form'): ?>
<section>
  <div class="page-heading">
    <div><h1><?= $edit ? 'Edit Barang Kembalian' : ($prefill ? 'Catat Komplain Retur' : 'Input Barang Kembalian') ?></h1>
      <div class="page-subtitle">Pos Satpam &mdash; PT. MCP</div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>barang_kembalian.php">&larr; Kembali</a>
  </div>
  <?= audit_card($edit ?: null, 'Audit Data Retur') ?>
  <?php if (($data['marketing_confirm'] ?? '') === 'yes'): ?>
    <div class="alert alert-success small">
      <i class="bi bi-check-circle"></i> confirm by <strong><?= e(user_username($data['marketing_confirm_by'] ?? null)) ?></strong>
      pada <?= e(format_waktu($data['marketing_confirm_at'] ?? null)) ?>
    </div>
  <?php elseif (($data['marketing_confirm'] ?? '') === 'no'): ?>
    <div class="alert alert-danger small">Ditolak marketing oleh <?= user_label($data['marketing_confirm_by'] ?? null) ?>.</div>
  <?php endif; ?>
    <form method="post" action=""<?= form_readonly_attr($data['status'] ?? '') ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

    <div class="card card-mcp">
      <div class="card-header">Data Kendaraan &amp; Surat Jalan</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-6"><label class="form-label">No. Polisi <span class="text-danger">*</span></label>
            <input class="form-control" name="nopol" required value="<?= e($data['nopol'] ?? '') ?>" placeholder="B 1234 ABC"></div>
          <div class="col-12 col-md-6"><label class="form-label">Nama Sopir <span class="text-danger">*</span></label>
            <input class="form-control" name="nama_sopir" required value="<?= e($data['nama_sopir'] ?? '') ?>"></div>
          <div class="col-12 col-md-6"><label class="form-label">No. Surat Jalan (SJ)</label>
            <input class="form-control" name="no_sj" placeholder="isi '-' jika tidak ada no.SJ" value="<?= e($data['no_sj'] ?? '') ?>">
            <div class="field-hint">Kosong boleh — akan disimpan sebagai &ldquo;-&rdquo;.</div></div>
          <div class="col-12 col-md-6"><label class="form-label">Customer <span class="text-danger">*</span></label>
            <input class="form-control" name="customer" required value="<?= e($data['customer'] ?? '') ?>"></div>
          <div class="col-12 col-md-6"><label class="form-label">Tanggal Kembali</label>
            <input type="date" class="form-control" name="tgl_kembali" value="<?= e($data['tgl_kembali'] ?? date('Y-m-d')) ?>"></div>
        </div>
      </div>
    </div>

    <div class="card card-mcp">
      <div class="card-header">Alasan Kembali</div>
      <div class="card-body">
        <div class="row g-3">
          <?php foreach ($alasanList as $a): ?>
            <div class="col-12 col-md-6">
              <div class="form-check form-check-card border rounded">
                <input class="form-check-input" type="radio" name="alasan" id="alasan<?= md5($a) ?>"
                       value="<?= e($a) ?>" required <?= (($data['alasan'] ?? '') === $a) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="alasan<?= md5($a) ?>"><?= e(ucfirst($a)) ?></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-3">
          <label class="form-label">Kaitkan Komplain Retur Marketing (khusus Keluhan Mutu)</label>
          <select class="form-select" name="komplain_id" id="komplain_id">
            <option value="">-- Tidak ada / input mandiri --</option>
            <?php foreach ($komplainRetur as $k): ?>
              <option value="<?= (int)$k['id'] ?>" <?= ((int)($data['komplain_id'] ?? 0) === (int)$k['id']) ? 'selected' : '' ?>>
                <?= e($k['no_komplain']) ?> | SJ <?= e($k['no_sj']) ?> | <?= e($k['customer']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="card card-mcp">
      <div class="card-header">Item / Kelompok Barang</div>
      <div class="card-body">
        <div class="table-responsive-mcp">
          <table class="table table-mcp" id="itemTable" style="min-width:800px">
            <thead><tr><th>Ukuran</th><th>Jumlah (KG)</th><th>Jumlah</th><th>Satuan</th><th>Keterangan</th><th></th></tr></thead>
            <tbody>
              <?php $items = $editItems ?: ($prefillItems ?: [['ukuran'=>'','jumlah'=>'','jumlah_kg'=>'','satuan'=>'roll','keterangan'=>'']]); ?>
              <?php foreach ($items as $it): ?>
                <tr class="item-row">
                  <td><input class="form-control form-control-sm" name="ukuran[]" value="<?= e($it['ukuran']) ?>" required></td>
                  <td>
                    <div class="input-group input-group-sm">
                      <input type="number" step="0.01" min="0" class="form-control" name="jumlah_kg[]"
                             value="<?= e($it['jumlah_kg'] ?? '0') ?>">
                      <span class="input-group-text">KG</span>
                    </div>
                  </td>
                  <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="jumlah[]" value="<?= e($it['jumlah']) ?>" required></td>
                  <td><select class="form-select form-select-sm" name="satuan[]">
                        <?php foreach ($satuanList as $s): ?>
                          <option value="<?= $s ?>" <?= (($it['satuan'] ?? 'roll') === $s) ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                      </select></td>
                  <td><input class="form-control form-control-sm" name="keterangan[]" value="<?= e($it['keterangan'] ?? '') ?>"></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger hapus-item">&times;</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="tambahItem">+ Tambah Item</button>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Simpan &amp; Kirim ke Gudang</button>
  </form>
</section>
<?php else: ?>
<section>
  <div class="page-heading">
    <div><h1>Barang Kembalian</h1><div class="page-subtitle">Data barang yang kembali dari customer</div></div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-primary" href="<?= BASE_URL ?>laporan_satpam.php"><i class="bi bi-printer"></i> Cetak Laporan</a>
      <a class="btn btn-primary" href="<?= BASE_URL ?>barang_kembalian.php?act=form">+ Input Barang</a>
    </div>
  </div>

  <?= $tabsStatus ?>
  <div class="card card-mcp">
    <div class="card-header">Komplain Retur Marketing (Keluhan Mutu) &mdash; Info Bersama</div>
    <div class="card-body p-0">
      <?php if (!$komplainRetur): ?><div class="empty-state">Belum ada komplain retur dari marketing.</div>
      <?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:620px">
          <thead><tr><th>No. Komplain</th><th>No. SJ</th><th>Customer</th><th>Tanggal</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody><?php foreach ($komplainRetur as $k): ?><tr>
            <td><?= e($k['no_komplain']) ?></td><td><?= e($k['no_sj']) ?></td><td><?= e($k['customer']) ?></td>
            <td><?= e(format_tanggal($k['tgl_komplain'])) ?></td><td><?= status_tiket($k['status']) ?></td>
            <td class="action-col">
              <?php if (status_is_open($k['status'])): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>barang_kembalian.php?act=form&komplain=<?= (int)$k['id'] ?>">Catat</a>
              <?php else: ?><span class="text-mute small">Selesai</span><?php endif; ?>
            </td>
          </tr><?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card card-mcp">
    <div class="card-header">Laporan Barang Kembalian</div>
    <div class="card-body p-0">
      <?php if (!$list): ?><div class="empty-state">Belum ada barang kembalian.</div>
      <?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:1250px">
        <thead><tr><th>No. Retur</th><th>No. SJ</th><th>Nopol</th><th>Sopir</th><th>Customer</th><th>Alasan</th><th>Konfirmasi Marketing</th><th>Item / Jumlah / Satuan / Keterangan</th><th>Status</th><th>Aksi</th></tr></thead>
        <tbody>
          <?php foreach ($list as $r): ?>
            <?php
              $di = $pdo->prepare('SELECT ukuran, jumlah, satuan, keterangan FROM retur_detail WHERE retur_header_id=? ORDER BY id');
              $di->execute([$r['id']]);
              $detailRows = $di->fetchAll();
            ?>
            <tr>
              <td><?= e($r['no_retur']) ?></td><td><?= e($r['no_sj']) ?></td><td><?= e($r['nopol']) ?></td>
              <td><?= e($r['nama_sopir']) ?></td><td><?= e($r['customer']) ?></td>
              <td><?= alasan_badge($r['alasan']) ?></td>
              <td class="small">
                <?php if ($r['marketing_confirm'] === 'yes'): ?>
                  <span class="badge bg-success text-white">confirm by <?= e(user_username($r['marketing_confirm_by'])) ?></span>
                  <div class="text-mute"><?= e(format_waktu($r['marketing_confirm_at'])) ?></div>
                <?php elseif ($r['marketing_confirm'] === 'no'): ?>
                  <span class="badge bg-danger">Ditolak marketing</span>
                <?php elseif ($r['komplain_id']): ?>
                  <span class="badge bg-info text-dark">Terhubung komplain</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Menunggu konfirmasi</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if (!$detailRows): ?>-<?php else: ?>
                  <?php foreach ($detailRows as $d): ?>
                    <div class="mb-1">
                      <strong><?= e($d['ukuran']) ?></strong> &mdash; <?= e(format_angka($d['jumlah'])) ?> <?= e($d['satuan']) ?>
                      <?php if ((float) ($d['jumlah_kg'] ?? 0) > 0): ?><span class="text-mute"> &middot; <?= e(format_angka($d['jumlah_kg'])) ?> KG</span><?php endif; ?>
                      <?php if (!empty($d['keterangan'])): ?><br><span class="text-mute"><?= e($d['keterangan']) ?></span><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td><?= status_tiket($r['status']) ?></td>
                <td class="action-col">
                  <?php if ($r['status'] === 'open'): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>barang_kembalian.php?act=form&id=<?= (int)$r['id'] ?>">Edit</a>
                    <form method="post" action="" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" data-confirm="Hapus data ini?" type="submit">Delete</button>
                    </form>
                  <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>barang_kembalian.php?act=form&id=<?= (int)$r['id'] ?>">Lihat</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<script>
document.getElementById('tambahItem')?.addEventListener('click', function () {
  const tbody = document.querySelector('#itemTable tbody');
  const row = tbody.querySelector('.item-row').cloneNode(true);
  row.querySelectorAll('input').forEach(i => i.value = '');
  tbody.appendChild(row);
});
document.getElementById('itemTable')?.addEventListener('click', function (e) {
  if (e.target.classList.contains('hapus-item') && document.querySelectorAll('#itemTable .item-row').length > 1) {
    e.target.closest('tr').remove();
  }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
