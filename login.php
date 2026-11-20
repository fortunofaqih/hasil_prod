<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $username = post('username');
        $password = post('password', '');
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($password, $account['password'])) {
            $error = 'Username atau kata sandi salah.';
        } elseif (!(bool) $account['is_active']) {
            $error = 'Akun ini dinonaktifkan. Hubungi administrator.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'    => (int) $account['id'],
                'nama'  => $account['nama'],
                'role'  => $account['role'],
                'username' => $account['username'],
            ];
            set_flash('Selamat datang, ' . $account['nama'] . '.');
            redirect('dashboard.php');
        }
    }
}

$pageTitle = 'Masuk';
require __DIR__ . '/header.php';
?>
<section class="login-wrap">
  <div class="card card-mcp login-card">
    <div class="text-center mb-4">
      <img src="<?= BASE_URL ?>img/logo_mcp.png" alt="Logo PT. MCP" class="login-logo">
      <h1 class="mt-2 mb-1">PT. MCP</h1>
      <p class="text-mute mb-0 small">Sistem Pemantauan Ambil Hasil Produksi</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" class="form-control" id="username" name="username" required autofocus
               value="<?= e(post('username')) ?>">
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Kata Sandi</label>
        <input type="password" class="form-control" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">Masuk</button>
    </form>

    <div class="mt-4 pt-3 border-top small text-mute">
      <strong>Akun demo:</strong><br>
      admin / admin123 &middot; marketing / marketing123<br>
      ppic / ppic123 &middot; qc / qc123<br>
      gudang / gudang123 &middot; manager / manager123
    </div>
  </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
