<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';

$user = require_login();

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $lama = post('password_lama', '');
        $baru = post('password_baru', '');
        $ulang = post('konfirmasi_password', '');

        if ($lama === '' || $baru === '' || $ulang === '') {
            $error = 'Lengkapi semua field.';
        } elseif (strlen($baru) < 6) {
            $error = 'Password baru minimal 6 karakter.';
        } elseif ($baru !== $ulang) {
            $error = 'Konfirmasi password tidak cocok.';
        } else {
            $stmt = db()->prepare('SELECT password FROM users WHERE id=? LIMIT 1');
            $stmt->execute([user_id()]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($lama, $hash)) {
                $error = 'Password lama tidak sesuai.';
            } elseif (password_verify($baru, $hash)) {
                $error = 'Password baru harus berbeda dari password lama.';
            } else {
                $stmt = db()->prepare('UPDATE users SET password=?, modified_by=?, modified_at=? WHERE id=?');
                $stmt->execute([
                    password_hash($baru, PASSWORD_DEFAULT),
                    user_id(),
                    date('Y-m-d H:i:s'),
                    user_id(),
                ]);
                $success = true;
                set_flash('Password berhasil diubah. Gunakan password baru untuk login berikutnya.');
            }
        }
    }
}

$pageTitle = 'Ganti Password';
require __DIR__ . '/header.php';
?>
<section class="login-wrap">
  <div class="card card-mcp login-card">
    <div class="mb-4">
      <h1>Ganti Password</h1>
      <p class="text-mute small mb-0">Ubah kata sandi akun <?= e($user['username']) ?> (<?= e($user['nama']) ?>).</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success" role="alert">Password Anda sudah diperbarui.</div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="mb-3">
        <label for="password_lama" class="form-label">Password Lama</label>
        <input type="password" class="form-control" id="password_lama" name="password_lama" required autofocus autocomplete="current-password">
      </div>
      <div class="mb-3">
        <label for="password_baru" class="form-label">Password Baru</label>
        <input type="password" class="form-control" id="password_baru" name="password_baru" minlength="6" required autocomplete="new-password">
        <div class="field-hint">Minimal 6 karakter.</div>
      </div>
      <div class="mb-3">
        <label for="konfirmasi_password" class="form-label">Ulangi Password Baru</label>
        <input type="password" class="form-control" id="konfirmasi_password" name="konfirmasi_password" minlength="6" required autocomplete="new-password">
      </div>
      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary">Simpan Password Baru</button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>dashboard.php">Kembali ke Dashboard</a>
      </div>
    </form>
  </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
