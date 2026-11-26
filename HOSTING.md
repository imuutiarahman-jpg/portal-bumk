# Panduan Pasang di Hosting (cPanel / LiteSpeed)

## 1. Upload file
- Upload **semua isi** folder `portal-bumk` ke **`public_html/` (root)**.
- Hasil di `public_html/` harus ada: `index.php`, folder `config`, `includes`, `modules`, `assets`, `sql`, `contoh`, dan `health.php`.
- Permission: folder **0755**, file **0644**.

## 2. Atur BASE_URL
Salin `config/app.hosting.php` → `config/app.php`, atau edit langsung baris:

```php
define('BASE_URL', '');
```

> Jangan pakai `'/portal-bumk'` kalau file ada di root, dan jangan `'/'` (double slash).

## 3. Atur koneksi database
Salin `config/database.hosting.php` → `config/database.php`, lalu isi dari panel hosting:

```php
define('DB_HOST', 'localhost');        // coba 127.0.0.1 kalau gagal
define('DB_NAME', 'bumb9491_bumk');
define('DB_USER', 'bumb9491_bumk');
define('DB_PASS', '...');              // password persis
```

Pastikan user DB di-**grant** ke database tersebut.

## 4. Import database
Buka phpMyAdmin hosting → pilih database → tab **Import**.

**Kalau Anda meng-import `sql/bumk_umgo.sql` versi terbaru yang ada di folder ini**, semua tabel & kolom sudah lengkap — **tidak perlu menjalankan migrasi**.

**Kalau memakai file SQL lama**, jalankan berurutan:

1. `sql/update_payment_status.sql`
2. `sql/update_import_qmas.sql`
3. `sql/update_qmas_harga_hpp.sql`
4. `sql/update_qmas_produk_aktif.sql`
5. `sql/update_qmas_pembelian.sql`
6. `sql/update_smcorner_lengkap.sql`
7. `sql/update_sm_import_katalog.sql`
8. `sql/update_keamanan_akun.sql`
9. `sql/update_qmas_opening_stock_date.sql` (stok awal bertanggal; lewati jika kolom sudah ada)
10. `sql/update_qmas_pembelian_lunas.sql` (kolom `lunas_date` nota pembelian; lewati jika kolom sudah ada)

> Catatan: nomor 4 dan 6 memakai `CREATE PROCEDURE`. Jika user hosting tidak boleh membuat routine, gunakan `sql/migrasi_tanpa_prosedur.sql` (jalankan hanya baris untuk kolom yang belum ada).

## 5. Cek kesehatan
Buka:

```
https://bumkumgostore.com/health.php
```

Semua baris harus **OK**. Perhatikan terutama: versi PHP 8.x, ekstensi `pdo_mysql`/`curl`, koneksi DB, tabel, dan kolom.

**Hapus `health.php` setelah selesai.**

## 6. Buka aplikasi
```
https://bumkumgostore.com/
```

Login demo: `superadmin` / `password123` (ganti setelah produksi).

## 7. Masalah umum

| Gejala | Penyebab | Solusi |
|---|---|---|
| 404 | URL `/portal-bumk` tapi file di root | Buka root domain |
| "Koneksi database gagal" | Host/user/pass salah | Cek panel hosting; coba `127.0.0.1` |
| Halaman putih | PHP < 8 atau error fatal | Cek error_log hosting |
| Chart tidak tampil | File Chart.js belum ter-upload | Upload `assets/vendor/chartjs/` |
| Import link sheet gagal | cURL/outbound HTTPS diblokir | Pakai upload CSV |
| Error kolom tidak ada | Migrasi belum dijalankan | Lihat langkah 4 |

## 8. Keamanan setelah produksi
- Ganti semua password demo.
- Hapus `health.php`.
- Batasi akses folder `sql/` (bisa ditaruh di luar `public_html` atau diblokir lewat `.htaccess`).
- Aktifkan HTTPS.
