<?php
declare(strict_types=1);
require_once __DIR__ . '/config/functions.php';
$user = require_login(['admin']);

$pdo = db();
$tab = $_GET['tab'] ?? 'user';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $action = post('action');

    if ($action === 'save_user') {
        $username = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', post('username')));
        $password = post('password');
        $nama = post('nama');
        $role = post('role');
        $email = post('email');
        $rolesAllowed = ['admin', 'marketing', 'ppic', 'qc', 'gudang', 'manager'];

        if ($username === '' || strlen($password) < 6 || $nama === '' || !in_array($role, $rolesAllowed, true)) {
            set_flash('Username, password minimal 6 karakter, nama, dan role wajib diisi.', 'danger');
        } else {
            $cek = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username=?');
            $cek->execute([$username]);
            if ($cek->fetchColumn()) {
                set_flash('Username sudah digunakan.', 'danger');
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (username, password, nama, role, email, is_active, created_by, modified_by, created_at, modified_at)
                                       VALUES (?,?,?,?,?,1,?,?,NOW(),NOW())');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $nama, $role, $email, user_id(), user_id()]);
                set_flash('User ' . $username . ' berhasil ditambahkan.');
            }
        }
        redirect('admin.php?tab=user');
    }

    if ($action === 'toggle_user') {
        $uid = (int) post('id');
        if ($uid !== user_id()) {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active, modified_by=?, modified_at=? WHERE id=?')
                ->execute([user_id(), date('Y-m-d H:i:s'), $uid]);
            set_flash('Status user berhasil diubah.');
        } else {
            set_flash('Tidak dapat menonaktifkan akun sendiri.', 'danger');
        }
        redirect('admin.php?tab=user');
    }

    if ($action === 'save_menu') {
        $key = strtolower(preg_replace('/[^a-z0-9_]/', '', post('menu_key')));
        $nama = post('nama');
        $route = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', post('route'));
        $icon = post('icon') ?: 'bi-circle';
        if (preg_match('/^bi-[a-z0-9-]+$/', $icon) !== 1) {
            $icon = 'bi-circle';
        }
        $urut = (int) post('urut');
        if ($key === '' || $nama === '' || $route === '') {
            set_flash('Menu key, nama, dan route wajib diisi.', 'danger');
        } else {
            $cek = $pdo->prepare('SELECT COUNT(*) FROM menus WHERE menu_key=?');
            $cek->execute([$key]);
            if ($cek->fetchColumn()) {
                set_flash('Menu key sudah ada.', 'danger');
            } else {
                $pdo->prepare('INSERT INTO menus (menu_key, nama, route, icon, urut, is_active, created_by, modified_by, created_at, modified_at)
                               VALUES (?,?,?,?,?,1,?,?,NOW(),NOW())')
                    ->execute([$key, $nama, $route, $icon, $urut, user_id(), user_id()]);
                set_flash('Menu berhasil ditambahkan. Atur hak akses di tab Hak Akses.');
            }
        }
        redirect('admin.php?tab=menu');
    }

    if ($action === 'toggle_menu') {
        $mid = (int) post('id');
        $pdo->prepare('UPDATE menus SET is_active = 1 - is_active, modified_by=?, modified_at=? WHERE id=?')
            ->execute([user_id(), date('Y-m-d H:i:s'), $mid]);
        set_flash('Status menu berhasil diubah.');
        redirect('admin.php?tab=menu');
    }

    if ($action === 'save_access') {
        $role = post('role');
        $menuId = (int) post('menu_id');
        $aktif = isset($_POST['is_active']) ? 1 : 0;
        $cek = $pdo->prepare('SELECT id FROM role_menus WHERE role=? AND menu_id=?');
        $cek->execute([$role, $menuId]);
        $existId = $cek->fetchColumn();
        if ($existId) {
            $pdo->prepare('UPDATE role_menus SET is_active=?, modified_by=?, modified_at=? WHERE id=?')
                ->execute([$aktif, user_id(), date('Y-m-d H:i:s'), $existId]);
        } else {
            $pdo->prepare('INSERT INTO role_menus (role, menu_id, is_active, created_by, modified_by, created_at, modified_at)
                           VALUES (?,?,?,?,?,NOW(),NOW())')
                ->execute([$role, $menuId, $aktif, user_id(), user_id()]);
        }
        set_flash('Hak akses diperbarui.');
        redirect('admin.php?tab=akses&role=' . urlencode($role));
    }
}

$users = $pdo->query('SELECT id, username, nama, role, email, is_active, created_at FROM users ORDER BY id')->fetchAll();
$adminMenus = $pdo->query('SELECT * FROM menus ORDER BY urut, id')->fetchAll();
$roles = ['admin', 'marketing', 'ppic', 'qc', 'gudang', 'manager'];
$roleParam = $_GET['role'] ?? 'admin';
$selectedRole = in_array($roleParam, $roles, true) ? $roleParam : 'admin';
$stmt = $pdo->prepare('SELECT m.id, m.nama, m.route, rm.is_active FROM menus m
    LEFT JOIN role_menus rm ON rm.menu_id=m.id AND rm.role=? ORDER BY m.urut, m.id');
$stmt->execute([$selectedRole]);
$akses = $stmt->fetchAll();

$pageTitle = 'Administrasi';
require __DIR__ . '/header.php';
?>
<section>
  <div class="page-heading"><div><h1>Administrasi</h1><div class="page-subtitle">Kelola user, menu, dan hak akses</div></div></div>

  <ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'user' ? 'active' : '' ?>" href="<?= BASE_URL ?>admin.php?tab=user">User</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'menu' ? 'active' : '' ?>" href="<?= BASE_URL ?>admin.php?tab=menu">Menu</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'akses' ? 'active' : '' ?>" href="<?= BASE_URL ?>admin.php?tab=akses">Hak Akses</a></li>
  </ul>

  <?php if ($tab === 'user'): ?>
    <div class="row g-3">
      <div class="col-12 col-lg-5">
        <div class="card card-mcp">
          <div class="card-header">Tambah User</div>
          <div class="card-body">
            <form method="post" action="">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="save_user">
              <div class="mb-2"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
              <div class="mb-2"><label class="form-label">Nama</label><input class="form-control" name="nama" required></div>
              <div class="mb-2"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div>
              <div class="mb-2"><label class="form-label">Role</label>
                <select class="form-select" name="role"><?php foreach ($roles as $r): ?><option value="<?= $r ?>"><?= ucfirst($r) ?></option><?php endforeach; ?></select>
              </div>
              <div class="mb-3"><label class="form-label">Kata Sandi</label><input type="password" class="form-control" name="password" minlength="6" required></div>
              <button class="btn btn-primary w-100" type="submit">Tambah User</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-7">
        <div class="card card-mcp">
          <div class="card-header">Daftar User</div>
          <div class="card-body p-0">
            <div class="table-responsive-mcp">
              <table class="table table-mcp mb-0" style="min-width:560px">
                <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                  <?php foreach ($users as $u): ?>
                    <tr>
                      <td><strong><?= e($u['nama']) ?></strong><br><small class="text-mute">@<?= e($u['username']) ?></small></td>
                      <td><?= e(strtoupper($u['role'])) ?></td>
                      <td><span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                      <td>
                        <form method="post" action="">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="toggle_user">
                          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                          <button class="btn btn-sm btn-outline-warning" data-confirm="Ubah status user ini?" type="submit">
                            <?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'menu'): ?>
    <div class="row g-3">
      <div class="col-12 col-lg-5">
        <div class="card card-mcp">
          <div class="card-header">Tambah Menu</div>
          <div class="card-body">
            <form method="post" action="">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="save_menu">
              <div class="mb-2"><label class="form-label">Menu key</label><input class="form-control" name="menu_key" placeholder="contoh: laporan" required></div>
              <div class="mb-2"><label class="form-label">Nama</label><input class="form-control" name="nama" required></div>
              <div class="mb-2"><label class="form-label">Route (file)</label><input class="form-control" name="route" placeholder="laporan.php" required></div>
              <div class="mb-2"><label class="form-label">Ikon (nama kelas Bootstrap Icons)</label>
                <input class="form-control" name="icon" placeholder="bi-circle">
                <div class="field-hint">Contoh: bi-house-door, bi-file-earmark-plus, bi-gear, bi-check-circle, bi-box-seam, bi-clipboard-check, bi-shield-lock</div>
              </div>
              <div class="mb-3"><label class="form-label">Urutan</label><input type="number" class="form-control" name="urut" value="9"></div>
              <button class="btn btn-primary w-100" type="submit">Tambah Menu</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-7">
        <div class="card card-mcp">
          <div class="card-header">Daftar Menu</div>
          <div class="card-body p-0">
            <div class="table-responsive-mcp">
              <table class="table table-mcp mb-0">
                <thead><tr><th>Menu</th><th>Route</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                  <?php foreach ($adminMenus as $m): ?>
                    <tr>
                      <td><i class="bi <?= e(preg_match('/^bi-[a-z0-9-]+$/', $m['icon']) === 1 ? $m['icon'] : 'bi-circle') ?>"></i> <?= e($m['nama']) ?></td>
                      <td><?= e($m['route']) ?></td>
                      <td><span class="badge <?= $m['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $m['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                      <td>
                        <form method="post" action="">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="toggle_menu">
                          <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                          <button class="btn btn-sm btn-outline-warning" type="submit">Ubah Status</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

  <?php else: ?>
    <div class="card card-mcp">
      <div class="card-header">Hak Akses Menu per Role</div>
      <div class="card-body">
        <ul class="nav nav-pills mb-3">
          <?php foreach ($roles as $r): ?>
            <li class="nav-item"><a class="nav-link <?= $r === $selectedRole ? 'active' : '' ?>"
              href="<?= BASE_URL ?>admin.php?tab=akses&role=<?= $r ?>"><?= ucfirst($r) ?></a></li>
          <?php endforeach; ?>
        </ul>
        <div class="table-responsive-mcp">
          <table class="table table-mcp mb-0" style="min-width:520px">
            <thead><tr><th>Menu</th><th>Route</th><th>Akses</th></tr></thead>
            <tbody>
              <?php foreach ($akses as $a): ?>
                <tr>
                  <td><?= e($a['nama']) ?></td>
                  <td><?= e($a['route']) ?></td>
                  <td>
                    <form method="post" action="" class="d-flex align-items-center gap-2">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="save_access">
                      <input type="hidden" name="role" value="<?= e($selectedRole) ?>">
                      <input type="hidden" name="menu_id" value="<?= (int)$a['id'] ?>">
                      <input class="form-check-input" type="checkbox" name="is_active" id="akses<?= (int)$a['id'] ?>"
                             <?= $a['is_active'] ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="akses<?= (int)$a['id'] ?>">Diizinkan</label>
                      <button class="btn btn-sm btn-outline-primary" type="submit">Simpan</button>
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
</section>
<?php require __DIR__ . '/footer.php'; ?>
