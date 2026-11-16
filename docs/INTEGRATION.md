# Integrasi AD Com dengan Website Sekolah

## Rekomendasi arsitektur

Gunakan subdomain khusus seperti `layanan.nama-sekolah.sch.id`, kemudian tambahkan menu **Layanan TKJ / AD Com** pada website resmi sekolah. Subdomain lebih aman dan mudah dirawat daripada menanam seluruh aplikasi Laravel di iframe atau subfolder CMS sekolah.

```text
Website sekolah
  └── Menu “Layanan TKJ”
        └── https://layanan.nama-sekolah.sch.id
              ├── Portal publik
              ├── Pemesanan dan pelacakan
              └── Dashboard tim sekolah
```

## Kebutuhan server

- PHP 8.3 atau lebih baru beserta ekstensi Laravel yang umum.
- Nginx/Apache dengan document root diarahkan ke folder `public`.
- MySQL 8/PostgreSQL untuk production; SQLite hanya disarankan untuk demo kecil.
- Node.js 22+ hanya diperlukan saat proses build aset.
- HTTPS wajib untuk keamanan sesi dan dukungan passkey.
- Scheduler dan queue worker ketika notifikasi email/WhatsApp diaktifkan.

## Checklist sebelum pilot

1. Ganti identitas sekolah, alamat, nomor kontak, cakupan wilayah, dan jam operasional.
2. Validasi daftar layanan, harga mulai, SOP, tingkat risiko, dan masa garansi bersama kaprog TKJ.
3. Buat akun tim resmi; hapus seluruh kredensial serta tiket demo.
4. Atur `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, database, mail, queue, dan `SESSION_SECURE_COOKIE=true`.
5. Jalankan migrasi, build aset, cache konfigurasi, dan test smoke pada server staging.
6. Uji persetujuan estimasi, eskalasi risiko, privasi foto, pembatalan, dan komplain dengan skenario nyata.
7. Tunjuk PIC admin, supervisor teknis, dan anggota tim teknisi khusus sebelum portal dipublikasikan.

## Perintah deploy ringkas

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Untuk deployment zero-downtime, arahkan setiap rilis ke direktori baru, gunakan symlink untuk `.env` dan `storage`, lalu pindahkan symlink aktif setelah migrasi aman.

## Tata kelola

- Data pelanggan hanya dipakai untuk pelaksanaan layanan dan dibatasi sesuai peran.
- Password Wi-Fi, private key, dan kredensial perangkat tidak boleh dimasukkan ke catatan tiket.
- Foto bukti harus menghindari wajah, dokumen pribadi, layar sensitif, serta area rumah yang tidak relevan.
- Pekerjaan berisiko sedang/tinggi harus mengikuti aturan verifikasi supervisor teknis pada PRD.
- Siswa menggunakan portal sebagai pelanggan biasa dan tidak otomatis menjadi anggota tim teknisi.
- Backup database dan lampiran dilakukan harian dengan uji pemulihan berkala.
