-- ============================================================
-- MIGRASI: Modul Barang Kembalian & Komplain Mutu
-- PT. MCP - Hasil Produksi
--
-- Jalankan pada database yang sudah ada.
-- Baris USE di bawah bisa diganti ke db_hasil_prod untuk server.
-- ============================================================

USE `hasil_prod`;

-- Role baru: satpam dan produksi (admin produksi)
ALTER TABLE users MODIFY role ENUM('admin','marketing','ppic','qc','gudang','manager','satpam','produksi') NOT NULL;

-- ------------------------------------------------------------
-- 1. KOMPLAIN MARKETING - HEADER & DETAIL
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS komplain_header (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no_komplain       VARCHAR(50) NOT NULL UNIQUE,
  no_sj             VARCHAR(50) NOT NULL,
  customer          VARCHAR(150) NOT NULL,
  tgl_komplain      DATE NOT NULL,
  jenis             ENUM('komplain_saja','retur') NOT NULL DEFAULT 'komplain_saja',
  status            ENUM('open','closed') NOT NULL DEFAULT 'open',
  bukti_keluhan     VARCHAR(255) NULL,
  bukti_selesai     VARCHAR(255) NULL,
  keterangan_selesai TEXT NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS komplain_detail (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  komplain_header_id INT UNSIGNED NOT NULL,
  ukuran            VARCHAR(50) NOT NULL,
  jumlah            DECIMAL(14,2) NOT NULL,
  jumlah_kg         DECIMAL(14,2) NOT NULL DEFAULT 0,
  satuan            VARCHAR(20) NOT NULL,
  keterangan        TEXT NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_kd_header FOREIGN KEY (komplain_header_id) REFERENCES komplain_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. BARANG KEMBALIAN (SATPAM) - HEADER & DETAIL
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retur_header (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no_retur          VARCHAR(50) NOT NULL UNIQUE,
  no_sj             VARCHAR(50) NOT NULL,
  nopol             VARCHAR(30) NOT NULL,
  nama_sopir        VARCHAR(100) NOT NULL,
  customer          VARCHAR(150) NOT NULL,
  tgl_kembali       DATE NOT NULL,
  alasan            ENUM('belum terverifikasi','gagal kirim - customer tutup','gagal kirim - sisa sales kelilingan','keluhan mutu','titipan') NOT NULL,
  status            ENUM('open','closed') NOT NULL DEFAULT 'open',
  marketing_confirm ENUM('yes','no') NULL,
  marketing_confirm_by INT UNSIGNED NULL,
  marketing_confirm_at DATETIME NULL,
  komplain_id       INT UNSIGNED NULL,
  asal_usul         VARCHAR(100) NULL,
  catatan_gudang    TEXT NULL,
  keterangan_produksi TEXT NULL,
  produksi_selesai_by INT UNSIGNED NULL,
  produksi_selesai_at DATETIME NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rh_komplain FOREIGN KEY (komplain_id) REFERENCES komplain_header(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retur_detail (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  retur_header_id   INT UNSIGNED NOT NULL,
  komplain_detail_id INT UNSIGNED NULL,
  ukuran            VARCHAR(50) NOT NULL,
  jumlah            DECIMAL(14,2) NOT NULL,
  jumlah_kg         DECIMAL(14,2) NOT NULL DEFAULT 0,
  satuan            VARCHAR(20) NOT NULL,
  keterangan        TEXT NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rd_header FOREIGN KEY (retur_header_id) REFERENCES retur_header(id) ON DELETE CASCADE,
  CONSTRAINT fk_rd_komdetail FOREIGN KEY (komplain_detail_id) REFERENCES komplain_detail(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. PENERIMAAN GUDANG - HEADER & DETAIL
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gudang_retur_header (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  retur_id          INT UNSIGNED NOT NULL,
  tgl_terima        DATE NOT NULL,
  status            ENUM('open','closed') NOT NULL DEFAULT 'open',
  catatan           TEXT NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_grh_retur FOREIGN KEY (retur_id) REFERENCES retur_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gudang_retur_detail (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gudang_retur_header_id INT UNSIGNED NOT NULL,
  ukuran            VARCHAR(50) NOT NULL,
  jumlah            DECIMAL(14,2) NOT NULL,
  satuan            VARCHAR(20) NOT NULL,
  berat_kg          DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_grd_header FOREIGN KEY (gudang_retur_header_id) REFERENCES gudang_retur_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. TINDAKLANJUT ADMIN PRODUKSI - HEADER & DETAIL
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS produksi_retur_header (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  komplain_id       INT UNSIGNED NOT NULL,
  tgl_tindakan      DATE NOT NULL,
  keterangan        TEXT NULL,
  keputusan         ENUM('selesai','reject') NULL DEFAULT NULL, -- tidak dipilih di UI; keputusan ditulis di keterangan
  status            ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prh_komplain FOREIGN KEY (komplain_id) REFERENCES komplain_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS produksi_retur_detail (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produksi_retur_header_id INT UNSIGNED NOT NULL,
  ukuran            VARCHAR(50) NOT NULL,
  jumlah            DECIMAL(14,2) NOT NULL,
  satuan            VARCHAR(20) NOT NULL,
  tindakan          TEXT NULL,
  created_by        INT UNSIGNED NULL,
  modified_by       INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prd_header FOREIGN KEY (produksi_retur_header_id) REFERENCES produksi_retur_header(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. USER & MENU BARU
-- ------------------------------------------------------------
INSERT IGNORE INTO users (id, username, password, nama, role, email, is_active, created_at) VALUES
 (7, 'satpam',    '$2y$10$k9clfD.4V42OFyEqW16wT.k7Ra2nFDBYhG0X3xgdFxCnfU/kFfNUa', 'Satpam PT. MCP', 'satpam', 'satpam@mcp.co.id', 1, NOW()),
 (8, 'produksi',  '$2y$10$TDEUPZs.Voeq11Jh.DoxFe31zQ.aLFQOH4TeUrVJw20lk8.4abp.i', 'Admin Produksi', 'produksi', 'produksi@mcp.co.id', 1, NOW());

INSERT IGNORE INTO menus (id, menu_key, nama, route, icon, urut, is_active, created_at) VALUES
 (8, 'barang_kembalian', 'Barang Kembalian', 'barang_kembalian.php', 'bi-truck', 8, 1, NOW()),
 (9, 'komplain', 'Komplain Mutu', 'komplain.php', 'bi-exclamation-diamond', 9, 1, NOW()),
 (10, 'produksi_retur', 'Retur Produksi', 'produksi_retur.php', 'bi-tools', 10, 1, NOW()),
 (11, 'gudang_retur', 'Retur Gudang', 'gudang_retur.php', 'bi-box-seam', 11, 1, NOW()),
 (12, 'laporan_retur', 'Laporan Retur', 'laporan_retur.php', 'bi-bar-chart', 12, 1, NOW()),
 (13, 'laporan_satpam', 'Laporan Satpam', 'laporan_satpam.php', 'bi-printer', 13, 1, NOW());

INSERT IGNORE INTO role_menus (role, menu_id, is_active, created_at)
SELECT 'admin', id, 1, NOW() FROM menus WHERE id BETWEEN 8 AND 13;
INSERT IGNORE INTO role_menus (role, menu_id, is_active, created_at) VALUES
 ('satpam', 1, 1, NOW()),
 ('satpam', 8, 1, NOW()),
 ('satpam', 13, 1, NOW()),
 ('marketing', 9, 1, NOW()),
 ('produksi', 1, 1, NOW()),
 ('produksi', 10, 1, NOW()),
 ('gudang', 11, 1, NOW()),
 ('manager', 12, 1, NOW());

-- Keputusan kini opsional; penjelasan ditulis manual di Keterangan Penyelesaian.
ALTER TABLE produksi_retur_header MODIFY keputusan ENUM('selesai','reject') NULL DEFAULT NULL;

-- Konfirmasi marketing atas barang kembalian yang diinput mandiri satpam.
ALTER TABLE retur_header
  ADD COLUMN IF NOT EXISTS marketing_confirm ENUM('yes','no') NULL AFTER status,
  ADD COLUMN IF NOT EXISTS marketing_confirm_by INT UNSIGNED NULL AFTER marketing_confirm,
  ADD COLUMN IF NOT EXISTS marketing_confirm_at DATETIME NULL AFTER marketing_confirm_by;

-- Jumlah berat (KG) per item komplain/retur.
ALTER TABLE komplain_detail ADD COLUMN IF NOT EXISTS jumlah_kg DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER jumlah;
ALTER TABLE retur_detail ADD COLUMN IF NOT EXISTS jumlah_kg DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER jumlah;

-- Keterangan penyelesaian dari produksi (diteruskan ke gudang).
ALTER TABLE retur_header ADD COLUMN IF NOT EXISTS keterangan_produksi TEXT NULL AFTER catatan_gudang;
ALTER TABLE retur_header ADD COLUMN IF NOT EXISTS produksi_selesai_by INT UNSIGNED NULL AFTER keterangan_produksi;
ALTER TABLE retur_header ADD COLUMN IF NOT EXISTS produksi_selesai_at DATETIME NULL AFTER produksi_selesai_by;
