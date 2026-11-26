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
1. **Marketing** input DO: no. DO, upload DO (JPG/PNG/PDF maks. 5 MB), no. SPK, tanggal ambil, customer, category, ukuran, jumlah, satuan, kategori Kirim/Las. Status → `ppic`. Tersedia tombol **Edit** (perubahan ikut ke detail produksi/QC/gudang) dan **Delete** (menghapus seluruh alur + file upload).
2. **PPIC** melihat antrean, mengisi **Tanggal Order (mulai kerja produksi)**, memilih DO/SPK, menentukan Roll saja atau Roll + Potong. Jika Roll saja, field tanggal potong otomatis tersembunyi dan tidak dikirim. Isi rencana & realisasi. Status → `qc`.
3. **QC** verifikasi per SPK dan mengisi jumlah lolos. Jika semua lolos = 0, status → `gagal` dan **tidak diteruskan ke gudang**. Jika ada yang lolos → `gudang`.
4. **Gudang** mengonfirmasi ketersediaan dan mencatat jumlah siap diambil. Status → `manager`.
5. **Manager** melihat history lengkap (timeline per bagian), lalu memilih:
   - **Verifikasi & Setujui Ambil** → status `siap_ambil`; gudang baru boleh melepas barang.
   - **Kembalikan untuk Koreksi** → pilih Marketing / Produksi / QC / Gudang; status `koreksi`, catatan koreksi muncul di halaman bagian tersebut, lalu bagian itu mengirim ulang.
6. **Gudang** melepas barang hanya setelah status `siap_ambil` → status `selesai`, `released_at` tercatat.

Setiap form (marketing, PPIC, QC, gudang, manager) menampilkan kartu **Audit Data**: dibuat oleh/pada dan diubah oleh/pada. Nama petugas adalah tautan `mailto:` ke email di tabel `users`, jadi bisa langsung dihubungi. Form QC, gudang, dan manager juga menampilkan lampiran DO yang diunggah marketing.

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
├── uploads/komplain/    # bukti keluhan & penyelesaian (PHP diblokir)
├── barang_kembalian.php # input satpam + laporan + edit/delete
├── komplain.php         # komplain mutu marketing (komplain saja / retur)
├── produksi_retur.php   # tindak lanjut admin produksi
├── gudang_retur.php     # klarifikasi, catat jumlah & berat, close
├── laporan_retur.php    # laporan manager per bagian
├── sql/migration_retur.sql # migrasi untuk database yang sudah ada
├── sql/db_hasil_prod_lengkap.sql # deployment fresh (semua modul)
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

## Modul Barang Kembalian & Komplain Mutu
- Role baru: `satpam` (satpam123) dan `produksi` (produksi123). Admin bisa membuat user tambahan.
- **Satpam** input barang kembalian: **No. SJ opsional** (kosong disimpan sebagai `-`), nopol, nama sopir, customer, tanggal, alasan (radio: belum terverifikasi / gagal kirim customer tutup / gagal kirim sisa sales / keluhan mutu / titipan), dan bisa memilih komplain retur marketing yang sudah ada. Edit/delete tersedia selama status masih OPEN.
- Pada kartu komplain retur di halaman satpam ada tombol **Catat**: membuka form yang sudah terisi No. SJ, customer, alasan Keluhan Mutu, dan item dari komplain marketing; tinggal isi nopol, sopir, keterangan lalu submit.
- Daftar barang kembalian satpam menampilkan rincian item: ukuran, jumlah, satuan, dan keterangan per baris.
- **Satpam** memiliki halaman **Laporan Satpam** (tombol **Cetak Laporan** di samping judul Barang Kembalian): filter `start date` / `end date` berdasarkan tanggal barang kembali, tabel dengan kolom No, No SJ, Nopol, Sopir, Customer, Alasan, Item, Jumlah, Satuan, Keterangan, serta tombol Cetak dengan ukuran kertas **A4 portrait**.
- Barang kembalian yang diinput **mandiri** satpam (tanpa komplain marketing) muncul di halaman komplain marketing dengan tombol **Yes / No**. Saat **Yes**, tersimpan `marketing_confirm`, `marketing_confirm_by`, dan `marketing_confirm_at`; di halaman satpam tampil badge hijau dengan teks putih **"confirm by <username>"** beserta waktunya.
- Halaman komplain marketing menggunakan tiga tab: **Komplain Mutu**, **Konfirmasi Retur Satpam**, dan **Info Keluhan Mutu** — bisa digeser horizontal di layar kecil. Kolom **Item (Jumlah &amp; Satuan)** menampilkan ringkasan seperti `1 Roll` atau `2 Roll, 5 KG`, bukan sekadar angka.
- **Marketing** input komplain mutu: **No. SJ opsional** (kosong disimpan sebagai `-`), upload bukti, pilih *komplain saja* (langsung CLOSE + bukti penyelesaian opsional) atau *komplain + retur* (OPEN, diteruskan ke produksi). Tiap item memiliki kolom **Jumlah (KG)** tetap (bukan dropdown satuan) di sebelah kanan Ukuran.
- **Admin Produksi** mengisi tanggal dan **Keterangan Penyelesaian** secara bebas; keputusan (diperbaiki / Afalan / daur ulang / reject) ditulis manual di keterangan itu, tanpa pilihan radio. Untuk retur **keluhan mutu yang berasal dari komplain**, tombolnya berubah jadi **Simpan &amp; Tutup (CLOSE)** dan produksi yang menutup retur + komplain; gudang tidak memproses retur ini. Keterangan penyelesaian beserta nama dan waktu produksi ikut tersimpan di `retur_header` dan ditampilkan di daftar gudang pada kolom **Keterangan Penyelesaian (Produksi)**. Retur keluhan mutu yang diinput mandiri satpam (tanpa komplain) tetap ditutup gudang.
- **Admin Gudang** mengklarifikasi asal usul barang yang masih "belum terverifikasi", mencatat jumlah, satuan, dan berat aktual KG, lalu menutup (CLOSE) retur. Komplain ikut CLOSE bila tindak lanjut produksi juga selesai. Retur dengan alasan selain keluhan mutu tetap ditutup gudang.
- Daftar Retur Gudang menampilkan siapa marketing & satpam yang menginput dan kapan, plus tombol **Proses**. Form Proses menampilkan data komplain marketing sebagai pembanding sebelum admin gudang mengisi jumlah, satuan, dan berat.
- Di form Proses ada kolom **BERAT KIRIM (KG)** (abu-abu muda, readonly) dan **Selisih** yang dihitung otomatis saat berat aktual (KG) diketik: `Sama` atau `Selisih x KG`. Nilai jumlah dari retur juga tampil abu-abu muda sebagai data referensi.
- Kedua tabel di halaman Retur Gudang tetap rapi saat data banyak: area scroll dengan batas tinggi, header tabel melekat, kolom pencarian, tombol **Tampilkan semua** (default 8 baris terbaru), dan petunjuk geser di layar HP/tablet.
- Penyesuaian yang sama diterapkan otomatis lewat `js/app.js` ke semua tabel daftar bertanda `data-scalable` (barang kembalian, komplain, gudang, produksi retur, laporan retur, admin, manager). Cukup tambahkan atribut `data-scalable` pada tabel baru.
- Semua daftar berstatus (dashboard, marketing, PPIC, QC, gudang DO, manager, barang kembalian, komplain, produksi retur, gudang retur, laporan retur) memakai tab **Open / Close** dengan **Open sebagai default**. Data yang statusnya berubah otomatis pindah ke tab Close. Di halaman komplain dan laporan retur, tab status berada di dalam tab menu yang sudah ada. Tabel konfigurasi admin (user/menu) sengaja tidak memakai tab ini karena statusnya aktif/nonaktif, bukan alur OPEN/CLOSE.
- Untuk data yang sudah **CLOSE**, kolom **Aksi** hanya menampilkan tombol **Lihat** (Edit/Delete/Proses hilang). Form yang dibuka jadi view-only: semua input disabled, tombol simpan dihapus, dan ada pesan "Data sudah CLOSE". Handler POST juga menolak perubahan pada data CLOSE.
- Info **Keluhan Mutu** saling muncul di marketing, satpam, gudang, dan produksi lewat No. SJ/customer.
- Aturan **OPEN/CLOSE** berlaku untuk retur, gagal kirim, sisa sales, titipan, dan komplain tanpa retur.
- Manager melihat progres per bagian lewat menu **Laporan Retur** (tab Marketing, Satpam, Produksi, Gudang).
- Dashboard **Satpam** hanya menampilkan barang kembalian; dashboard **Admin Produksi** hanya menampilkan komplain retur. Dashboard **Gudang** tetap menampilkan data DO/SPK, dengan kartu tambahan menuju dashboard Retur Gudang.
- Tombol **+ Tambah Item** pada form barang kembalian/komplain berfungsi dinamis (kloning baris, reset input, hapus baris).
- Semua tabel baru memakai header + detail dan kolom audit `created_by/modified_by/created_at/modified_at`.

## Keamanan
- Koneksi PDO dengan prepared statement.
- Token CSRF pada semua form.
- Password di-hash dengan `password_hash()`.
- Upload divalidasi ekstensi + MIME (finfo), dibatasi 5 MB, nama file diacak.
- Akses halaman dibatasi per role.
