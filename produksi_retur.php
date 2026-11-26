<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'produksi']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0); // komplain_header id

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!csrf_check()) { set_flash('Token tidak valid.', 'danger'); redirect('produksi_retur.php'); }
    $komplainId = (int) post('komplain_id');
    $keterangan = post('keterangan');
    if ($keterangan === '') {
        set_flash('Keterangan Penyelesaian wajib diisi (termasuk keputusan/afalan bila ada).', 'danger');
        redirect('produksi_retur.php?act=form&id=' . $komplainId);
    }
    if (post('tgl_tindakan') === '') { set_flash('Tanggal tindakan wajib diisi.', 'danger'); redirect('produksi_retur.php?act=form&id=' . $komplainId); }

    $k = $pdo->prepare('SELECT * FROM komplain_header WHERE id=?');
    $k->execute([$komplainId]); $komplain = $k->fetch();
    if (!$komplain) { set_flash('Komplain tidak ditemukan.', 'danger'); redirect('produksi_retur.php'); }
    tolak_jika_closed($komplain['status'], 'produksi_retur.php?act=form&id=' . $komplainId);

    $km = $pdo->prepare("SELECT id FROM retur_header WHERE komplain_id=? AND LOWER(alasan)='keluhan mutu'");
    $km->execute([$komplainId]);
    $returKeluhan = array_map('intval', $km->fetchAll(PDO::FETCH_COLUMN));

    $ukArr = $_POST['ukuran'] ?? [];
    $jumArr = $_POST['jumlah'] ?? [];
    $satArr = $_POST['satuan'] ?? [];
    $tindArr = $_POST['tindakan'] ?? [];

    try {
        $pdo->beginTransaction();
        $exist = $pdo->prepare('SELECT id FROM produksi_retur_header WHERE komplain_id=? ORDER BY id DESC LIMIT 1');
        $exist->execute([$komplainId]);
        $headId = (int) $exist->fetchColumn();
        if ($headId) {
            $pdo->prepare('UPDATE produksi_retur_header SET tgl_tindakan=?, keterangan=?, status=\'closed\', modified_by=?, modified_at=? WHERE id=?')
                ->execute([post('tgl_tindakan'), $keterangan, user_id(), date('Y-m-d H:i:s'), $headId]);
            $pdo->prepare('DELETE FROM produksi_retur_detail WHERE produksi_retur_header_id=?')->execute([$headId]);
        } else {
            $pdo->prepare('INSERT INTO produksi_retur_header
                (komplain_id, tgl_tindakan, keterangan, status, created_by, modified_by, created_at, modified_at)
                VALUES (?,?,?,\'closed\',?,?,NOW(),NOW())')
                ->execute([$komplainId, post('tgl_tindakan'), $keterangan, user_id(), user_id()]);
            $headId = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO produksi_retur_detail
            (produksi_retur_header_id, ukuran, jumlah, satuan, tindakan, created_by, modified_by, created_at, modified_at)
            VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
        foreach ($ukArr as $i => $uk) {
            if (trim((string) $uk) === '') { continue; }
            $ins->execute([$headId, trim((string) $uk), (float) ($jumArr[$i] ?? 0), $satArr[$i] ?? 'roll',
                $tindArr[$i] ?? '', user_id(), user_id()]);
        }

        // Retur keluhan mutu ditutup oleh produksi; alasan lain tetap ditutup gudang.
        if ($returKeluhan) {
            $placeholder = implode(',', array_fill(0, count($returKeluhan), '?'));
            $waktu = date('Y-m-d H:i:s');
            $uid = user_id();
            $pdo->prepare("UPDATE retur_header SET status='closed', asal_usul=?, keterangan_produksi=?, produksi_selesai_by=?, produksi_selesai_at=?, modified_by=?, modified_at=? WHERE id IN ($placeholder)")
                ->execute(array_merge(
                    ['keluhan mutu', $keterangan, $uid, $waktu, $uid, $waktu],
                    $returKeluhan
                ));
            $pdo->prepare('UPDATE komplain_header SET status=\'closed\', keterangan_selesai=?, modified_by=?, modified_at=? WHERE id=?')
                ->execute(['Tindak lanjut produksi (keluhan mutu): ' . $keterangan, user_id(), date('Y-m-d H:i:s'), $komplainId]);
        } else {
            $openRetur = (int) $pdo->query("SELECT COUNT(*) FROM retur_header WHERE komplain_id=$komplainId AND status='open'")->fetchColumn();
            if ($openRetur === 0) {
                $pdo->prepare('UPDATE komplain_header SET status=\'closed\', keterangan_selesai=?, modified_by=?, modified_at=? WHERE id=?')
                    ->execute(['Tindak lanjut produksi: ' . $keterangan, user_id(), date('Y-m-d H:i:s'), $komplainId]);
            }
        }
        $pdo->commit();
        set_flash($returKeluhan
            ? 'Tindak lanjut produksi tersimpan. Retur keluhan mutu dan komplain ditutup (CLOSE) oleh produksi.'
            : 'Tindak lanjut produksi tersimpan.');
        redirect('produksi_retur.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('produksi_retur.php?act=form&id=' . $komplainId);
    }
}

$list = $pdo->query("SELECT k.*, (SELECT COUNT(*) FROM produksi_retur_header p WHERE p.komplain_id=k.id) AS sudah
                     FROM komplain_header k WHERE k.jenis='retur' ORDER BY k.id DESC")->fetchAll();
$returSemua = $pdo->query("SELECT r.no_retur, r.no_sj, r.customer, r.alasan, r.status, r.komplain_id
                           FROM retur_header r WHERE r.alasan='keluhan mutu' ORDER BY r.id DESC")->fetchAll();

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari(array_merge($list, $returSemua), $statusSekarang);
$list = status_pisah($list, $statusSekarang);
$returSemua = status_pisah($returSemua, $statusSekarang);

$komplain = null; $items = []; $tindakan = []; $head = null; $returKeluhan = [];
if ($act === 'form' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM komplain_header WHERE id=?');
    $stmt->execute([$id]); $komplain = $stmt->fetch();
    if ($komplain) {
        $stmt = $pdo->prepare('SELECT * FROM komplain_detail WHERE komplain_header_id=? ORDER BY id');
        $stmt->execute([$id]); $items = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM produksi_retur_header WHERE komplain_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]); $head = $stmt->fetch();
        if ($head) {
            $stmt = $pdo->prepare('SELECT * FROM produksi_retur_detail WHERE produksi_retur_header_id=? ORDER BY id');
            $stmt->execute([$head['id']]);
            foreach ($stmt->fetchAll() as $d) { $tindakan[$d['ukuran']] = $d['tindakan']; }
        }
        $km = $pdo->prepare("SELECT id FROM retur_header WHERE komplain_id=? AND LOWER(alasan)='keluhan mutu'");
        $km->execute([$id]); $returKeluhan = array_map('intval', $km->fetchAll(PDO::FETCH_COLUMN));
    }
}

$pageTitle = 'Retur Produksi';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'form' && $komplain): ?>
<section>
  <div class="page-heading">
    <div><h1>Tindak Lanjut Retur</h1><div class="page-subtitle">Komplain <?= e($komplain['no_komplain']) ?> &middot; SJ <?= e($komplain['no_sj']) ?></div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>produksi_retur.php">&larr; Kembali</a>
  </div>
  <?= audit_card($komplain ?: null, 'Audit Komplain Marketing') ?>
  <?= audit_card($head ?: null, 'Audit Tindak Lanjut Produksi') ?>
  <?php if ($returKeluhan): ?>
    <div class="alert alert-info small mb-3"><i class="bi bi-info-circle"></i> Retur ini adalah <strong>keluhan mutu</strong>. Setelah disimpan, admin produksi yang menutup (CLOSE) retur dan komplain; gudang tidak menutup retur ini.</div>
  <?php endif; ?>
    <form method="post" action=""<?= form_readonly_attr($komplain['status'] ?? '') ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="komplain_id" value="<?= (int)$komplain['id'] ?>">
    <div class="card card-mcp">
      <div class="card-header">Data Keluhan</div>
      <div class="card-body">
        <dl class="kv">
          <dt>Customer</dt><dd><?= e($komplain['customer']) ?></dd>
          <dt>No. SJ</dt><dd><?= e($komplain['no_sj']) ?></dd>
          <dt>Status</dt><dd><?= status_tiket($komplain['status']) ?></dd>
        </dl>
      </div>
    </div>
    <div class="card card-mcp">
      <div class="card-header">Rencana Penyelesaian / Perbaikan</div>
      <div class="card-body">
        <div class="mb-3"><label class="form-label">Tanggal Tindakan</label>
          <input type="date" class="form-control" name="tgl_tindakan" required value="<?= e($head['tgl_tindakan'] ?? date('Y-m-d')) ?>"></div>
        <div class="mb-3"><label class="form-label">Keterangan Penyelesaian <span class="text-danger">*</span></label>
          <textarea class="form-control" name="keterangan" rows="4" required
                    placeholder="Tuliskan tindakan dan keputusan akhir, misalnya: diperbaiki / masuk Afalan / reject"><?= e($head['keterangan'] ?? '') ?></textarea>
          </div>
        <div class="table-responsive-mcp"><table class="table table-mcp" style="min-width:620px">
          <thead><tr><th>Ukuran</th><th>Jumlah</th><th>Satuan</th><th>Tindakan per Item</th></tr></thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><?= e($it['ukuran']) ?>
                  <input type="hidden" name="ukuran[]" value="<?= e($it['ukuran']) ?>">
                  <input type="hidden" name="jumlah[]" value="<?= e($it['jumlah']) ?>">
                  <input type="hidden" name="satuan[]" value="<?= e($it['satuan']) ?>">
                </td>
                <td><?= e(format_angka($it['jumlah'])) ?></td><td><?= e($it['satuan']) ?></td>
                <td><input class="form-control form-control-sm" name="tindakan[]" value="<?= e($tindakan[$it['ukuran']] ?? '') ?>"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary"><?= $returKeluhan ? 'Simpan &amp; Tutup (CLOSE)' : 'Simpan Tindak Lanjut' ?></button>
  </form>
</section>
<?php else: ?>
<section>
  <div class="page-heading"><div><h1>Retur Produksi</h1><div class="page-subtitle">Komplain retur dari marketing</div></div></div>

  <?= $tabsStatus ?>
  <div class="card card-mcp">
    <div class="card-header">Retur Keluhan Mutu dari Satpam &mdash; Info Bersama</div>
    <div class="card-body p-0">
      <?php if (!$returSemua): ?><div class="empty-state">Belum ada retur keluhan mutu dari satpam.</div>
      <?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:700px">
          <thead><tr><th>No. Retur</th><th>No. SJ</th><th>Customer</th><th>Alasan</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($returSemua as $r): ?><tr>
            <td><?= e($r['no_retur']) ?></td><td><?= e($r['no_sj']) ?></td><td><?= e($r['customer']) ?></td>
            <td><?= alasan_badge($r['alasan']) ?></td><td><?= status_tiket($r['status']) ?></td>
          </tr><?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card card-mcp">
    <div class="card-header">Antrean Komplain Retur</div>
    <div class="card-body p-0">
      <?php if (!$list): ?><div class="empty-state">Belum ada komplain retur.</div>
      <?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:680px">
          <thead><tr><th>No. Komplain</th><th>No. SJ</th><th>Customer</th><th>Status</th><th>Tindak Lanjut</th><th>Aksi</th></tr></thead>
          <tbody><?php foreach ($list as $k): ?><tr>
            <td><?= e($k['no_komplain']) ?></td><td><?= e($k['no_sj']) ?></td><td><?= e($k['customer']) ?></td>
            <td><?= status_tiket($k['status']) ?></td>
            <td><?= (int)$k['sudah'] > 0 ? '<span class="badge bg-info text-dark">Sudah diisi</span>' : '<span class="badge bg-warning text-dark">Belum</span>' ?></td>
            <td>
              <?php if (status_is_open($k['status'])): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>produksi_retur.php?act=form&id=<?= (int)$k['id'] ?>">Proses</a>
              <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>produksi_retur.php?act=form&id=<?= (int)$k['id'] ?>">Lihat</a>
              <?php endif; ?>
            </td>
          </tr><?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
