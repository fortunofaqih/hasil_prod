<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'gudang']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0); // retur_header id
$alasanList = [
    'belum terverifikasi',
    'gagal kirim - customer tutup',
    'gagal kirim - sisa sales kelilingan',
    'keluhan mutu',
    'titipan',
];
$satuanList = ['roll', 'bal', 'KG', 'lembar'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!csrf_check()) { set_flash('Token tidak valid.', 'danger'); redirect('gudang_retur.php'); }
    $returId = (int) post('retur_id');
    $asal = post('asal_usul');
    if (!in_array($asal, $alasanList, true) || post('tgl_terima') === '') {
        set_flash('Tanggal terima dan asal usul wajib diisi.', 'danger');
        redirect('gudang_retur.php?act=form&id=' . $returId);
    }
    $r = $pdo->prepare('SELECT * FROM retur_header WHERE id=?');
    $r->execute([$returId]); $retur = $r->fetch();
    if (!$retur) { set_flash('Data retur tidak ditemukan.', 'danger'); redirect('gudang_retur.php'); }
    tolak_jika_closed($retur['status'], 'gudang_retur.php?act=form&id=' . $returId);
    if (strtolower((string) $retur['alasan']) === 'keluhan mutu' && !empty($retur['komplain_id'])) {
        set_flash('Retur keluhan mutu dari komplain ditutup oleh admin produksi, bukan oleh gudang.', 'warning');
        redirect('gudang_retur.php');
    }

    $ukArr = $_POST['ukuran'] ?? []; $jumArr = $_POST['jumlah'] ?? [];
    $satArr = $_POST['satuan'] ?? []; $beratArr = $_POST['berat_kg'] ?? [];

    try {
        $pdo->beginTransaction();
        $exist = $pdo->prepare('SELECT id FROM gudang_retur_header WHERE retur_id=? ORDER BY id DESC LIMIT 1');
        $exist->execute([$returId]); $headId = (int) $exist->fetchColumn();
        if ($headId) {
            $pdo->prepare('UPDATE gudang_retur_header SET tgl_terima=?, status=\'closed\', catatan=?, modified_by=?, modified_at=? WHERE id=?')
                ->execute([post('tgl_terima'), post('catatan'), user_id(), date('Y-m-d H:i:s'), $headId]);
            $pdo->prepare('DELETE FROM gudang_retur_detail WHERE gudang_retur_header_id=?')->execute([$headId]);
        } else {
            $pdo->prepare('INSERT INTO gudang_retur_header
                (retur_id, tgl_terima, status, catatan, created_by, modified_by, created_at, modified_at)
                VALUES (?,?, \'closed\', ?,?,?,NOW(),NOW())')
                ->execute([$returId, post('tgl_terima'), post('catatan'), user_id(), user_id()]);
            $headId = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO gudang_retur_detail
            (gudang_retur_header_id, ukuran, jumlah, satuan, berat_kg, created_by, modified_by, created_at, modified_at)
            VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
        foreach ($ukArr as $i => $uk) {
            if (trim((string) $uk) === '') { continue; }
            $ins->execute([$headId, trim((string) $uk), (float) ($jumArr[$i] ?? 0), $satArr[$i] ?? 'roll',
                (float) ($beratArr[$i] ?? 0), user_id(), user_id()]);
        }
        $pdo->prepare('UPDATE retur_header SET status=\'closed\', asal_usul=?, catatan_gudang=?, modified_by=?, modified_at=? WHERE id=?')
            ->execute([$asal, post('catatan'), user_id(), date('Y-m-d H:i:s'), $returId]);

        // Tutup komplain retur bila tindak lanjut produksi juga sudah selesai.
        if (!empty($retur['komplain_id'])) {
            $kid = (int) $retur['komplain_id'];
            $totalProd = (int) $pdo->query("SELECT COUNT(*) FROM produksi_retur_header WHERE komplain_id=$kid")->fetchColumn();
            $openProd = (int) $pdo->query("SELECT COUNT(*) FROM produksi_retur_header WHERE komplain_id=$kid AND status<>'closed'")->fetchColumn();
            if ($totalProd > 0 && $openProd === 0) {
                $pdo->prepare('UPDATE komplain_header SET status=\'closed\', modified_by=?, modified_at=? WHERE id=?')
                    ->execute([user_id(), date('Y-m-d H:i:s'), $kid]);
            }
        }
        $pdo->commit();
        set_flash('Barang kembalian dicatat dan ditutup (CLOSE) oleh gudang.');
        redirect('gudang_retur.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('gudang_retur.php?act=form&id=' . $returId);
    }
}

$list = $pdo->query("SELECT r.*, k.no_komplain, k.created_by AS marketing_by, k.created_at AS marketing_at,
                     (SELECT COUNT(*) FROM retur_detail d WHERE d.retur_header_id=r.id) AS item_count
                     FROM retur_header r
                     LEFT JOIN komplain_header k ON k.id=r.komplain_id
                     ORDER BY r.id DESC")->fetchAll();
$komplainRetur = $pdo->query("SELECT id, no_komplain, no_sj, customer, tgl_komplain, status
                              FROM komplain_header WHERE jenis='retur' ORDER BY id DESC")->fetchAll();

$retur = null; $items = []; $gudangRow = null; $gudangItems = [];
$komplainData = null; $komplainItems = []; $komplainKg = [];
if ($act === 'form' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM retur_header WHERE id=?');
    $stmt->execute([$id]); $retur = $stmt->fetch();
    if ($retur && strtolower((string) $retur['alasan']) === 'keluhan mutu' && !empty($retur['komplain_id']) && status_is_open($retur['status'])) {
        set_flash('Retur keluhan mutu dari komplain ditutup oleh admin produksi, bukan oleh gudang.', 'warning');
        redirect('gudang_retur.php');
    }
    if ($retur) {
        $stmt = $pdo->prepare('SELECT * FROM retur_detail WHERE retur_header_id=? ORDER BY id');
        $stmt->execute([$id]); $items = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM gudang_retur_header WHERE retur_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]); $gudangRow = $stmt->fetch();
        if ($gudangRow) {
            $stmt = $pdo->prepare('SELECT * FROM gudang_retur_detail WHERE gudang_retur_header_id=? ORDER BY id');
            $stmt->execute([$gudangRow['id']]);
            foreach ($stmt->fetchAll() as $d) { $gudangItems[$d['ukuran']] = $d; }
        }
        if (!empty($retur['komplain_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM komplain_header WHERE id=?');
            $stmt->execute([$retur['komplain_id']]); $komplainData = $stmt->fetch();
            if ($komplainData) {
                $stmt = $pdo->prepare('SELECT * FROM komplain_detail WHERE komplain_header_id=? ORDER BY id');
                $stmt->execute([$retur['komplain_id']]); $komplainItems = $stmt->fetchAll();
            }
        }
        $komplainKg = [];
        foreach ($komplainItems as $ki) {
            $komplainKg[$ki['ukuran']] = (float) ($ki['jumlah_kg'] ?? 0);
        }
    }
}

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari(array_merge($list, $komplainRetur), $statusSekarang);
$list = status_pisah($list, $statusSekarang);
$komplainRetur = status_pisah($komplainRetur, $statusSekarang);

$pageTitle = 'Retur Gudang';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'form' && $retur): ?>
<section>
  <div class="page-heading">
    <div><h1>Catat Barang Kembalian</h1><div class="page-subtitle">Retur <?= e($retur['no_retur']) ?> &middot; SJ <?= e($retur['no_sj']) ?></div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>gudang_retur.php">&larr; Kembali</a>
  </div>
  <?= audit_card($retur ?: null, 'Audit Input Satpam') ?>
  <?= audit_card($gudangRow ?: null, 'Audit Penerimaan Gudang') ?>
  <form method="post" action=""<?= form_readonly_attr($retur['status'] ?? '') ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="retur_id" value="<?= (int)$retur['id'] ?>">
    <div class="card card-mcp">
      <div class="card-header">Data Barang Kembali</div>
      <div class="card-body">
        <dl class="kv">
          <dt>Nopol</dt><dd><?= e($retur['nopol']) ?></dd>
          <dt>Sopir</dt><dd><?= e($retur['nama_sopir']) ?></dd>
          <dt>Customer</dt><dd><?= e($retur['customer']) ?></dd>
          <dt>Tgl Kembali</dt><dd><?= e(format_tanggal($retur['tgl_kembali'])) ?></dd>
          <dt>Alasan Satpam</dt><dd><?= alasan_badge($retur['alasan']) ?></dd>
          <dt>Status</dt><dd><?= status_tiket($retur['status']) ?></dd>
          <dt>Dicatat Satpam</dt><dd><?= user_label($retur['created_by']) ?> &middot; <?= e(format_waktu($retur['created_at'])) ?></dd>
          <?php if ($komplainData): ?>
            <dt>Dicatat Marketing</dt><dd><?= user_label($komplainData['created_by']) ?> &middot; <?= e(format_waktu($komplainData['created_at'])) ?></dd>
            <dt>No. Komplain</dt><dd><?= e($komplainData['no_komplain']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <?php if ($komplainData): ?>
      <div class="card card-mcp">
        <div class="card-header">Data Komplain Marketing (Pembanding)</div>
        <div class="card-body">
          <dl class="kv">
            <dt>No. Komplain</dt><dd><?= e($komplainData['no_komplain']) ?></dd>
            <dt>No. SJ</dt><dd><?= e($komplainData['no_sj']) ?></dd>
            <dt>Customer</dt><dd><?= e($komplainData['customer']) ?></dd>
            <dt>Dibuat oleh</dt><dd><?= user_label($komplainData['created_by']) ?></dd>
            <dt>Dibuat pada</dt><dd><?= e(format_waktu($komplainData['created_at'])) ?></dd>
          </dl>
          <div class="table-responsive-mcp mt-3">
            <table class="table table-mcp mb-0" style="min-width:620px">
              <thead><tr><th>Ukuran</th><th>Jumlah Marketing</th><th>Jumlah (KG)</th><th>Satuan</th><th>Keterangan</th></tr></thead>
              <tbody>
                <?php foreach ($komplainItems as $ki): ?>
                  <tr>
                    <td><?= e($ki['ukuran']) ?></td>
                    <td><?= e(format_angka($ki['jumlah'])) ?></td>
                    <td><?= e(format_angka($ki['jumlah_kg'] ?? 0)) ?></td>
                    <td><?= e($ki['satuan']) ?></td>
                    <td><?= e($ki['keterangan']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="field-hint mt-2">Bandingkan dengan catatan satpam di bawah, lalu isi jumlah, satuan, dan berat aktual (KG).</div>
        </div>
      </div>
      <?= audit_card($komplainData ?: null, 'Audit Komplain Marketing') ?>
    <?php endif; ?>

    <div class="card card-mcp">
      <div class="card-header">Konfirmasi Gudang</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-6"><label class="form-label">Tanggal Terima</label>
            <input type="date" class="form-control" name="tgl_terima" required value="<?= e($gudangRow['tgl_terima'] ?? date('Y-m-d')) ?>"></div>
          <div class="col-12 col-md-6"><label class="form-label">Hasil Cek Gudang <span class="text-danger">*</span></label>
            <select class="form-select" name="asal_usul" required>
              <?php foreach ($alasanList as $a): ?>
                <option value="<?= e($a) ?>" <?= (($retur['asal_usul'] ?? $retur['alasan']) === $a) ? 'selected' : '' ?>><?= e(ucfirst($a)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-12"><label class="form-label">Catatan Gudang</label>
            <textarea class="form-control" name="catatan" rows="2"><?= e($gudangRow['catatan'] ?? '') ?></textarea></div>
        </div>
      </div>
    </div>
    <div class="card card-mcp">
      <div class="card-header">Catatan Fisik Barang (Jumlah, Satuan &amp; Berat Aktual)</div>
      <div class="card-body">
        <div class="table-responsive-mcp"><table class="table table-mcp" style="min-width:1000px">
          <thead><tr><th>Ukuran</th><th>Jumlah</th><th>Satuan</th><th>Jumlah Dicatat Gudang</th><th>Satuan Gudang</th><th>BERAT KIRIM (KG)</th><th>Berat Aktual (KG)</th><th>Selisih</th></tr></thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <?php $g = $gudangItems[$it['ukuran']] ?? null; ?>
              <?php $kgM = $komplainKg[$it['ukuran']] ?? (float) ($it['jumlah_kg'] ?? 0); ?>
              <tr>
                <td><?= e($it['ukuran']) ?></td>
                <td class="readonly-field"><?= e(format_angka($it['jumlah'])) ?></td>
                <td><?= e($it['satuan']) ?></td>
                <td><input type="hidden" name="ukuran[]" value="<?= e($it['ukuran']) ?>">
                  <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="jumlah[]"
                         value="<?= e($g['jumlah'] ?? $it['jumlah']) ?>"></td>
                <td><select class="form-select form-select-sm" name="satuan[]">
                    <?php foreach ($satuanList as $s): ?><option value="<?= $s ?>" <?= (($g['satuan'] ?? $it['satuan']) === $s) ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                  </select></td>
                <td>
                  <input type="text" class="form-control form-control-sm kg-marketing readonly-field" readonly
                         value="<?= e(number_format($kgM, 2, ',', '.')) ?>" aria-label="KG dari marketing">
                </td>
                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm berat-aktual"
                           data-kg-marketing="<?= e((string) $kgM) ?>"
                           name="berat_kg[]" value="<?= e($g['berat_kg'] ?? '0') ?>" required></td>
                <td class="kg-selisih small">-</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <button type="submit" class="btn btn-success" data-confirm="Simpan dan tutup (CLOSE) retur ini?">Simpan &amp; Close</button>
  </form>
</section>
<?php else: ?>
<section>
  <div class="page-heading"><div><h1>Antrean Retur Gudang</h1><div class="page-subtitle">Semua barang kembali dicek &amp; dicatat di sini</div></div></div>

  <?= $tabsStatus ?>
  <div class="card card-mcp">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span>Komplain Retur Marketing &mdash; Info Bersama <span class="badge bg-secondary"><?= count($komplainRetur) ?></span></span>
      <?php if (count($komplainRetur) > 8): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle-rows="tabelKomplain">Tampilkan semua</button>
      <?php endif; ?>
    </div>
    <div class="card-body p-0">
      <div class="table-toolbar">
        <input type="search" class="form-control form-control-sm" placeholder="Cari no. komplain / SJ / customer..."
               data-filter-table="tabelKomplain" aria-label="Cari komplain retur">
      </div>
      <div class="mobile-scroll-hint">Geser tabel ke samping untuk melihat kolom lain &rarr;</div>
      <?php if (!$komplainRetur): ?><div class="empty-state">Belum ada komplain retur marketing.</div>
      <?php else: ?>
        <div class="table-responsive-mcp table-scroll-box"><table class="table table-mcp mb-0" id="tabelKomplain" style="min-width:680px">
          <thead><tr><th>No. Komplain</th><th>No. SJ</th><th>Customer</th><th>Tanggal</th><th>Status</th></tr></thead>
          <tbody>
            <?php $i = 0; foreach ($komplainRetur as $k): ?>
              <tr class="<?= $i >= 8 ? 'baris-extra d-none' : '' ?>">
                <td><?= e($k['no_komplain']) ?></td><td><?= e($k['no_sj']) ?></td><td><?= e($k['customer']) ?></td>
                <td><?= e(format_tanggal($k['tgl_komplain'])) ?></td><td><?= status_tiket($k['status']) ?></td>
              </tr>
              <?php $i++; endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card card-mcp">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span>Daftar Barang Kembalian <span class="badge bg-secondary"><?= count($list) ?></span></span>
      <?php if (count($list) > 8): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle-rows="tabelRetur">Tampilkan semua</button>
      <?php endif; ?>
    </div>
    <div class="card-body p-0">
      <div class="table-toolbar">
        <input type="search" class="form-control form-control-sm" placeholder="Cari no. retur / SJ / nopol / customer..."
               data-filter-table="tabelRetur" aria-label="Cari barang kembalian">
      </div>
      <div class="mobile-scroll-hint">Geser tabel ke samping untuk melihat kolom lain &rarr;</div>
      <?php if (!$list): ?><div class="empty-state">Belum ada barang kembalian.</div>
      <?php else: ?>
        <div class="table-responsive-mcp table-scroll-box"><table class="table table-mcp mb-0" id="tabelRetur" style="min-width:1480px">
          <thead><tr><th>No. Retur</th><th>No. SJ</th><th>No. Komplain</th><th>Customer</th><th>Dicatat Marketing</th><th>Dicatat Satpam</th><th>Alasan Satpam</th><th>Hasil Cek Gudang</th><th>Keterangan Penyelesaian (Produksi)</th><th>Item</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php $i = 0; foreach ($list as $r): ?>
              <tr class="<?= $i >= 8 ? 'baris-extra d-none' : '' ?>">
            <td><?= e($r['no_retur']) ?></td><td><?= e($r['no_sj']) ?></td>
            <td><?= $r['no_komplain'] ? e($r['no_komplain']) : '-' ?></td>
            <td><?= e($r['customer']) ?></td>
            <td class="small">
              <?php if ($r['marketing_by']): ?>
                <?= user_label($r['marketing_by']) ?><br><span class="text-mute"><?= e(format_waktu($r['marketing_at'])) ?></span>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td class="small"><?= user_label($r['created_by']) ?><br><span class="text-mute"><?= e(format_waktu($r['created_at'])) ?></span></td>
            <td><?= alasan_badge($r['alasan']) ?></td>
            <td><?= $r['asal_usul'] ? e(ucfirst($r['asal_usul'])) : '<span class="text-mute small">Belum dicek gudang</span>' ?></td>
            <td class="small">
              <?php if (!empty($r['keterangan_produksi'])): ?>
                <?= e($r['keterangan_produksi']) ?><br>
                <span class="text-mute"><?= user_label($r['produksi_selesai_by']) ?> &middot; <?= e(format_waktu($r['produksi_selesai_at'])) ?></span>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td><?= (int)$r['item_count'] ?></td><td><?= status_tiket($r['status']) ?></td>
            <td class="action-col">
              <?php if (!status_is_open($r['status'])): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>gudang_retur.php?act=form&id=<?= (int)$r['id'] ?>">Lihat</a>
              <?php elseif (strtolower((string) $r['alasan']) === 'keluhan mutu' && !empty($r['komplain_id'])): ?>
                <span class="badge bg-info text-dark">Ditutup produksi</span>
              <?php else: ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>gudang_retur.php?act=form&id=<?= (int)$r['id'] ?>">Proses</a>
              <?php endif; ?>
            </td>
              </tr>
              <?php $i++; endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
