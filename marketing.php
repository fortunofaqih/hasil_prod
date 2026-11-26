<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'marketing']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);

// ---------------- Simpan DO ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!csrf_check()) {
        set_flash('Token keamanan tidak valid.', 'danger');
        redirect('marketing.php');
    }

    $editId = (int) post('id');
    $cekSt = $pdo->prepare('SELECT status FROM do_header WHERE id=?');
    $cekSt->execute([$editId]);
    tolak_jika_closed($cekSt->fetchColumn(), 'marketing.php?act=form&id=' . $editId . '');
    $data = [
        'no_do'     => strtoupper(post('no_do')),
        'no_spk'    => strtoupper(post('no_spk')),
        'tgl_ambil' => post('tgl_ambil'),
        'customer'  => post('customer'),
        'category'  => post('category'),
        'ukuran'    => post('ukuran'),
        'jumlah'    => (float) post('jumlah'),
        'satuan'    => post('satuan'),
        'kategori_kirim' => in_array(post('kategori_kirim'), ['Kirim', 'Las'], true) ? post('kategori_kirim') : 'Kirim',
    ];

    if ($data['no_do'] === '' || $data['no_spk'] === '' || $data['tgl_ambil'] === ''
        || $data['customer'] === '' || $data['jumlah'] <= 0) {
        set_flash('Lengkapi semua field wajib dengan benar.', 'danger');
        redirect('marketing.php?act=form' . ($editId ? '&id=' . $editId : ''));
    }

    $fileName = null;
    if (!empty($_FILES['file_do']['name'])) {
        $fileName = upload_do($_FILES['file_do']);
        if ($fileName === null) {
            set_flash('Upload gagal. Format diperbolehkan: JPG, PNG, PDF (maks. 5 MB).', 'danger');
            redirect('marketing.php?act=form' . ($editId ? '&id=' . $editId : ''));
        }
    }

    try {
        $pdo->beginTransaction();

        if ($editId > 0) {
            $old = $pdo->prepare('SELECT file_do, status, returned_to FROM do_header WHERE id=?');
            $old->execute([$editId]);
            $current = $old->fetch();
            $existing = $current['file_do'] ?? null;
            $statusBaru = ($current['status'] === 'koreksi' && $current['returned_to'] === 'marketing')
                ? 'ppic' : $current['status'];

            $sql = 'UPDATE do_header SET no_do=?, no_spk=?, tgl_ambil=?, customer=?, category=?,
                    ukuran=?, jumlah=?, satuan=?, kategori_kirim=?, status=?, returned_to=NULL, catatan_koreksi=NULL,
                    modified_by=?, modified_at=?';
            $params = [$data['no_do'], $data['no_spk'], $data['tgl_ambil'], $data['customer'],
                       $data['category'], $data['ukuran'], $data['jumlah'], $data['satuan'], $data['kategori_kirim'],
                       $statusBaru, user_id(), date('Y-m-d H:i:s')];
            if ($fileName) {
                $sql .= ', file_do=?';
                $params[] = $fileName;
            }
            $sql .= ' WHERE id=?';
            $params[] = $editId;
            $pdo->prepare($sql)->execute($params);

            $pdo->prepare('UPDATE do_detail SET ukuran=?, jumlah=?, satuan=?, modified_by=?, modified_at=? WHERE do_header_id=?')
                ->execute([$data['ukuran'], $data['jumlah'], $data['satuan'], user_id(), date('Y-m-d H:i:s'), $editId]);

            // Propagasi ke PPIC/QC/Gudang: baris detail produksi ikut diperbarui.
            $pdo->prepare('UPDATE produksi_detail SET ukuran=?, jumlah=?, satuan=?, modified_by=?, modified_at=?
                           WHERE do_detail_id IN (SELECT id FROM do_detail WHERE do_header_id=?)')
                ->execute([$data['ukuran'], $data['jumlah'], $data['satuan'], user_id(), date('Y-m-d H:i:s'), $editId]);

            $doId = $editId;
            $note = 'Edit data DO oleh marketing: ' . $data['no_do'];
        } else {
            $stmt = $pdo->prepare('INSERT INTO do_header
                (no_do, no_spk, tgl_ambil, customer, category, ukuran, jumlah, satuan, kategori_kirim,
                 file_do, status, created_by, modified_by, created_at, modified_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,\'ppic\',?,?,NOW(),NOW())');
            $stmt->execute([$data['no_do'], $data['no_spk'], $data['tgl_ambil'], $data['customer'],
                $data['category'], $data['ukuran'], $data['jumlah'], $data['satuan'], $data['kategori_kirim'],
                $fileName, user_id(), user_id()]);
            $doId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO do_detail (do_header_id, ukuran, jumlah, satuan, created_by, modified_by, created_at, modified_at)
                           VALUES (?,?,?,?,?,?,NOW(),NOW())')
                ->execute([$doId, $data['ukuran'], $data['jumlah'], $data['satuan'], user_id(), user_id()]);
            $note = 'Input DO baru: ' . $data['no_do'] . ' / SPK ' . $data['no_spk'];
        }

        track($doId, 'marketing', $note);
        $pdo->commit();

        if ($fileName && $editId && !empty($existing)) {
            @unlink(UPLOAD_DO_DIR . $existing);
        }
        set_flash($editId ? 'Data DO berhasil dikoreksi dan diteruskan ke PPIC.' : 'DO berhasil disimpan dan diteruskan ke PPIC.');
        redirect('marketing.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($fileName) { @unlink(UPLOAD_DO_DIR . $fileName); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('marketing.php?act=form' . ($editId ? '&id=' . $editId : ''));
    }
}

// ---------------- Hapus DO ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (!csrf_check()) {
        set_flash('Token keamanan tidak valid.', 'danger');
        redirect('marketing.php');
    }
    $hapusId = (int) post('id');
    $stmt = $pdo->prepare('SELECT no_do, file_do, created_by, status FROM do_header WHERE id=?');
    $stmt->execute([$hapusId]);
    $target = $stmt->fetch();

    tolak_jika_closed($target['status'] ?? '', 'marketing.php');

    if (!$target || (($target['created_by'] != user_id()) && ($user['role'] !== 'admin'))) {
        set_flash('DO tidak ditemukan atau Anda tidak berhak menghapusnya.', 'danger');
        redirect('marketing.php');
    }

    // FK ON DELETE CASCADE menghapus detail, produksi, QC, gudang, manager, dan tracking.
    $pdo->prepare('DELETE FROM do_header WHERE id=?')->execute([$hapusId]);
    if (!empty($target['file_do'])) {
        @unlink(UPLOAD_DO_DIR . $target['file_do']);
    }
    set_flash('DO ' . $target['no_do'] . ' beserta seluruh alurnya berhasil dihapus.');
    redirect('marketing.php');
}

// ---------------- Data ----------------
$where = ($user['role'] === 'marketing') ? 'WHERE d.created_by = ?' : '';
$params = ($user['role'] === 'marketing') ? [user_id()] : [];
$rows = $pdo->prepare("SELECT d.*, u.nama AS input_by FROM do_header d LEFT JOIN users u ON u.id=d.created_by $where ORDER BY d.id DESC");
$rows->execute($params);
$list = $rows->fetchAll();

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari($list, $statusSekarang);
$list = status_pisah($list, $statusSekarang);

$edit = null;
if ($act === 'form' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM do_header WHERE id=?');
    $stmt->execute([$id]);
    $edit = $stmt->fetch();
}

$detail = null;
if ($act === 'detail' && $id > 0) {
    $stmt = $pdo->prepare('SELECT d.*, u.nama AS input_by FROM do_header d LEFT JOIN users u ON u.id=d.created_by WHERE d.id=?');
    $stmt->execute([$id]);
    $detail = $stmt->fetch();
}

$pageTitle = 'Input DO';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'form'): ?>
<section>
  <div class="page-heading">
    <div><h1><?= $edit ? 'Koreksi DO' : 'Input DO Baru' ?></h1><div class="page-subtitle">Form Delivery Order &mdash; Marketing</div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>marketing.php">&larr; Kembali</a>
  </div>
  <?= audit_card($edit ?: null, 'Audit Data DO') ?>
  <form method="post" enctype="multipart/form-data" action=""<?= form_readonly_attr($edit['status'] ?? '') ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

    <div class="card card-mcp">
      <div class="card-header">Data Delivery Order</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label for="no_do" class="form-label">No. DO <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="no_do" name="no_do" required value="<?= e($edit['no_do'] ?? '') ?>">
          </div>
          <div class="col-12 col-md-6">
            <label for="no_spk" class="form-label">No. SPK <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="no_spk" name="no_spk" required value="<?= e($edit['no_spk'] ?? '') ?>">
          </div>
          <div class="col-12 col-md-6">
            <label for="tgl_ambil" class="form-label">Tanggal Ambil Hasil Produksi <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="tgl_ambil" name="tgl_ambil" required value="<?= e($edit['tgl_ambil'] ?? '') ?>">
          </div>
          <div class="col-12 col-md-6">
            <label for="customer" class="form-label">Nama Customer <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="customer" name="customer" required value="<?= e($edit['customer'] ?? '') ?>">
          </div>
          <div class="col-12 col-md-6">
            <label for="category" class="form-label">Category</label>
            <input type="text" class="form-control" id="category" name="category" placeholder="Contoh: Potong / Roll" value="<?= e($edit['category'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="card card-mcp">
      <div class="card-header">Detail Barang &amp; Pengiriman</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label for="ukuran" class="form-label">Ukuran</label>
            <input type="text" class="form-control" id="ukuran" name="ukuran" placeholder="Contoh: 0,6 mm x 1000 mm" value="<?= e($edit['ukuran'] ?? '') ?>">
          </div>
          <div class="col-6 col-md-4">
            <label for="jumlah" class="form-label">Jumlah <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0.01" class="form-control" id="jumlah" name="jumlah" required value="<?= e($edit['jumlah'] ?? '') ?>">
          </div>
          <div class="col-6 col-md-4">
            <label for="satuan" class="form-label">Satuan</label>
            <input type="text" class="form-control" id="satuan" name="satuan" placeholder="kg / roll / pcs" value="<?= e($edit['satuan'] ?? '') ?>">
          </div>
          <div class="col-12 col-md-6">
            <label for="kategori_kirim" class="form-label">Kategori Kirim / Las</label>
            <select class="form-select" id="kategori_kirim" name="kategori_kirim">
              <?php foreach (['Kirim', 'Las'] as $k): ?>
                <option value="<?= $k ?>" <?= (($edit['kategori_kirim'] ?? 'Kirim') === $k) ? 'selected' : '' ?>><?= $k ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label for="file_do" class="form-label">Upload DO (gambar / PDF)</label>
            <input type="file" class="form-control" id="file_do" name="file_do" accept=".jpg,.jpeg,.png,.application/pdf,image/jpeg,image/png,application/pdf">
            <div class="field-hint">Maks. 5 MB. <?= !empty($edit['file_do']) ? 'Kosongkan jika tidak mengganti file.' : '' ?></div>
            <?php if (!empty($edit['file_do'])): ?>
              <a class="d-inline-block mt-2 small" href="<?= BASE_URL ?>uploads/do/<?= e($edit['file_do']) ?>" target="_blank" rel="noopener">Lihat file saat ini &nearr;</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Simpan &amp; Teruskan ke PPIC</button>
  </form>
</section>

<?php elseif ($act === 'detail' && $detail): ?>
<section>
  <div class="page-heading">
    <div><h1>Detail DO</h1><div class="page-subtitle">No. DO <?= e($detail['no_do']) ?></div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>marketing.php">&larr; Kembali</a>
  </div>
  <div class="card card-mcp">
    <div class="card-header">Data Delivery Order <?= status_badge($detail['status']) ?></div>
    <div class="card-body">
      <dl class="kv">
        <dt>No. DO</dt><dd><?= e($detail['no_do']) ?></dd>
        <dt>No. SPK</dt><dd><?= e($detail['no_spk']) ?></dd>
        <dt>Tgl. Order (PPIC)</dt><dd><?= e(format_tanggal($detail['tgl_order'])) ?></dd>
        <dt>Tgl. Ambil</dt><dd><?= e(format_tanggal($detail['tgl_ambil'])) ?></dd>
        <dt>Customer</dt><dd><?= e($detail['customer']) ?></dd>
        <dt>Category</dt><dd><?= e($detail['category']) ?></dd>
        <dt>Ukuran</dt><dd><?= e($detail['ukuran']) ?></dd>
        <dt>Jumlah</dt><dd><?= e(format_angka($detail['jumlah'])) ?> <?= e($detail['satuan']) ?></dd>
        <dt>Kategori</dt><dd><?= e($detail['kategori_kirim']) ?></dd>
        <dt>Input oleh</dt><dd><?= e($detail['input_by']) ?></dd>
        <?php if (!empty($detail['catatan_koreksi'])): ?>
          <dt class="text-danger">Catatan koreksi</dt><dd class="text-danger"><?= e($detail['catatan_koreksi']) ?></dd>
        <?php endif; ?>
      </dl>
      <?= audit_card($detail ?: null, 'Audit Data DO') ?>
      <?php if (!empty($detail['file_do'])): ?>
        <div class="mt-3">
          <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>uploads/do/<?= e($detail['file_do']) ?>" target="_blank" rel="noopener">Buka DO</a>
          <?php $ext = strtolower(pathinfo($detail['file_do'], PATHINFO_EXTENSION)); ?>
          <?php if (in_array($ext, ['jpg', 'jpeg', 'png'], true)): ?>
            <div class="mt-2"><img class="file-preview" src="<?= BASE_URL ?>uploads/do/<?= e($detail['file_do']) ?>" alt="Dokumen DO"></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php else: ?>
<section>
  <div class="page-heading">
    <div><h1>Data Delivery Order</h1><div class="page-subtitle">Daftar DO yang sudah dibuat marketing</div></div>
    <a class="btn btn-primary" href="<?= BASE_URL ?>marketing.php?act=form">+ Input DO</a>
  </div>
  <?= $tabsStatus ?>
  <div class="card card-mcp">
    <div class="card-body p-0">
      <?php if (!$list): ?>
        <div class="empty-state">Belum ada data DO.</div>
      <?php else: ?>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0">
            <thead><tr><th>No. DO</th><th>No. SPK</th><th>Customer</th><th>Tgl Ambil</th><th>Jumlah</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($list as $row): ?>
                <tr>
                  <td><?= e($row['no_do']) ?></td>
                  <td><?= e($row['no_spk']) ?></td>
                  <td><?= e($row['customer']) ?></td>
                  <td><?= e(format_tanggal($row['tgl_ambil'])) ?></td>
                  <td><?= e(format_angka($row['jumlah'])) ?> <?= e($row['satuan']) ?></td>
                  <td><?= status_badge($row['status']) ?></td>
                  <td class="action-col">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>marketing.php?act=detail&id=<?= (int)$row['id'] ?>">Detail</a>
                    <?php if (status_is_open($row['status'])): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>marketing.php?act=form&id=<?= (int)$row['id'] ?>">Edit</a>
                    <form method="post" action="" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"
                              data-confirm="Hapus DO <?= e($row['no_do']) ?> beserta seluruh alurnya?">Delete</button>
                    </form>
                    <?php else: ?>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>marketing.php?act=form&id=<?= (int)$row['id'] ?>">Lihat</a>
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
