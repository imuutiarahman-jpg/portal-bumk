# Portal Terintegrasi BUMK UMGO

Sistem informasi unit usaha Badan Usaha Milik Kampus Universitas Muhammadiyah Gorontalo.
Dibangun dengan **PHP 8 native + PDO + MySQL (XAMPP/phpMyAdmin)** dan **Bootstrap 5**.

Unit usaha: **Qmas Air Mineral**, **Suara Muhammadiyah (SM) Corner**, **Daqam Caffe**.

---

## 1. Instalasi

1. Pastikan XAMPP berjalan (Apache + MySQL).
2. Buka phpMyAdmin: `http://localhost/phpmyadmin`
3. Klik tab **Import** → pilih file `sql/bumk_umgo.sql` → klik **Go**.
   (File ini otomatis membuat database `bumk_umgo`, tabel, dan data contoh.)
   > Jika database sudah terisi dari versi sebelumnya, import juga `sql/update_payment_status.sql` (cukup sekali). Script ini mengubah status `lunas` lama menjadi `tunai` dan menambah kolom `bank`, tanpa menghapus data.
   > Untuk data yang sudah memakai `opening_stock`, jalankan juga `sql/update_qmas_opening_stock_date.sql` (cukup sekali). Script ini menambah kolom `opening_stock_date`, mengisi stok awal penyeimbang, lalu menghitung ulang stok.
   > Jalankan pula `sql/update_qmas_pembelian_lunas.sql` (cukup sekali) untuk menambah kolom `lunas_date` pada nota pembelian, agar piutang ke supplier bisa dilunasi.
4. Pastikan folder ini berada di `C:\xampp\htdocs\portal-bumk`.
5. Buka di browser: **http://localhost/portal-bumk/**

> Koneksi DB ada di `config/database.php` (default XAMPP: `root`, password kosong).
> Sesuaikan jika password MySQL Anda berbeda.

## 2. Akun Demo

Password untuk semua akun: **`password123`**

| Username | Role | Akses |
|---|---|---|
| `superadmin` | Pimpinan / Super Admin | Omzet gabungan 3 unit + semua dashboard |
| `admin_qmas`, `kasir_qmas` | Admin/Kasir | Qmas Air Mineral saja |
| `admin_smcorner` | Admin | SM Corner saja |
| `kasir_daqam` | Kasir | Daqam Caffe saja |

**Ganti passworddemo sebelum dipakai produksi** (ubah lewat phpMyAdmin dengan `password_hash()`).

## 3. Alur Sistem

```
[Pengunjung]
    │
    ▼
Portal Utama (landing: header 80px, hero 280px, 3 kartu unit, footer 70px)
    │
    ├── Kartu Qmas ────┐
    ├── Kartu SM ──────┼──► (belum login) → Login → redirect ke dashboard tujuan
    └── Kartu Daqam ───┘       (sudah login & berhak) → Dashboard unit
                                    │
                                    ▼
                          Role-Based Access Control
                          ├── super_admin → Ringkasan Omzet Gabungan + semua unit
                          └── admin/kasir → hanya dashboard unitnya (require_unit)
```

- Session + `password_hash`/`password_verify`
- Token CSRF pada semua form
- Semua query memakai **prepared statement** (PDO)

### Keamanan akun
- **Super Admin** mengelola akun lewat menu **Kelola Akun**: tambah, ubah, reset password, nonaktifkan, dan hapus akun. Tidak bisa menghapus akun sendiri atau Super Admin terakhir.
- Kebijakan password: minimal 8 karakter, wajib huruf + angka, tidak sama dengan username.
- Akun baru / hasil reset wajib **ganti password** saat login pertama (`must_change_password`).
- **Lockout**: 5 kali login gagal → akun dikunci 15 menit.
- **Session timeout** 30 menit tidak aktif.
- Setiap user bisa mengganti password sendiri lewat menu **Ganti Password**.
- Riwayat login/logout & aktivitas akun tercatat di `activity_logs` dan ditampilkan di halaman Kelola Akun.

## 4. Fitur per Unit

### Qmas Air Mineral
- KPI: omzet hari ini, galon terjual, dus terjual, sisa stok gudang
- Transaksi & distribusi: input penjualan multi-item, jenis pelanggan (fakultas/mahasiswa/umum), serta **Hapus transaksi** yang salah input (konfirmasi dengan mengetik nomor transaksi; stok otomatis dihitung ulang). Transaksi Tunai yang akan disetor ke bank bisa diklik **Setor** (pilih bank BSI/Muamalat + tanggal setor); status otomatis jadi **Transfer** dan `Tgl Setor` tampil di laporan. Pencarian nama pelanggan tersedia di header riwayat transaksi.
- **Stok dihitung menurut tanggal**: `stok = opening_stock + pembelian/penjualan dengan tanggal > opening_stock_date (s.d. hari ini)`; transaksi hasil import **juga** mengurangi stok. Data sebelum tanggal stok awal tidak dikurangi dua kali. Pembelian backdated hanya memengaruhi transaksi setelah tanggalnya. Tombol **Hitung Ulang Stok** di **Stok & Galon** dipakai untuk sinkronisasi.
- **Import Stok Masuk** (`Import Stok Masuk`): tarik kolom **TANGGAL + Stok Masuk** dari tab *Kartu Kontrol Stok* Qmas (link/paste/CSV) menjadi nota pembelian bertanggal. Mode timpa batch `KARTU-STOK-2026` mencegah dobel. Harga nota memakai HPP produk yang berlaku agar laporan laba tidak berubah. Untuk data 2026, `opening_stock` dipakai sebagai angka penyeimbang selisih antara orderan yang diinput di aplikasi dengan kolom `Stok Keluar` kartu; lihat `sql/update_qmas_opening_stock_date.sql`.
- **Hapus Data per Bulan**: pilih periode `YYYY-MM`, konfirmasi dengan mengetik periode tersebut; menghapus seluruh transaksi & pembelian bulan itu dan menyetel stok semua produk Qmas menjadi 0 (cocok untuk mengosongkan data Qmas)
- Status bayar: **Tunai / Transfer / Piutang**. Saat memilih **Transfer**, muncul pilihan bank **BSI** atau **Muamalat** (wajib). Piutang bisa dilunasi lewat form pilihan metode + bank.
- Stok kemasan (GL 19L, 240ml, 330ml, 600ml, 1.5L) + pencatatan tukar galon kosong + HPP per produk
- **Pembelian / Stok Masuk** (menu terpisah): catat nota pembelian dari supplier (tanggal, no. invoice, multi-produk, qty, harga beli, status bayar Tunai/Transfer/Piutang + bank). Saat disimpan, **stok bertambah** dan **HPP produk diubah ke harga beli terakhir**; riwayat & ringkasan harian/bulanan tersedia di halaman yang sama. Nota berstatus **Piutang** bisa diklik **Lunas** (Tunai/Transfer + bank); dashboard menampilkan kartu **Piutang Pembelian** (hutang ke supplier) yang berkurang otomatis setelah pelunasan
- Kelola produk di menu **Stok & Galon**: tambah, **Ubah** lewat popup (nama, SKU, jenis, ukuran, harga, HPP, stok, min stok, satuan), dan **Hapus permanen**
  - Tombol aksi memakai **tulisan**: **Ubah** dan **Hapus** (tidak lagi ikon saja).
  - **Hapus permanen**: hanya untuk produk yang **belum pernah dipakai transaksi**; konfirmasi harus **mengetik nama produk persis**. Produk yang sudah dipakai transaksi ditolak agar riwayat omzet/laba tidak rusak (foreign key `RESTRICT`). Tidak ada lagi tombol Nonaktifkan.
- Bootstrap 5 disimpan lokal di `assets/vendor/bootstrap/` (CDN hanya fallback), agar modal tetap berfungsi tanpa internet.
- Laporan harian/mingguan/bulanan + export CSV (Excel), lengkap dengan TGL LUNAS, TGL SETOR, HPP, dan laba
- **Import CSV**: upload hasil "Save as CSV" dari sheet *list order QMAS* (lihat bagian 7); tersedia pengaturan tahun default untuk tanggal tanpa tahun dan mode batch (timpa/simpan sebagai batch baru)
- **Rekap Bulanan**: ringkasan per bulan dari 2025 s/d sekarang (omzet, tunai, transfer BSI/Muamalat, piutang belum lunas, laba) + export rekap CSV

### SM Corner
- KPI: penjualan ritel, produk terlaris, low stock, nilai inventaris, pembelian bulan ini
- Katalog & kategori: kain & seragam, kemeja/batik/jas, perlengkapan HW
- SKU ukuran S–XXXL dan satuan meteran; kelola produk (Ubah/Hapus permanen) + **HPP**
- Kasir POS dengan pencarian cepat + cetak struk
- **Pembelian / Stok Masuk**: nota supplier (Tunai/Transfer/Piutang + bank), stok & HPP otomatis, riwayat + laporan pembelian export CSV
- **Rekap Bulanan**: omzet, HPP, laba per bulan + chart + export CSV
- **Import Katalog Lengkap**: cukup tempel link Google Sheets → semua tab diambil sekaligus (XLSX): `Master Kategori` → `sm_categories`, `Katalog Produk` → `sm_products` (SKU unik), `Detail Stok & Harga` → `sm_product_variants`, `Riwayat Stok` → `sm_stock_history`, `Ringkasan` → `sm_catalog_summary`. Ada pratinjau + peringatan kualitas data sebelum commit. File CSV (tab ringkasan saja) tetap diterima sebagai fallback. Skema dibuat otomatis saat halaman import/kasir/pembelian dibuka.
- Import transaksi CSV menyusul setelah data penjualan SM Corner tersedia (tidak memakai format Qmas)

### Daqam Caffe
- KPI: pendapatan hari ini, jumlah order, best seller, rata-rata transaksi
- Kasir POS grid menu (kopi, non-kopi, makanan berat, snack), meja/take-away, catatan, tunai/QRIS + cetak struk
- Manajemen menu, status tersedia/habis, resep & bahan baku (stok otomatis berkurang saat order)
- Laporan shift kasir (uang laci vs sistem vs QRIS) + export CSV

### Pimpinan
- Dashboard omzet gabungan (hari ini, bulan ini, total) + kontribusi per unit + transaksi terbaru seluruh unit

## 5. Struktur Folder

```
portal-bumk/
├── index.php
├── config/{app,database}.php
├── includes/{auth,functions,header,footer}.php
├── assets/{css,js}/
├── modules/
│   ├── auth/        login, logout
│   ├── portal/      landing
│   ├── superadmin/  dashboard omzet gabungan
│   ├── qmas/        dashboard, transaksi, stok, pembelian, laporan, rekap, import, import_pembelian
│   ├── smcorner/    dashboard, produk, pembelian, kasir, laporan, rekap, import_katalog
│   └── daqam/       dashboard, kasir, menu, bahanbaku, shift, laporan
├── contoh/contoh_import_qmas.csv   # template CSV contoh
└── sql/
    ├── bumk_umgo.sql
    ├── update_payment_status.sql
    ├── update_import_qmas.sql
    ├── update_qmas_harga_hpp.sql
    ├── update_qmas_produk_aktif.sql  # kolom is_active (soft delete produk)
    ├── reactivate_qmas_products.sql
    ├── update_qmas_pembelian.sql    # tabel pembelian / stok masuk (Qmas)
    ├── update_qmas_opening_stock_date.sql  # tanggal stok awal + angka penyeimbang
    ├── update_qmas_pembelian_lunas.sql     # kolom lunas_date nota pembelian
    ├── update_qmas_setor_tunai_lama.sql     # opsional: tunai lama → transfer
    ├── update_smcorner_lengkap.sql  # HPP + pembelian SM Corner
    └── update_sm_import_lengkap.sql # varian, riwayat stok, batch import katalog (opsional; otomatis saat pakai)
```

## 6. Catatan Desain

Implementasi mengikuti dokumen `PSD_Perancangan_Portal_BUMK_UMGO.docx` dengan penyempurnaan palet (UI v3):
- Warna identitas UMGO: hijau tua `#0B4F3E` → hijau terang `#10B981`, dengan turunan shade untuk aksen/soft background
- Warna identitas visual: teal `#0E9F90` dipakai seragam di header, sidebar, kartu, tombol, grafik, dan hero. Atribut `data-unit` pada `<body>` tetap ada, tetapi variabel warna unit (`--qmas`, `--smcorner`, `--daqam`) kini mengacu ke teal yang sama.
- Header 76px, hero ±296px, footer 68px; kartu KPI/unit_radius 16px
- Struktur layer PSD diterjemahkan ke section CSS: `01_HEADER_NAV`, `02_HERO_BANNER`, `03_MENU_UNIT_USAHA`, `04_FOOTER_INFO`
- Bootstrap, Bootstrap Icons, dan Chart.js disimpan lokal di `assets/vendor/` (tetap berfungsi tanpa internet)
- Aksesibilitas: skip link, `:focus-visible`, label form, aria pada drawer sidebar, serta dukungan `prefers-reduced-motion`

Bagian "Alur Sistem & Struktur Database" yang disebut di dokumen tetapi tidak diisi, sudah dilengkapi di file `sql/bumk_umgo.sql` dan dokumentasi ini.

## 7. Import CSV Transaksi Qmas

1. Buka menu **Qmas → Import CSV**. Ada dua cara:
   - **Lewat link (disarankan):** di Google Sheets klik **Share → Anyone with the link → Viewer**, lalu salin URL-nya ke kolom **Link Google Sheets**. URL `/edit#gid=123` maupun `/export?format=csv&gid=123` diterima; aplikasi mengubahnya otomatis ke URL ekspor CSV. Hanya `docs.google.com` yang diperbolehkan.
   - **Lewat file:** di Sheets/Excel pilih **File → Download / Save as → CSV**, lalu pilih file di kolom **File CSV**.
   
   Jika keduanya diisi, **link yang dipakai**. Jika sheet tidak di-share publik, aplikasi akan memberi pesan error dan Anda bisa memakai cara file.
2. Klik **Pratinjau**.
3. Periksa hasil pratinjau (jumlah transaksi, status, bank, baris yang dilewati), lalu klik **Import**.
4. Import ulang dengan **link/file yang sama** akan menimpa batch lama (tidak dobel). Transaksi manual yang dibuat lewat form tidak terpengaruh.

**Urutan kolom yang dibaca** (mengikuti sheet asli: tiap produk punya QTT/OTT, PRICE, HPP; ada kolom LABA/RUGI; header boleh tiga baris dan ada baris "BULAN …" — semuanya dilewati otomatis). Import kini **mendeteksi posisi kolom dari label header/sub-header**, sehingga tetap benar jika sheet memakai `OTT` (bukan `QTT`), tidak memiliki `TGL SETOR`, atau HPP hanya ada pada produk tertentu. QTT yang tidak wajar (misal harga terbaca sebagai jumlah) otomatis ditolak.

`TANGGAL | NAMA PELANGGAN | TGL LUNAS | TGL SETOR | GL19 QTT | GL19 PRICE | GL19 HPP | 240ML QTT | 240ML PRICE | 240ML HPP | 330ML QTT | 330ML PRICE | 330ML HPP | 600ML QTT | 600ML PRICE | 600ML HPP | 1.5L QTT | 1.5L PRICE | 1.5L HPP | LABA/RUGI | CASH | BANK | TAGIHAN | KET`

Aturan penentuan status (prioritas **PENERIMAAN KAS**):
1. **BANK > 0 → Transfer**, bank dibaca dari `KET` (BSI / Muamalat). Jika KET tidak menyebut bank, tetap Transfer dengan bank kosong dan baris masuk daftar "Perlu diperiksa".
2. **CASH > 0 → Tunai**.
3. **TAGIHAN > 0** atau KET mengandung "Belum" → **Piutang**.
4. Selain itu → Tunai.

Catatan lain:
- Tanggal kosong mengikuti tanggal baris sebelumnya; format diterima: `01 Nov 26`, `5 Jan 2026`, `dd/mm/yyyy`, `yyyy-mm-dd`, serial Excel. `TGL LUNAS` yang berisi teks "Lunas" diterima (tanggal diambil dari `TGL SETOR`).
- Sel yang hanya berisi PRICE tanpa QTT diabaikan.
- HPP diambil **per produk per baris** dari kolom HPP masing-masing, lalu disimpan sebagai HPP item transaksi.

Template bisa diunduh langsung dari halaman Import atau dari `contoh/contoh_import_qmas.csv`.
