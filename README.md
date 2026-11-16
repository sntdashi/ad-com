# AD Com

Portal unit layanan teknologi TKJ untuk siswa, warga sekolah, dan masyarakat umum. Pengguna dapat memilih jasa, membuat tiket tanpa akun, dan melacak progres menggunakan nomor tiket serta kode akses privat. Tim teknisi khusus AD Com memperoleh dashboard berbasis peran untuk triase, penugasan tim, pelaksanaan, supervisi, standar kualitas, dan audit status. Siswa tidak memiliki role khusus; mereka menggunakan layanan sebagai pelanggan biasa.

## Yang sudah tersedia

- Landing page responsif dan guided service picker.
- Katalog Jaringan & Wi-Fi, CCTV, Servis Komputer, dan Konsultasi IT.
- Form pemesanan publik tiga langkah dengan validasi Indonesia.
- Nomor tiket ramah dan kode akses yang hanya disimpan dalam bentuk hash.
- Proof-of-Service Timeline, jadwal, PIC, anggota tim teknisi, dan rincian estimasi.
- Enam dashboard berbasis role: operator, admin, supervisor, teknisi, gudang, serta pelanggan.
- Chat pelanggan–PIC yang terisolasi berdasarkan nomor tiket dan diperbarui otomatis.
- Profil PIC berisi avatar, spesialisasi, bio, dan telepon berdasarkan consent.
- Inventaris dengan mutasi stok atomik, ambang restock, dan pemakaian per tiket.
- Ledger keuangan transparan dengan pemisahan pencatat admin dan verifikator operator.
- Transisi status tervalidasi dan audit log.
- Checklist layanan yang dipetakan ke standar keselamatan dan kualitas AD Com.
- Login email, passkey, 2FA, reset password, dan email verification.
- Data demo serta test otomatis alur penting.

PRD lengkap ada di [`docs/PRD.md`](docs/PRD.md). Panduan integrasi sekolah ada di [`docs/INTEGRATION.md`](docs/INTEGRATION.md).

## Stack

- PHP 8.3+, Laravel 13, Fortify, SQLite untuk pengembangan.
- Inertia 3, React 19, TypeScript.
- Tailwind CSS 4 dan komponen Radix/shadcn.
- Pest 5 untuk feature test.

## Menjalankan secara lokal

Dengan PHP dan Composer lokal:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
npm install
npm run build
php artisan serve
```

Buka `http://localhost:8000`.

Jika PHP belum terpasang, gunakan Composer melalui Docker:

```bash
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 composer install
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 php artisan migrate:fresh --seed
npm install
npm run build
docker run --rm --user "$(id -u):$(id -g)" -p 8000:8000 -v "$PWD:/app" -w /app composer:2 php artisan serve --host=0.0.0.0 --port=8000
```

## Akun demo

Semua akun memakai kata sandi `password`.

| Peran                   | Email                       |
| ----------------------- | --------------------------- |
| Operator / super admin  | `operator@adcom.test`       |
| Admin                   | `admin@adcom.test`          |
| Supervisor teknis       | `supervisor@adcom.test`     |
| Petugas gudang          | `gudang@adcom.test`         |
| Teknisi / PIC           | `teknisi@adcom.test`        |
| Teknisi jaringan & CCTV | `nabila.teknisi@adcom.test` |
| Teknisi perangkat       | `dedi.teknisi@adcom.test`   |
| Pelanggan               | `pelanggan@adcom.test`      |

Contoh pelacakan publik: tiket `ADC-2609-0101`, kode `ADCOM101`.

## Verifikasi

```bash
php artisan test
npm run types:check
npm run build
vendor/bin/pint --test
```

Jangan gunakan akun, kode tiket, dan `APP_KEY` demo di production. Jalankan `php artisan migrate --force` tanpa seeder demo pada server production.
