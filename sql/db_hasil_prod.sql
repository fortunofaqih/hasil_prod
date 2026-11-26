-- ============================================================
-- PT. MCP - Sistem Pemantauan Ambil Hasil Produksi
-- FILE DEPLOYMENT : db_hasil_prod.sql
--
-- Database       : db_hasil_prod
-- Berisi         : struktur 15 tabel + 6 user default + 7 menu + hak akses
-- TIDAK berisi   : data DO/SPK demo (semua tabel transaksi kosong)
--
-- Cara import di server:
--   mysql -u root -p < db_hasil_prod.sql
--   lalu ubah DB_HOST / DB_USER / DB_PASS / DB_NAME di config/koneksi.php
--   DB_NAME = 'db_hasil_prod'
--
-- AKUN DEFAULT (hash password sudah tersimpan di tabel users):
--   admin     / admin123      -> Administrator
--   marketing / marketing123  -> Marketing (User A)
--   ppic      / ppic123       -> PPIC / produksi
--   qc        / qc123         -> Quality Control
--   gudang    / gudang123     -> Gudang
--   manager   / manager123    -> Manager
--
-- Segera ganti password masing-masing lewat tombol Ganti Password.
-- ============================================================

-- ============================================================
-- PT. MCP - Aplikasi Pemantauan Ambil Hasil Produksi
-- Database: db_hasil_prod
-- Setiap tabel memiliki kolom audit:
--   created_by, modified_by, created_at, modified_at
-- ============================================================

CREATE DATABASE IF NOT EXISTS `db_hasil_prod`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `db_hasil_prod`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS tracking_detail;
DROP TABLE IF EXISTS tracking_header;
DROP TABLE IF EXISTS manager_detail;
DROP TABLE IF EXISTS manager_header;
DROP TABLE IF EXISTS gudang_detail;
DROP TABLE IF EXISTS gudang_header;
DROP TABLE IF EXISTS qc_detail;
DROP TABLE IF EXISTS qc_header;
DROP TABLE IF EXISTS produksi_detail;
DROP TABLE IF EXISTS produksi_header;
DROP TABLE IF EXISTS do_detail;
DROP TABLE IF EXISTS do_header;
DROP TABLE IF EXISTS role_menus;
DROP TABLE IF EXISTS menus;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 1. USERS
-- ------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50) NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL,
  nama          VARCHAR(100) NOT NULL,
  role          ENUM('admin','marketing','ppic','qc','gudang','manager') NOT NULL,
  email         VARCHAR(100) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  modified_by   INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at   DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. MENUS & ROLE_MENUS
-- ------------------------------------------------------------
CREATE TABLE menus (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_key      VARCHAR(50) NOT NULL UNIQUE,
  nama          VARCHAR(100) NOT NULL,
  route         VARCHAR(150) NOT NULL,
  icon          VARCHAR(50) NULL,
  urut          INT NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  modified_by   INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at   DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_menus (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role          VARCHAR(20) NOT NULL,
  menu_id       INT UNSIGNED NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  modified_by   INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at   DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_role_menu (role, menu_id),
  CONSTRAINT fk_rolemenus_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. DO (DELIVERY ORDER) - HEADER & DETAIL  [Input Marketing]
-- ------------------------------------------------------------
CREATE TABLE do_header (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no_do           VARCHAR(50) NOT NULL UNIQUE,
  no_spk          VARCHAR(50) NOT NULL,
  tgl_order       DATE NULL,
  tgl_ambil       DATE NOT NULL,
  customer        VARCHAR(150) NOT NULL,
  category        VARCHAR(100) NOT NULL,
  ukuran          VARCHAR(50) NULL,
  jumlah          DECIMAL(14,2) NOT NULL,
  satuan          VARCHAR(20) NOT NULL,
  kategori_kirim  ENUM('Kirim','Las') NOT NULL DEFAULT 'Kirim',
  file_do         VARCHAR(255) NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'ppic',
  returned_to     VARCHAR(20) NULL,
  catatan_koreksi TEXT NULL,
  created_by      INT UNSIGNED NULL,
  modified_by     INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at     DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_doh_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE do_detail (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  do_header_id  INT UNSIGNED NOT NULL,
  ukuran        VARCHAR(50) NOT NULL,
  jumlah        DECIMAL(14,2) NOT NULL,
  satuan        VARCHAR(20) NOT NULL,
  created_by    INT UNSIGNED NULL,
  modified_by   INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at   DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dod_header FOREIGN KEY (do_header_id) REFERENCES do_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. PRODUKSI / PPIC - HEADER & DETAIL
-- ------------------------------------------------------------
CREATE TABLE produksi_header (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  do_header_id        INT UNSIGNED NOT NULL,
  no_spk              VARCHAR(50) NOT NULL,
  jenis_proses        ENUM('roll','potong') NOT NULL DEFAULT 'roll',
  tgl_rencana_roll    DATE NULL,
  tgl_realisasi_roll  DATE NULL,
  tgl_rencana_potong  DATE NULL,
  tgl_realisasi_potong DATE NULL,
  status              VARCHAR(20) NOT NULL DEFAULT 'qc',
  catatan             TEXT NULL,
  created_by          INT UNSIGNED NULL,
  modified_by         INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at         DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_proh_do FOREIGN KEY (do_header_id) REFERENCES do_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produksi_detail (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produksi_header_id INT UNSIGNED NOT NULL,
  do_detail_id      INT UNSIGNED NULL,
  ukuran            VARCHAR(50) NOT NULL,
  jumlah            DECIMAL(14,2) NOT NULL,
  satuan            VARCHAR(20) NOT NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_header FOREIGN KEY (produksi_header_id) REFERENCES produksi_header(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_dod FOREIGN KEY (do_detail_id) REFERENCES do_detail(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. QC - HEADER & DETAIL
-- ------------------------------------------------------------
CREATE TABLE qc_header (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produksi_header_id  INT UNSIGNED NOT NULL,
  no_spk              VARCHAR(50) NOT NULL,
  tgl_verifikasi      DATE NOT NULL,
  jumlah_lolos        DECIMAL(14,2) NOT NULL DEFAULT 0,
  status              VARCHAR(20) NOT NULL DEFAULT 'gudang',
  catatan             TEXT NULL,
  created_by          INT UNSIGNED NULL,
  modified_by         INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at         DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_qch_pro FOREIGN KEY (produksi_header_id) REFERENCES produksi_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qc_detail (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  qc_header_id      INT UNSIGNED NOT NULL,
  produksi_detail_id INT UNSIGNED NULL,
  jumlah_lolos      DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_qcd_qch FOREIGN KEY (qc_header_id) REFERENCES qc_header(id) ON DELETE CASCADE,
  CONSTRAINT fk_qcd_prod FOREIGN KEY (produksi_detail_id) REFERENCES produksi_detail(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. GUDANG - HEADER & DETAIL
-- ------------------------------------------------------------
CREATE TABLE gudang_header (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  qc_header_id      INT UNSIGNED NOT NULL,
  no_spk            VARCHAR(50) NOT NULL,
  tgl_verifikasi    DATE NOT NULL,
  jumlah_siap       DECIMAL(14,2) NOT NULL DEFAULT 0,
  status            VARCHAR(20) NOT NULL DEFAULT 'manager',
  released_at       DATETIME NULL,
  catatan           TEXT NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gh_qc FOREIGN KEY (qc_header_id) REFERENCES qc_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gudang_detail (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gudang_header_id  INT UNSIGNED NOT NULL,
  qc_detail_id      INT UNSIGNED NULL,
  jumlah_siap       DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gd_gh FOREIGN KEY (gudang_header_id) REFERENCES gudang_header(id) ON DELETE CASCADE,
  CONSTRAINT fk_gd_qcd FOREIGN KEY (qc_detail_id) REFERENCES qc_detail(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. MANAGER - HEADER & DETAIL (verifikasi / pengembalian)
-- ------------------------------------------------------------
CREATE TABLE manager_header (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  do_header_id      INT UNSIGNED NOT NULL,
  no_spk            VARCHAR(50) NOT NULL,
  tgl_verifikasi    DATE NOT NULL,
  status            VARCHAR(20) NOT NULL DEFAULT 'menunggu',
  catatan           TEXT NULL,
  released_at       DATETIME NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mh_do FOREIGN KEY (do_header_id) REFERENCES do_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE manager_detail (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  manager_header_id INT UNSIGNED NOT NULL,
  stage             VARCHAR(20) NOT NULL,
  status            VARCHAR(20) NOT NULL,
  catatan           TEXT NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_md_mh FOREIGN KEY (manager_header_id) REFERENCES manager_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. TRACKING - HEADER & DETAIL (history seluruh alur)
-- ------------------------------------------------------------
CREATE TABLE tracking_header (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  do_header_id    INT UNSIGNED NOT NULL,
  status          VARCHAR(20) NOT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_by     INT UNSIGNED NULL,
  modified_at     DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_th_do FOREIGN KEY (do_header_id) REFERENCES do_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tracking_detail (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_header_id  INT UNSIGNED NOT NULL,
  stage               VARCHAR(20) NOT NULL,
  keterangan          TEXT NULL,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_by         INT UNSIGNED NULL,
  modified_at         DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_td_th FOREIGN KEY (tracking_header_id) REFERENCES tracking_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATA AWAL
-- ============================================================

INSERT INTO users (id, username, password, nama, role, email, is_active, created_at) VALUES
 (1, 'admin',     '$2y$10$FGebVC9NN9/PVxlpKxhWF.ievJ16Ko4IDKi8fXMxyExyruQMxFqQW', 'Administrator',        'admin',     'admin@mcp.co.id',     1, NOW()),
 (2, 'marketing', '$2y$10$YOwYlgTRv4rt2tteLszZSeFClR.pRNZJ6IjjkTW24s.JY/dJcApk.', 'User A - Marketing',   'marketing', 'marketing@mcp.co.id', 1, NOW()),
 (3, 'ppic',      '$2y$10$dd0vFzG4HMTIcit3uB4vfu2TNuGKxFmxiTMZXbgeoTm1VS/AibPbm', 'Staff PPIC',           'ppic',      'ppic@mcp.co.id',      1, NOW()),
 (4, 'qc',        '$2y$10$kfFebFk27RHF03CmqnLiwOFZ4tnxiJmFlBkXPI/2pU.hPIdMmYd8a', 'Staff QC',             'qc',        'qc@mcp.co.id',        1, NOW()),
 (5, 'gudang',    '$2y$10$tdpY9IdZWD.nYLloVYrwa.iX/DfUF.9EbJyEl7ipnEwkGICVgFN22', 'Admin Gudang',         'gudang',    'gudang@mcp.co.id',    1, NOW()),
 (6, 'manager',   '$2y$10$IV./OKbOplIN5KPPwxm/VebwJCIkr2e59GCyJOGUMIHSjoOWF8YmS', 'Manager',              'manager',   'manager@mcp.co.id',   1, NOW());

INSERT INTO menus (id, menu_key, nama, route, icon, urut, is_active, created_at) VALUES
 (1, 'dashboard', 'Dashboard',   'dashboard.php', 'bi-house-door', 1, 1, NOW()),
 (2, 'marketing', 'Input DO',    'marketing.php', 'bi-file-earmark-plus', 2, 1, NOW()),
 (3, 'ppic',      'Produksi',    'ppic.php',      'bi-gear', 3, 1, NOW()),
 (4, 'qc',        'Quality Control', 'qc.php',    'bi-check-circle', 4, 1, NOW()),
 (5, 'gudang',    'Gudang',      'gudang.php',    'bi-box-seam', 5, 1, NOW()),
 (6, 'manager',   'Manager',     'manager.php',   'bi-clipboard-check', 6, 1, NOW()),
 (7, 'admin',     'Administrasi','admin.php',     'bi-shield-lock', 7, 1, NOW());

INSERT INTO role_menus (role, menu_id, is_active, created_at)
SELECT r.role, m.id, 1, NOW()
FROM (SELECT 'admin' AS role UNION ALL SELECT 'marketing' UNION ALL SELECT 'ppic'
      UNION ALL SELECT 'qc' UNION ALL SELECT 'gudang' UNION ALL SELECT 'manager') r
CROSS JOIN menus m
WHERE r.role = 'admin';

INSERT INTO role_menus (role, menu_id, is_active, created_at)
SELECT 'marketing', id, 1, NOW() FROM menus WHERE id IN (1,2);
INSERT INTO role_menus (role, menu_id, is_active, created_at)
SELECT 'ppic', id, 1, NOW() FROM menus WHERE id IN (1,3);
INSERT INTO role_menus (role, menu_id, is_active, created_at)
SELECT 'qc', id, 1, NOW() FROM menus WHERE id IN (1,4);
INSERT INTO role_menus (role, menu_id, is_active, created_at)
SELECT 'gudang', id, 1, NOW() FROM menus WHERE id IN (1,5);
INSERT INTO role_menus (role, menu_id, is_active, created_at)
SELECT 'manager', id, 1, NOW() FROM menus WHERE id IN (1,6);
