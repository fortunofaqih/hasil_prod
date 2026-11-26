<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'qc']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0); // produksi_header id

// ---------------- Simpan verifikasi QC ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!csrf_check()) {
        set_flash('Token keamanan tidak valid.', 'danger');
        redirect('qc.php');
    }
    $prodId = (int) post('produksi_id');
    $tgl = post('tgl_verifikasi');
    $catatan = post('catatan');
    $lolos = $_POST['lolos'] ?? [];
    if (!is_array($lolos)) { $lolos = []; }

    $stmt = $pdo->prepare('SELECT p.*, d.id AS do_id, d.no_do, d.status AS do_status FROM produksi_header p
                           JOIN do_header d ON d.id=p.do_header_id WHERE p.id=?');
    $stmt->execute([$prodId]);
    $prod = $stmt->fetch();
    tolak_jika_closed($prod['do_status'] ?? '', 'qc.php?act=form&id=' . $prodId);
    if (!$prod || $tgl === '') {
        set_flash('Data QC tidak lengkap.', 'danger');
        redirect('qc.php?act=form&id=' . $prodId);
    }

    $total = 0;
    foreach ($lolos as $val) { $total += max(0, (float) $val); }

    try {
        $pdo->beginTransaction();
        $exist = $pdo->prepare('SELECT id FROM qc_header WHERE produksi_header_id=? ORDER BY id DESC LIMIT 1');
        $exist->execute([$prodId]);
        $qcId = (int) $exist->fetchColumn();

        if ($qcId) {
            $pdo->prepare('UPDATE qc_header SET tgl_verifikasi=?, jumlah_lolos=?, status=?, catatan=?, modified_by=?, modified_at=? WHERE id=?')
                ->execute([$tgl, $total, $total > 0 ? 'gudang' : 'gagal', $catatan, user_id(), date('Y-m-d H:i:s'), $qcId]);
            $pdo->prepare('DELETE FROM qc_detail WHERE qc_header_id=?')->execute([$qcId]);
        } else {
            $pdo->prepare('INSERT INTO qc_header
                (produksi_header_id, no_spk, tgl_verifikasi, jumlah_lolos, status, catatan, created_by, modified_by, created_at, modified_at)
                VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())')
                ->execute([$prodId, $prod['no_spk'], $tgl, $total, $total > 0 ? 'gudang' : 'gagal', $catatan, user_id(), user_id()]);
            $qcId = (int) $pdo->lastInsertId();
        }

        $ins = $pdo->prepare('INSERT INTO qc_detail (qc_header_id, produksi_detail_id, jumlah_lolos, created_by, modified_by, created_at, modified_at)
                              VALUES (?,?,?,?,?,NOW(),NOW())');
        $details = $pdo->prepare('SELECT id FROM produksi_detail WHERE produksi_header_id=? ORDER BY id');
        $details->execute([$prodId]);
        foreach ($details->fetchAll() as $d) {
            $val = (float) ($lolos[$d['id']] ?? 0);
            $ins->execute([$qcId, $d['id'], max(0, $val), user_id(), user_id()]);
        }

        if ($total > 0) {
            set_do_status((int) $prod['do_id'], 'gudang');
            $ket = 'QC lolos ' . format_angka($total) . ' dari SPK ' . $prod['no_spk'] . ' - diteruskan ke gudang';
        } else {
            set_do_status((int) $prod['do_id'], 'gagal');
            $ket = 'QC tidak ada yang lolos - proses dihentikan, tidak diteruskan ke gudang';
        }
        track((int) $prod['do_id'], 'qc', $ket);
        $pdo->commit();
        set_flash($total > 0 ? 'Verifikasi QC tersimpan. Barang diteruskan ke Gudang.' : 'Tidak ada barang lolos QC. Proses dihentikan.', $total > 0 ? 'success' : 'warning');
        redirect('qc.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('qc.php?act=form&id=' . $prodId);
    }
}

// ---------------- Data ----------------
$list = $pdo->query("SELECT p.*, d.no_do, d.customer, d.status AS do_status, d.jumlah AS do_jumlah, d.satuan,
                    (SELECT COUNT(*) FROM qc_header q WHERE q.produksi_header_id=p.id) AS sudah
                    FROM produksi_header p JOIN do_header d ON d.id=p.do_header_id
                    ORDER BY p.id DESC")->fetchAll();

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari($list, $statusSekarang, 'do_status');
$list = status_pisah($list, $statusSekarang, 'do_status');

$prod = null;
$details = [];
$qcData = null;
$qcRows = [];
if ($act === 'form' && $id > 0) {
    $stmt = $pdo->prepare('SELECT p.*, d.no_do, d.customer, d.jumlah AS do_jumlah, d.satuan, d.tgl_ambil, d.id AS do_id, d.file_do
                           FROM produksi_header p JOIN do_header d ON d.id=p.do_header_id WHERE p.id=?');
    $stmt->execute([$id]);
    $prod = $stmt->fetch();
    if ($prod) {
        $stmt = $pdo->prepare('SELECT * FROM produksi_detail WHERE produksi_header_id=? ORDER BY id');
        $stmt->execute([$id]);
        $details = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM qc_header WHERE produksi_header_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]);
        $qcData = $stmt->fetch();
        if ($qcData) {
            $stmt = $pdo->prepare('SELECT * FROM qc_detail WHERE qc_header_id=?');
            $stmt->execute([$qcData['id']]);
            foreach ($stmt->fetchAll() as $r) { $qcRows[$r['produksi_detail_id']] = $r['jumlah_lolos']; }
        }
    }
}

$pageTitle = 'Quality Control';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'form' && $prod): ?>
<section>
  <div class="page-heading">
    <div><h1>Verifikasi QC</h1><div class="page-subtitle">SPK <?= e($prod['no_spk']) ?> &middot; DO <?= e($prod['no_do']) ?></div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>qc.php">&larr; Kembali</a>
  </div>
  <?= audit_card($prod ?: null, 'Audit Data Produksi') ?>
  <?= audit_card($qcData ?: null, 'Audit Data QC') ?>

  <div class="card card-mcp">
    <div class="card-header">Lampiran DO dari Marketing</div>
    <div class="card-body">
      <?php if (empty($prod['file_do'])): ?>
        <div class="text-mute small">Marketing tidak mengunggah lampiran DO.</div>
      <?php else: ?>
        <?php
          $fileUrl = BASE_URL . 'uploads/do/' . rawurlencode($prod['file_do']);
          $fileExt = strtolower(pathinfo($prod['file_do'], PATHINFO_EXTENSION));
        ?>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-sm btn-outline-primary" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right"></i> Buka Lampiran DO
          </a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e($fileUrl) ?>" download>
            <i class="bi bi-download"></i> Unduh
          </a>
          <span class="align-self-center small text-mute"><?= e($prod['file_do']) ?></span>
        </div>
        <?php if (in_array($fileExt, ['jpg', 'jpeg', 'png'], true)): ?>
          <div class="mt-3"><img class="file-preview" src="<?= e($fileUrl) ?>" alt="Lampiran DO"></div>
        <?php elseif ($fileExt === 'pdf'): ?>
          <div class="mt-3"><iframe class="w-100" style="height:300px;border:1px solid var(--mcp-border);border-radius:10px"
                                    src="<?= e($fileUrl) ?>" title="Lampiran DO"></iframe></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($prod['catatan_koreksi'])): ?>
    <div class="alert alert-warning"><?= e($prod['catatan_koreksi']) ?></div>
  <?php endif; ?>
  <form method="post" action=""<?= form_readonly_attr($prod['do_status'] ?? '') ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="produksi_id" value="<?= (int)$prod['id'] ?>">
    <div class="card card-mcp">
      <div class="card-header">Data Produksi</div>
      <div class="card-body">
        <dl class="kv">
          <dt>Customer</dt><dd><?= e($prod['customer']) ?></dd>
          <dt>Proses</dt><dd><?= $prod['jenis_proses'] === 'potong' ? 'Roll + Potong' : 'Roll saja' ?></dd>
          <dt>Tgl. Ambil</dt><dd><?= e(format_tanggal($prod['tgl_ambil'])) ?></dd>
          <dt>Catatan PPIC</dt><dd><?= e($prod['catatan'] ?: '-') ?></dd>
        </dl>
      </div>
    </div>
    <div class="card card-mcp">
      <div class="card-header">Hasil Pemeriksaan</div>
      <div class="card-body">
        <div class="mb-3">
          <label for="tgl_verifikasi" class="form-label">Tanggal Verifikasi <span class="text-danger">*</span></label>
          <input type="date" class="form-control" id="tgl_verifikasi" name="tgl_verifikasi" required
                 value="<?= e($qcData['tgl_verifikasi'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0" style="min-width:520px">
            <thead><tr><th>Ukuran</th><th>Jumlah Produksi</th><th>Satuan</th><th>Jumlah Lolos QC</th></tr></thead>
            <tbody>
              <?php foreach ($details as $d): ?>
                <tr>
                  <td><?= e($d['ukuran']) ?></td>
                  <td><?= e(format_angka($d['jumlah'])) ?></td>
                  <td><?= e($d['satuan']) ?></td>
                  <td><input type="number" min="0" step="0.01" class="form-control" name="lolos[<?= (int)$d['id'] ?>]"
                             value="<?= e($qcRows[$d['id']] ?? $d['jumlah']) ?>"></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mt-3">
          <label for="catatan" class="form-label">Catatan QC</label>
          <textarea class="form-control" id="catatan" name="catatan" rows="2"><?= e($qcData['catatan'] ?? '') ?></textarea>
          <div class="field-hint">Jika semua jumlah lolos = 0, barang tidak diteruskan ke gudang.</div>
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary" data-confirm="Simpan hasil QC dan lanjutkan alur?">Simpan Verifikasi QC</button>
  </form>
</section>
<?php else: ?>
<section>
  <div class="page-heading"><div><h1>Antrian Quality Control</h1><div class="page-subtitle">SPK yang sudah diproses PPIC</div></div></div>
  <?= $tabsStatus ?>
  <div class="card card-mcp">
    <div class="card-body p-0">
      <?php if (!$list): ?>
        <div class="empty-state">Tidak ada SPK yang menunggu QC.</div>
      <?php else: ?>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0">
            <thead><tr><th>No. DO</th><th>No. SPK</th><th>Customer</th><th>Proses</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($list as $row): ?>
                <tr>
                  <td><?= e($row['no_do']) ?></td>
                  <td><?= e($row['no_spk']) ?></td>
                  <td><?= e($row['customer']) ?></td>
                  <td><?= $row['jenis_proses'] === 'potong' ? 'Roll + Potong' : 'Roll' ?></td>
                  <td><?= status_badge($row['do_status'] ?? $row['status'] ?? 'qc') ?></td>
                  <td class="action-col">
                    <?php if (status_is_open($row['do_status'] ?? $row['status'] ?? '')): ?>
                      <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>qc.php?act=form&id=<?= (int)$row['id'] ?>">
                        <?= (int)$row['sudah'] > 0 ? 'Koreksi' : 'Verifikasi' ?></a>
                    <?php else: ?>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>qc.php?act=form&id=<?= (int)$row['id'] ?>">Lihat</a>
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
