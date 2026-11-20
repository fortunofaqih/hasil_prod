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
