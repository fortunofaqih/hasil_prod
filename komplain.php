<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin', 'marketing']);

$pdo = db();
$act = $_GET['act'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$satuanList = ['roll', 'bal', 'KG', 'lembar'];

// Konfirmasi marketing atas retur mandiri satpam.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'confirm_retur') {
    if (!csrf_check()) { set_flash('Token tidak valid.', 'danger'); redirect('komplain.php'); }
    $returId = (int) post('retur_id');
    $jawaban = post('jawaban');
    if (!in_array($jawaban, ['yes', 'no'], true)) {
        set_flash('Pilihan konfirmasi tidak valid.', 'danger');
        redirect('komplain.php');
    }
    $cek = $pdo->prepare('SELECT no_retur FROM retur_header WHERE id=? AND komplain_id IS NULL');
    $cek->execute([$returId]);
    $noRetur = $cek->fetchColumn();
    if (!$noRetur) {
        set_flash('Data retur mandiri tidak ditemukan.', 'danger');
        redirect('komplain.php');
    }
    $pdo->prepare('UPDATE retur_header SET marketing_confirm=?, marketing_confirm_by=?, marketing_confirm_at=?,
                   modified_by=?, modified_at=? WHERE id=?')
        ->execute([$jawaban, user_id(), date('Y-m-d H:i:s'), user_id(), date('Y-m-d H:i:s'), $returId]);
    set_flash('Konfirmasi ' . strtoupper($jawaban) . ' untuk ' . $noRetur . ' tersimpan.');
    redirect('komplain.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!csrf_check()) { set_flash('Token tidak valid.', 'danger'); redirect('komplain.php'); }
    $editId = (int) post('id');
    $cekSt = $pdo->prepare('SELECT status FROM komplain_header WHERE id=?');
    $cekSt->execute([$editId]);
    tolak_jika_closed($cekSt->fetchColumn(), 'komplain.php?act=form&id=' . $editId . '');
    $jenis = in_array(post('jenis'), ['komplain_saja', 'retur'], true) ? post('jenis') : 'komplain_saja';
    $ukuranArr = $_POST['ukuran'] ?? [];
    $jumlahArr = $_POST['jumlah'] ?? [];
    $satuanArr = $_POST['satuan'] ?? [];
    $ketArr = $_POST['keterangan_item'] ?? [];
    $kgArr = $_POST['jumlah_kg'] ?? [];
    $noSj = strtoupper(post('no_sj'));
    if ($noSj === '') { $noSj = '-'; }

    if (post('customer') === '' || !is_array($ukuranArr) || count($ukuranArr) === 0) {
        set_flash('Lengkapi field wajib dan minimal satu item.', 'danger');
        redirect('komplain.php?act=form' . ($editId ? '&id=' . $editId : ''));
    }

    $buktiKeluhan = null;
    if (!empty($_FILES['bukti_keluhan']['name'])) {
        $buktiKeluhan = upload_komplain($_FILES['bukti_keluhan']);
        if ($buktiKeluhan === null) {
            set_flash('Upload bukti keluhan gagal. Gunakan JPG/PNG/PDF maks. 5 MB.', 'danger');
            redirect('komplain.php?act=form' . ($editId ? '&id=' . $editId : ''));
        }
    }
    $buktiSelesai = null;
    if (!empty($_FILES['bukti_selesai']['name'])) {
        $buktiSelesai = upload_komplain($_FILES['bukti_selesai']);
        if ($buktiSelesai === null) {
            set_flash('Upload bukti penyelesaian gagal.', 'danger');
            redirect('komplain.php?act=form' . ($editId ? '&id=' . $editId : ''));
        }
    }

    try {
        $pdo->beginTransaction();
        if ($editId > 0) {
            $old = $pdo->prepare('SELECT bukti_keluhan, bukti_selesai FROM komplain_header WHERE id=?');
            $old->execute([$editId]); $existing = $old->fetch();
            $fields = 'no_sj=?, customer=?, tgl_komplain=?, jenis=?, status=?, keterangan_selesai=?, modified_by=?, modified_at=?';
            $params = [$noSj, post('customer'), post('tgl_komplain'), $jenis,
                $jenis === 'komplain_saja' ? 'closed' : 'open', post('keterangan_selesai'), user_id(), date('Y-m-d H:i:s')];
            if ($buktiKeluhan) { $fields .= ', bukti_keluhan=?'; $params[] = $buktiKeluhan; }
            if ($buktiSelesai) { $fields .= ', bukti_selesai=?'; $params[] = $buktiSelesai; }
            $params[] = $editId;
            $pdo->prepare("UPDATE komplain_header SET $fields WHERE id=?")->execute($params);
            $pdo->prepare('DELETE FROM komplain_detail WHERE komplain_header_id=?')->execute([$editId]);
            $komplainId = $editId;
        } else {
            $noKomplain = nomor_otomatis('KMP', 'komplain_header', 'id');
            $pdo->prepare('INSERT INTO komplain_header
                (no_komplain, no_sj, customer, tgl_komplain, jenis, status, bukti_keluhan, bukti_selesai, keterangan_selesai, created_by, modified_by, created_at, modified_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
                ->execute([$noKomplain, $noSj, post('customer'), post('tgl_komplain'), $jenis,
                    $jenis === 'komplain_saja' ? 'closed' : 'open', $buktiKeluhan, $buktiSelesai,
                    post('keterangan_selesai'), user_id(), user_id()]);
            $komplainId = (int) $pdo->lastInsertId();
        }

        $ins = $pdo->prepare('INSERT INTO komplain_detail
            (komplain_header_id, ukuran, jumlah, jumlah_kg, satuan, keterangan, created_by, modified_by, created_at, modified_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');
        foreach ($ukuranArr as $i => $uk) {
            if (trim((string) $uk) === '') { continue; }
            $ins->execute([$komplainId, trim((string) $uk), (float) ($jumlahArr[$i] ?? 0),
                (float) ($kgArr[$i] ?? 0), $satuanArr[$i] ?? 'roll', $ketArr[$i] ?? '', user_id(), user_id()]);
        }
        $pdo->commit();
        if ($editId && $buktiKeluhan && !empty($existing['bukti_keluhan'])) {
            @unlink(UPLOAD_KOMPLAIN_DIR . $existing['bukti_keluhan']);
        }
        set_flash($jenis === 'komplain_saja'
            ? 'Komplain tersimpan dan langsung CLOSE (diselesaikan ke customer).'
            : 'Komplain retur dikirim ke Admin Produksi dan dibagikan ke Satpam & Gudang.');
        redirect('komplain.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('Gagal menyimpan: ' . $ex->getMessage(), 'danger');
        redirect('komplain.php?act=form' . ($editId ? '&id=' . $editId : ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (!csrf_check()) { set_flash('Token tidak valid.', 'danger'); redirect('komplain.php'); }
    $hapus = (int) post('id');
    $row = $pdo->prepare('SELECT bukti_keluhan, bukti_selesai, created_by, status FROM komplain_header WHERE id=?');
    $row->execute([$hapus]); $k = $row->fetch();
    tolak_jika_closed($k['status'] ?? '', 'komplain.php');
    if ($k && ((int)$k['created_by'] === user_id() || $user['role'] === 'admin')) {
        $pdo->prepare('DELETE FROM komplain_header WHERE id=?')->execute([$hapus]);
        if ($k['bukti_keluhan']) { @unlink(UPLOAD_KOMPLAIN_DIR . $k['bukti_keluhan']); }
        if ($k['bukti_selesai']) { @unlink(UPLOAD_KOMPLAIN_DIR . $k['bukti_selesai']); }
        set_flash('Komplain dihapus.');
    } else { set_flash('Tidak berhak menghapus.', 'danger'); }
    redirect('komplain.php');
}

$list = $pdo->query('SELECT k.*, (SELECT COUNT(*) FROM komplain_detail d WHERE d.komplain_header_id=k.id) AS item_count
                     FROM komplain_header k ORDER BY k.id DESC')->fetchAll();
$returTerkait = $pdo->query("SELECT r.no_retur, r.no_sj, r.customer, r.tgl_kembali, r.alasan, r.status
                             FROM retur_header r WHERE r.alasan='keluhan mutu' ORDER BY r.id DESC")->fetchAll();
$returMandiri = $pdo->query("SELECT r.*,
    (SELECT COUNT(*) FROM retur_detail d WHERE d.retur_header_id=r.id) AS item_count
    FROM retur_header r WHERE r.komplain_id IS NULL ORDER BY r.id DESC")->fetchAll();

$edit = null; $editItems = [];
if ($act === 'form' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM komplain_header WHERE id=?');
    $stmt->execute([$id]); $edit = $stmt->fetch();
    if ($edit) {
        $stmt = $pdo->prepare('SELECT * FROM komplain_detail WHERE komplain_header_id=? ORDER BY id');
        $stmt->execute([$id]); $editItems = $stmt->fetchAll();
    }
}

$statusSekarang = status_filter();
$tabsStatus = status_tabs_dari(array_merge($list, $returMandiri, $returTerkait), $statusSekarang);
$list = status_pisah($list, $statusSekarang);
$returMandiri = status_pisah($returMandiri, $statusSekarang);
$returTerkait = status_pisah($returTerkait, $statusSekarang);

$pageTitle = 'Komplain Mutu';
require __DIR__ . '/header.php';
?>

<?php if ($act === 'form'): ?>
<section>
  <div class="page-heading">
    <div><h1><?= $edit ? 'Edit Komplain' : 'Input Komplain Mutu' ?></h1><div class="page-subtitle">Marketing &mdash; PT. MCP</div></div>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>komplain.php">&larr; Kembali</a>
  </div>
  <?= audit_card($edit ?: null, 'Audit Data Komplain') ?>
    <form method="post" enctype="multipart/form-data" action=""<?= form_readonly_attr($edit['status'] ?? '') ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

    <div class="card card-mcp">
      <div class="card-header">Data Komplain</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-6"><label class="form-label">No. Surat Jalan (SJ)</label>
            <input class="form-control" name="no_sj" placeholder="isi '-' jika tidak ada no.SJ" value="<?= e($edit['no_sj'] ?? '') ?>">
            <div class="field-hint">Kosong boleh — akan disimpan sebagai &ldquo;-&rdquo;.</div></div>
          <div class="col-12 col-md-6"><label class="form-label">Customer <span class="text-danger">*</span></label>
            <input class="form-control" name="customer" required value="<?= e($edit['customer'] ?? '') ?>"></div>
          <div class="col-12 col-md-6"><label class="form-label">Tanggal Komplain</label>
            <input type="date" class="form-control" name="tgl_komplain" value="<?= e($edit['tgl_komplain'] ?? date('Y-m-d')) ?>"></div>
          <div class="col-12"><label class="form-label">Jenis Penanganan <span class="text-danger">*</span></label>
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <div class="form-check form-check-card border rounded">
                  <input class="form-check-input" type="radio" name="jenis" id="jenis_saja" value="komplain_saja"
                         required <?= (($edit['jenis'] ?? 'komplain_saja') === 'komplain_saja') ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="jenis_saja">Komplain saja, barang tetap diterima customer (langsung CLOSE)</label>
                </div>
              </div>
              <div class="col-12 col-md-6">
                <div class="form-check form-check-card border rounded">
                  <input class="form-check-input" type="radio" name="jenis" id="jenis_retur" value="retur"
                         <?= (($edit['jenis'] ?? '') === 'retur') ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="jenis_retur">Komplain + barang RETUR (diteruskan ke Admin Produksi)</label>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card card-mcp">
      <div class="card-header">Item yang Dikeluhkan</div>
      <div class="card-body">
        <div class="table-responsive-mcp"><table class="table table-mcp" id="itemTable" style="min-width:780px">
          <thead><tr><th>Ukuran</th><th>Jumlah (KG)</th><th>Jumlah</th><th>Satuan</th><th>Keterangan</th><th></th></tr></thead>
          <tbody>
            <?php $items = $editItems ?: [['ukuran'=>'','jumlah'=>'','jumlah_kg'=>'','satuan'=>'roll','keterangan'=>'']]; ?>
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
                      <?php foreach ($satuanList as $s): ?><option value="<?= $s ?>" <?= (($it['satuan'] ?? 'roll') === $s) ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                    </select></td>
                <td><input class="form-control form-control-sm" name="keterangan_item[]" value="<?= e($it['keterangan'] ?? '') ?>"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger hapus-item">&times;</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="tambahItem">+ Tambah Item</button>
      </div>
    </div>

    <div class="card card-mcp">
      <div class="card-header">Bukti &amp; Penyelesaian</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label">Upload JPG Bukti Keluhan Customer</label>
            <input type="file" class="form-control" name="bukti_keluhan" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf">
            <?php if (!empty($edit['bukti_keluhan'])): ?>
              <a class="d-inline-block mt-2 small" target="_blank" rel="noopener"
                 href="<?= BASE_URL ?>uploads/komplain/<?= e($edit['bukti_keluhan']) ?>">Lihat bukti saat ini &nearr;</a>
            <?php endif; ?>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Bukti Penyelesaian (opsional, komplain tanpa retur)</label>
            <input type="file" class="form-control" name="bukti_selesai" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf">
            <?php if (!empty($edit['bukti_selesai'])): ?>
              <a class="d-inline-block mt-2 small" target="_blank" rel="noopener"
                 href="<?= BASE_URL ?>uploads/komplain/<?= e($edit['bukti_selesai']) ?>">Lihat bukti selesai &nearr;</a>
            <?php endif; ?>
          </div>
          <div class="col-12">
            <label class="form-label">Keterangan Penyelesaian</label>
            <textarea class="form-control" name="keterangan_selesai" rows="2"><?= e($edit['keterangan_selesai'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Kirim Komplain</button>
  </form>
</section>
<?php else: ?>
<section>
  <div class="page-heading">
    <div><h1>Komplain Mutu</h1><div class="page-subtitle">Keluhan customer &amp; retur barang</div></div>
    <a class="btn btn-primary" href="<?= BASE_URL ?>komplain.php?act=form">+ Input Komplain</a>
  </div>

  <ul class="nav nav-tabs flex-nowrap overflow-auto" role="tablist">
    <li class="nav-item"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#tabKomplain">Komplain Mutu <span class="badge bg-secondary"><?= count($list) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tabKonfirmasi">Konfirmasi Retur Satpam <span class="badge bg-warning text-dark"><?= count($returMandiri) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tabInfo">Info Keluhan Mutu <span class="badge bg-info text-dark"><?= count($returTerkait) ?></span></button></li>
  </ul>

  <div class="tab-content pt-3">
  <?= $tabsStatus ?>
  <div class="tab-pane fade" id="tabKonfirmasi">
  <div class="card card-mcp border-warning">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span>Barang Kembalian Input Mandiri Satpam <span class="badge bg-warning text-dark"><?= count($returMandiri) ?></span></span>
      <span class="small text-mute">Konfirmasi bahwa Anda mengetahui barang ini kembali dari customer</span>
    </div>
    <div class="card-body p-0">
      <?php if (!$returMandiri): ?><div class="empty-state">Tidak ada barang kembalian yang diinput mandiri oleh satpam.</div>
      <?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari no. retur / SJ / customer..." class="table table-mcp mb-0" style="min-width:1000px">
          <thead><tr><th>No. Retur</th><th>No. SJ</th><th>Nopol</th><th>Sopir</th><th>Customer</th><th>Alasan</th><th> Jml Item</th><th>Konfirmasi Marketing</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach ($returMandiri as $r): ?>
              <tr>
                <td><?= e($r['no_retur']) ?></td><td><?= e($r['no_sj']) ?></td><td><?= e($r['nopol']) ?></td>
                <td><?= e($r['nama_sopir']) ?></td><td><?= e($r['customer']) ?></td>
                <td><?= alasan_badge($r['alasan']) ?></td><td><?= e(ringkasan_item('retur_detail', 'retur_header_id', (int)$r['id'])) ?></td>
                <td class="small">
                  <?php if ($r['marketing_confirm'] === 'yes'): ?>
                    <span class="badge bg-success text-white">confirm by <?= e(user_username($r['marketing_confirm_by'])) ?></span><br>
                    <span class="text-mute"><?= e(format_waktu($r['marketing_confirm_at'])) ?></span>
                  <?php elseif ($r['marketing_confirm'] === 'no'): ?>
                    <span class="badge bg-danger">Ditolak marketing</span><?php if ($r['marketing_confirm_by']): ?> oleh <?= user_label($r['marketing_confirm_by']) ?><?php endif; ?>
                  <?php else: ?>
                    <span class="badge bg-secondary">Menunggu konfirmasi</span>
                  <?php endif; ?>
                </td>
                <td class="action-col">
                  <form method="post" action="" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="confirm_retur">
                    <input type="hidden" name="retur_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" name="jawaban" value="yes" class="btn btn-sm <?= $r['marketing_confirm'] === 'yes' ? 'btn-success' : 'btn-outline-success' ?>">Yes</button>
                    <button type="submit" name="jawaban" value="no" class="btn btn-sm <?= $r['marketing_confirm'] === 'no' ? 'btn-danger' : 'btn-outline-danger' ?>">No</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
  </div>

  <div class="tab-pane fade" id="tabInfo">
  <div class="card card-mcp">
    <div class="card-header">Barang Kembalian Satpam dengan Alasan Keluhan Mutu &mdash; Info Bersama</div>
    <div class="card-body p-0">
      <?php if (!$returTerkait): ?><div class="empty-state">Belum ada retur keluhan mutu dari satpam.</div>
      <?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:700px">
          <thead><tr><th>No. Retur</th><th>No. SJ</th><th>Customer</th><th>Tgl Kembali</th><th>Alasan</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($returTerkait as $r): ?><tr>
            <td><?= e($r['no_retur']) ?></td><td><?= e($r['no_sj']) ?></td><td><?= e($r['customer']) ?></td>
            <td><?= e(format_tanggal($r['tgl_kembali'])) ?></td><td><?= alasan_badge($r['alasan']) ?></td>
            <td><?= status_tiket($r['status']) ?></td>
          </tr><?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
  </div>

  <div class="tab-pane fade show active" id="tabKomplain">
  <div class="card card-mcp">
    <div class="card-header">Laporan Komplain</div>
    <div class="card-body p-0">
      <?php if (!$list): ?><div class="empty-state">Belum ada komplain.</div>
      <?php else: ?>
        <div class="table-responsive-mcp"><table data-scalable data-search-placeholder="Cari data..." class="table table-mcp mb-0" style="min-width:760px">
          <thead><tr><th>No. Komplain</th><th>No. SJ</th><th>Customer</th><th>Jenis</th><th>Jml Item</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach ($list as $k): ?>
              <tr>
                <td><?= e($k['no_komplain']) ?></td><td><?= e($k['no_sj']) ?></td><td><?= e($k['customer']) ?></td>
                <td><?= $k['jenis'] === 'retur' ? '<span class="badge bg-danger">Retur</span>' : 'Komplain saja' ?></td>
                <td><?= e(ringkasan_item('komplain_detail', 'komplain_header_id', (int)$k['id'])) ?></td><td><?= status_tiket($k['status']) ?></td>
                <td class="action-col">
                  <?php if (status_is_open($k['status'])): ?>
                  <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>komplain.php?act=form&id=<?= (int)$k['id'] ?>">Edit</a>
                  <form method="post" action="" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" data-confirm="Hapus komplain ini?" type="submit">Delete</button>
                  </form>
                  <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>komplain.php?act=form&id=<?= (int)$k['id'] ?>">Lihat</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
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
