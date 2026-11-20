<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'manager']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0); // do_header id

// ---------------- Verifikasi / kembalikan ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['verify', 'return'], true)) {
    if (!csrf_check()) {
        set_flash('Token keamanan tidak valid.', 'danger');
        redirect('manager.php');
    }
    $doId = (int) post('do_id');
    $tgl = post('tgl_verifikasi');
    $catatan = post('catatan');
    $action = post('action');

    $stmt = $pdo->prepare('SELECT id, no_do, no_spk FROM do_header WHERE id=?');
    $stmt->execute([$doId]);
    $do = $stmt->fetch();
    if (!$do || $tgl === '') {
        set_flash('Data verifikasi tidak lengkap.', 'danger');
        redirect('manager.php');
    }

    try {
        $pdo->beginTransaction();
        $exist = $pdo->prepare('SELECT id FROM manager_header WHERE do_header_id=? ORDER BY id DESC LIMIT 1');
        $exist->execute([$doId]);
        $mId = (int) $exist->fetchColumn();

        if ($action === 'verify') {
            $status = 'verified';
            $released = date('Y-m-d H:i:s');
            if ($mId) {
                $pdo->prepare('UPDATE manager_header SET tgl_verifikasi=?, status=?, catatan=?, released_at=?, modified_by=?, modified_at=? WHERE id=?')
                    ->execute([$tgl, $status, $catatan, $released, user_id(), date('Y-m-d H:i:s'), $mId]);
                $pdo->prepare('DELETE FROM manager_detail WHERE manager_header_id=?')->execute([$mId]);
            } else {
                $pdo->prepare('INSERT INTO manager_header
                    (do_header_id, no_spk, tgl_verifikasi, status, catatan, released_at, created_by, modified_by, created_at, modified_at)
                    VALUES (?,?,?,\'verified\',?,?,?,?,NOW(),NOW())')
                    ->execute([$doId, $do['no_spk'], $tgl, $catatan, $released, user_id(), user_id()]);
                $mId = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO manager_detail (manager_header_id, stage, status, catatan, created_by, modified_by, created_at, modified_at)
                                  VALUES (?,?,\'verified\',?,?,?,NOW(),NOW())');
            foreach (['marketing', 'produksi', 'qc', 'gudang'] as $stage) {
                $ins->execute([$mId, $stage, 'Disetujui manager', user_id(), user_id()]);
            }
            set_do_status($doId, 'siap_ambil');
            $ket = 'Manager menyetujui pengambilan DO ' . $do['no_do'] . ' / SPK ' . $do['no_spk'] . '. Gudang boleh melepas barang.';
        } else {
            $target = post('returned_to');
            if (!in_array($target, ['marketing', 'produksi', 'qc', 'gudang'], true)) {
                set_flash('Pilih bagian yang akan dikoreksi.', 'danger');
                redirect('manager.php?act=detail&id=' . $doId);
            }
            if ($mId) {
                $pdo->prepare('UPDATE manager_header SET tgl_verifikasi=?, status=\'koreksi\', catatan=?, released_at=NULL, modified_by=?, modified_at=? WHERE id=?')
                    ->execute([$tgl, $catatan, user_id(), date('Y-m-d H:i:s'), $mId]);
                $pdo->prepare('DELETE FROM manager_detail WHERE manager_header_id=?')->execute([$mId]);
            } else {
                $pdo->prepare('INSERT INTO manager_header
                    (do_header_id, no_spk, tgl_verifikasi, status, catatan, created_by, modified_by, created_at, modified_at)
                    VALUES (?,?,?,\'koreksi\',?,?,?,NOW(),NOW())')
                    ->execute([$doId, $do['no_spk'], $tgl, $catatan, user_id(), user_id()]);
                $mId = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO manager_detail (manager_header_id, stage, status, catatan, created_by, modified_by, created_at, modified_at)
                                  VALUES (?,?,?,?,?,?,NOW(),NOW())');
            foreach (['marketing', 'produksi', 'qc', 'gudang'] as $stage) {
                $ins->execute([$mId, $stage, $stage === $target ? 'koreksi' : 'sementara', $stage === $target ? $catatan : '', user_id(), user_id()]);
            }
            set_do_status($doId, 'koreksi', $target, $catatan);
            $ket = 'Manager mengembalikan DO ke bagian ' . strtoupper($target) . ' untuk koreksi.';
        }

        track($doId, 'manager', $ket);
        $pdo->commit();
        set_flash($action === 'verify' ? 'Disetujui. Gudang kini boleh melepas barang.' : 'DO dikembalikan ke bagian ' . strtoupper($target) . '.');
        redirect('manager.php?act=detail&id=' . $doId);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('manager.php?act=detail&id=' . $doId);
    }
}

$list = $pdo->query("SELECT d.id, d.no_do, d.no_spk, d.customer, d.status, d.tgl_ambil, d.jumlah, d.satuan,
                     (SELECT COUNT(*) FROM tracking_header t WHERE t.do_header_id=d.id) AS jml_track
                     FROM do_header d ORDER BY d.id DESC")->fetchAll();

$do = null;
$timeline = [];
$stages = [];
if ($act === 'detail' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM do_header WHERE id=?');
    $stmt->execute([$id]);
    $do = $stmt->fetch();
    if ($do) {
        $stmt = $pdo->prepare("SELECT dd.id, dd.ukuran, dd.satuan, dd.jumlah AS qty_do,
            COALESCE((SELECT SUM(pd.jumlah) FROM produksi_detail pd
                      JOIN produksi_header ph ON ph.id=pd.produksi_header_id
                      WHERE pd.do_detail_id=dd.id AND ph.do_header_id=dd.do_header_id), 0) AS qty_produksi,
            COALESCE((SELECT SUM(qd.jumlah_lolos) FROM qc_detail qd
                      JOIN produksi_detail pd2 ON pd2.id=qd.produksi_detail_id
                      JOIN produksi_header ph2 ON ph2.id=pd2.produksi_header_id
                      WHERE pd2.do_detail_id=dd.id AND ph2.do_header_id=dd.do_header_id), 0) AS qty_qc,
            COALESCE((SELECT SUM(gd.jumlah_siap) FROM gudang_detail gd
                      JOIN qc_detail qd2 ON qd2.id=gd.qc_detail_id
                      JOIN produksi_detail pd3 ON pd3.id=qd2.produksi_detail_id
                      JOIN produksi_header ph3 ON ph3.id=pd3.produksi_header_id
                      WHERE pd3.do_detail_id=dd.id AND ph3.do_header_id=dd.do_header_id), 0) AS qty_gudang
            FROM do_detail dd WHERE dd.do_header_id=? ORDER BY dd.id");
        $stmt->execute([$id]);
        $qtyRows = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT t.stage, t.keterangan, t.created_at, u.nama AS user_nama
            FROM tracking_detail t LEFT JOIN users u ON u.id=t.created_by
            WHERE t.tracking_header_id IN (SELECT id FROM tracking_header WHERE do_header_id=?) ORDER BY t.id');
        $stmt->execute([$id]);
        $timeline = $stmt->fetchAll();

        $stages = [
            'Marketing' => $pdo->query("SELECT no_do, no_spk, created_at FROM do_header WHERE id=$id")->fetch(),
            'Produksi'  => $pdo->query("SELECT no_spk, jenis_proses, tgl_rencana_roll, tgl_realisasi_roll, tgl_rencana_potong, tgl_realisasi_potong, created_at FROM produksi_header WHERE do_header_id=$id ORDER BY id DESC LIMIT 1")->fetch(),
            'QC'        => $pdo->query("SELECT q.no_spk, q.jumlah_lolos, q.tgl_verifikasi, q.catatan FROM qc_header q JOIN produksi_header p ON p.id=q.produksi_header_id WHERE p.do_header_id=$id ORDER BY q.id DESC LIMIT 1")->fetch(),
            'Gudang'    => $pdo->query("SELECT g.no_spk, g.jumlah_siap, g.tgl_verifikasi, g.status, g.released_at FROM gudang_header g JOIN qc_header q ON q.id=g.qc_header_id JOIN produksi_header p ON p.id=q.produksi_header_id WHERE p.do_header_id=$id ORDER BY g.id DESC LIMIT 1")->fetch(),
            'Manager'   => $pdo->query("SELECT status, catatan, tgl_verifikasi, released_at FROM manager_header WHERE do_header_id=$id ORDER BY id DESC LIMIT 1")->fetch(),
        ];
    }
}

$pageTitle = 'Manager';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'detail' && $do): ?>
<section>
  <div class="page-heading">
    <div><h1>History DO / SPK</h1><div class="page-subtitle"><?= e($do['no_do']) ?> &middot; <?= e($do['no_spk']) ?> &middot; <?= e($do['customer']) ?> &mdash; <?= status_badge($do['status']) ?></div></div>
    <div class="d-flex flex-wrap gap-2">
      <?php if (!empty($do['file_do'])): ?>
        <a class="btn btn-primary" href="<?= BASE_URL ?>uploads/do/<?= e(rawurlencode($do['file_do'])) ?>"
           target="_blank" rel="noopener" title="Buka file DO yang diunggah marketing">
          <i class="bi bi-file-earmark-check" aria-hidden="true"></i> Buka File DO
        </a>
      <?php else: ?>
        <button class="btn btn-outline-secondary" type="button" disabled title="Marketing tidak mengunggah file DO">
          <i class="bi bi-file-earmark-x" aria-hidden="true"></i> File DO tidak ada
        </button>
      <?php endif; ?>
      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>manager.php">&larr; Kembali</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-7">
      <div class="card card-mcp">
        <div class="card-header">Ringkasan per Bagian</div>
        <div class="card-body">
          <?php foreach ($stages as $nama => $data): ?>
            <div class="mb-3">
              <div class="section-title mb-2"><?= e($nama) ?></div>
              <?php if (!$data): ?>
                <div class="text-mute small">Belum ada data.</div>
              <?php else: ?>
                <dl class="kv">
                  <?php foreach ($data as $key => $value): ?>
                    <?php if (in_array($key, ['created_at', 'released_at']) && $value): ?>
                      <dt><?= $key === 'created_at' ? 'Waktu' : 'Rilis' ?></dt><dd><?= e(date('d/m/Y H:i', strtotime($value))) ?></dd>
                    <?php elseif ($key === 'tgl_verifikasi' && $value): ?>
                      <dt>Tgl. verifikasi</dt><dd><?= e(format_tanggal($value)) ?></dd>
                    <?php elseif ($key === 'jenis_proses'): ?>
                      <dt>Proses</dt><dd><?= $value === 'potong' ? 'Roll + Potong' : 'Roll saja' ?></dd>
                    <?php elseif (str_contains($key, 'tgl_')): ?>
                      <dt><?= e(str_replace('_', ' ', $key)) ?></dt><dd><?= e(format_tanggal($value ?: null)) ?></dd>
                    <?php elseif ($key === 'jumlah_lolos' || $key === 'jumlah_siap'): ?>
                      <dt><?= $key === 'jumlah_lolos' ? 'Lolos QC' : 'Siap diambil' ?></dt><dd><?= e(format_angka($value)) ?></dd>
                    <?php elseif ($key === 'status'): ?>
                      <dt>Status</dt><dd><?= e($value) ?></dd>
                    <?php elseif ($key === 'catatan' && $value): ?>
                      <dt>Catatan</dt><dd><?= e($value) ?></dd>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </dl>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card card-mcp">
        <div class="card-header">Dokumen DO dari Marketing</div>
        <div class="card-body">
          <?php if (empty($do['file_do'])): ?>
            <div class="empty-state py-3">Tidak ada file yang diunggah saat input DO.</div>
          <?php else: ?>
            <?php
              $fileUrl = BASE_URL . 'uploads/do/' . rawurlencode($do['file_do']);
              $fileExt = strtolower(pathinfo($do['file_do'], PATHINFO_EXTENSION));
            ?>
            <dl class="kv">
              <dt>Nama file</dt><dd><?= e($do['file_do']) ?></dd>
              <dt>Jenis</dt><dd><?= e(strtoupper($fileExt)) ?></dd>
            </dl>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <a class="btn btn-sm btn-outline-primary" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right"></i> Buka di Tab Baru
              </a>
              <a class="btn btn-sm btn-outline-secondary" href="<?= e($fileUrl) ?>" download>
                <i class="bi bi-download"></i> Unduh
              </a>
            </div>
            <?php if (in_array($fileExt, ['jpg', 'jpeg', 'png'], true)): ?>
              <div class="mt-3"><img class="file-preview" src="<?= e($fileUrl) ?>" alt="Dokumen DO yang diunggah marketing"></div>
            <?php elseif ($fileExt === 'pdf'): ?>
              <div class="mt-3"><iframe class="w-100" style="height:320px;border:1px solid var(--mcp-border);border-radius:10px"
                                        src="<?= e($fileUrl) ?>" title="Dokumen DO"></iframe></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card card-mcp">
        <div class="card-header">Perbandingan Qty per SPK</div>
        <div class="card-body p-0">
          <?php if (!$qtyRows): ?>
            <div class="empty-state">Belum ada detail barang pada DO ini.</div>
          <?php else: ?>
            <?php
              $tDo = 0.0; $tPro = 0.0; $tQc = 0.0; $tGud = 0.0;
              foreach ($qtyRows as $q) {
                  $tDo  += (float) $q['qty_do'];
                  $tPro += (float) $q['qty_produksi'];
                  $tQc  += (float) $q['qty_qc'];
                  $tGud += (float) $q['qty_gudang'];
              }
              $satuan = $qtyRows[0]['satuan'];
            ?>
            <div class="table-responsive-mcp">
              <table class="table table-mcp mb-0" style="min-width:720px">
                <thead>
                  <tr>
                    <th>Ukuran</th><th>Satuan</th>
                    <th class="text-end">Marketing (DO)</th>
                    <th class="text-end">Produksi</th>
                    <th class="text-end">QC Lolos</th>
                    <th class="text-end">Selisih</th>
                    <th class="text-end">Gudang Siap</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($qtyRows as $q): ?>
                    <?php $selisih = (float) $q['qty_produksi'] - (float) $q['qty_qc']; ?>
                    <tr>
                      <td><?= e($q['ukuran']) ?></td>
                      <td><?= e($q['satuan']) ?></td>
                      <td class="text-end"><?= e(format_angka($q['qty_do'])) ?></td>
                      <td class="text-end"><?= e(format_angka($q['qty_produksi'])) ?></td>
                      <td class="text-end"><?= e(format_angka($q['qty_qc'])) ?></td>
                      <td class="text-end">
                        <?php if ($selisih > 0): ?>
                          <span class="badge bg-danger"><?= e(format_angka($selisih)) ?></span>
                        <?php else: ?>
                          <span class="badge bg-success">0</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end"><?= e(format_angka($q['qty_gudang'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot class="fw-bold">
                  <tr>
                    <td colspan="2">Total</td>
                    <td class="text-end"><?= e(format_angka($tDo)) ?></td>
                    <td class="text-end"><?= e(format_angka($tPro)) ?></td>
                    <td class="text-end"><?= e(format_angka($tQc)) ?></td>
                    <td class="text-end"><?= e(format_angka($tPro - $tQc)) ?></td>
                    <td class="text-end"><?= e(format_angka($tGud)) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <?php if (($tPro - $tQc) > 0): ?>
              <div class="p-3 pt-0">
                <div class="alert alert-warning mb-0 small">
                  Selisih <strong><?= e(format_angka($tPro - $tQc)) ?> <?= e($satuan) ?></strong> antara produksi dan QC
                  (barang tidak lolos / rusak). QC meneruskan <?= e(format_angka($tQc)) ?> <?= e($satuan) ?> ke gudang.
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!in_array($do['status'], ['gagal', 'siap_ambil', 'selesai'], true)): ?>
      <div class="card card-mcp">
        <div class="card-header">Keputusan Manager</div>
        <div class="card-body">
          <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do_id" value="<?= (int)$do['id'] ?>">
            <div class="row g-3">
              <div class="col-12 col-md-5">
                <label for="tgl_verifikasi" class="form-label">Tanggal Verifikasi</label>
                <input type="date" class="form-control" id="tgl_verifikasi" name="tgl_verifikasi" value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="col-12">
                <label for="catatan" class="form-label">Catatan</label>
                <textarea class="form-control" id="catatan" name="catatan" rows="2"></textarea>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <button type="submit" name="action" value="verify" class="btn btn-success" data-confirm="Setujui pengambilan barang?">Verifikasi &amp; Setujui Ambil</button>
            </div>
            <hr>
            <div class="row g-2 align-items-end">
              <div class="col-12 col-md-5">
                <label for="returned_to" class="form-label">Kembalikan ke bagian</label>
                <select class="form-select" id="returned_to" name="returned_to">
                  <option value="marketing">Marketing</option>
                  <option value="produksi">Produksi / PPIC</option>
                  <option value="qc">QC</option>
                  <option value="gudang">Gudang</option>
                </select>
              </div>
              <div class="col-12 col-md-7">
                <button type="submit" name="action" value="return" class="btn btn-danger w-100" data-confirm="Kembalikan data ke bagian tersebut?">Kembalikan untuk Koreksi</button>
              </div>
            </div>
          </form>
        </div>
      </div>
      <?php else: ?>
        <div class="alert alert-info">Alur sudah selesai / disetujui. Tidak ada tindakan lanjutan dari manager.</div>
      <?php endif; ?>
    </div>

    <div class="col-12 col-lg-5">
      <div class="card card-mcp">
        <div class="card-header">Timeline</div>
        <div class="card-body">
          <?php if (!$timeline): ?>
            <div class="empty-state">Belum ada aktivitas.</div>
          <?php else: ?>
            <ul class="timeline">
              <?php foreach ($timeline as $t): ?>
                <li>
                  <div class="stage"><?= e(ucfirst($t['stage'])) ?></div>
                  <div><?= e($t['keterangan']) ?></div>
                  <div class="meta"><?= e($t['user_nama'] ?: '-') ?> &middot; <?= e(date('d/m/Y H:i', strtotime($t['created_at']))) ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php else: ?>
<section>
  <div class="page-heading"><div><h1>Verifikasi Manager</h1><div class="page-subtitle">Periksa history DO/SPK dari marketing sampai gudang</div></div></div>
  <div class="card card-mcp">
    <div class="card-body p-0">
      <?php if (!$list): ?>
        <div class="empty-state">Belum ada data DO.</div>
      <?php else: ?>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0">
            <thead><tr><th>No. DO</th><th>No. SPK</th><th>Customer</th><th>Tgl Ambil</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($list as $row): ?>
                <tr>
                  <td><?= e($row['no_do']) ?></td>
                  <td><?= e($row['no_spk']) ?></td>
                  <td><?= e($row['customer']) ?></td>
                  <td><?= e(format_tanggal($row['tgl_ambil'])) ?></td>
                  <td><?= status_badge($row['status']) ?></td>
                  <td class="action-col"><a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>manager.php?act=detail&id=<?= (int)$row['id'] ?>">Lihat History</a></td>
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
