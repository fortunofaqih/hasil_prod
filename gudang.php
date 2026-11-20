<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'gudang']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0); // qc_header id

// ---------------- Simpan verifikasi gudang ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!csrf_check()) {
        set_flash('Token keamanan tidak valid.', 'danger');
        redirect('gudang.php');
    }
    $qcId = (int) post('qc_id');
    $tgl = post('tgl_verifikasi');
    $catatan = post('catatan');
    $siap = $_POST['siap'] ?? [];
    if (!is_array($siap)) { $siap = []; }

    $stmt = $pdo->prepare('SELECT q.*, d.id AS do_id, d.no_do FROM qc_header q
                           JOIN produksi_header p ON p.id=q.produksi_header_id
                           JOIN do_header d ON d.id=p.do_header_id WHERE q.id=?');
    $stmt->execute([$qcId]);
    $qc = $stmt->fetch();
    if (!$qc || $tgl === '') {
        set_flash('Data gudang tidak lengkap.', 'danger');
        redirect('gudang.php?act=form&id=' . $qcId);
    }

    $total = 0;
    foreach ($siap as $val) { $total += max(0, (float) $val); }

    try {
        $pdo->beginTransaction();
        $exist = $pdo->prepare('SELECT id FROM gudang_header WHERE qc_header_id=? ORDER BY id DESC LIMIT 1');
        $exist->execute([$qcId]);
        $gId = (int) $exist->fetchColumn();

        if ($gId) {
            $pdo->prepare('UPDATE gudang_header SET tgl_verifikasi=?, jumlah_siap=?, status=\'manager\', catatan=?, modified_by=?, modified_at=? WHERE id=?')
                ->execute([$tgl, $total, $catatan, user_id(), date('Y-m-d H:i:s'), $gId]);
            $pdo->prepare('DELETE FROM gudang_detail WHERE gudang_header_id=?')->execute([$gId]);
        } else {
            $pdo->prepare('INSERT INTO gudang_header
                (qc_header_id, no_spk, tgl_verifikasi, jumlah_siap, status, catatan, created_by, modified_by, created_at, modified_at)
                VALUES (?,?,?,?,\'manager\',?,?,?,NOW(),NOW())')
                ->execute([$qcId, $qc['no_spk'], $tgl, $total, $catatan, user_id(), user_id()]);
            $gId = (int) $pdo->lastInsertId();
        }

        $ins = $pdo->prepare('INSERT INTO gudang_detail (gudang_header_id, qc_detail_id, jumlah_siap, created_by, modified_by, created_at, modified_at)
                              VALUES (?,?,?,?,?,NOW(),NOW())');
        $details = $pdo->prepare('SELECT * FROM qc_detail WHERE qc_header_id=? ORDER BY id');
        $details->execute([$qcId]);
        foreach ($details->fetchAll() as $d) {
            $val = (float) ($siap[$d['id']] ?? 0);
            $ins->execute([$gId, $d['id'], max(0, $val), user_id(), user_id()]);
        }

        set_do_status((int) $qc['do_id'], 'manager');
        track((int) $qc['do_id'], 'gudang', 'Gudang konfirmasi barang tersedia: ' . format_angka($total) . ' - menunggu verifikasi manager');
        $pdo->commit();
        set_flash('Data gudang tersimpan. Menunggu verifikasi manager sebelum barang boleh diambil.');
        redirect('gudang.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('gudang.php?act=form&id=' . $qcId);
    }
}

// ---------------- Lepaskan barang (hanya setelah verifikasi manager) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'release') {
    if (!csrf_check()) {
        set_flash('Token keamanan tidak valid.', 'danger');
        redirect('gudang.php');
    }
    $doId = (int) post('do_id');
    $stmt = $pdo->prepare('SELECT d.*, g.id AS gudang_id FROM do_header d
        JOIN produksi_header p ON p.do_header_id=d.id
        JOIN qc_header q ON q.produksi_header_id=p.id
        JOIN gudang_header g ON g.qc_header_id=q.id
        WHERE d.id=? ORDER BY g.id DESC LIMIT 1');
    $stmt->execute([$doId]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'siap_ambil') {
        set_flash('Barang belum disetujui manager. Gudang belum boleh melepas barang.', 'danger');
        redirect('gudang.php');
    }
    $pdo->prepare('UPDATE gudang_header SET status=\'selesai\', released_at=?, modified_by=?, modified_at=? WHERE id=?')
        ->execute([date('Y-m-d H:i:s'), user_id(), date('Y-m-d H:i:s'), $row['gudang_id']]);
    set_do_status($doId, 'selesai');
    track($doId, 'gudang', 'Barang dilepas gudang setelah verifikasi manager: ' . $row['no_do']);
    set_flash('Barang berhasil dilepas. Transaksi selesai.');
    redirect('gudang.php');
}

// ---------------- Data ----------------
$siapList = $pdo->query("SELECT d.id, d.no_do, d.no_spk, d.customer, d.jumlah, d.satuan, g.jumlah_siap, g.id AS gudang_id
    FROM do_header d
    JOIN produksi_header p ON p.do_header_id=d.id
    JOIN qc_header q ON q.produksi_header_id=p.id
    JOIN gudang_header g ON g.qc_header_id=q.id
    WHERE d.status='siap_ambil' ORDER BY d.id DESC")->fetchAll();
$list = $pdo->query("SELECT q.*, d.no_do, d.customer, d.status AS do_status,
                    (SELECT COUNT(*) FROM gudang_header g WHERE g.qc_header_id=q.id) AS sudah
                    FROM qc_header q
                    JOIN produksi_header p ON p.id=q.produksi_header_id
                    JOIN do_header d ON d.id=p.do_header_id
                    WHERE d.status='gudang' OR (d.status='koreksi' AND d.returned_to='gudang')
                    ORDER BY q.id DESC")->fetchAll();

$qc = null;
$details = [];
$gData = null;
$gRows = [];
if ($act === 'form' && $id > 0) {
    $stmt = $pdo->prepare('SELECT q.*, d.no_do, d.customer, d.id AS do_id, d.tgl_ambil, d.no_spk,
                           p.jenis_proses, p.catatan AS catatan_ppic, qh.jumlah_lolos
                           FROM qc_header q
                           JOIN produksi_header p ON p.id=q.produksi_header_id
                           JOIN do_header d ON d.id=p.do_header_id
                           LEFT JOIN qc_header qh ON qh.id=q.id
                           WHERE q.id=?');
    $stmt->execute([$id]);
    $qc = $stmt->fetch();
    if ($qc) {
        $stmt = $pdo->prepare('SELECT qd.*, pd.ukuran, pd.satuan, pd.jumlah AS jumlah_produksi
                               FROM qc_detail qd LEFT JOIN produksi_detail pd ON pd.id=qd.produksi_detail_id
                               WHERE qd.qc_header_id=? ORDER BY qd.id');
        $stmt->execute([$id]);
        $details = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM gudang_header WHERE qc_header_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]);
        $gData = $stmt->fetch();
        if ($gData) {
            $stmt = $pdo->prepare('SELECT * FROM gudang_detail WHERE gudang_header_id=?');
            $stmt->execute([$gData['id']]);
            foreach ($stmt->fetchAll() as $r) { $gRows[$r['qc_detail_id']] = $r['jumlah_siap']; }
        }
    }
}

$pageTitle = 'Gudang';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'form' && $qc): ?>
<section>
  <div class="page-heading">
    <div><h1>Konfirmasi Gudang</h1><div class="page-subtitle">SPK <?= e($qc['no_spk']) ?> &middot; DO <?= e($qc['no_do']) ?></div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>gudang.php">&larr; Kembali</a>
  </div>
  <?php if ($qc['do_status'] === 'koreksi' && !empty($qc['catatan_koreksi'])): ?>
    <div class="alert alert-warning"><?= e($qc['catatan_koreksi']) ?></div>
  <?php endif; ?>
  <form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="qc_id" value="<?= (int)$qc['id'] ?>">
    <div class="card card-mcp">
      <div class="card-header">Data QC</div>
      <div class="card-body">
        <dl class="kv">
          <dt>Customer</dt><dd><?= e($qc['customer']) ?></dd>
          <dt>Jumlah Lolos QC</dt><dd><?= e(format_angka($qc['jumlah_lolos'])) ?></dd>
          <dt>Tgl. Ambil</dt><dd><?= e(format_tanggal($qc['tgl_ambil'])) ?></dd>
          <dt>Catatan QC</dt><dd><?= e($qc['catatan'] ?: '-') ?></dd>
        </dl>
      </div>
    </div>
    <div class="card card-mcp">
      <div class="card-header">Ketersediaan Barang</div>
      <div class="card-body">
        <div class="mb-3">
          <label for="tgl_verifikasi" class="form-label">Tanggal Verifikasi <span class="text-danger">*</span></label>
          <input type="date" class="form-control" id="tgl_verifikasi" name="tgl_verifikasi" required
                 value="<?= e($gData['tgl_verifikasi'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0" style="min-width:560px">
            <thead><tr><th>Ukuran</th><th>Lolos QC</th><th>Satuan</th><th>Jumlah Siap Diambil</th></tr></thead>
            <tbody>
              <?php foreach ($details as $d): ?>
                <tr>
                  <td><?= e($d['ukuran'] ?: '-') ?></td>
                  <td><?= e(format_angka($d['jumlah_lolos'])) ?></td>
                  <td><?= e($d['satuan'] ?: '-') ?></td>
                  <td><input type="number" min="0" step="0.01" class="form-control" name="siap[<?= (int)$d['id'] ?>]"
                             value="<?= e($gRows[$d['id']] ?? $d['jumlah_lolos']) ?>"></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mt-3">
          <label for="catatan" class="form-label">Catatan Gudang</label>
          <textarea class="form-control" id="catatan" name="catatan" rows="2"><?= e($gData['catatan'] ?? '') ?></textarea>
          <div class="field-hint">Barang baru boleh dilepas setelah manager memberikan verifikasi.</div>
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary" data-confirm="Simpan data gudang dan ajukan ke manager?">Simpan &amp; Ajukan ke Manager</button>
  </form>
</section>
<?php else: ?>
<section>
  <div class="page-heading"><div><h1>Antrian Gudang</h1><div class="page-subtitle">Barang yang lolos QC dan menunggu verifikasi gudang</div></div></div>

  <?php if ($siapList): ?>
  <div class="card card-mcp border-success">
    <div class="card-header text-success">Sudah Disetujui Manager &mdash; Boleh Dilepas</div>
    <div class="card-body p-0">
      <div class="table-responsive-mcp">
        <table class="table table-mcp mb-0" style="min-width:620px">
          <thead><tr><th>No. DO</th><th>No. SPK</th><th>Customer</th><th>Siap Diambil</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach ($siapList as $s): ?>
              <tr>
                <td><?= e($s['no_do']) ?></td>
                <td><?= e($s['no_spk']) ?></td>
                <td><?= e($s['customer']) ?></td>
                <td><?= e(format_angka($s['jumlah_siap'])) ?> <?= e($s['satuan']) ?></td>
                <td>
                  <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="do_id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-sm btn-success" data-confirm="Lepaskan barang ke customer sekarang?" type="submit">Lepaskan Barang</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card card-mcp">
    <div class="card-body p-0">
      <?php if (!$list): ?>
        <div class="empty-state">Tidak ada barang yang menunggu verifikasi gudang.</div>
      <?php else: ?>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0">
            <thead><tr><th>No. DO</th><th>No. SPK</th><th>Customer</th><th>Lolos QC</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($list as $row): ?>
                <tr>
                  <td><?= e($row['no_do']) ?></td>
                  <td><?= e($row['no_spk']) ?></td>
                  <td><?= e($row['customer']) ?></td>
                  <td><?= e(format_angka($row['jumlah_lolos'])) ?></td>
                  <td><?= status_badge($row['do_status']) ?></td>
                  <td class="action-col"><a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>gudang.php?act=form&id=<?= (int)$row['id'] ?>">
                    <?= (int)$row['sudah'] > 0 ? 'Koreksi' : 'Verifikasi' ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
