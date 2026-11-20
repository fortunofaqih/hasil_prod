# PT. MCP — Sistem Pemantauan Ambil Hasil Produksi

Aplikasi web native (HTML + PHP 8 + MySQL/MariaDB) untuk mengawasi alur DO/SPK mulai dari marketing, PPIC/produksi, QC, gudang, hingga verifikasi manager.

## Lokasi
`C:\xampp\htdocs\hasil_prod`

## Akses
- URL: `http://localhost/hasil_prod/`
- Pastikan Apache & MySQL di XAMPP sudah berjalan.

## Akun bawaan (password bisa diganti lewat database)
| Username | Password | Role |
|---|---|---|
| admin | admin123 | Administrator |
| marketing | marketing123 | Marketing (User A) |
| ppic | ppic123 | PPIC / produksi |
| qc | qc123 | Quality Control |
| gudang | gudang123 | Gudang |
| manager | manager123 | Manager |

Setiap user dapat mengganti kata sandinya sendiri lewat tombol **kunci** di navbar (Ganti Password), tanpa perlu admin.

## Alur aplikasi
1. **Marketing** input DO: no. DO, upload DO (JPG/PNG/PDF maks. 5 MB), no. SPK, tanggal order, tanggal ambil, customer, category, ukuran, jumlah, satuan, kategori Kirim/Las. Status → `ppic`.
2. **PPIC** melihat antrean, memilih DO/SPK, menentukan Roll saja atau Roll + Potong. Jika Roll saja, field tanggal potong otomatis tersembunyi dan tidak dikirim. Isi rencana & realisasi. Status → `qc`.
3. **QC** verifikasi per SPK dan mengisi jumlah lolos. Jika semua lolos = 0, status → `gagal` dan **tidak diteruskan ke gudang**. Jika ada yang lolos → `gudang`.
4. **Gudang** mengonfirmasi ketersediaan dan mencatat jumlah siap diambil. Status → `manager`.
5. **Manager** melihat history lengkap (timeline per bagian), lalu memilih:
   - **Verifikasi & Setujui Ambil** → status `siap_ambil`; gudang baru boleh melepas barang.
   - **Kembalikan untuk Koreksi** → pilih Marketing / Produksi / QC / Gudang; status `koreksi`, catatan koreksi muncul di halaman bagian tersebut, lalu bagian itu mengirim ulang.
6. **Gudang** melepas barang hanya setelah status `siap_ambil` → status `selesai`, `released_at` tercatat.

## Struktur
```
hasil_prod/
├── index.php            # redirect sesuai session
├── login.php, logout.php
├── ganti_password.php       # user mengubah password sendiri lewat tombol di navbar
├── dashboard.php
├── header.php, footer.php
├── marketing.php, ppic.php, qc.php, gudang.php, manager.php, admin.php
├── config/              # config.php, koneksi.php (PDO), functions.php, report.php
├── css/app.css          # mobile-first + breakpoint 768/992px
├── js/app.js            # konfirmasi, auto-hide alert, toggle field potong
├── library/bootstrap/   # Bootstrap 5.3.3 CSS + JS (lokal, bukan CDN)
├── library/bootstrap-icons/ # Bootstrap Icons CSS + font woff/woff2 (lokal)
├── library/chartjs/     # Chart.js 4.4.4 (lokal, bukan CDN)
├── img/logo_mcp.png     # logo resmi PT. MCP (dipakai navbar, login, favicon)
├── uploads/do/          # file DO yang diunggah (PHP diblokir via .htaccess)
├── sql/hasil_prod.sql   # schema + data awal
└── README.md
```

## Database
Database: `hasil_prod` (utf8mb4), 15 tabel. Setiap tabel memiliki kolom audit:
`created_by`, `modified_by`, `created_at`, `modified_at`.

Tabel transaksi memakai pasangan **header + detail**:
`do_header`/`do_detail`, `produksi_header`/`produksi_detail`, `qc_header`/`qc_detail`, `gudang_header`/`gudang_detail`, `manager_header`/`manager_detail`, `tracking_header`/`tracking_detail`.

Import ulang schema (hati-hati: menghapus data yang ada):
```
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\hasil_prod\sql\hasil_prod.sql
```

## Administrasi
Halaman `admin.php` (hanya role admin):
- menambah user, mengaktifkan/menonaktifkan user;
- menambah menu (key, nama, route, ikon, urutan) dan mengaktifkan/menonaktifkannya;
- mengatur hak akses menu per role. Menu yang ditampilkan di navbar diambil dari database.

## Laporan Manager
- `manager.php?act=detail&id=...` menampilkan tombol **Buka File DO** dan kartu **Dokumen DO dari Marketing** (nama file, buka tab baru, unduh, pratinjau gambar/PDF) langsung dari file yang diunggah saat input DO.
- `manager.php?act=detail&id=...` juga menampilkan tabel **Perbandingan Qty per SPK**: Marketing (DO) vs Produksi vs QC Lolos vs Selisih (barang tidak lolos/rusak) vs Catatan Gudang, lengkap dengan baris Total dan peringatan selisih.
- Dashboard manager/admin saat ini hanya menampilkan satu grafik lokal (Chart.js): **Distribusi Status DO Saat Ini** (donat).
- Kartu KPI, grafik perbandingan qty, grafik lama proses, dan tabel DO mandek untuk sengaja tidak ditampilkan agar dashboard tetap ringkas.

## Keamanan
- Koneksi PDO dengan prepared statement.
- Token CSRF pada semua form.
- Password di-hash dengan `password_hash()`.
- Upload divalidasi ekstensi + MIME (finfo), dibatasi 5 MB, nama file diacak.
- Akses halaman dibatasi per role.
