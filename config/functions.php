<?php
declare(strict_types=1);

require_once __DIR__ . '/koneksi.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : BASE_URL . ltrim($path, '/')));
    exit;
}

function set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function get_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(array $roles = []): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        set_flash('Akses ditolak. Hak Anda tidak sesuai halaman ini.', 'danger');
        redirect('dashboard.php');
    }
    return $user;
}

function user_id(): int
{
    return (int) (current_user()['id'] ?? 0);
}

function is_active_menu(string $route): bool
{
    return basename($_SERVER['PHP_SELF'] ?? '') === $route;
}

function audit_values(): array
{
    return ['created_by' => user_id(), 'modified_by' => user_id(), 'created_at' => date('Y-m-d H:i:s'), 'modified_at' => date('Y-m-d H:i:s')];
}

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function status_badge(string $status): string
{
    $map = [
        'ppic'        => ['Menunggu PPIC', 'bg-warning text-dark'],
        'qc'          => ['Menunggu QC', 'bg-info text-dark'],
        'gudang'      => ['Menunggu Gudang', 'bg-primary'],
        'manager'     => ['Menunggu Manager', 'bg-secondary'],
        'siap_ambil'  => ['Siap Diambil', 'bg-success'],
        'selesai'     => ['Selesai - Barang Diambil', 'bg-success'],
        'koreksi'     => ['Perlu Koreksi', 'bg-danger'],
        'gagal'       => ['Gagal QC - Dihentikan', 'bg-dark'],
    ];
    [$label, $class] = $map[$status] ?? [$status, 'bg-light text-dark'];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

function track(int $doId, string $stage, string $keterangan): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO tracking_header (do_header_id, status, created_by, created_at) VALUES (?,?,?,?)');
    $stmt->execute([$doId, $stage, user_id(), date('Y-m-d H:i:s')]);
    $trackingId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO tracking_detail (tracking_header_id, stage, keterangan, created_by, created_at) VALUES (?,?,?,?,?)');
    $stmt->execute([$trackingId, $stage, $keterangan, user_id(), date('Y-m-d H:i:s')]);
}

function set_do_status(int $doId, string $status, ?string $returnedTo = null, ?string $catatan = null): void
{
    $stmt = db()->prepare('UPDATE do_header SET status=?, returned_to=?, catatan_koreksi=?, modified_by=?, modified_at=? WHERE id=?');
    $stmt->execute([$status, $returnedTo, $catatan, user_id(), date('Y-m-d H:i:s'), $doId]);
}

function format_tanggal(?string $date): string
{
    if (!$date || $date === '0000-00-00') {
        return '-';
    }
    return date('d/m/Y', strtotime($date));
}

function format_angka($value): string
{
    return number_format((float) $value, 0, ',', '.');
}

function upload_do(array $file): ?string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    if (($allowed[$ext] ?? '') !== $mime) {
        return null;
    }
    $nama = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DO_DIR . $nama)) {
        return null;
    }
    return $nama;
}

function format_waktu(?string $datetime): string
{
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    return date('d/m/Y H:i', strtotime($datetime));
}

/** Nama petugas + tautan email supaya bisa langsung dihubungi. */
function user_label($userId): string
{
    $id = (int) $userId;
    if ($id <= 0) {
        return '-';
    }
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $stmt = db()->prepare('SELECT nama, email FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $cache[$id] = $stmt->fetch() ?: null;
    }
    $u = $cache[$id];
    if (!$u) {
        return 'User #' . $id;
    }
    $nama = e($u['nama']);
    if (!empty($u['email'])) {
        $email = e($u['email']);
        return '<a href="mailto:' . $email . '">' . $nama . '</a> <small class="text-mute">(' . $email . ')</small>';
    }
    return $nama;
}

/** Hentikan aksi jika data sudah CLOSE. */
function tolak_jika_closed($status, string $redirect): void
{
    if (!status_is_open($status ?? '')) {
        set_flash('Data sudah CLOSE dan tidak bisa diubah.', 'danger');
        redirect($redirect);
    }
}

/** Atribut form untuk data yang sudah CLOSE (hanya bisa dilihat). */
function form_readonly_attr($status): string
{
    return status_is_open($status ?? '') ? '' : ' data-readonly';
}

/** Username singkat untuk badge konfirmasi (tanpa nama panjang/email). */
function user_username($userId): string
{
    $id = (int) $userId;
    if ($id <= 0) { return '-'; }
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $stmt = db()->prepare('SELECT username FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $cache[$id] = $stmt->fetchColumn();
    }
    return $cache[$id] ? (string) $cache[$id] : '-';
}

/** Kartu audit created_by / modified_by / created_at / modified_at. */
function audit_card(?array $row, string $judul = 'Audit Data'): string
{
    $html = '<div class="card card-mcp"><div class="card-header">' . e($judul) . '</div><div class="card-body">';
    if (!$row) {
        return $html . '<div class="text-mute small">Belum ada data yang tersimpan.</div></div></div>';
    }
    $html .= '<dl class="kv">';
    $html .= '<dt>Dibuat oleh</dt><dd>' . user_label($row['created_by'] ?? null) . '</dd>';
    $html .= '<dt>Dibuat pada</dt><dd>' . e(format_waktu($row['created_at'] ?? null)) . '</dd>';
    $html .= '<dt>Diubah oleh</dt><dd>' . user_label($row['modified_by'] ?? null) . '</dd>';
    $html .= '<dt>Diubah pada</dt><dd>' . e(format_waktu($row['modified_at'] ?? null)) . '</dd>';
    $html .= '</dl></div></div>';
    return $html;
}


/** Status OPEN / CLOSE untuk tiket retur & komplain. */
function status_tiket(string $status): string
{
    return $status === 'closed'
        ? '<span class="badge bg-success">CLOSE</span>'
        : '<span class="badge bg-warning text-dark">OPEN</span>';
}

function alasan_badge(string $alasan): string
{
    $map = [
        'belum terverifikasi' => ['Belum Terverifikasi', 'bg-secondary'],
        'gagal kirim - customer tutup' => ['Gagal Kirim - Customer Tutup', 'bg-danger'],
        'gagal kirim - sisa sales kelilingan' => ['Gagal Kirim - Sisa Sales', 'bg-warning text-dark'],
        'keluhan mutu' => ['Keluhan Mutu', 'bg-info text-dark'],
        'titipan' => ['Titipan', 'bg-primary'],
    ];
    [$label, $class] = $map[$alasan] ?? [$alasan, 'bg-light text-dark'];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

function upload_komplain(array $file): ?string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > MAX_UPLOAD_SIZE) {
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    if (($allowed[$ext] ?? '') !== $mime) {
        return null;
    }
    $nama = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_KOMPLAIN_DIR . $nama)) {
        return null;
    }
    return $nama;
}

function nomor_otomatis(string $prefix, string $tabel, string $kolom): string
{
    $last = db()->query("SELECT MAX(id) FROM $tabel")->fetchColumn();
    return $prefix . '-' . date('ymd') . '-' . str_pad((string) ((int) $last + 1), 4, '0', STR_PAD_LEFT);
}

/** Ringkasan item: "1 Roll" atau "2 Roll, 5 KG" (di-group per satuan). */
function ringkasan_item(string $tabelDetail, string $kolomFk, int $id): string
{
    $whitelist = [
        'retur_detail'    => 'retur_header_id',
        'komplain_detail' => 'komplain_header_id',
    ];
    if (!isset($whitelist[$tabelDetail]) || $whitelist[$tabelDetail] !== $kolomFk) {
        return '-';
    }
    $stmt = db()->prepare("SELECT satuan, SUM(jumlah) AS total FROM $tabelDetail WHERE $kolomFk=? GROUP BY satuan ORDER BY satuan");
    $stmt->execute([$id]);
    $bagian = [];
    foreach ($stmt->fetchAll() as $r) {
        $satuan = (string) $r['satuan'];
        $satuan = in_array(strtolower($satuan), ['kg'], true) ? 'KG' : ucfirst(strtolower($satuan));
        $bagian[] = format_angka($r['total']) . ' ' . $satuan;
    }
    return $bagian ? implode(', ', $bagian) : '-';
}


/** Status yang dianggap CLOSE. */
function status_is_open($nilai): bool
{
    return !in_array(strtolower((string) $nilai), ['closed', 'close', 'selesai'], true);
}

/** Filter status halaman: default open, bisa diganti ?status=close. */
function status_filter(): string
{
    return (($_GET['status'] ?? '') === 'close') ? 'close' : 'open';
}

/** Hitung jumlah baris open/close dari array baris. */
function status_hitung(array $baris, string $kunci = 'status'): array
{
    $buka = 0;
    foreach ($baris as $r) {
        if (status_is_open($r[$kunci] ?? $r['status'] ?? '')) { $buka++; }
    }
    return [$buka, count($baris) - $buka];
}

/** Ambil hanya baris sesuai filter status (default open). */
function status_pisah(array $baris, string $filter, string $kunci = 'status'): array
{
    $hasil = [];
    foreach ($baris as $r) {
        $buka = status_is_open($r[$kunci] ?? $r['status'] ?? '');
        if (($filter === 'open' && $buka) || ($filter === 'close' && !$buka)) { $hasil[] = $r; }
    }
    return $hasil;
}

/** Tab status dari sekumpulan baris. */
function status_tabs_dari(array $baris, string $filter, string $kunci = 'status'): string
{
    [$buka, $tutup] = status_hitung($baris, $kunci);
    return status_tabs_ui($buka, $tutup, $filter);
}

/** Navigasi tab Open/Close untuk daftar berstatus. */
function status_tabs_ui(int $open, int $close, string $current): string
{
    $params = $_GET;
    unset($params['status']);
    $dasar = http_build_query($params);
    $link = static function (string $s) use ($dasar): string {
        return '?' . ($dasar !== '' ? $dasar . '&' : '') . 'status=' . $s;
    };
    $aktifOpen = $current === 'open' ? ' active' : '';
    $aktifClose = $current === 'close' ? ' active' : '';
    return '<ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" role="tablist">'
        . '<li class="nav-item"><a class="nav-link' . $aktifOpen . '" href="' . e($link('open')) . '">'
        . 'Open <span class="badge bg-warning text-dark">' . $open . '</span></a></li>'
        . '<li class="nav-item"><a class="nav-link' . $aktifClose . '" href="' . e($link('close')) . '">'
        . 'Close <span class="badge bg-success">' . $close . '</span></a></li>'
        . '</ul>';
}
