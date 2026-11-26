<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'ppic']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0); // do_header id

// ---------------- Simpan rencana produksi ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!csrf_check()) {
        set_flash('Token keamanan tidak valid.', 'danger');
        redirect('ppic.php');
    }
    $doId = (int) post('do_id');
    $cekSt = $pdo->prepare('SELECT status FROM do_header WHERE id=?');
    $cekSt->execute([$doId]);
    tolak_jika_closed($cekSt->fetchColumn(), 'ppic.php?act=form&id=' . $doId . '');
    $jenis = in_array(post('jenis_proses'), ['roll', 'potong'], true) ? post('jenis_proses') : 'roll';
    $catatan = post('catatan');
    $tglOrder = post('tgl_order');
    if ($tglOrder === '') {
        set_flash('Tanggal order (mulai kerja produksi) wajib diisi.', 'danger');
        redirect('ppic.php?act=form&id=' . $doId);
    }
    $tanggal = [
        'roll_rencana'   => post('tgl_rencana_roll') ?: null,
        'roll_realisasi' => post('tgl_realisasi_roll') ?: null,
        'potong_rencana' => $jenis === 'potong' ? (post('tgl_rencana_potong') ?: null) : null,
        'potong_realisasi' => $jenis === 'potong' ? (post('tgl_realisasi_potong') ?: null) : null,
    ];

    $stmt = $pdo->prepare('SELECT * FROM do_header WHERE id=?');
    $stmt->execute([$doId]);
    $do = $stmt->fetch();
    if (!$do) {
        set_flash('DO tidak ditemukan.', 'danger');
        redirect('ppic.php');
    }

    try {
        $pdo->beginTransaction();
        $exist = $pdo->prepare('SELECT id FROM produksi_header WHERE do_header_id=? ORDER BY id DESC LIMIT 1');
        $exist->execute([$doId]);
        $prodId = (int) $exist->fetchColumn();

        if ($prodId) {
            $pdo->prepare('UPDATE produksi_header SET jenis_proses=?, tgl_rencana_roll=?, tgl_realisasi_roll=?,
                tgl_rencana_potong=?, tgl_realisasi_potong=?, status=\'qc\', catatan=?, modified_by=?, modified_at=? WHERE id=?')
                ->execute([$jenis, $tanggal['roll_rencana'], $tanggal['roll_realisasi'], $tanggal['potong_rencana'],
                    $tanggal['potong_realisasi'], $catatan, user_id(), date('Y-m-d H:i:s'), $prodId]);
            $pdo->prepare('DELETE FROM produksi_detail WHERE produksi_header_id=?')->execute([$prodId]);
        } else {
            $pdo->prepare('INSERT INTO produksi_header
                (do_header_id, no_spk, jenis_proses, tgl_rencana_roll, tgl_realisasi_roll, tgl_rencana_potong,
                 tgl_realisasi_potong, status, catatan, created_by, modified_by, created_at, modified_at)
                VALUES (?,?,?,?,?,?,?,\'qc\',?,?,?,NOW(),NOW())')
                ->execute([$doId, $do['no_spk'], $jenis, $tanggal['roll_rencana'], $tanggal['roll_realisasi'],
                    $tanggal['potong_rencana'], $tanggal['potong_realisasi'], $catatan, user_id(), user_id()]);
            $prodId = (int) $pdo->lastInsertId();
        }

        $details = $pdo->prepare('SELECT * FROM do_detail WHERE do_header_id=? ORDER BY id');
        $details->execute([$doId]);
        $ins = $pdo->prepare('INSERT INTO produksi_detail
            (produksi_header_id, do_detail_id, ukuran, jumlah, satuan, created_by, modified_by, created_at, modified_at)
            VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
        foreach ($details->fetchAll() as $d) {
            $ins->execute([$prodId, $d['id'], $d['ukuran'], $d['jumlah'], $d['satuan'], user_id(), user_id()]);
        }

        $pdo->prepare('UPDATE do_header SET tgl_order=?, modified_by=?, modified_at=? WHERE id=?')
            ->execute([$tglOrder, user_id(), date('Y-m-d H:i:s'), $doId]);

        set_do_status($doId, 'qc');
        $ket = 'PPIC: ' . ($jenis === 'potong' ? 'Roll + Potong' : 'Roll saja') . ', SPK ' . $do['no_spk'];
        track($doId, 'produksi', $ket);
        $pdo->commit();
        set_flash('Rencana produksi tersimpan dan diteruskan ke QC.');
        redirect('ppic.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('ppic.php?act=form&id=' . $doId);
    }
}

// ---------------- Data ----------------
$list = $pdo->query("SELECT d.*, (SELECT COUNT(*) FROM produksi_header p WHERE p.do_header_id=d.id) AS sudah
                     FROM do_header d WHERE EXISTS (SELECT 1 FROM produksi_header p WHERE p.do_header_id=d.id)
                     OR d.status='ppic' OR (d.status='koreksi' AND d.returned_to='ppic')
                     ORDER BY d.id DESC")->fetchAll();

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari($list, $statusSekarang);
$list = status_pisah($list, $statusSekarang);

$do = null;
$produksi = null;
if ($act === 'form' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM do_header WHERE id=?');
    $stmt->execute([$id]);
    $do = $stmt->fetch();
    if ($do) {
        $stmt = $pdo->prepare('SELECT * FROM produksi_header WHERE do_header_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]);
        $produksi = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT * FROM do_detail WHERE do_header_id=? ORDER BY id');
        $stmt->execute([$id]);
        $doDetails = $stmt->fetchAll();
    }
}

$pageTitle = 'Produksi - PPIC';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'form' && $do): ?>
<section>
  <div class="page-heading">
    <div><h1>Rencana Produksi</h1><div class="page-subtitle">DO <?= e($do['no_do']) ?> &middot; SPK <?= e($do['no_spk']) ?></div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>ppic.php">&larr; Kembali</a>
  </div>
  <?= audit_card($do ?: null, 'Audit Data DO (Marketing)') ?>
  <?= audit_card($produksi ?: null, 'Audit Data Produksi') ?>
  <form method="post" action=""<?= form_readonly_attr($do['status'] ?? '') ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="do_id" value="<?= (int)$do['id'] ?>">

    <div class="card card-mcp">
      <div class="card-header">Data Order</div>
      <div class="card-body">
        <dl class="kv">
          <dt>No. DO</dt><dd><?= e($do['no_do']) ?></dd>
          <dt>No. SPK</dt><dd><?= e($do['no_spk']) ?></dd>
          <dt>Customer</dt><dd><?= e($do['customer']) ?></dd>
          <dt>Jumlah</dt><dd><?= e(format_angka($do['jumlah'])) ?> <?= e($do['satuan']) ?></dd>
          <dt>Tgl. Ambil</dt><dd><?= e(format_tanggal($do['tgl_ambil'])) ?></dd>
        </dl>
        <?php if ($do['status'] === 'koreksi' && !empty($do['catatan_koreksi'])): ?>
          <div class="alert alert-warning mt-3 mb-0"><?= e($do['catatan_koreksi']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card card-mcp">
      <div class="card-header">Proses Produksi</div>
      <div class="card-body">
        <div class="mb-3">
          <label for="tgl_order" class="form-label">Tanggal Order (Mulai Kerja Produksi) <span class="text-danger">*</span></label>
          <input type="date" class="form-control" id="tgl_order" name="tgl_order" required
                 value="<?= e($do['tgl_order'] ?? '') ?>">
          <div class="field-hint">Diisi oleh PPIC. Tanggal order mulai kerja produksi.</div>
        </div>

        <div class="mb-3">
          <label for="jenis_proses" class="form-label">Pilih Proses <span class="text-danger">*</span></label>
          <select class="form-select" id="jenis_proses" name="jenis_proses">
            <option value="roll" <?= (($produksi['jenis_proses'] ?? '') === 'roll') ? 'selected' : '' ?>>Roll saja</option>
            <option value="potong" <?= (($produksi['jenis_proses'] ?? '') === 'potong') ? 'selected' : '' ?>>Roll + Potong</option>
          </select>
          <div class="field-hint">Jika hanya roll, tanggal rencana &amp; realisasi potong tidak perlu diisi.</div>
        </div>

        <div class="section-title">Tanggal Roll</div>
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label for="tgl_rencana_roll" class="form-label">Rencana Selesai Roll</label>
            <input type="date" class="form-control" id="tgl_rencana_roll" name="tgl_rencana_roll" value="<?= e($produksi['tgl_rencana_roll'] ?? '') ?>">
          </div>
          <div class="col-12 col-md-6">
            <label for="tgl_realisasi_roll" class="form-label">Realisasi Selesai Roll</label>
            <input type="date" class="form-control" id="tgl_realisasi_roll" name="tgl_realisasi_roll" value="<?= e($produksi['tgl_realisasi_roll'] ?? '') ?>">
          </div>
        </div>

        <div class="section-title mt-4">Tanggal Potong (diisi jika ada potong)</div>
        <div class="row g-3">
          <div class="col-12 col-md-6 potong-field">
            <label for="tgl_rencana_potong" class="form-label">Rencana Potong</label>
            <input type="date" class="form-control" id="tgl_rencana_potong" name="tgl_rencana_potong" value="<?= e($produksi['tgl_rencana_potong'] ?? '') ?>">
          </div>
          <div class="col-12 col-md-6 potong-field">
            <label for="tgl_realisasi_potong" class="form-label">Realisasi Potong</label>
            <input type="date" class="form-control" id="tgl_realisasi_potong" name="tgl_realisasi_potong" value="<?= e($produksi['tgl_realisasi_potong'] ?? '') ?>">
          </div>
        </div>

        <div class="mt-3">
          <label for="catatan" class="form-label">Catatan</label>
          <textarea class="form-control" id="catatan" name="catatan" rows="2"><?= e($produksi['catatan'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Simpan &amp; Teruskan ke QC</button>
  </form>
</section>
<?php else: ?>
<section>
  <div class="page-heading"><div><h1>Antrian Produksi</h1><div class="page-subtitle">Data dari marketing yang perlu direncanakan PPIC</div></div></div>
  <?= $tabsStatus ?>
  <div class="card card-mcp">
    <div class="card-body p-0">
      <?php if (!$list): ?>
        <div class="empty-state">Tidak ada DO yang menunggu PPIC.</div>
      <?php else: ?>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0">
            <thead><tr><th>No. DO</th><th>No. SPK</th><th>Customer</th><th>Jumlah</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($list as $row): ?>
                <tr>
                  <td><?= e($row['no_do']) ?></td>
                  <td><?= e($row['no_spk']) ?></td>
                  <td><?= e($row['customer']) ?></td>
                  <td><?= e(format_angka($row['jumlah'])) ?> <?= e($row['satuan']) ?></td>
                  <td><?= status_badge($row['status']) ?></td>
                  <td class="action-col">
                    <?php if (status_is_open($row['status'])): ?>
                      <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>ppic.php?act=form&id=<?= (int)$row['id'] ?>">
                        <?= (int)$row['sudah'] > 0 ? 'Koreksi' : 'Proses' ?>
                      </a>
                    <?php else: ?>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>ppic.php?act=form&id=<?= (int)$row['id'] ?>">Lihat</a>
                    <?php endif; ?>
                  </td>
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
