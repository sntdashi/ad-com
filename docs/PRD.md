# Product Requirements Document — AD Com

| Atribut          | Nilai                                                       |
| ---------------- | ----------------------------------------------------------- |
| Versi            | 3.0 — operations control, ticket chat, stock, dan finance   |
| Status           | Siap untuk pilot terbatas                                   |
| Pemilik produk   | Unit Layanan Teknologi TKJ / AD Com                         |
| Kanal            | Web app terintegrasi dengan website resmi sekolah           |
| Pengguna layanan | Siswa, guru, staf, warga sekitar, UMKM, dan masyarakat umum |
| Tim operasional  | Operator, admin, supervisor, teknisi, dan petugas gudang    |

## 1. Ringkasan produk

AD Com adalah web app layanan jasa teknologi milik unit layanan TKJ sekolah. Produk ini membantu masyarakat menemukan layanan, mendapatkan pemetaan kebutuhan, membuat pesanan, menyetujui estimasi, memantau pekerjaan, dan menyimpan riwayat layanan tanpa bergantung pada percakapan chat yang tercecer.

Pekerjaan dilaksanakan oleh **tim teknisi khusus AD Com**, bukan oleh siswa secara otomatis. Siswa tidak memiliki role khusus dan menggunakan AD Com sebagai pelanggan biasa, sama seperti guru, staf, atau masyarakat. Supervisor teknis mengendalikan risiko dan kualitas, admin mengelola operasional serta pencatatan keuangan, petugas gudang menjaga stok, dan operator memegang kendali konfigurasi serta akses tingkat tertinggi.

Layanan awal mencakup instalasi CCTV, jaringan dan Wi-Fi, servis komputer, serta konsultasi IT. AD Com dipasang sebagai menu atau portal pada website resmi sekolah agar mudah ditemukan dan memiliki legitimasi yang jelas.

## 2. Latar belakang dan masalah

### 2.1 Masalah pengguna

- Sulit menemukan layanan IT lokal yang tepercaya dan mudah dihubungi.
- Harga, ruang lingkup, jadwal, dan penanggung jawab sering tidak jelas.
- Progres pekerjaan tercecer di chat pribadi dan sulit ditelusuri.
- Riwayat konfigurasi, perbaikan, bukti kerja, dan garansi mudah hilang.
- Pengguna awam kesulitan menjelaskan gejala teknis dengan tepat.

### 2.2 Masalah operasional AD Com

- Pesanan masuk melalui banyak kanal dan rawan terlewat.
- Penugasan PIC serta anggota tim teknisi belum terdokumentasi konsisten.
- Risiko lapangan dan verifikasi supervisor belum menjadi gerbang sistem.
- Estimasi, perubahan biaya, bukti kerja, dan persetujuan pelanggan belum memiliki audit trail terpadu.
- Percakapan pelanggan dan teknisi belum terikat langsung pada nomor tiket.
- Pemakaian suku cadang serta arus pembayaran belum terhubung ke pekerjaan yang menggunakannya.
- Data layanan belum dapat diubah menjadi insight tentang masalah yang sering terjadi, beban tim, dan kualitas penyelesaian.

## 3. Visi dan prinsip

### 3.1 Visi

Menjadi portal layanan teknologi berbasis sekolah yang paling transparan, aman, mudah digunakan, dan benar-benar bermanfaat bagi masyarakat.

### 3.2 Janji produk

> Masalah IT tertangani oleh tim yang jelas. Progres, biaya, bukti, dan garansinya bisa dilacak dari satu tiket.

### 3.3 Prinsip

1. **Trust by design** — identitas tim, estimasi, perubahan biaya, dan bukti kerja selalu jelas.
2. **Diagnosis manusia tetap final** — AI hanya membantu triase, bukan menggantikan teknisi.
3. **Safety-gated service** — pekerjaan berisiko membutuhkan kendali supervisor.
4. **Privacy by default** — data pelanggan hanya tampil kepada pihak yang berwenang.
5. **Account optional for first contact** — pemesanan dan pelacakan dapat dimulai tanpa akun.
6. **Mobile first** — alur utama nyaman digunakan dari ponsel.

## 4. Diferensiasi inti: Service Intelligence Loop

AD Com tidak berhenti pada katalog dan formulir pemesanan. Setiap pekerjaan menghasilkan jejak teknis yang bisa digunakan kembali:

```text
Keluhan pengguna
  ↓
Triase awal ADI + katalog layanan
  ↓
Estimasi dan pemeriksaan risiko
  ↓
Penugasan PIC + anggota tim teknisi
  ↓
Checklist standar + bukti pekerjaan
  ↓
Verifikasi supervisor + serah terima
  ↓
Tech Passport, garansi, dan insight layanan
```

### 4.1 ADI-assisted triage

ADI, Asisten Digital Infrastruktur, membantu pengguna mengubah keluhan sehari-hari menjadi informasi awal yang lebih terstruktur. ADI menyarankan kategori layanan dan langkah aman, tetapi tidak membuat diagnosis final, menjanjikan jadwal, atau menetapkan harga.

### 4.2 Transparent Team Assignment

Setiap pekerjaan dapat memiliki satu PIC teknisi dan beberapa anggota tim. Profil PIC, spesialisasi, foto, kontak yang disetujui untuk ditampilkan, dan tim aktif tersedia pada detail tiket. Anggota yang tidak ditugaskan tidak dapat membuka tiket tersebut.

### 4.3 Proof-of-Service Timeline

Status, aktor, waktu, estimasi, tindakan, hasil pengujian, dan bukti kerja berada dalam satu linimasa. Pelanggan tidak perlu menanyakan progres berulang kali.

### 4.4 Quality Ledger

Setiap jenis layanan memiliki checklist keselamatan, dokumentasi, dan pengujian dengan kode standar. Checklist ini menilai mutu pekerjaan AD Com, bukan kompetensi siswa.

### 4.5 Tech Passport

Perangkat atau instalasi yang pernah ditangani memperoleh riwayat layanan, rekomendasi, hasil pengujian, dan masa garansi. Riwayat ini mempersingkat diagnosis pada kunjungan berikutnya.

### 4.6 Community Impact Pulse

Data agregat menampilkan jumlah masalah selesai, waktu respons, first-time-fix rate, tiket dibuka kembali, kepuasan, dan jangkauan penerima manfaat tanpa membuka data pribadi pelanggan.

## 5. Tujuan dan indikator keberhasilan

### 5.1 Sasaran pilot 90 hari

| Area          | Target awal                                                               |
| ------------- | ------------------------------------------------------------------------- |
| Adopsi        | Minimal 30 pesanan valid                                                  |
| Manfaat       | Minimal 20 pekerjaan selesai dan diverifikasi                             |
| Respons       | Median respons awal di bawah 4 jam operasional                            |
| Transparansi  | 100% pekerjaan berbayar memiliki estimasi yang disetujui                  |
| Akuntabilitas | 100% pekerjaan terjadwal memiliki PIC atau tim teknisi                    |
| Kualitas      | Minimal 90% pekerjaan selesai tanpa reopen pada masa garansi awal         |
| Kepuasan      | Rata-rata minimal 4,5 dari 5                                              |
| Keamanan      | Tidak ada insiden kebocoran data atau pekerjaan berisiko tanpa verifikasi |

### 5.2 North-star metric

**Verified Helpful Jobs per Month**: jumlah tiket selesai, lolos verifikasi sesuai tingkat risiko, diterima pelanggan, dan tidak dibuka kembali selama masa garansi awal.

## 6. Aktor dan hak akses

Sistem memiliki **enam role akun**. Pengunjung tanpa akun bukan role database.

### 6.1 Customer / user

Termasuk siswa, guru, staf, warga sekitar, UMKM, dan masyarakat umum.

- Membuat pesanan dan melihat tiket miliknya.
- Menyetujui estimasi dan perubahan biaya.
- Melacak progres, tim teknisi, bukti publik, serta garansi.
- Mengobrol langsung dengan PIC setelah teknisi ditugaskan ke tiket.
- Memberi ulasan dan meminta layanan ulang.

### 6.2 Technician

Anggota tim teknisi khusus AD Com yang telah ditunjuk oleh pengelola.

- Melihat hanya tiket yang secara aktif ditugaskan kepadanya.
- Membaca keluhan, lokasi, jadwal, dan checklist layanan.
- Memperbarui progres lapangan yang diizinkan.
- Mengisi work log, hasil uji, serta bukti pekerjaan.
- Berkomunikasi dengan pelanggan hanya pada tiket yang ditugaskan.
- Melihat stok yang relevan untuk pekerjaan tanpa dapat mengubah master barang.
- Tidak dapat mengubah role, harga yang sudah disetujui, atau menutup pekerjaan berisiko tanpa supervisor.

### 6.3 Supervisor

Koordinator teknis dan penjaga kualitas layanan.

- Melakukan triase, menentukan risiko, dan menyetujui ruang lingkup.
- Menentukan PIC dan anggota tim teknisi.
- Memverifikasi checklist serta bukti pekerjaan.
- Menangani eskalasi, reopen, dan keputusan keselamatan.
- Melihat persediaan untuk memastikan kesiapan pekerjaan.

### 6.4 Warehouse / petugas gudang

- Mengelola master barang, stok masuk, stok keluar, penyesuaian, dan kebutuhan restock.
- Menghubungkan pemakaian barang dengan nomor tiket saat relevan.
- Melihat histori mutasi beserta aktor dan saldo akhir.
- Tidak dapat membuka keuangan, mengubah role, atau mengelola tiket di luar kewenangannya.

### 6.5 Admin

Pengelola operasional AD Com.

- Mengelola akun non-operator, katalog, harga awal, area layanan, dan jam operasional.
- Memantau seluruh tiket, kapasitas tim, inventaris, transaksi keuangan, dan audit log.
- Mencatat pemasukan/pengeluaran untuk diverifikasi operator.
- Mengaktifkan atau menonaktifkan akun non-operator.

### 6.6 Operator / super admin

Pemegang kontrol tertinggi dan fungsi pemisahan tugas.

- Memiliki akses menyeluruh ke tiket, tim, inventaris, keuangan, dan audit.
- Satu-satunya role yang dapat memberikan, mengubah, atau mencabut akses `operator`.
- Memverifikasi transaksi yang dicatat admin sehingga pencatat dan pemeriksa dapat dipisahkan.
- Menetapkan role, status akun, profil publik teknisi, dan konfigurasi lintas modul.
- Tidak boleh digunakan sebagai akun operasional harian bersama; setiap operator wajib memiliki akun personal.

### 6.7 Pengunjung tanpa akun

- Membaca katalog dan informasi publik.
- Menggunakan ADI untuk pertanyaan umum dan triase awal.
- Membuat pesanan sebagai tamu.
- Melacak satu tiket menggunakan nomor tiket dan kode akses privat.

## 7. Ruang lingkup

### 7.1 P0 — wajib untuk pilot

1. Landing page, katalog, detail layanan, FAQ, privasi, dan syarat layanan.
2. Guided Service Check dan chatbot ADI.
3. Pemesanan tamu atau pengguna login.
4. Nomor tiket, kode akses yang disimpan dalam bentuk hash, dan tautan pelacakan bertanda tangan.
5. Login email, Google, passkey, reset password, verifikasi email, dan 2FA untuk akun tim.
6. Enam role: operator, admin, supervisor, technician, warehouse, dan customer.
7. Dashboard dengan isolasi data sesuai role.
8. Penugasan satu PIC dan beberapa anggota tim teknisi per tiket.
9. Profil PIC berisi nama, foto/avatar, spesialisasi, bio, serta telepon berdasarkan consent.
10. Chat pelanggan–PIC yang terisolasi berdasarkan nomor tiket.
11. Status workflow, estimasi, audit trail, serta timeline pelanggan.
12. Inventaris, mutasi stok atomik, ambang restock, dan relasi pemakaian ke tiket.
13. Ledger pemasukan/pengeluaran, referensi transfer, serta verifikasi operator.
14. Checklist standar kualitas dan bukti pekerjaan.
15. Gerbang verifikasi supervisor untuk pekerjaan berisiko sedang atau tinggi.
16. Tech Passport dan garansi dasar.

### 7.2 P1 — setelah pilot stabil

- Persetujuan estimasi dari halaman pelanggan.
- Notifikasi email atau WhatsApp berbasis consent.
- Kalender kapasitas tim dan SLA alert.
- Review pelanggan serta Community Impact Pulse dari data nyata.
- Upload bukti dengan redaksi metadata dan aturan privasi.
- Upload bukti transfer/lampiran privat dan rekonsiliasi laporan periodik.
- Notifikasi chat dan perubahan status secara real-time.

### 7.3 P2 — ekspansi

- Pengadaan dengan purchase order dan persetujuan berjenjang.
- Invoice fiskal serta payment gateway resmi.
- Multi-unit atau multi-sekolah.
- Preventive maintenance subscription untuk UMKM.
- Analitik pola gangguan dan rekomendasi perawatan.

### 7.4 Di luar scope MVP

- Marketplace teknisi eksternal.
- Penilaian akademik atau rapor kompetensi siswa.
- Akses otomatis siswa sebagai teknisi.
- Diagnosis final sepenuhnya oleh AI.
- Penyimpanan password perangkat, Wi-Fi, private key, atau data rahasia pelanggan.

## 8. Layanan awal

| Kode   | Layanan          | Mode             | Risiko awal | Harga awal               |
| ------ | ---------------- | ---------------- | ----------- | ------------------------ |
| NET.01 | Jaringan & Wi-Fi | On-site          | Sedang      | Mulai Rp75.000           |
| SEC.02 | Instalasi CCTV   | On-site          | Tinggi      | Survei mulai Rp50.000    |
| DEV.03 | Servis Komputer  | Drop-off         | Sedang      | Diagnosis mulai Rp50.000 |
| ADV.04 | Konsultasi IT    | Remote / on-site | Rendah      | Konsultasi awal gratis   |

Harga final selalu bergantung pada hasil pemeriksaan dan baru berlaku setelah disetujui pelanggan.

## 9. Alur utama

### 9.1 Pemesanan

1. Pengguna memilih masalah atau bertanya kepada ADI.
2. Sistem menyarankan kategori layanan tanpa mengklaim diagnosis final.
3. Pengguna mengisi kontak, lokasi, keluhan, waktu pilihan, dan consent privasi.
4. Sistem membuat nomor tiket, kode akses sekali tampil, dan status `new`.
5. Supervisor melakukan triase dan menentukan risiko.
6. Tim menyiapkan estimasi; pelanggan menyetujuinya sebelum pekerjaan berbayar dimulai.
7. Supervisor/admin/operator menetapkan PIC dan anggota tim teknisi.
8. Pelanggan otomatis terhubung ke ruang chat tiket dengan PIC tersebut.
9. Gudang mencatat barang yang dipakai; admin mencatat transaksi pembayaran terkait tiket.
10. Tim menjalankan checklist, work log, serta bukti kerja.
11. Supervisor memverifikasi hasil sesuai risiko; operator memverifikasi transaksi keuangan.
12. Pelanggan menerima hasil, Tech Passport, ringkasan pembayaran, dan informasi garansi.

### 9.2 Status tiket

```text
new → needs_info → triaged → waiting_estimate → waiting_approval
    → scheduled → on_the_way → in_progress → verification
    → waiting_payment → completed → warranty → closed
```

Cabang yang sah: `cancelled` dari tahap pra-penyelesaian dan `reopened` dari `completed` atau `warranty`. Seluruh perubahan menyimpan aktor, waktu, catatan, dan visibility.

## 10. Kebutuhan fungsional

### 10.1 Katalog dan guided check

- Katalog menampilkan masalah yang ditangani, hasil yang diterima, mode layanan, risiko awal, harga mulai, dan garansi.
- Guided check menggunakan bahasa nonteknis dan dapat diarahkan ke pemesanan dengan layanan terpilih.
- Kondisi asap, bau terbakar, listrik, ketinggian, atau risiko keamanan selalu memicu instruksi berhenti dan eskalasi.

### 10.2 ADI

- Tersedia secara global pada halaman publik dan dashboard.
- Menjawab berdasarkan katalog, aturan keselamatan, dan batas layanan AD Com.
- Menolak mengarang harga final, jadwal, diagnosis, atau status tiket.
- Tidak meminta password, kode akses, alamat lengkap, atau data sensitif di percakapan.
- Memiliki fallback lokal agar pertanyaan dasar tetap terjawab ketika provider AI tidak tersedia.
- Percakapan tidak dikirim dengan opsi penyimpanan provider jika integrasi mendukungnya.

### 10.3 Pemesanan dan pelacakan

- Field minimum: layanan, nama, telepon, alamat atau mode serah terima, keluhan, urgensi, dan persetujuan privasi.
- Nomor telepon dinormalisasi ke format Indonesia.
- Input status, role, harga, dan assignment dari klien tidak boleh dipercaya.
- Kode akses mentah hanya tampil sekali; database menyimpan hash.
- Halaman pelacakan hanya memperlihatkan data yang aman bagi pelanggan.

### 10.4 Penugasan tim teknisi

- Tiket dapat memiliki satu `assigned_technician_id` sebagai PIC dan banyak `order_assignments` aktif.
- Setiap anggota assignment wajib memiliki role `technician`.
- Assignment menyimpan waktu mulai dan waktu pelepasan.
- PIC dan seluruh anggota aktif dapat melihat serta memperbarui tiket sesuai workflow teknisi.
- Pelanggan melihat nama, foto, spesialisasi, bio, dan nomor telepon PIC hanya ketika PIC menyetujui publikasi kontak.
- Chat baru aktif setelah PIC ditetapkan dan hanya dapat dibuka pelanggan pemilik tiket serta tim yang berwenang.

### 10.5 Estimasi dan persetujuan

- Estimasi memiliki versi, item, jumlah, harga satuan, total, masa berlaku, serta status.
- Perubahan setelah persetujuan membuat versi baru dan meminta persetujuan ulang.
- Pekerjaan berbayar tidak masuk tahap pelaksanaan tanpa persetujuan yang tercatat.

### 10.6 Operasional dan Quality Ledger

- Dashboard menyediakan antrean berdasarkan status, risiko, jadwal, layanan, dan tim.
- Checklist berasal dari template per layanan dan memiliki `standard_code`.
- Item dapat mewajibkan catatan atau bukti.
- Teknisi mencatat tindakan dan hasil uji; supervisor memverifikasi tahap yang diwajibkan.

### 10.7 Tech Passport dan garansi

- Aset dapat berupa komputer, instalasi jaringan, atau sistem CCTV.
- Riwayat menyimpan diagnosis, tindakan, hasil tes, rekomendasi, dan masa garansi.
- Serial number ditampilkan dalam bentuk tersamarkan.
- Reopen pada masa garansi tetap terhubung ke tiket serta riwayat aset asal.

### 10.8 Ticket chat dan profil PIC

- Setiap pesan terhubung ke satu tiket, pengirim, penerima, waktu kirim, dan status baca.
- Customer mengirim ke PIC; PIC/tim berwenang mengirim ke customer yang terhubung.
- Endpoint chat memakai policy tiket, validasi server, rate limit, dan batas panjang pesan.
- Tampilan detail memuat progres, identitas PIC, tim, kontak berdasarkan consent, serta percakapan dalam satu konteks.
- Versi pilot melakukan refresh berkala lima detik; real-time WebSocket menjadi peningkatan setelah pilot.

### 10.9 Inventaris

- Barang memiliki SKU unik, kategori, unit, saldo, ambang restock, harga pokok, lokasi, dan status aktif.
- Mutasi `initial`, `stock_in`, `stock_out`, dan `adjustment` menyimpan aktor serta saldo akhir.
- Pengurangan stok dilakukan di dalam transaksi database dengan row lock dan ditolak jika menyebabkan saldo negatif.
- Mutasi dapat ditautkan ke tiket agar biaya material dan histori pemakaian dapat diaudit.

### 10.10 Ledger keuangan

- Admin/operator dapat mencatat pemasukan dan pengeluaran, metode pembayaran, referensi, deskripsi, tanggal, dan tiket terkait.
- Entri baru berstatus `pending`; hanya operator yang dapat mengubahnya menjadi `verified`.
- Ringkasan pelanggan hanya memuat pemasukan milik tiketnya beserta status `pending` atau `verified`, bukan pengeluaran maupun ledger internal global.
- Modul ini merupakan ledger transparansi internal, bukan payment gateway dan tidak memindahkan uang secara otomatis.

## 11. Struktur informasi

### 11.1 Publik

- `/` — landing dan guided service picker
- `/pesan` — form pemesanan
- `/lacak` — pencarian tiket privat
- `/lacak/p/{order}` — pelacakan dengan URL bertanda tangan
- `/privasi` dan `/syarat-layanan`
- tombol ADI global

### 11.2 Terautentikasi

- `/dashboard` — ringkasan sesuai role
- `/orders/{order}` — detail operasional
- `/inventory` — stok dan histori mutasi sesuai role
- `/finance` — ledger keuangan untuk operator/admin
- `/team` — pengelolaan role dan profil tim untuk operator/admin
- pengaturan profil, keamanan, passkey, dan 2FA
- modul katalog, laporan lanjutan, dan Tech Passport dikembangkan bertahap

## 12. Model data tingkat tinggi

Terdapat 21 entitas domain utama pada rancangan saat ini; tabel framework seperti session, cache, dan queue tidak dihitung.

| Kelompok         | Entitas                                                      |
| ---------------- | ------------------------------------------------------------ |
| Identitas        | `users`, `social_accounts`                                   |
| Katalog          | `services`, `checklist_templates`                            |
| Pesanan          | `service_orders`, `order_assignments`, `order_status_events` |
| Harga            | `estimates`, `estimate_items`, `approvals`                   |
| Pelaksanaan      | `order_checklist_results`, `work_logs`                       |
| Komunikasi       | `order_messages`                                             |
| Inventaris       | `inventory_items`, `inventory_movements`                     |
| Keuangan         | `financial_transactions`                                     |
| Aset             | `customer_assets`, `asset_service_records`                   |
| Kualitas         | `reviews`, `audit_logs`                                      |
| Autentikasi kuat | `passkeys`                                                   |

Aturan relasi penting:

- Customer dapat memiliki banyak service order.
- Service order memiliki satu layanan, nol atau satu PIC, nol atau satu supervisor, dan banyak assignment.
- Assignment menghubungkan satu tiket dengan satu anggota tim teknisi.
- Service order memiliki banyak status event, estimate, checklist result, work log, message, mutasi barang, dan transaksi keuangan.
- Customer asset memiliki banyak service record.
- Inventory item memiliki banyak mutasi; setiap mutasi menyimpan saldo setelah perubahan.
- Financial transaction dapat ditautkan ke satu tiket dan diverifikasi satu operator.

## 13. Otorisasi dan keamanan

| Aksi                             | Operator | Admin | Supervisor | Technician          | Warehouse | Customer |
| -------------------------------- | -------- | ----- | ---------- | ------------------- | --------- | -------- |
| Melihat semua tiket              | Ya       | Ya    | Ya         | Tidak               | Tidak     | Tidak    |
| Melihat tiket sendiri/ditugaskan | Ya       | Ya    | Ya         | Ya                  | Tidak     | Ya       |
| Menentukan tim                   | Ya       | Ya    | Ya         | Tidak               | Tidak     | Tidak    |
| Memperbarui progres lapangan     | Ya       | Ya    | Ya         | Ya, jika ditugaskan | Tidak     | Tidak    |
| Chat pada tiket berizin          | Ya       | Ya    | Ya         | Ya, jika ditugaskan | Tidak     | Ya       |
| Kelola inventaris                | Ya       | Ya    | Lihat      | Lihat               | Ya        | Tidak    |
| Catat transaksi keuangan         | Ya       | Ya    | Tidak      | Tidak               | Tidak     | Tidak    |
| Verifikasi transaksi             | Ya       | Tidak | Tidak      | Tidak               | Tidak     | Tidak    |
| Kelola akun non-operator         | Ya       | Ya    | Tidak      | Tidak               | Tidak     | Tidak    |
| Kelola role operator             | Ya       | Tidak | Tidak      | Tidak               | Tidak     | Tidak    |

Kontrol minimum:

- Policy Laravel untuk seluruh endpoint operasional.
- Rate limit pada login, lookup tiket, dan endpoint AI.
- CSRF, validasi server, mass-assignment protection, dan output escaping.
- Hash untuk kode akses serta token pelacakan.
- 2FA wajib untuk operator, admin, dan supervisor saat production.
- Audit log append-only untuk perubahan status, harga, assignment, role, stok, dan transaksi.
- Lampiran privat diakses melalui URL sementara.

## 14. Privasi dan keselamatan

- Minimalkan data pribadi dan tetapkan masa retensi.
- Foto bukti menghindari wajah, dokumen, layar sensitif, dan area rumah yang tidak relevan.
- Kredensial perangkat tidak boleh dimasukkan ke tiket, log, maupun ADI.
- Pekerjaan listrik, ketinggian, area publik, dan CCTV mempunyai checklist risiko khusus.
- Pekerjaan berisiko tinggi membutuhkan persetujuan supervisor sebelum mulai dan sebelum selesai.
- Teknisi dapat menghentikan pekerjaan ketika kondisi lapangan tidak aman atau di luar scope.

## 15. Kebutuhan nonfungsional

- Mobile-first dan dapat digunakan pada lebar 320 px.
- Target WCAG 2.2 AA untuk kontras, keyboard, focus state, label, dan reduced motion.
- LCP target di bawah 2,5 detik pada koneksi seluler wajar.
- Seluruh query daftar menggunakan eager loading, pagination/limit, dan indeks sesuai pola nyata.
- Error eksternal tidak boleh membocorkan secret atau detail internal.
- Backup harian pada production disertai uji pemulihan berkala.
- Observability mencakup error rate, queue failure, latency, dan audit aktivitas penting.

## 16. Arah UX

Konsep visual adalah **precision service cockpit**: percaya diri, manusiawi, dan teknis tanpa terasa seperti panel admin generik.

- Warna inti: AD red, crimson gradient, ink, dan warm white.
- Tombol, panel, dan navigasi memakai bahasa visual liquid glass yang tetap menjaga kontras serta keterbacaan.
- Informasi kritis memakai teks sekaligus ikon; warna tidak menjadi satu-satunya sinyal.
- Pelanggan melihat bahasa sehari-hari, sedangkan tim mendapatkan detail operasional.
- Nama PIC, anggota tim, estimasi, dan status terbaru harus mudah dipindai.
- Empty, loading, error, offline, dan success state wajib dirancang.

## 17. Stack teknis

- Laravel 13 dan PHP 8.5 untuk domain, otorisasi, workflow, queue, dan audit.
- Inertia 3, React 19, dan TypeScript untuk pengalaman SPA tanpa API terpisah yang tidak perlu.
- Tailwind CSS 4 dan komponen Radix untuk UI responsif dan aksesibel.
- Laravel Fortify untuk autentikasi inti, Socialite untuk Google OAuth, passkey, dan 2FA.
- Wayfinder untuk kontrak route bertipe di frontend.
- SQLite untuk pengembangan; MySQL 8 atau PostgreSQL direkomendasikan untuk production.
- OpenAI Responses API untuk ADI dengan fallback lokal dan guardrail server-side.

## 18. Integrasi website sekolah

Rekomendasi utama adalah subdomain `layanan.nama-sekolah.sch.id` dan satu menu **Layanan TKJ / AD Com** pada website resmi. Subdomain menjaga deployment, keamanan, sesi, dan pengembangan tetap terisolasi. Iframe tidak direkomendasikan.

Parameter integrasi yang harus dapat diatur tanpa mengubah alur inti: nama serta logo sekolah, alamat, kontak resmi, jam operasi, area layanan, katalog, kebijakan garansi, dan tautan kembali ke website sekolah.

## 19. Analytics

Event minimum:

- `service_viewed`, `guided_check_started`, `adi_message_sent`
- `booking_started`, `booking_submitted`, `booking_abandoned`
- `estimate_sent`, `estimate_approved`, `estimate_rejected`
- `technician_assigned`, `technician_released`
- `ticket_message_sent`, `stock_movement_recorded`, `finance_transaction_verified`
- `work_started`, `verification_requested`, `order_completed`, `order_reopened`
- `tracking_opened`, `review_submitted`, `warranty_claimed`

Dashboard agregat menampilkan funnel pemesanan, median response time, SLA, beban per teknisi, first-time-fix rate, reopen rate, kepuasan, dan penerima manfaat.

## 20. Acceptance criteria MVP

1. Pengunjung dapat membuat tiket valid tanpa akun dan menerima nomor tiket serta kode akses sekali tampil.
2. Kode akses dan token mentah tidak tersimpan di database.
3. Pengguna Google baru selalu dibuat sebagai `customer`, termasuk bila pengguna tersebut adalah siswa.
4. Customer hanya melihat tiket yang terhubung dengan akunnya.
5. Teknisi hanya melihat tiket ketika menjadi PIC atau anggota assignment aktif.
6. Halaman tiket menampilkan profil PIC, anggota tim, progres, timeline, chat, checklist, dan status pembayaran.
7. Chat hanya aktif untuk customer pemilik tiket dan tim yang berwenang setelah PIC ditetapkan.
8. Mutasi stok bersifat atomik, tercatat, dan tidak dapat menghasilkan saldo negatif.
9. Transaksi yang dicatat admin tetap pending sampai diverifikasi operator.
10. Admin tidak dapat membuat, mengubah, atau mencabut role operator.
11. Pekerjaan berisiko tidak dapat ditutup melewati gerbang verifikasi yang ditetapkan.
12. Perubahan penting menghasilkan event atau audit log yang relevan.
13. ADI tidak mengklaim diagnosis, harga, jadwal, atau aksi yang belum benar-benar dibuat sistem.
14. Alur utama lulus feature test, type check, build production, analisis statis, dan pemeriksaan tampilan desktop/mobile.

## 21. Tahapan peluncuran

### Fase 0 — validasi operasional

Finalisasi identitas sekolah, daftar teknisi khusus, supervisor, SOP, harga, area, jam, risiko, privasi, serta kebijakan garansi.

### Fase 1 — internal alpha

Operator, admin, supervisor, petugas gudang, dan seluruh anggota tim teknisi menjalankan skenario lengkap dengan data simulasi.

### Fase 2 — pilot terbatas

Buka untuk kelompok pengguna terkontrol yang mewakili siswa, guru, staf, warga, dan UMKM sekitar. Adakan review operasional mingguan.

### Fase 3 — publik bertahap

Pasang portal pada website sekolah, perluas cakupan secara bertahap, aktifkan kanal notifikasi, dan publikasikan metrik dampak agregat.

## 22. Risiko dan mitigasi

| Risiko                                         | Mitigasi                                                                    |
| ---------------------------------------------- | --------------------------------------------------------------------------- |
| Kapasitas tim teknisi terbatas                 | Kuota harian, kalender kapasitas, waiting list, dan SLA realistis           |
| Kualitas antar-teknisi tidak konsisten         | Quality Ledger, PIC jelas, bukti kerja, dan verifikasi supervisor           |
| Pengguna menganggap saran AI sebagai diagnosis | Label triase, guardrail, dan ajakan pemeriksaan teknisi                     |
| Harga dipersepsikan berubah sepihak            | Estimasi berversi dan persetujuan ulang                                     |
| Teknisi membuka tiket di luar tugas            | Policy berdasarkan PIC atau assignment aktif                                |
| Kebocoran foto atau alamat                     | Storage privat, URL sementara, minimisasi, redaksi, dan audit akses         |
| Akun tim diambil alih                          | 2FA/passkey, rate limit, session control, dan penonaktifan akun             |
| Data keuangan dimanipulasi satu pihak          | Pemisahan pencatat admin dan verifikator operator serta audit append-only   |
| Stok fisik dan sistem berbeda                  | Mutasi per aktor/tiket, saldo akhir, stock opname, dan audit berkala        |
| Portal dianggap sekadar tugas kampus           | Pilot pengguna nyata, owner operasional sekolah, dan metrik manfaat 90 hari |

## 23. Keputusan yang harus divalidasi sekolah

- Identitas resmi, operator, admin, supervisor, petugas gudang, dan anggota tim teknisi.
- Wilayah layanan, jam operasional, kapasitas, dan kanal kontak.
- SOP keselamatan, batas pekerjaan, harga, pembayaran, dan garansi.
- Mekanisme persetujuan estimasi dan dokumentasi bukti.
- Masa retensi data, consent WhatsApp/email, serta penanganan komplain.
- Provider hosting, backup, domain, dan PIC insiden keamanan.

## 24. Definition of done

- Enam role dan matriks izin berjalan serta teruji.
- Siswa diperlakukan sebagai customer biasa; tidak ada narasi atau akses siswa sebagai teknisi otomatis.
- PIC, foto/profil, anggota tim, progres, chat, dan kontak berdasarkan consent tampil konsisten pada detail tiket dan pelacakan.
- Booking, tracking privat, Google login, ADI, workflow, inventaris, finance ledger, audit, serta checklist standar lulus pengujian.
- Tidak ada error TypeScript, build production, atau pelanggaran formatter.
- Identitas, SOP, layanan, harga, area, teknisi, supervisor, privasi, dan kontak telah disetujui sekolah.
- Pilot memiliki owner operasional, jadwal evaluasi, kanal dukungan, dan target manfaat yang terukur.
